<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/**
 * The operation log of spec section 16.4 (REQ-MUT-20 to REQ-MUT-22): the line of the operation
 * that runs lives in RAM and grows with each step, so the tab can show the step; when the
 * operation ends, the line goes to the flash drive, which keeps the last 200.
 */
final class OperationLog {
  public const KEEP = 200;

  private array $line;
  private float $started;
  private float $stepStarted = 0.0;
  private bool $ended = false;
  /** @var list<string> */
  private array $secrets = [];

  /** @param array{id?: string, queuedAt?: string} $operation what the API module knows of the operation */
  public function __construct(private readonly Env $env, string $mutation, array $caller, array $operation, ?string $managedId, string $name) {
    $this->started = microtime(true);
    $this->line = [
      'id' => is_string($operation['id'] ?? null) ? $operation['id'] : Registry::newId(),
      'mutation' => $mutation,
      'caller' => (string)($caller['id'] ?? 'unknown'),
      'callerName' => (string)($caller['name'] ?? ''),
      'providerVersion' => is_string($caller['providerVersion'] ?? null) ? $caller['providerVersion'] : null,
      'managedId' => $managedId,
      'name' => $name,
      'queuedAt' => is_string($operation['queuedAt'] ?? null) ? $operation['queuedAt'] : Registry::now(),
      'startedAt' => Registry::now(),
      'endedAt' => null,
      'durationMs' => null,
      'result' => 'running',
      'step' => null,
      'error' => null,
      'changes' => [],
      'steps' => [],
    ];
    $this->save();
  }

  public function id(): string {
    return $this->line['id'];
  }

  /** Who the operation is about, once the checks have found out. */
  public function about(?string $managedId, string $name): void {
    $this->line['managedId'] = $managedId;
    $this->line['name'] = $name;
  }

  /** @param list<string> $changes names, never values (REQ-FILE-5) */
  public function changes(array $changes): void {
    $this->line['changes'] = $changes;
  }

  /** @param list<string> $secrets the values to mask from here on (Secrets::of) */
  public function secrets(array $secrets): void {
    $this->secrets = $secrets;
  }

  /** Starts a step of section 9; the step before it ends here. */
  public function step(string $name): void {
    $this->closeStep();
    $this->line['steps'][] = ['step' => $name, 'startedAt' => Registry::now(), 'ms' => null];
    $this->stepStarted = microtime(true);
    $this->save();
  }

  /** A fact of the step that runs: the digest of a pull, the command of a create and its output, an exit code, log lines. */
  public function fact(string $key, mixed $value): void {
    $last = array_key_last($this->line['steps']);
    if ($last !== null) {
      $this->line['steps'][$last][$key] = $this->mask($value);
    }
  }

  /** Ends the operation, once, and returns its duration in milliseconds. */
  public function end(?\Throwable $failure): int {
    if ($this->ended) {
      return $this->durationMs();
    }
    $this->ended = true;
    $this->closeStep();
    $this->line['endedAt'] = Registry::now();
    $this->line['durationMs'] = $this->durationMs();
    $this->line['result'] = $failure === null ? 'succeeded' : 'failed';
    if ($failure !== null) {
      $this->line['step'] = match (true) {
        $failure instanceof Failure => $failure->step,
        $failure instanceof Refusal => 'checks',
        default => $this->line['steps'][array_key_last($this->line['steps']) ?? 0]['step'] ?? 'checks',
      };
      $this->line['error'] = $this->mask($failure->getMessage());
    }
    $file = $this->env->operationsFile();
    $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $lines[] = json_encode($this->line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (!is_dir(dirname($file))) {
      mkdir(dirname($file), 0755, true);
    }
    file_put_contents($file, implode("\n", array_slice($lines, -self::KEEP)) . "\n");
    @unlink($this->env->currentOperationFile());
    return $this->durationMs();
  }

  public function durationMs(): int {
    return (int)round((microtime(true) - $this->started) * 1000);
  }

  public function mask(mixed $value): mixed {
    return match (true) {
      is_string($value) => Secrets::mask($value, $this->secrets),
      is_array($value) => array_map(fn(mixed $item) => $this->mask($item), $value),
      default => $value,
    };
  }

  /** @return ?array the line of the operation with that ID: the one that runs, or one from the flash drive */
  public static function find(Env $env, string $id): ?array {
    $current = Json::read($env->currentOperationFile());
    if (is_array($current) && ($current['id'] ?? null) === $id) {
      return $current;
    }
    foreach (array_reverse(self::tail($env, self::KEEP)) as $line) {
      if (($line['id'] ?? null) === $id) {
        return $line;
      }
    }
    return null;
  }

  /** @return ?array the line of the operation that runs, if one runs */
  public static function current(Env $env): ?array {
    try {
      $current = Json::read($env->currentOperationFile());
    } catch (\RuntimeException) {
      return null; // caught halfway through a write
    }
    return is_array($current) ? $current : null;
  }

  /** @return list<array> the last lines, oldest first */
  public static function tail(Env $env, int $count): array {
    $file = $env->operationsFile();
    $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    return array_values(array_filter(array_map(fn(string $line) => json_decode($line, true), array_slice($lines, -$count)), is_array(...)));
  }

  private function closeStep(): void {
    $last = array_key_last($this->line['steps']);
    if ($last !== null && $this->line['steps'][$last]['ms'] === null) {
      $this->line['steps'][$last]['ms'] = (int)round((microtime(true) - $this->stepStarted) * 1000);
    }
  }

  private function save(): void {
    Json::write($this->env->currentOperationFile(), $this->line);
  }
}
