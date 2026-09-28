<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// The shim against the Docker of the test host (REQ-TST-4, REQ-TST-5): real pulls, real
// `docker create`, never a started container.

declare(strict_types=1);

use Tofuman\Definition;
use Tofuman\Json;
use Tofuman\Operations;

function test_create_writes_a_native_container(): void {
  $env = make_env();
  write_policy($env);
  $view = (new Operations($env))->create(definition(), caller());
  $id = $view['id'];
  same(Definition::normalize(definition()), $view['definition']);
  same(false, $view['changedSinceLastMutation']);
  $ct = inspect('tofumantest-a');
  same($id, $ct['Config']['Labels']['tofuman.id'], 'the marker label');
  same('dockerman', $ct['Config']['Labels']['net.unraid.docker.managed']);
  same('infra', $ct['Config']['Labels']['com.example.team']);
  same('alpha', $ct['Config']['Hostname']);
  same(['sleep', '3600'], $ct['Config']['Cmd']);
  same(false, $ct['State']['Running']);
  check(in_array('SECRET=hunter2', $ct['Config']['Env'], true) && in_array('TZ=Etc/UTC', $ct['Config']['Env'], true), 'the variables are missing');
  check(in_array(MOUNTED . '/appdata/a:/config:rw', $ct['HostConfig']['Binds'], true), 'the bind mount is missing');
  same('18081', $ct['HostConfig']['PortBindings']['8080/tcp'][0]['HostPort'], 'the port on the bridge network');
  same($id, Definition::markerOf(Definition::read(template_file('tofumantest-a'))), 'the marker in the template');
  same('tofumantest-a', Json::read($env->registryFile())['containers'][$id]['name']);
  same(['createContainer', 'succeeded'], [last_audit($env)['action'], last_audit($env)['result']]);
  same($view, (new Operations($env))->get($id, null, caller()));
  same([$view], (new Operations($env))->list(caller()));
}

function test_update_recreates_and_renames_in_place(): void {
  $env = make_env();
  write_policy($env);
  $operations = new Operations($env);
  $id = $operations->create(definition(), caller())['id'];
  $changed = definition(['extraParams' => ['--hostname', 'beta']]);
  same('beta', $operations->update($id, $changed, caller())['definition']['extraParams'][1]);
  same('beta', inspect('tofumantest-a')['Config']['Hostname']);
  $renamed = $operations->update($id, definition(['name' => 'tofumantest-b']), caller());
  same([$id, 'tofumantest-b'], [$renamed['id'], $renamed['definition']['name']]);
  check(!container_exists('tofumantest-a') && container_exists('tofumantest-b'), 'the rename left the containers wrong');
  check(!is_file(template_file('tofumantest-a')) && is_file(template_file('tofumantest-b')), 'the rename left the templates wrong');
  same($id, inspect('tofumantest-b')['Config']['Labels']['tofuman.id']);
}

function test_delete_removes_container_template_and_entry(): void {
  $env = make_env();
  write_policy($env);
  $operations = new Operations($env);
  $id = $operations->create(definition(['autostart' => false]), caller())['id'];
  same(['id' => $id, 'name' => 'tofumantest-a'], $operations->delete($id, caller()));
  check(!container_exists('tofumantest-a') && !is_file(template_file('tofumantest-a')), 'the delete left the container or the template');
  same([], Json::read($env->registryFile())['containers']);
  same(['deleteContainer', 'succeeded'], [last_audit($env)['action'], last_audit($env)['result']]);
  refuses(fn() => $operations->delete($id, caller()), "no managed container has the ID $id");
}

function test_checks_refuse_and_record_the_refusal(): void {
  $env = make_env();
  write_policy($env);
  $operations = new Operations($env);
  $cases = [
    'outside every directory' => definition(['configEntries' => [entry('PATH', 'Config', '/config', '/tmp/x', '', 'rw')]]),
    'privileged needs' => definition(['privileged' => true]),
    'network host needs' => definition(['network' => 'host']),
    '--cap-add is never allowed' => definition(['extraParams' => ['--cap-add', 'SYS_ADMIN']]),
    'does not list --tmpfs' => definition(['extraParams' => ['--tmpfs', '/run']]),
    'reserved' => definition(['configEntries' => [entry('LABEL', 'Id', 'tofuman.id', 'x')]]),
    'does not exist on the server' => definition(['network' => 'tofumantest-no-such-network']),
    'overview does not survive' => definition(['overview' => 'a\\b']),
  ];
  foreach ($cases as $needle => $definition) {
    refuses(fn() => $operations->create($definition, caller()), $needle);
    same(['createContainer', 'refused'], [last_audit($env)['action'], last_audit($env)['result']], $needle);
  }
  refuses(fn() => $operations->create(definition(), ['id' => '22222222-2222-4222-8222-222222222222', 'name' => 'stranger', 'admin' => false]), 'not on the key allowlist');
  same(false, container_exists('tofumantest-a'), 'a refused create left a container');
  $untested = sys_get_temp_dir() . '/tofumantest-builds-' . bin2hex(random_bytes(4)) . '.json';
  Json::write($untested, ['builds' => []]);
  $untestedEnv = make_env($untested);
  write_policy($untestedEnv);
  refuses(fn() => (new Operations($untestedEnv))->check('createContainer', null, definition(), caller(true)), 'matches no tested build');
}

function test_names_of_hand_made_containers_are_taken(): void {
  $env = make_env();
  write_policy($env);
  docker('create --name tofumantest-hand busybox:latest');
  refuses(fn() => (new Operations($env))->create(definition(['name' => 'tofumantest-hand']), caller()), 'belongs to a hand-made container');
}

function test_a_failed_pull_changes_nothing(): void {
  $env = make_env();
  write_policy($env);
  fails_at(fn() => (new Operations($env))->create(definition(['repository' => 'tofumantest/no-such-image:none']), caller()), 'pull');
  check(!is_file(template_file('tofumantest-a')), 'a failed pull left a template');
  same(['createContainer', 'failed'], [last_audit($env)['action'], last_audit($env)['result']]);
}

function test_a_failed_create_is_undone(): void {
  $env = make_env();
  write_policy($env);
  $failure = fails_at(fn() => (new Operations($env))->create(definition(['extraParams' => ['--runtime', 'tofumantest-no-such-runtime']]), caller()), 'create');
  check(str_contains($failure->getMessage(), 'runtime'), "the failure does not name the runtime: {$failure->getMessage()}");
  check(!is_file(template_file('tofumantest-a')) && !container_exists('tofumantest-a'), 'a failed create left a template or a container');
  same(null, Json::read($env->registryFile()), 'a failed create touched the registry');
}

function test_a_failed_update_restores_the_previous_container(): void {
  $env = make_env();
  write_policy($env);
  $operations = new Operations($env);
  $before = $operations->create(definition(), caller());
  $failure = fails_at(fn() => $operations->update($before['id'], definition(['extraParams' => ['--runtime', 'tofumantest-no-such-runtime']]), caller()), 'create');
  check(str_contains($failure->getMessage(), 'is back'), "the failure does not say that the container is back: {$failure->getMessage()}");
  same($before['definition'], $operations->get($before['id'], null, caller())['definition']);
  same('alpha', inspect('tofumantest-a')['Config']['Hostname']);
}

function test_a_missing_mount_stops_the_operation(): void {
  $env = make_env();
  write_policy($env, ['bindRoots' => [MOUNTED . '/', '/tmp/']]);
  fails_at(fn() => (new Operations($env))->create(definition(['configEntries' => [entry('PATH', 'Config', '/config', '/tmp/tofumantest-unmounted/a', '', 'rw')]]), caller()), 'mounts');
  check(!is_file(template_file('tofumantest-a')), 'a missing mount left a template');
}

function test_a_custom_network_takes_ip_and_mac(): void {
  $env = make_env();
  write_policy($env);
  $d = definition(['network' => network_name(), 'ipAddresses' => ['192.0.2.10'], 'macAddress' => '02:42:C0:00:02:0A']);
  $view = (new Operations($env))->create($d, caller());
  same('02:42:c0:00:02:0a', $view['definition']['macAddress'], 'the stored form of the MAC');
  $network = inspect('tofumantest-a')['NetworkSettings']['Networks'][network_name()];
  same('192.0.2.10', $network['IPAMConfig']['IPv4Address']);
  same('02:42:c0:00:02:0a', $network['MacAddress']);
}

function test_adopt_marks_without_recreating(): void {
  $env = make_env();
  write_policy($env);
  hand_made('my-tofumantest-hand.xml', 'tofumantest-hand');
  $dockerId = inspect('tofumantest-hand')['Id'];
  $operations = new Operations($env);
  $view = $operations->adopt('tofumantest-hand', caller(true));
  same($dockerId, inspect('tofumantest-hand')['Id'], 'the container was recreated');
  same($view['id'], Definition::markerOf(Definition::read(template_file('tofumantest-hand'))));
  same(false, $view['changedSinceLastMutation']);
  same(['adopt', 'succeeded', 'webgui'], [last_audit($env)['action'], last_audit($env)['result'], last_audit($env)['caller']]);
  refuses(fn() => $operations->adopt('tofumantest-hand', caller(true)), 'already a managed container');
  $updated = $operations->update($view['id'], $view['definition'], caller());
  same($view['definition'], $updated['definition'], 'an update with the adopted definition');
  same($view['id'], inspect('tofumantest-hand')['Config']['Labels']['tofuman.id']);
}

function test_adopt_refuses_what_it_cannot_keep(): void {
  $env = make_env();
  write_policy($env);
  $operations = new Operations($env);
  hand_made('my-tofumantest-ts.xml', 'tofumantest-ts', false);
  refuses(fn() => $operations->adopt('tofumantest-ts', caller(true)), 'Tailscale');
  hand_made('my-tofumantest-shell.xml', 'tofumantest-shell', false);
  refuses(fn() => $operations->adopt('tofumantest-shell', caller(true)), 'shell syntax');
  docker('create --name tofumantest-plain --label net.unraid.docker.managed=dockerman busybox:latest');
  refuses(fn() => $operations->adopt('tofumantest-plain', caller(true)), 'has no template');
  docker('create --name tofumantest-compose busybox:latest');
  refuses(fn() => $operations->adopt('tofumantest-compose', caller(true)), 'is not a DockerMan container');
  same(['adopt', 'refused'], [last_audit($env)['action'], last_audit($env)['result']]);
}

function test_a_gone_container_reads_as_absent_and_can_come_back(): void {
  $env = make_env();
  write_policy($env);
  $operations = new Operations($env);
  $id = $operations->create(definition(), caller())['id'];
  docker('rm tofumantest-a'); // removed in the webgui: the template stays
  same(null, $operations->get($id, null, caller()));
  same($id, $operations->create(definition(), caller())['id'], 'the create did not reuse the entry');
}
