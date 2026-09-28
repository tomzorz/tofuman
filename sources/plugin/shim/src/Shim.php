<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/**
 * One request in, one response out:
 *
 *   {"action": "...", "args": {...}, "caller": {"id": "...", "name": "...", "admin": false}}
 *   {"ok": true, "result": ...}
 *   {"ok": false, "refused": true, "errors": [...]}   a check failed; nothing changed
 *   {"ok": false, "step": "...", "errors": [...]}     an operation failed at that step
 */
final class Shim {
  public static function run(string $input): int {
    try {
      $request = json_decode($input, true, 64, JSON_THROW_ON_ERROR);
      $env = Env::fromGlobals();
      $operations = new Operations($env);
      $args = $request['args'] ?? [];
      $caller = $request['caller'] ?? [];
      $result = match ($request['action'] ?? '') {
        'authorize' => $operations->authorize($caller),
        'list' => $operations->list($caller),
        'get' => $operations->get($args['id'] ?? null, $args['name'] ?? null, $caller),
        'check' => $operations->check((string)$args['mutation'], $args['id'] ?? null, $args['definition'] ?? null, $caller),
        'create' => $operations->create($args['definition'], $caller),
        'update' => $operations->update((string)$args['id'], $args['definition'], $caller),
        'delete' => $operations->delete((string)$args['id'], $caller),
        'adopt' => $operations->adopt((string)$args['name'], $caller),
        'handMade' => $operations->handMade($caller),
        'savePolicy' => $operations->savePolicy((string)($args['text'] ?? ''), $caller),
        'tabState' => self::tabState($env, $operations, $caller),
        'recordRefusal' => $operations->recordRefusal((string)$args['mutation'], $args['id'] ?? null, (string)($args['name'] ?? ''), (string)$args['error'], $caller),
        'validatePolicy' => Policy::validate($args['policy'] ?? null),
        'initPolicy' => self::initPolicy($env),
        'auditTail' => (new Audit($env->auditFile()))->tail((int)($args['count'] ?? 100)),
        'testedBuild' => self::testedBuild($env),
        default => throw new Refusal(['unknown action ' . json_encode($request['action'] ?? null)]),
      };
      self::respond(['ok' => true, 'result' => $result]);
      return 0;
    } catch (Refusal $refusal) {
      self::respond(['ok' => false, 'refused' => true, 'errors' => $refusal->errors]);
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

  /** Everything the tab shows, in one process: REQ-TAB-2, REQ-TAB-4, REQ-TAB-8, REQ-TAB-10, REQ-TAB-11, REQ-PKG-3. */
  private static function tabState(Env $env, Operations $operations, array $caller): array {
    return [
      'managed' => $operations->list($caller),
      'handMade' => $operations->handMade($caller),
      'policy' => Policy::read($env->policyFile()),
      'audit' => (new Audit($env->auditFile()))->tail(100),
      'testedBuild' => self::testedBuild($env),
      'apiModule' => LoadRecord::status($env),
    ];
  }

  /** REQ-TAB-11, and the hashes that go into tested-builds.json for a new Unraid release. */
  private static function testedBuild(Env $env): array {
    $current = TestedBuild::current($env->docroot);
    return ['match' => TestedBuild::match($current, $env->testedBuildsFile), 'files' => $current];
  }

  private static function respond(array $response): void {
    $noise = ob_get_clean();
    if ($noise !== false && $noise !== '') {
      fwrite(STDERR, $noise);
    }
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
  }
}
