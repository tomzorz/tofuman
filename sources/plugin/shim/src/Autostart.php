<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/**
 * DockerMan's autostart file: one line per container, `name` or `name wait` (REQ-DEF-8 and
 * REQ-DEF-9). A new line goes last; every other line keeps its place and its wait value.
 */
final class Autostart {
  public function __construct(private readonly string $file) {}

  public function isOn(string $name): bool {
    return $this->find($this->lines(), $name) !== null;
  }

  public function set(string $name, bool $on): void {
    $lines = $this->lines();
    $index = $this->find($lines, $name);
    if ($on && $index === null) {
      $lines[] = $name;
    } elseif (!$on && $index !== null) {
      unset($lines[$index]);
    }
    $this->write(array_values($lines));
  }

  public function rename(string $from, string $to): void {
    $lines = $this->lines();
    $index = $this->find($lines, $from);
    if ($index !== null) {
      $parts = explode(' ', $lines[$index], 2);
      $lines[$index] = isset($parts[1]) ? "$to {$parts[1]}" : $to;
      $this->write($lines);
    }
  }

  private function find(array $lines, string $name): ?int {
    foreach ($lines as $index => $line) {
      if (explode(' ', $line, 2)[0] === $name) {
        return $index;
      }
    }
    return null;
  }

  private function lines(): array {
    return is_file($this->file) ? file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
  }

  /** Like the webgui, an empty list removes the file. */
  private function write(array $lines): void {
    if (!$lines) {
      if (is_file($this->file)) {
        unlink($this->file);
      }
      return;
    }
    if (file_put_contents($this->file, implode("\n", $lines) . "\n") === false) {
      throw new Failure('autostart', "cannot write {$this->file}");
    }
  }
}
