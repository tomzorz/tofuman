<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// Assertions and fixtures for the tests. Every container a test creates has a name that
// starts with tofumantest-, and reset_state() removes exactly those.

declare(strict_types=1);

use Tofuman\Env;
use Tofuman\Refusal;

const CALLER_ID = '11111111-1111-4111-8111-111111111111';
const MOUNTED = '/mnt/tofumantest'; // a bind mount into the test container, so not on its root filesystem

function check(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function same(mixed $expected, mixed $actual, string $what = ''): void {
  if ($expected !== $actual) {
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    throw new RuntimeException(($what === '' ? '' : "$what: ") . 'expected ' . json_encode($expected, $flags) . ', got ' . json_encode($actual, $flags));
  }
}

/** Runs the action and requires a refusal whose text contains the needle. */
function refuses(callable $action, string $needle): void {
  try {
    $action();
  } catch (Refusal $refusal) {
    check(str_contains($refusal->getMessage(), $needle), "refused, but not with '$needle': {$refusal->getMessage()}");
    return;
  }
  throw new RuntimeException("no refusal, expected one with '$needle'");
}

/** Runs the action and requires a failure at the step. */
function fails_at(callable $action, string $step): Tofuman\Failure {
  try {
    $action();
  } catch (Tofuman\Failure $failure) {
    same($step, $failure->step, "the failed step ({$failure->getMessage()})");
    return $failure;
  }
  throw new RuntimeException("no failure, expected one at the step $step");
}

function fixture(string $name): string {
  return __DIR__ . "/fixtures/$name";
}

function make_env(?string $testedBuildsFile = null): Env {
  global $dockerManPaths, $docroot;
  $dir = sys_get_temp_dir() . '/tofumantest-' . bin2hex(random_bytes(4));
  mkdir("$dir/data", 0755, true);
  return new Env("$dir/data", "$dir/lock", $dockerManPaths['autostart-file'], $docroot, $testedBuildsFile ?? dirname(__DIR__) . '/shim/tested-builds.json',
    "$dir/tofuman-api.json", dirname(__DIR__) . '/api/package.json');
}

function network_name(): string {
  return getenv('TOFUMAN_TEST_NETWORK') ?: throw new RuntimeException('TOFUMAN_TEST_NETWORK is not set; run the tests through ci/test.sh');
}

function write_policy(Env $env, array $overrides = []): void {
  Tofuman\Json::write($env->policyFile(), array_replace([
    'version' => 1,
    'keyAllowlist' => [CALLER_ID],
    'bindRoots' => [MOUNTED . '/'],
    'networks' => ['bridge', network_name()],
    'extraParamFlags' => ['--hostname', '--sysctl', '--runtime', '--restart'],
    'exceptions' => ['tofumantest-host' => ['hostNetwork' => true]],
  ], $overrides));
}

function caller(bool $admin = false): array {
  return ['id' => $admin ? 'webgui' : CALLER_ID, 'name' => 'tests', 'admin' => $admin];
}

function entry(string $type, string $name, string $target, string $value, string $default = '', string $mode = '', bool $mask = false): array {
  return ['type' => $type, 'name' => $name, 'target' => $target, 'value' => $value, 'default' => $default, 'mode' => $mode, 'description' => '', 'display' => 'always', 'required' => false, 'mask' => $mask];
}

/** A definition that passes every check with the policy of write_policy(). */
function definition(array $overrides = []): array {
  return array_replace([
    'name' => 'tofumantest-a',
    'repository' => 'busybox:latest',
    'network' => 'bridge',
    'ipAddresses' => [],
    'macAddress' => '',
    'autostart' => false,
    'privileged' => false,
    'cpuset' => '',
    'shell' => 'sh',
    'extraParams' => ['--hostname', 'alpha'],
    'postArgs' => ['sleep', '3600'],
    'webUi' => 'http://[IP]:[PORT:8080]/',
    'icon' => '',
    'overview' => 'A test container.',
    'category' => '',
    'support' => '',
    'project' => '',
    'readMe' => '',
    'templateUrl' => '',
    'registry' => '',
    'donateText' => '',
    'donateLink' => '',
    'requires' => '',
    'configEntries' => [
      entry('PATH', 'Config', '/config', MOUNTED . '/appdata/a', '', 'rw'),
      entry('PORT', 'Web', '8080', '18081', '8080', 'tcp'),
      entry('VARIABLE', 'TZ', 'TZ', 'Etc/UTC', 'Etc/UTC'),
      entry('VARIABLE', 'Secret', 'SECRET', 'hunter2', '', '', true),
      entry('LABEL', 'Team', 'com.example.team', 'infra'),
    ],
  ], $overrides);
}

function inspect(string $container): array {
  exec('docker inspect ' . escapeshellarg($container) . ' 2>/dev/null', $lines, $code);
  check($code === 0, "docker inspect $container failed");
  return json_decode(implode("\n", $lines), true)[0];
}

function container_exists(string $container): bool {
  exec('docker inspect ' . escapeshellarg($container) . ' >/dev/null 2>&1', $lines, $code);
  return $code === 0;
}

function docker(string $args): void {
  exec("docker $args 2>&1", $lines, $code);
  check($code === 0, "docker $args failed: " . implode("\n", $lines));
}

function template_file(string $container): string {
  global $dockerManPaths;
  return "{$dockerManPaths['templates-user']}/my-$container.xml";
}

/**
 * Places a fixture template the way the webgui would, and creates its container the way
 * DockerMan does. A fixture that exists to be refused gets a plain container instead:
 * its own command would run shell syntax or Tailscale's wiring on the test host.
 */
function hand_made(string $fixture, string $container, bool $throughDockerMan = true): void {
  global $dockerManPaths;
  @mkdir($dockerManPaths['templates-user'], 0755, true);
  copy(fixture("templates/$fixture"), template_file($container));
  if ($throughDockerMan) {
    [$command] = xmlToCommand(template_file($container), false);
    Tofuman\Docker::run($command);
  } else {
    docker('create --name ' . escapeshellarg($container) . ' --label net.unraid.docker.managed=dockerman busybox:latest');
  }
}

function last_audit(Env $env): array {
  $lines = (new Tofuman\Audit($env->auditFile()))->tail(1);
  check($lines !== [], 'the audit log is empty');
  return $lines[0];
}

function reset_state(): void {
  global $dockerManPaths, $DockerClient;
  exec("docker ps -aq --filter name=tofumantest- | xargs -r docker rm -f >/dev/null 2>&1");
  foreach (glob("{$dockerManPaths['templates-user']}/my-tofumantest-*.xml") ?: [] as $file) {
    unlink($file);
  }
  if (is_file($dockerManPaths['autostart-file'])) {
    unlink($dockerManPaths['autostart-file']);
  }
  $DockerClient->flushCaches();
}
