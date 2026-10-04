<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/** REQ-TAB-31: the last 7 days at a glance, from the audit log and the operation log. */
final class Stats {
  public const DAYS = 7;

  /** @return array{days: int, succeeded: int, failed: int, refused: int, medianMs: ?int} */
  public static function week(Env $env): array {
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - self::DAYS * 86400);
    $counts = ['succeeded' => 0, 'failed' => 0, 'refused' => 0];
    foreach ((new Audit($env->auditFile()))->tail(Audit::KEEP) as $line) {
      $result = is_array($line) ? (string)($line['result'] ?? '') : '';
      if ($result !== '' && ($line['action'] ?? '') !== 'adopt' && (string)($line['time'] ?? '') >= $since && isset($counts[$result])) {
        $counts[$result]++;
      }
    }
    $durations = [];
    foreach (OperationLog::tail($env, OperationLog::KEEP) as $operation) {
      if (is_int($operation['durationMs'] ?? null) && (string)($operation['endedAt'] ?? '') >= $since) {
        $durations[] = $operation['durationMs'];
      }
    }
    sort($durations);
    $middle = intdiv(count($durations), 2);
    $median = match (true) {
      $durations === [] => null,
      count($durations) % 2 === 1 => $durations[$middle],
      default => intdiv($durations[$middle - 1] + $durations[$middle], 2),
    };
    return ['days' => self::DAYS, ...$counts, 'medianMs' => $median];
  }
}
