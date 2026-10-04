<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// The shim against the Docker of the test host (REQ-TST-4, REQ-TST-5): real pulls, real
// `docker create`, and a started container only in the tests of the start check.

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

function test_the_tab_lists_hand_made_containers_and_only_an_administrator_adopts(): void {
  $env = make_env();
  write_policy($env);
  $operations = new Operations($env);
  $operations->create(definition(), caller());
  hand_made('my-tofumantest-hand.xml', 'tofumantest-hand');
  $handMade = $operations->handMade(caller(true));
  same([['tofumantest-hand', false, []]], array_map(fn(array $c) => [$c['name'], $c['running'], $c['refusals']], $handMade), 'the hand-made containers, each ready to adopt');
  check($handMade[0]['image'] !== '', 'a hand-made container came without its image');
  refuses(fn() => $operations->handMade(caller()), 'only an administrator');
  refuses(fn() => $operations->adopt('tofumantest-hand', caller()), 'only an administrator');
  $operations->adopt('tofumantest-hand', caller(true));
  same([], $operations->handMade(caller(true)), 'an adopted container is still offered for adoption');
}

/** REQ-MUT-20 to REQ-MUT-22, REQ-FILE-4 to REQ-FILE-7: each operation leaves one masked line, and the audit names it. */
function test_operations_leave_a_line_each(): void {
  $env = make_env();
  write_policy($env);
  $operations = new Operations($env);
  $id = $operations->create(definition(), caller() + ['providerVersion' => '0.2.0'], ['id' => 'op-create', 'queuedAt' => '2026-10-04T12:00:00Z'])['id'];
  $operations->update($id, definition(['extraParams' => ['--hostname', 'beta']]), caller(), ['id' => 'op-update']);
  $operations->delete($id, caller(), ['id' => 'op-delete']);
  $lines = Tofuman\OperationLog::tail($env, 10);
  same(['op-create', 'op-update', 'op-delete'], array_column($lines, 'id'));
  same(['succeeded', 'succeeded', 'succeeded'], array_column($lines, 'result'));
  same('2026-10-04T12:00:00Z', $lines[0]['queuedAt']);
  same('0.2.0', $lines[0]['providerVersion']);
  same(['mounts', 'pull', 'template', 'create'], array_column($lines[0]['steps'], 'step'), 'the steps of a create without autostart');
  same(['mounts', 'pull', 'template', 'remove', 'create'], array_column($lines[1]['steps'], 'step'), 'the steps of an update of a stopped container');
  same(['remove', 'template'], array_column($lines[2]['steps'], 'step'), 'the steps of a delete');
  $create = $lines[0]['steps'][3];
  check(str_contains($create['command'], "'SECRET'='***'") && !str_contains(json_encode($lines), 'hunter2'), 'the operation log holds the masked value: ' . $create['command']);
  check(preg_match('/^[0-9a-f]{64}$/', $create['output']) === 1, "the output of docker create is not a container ID: {$create['output']}");
  check(str_starts_with((string)$lines[0]['steps'][1]['digest'], 'sha256:'), 'the pull left no digest');
  same(['extraParams'], $lines[1]['changes']);
  same(null, Tofuman\OperationLog::current($env), 'an ended operation is still in RAM');
  $audit = (new Tofuman\Audit($env->auditFile()))->tail(3);
  same(['op-create', 'op-update', 'op-delete'], array_column($audit, 'operationId'));
  same([[], ['extraParams'], []], array_column($audit, 'changes'));
  check(is_int($audit[1]['durationMs']), 'the audit line has no duration');
  same(['0.2.0', null, null], array_column($audit, 'providerVersion'));
  same($lines[1], Tofuman\OperationLog::find($env, 'op-update'));
}

/** REQ-MUT-17 to REQ-MUT-19 and REQ-MUT-23: a create that dies at its start is undone, logged, and notified. */
function test_a_create_that_dies_at_its_start_is_undone(): void {
  $env = make_env();
  write_policy($env);
  $dies = definition(['autostart' => true, 'postArgs' => ['sh', '-c', 'echo tofumantest-boom hunter2; exit 7']]);
  $failure = fails_at(fn() => (new Operations($env))->create($dies, caller(), ['id' => 'op-dies', 'startCheck' => 5]), 'start check');
  check(str_contains($failure->getMessage(), 'exit code 7') && str_contains($failure->getMessage(), 'tofumantest-boom'), "the failure lacks the exit code or the log: {$failure->getMessage()}");
  check(!str_contains($failure->getMessage(), 'hunter2'), 'the failure shows the masked value from the log');
  check(!container_exists('tofumantest-a') && !is_file(template_file('tofumantest-a')), 'a create that died left its container or its template');
  $line = Tofuman\OperationLog::find($env, 'op-dies');
  same(['failed', 'start check', 7], [$line['result'], $line['step'], end($line['steps'])['exitCode']]);
  check(in_array('tofumantest-boom ***', end($line['steps'])['log'], true), 'the operation log lacks the masked log line: ' . json_encode($line['steps']));
  same(['createContainer', 'failed', 'op-dies'], [last_audit($env)['action'], last_audit($env)['result'], last_audit($env)['operationId']]);
  $notified = notifications($env);
  same(1, count($notified), 'the notifications of a failed operation');
  check(in_array('warning', $notified[0], true) && in_array(Tofuman\Notify::LINK, $notified[0], true), 'the notification is not a warning that links to the Docker page: ' . json_encode($notified[0]));
}

/** REQ-MUT-19 with REQ-MUT-9: an update that dies at its start puts the previous container back. */
function test_an_update_that_dies_at_its_start_puts_the_previous_back(): void {
  $env = make_env();
  write_policy($env);
  $operations = new Operations($env);
  $before = $operations->create(definition(['autostart' => true]), caller(), ['startCheck' => 1]);
  same(true, inspect('tofumantest-a')['State']['Running'], 'the container of the create runs');
  $failure = fails_at(fn() => $operations->update($before['id'], definition(['autostart' => true, 'postArgs' => ['sh', '-c', 'exit 5']]), caller(), ['startCheck' => 5]), 'start check');
  check(str_contains($failure->getMessage(), 'exit code 5') && str_contains($failure->getMessage(), 'is back'), "the failure does not say that the previous container is back: {$failure->getMessage()}");
  same(['sleep', '3600'], inspect('tofumantest-a')['Config']['Cmd'], 'the previous command');
  same(true, inspect('tofumantest-a')['State']['Running'], 'the previous container runs again');
  same($before['definition'], $operations->get($before['id'], null, caller())['definition']);
}

/** REQ-MUT-17: a start check of 0 seconds lets a container that ends on its own pass. */
function test_a_start_check_of_zero_is_skipped(): void {
  $env = make_env();
  write_policy($env);
  (new Operations($env))->create(definition(['autostart' => true, 'postArgs' => ['true']]), caller(), ['startCheck' => 0]);
  check(container_exists('tofumantest-a'), 'the container is gone');
  same(['mounts', 'pull', 'template', 'create', 'start'], array_column(Tofuman\OperationLog::tail($env, 1)[0]['steps'], 'step'));
}

/** REQ-MUT-25 and REQ-TAB-27: the query `check` answers without an audit line, and remembers the policy gaps. */
function test_the_check_query_answers_without_an_audit_line(): void {
  $env = make_env();
  write_policy($env);
  $operations = new Operations($env);
  same(['failedChecks' => [], 'policyGaps' => 0], $operations->checkQuery(null, definition(), caller()));
  $answer = $operations->checkQuery(null, definition(['name' => 'tofumantest-gpu', 'extraParams' => ['--gpus', 'all'], 'network' => 'host']), caller());
  same(2, $answer['policyGaps']);
  same(2, count($answer['failedChecks']), 'the failed checks: ' . implode('; ', $answer['failedChecks']));
  same(null, Json::read($env->auditFile()), 'the query check wrote to the audit log');
  same([['tofumantest-gpu', ['hostNetwork', 'flag']]], array_map(fn(array $r) => [$r['container'], array_column($r['gaps'], 'kind')], $operations->recentGaps()));
  write_policy($env, ['extraParamFlags' => ['--hostname', '--gpus'], 'exceptions' => ['tofumantest-gpu' => ['hostNetwork' => true]]]);
  same([], $operations->recentGaps(), 'gaps that the policy closed since the refusal');
  write_policy($env);
  refuses(fn() => $operations->checkQuery(null, null, caller()), 'takes an id, a definition, or both');
  refuses(fn() => $operations->checkQuery(null, definition(), ['id' => 'x', 'name' => 'stranger', 'admin' => false]), 'not on the key allowlist');
  $id = $operations->create(definition(), caller())['id'];
  same(['failedChecks' => [], 'policyGaps' => 0], $operations->checkQuery($id, null, caller()), 'the checks of a delete');
  same(1, $operations->checkQuery($id, definition(['privileged' => true]), caller())['policyGaps'], 'the checks of an update');
}

/** REQ-POL-11: --ipc host needs an exception for the container. */
function test_ipc_host_needs_an_exception(): void {
  $env = make_env();
  write_policy($env);
  $operations = new Operations($env);
  $ipc = definition(['extraParams' => ['--ipc', 'host']]);
  refuses(fn() => $operations->check('createContainer', null, $ipc, caller()), '"ipcHost": true');
  write_policy($env, ['exceptions' => ['tofumantest-a' => ['ipcHost' => true]]]);
  $operations->create($ipc, caller());
  same('host', inspect('tofumantest-a')['HostConfig']['IpcMode']);
}

/** REQ-TAB-23 and REQ-TAB-24: the tab knows what would refuse an adoption, and adopts several containers at once. */
function test_adoption_readiness_and_several_at_once(): void {
  $env = make_env();
  write_policy($env);
  $operations = new Operations($env);
  hand_made('my-tofumantest-hand.xml', 'tofumantest-hand');
  hand_made('my-tofumantest-shell.xml', 'tofumantest-shell', false);
  $ready = array_column($operations->handMade(caller(true)), 'refusals', 'name');
  same([], $ready['tofumantest-hand']);
  check(str_contains($ready['tofumantest-shell'][0] ?? '', 'shell syntax'), 'the readiness misses the shell syntax: ' . json_encode($ready));
  $results = $operations->adoptMany(['tofumantest-hand', 'tofumantest-shell'], caller(true));
  same([['tofumantest-hand', true], ['tofumantest-shell', false]], array_map(fn(array $r) => [$r['name'], $r['adopted']], $results));
  same(['adopt', 'refused'], [last_audit($env)['action'], last_audit($env)['result']]);
  same(1, count($operations->list(caller())), 'the managed containers after adopting several');
}

/** REQ-TAB-26 and section 16.5: the gaps of managed containers, and a diagnostics file without secrets. */
function test_managed_gaps_and_diagnostics(): void {
  $env = make_env();
  write_policy($env);
  $operations = new Operations($env);
  $operations->create(definition(), caller());
  same([], $operations->managedGaps(caller(true)));
  write_policy($env, ['extraParamFlags' => []]); // the policy tightens after the create
  same([['flag', '--hostname']], array_map(fn(array $g) => [$g['kind'], $g['value']], $operations->managedGaps(caller(true))));
  file_put_contents($env->apiLogFile, "other line\n[tofuman] operation x started\nmore\n");
  $diagnostics = Tofuman\Diagnostics::collect($env, $operations, caller(true));
  same(['generatedAt', 'unraid', 'plugin', 'apiModule', 'testedBuild', 'policy', 'registry', 'managed', 'networks', 'gaps', 'running', 'audit', 'operations', 'apiLog'], array_keys($diagnostics));
  same(['[tofuman] operation x started'], $diagnostics['apiLog']);
  same('unraid-7.3.2', $diagnostics['testedBuild']['match']);
  check(!str_contains(json_encode($diagnostics), 'hunter2'), 'the diagnostics file holds a masked value');
  refuses(fn() => Tofuman\Diagnostics::collect($env, $operations, caller()), 'only an administrator');
  $stats = Tofuman\Stats::week($env);
  same([7, 1, 0, 0], [$stats['days'], $stats['succeeded'], $stats['failed'], $stats['refused']]);
  check(is_int($stats['medianMs']), 'no median duration');
}

/** REQ-UPG-7: an untested build raises one alert. */
function test_startup_alerts_on_an_untested_build(): void {
  $untested = sys_get_temp_dir() . '/tofumantest-builds-' . bin2hex(random_bytes(4)) . '.json';
  Json::write($untested, ['builds' => []]);
  $env = make_env($untested);
  same(['tested' => false], Tofuman\Shim::startup($env));
  $notified = notifications($env);
  same(1, count($notified));
  check(in_array('alert', $notified[0], true), 'the notification is not an alert: ' . json_encode($notified[0]));
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
