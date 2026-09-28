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
  $file = realpath('/webgui/emhttp' . $path); // webGui is a link to plugins/dynamix
  if ($file === false || !str_starts_with($file, '/webgui/emhttp/')) {
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
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>tofuman tab preview</title>
<?php foreach (['default-fonts', 'default-color-palette', 'default-base', 'default-dynamix', "themes/$theme"] as $sheet): ?>
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
