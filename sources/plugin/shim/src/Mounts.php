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
  /** REQ-MNT-5: what Apply in the webgui gives a host path that it creates, nobody:users and 0777. */
  public const OWNER = 99;
  public const GROUP = 100;
  public const MODE = 0777;

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

  /**
   * REQ-MNT-4 to REQ-MNT-9: creates each missing directory of each host path, its parents
   * included, the way Apply in the webgui does. The mode comes from chmod, because the umask
   * trims what mkdir gets. A directory that exists stays as it is.
   *
   * @return list<string> the directories it created, outermost first
   */
  public static function create(array $d): array {
    $created = [];
    foreach ($d['configEntries'] as $e) {
      if ($e['type'] !== 'PATH') {
        continue;
      }
      foreach (self::missing($e['value']) as $dir) {
        error_clear_last();
        if (!@mkdir($dir)) {
          throw self::cannotCreate($e['value'], $dir);
        }
        if (!@chown($dir, self::OWNER) || !@chgrp($dir, self::GROUP) || !@chmod($dir, self::MODE)) {
          $failure = self::cannotCreate($e['value'], $dir);
          @rmdir($dir); // REQ-MNT-9: a retry would find it half made and leave it so
          throw $failure;
        }
        $created[] = $dir;
      }
    }
    return $created;
  }

  /** @return list<string> the directories of the path that do not exist, outermost first */
  private static function missing(string $path): array {
    $existing = self::nearestExisting($path);
    $missing = [];
    for ($dir = rtrim($path, '/') ?: '/'; $dir !== $existing; $dir = dirname($dir)) {
      array_unshift($missing, $dir); // the same walk as nearestExisting, so it ends there
    }
    return $missing;
  }

  /** REQ-MNT-8. */
  private static function cannotCreate(string $path, string $dir): Failure {
    return new Failure('directories', "path $path: cannot create $dir as nobody:users with mode 0777: " . (error_get_last()['message'] ?? 'no reason given'));
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
