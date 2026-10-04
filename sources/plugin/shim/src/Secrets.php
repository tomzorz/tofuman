<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/**
 * The values of config entries with mask true, kept out of what the plugin writes about an
 * operation: the operation log, the audit log, the errors, and the diagnostics file
 * (REQ-FILE-6 to REQ-FILE-8). The template itself holds them in plain text, as DockerMan does.
 */
final class Secrets {
  /** The shortest value that the masking of free text looks for; shorter ones would mangle ordinary words. */
  private const MIN_FREE_TEXT = 4;

  /** @return list<string> the masked values of the definitions, longest first */
  public static function of(?array ...$definitions): array {
    $values = [];
    foreach ($definitions as $d) {
      foreach ($d['configEntries'] ?? [] as $e) {
        if ($e['mask'] && $e['value'] !== '') {
          $values[] = $e['value'];
        }
      }
    }
    $values = array_values(array_unique($values));
    usort($values, fn(string $a, string $b) => strlen($b) <=> strlen($a));
    return $values;
  }

  /**
   * Masks each value where the shell would see it, as in `-e 'TOKEN'='value'` of a docker
   * create, and then each occurrence in free text such as a log line.
   *
   * @param list<string> $secrets
   */
  public static function mask(string $text, array $secrets): string {
    foreach ($secrets as $secret) {
      $text = str_replace(escapeshellarg($secret), "'***'", $text);
      if (strlen($secret) >= self::MIN_FREE_TEXT) {
        $text = str_replace($secret, '***', $text);
      }
    }
    return $text;
  }

  /** The definition with each masked value shown as `***`. */
  public static function maskDefinition(array $d): array {
    foreach ($d['configEntries'] as &$e) {
      if ($e['mask'] && $e['value'] !== '') {
        $e['value'] = '***';
      }
    }
    unset($e);
    return $d;
  }
}
