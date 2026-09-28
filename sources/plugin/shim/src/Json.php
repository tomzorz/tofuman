<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

final class Json {
  private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

  /**
   * RFC 8785 form for what a definition holds: strings, booleans, lists and objects with
   * ASCII keys. No floats ever reach it, which is the part of RFC 8785 this skips.
   */
  public static function canonical(mixed $value): string {
    if (!is_array($value)) {
      return json_encode($value, self::FLAGS);
    }
    if (array_is_list($value)) {
      return '[' . implode(',', array_map(self::canonical(...), $value)) . ']';
    }
    ksort($value, SORT_STRING);
    $members = [];
    foreach ($value as $key => $member) {
      $members[] = json_encode((string)$key, self::FLAGS) . ':' . self::canonical($member);
    }
    return '{' . implode(',', $members) . '}';
  }

  public static function hash(mixed $value): string {
    return 'sha256:' . hash('sha256', self::canonical($value));
  }

  /** Null for a file that does not exist; an exception naming the file for one that is not JSON. */
  public static function read(string $path): mixed {
    if (!is_file($path)) {
      return null;
    }
    try {
      return json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
      throw new \RuntimeException("$path is not valid JSON: {$e->getMessage()}");
    }
  }

  /** Write to a temporary file first, so a crash never leaves half a registry on the flash drive. */
  public static function write(string $path, mixed $value): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
      throw new \RuntimeException('cannot create ' . dirname($path));
    }
    $tmp = "$path.tmp";
    if (file_put_contents($tmp, json_encode($value, self::FLAGS | JSON_PRETTY_PRINT) . "\n") === false || !rename($tmp, $path)) {
      throw new \RuntimeException("cannot write $path");
    }
  }
}
