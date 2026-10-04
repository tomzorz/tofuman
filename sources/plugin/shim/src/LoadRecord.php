<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/**
 * REQ-PKG-3 from the record of REQ-PKG-15: whether the unraid-api that runs now loaded the API
 * module, and which version. /var/run starts empty at each boot, so a missing record means no
 * load since the boot.
 */
final class LoadRecord {
  /** @return array{state: string, version: ?string, expected: ?string, loadedAt: ?string} state: loaded, stale, gone, or missing */
  public static function status(Env $env): array {
    $expected = $env->pluginVersion();
    $record = is_file($env->loadRecordFile) ? json_decode((string)file_get_contents($env->loadRecordFile), true) : null;
    if (!is_array($record)) {
      return ['state' => 'missing', 'version' => null, 'expected' => $expected, 'loadedAt' => null];
    }
    $version = is_string($record['version'] ?? null) ? $record['version'] : null;
    $loadedAt = is_string($record['loadedAt'] ?? null) ? $record['loadedAt'] : null;
    $pid = is_int($record['pid'] ?? null) ? $record['pid'] : 0;
    $state = match (true) {
      $pid <= 0 || !is_dir("/proc/$pid") => 'gone',
      $version !== $expected => 'stale',
      default => 'loaded',
    };
    return ['state' => $state, 'version' => $version, 'expected' => $expected, 'loadedAt' => $loadedAt];
  }
}
