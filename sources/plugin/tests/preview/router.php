<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// A stand-in for the webgui around the tab, to look at the tab outside Unraid: the page body in
// a bare layout with the webgui's own stylesheets and jQuery bundle, the webgui's CSRF prefilter,
// and the endpoint behind the check that local_prepend.php makes. Serve it with php -S through
// ci/test.sh --tab PORT; ?theme=black picks another webgui theme.

declare(strict_types=1);

const CSRF_TOKEN = 'tab-preview';
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/plugins/tofuman/include/endpoint.php') {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['csrf_token'] ?? null) !== CSRF_TOKEN) {
    http_response_code(403); // local_prepend.php logs the request and exits
    exit;
  }
  unset($_POST['csrf_token']);
  require dirname(__DIR__, 2) . '/tab/include/endpoint.php';
  return;
}

if (str_starts_with($path, '/webGui/')) {
  $file = realpath('/usr/local/emhttp' . $path); // webGui is a link to plugins/dynamix
  if ($file === false || !str_starts_with($file, '/usr/local/emhttp/')) {
    http_response_code(404);
    return;
  }
  $types = ['css' => 'text/css', 'js' => 'text/javascript', 'woff' => 'font/woff', 'png' => 'image/png', 'svg' => 'image/svg+xml'];
  header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
  readfile($file);
  return;
}

$theme = preg_match('/^(white|black|azure|gray)$/', $_GET['theme'] ?? '') ? $_GET['theme'] : 'white';
[, $body] = explode("\n---\n", (string)file_get_contents(dirname(__DIR__, 2) . '/tab/tofuman.page'), 2);

// /docker: a stand-in of the stock container table, with the markup of DockerContainers.php at
// the tag 7.3.2 for each row, so the badge (section 13.1) shows where it shows on a server
if ($path === '/docker') {
  $rows = '';
  foreach (['tofumantest-a' => 'stopped', 'tofumantest-hand' => 'started', 'tofumantest-shell' => 'stopped'] as $name => $status) {
    $color = $status === 'started' ? 'green-text' : 'red-text';
    $shape = $status === 'started' ? 'play' : 'square';
    $rows .= "<tr class='sortable'><td class='ct-name' style='width:220px;padding:8px'><i class='fa fa-arrows-v mover orange-text'></i>"
      . "<span class='outer'><span class='hand'><img src='/plugins/dynamix.docker.manager/images/question.png' class='img'></span>"
      . "<span class='inner'><span class='appname '><a class='exec'>$name</a></span><br><i class='fa fa-$shape $status $color'></i><span class='state'>$status</span></span></span></td>"
      . "<td>latest</td><td>bridge</td><td>172.17.0.2</td><td>8080/tcp</td><td>192.0.2.10:18081</td><td>/config &rarr; /mnt/user/appdata/x</td>"
      . "<td><input type='checkbox' class='autostart' container='$name'></td><td>1 hour</td></tr>";
  }
  $body = "<link type='text/css' rel='stylesheet' href='/plugins/tofuman/include/endpoint.php?action=badges'>"
    . "<div class='TableContainer'><table id='docker_containers' class='tablesorter shift'><thead><tr><th>Application</th><th>Version</th><th>Network</th>"
    . "<th>Container IP</th><th>Container Port</th><th>LAN IP:Port</th><th>Volume Mappings</th><th>Autostart</th><th>Uptime</th></tr></thead>"
    . "<tbody id='docker_list'>$rows</tbody></table></div>";
}
if (str_starts_with($path, '/plugins/dynamix.docker.manager/images/')) {
  $file = realpath('/usr/local/emhttp' . $path);
  if ($file === false || !str_starts_with($file, '/usr/local/emhttp/plugins/dynamix.docker.manager/images/')) {
    http_response_code(404);
    return;
  }
  header('Content-Type: image/png');
  readfile($file);
  return;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>tofuman tab preview</title>
<?php foreach (['default-fonts', 'default-color-palette', 'default-base', 'default-dynamix', 'font-awesome', "themes/$theme"] as $sheet): ?>
<link type="text/css" rel="stylesheet" href="/webGui/styles/<?= $sheet ?>.css">
<?php endforeach ?>
<script src="/webGui/javascript/dynamix.js"></script>
<script>
var csrf_token = "<?= CSRF_TOKEN ?>";
// the prefilter of DefaultPageLayout/HeadInlineJS.php
$.ajaxPrefilter(function(s, orig, xhr){
  if (s.type.toLowerCase() == "post" && !s.crossDomain) {
    s.data = s.data || "";
    s.data += s.data?"&":"";
    s.data += "csrf_token="+csrf_token;
  }
});
</script>
</head>
<body style="padding: 24px">
<?php eval('?>' . $body); ?>
</body>
</html>
