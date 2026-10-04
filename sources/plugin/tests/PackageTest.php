<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// The install script of the API module against a scratch copy of the files it edits in
// unraid-api. The vendor archive and the restart need a server; spike 2 covers them.

declare(strict_types=1);

/** The .plg file as the plugin manager of the webgui reads it (dynamix.plugin.manager/scripts/plugin). */
function test_the_plg_file_parses_like_the_plugin_manager_reads_it(): void {
  libxml_use_internal_errors(true);
  $xml = simplexml_load_file(dirname(__DIR__) . '/tofuman.plg', null, LIBXML_NOCDATA);
  $errors = array_map(fn(LibXMLError $e) => trim($e->message) . " on line {$e->line}", libxml_get_errors());
  libxml_clear_errors();
  check($xml !== false && $errors === [], 'tofuman.plg does not parse: ' . implode('; ', $errors));
  $version = (string)$xml->attributes()->version;
  check((bool)preg_match('/^(\d{4}\.\d{2}\.\d{2}[a-z]?|0000\.00\.00)$/', $version), "the version $version breaks REQ-PKG-16");
  same('https://raw.githubusercontent.com/tomzorz/tofuman/main/sources/plugin/tofuman.plg', (string)$xml->attributes()->pluginURL, 'REQ-PKG-18');
  same("https://github.com/tomzorz/tofuman/releases/download/plugin-$version/tofuman-$version.tgz", (string)$xml->FILE[0]->URL, 'REQ-PKG-19');
  check((bool)preg_match('/^[0-9a-f]{64}$/', (string)$xml->FILE[0]->SHA256), 'the package has no SHA256 hash');
  foreach ($xml->FILE as $file) {
    check(!str_contains((string)$file->INLINE, '&'), "an entity stayed unexpanded in: {$file->INLINE}");
  }
  same('remove', (string)$xml->FILE[2]->attributes()->Method);
  // REQ-PKG-21 and REQ-PKG-22
  check(str_contains((string)$xml->CHANGES, "### $version"), "the release notes have no heading for $version");
  same('https://github.com/tomzorz/tofuman/issues', (string)$xml->attributes()->support);
  same('Docker', (string)$xml->attributes()->launch);
  check((string)$xml->attributes()->icon !== '', 'the plugin has no icon');
  $releases = array_map(fn(array $build) => substr($build['name'], strlen('unraid-')), Tofuman\Json::read(dirname(__DIR__) . '/shim/tested-builds.json')['builds']);
  usort($releases, version_compare(...));
  same($releases[0], (string)$xml->attributes()->min, 'min is the oldest Unraid release with a tested build');
}

function test_the_api_module_goes_into_unraid_api_and_out_again(): void {
  $dir = sys_get_temp_dir() . '/tofumantest-package-' . bin2hex(random_bytes(4));
  mkdir("$dir/plugin/api/dist", 0755, true);
  file_put_contents("$dir/plugin/api/package.json", json_encode(['name' => 'unraid-api-plugin-tofuman', 'version' => '2026.09.28']));
  file_put_contents("$dir/plugin/api/dist/index.js", "export const adapter = 'nestjs';\n");
  mkdir("$dir/unraid-api/node_modules/@unraid/shared", 0755, true);
  file_put_contents("$dir/unraid-api/package.json", '{"name": "unraid-api", "peerDependencies": {"unraid-api-plugin-connect": "workspace:*"}, "engines": {}}');
  file_put_contents("$dir/api.json", '{"version": "4.35.1", "extraOrigins": [], "sandbox": false, "ssoSubIds": [], "plugins": ["unraid-api-plugin-connect"]}');
  $script = function (string ...$args) use ($dir): void {
    $environment = getenv() + [
      'TOFUMAN_PLUGIN_DIR' => "$dir/plugin",
      'TOFUMAN_API_DIR' => "$dir/unraid-api",
      'TOFUMAN_API_CONFIG' => "$dir/api.json",
      'TOFUMAN_VENDOR_CONFIG' => "$dir/no-vendor-archive.json",
      'TOFUMAN_DATA_DIR' => $dir,
    ];
    $process = proc_open(['php', dirname(__DIR__) . '/scripts/api-module.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    same(0, proc_close($process), 'api-module.php ' . implode(' ', $args) . ": $output");
  };
  $package = fn() => json_decode((string)file_get_contents("$dir/unraid-api/package.json"), true);
  $plugins = fn() => json_decode((string)file_get_contents("$dir/api.json"), true)['plugins'];

  $script('install', '2026.09.28');
  $script('install', '2026.09.28'); // every boot runs it again
  same(['unraid-api-plugin-connect' => 'workspace:*', 'unraid-api-plugin-tofuman' => '2026.09.28'], $package()['peerDependencies']);
  check(str_contains((string)file_get_contents("$dir/unraid-api/package.json"), '"engines": {}'), 'an empty object in package.json came back as something else');
  same(['unraid-api-plugin-connect', 'unraid-api-plugin-tofuman'], $plugins());
  check(is_file("$dir/unraid-api/node_modules/unraid-api-plugin-tofuman/dist/index.js"), 'the module is not in node_modules');

  $script('remove');
  same(['unraid-api-plugin-connect' => 'workspace:*'], $package()['peerDependencies']);
  same(['unraid-api-plugin-connect'], $plugins());
  check(!file_exists("$dir/unraid-api/node_modules/unraid-api-plugin-tofuman"), 'the module stayed in node_modules');
}
