<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// The parts of the shim that need no container.

declare(strict_types=1);

use Tofuman\Arguments;
use Tofuman\Autostart;
use Tofuman\Definition;
use Tofuman\Flags;
use Tofuman\Json;
use Tofuman\Mounts;
use Tofuman\Policy;
use Tofuman\Registry;
use Tofuman\Validator;

function test_arguments_split_like_a_shell(): void {
  same(['--sysctl=a b', '-u', '99:100', 'x y', ' z', ''], Arguments::split('--sysctl="a b" -u 99:100 \'x y\' \\ z \'\''));
  same(['a"b', 'c$d'], Arguments::split('"a\\"b" "c\\$d"'));
  refuses(fn() => Arguments::split("'open"), 'unterminated single quote');
  refuses(fn() => Arguments::split('"open'), 'unterminated double quote');
}

function test_arguments_survive_quoting(): void {
  $args = ['a b', "it's", '', '$HOME', "x\ny", '--sysctl=net.ipv4.ip_local_port_range=1024 65000'];
  same($args, Arguments::split(Arguments::join($args)));
}

function test_arguments_spot_shell_syntax(): void {
  check(!Arguments::hasShellSyntax('--hostname hand --sysctl="a b" a#b'), 'plain arguments count as shell syntax');
  check(!Arguments::hasShellSyntax("--x='\$(id)'"), 'single-quoted text counts as shell syntax');
  foreach (['--x=$(id)', 'a;b', 'a|b', '"$HOME"', '#comment', '~/x', 'a*', "a\nb", "'open"] as $text) {
    check(Arguments::hasShellSyntax($text), "'$text' does not count as shell syntax");
  }
}

function test_flags_parse_and_refuse(): void {
  same([['--user', '99:100'], ['--init', null], ['--restart', 'always']], Flags::parse(['-u', '99:100', '--init', '--restart=always']));
  refuses(fn() => Flags::parse(['-v', '/:/host']), '-v is never allowed');
  refuses(fn() => Flags::parse(['--cap-add=SYS_ADMIN']), '--cap-add is never allowed');
  refuses(fn() => Flags::parse(['--link', 'db']), 'does not know the flag --link');
  refuses(fn() => Flags::parse(['--user']), '--user needs a value');
  refuses(fn() => Flags::parse(['--init=yes']), '--init takes no value');
  refuses(fn() => Flags::parse(['sleep']), "'sleep' is not a flag");
}

function test_json_canonical_form(): void {
  same('{"a":[true,"x/y","é"],"b":1}', Json::canonical(['b' => 1, 'a' => [true, 'x/y', 'é']]));
}

function test_autostart_keeps_other_lines(): void {
  $file = sys_get_temp_dir() . '/tofumantest-autostart-' . bin2hex(random_bytes(4));
  file_put_contents($file, "first\nb 10\n");
  $autostart = new Autostart($file);
  $autostart->set('a', true);
  $autostart->set('a', true);
  $autostart->rename('b', 'c');
  same("first\nc 10\na\n", file_get_contents($file));
  check($autostart->isOn('c') && !$autostart->isOn('b'), 'the rename did not move the line');
  $autostart->set('first', false);
  $autostart->set('c', false);
  $autostart->set('a', false);
  check(!is_file($file), 'an empty autostart file was kept');
}

function test_mounts_find_the_root_filesystem(): void {
  same('/tmp', Mounts::nearestExisting('/tmp/tofumantest-missing/x'));
  same([], Mounts::check(definition()));
  $errors = Mounts::check(definition(['configEntries' => [entry('PATH', 'Config', '/config', '/tmp/tofumantest-missing/x', '', 'rw')]]));
  check(count($errors) === 1 && str_contains($errors[0], 'the mount behind the path is missing'), 'a path on the root filesystem passed');
}

function test_policy_resolves_symbolic_links(): void {
  $link = MOUNTED . '/tofumantest-link';
  @unlink($link);
  symlink('/tmp', $link);
  try {
    same('/tmp/x', Mounts::resolve("$link/x"));
    $env = make_env();
    write_policy($env);
    $errors = Policy::load($env->policyFile())->check(Definition::normalize(definition(['configEntries' => [entry('PATH', 'Config', '/config', "$link/x", '', 'rw')]])));
    check(count($errors) === 1 && str_contains($errors[0], 'outside every directory'), 'a symbolic link led out of bindRoots');
  } finally {
    unlink($link);
  }
}

function test_policy_validation(): void {
  $defaults = json_decode(json_encode(Policy::defaults()), true); // as the tab and the API module read it
  same([], Policy::validate($defaults));
  same(['unknown field extra'], Policy::validate($defaults + ['extra' => 1]));
  $errors = Policy::validate(array_replace($defaults, ['bindRoots' => ['relative/'], 'extraParamFlags' => ['--link'], 'keyAllowlist' => ['nope']]));
  same(3, count($errors), 'errors for a bad bindRoots, a bad flag, and a bad key: ' . implode('; ', $errors));
  refuses(fn() => Policy::load('/nonexistent/policy.json'), 'does not exist');
}

function test_policy_checks_each_rule(): void {
  $env = make_env();
  write_policy($env, ['extraParamFlags' => ['--hostname']]);
  $policy = Policy::load($env->policyFile());
  same([], $policy->check(Definition::normalize(definition())));
  $errors = $policy->check(Definition::normalize(definition([
    'privileged' => true,
    'network' => 'host',
    'extraParams' => ['--hostname', 'a', '--tmpfs', '/run'],
    'configEntries' => [entry('PATH', 'Root', '/host', '/', '', 'rw'), entry('DEVICE', 'GPU', '', '/dev/dri')],
  ])));
  $expected = ['outside every directory', 'needs an entry in the policy\'s exceptions', 'privileged needs', 'network host needs', 'does not list --tmpfs'];
  same(count($expected), count($errors), 'policy errors: ' . implode('; ', $errors));
  foreach ($expected as $i => $needle) {
    check(str_contains($errors[$i], $needle), "error $i is '{$errors[$i]}', expected '$needle'");
  }
  check($policy->allowsCaller(caller()) && $policy->allowsCaller(caller(true)), 'the allowlisted key or the administrator was refused');
  check(!$policy->allowsCaller(['id' => '22222222-2222-4222-8222-222222222222']), 'a key off the allowlist passed');
}

function test_validator_shape(): void {
  same([], Validator::shape(definition()));
  $bad = definition(['autostart' => 'yes', 'extra' => 1]);
  unset($bad['name']);
  same(['the definition has an unknown field extra', 'name must be a string', 'autostart must be a boolean'], Validator::shape($bad));
}

function test_validator_fields(): void {
  $ok = Definition::normalize(definition());
  $networks = Tofuman\Docker::networks();
  same([], Validator::fields($ok, $networks, fn() => false));
  $bad = Definition::normalize(definition([
    'name' => '-bad',
    'repository' => 'Upper/Case',
    'webUi' => 'http://x/"onclick',
    'ipAddresses' => ['300.1.1.1'],
    'macAddress' => 'zz:00:00:00:00:00',
    'cpuset' => '1;2',
    'shell' => 'zsh',
    'network' => 'tofumantest-no-such-network',
    'configEntries' => [
      entry('PATH', 'Config', 'config', MOUNTED . '/../x', '', 'rw'),
      entry('PORT', 'Web', '70000', '0', '', 'sctp'),
      entry('LABEL', 'Managed', 'net.unraid.docker.managed', 'x'),
      entry('DEVICE', 'Disk', '', '/etc/shadow'),
    ],
  ]));
  $errors = Validator::fields($bad, $networks, fn() => false);
  foreach (['valid container name', 'not an image reference', 'webUi must be', 'neither an IPv4', 'macAddress', 'cpuset', 'shell must be', 'does not exist on the server', 'host path', 'container path', 'container port', 'host port', 'protocol', 'reserved', 'under /dev/'] as $needle) {
    check((bool)array_filter($errors, fn(string $e) => str_contains($e, $needle)), "no error mentions '$needle': " . implode('; ', $errors));
  }
}

function test_validator_round_trip(): void {
  same([], Validator::roundTrip(Definition::normalize(definition())));
  $errors = Validator::roundTrip(Definition::normalize(definition(['overview' => 'a\\b <b>bold</b>'])));
  check(count($errors) === 1 && str_contains($errors[0], 'overview does not survive'), 'an overview that DockerMan rewrites passed: ' . implode('; ', $errors));
}

function test_definition_reads_a_hand_made_template(): void {
  $d = Definition::fromVar(Definition::read(fixture('templates/my-tofumantest-hand.xml')), false);
  same(['--hostname', 'hand', '--sysctl=net.ipv4.ip_local_port_range=1024 65000'], $d['extraParams']);
  same(['sleep', '3600'], $d['postArgs']);
  same(['PATH', 'PORT', 'VARIABLE', 'VARIABLE'], array_column($d['configEntries'], 'type'));
  same('Etc/UTC', $d['configEntries'][2]['value'], 'an empty value reads as its default');
  same('', $d['configEntries'][2]['mode'], 'the junk mode of a variable');
  check($d['configEntries'][3]['mask'], 'the masked variable lost its mask');
  same([], Validator::roundTrip($d));
}

function test_definition_carries_the_marker(): void {
  $xml = Definition::toXml(Definition::normalize(definition()), Registry::NIL_ID);
  $var = Definition::readXml($xml);
  same(Registry::NIL_ID, Definition::markerOf($var));
  same(Definition::MARKER_NAME, end($var['Config'])['Name']);
  same(Definition::normalize(definition()), Definition::fromVar($var, false));
}

function test_registry_reconciles(): void {
  $path = sys_get_temp_dir() . '/tofumantest-registry-' . bin2hex(random_bytes(4)) . '.json';
  $registry = Registry::load($path);
  $registry->record('id-a', 'a', 'h');
  $registry->record('id-b', 'b', 'h');
  $registry->record('id-c', 'c', 'h');
  $registry->record('id-d', 'd', 'h');
  $registry->reconcile([
    'a' => ['marker' => 'id-a'],
    'clone-of-a' => ['marker' => 'id-a'], // REQ-OWN-9: a clone changes nothing
    'b-renamed' => ['marker' => 'id-b'], // REQ-OWN-10: a rename moves the entry
  ], fn(string $name) => $name === 'c'); // c has a template and no container, d has neither
  same(['id-a' => 'a', 'id-b' => 'b-renamed', 'id-c' => 'c'], array_map(fn(array $e) => $e['name'], $registry->containers));
  $registry->save();
  same(array_keys($registry->containers), array_keys(Registry::load($path)->containers));
  check(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Registry::newId()) === 1, 'a managed ID is not a UUID v4');
}

/** REQ-UPG-2 in the layout of a server (REQ-TST-10), where the plugin lives inside the docroot. */
function test_tested_build_covers_the_loaded_files(): void {
  global $docroot;
  $files = Tofuman\TestedBuild::current($docroot);
  $paths = implode(', ', array_keys($files));
  // local_prepend.php comes from php.ini's auto_prepend_file, as on Unraid
  foreach (['plugins/dynamix.docker.manager/include/Helpers.php', 'plugins/dynamix.docker.manager/include/DockerClient.php', 'plugins/dynamix/include/local_prepend.php', Tofuman\TestedBuild::DOCKER_SCRIPT] as $path) {
    check(isset($files[$path]), "the tested build does not cover $path: $paths");
  }
  check(str_starts_with(realpath(__DIR__) ?: '', realpath($docroot) . '/'), 'the tests do not run inside the docroot, so they cannot see the layout of a server');
  check(!array_filter(array_keys($files), fn(string $path) => str_starts_with($path, 'plugins/tofuman/')), "the plugin's own files count as webgui files: $paths");
}

function test_the_load_record_tells_whether_unraid_api_loaded_the_module(): void {
  $env = make_env();
  $expected = Tofuman\Json::read($env->apiManifestFile)['version'];
  $state = fn() => Tofuman\LoadRecord::status($env)['state'];
  same('missing', $state());
  $record = fn(string $version, int $pid) => file_put_contents($env->loadRecordFile, json_encode(['version' => $version, 'pid' => $pid, 'loadedAt' => '2026-09-28T12:00:00.000Z']));
  $record($expected, getmypid());
  same('loaded', $state());
  $record('2000.01.01', getmypid());
  same('stale', $state(), 'a load of an older version');
  $record($expected, 2147483646);
  same('gone', $state(), 'a load by a process that is gone');
}

function test_the_tab_saves_only_a_valid_policy(): void {
  $env = make_env();
  $operations = new Tofuman\Operations($env);
  same(['text' => null, 'errors' => ["the policy {$env->policyFile()} does not exist"]], Tofuman\Policy::read($env->policyFile()));
  $text = '{"version": 1, "keyAllowlist": ["' . CALLER_ID . '"], "bindRoots": ["/mnt/user/appdata/"], "networks": ["bridge"], "extraParamFlags": [], "exceptions": {}}';
  $saved = $operations->savePolicy($text, caller(true));
  same([], $saved['errors']);
  check(str_contains((string)$saved['text'], '"exceptions": {}'), "an empty exceptions object came back as something else: {$saved['text']}");
  refuses(fn() => $operations->savePolicy('{"version": 2}', caller(true)), 'version must be 1');
  refuses(fn() => $operations->savePolicy('{nope', caller(true)), 'not JSON');
  refuses(fn() => $operations->savePolicy($text, caller()), 'only an administrator');
  same($saved, Tofuman\Policy::read($env->policyFile()), 'a refused save changed the policy');
}

function test_shim_speaks_json_on_stdin_and_stdout(): void {
  $env = make_env();
  $ask = function (array $request) use ($env): array {
    $process = proc_open(['php', dirname(__DIR__) . '/shim/tofuman-shim.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv() + ['TOFUMAN_DATA_DIR' => $env->dataDir, 'TOFUMAN_LOCK' => $env->lockFile]);
    fwrite($pipes[0], json_encode($request));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($process);
    $lines = explode("\n", trim((string)$stdout));
    same(1, count($lines), "the shim printed more than its response: $stdout");
    return json_decode($lines[0], true);
  };
  same(['ok' => true, 'result' => true], $ask(['action' => 'initPolicy']));
  same(['ok' => true, 'result' => false], $ask(['action' => 'initPolicy']), 'a second init');
  $state = $ask(['action' => 'tabState', 'caller' => caller(true)]);
  same(['managed', 'handMade', 'policy', 'audit', 'testedBuild', 'apiModule'], array_keys($state['result'] ?? []), 'the parts of the tab state');
  same([], $state['result']['policy']['errors'], 'the default policy');
  same(['ok' => true, 'result' => []], $ask(['action' => 'list', 'caller' => caller(true)]));
  same(['ok' => false, 'refused' => true, 'errors' => ['the caller x is not on the key allowlist']], $ask(['action' => 'list', 'caller' => ['id' => 'x', 'name' => 'stranger', 'admin' => false]]));
  same(['ok' => true, 'result' => []], $ask(['action' => 'validatePolicy', 'args' => ['policy' => Tofuman\Json::read($env->policyFile())]]));
  same('unraid-7.3.2', $ask(['action' => 'testedBuild'])['result']['match']);
  $refused = $ask(['action' => 'check', 'args' => ['mutation' => 'createContainer', 'definition' => definition()], 'caller' => ['id' => 'x', 'name' => 'stranger', 'admin' => false]]);
  same([false, true], [$refused['ok'], $refused['refused']]);
  same(['ok' => false, 'refused' => true, 'errors' => ['unknown action "nope"']], $ask(['action' => 'nope']));
}
