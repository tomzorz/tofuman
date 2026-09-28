<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/** The registry of spec sections 5 and 16.1: the only source of truth for what tofuman manages. */
final class Registry {
  /** A managed ID that no container ever gets, for checks that need one. */
  public const NIL_ID = '00000000-0000-4000-8000-000000000000';

  /** @param array<string, array{name: string, createdAt: string, lastMutationAt: string, definitionHash: string}> $containers */
  private function __construct(private readonly string $path, public array $containers) {}

  public static function load(string $path): self {
    $data = Json::read($path) ?? ['version' => 1, 'containers' => []];
    if (($data['version'] ?? null) !== 1 || !is_array($data['containers'] ?? null)) {
      throw new \RuntimeException("$path is not a version 1 registry");
    }
    return new self($path, $data['containers']);
  }

  public function save(): void {
    Json::write($this->path, ['version' => 1, 'containers' => (object)$this->containers]);
  }

  public function idOf(string $name): ?string {
    foreach ($this->containers as $id => $entry) {
      if ($entry['name'] === $name) {
        return (string)$id;
      }
    }
    return null;
  }

  /** Records a mutation of a container, and adds the entry if the container is new to the registry. */
  public function record(string $id, string $name, string $definitionHash): void {
    $now = self::now();
    $this->containers[$id] = [
      'name' => $name,
      'createdAt' => $this->containers[$id]['createdAt'] ?? $now,
      'lastMutationAt' => $now,
      'definitionHash' => $definitionHash,
    ];
  }

  public function remove(string $id): void {
    unset($this->containers[$id]);
  }

  /**
   * REQ-OWN-9 to REQ-OWN-11. A clone that carries the marker of an entry whose own container
   * still exists changes nothing: it is a hand-made container.
   *
   * @param array<string, array{marker: ?string}> $containers by name
   * @param callable(string): bool $templateExists
   */
  public function reconcile(array $containers, callable $templateExists): void {
    foreach ($this->containers as $id => $entry) {
      if (isset($containers[$entry['name']])) {
        continue;
      }
      $carriers = array_keys(array_filter($containers, fn(array $c) => $c['marker'] === (string)$id));
      if (count($carriers) === 1) {
        $this->containers[$id]['name'] = (string)$carriers[0];
      } elseif (!$templateExists($entry['name'])) {
        unset($this->containers[$id]);
      }
    }
  }

  public static function newId(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
  }

  public static function now(): string {
    return gmdate('Y-m-d\TH:i:s\Z');
  }
}
