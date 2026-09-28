<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/**
 * Host paths as the kernel will see them (spec section 10 and REQ-POL-3).
 *
 * Unraid runs from RAM: the root filesystem, /mnt included, is a RAM disk until the array,
 * the pools, and the remote shares mount over it. A bind source that falls through to the
 * root filesystem means its mount is missing, and Docker would create the directory in RAM.
 */
final class Mounts {
  /** REQ-MNT-1. */
  public static function check(array $d): array {
    $root = stat('/')['dev'];
    $errors = [];
    foreach ($d['configEntries'] as $e) {
      if ($e['type'] !== 'PATH') {
        continue;
      }
      $existing = self::nearestExisting($e['value']);
      $stat = @stat($existing);
      if ($stat === false || $stat['dev'] === $root) {
        $errors[] = "path {$e['value']}: $existing is on the root filesystem of the server, so the mount behind the path is missing";
      }
    }
    return $errors;
  }

  /** The path with its nearest existing ancestor resolved by realpath, so no symbolic link hides where it leads. */
  public static function resolve(string $path): string {
    $existing = self::nearestExisting($path);
    $rest = substr(rtrim($path, '/'), strlen(rtrim($existing, '/')));
    $real = realpath($existing);
    return rtrim($real === false ? $existing : $real, '/') . $rest ?: '/';
  }

  public static function nearestExisting(string $path): string {
    $probe = rtrim($path, '/') ?: '/';
    while (!file_exists($probe)) {
      $parent = dirname($probe);
      if ($parent === $probe) {
        break;
      }
      $probe = $parent;
    }
    return $probe;
  }
}
