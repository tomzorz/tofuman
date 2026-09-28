<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// Puts the API module into unraid-api, and takes it out again (REQ-PKG-7, REQ-PKG-10 to
// REQ-PKG-14). The .plg file runs `install` at each boot, before emhttp starts unraid-api, and at
// each install or update of the plugin; it runs `remove` when a person removes the plugin.
//
//   php api-module.php install <version>
//   php api-module.php remove
//
// unraid-api loads a plugin that is in the plugins list of api.json (flash drive) and in the
// dependencies of its package.json (RAM, back to stock at each boot). Each start through
// rc.unraid-api replaces node_modules from the vendor archive on the flash drive, so the module
// has to be in that archive as well.

declare(strict_types=1);

const MODULE = 'unraid-api-plugin-tofuman';

$paths = [
  'plugin' => getenv('TOFUMAN_PLUGIN_DIR') ?: dirname(__DIR__),
  'api' => getenv('TOFUMAN_API_DIR') ?: '/usr/local/unraid-api',
  'config' => getenv('TOFUMAN_API_CONFIG') ?: '/boot/config/plugins/dynamix.my.servers/configs/api.json',
  'vendor' => getenv('TOFUMAN_VENDOR_CONFIG') ?: '/usr/local/share/dynamix.unraid.net/config/vendor_archive.json',
  'scripts' => '/usr/local/share/dynamix.unraid.net/scripts',
  'stamp' => (getenv('TOFUMAN_DATA_DIR') ?: '/boot/config/plugins/tofuman') . '/vendor-archive.json',
];

function say(string $line): void {
  echo "tofuman: $line\n";
}

/** Objects stay objects: json_decode without assoc keeps {} apart from []. */
function read_json(string $path): ?stdClass {
  $value = is_file($path) ? json_decode((string)file_get_contents($path)) : null;
  return $value instanceof stdClass ? $value : null;
}

function write_json(string $path, stdClass $value): void {
  $tmp = "$path.tofuman";
  if (file_put_contents($tmp, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false || !rename($tmp, $path)) {
    throw new RuntimeException("cannot write $path");
  }
}

function run(string $command): bool {
  say("running $command");
  passthru($command, $code);
  return $code === 0;
}

function remove_tree(string $path): void {
  if (is_dir($path) && !is_link($path)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
      $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
  } elseif (file_exists($path) || is_link($path)) {
    unlink($path);
  }
}

function copy_tree(string $from, string $to): void {
  mkdir($to, 0755, true);
  foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
    $target = $to . substr($entry->getPathname(), strlen($from));
    $entry->isDir() ? mkdir($target, 0755, true) : copy($entry->getPathname(), $target);
  }
}

/** What the package of the API module ships: its manifest and dist/ ("files" in package.json). */
function copy_module(array $paths): void {
  $source = "{$paths['plugin']}/api";
  $target = "{$paths['api']}/node_modules/" . MODULE;
  if (!is_file("$source/package.json") || !is_dir("$source/dist")) {
    throw new RuntimeException("the plugin lacks the API module in $source");
  }
  remove_tree($target);
  mkdir($target, 0755, true);
  copy("$source/package.json", "$target/package.json");
  copy_tree("$source/dist", "$target/dist");
}

function patch_package(array $paths, ?string $version): void {
  $file = "{$paths['api']}/package.json";
  $package = read_json($file) ?? throw new RuntimeException("$file is missing or not JSON");
  $peers = $package->peerDependencies ?? new stdClass();
  if ($version === null) {
    unset($peers->{MODULE});
  } else {
    $peers->{MODULE} = $version;
  }
  $package->peerDependencies = $peers;
  write_json($file, $package);
}

function patch_config(array $paths, bool $add): void {
  $config = read_json($paths['config']);
  if ($config === null) {
    say("{$paths['config']} is missing or not JSON; unraid-api will not load the API module");
    return;
  }
  $plugins = array_values(array_filter(is_array($config->plugins ?? null) ? $config->plugins : [], fn(mixed $p) => $p !== MODULE));
  if ($add) {
    $plugins[] = MODULE;
  }
  $config->plugins = $plugins;
  write_json($paths['config'], $config);
}

/** The vendor archive and its size and time, or null when unraid-api keeps none. */
function vendor_archive(array $paths): ?array {
  $store = read_json($paths['vendor'])?->vendor_store_path ?? null;
  if (!is_string($store) || !is_file($store)) {
    return null;
  }
  clearstatcache(true, $store);
  return ['archive' => $store, 'size' => filesize($store), 'mtime' => filemtime($store)];
}

/** REQ-PKG-13, once per version and archive: restore, change node_modules, archive again. */
function rearchive(array $paths, callable $change, ?string $version): void {
  $archive = vendor_archive($paths);
  if ($archive === null) {
    say('unraid-api keeps no vendor archive, so node_modules stays as it is');
    $change();
    return;
  }
  $stamp = is_file($paths['stamp']) ? json_decode((string)file_get_contents($paths['stamp']), true) : null;
  // an install whose version the archive holds, or a removal from an archive that never held it
  if (($version !== null && $stamp === $archive + ['version' => $version]) || ($version === null && $stamp === null)) {
    $change();
    return;
  }
  run(escapeshellarg("{$paths['scripts']}/dependencies.sh") . ' restore') || throw new RuntimeException('restoring node_modules from the vendor archive failed');
  $change();
  run('/etc/rc.d/rc.unraid-api archive-dependencies') || throw new RuntimeException('rebuilding the vendor archive failed');
  $archive = vendor_archive($paths) ?? throw new RuntimeException('the vendor archive is gone after its rebuild');
  if ($version === null) {
    @unlink($paths['stamp']);
  } else {
    file_put_contents($paths['stamp'], json_encode($archive + ['version' => $version]) . "\n");
  }
}

/** REQ-PKG-14. At boot, rc.local installs the plugins before emhttpd starts unraid-api. */
function restart_if_running(): void {
  exec('pgrep -x emhttpd', $output, $code);
  if ($code !== 0) {
    say('emhttpd does not run yet, so unraid-api starts later with the API module');
    return;
  }
  run('/etc/rc.d/rc.unraid-api restart') || say('restarting unraid-api failed; the tofuman tab shows whether it loaded the API module');
}

$action = $argv[1] ?? '';
try {
  if (!is_dir("{$paths['api']}/node_modules") && $action !== 'remove') {
    say("{$paths['api']}/node_modules does not exist, so the API module stays out of unraid-api");
    exit(0);
  }
  switch ($action) {
    case 'install':
      $version = $argv[2] ?? throw new RuntimeException('usage: api-module.php install <version>');
      rearchive($paths, fn() => copy_module($paths), $version);
      patch_package($paths, $version);
      patch_config($paths, true);
      restart_if_running();
      say("the API module $version is in unraid-api");
      break;
    case 'remove':
      patch_config($paths, false);
      if (is_file("{$paths['api']}/package.json")) {
        patch_package($paths, null);
        rearchive($paths, fn() => remove_tree("{$paths['api']}/node_modules/" . MODULE), null);
      }
      restart_if_running();
      say('the API module is out of unraid-api');
      break;
    default:
      throw new RuntimeException('usage: api-module.php install <version> | remove');
  }
} catch (Throwable $error) {
  say($error->getMessage());
  exit(1);
}
