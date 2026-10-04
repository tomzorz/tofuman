<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/**
 * The diagnostics file of spec section 16.5: what a maintainer needs to find the cause of a
 * failure, in one download from the tab. No API key goes in, and every masked value shows as
 * `***` (REQ-FILE-8): the policy holds only key IDs, and the operation log is masked already.
 */
final class Diagnostics {
  /** REQ-FILE-8 is about these lines too: the log of unraid-api that names tofuman. */
  public const API_LOG_LINES = 200;

  public static function collect(Env $env, Operations $operations, array $caller): array {
    if (($caller['admin'] ?? false) !== true) {
      throw new Refusal(['only an administrator in the tab can do this']);
    }
    $unraid = is_file($env->unraidVersionFile) ? (parse_ini_file($env->unraidVersionFile) ?: []) : [];
    $current = TestedBuild::current($env->docroot);
    $policy = Policy::read($env->policyFile());
    return [
      'generatedAt' => Registry::now(),
      'unraid' => is_string($unraid['version'] ?? null) ? $unraid['version'] : null,
      'plugin' => $env->pluginVersion(),
      'apiModule' => LoadRecord::status($env),
      'testedBuild' => ['match' => TestedBuild::match($current, $env->testedBuildsFile), 'mismatches' => TestedBuild::mismatches($env), 'files' => $current],
      'policy' => ['text' => $policy['text'], 'errors' => $policy['errors']],
      'registry' => Json::read($env->registryFile()),
      'managed' => array_map(fn(array $view) => ['definition' => Secrets::maskDefinition($view['definition'])] + $view, $operations->list($caller)),
      'networks' => Docker::networks(),
      'gaps' => ['managed' => $operations->managedGaps($caller), 'recent' => $operations->recentGaps()],
      'running' => OperationLog::current($env),
      'audit' => (new Audit($env->auditFile()))->tail(Audit::KEEP),
      'operations' => OperationLog::tail($env, OperationLog::KEEP),
      'apiLog' => self::linesNaming($env->apiLogFile, 'tofuman', self::API_LOG_LINES),
    ];
  }

  /**
   * The last lines of a log that name the needle, read from the end so a large log costs
   * little.
   *
   * @return list<string>
   */
  public static function linesNaming(string $file, string $needle, int $count): array {
    $handle = is_file($file) ? @fopen($file, 'r') : false;
    if ($handle === false) {
      return [];
    }
    $size = (int)fstat($handle)['size'];
    $chunk = 1 << 20; // the last mebibyte is plenty for 200 lines
    fseek($handle, max(0, $size - $chunk));
    $text = (string)stream_get_contents($handle);
    fclose($handle);
    $lines = array_filter(explode("\n", $text), fn(string $line) => stripos($line, $needle) !== false);
    return array_values(array_slice($lines, -$count));
  }
}
