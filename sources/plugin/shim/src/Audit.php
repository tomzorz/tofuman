<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/** The audit log of spec section 16.3: one JSON line per mutation and per adoption, the last 1000 kept. */
final class Audit {
  public const KEEP = 1000;

  public function __construct(private readonly string $file) {}

  public function append(array $caller, string $action, ?string $managedId, string $name, string $result, ?string $error): void {
    $lines = $this->lines();
    $lines[] = json_encode([
      'time' => Registry::now(),
      'caller' => (string)($caller['id'] ?? 'unknown'),
      'callerName' => (string)($caller['name'] ?? ''),
      'action' => $action,
      'managedId' => $managedId,
      'name' => $name,
      'result' => $result,
      'error' => $error,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (!is_dir(dirname($this->file))) {
      mkdir(dirname($this->file), 0755, true);
    }
    file_put_contents($this->file, implode("\n", array_slice($lines, -self::KEEP)) . "\n");
  }

  /** @return list<array> the last lines, oldest first */
  public function tail(int $count): array {
    return array_map(fn(string $line) => json_decode($line, true), array_slice($this->lines(), -$count));
  }

  private function lines(): array {
    return is_file($this->file) ? file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
  }
}
