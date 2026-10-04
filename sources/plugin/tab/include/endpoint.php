<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// The endpoint of the tab (REQ-TAB-13). A read comes as GET and a change comes as POST. The
// webgui's local_prepend.php checks the CSRF token of every POST before this file runs, and the
// webgui's jQuery prefilter adds the token to every POST of the tab. Each action runs the shim
// as the webgui caller, an administrator.

declare(strict_types=1);

function tofuman_shim(string $action, array $args): array {
  $shim = '/usr/local/emhttp/plugins/tofuman/shim/tofuman-shim.php';
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

/**
 * REQ-TAB-33 to REQ-TAB-35: the badge, as a stylesheet. A row of the Docker page carries the
 * container name only on its autostart switch, so the rule finds the row by that switch and
 * marks the name, after it, where nothing can be covered.
 */
function tofuman_badge_css(array $names): string {
  $selectors = [];
  foreach ($names as $name) {
    if (is_string($name) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name)) {
      $selectors[] = "#docker_list tr:has(input.autostart[container=\"$name\"]) span.appname::after";
    }
  }
  if (!$selectors) {
    return "/* tofuman manages no container yet */\n";
  }
  return implode(",\n", $selectors) . " {\n"
    . "  content: \"tofu\";\n"
    . "  display: inline-block;\n"
    . "  margin-left: 6px;\n"
    . "  padding: 0 4px;\n"
    . "  border: 1px solid currentColor;\n"
    . "  border-radius: 3px;\n"
    . "  font-size: 1.05rem;\n"
    . "  line-height: 1.5rem;\n"
    . "  font-weight: normal;\n"
    . "  letter-spacing: 0.5px;\n"
    . "  vertical-align: 1px;\n"
    . "  color: inherit;\n" // the text color of the row, which every theme keeps legible
    . "  opacity: 0.75;\n"
    . "}\n";
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''));

if ("$method $action" === 'GET badges') {
  $response = tofuman_shim('badges', []);
  header('Content-Type: text/css');
  header('Cache-Control: no-store');
  echo tofuman_badge_css($response['ok'] ? $response['result'] : []);
  return;
}

// REQ-TAB-32: the diagnostics file comes as a download, not as an answer for the page
if ("$method $action" === 'GET diagnostics') {
  $response = tofuman_shim('diagnostics', []);
  header('Content-Type: application/json');
  header('Content-Disposition: attachment; filename="tofuman-diagnostics-' . gmdate('Ymd\THis\Z') . '.json"');
  echo json_encode($response['ok'] ? $response['result'] : $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  return;
}

$names = $_POST['names'] ?? [];
$response = match ("$method $action") {
  'GET state' => tofuman_shim('tabState', []),
  'GET operation' => tofuman_shim('operation', ['id' => (string)($_GET['id'] ?? '')]),
  'POST adopt' => tofuman_shim('adopt', ['name' => (string)($_POST['name'] ?? '')]),
  'POST adoptMany' => tofuman_shim('adoptMany', ['names' => array_values(array_filter(is_array($names) ? $names : [], is_string(...)))]),
  'POST savePolicy' => tofuman_shim('savePolicy', ['text' => (string)($_POST['text'] ?? '')]),
  default => ['ok' => false, 'errors' => ["the tab has no action $method $action"]],
};
header('Content-Type: application/json');
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
