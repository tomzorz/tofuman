<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// The endpoint of the tab (REQ-TAB-13). A read comes as GET and a change comes as POST. The
// webgui's local_prepend.php checks the CSRF token of every POST before this file runs, and the
// webgui's jQuery prefilter adds the token to every POST of the tab. Each action runs the shim
// as the webgui caller, an administrator.

declare(strict_types=1);

function tofuman_shim(string $action, array $args): array {
  $shim = getenv('TOFUMAN_SHIM') ?: '/usr/local/emhttp/plugins/tofuman/shim/tofuman-shim.php';
  $request = json_encode(['action' => $action, 'args' => (object)$args, 'caller' => ['id' => 'webgui', 'name' => 'webgui', 'admin' => true]], JSON_THROW_ON_ERROR);
  $process = proc_open(['php', $shim], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if ($process === false) {
    return ['ok' => false, 'errors' => ["cannot start the shim $shim"]];
  }
  fwrite($pipes[0], $request);
  fclose($pipes[0]);
  $stdout = (string)stream_get_contents($pipes[1]);
  $stderr = (string)stream_get_contents($pipes[2]);
  proc_close($process);
  $response = json_decode($stdout, true);
  return is_array($response) ? $response : ['ok' => false, 'errors' => ['the shim answered without JSON: ' . trim($stderr . $stdout)]];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''));
$response = match ("$method $action") {
  'GET state' => tofuman_shim('tabState', []),
  'POST adopt' => tofuman_shim('adopt', ['name' => (string)($_POST['name'] ?? '')]),
  'POST savePolicy' => tofuman_shim('savePolicy', ['text' => (string)($_POST['text'] ?? '')]),
  default => ['ok' => false, 'errors' => ["the tab has no action $method $action"]],
};
header('Content-Type: application/json');
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
