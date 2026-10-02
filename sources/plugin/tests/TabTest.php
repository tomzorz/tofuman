<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// The endpoint of the tab, each request in its own process against the real shim.

declare(strict_types=1);

use Tofuman\Env;

function tab_request(Env $env, string $method, array $params): array {
  $environment = getenv() + [
    'TOFUMAN_DATA_DIR' => $env->dataDir,
    'TOFUMAN_LOCK' => $env->lockFile,
  ];
  $request = json_encode(['method' => $method, 'params' => $params], JSON_THROW_ON_ERROR);
  $process = proc_open(['php', fixture('tab-request.php'), $request], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
  $stdout = (string)stream_get_contents($pipes[1]);
  $stderr = (string)stream_get_contents($pipes[2]);
  proc_close($process);
  $response = json_decode($stdout, true);
  check(is_array($response), "the endpoint answered without JSON: $stdout $stderr");
  return $response;
}

function test_the_tab_endpoint_reads_with_get_and_changes_with_post(): void {
  $env = make_env();
  write_policy($env);
  $state = tab_request($env, 'GET', ['action' => 'state']);
  same(true, $state['ok'], 'the state: ' . json_encode($state['errors'] ?? null));
  same([], $state['result']['policy']['errors']);
  same(['ok' => false, 'errors' => ['the tab has no action GET adopt']], tab_request($env, 'GET', ['action' => 'adopt', 'name' => 'tofumantest-hand']));
  same(['ok' => false, 'errors' => ['the tab has no action POST state']], tab_request($env, 'POST', ['action' => 'state']));

  $text = $state['result']['policy']['text'];
  $refused = tab_request($env, 'POST', ['action' => 'savePolicy', 'text' => '{"version": 2}']);
  same([false, true], [$refused['ok'], $refused['refused'] ?? null], 'an invalid policy');
  $saved = tab_request($env, 'POST', ['action' => 'savePolicy', 'text' => str_replace('"--restart"', '"--restart", "--memory"', $text)]);
  same(true, $saved['ok'], 'a valid policy: ' . json_encode($saved['errors'] ?? null));
  check(str_contains((string)file_get_contents($env->policyFile()), '"--memory"'), 'the policy file lacks the saved flag');

  hand_made('my-tofumantest-hand.xml', 'tofumantest-hand');
  $adopted = tab_request($env, 'POST', ['action' => 'adopt', 'name' => 'tofumantest-hand']);
  same(true, $adopted['ok'], 'the adoption: ' . json_encode($adopted['errors'] ?? null));
  same(['adopt', 'succeeded', 'webgui'], [last_audit($env)['action'], last_audit($env)['result'], last_audit($env)['caller']]);
}
