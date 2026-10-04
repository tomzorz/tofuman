<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/**
 * One request in, one response out:
 *
 *   {"action": "...", "args": {...}, "caller": {"id": "...", "name": "...", "admin": false, "providerVersion": "..."}}
 *   {"ok": true, "result": ...}
 *   {"ok": false, "refused": true, "errors": [...], "policyGaps": 0}   a check failed; nothing changed
 *   {"ok": false, "step": "...", "errors": [...]}                      an operation failed at that step
 */
final class Shim {
  public static function run(string $input): int {
    try {
      $request = json_decode($input, true, 64, JSON_THROW_ON_ERROR);
      $env = Env::fromGlobals();
      $operations = new Operations($env);
      $args = $request['args'] ?? [];
      $caller = $request['caller'] ?? [];
      $operation = is_array($args['operation'] ?? null) ? $args['operation'] : [];
      $result = match ($request['action'] ?? '') {
        'authorize' => $operations->authorize($caller),
        'list' => $operations->list($caller),
        'get' => $operations->get($args['id'] ?? null, $args['name'] ?? null, $caller),
        'check' => $operations->check((string)$args['mutation'], $args['id'] ?? null, $args['definition'] ?? null, $caller),
        'checkQuery' => $operations->checkQuery($args['id'] ?? null, $args['definition'] ?? null, $caller),
        'create' => $operations->create($args['definition'], $caller, $operation),
        'update' => $operations->update((string)$args['id'], $args['definition'], $caller, $operation),
        'delete' => $operations->delete((string)$args['id'], $caller, $operation),
        'adopt' => $operations->adopt((string)$args['name'], $caller),
        'adoptMany' => $operations->adoptMany(array_values(array_filter((array)($args['names'] ?? []), is_string(...))), $caller),
        'handMade' => $operations->handMade($caller),
        'savePolicy' => $operations->savePolicy((string)($args['text'] ?? ''), $caller),
        'tabState' => self::tabState($env, $operations, $caller),
        'operation' => self::operation($env, (string)($args['id'] ?? ''), $caller),
        'diagnostics' => Diagnostics::collect($env, $operations, $caller),
        'badges' => self::badges($env),
        'recordRefusal' => $operations->recordRefusal((string)$args['mutation'], $args['id'] ?? null, (string)($args['name'] ?? ''), (string)$args['error'], $caller),
        'validatePolicy' => Policy::validate($args['policy'] ?? null),
        'initPolicy' => self::initPolicy($env),
        'auditTail' => (new Audit($env->auditFile()))->tail((int)($args['count'] ?? 100)),
        'testedBuild' => self::testedBuild($env),
        'startup' => self::startup($env),
        default => throw new Refusal(['unknown action ' . json_encode($request['action'] ?? null)]),
      };
      self::respond(['ok' => true, 'result' => $result]);
      return 0;
    } catch (Refusal $refusal) {
      self::respond(['ok' => false, 'refused' => true, 'errors' => $refusal->errors, 'policyGaps' => count($refusal->gaps)]);
      return 0;
    } catch (Failure $failure) {
      self::respond(['ok' => false, 'step' => $failure->step, 'errors' => [$failure->getMessage()]]);
      return 0;
    } catch (\Throwable $error) {
      self::respond(['ok' => false, 'errors' => [get_class($error) . ': ' . $error->getMessage()]]);
      return 1;
    }
  }

  /** REQ-FILE-1: the default policy, only if there is no policy yet. */
  private static function initPolicy(Env $env): bool {
    if (is_file($env->policyFile())) {
      return false;
    }
    Json::write($env->policyFile(), Policy::defaults());
    return true;
  }

  /** Everything the tab shows, in one process (section 13, REQ-PKG-3). */
  private static function tabState(Env $env, Operations $operations, array $caller): array {
    $policy = Policy::read($env->policyFile());
    $allowlist = json_decode((string)$policy['text'], true)['keyAllowlist'] ?? [];
    return [
      'plugin' => $env->pluginVersion(),
      'managed' => array_map(fn(array $view) => $view + ['tab' => Docker::tabFacts($view['definition']['name'], $view['definition'])], $operations->list($caller)),
      'handMade' => array_map(fn(array $container) => $container + ['tab' => Docker::tabFacts($container['name'], null)], $operations->handMade($caller)),
      'policy' => $policy,
      'keyAllowlistSize' => is_array($allowlist) ? count($allowlist) : 0,
      'gaps' => ['managed' => $operations->managedGaps($caller), 'recent' => $operations->recentGaps()],
      'audit' => (new Audit($env->auditFile()))->tail(100),
      'running' => OperationLog::current($env),
      'stats' => Stats::week($env),
      'testedBuild' => self::testedBuild($env),
      'apiModule' => LoadRecord::status($env),
    ];
  }

  /**
   * REQ-TAB-33: the names that get the badge on the Docker page. The registry alone answers,
   * without Docker, because the Docker page asks on every load.
   *
   * @return list<string>
   */
  private static function badges(Env $env): array {
    return array_values(array_map(fn(array $entry) => (string)$entry['name'], Registry::load($env->registryFile())->containers));
  }

  /** REQ-TAB-30: the line of one operation, for an administrator in the tab. */
  private static function operation(Env $env, string $id, array $caller): ?array {
    if (($caller['admin'] ?? false) !== true) {
      throw new Refusal(['only an administrator in the tab can do this']);
    }
    return OperationLog::find($env, $id);
  }

  /** REQ-TAB-11, and the hashes that go into tested-builds.json for a new Unraid release. */
  private static function testedBuild(Env $env): array {
    $current = TestedBuild::current($env->docroot);
    return ['match' => TestedBuild::match($current, $env->testedBuildsFile), 'files' => $current];
  }

  /** REQ-UPG-7: what the API module runs once per start of unraid-api. */
  public static function startup(Env $env): array {
    $mismatches = TestedBuild::mismatches($env);
    if ($mismatches) {
      Notify::raise($env, 'alert', 'tofuman: this Unraid release is untested',
        'tofuman refuses every mutation until a plugin release covers this Unraid release. The tofuman tab on the Docker page names each webgui file that differs.');
    }
    return ['tested' => !$mismatches];
  }

  private static function respond(array $response): void {
    $noise = ob_get_clean();
    if ($noise !== false && $noise !== '') {
      fwrite(STDERR, $noise);
    }
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
  }
}
