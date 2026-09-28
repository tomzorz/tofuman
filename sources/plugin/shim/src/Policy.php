<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/** The policy of spec sections 7 and 16.2. Only the tab writes it; the API module only reads it. */
final class Policy {
  private function __construct(private readonly array $p) {}

  /** REQ-FILE-1. */
  public static function defaults(): array {
    return ['version' => 1, 'keyAllowlist' => [], 'bindRoots' => ['/mnt/user/appdata/'], 'networks' => ['bridge'], 'extraParamFlags' => [], 'exceptions' => new \stdClass()];
  }

  /** REQ-POL-1: an absent or invalid policy refuses every mutation. */
  public static function load(string $path): self {
    $policy = Json::read($path);
    if ($policy === null) {
      throw new Refusal(["the policy $path does not exist"]);
    }
    $errors = self::validate($policy);
    if ($errors) {
      throw new Refusal(array_map(fn(string $error) => "policy: $error", $errors));
    }
    return new self($policy);
  }

  /** The checks the tab runs before it saves a policy (REQ-TAB-9), and the ones load() runs. */
  public static function validate(mixed $p): array {
    if (!is_array($p) || ($p !== [] && array_is_list($p))) {
      return ['the policy is not an object'];
    }
    $errors = [];
    foreach (array_diff(array_keys($p), ['version', 'keyAllowlist', 'bindRoots', 'networks', 'extraParamFlags', 'exceptions']) as $unknown) {
      $errors[] = "unknown field $unknown";
    }
    if (($p['version'] ?? null) !== 1) {
      $errors[] = 'version must be 1';
    }
    foreach (['keyAllowlist', 'bindRoots', 'networks', 'extraParamFlags'] as $key) {
      if (!is_array($p[$key] ?? null) || !array_is_list($p[$key]) || count(array_filter($p[$key], is_string(...))) !== count($p[$key])) {
        $errors[] = "$key must be a list of strings";
        return $errors;
      }
    }
    foreach ($p['keyAllowlist'] as $id) {
      if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
        $errors[] = "keyAllowlist: '$id' is not an API key ID";
      }
    }
    foreach ($p['bindRoots'] as $root) {
      if (!str_starts_with($root, '/') || !str_ends_with($root, '/') || str_contains($root, '/../') || str_contains($root, '/./') || str_contains($root, '//')) {
        $errors[] = "bindRoots: '$root' must be an absolute path that ends with /"; // REQ-FILE-2
      }
    }
    foreach ($p['extraParamFlags'] as $flag) {
      if (!array_key_exists($flag, Flags::KNOWN)) {
        $errors[] = "extraParamFlags: tofuman does not know the flag '$flag'"; // REQ-VAL-8
      }
    }
    $exceptions = $p['exceptions'] ?? null;
    if (!is_array($exceptions) || ($exceptions !== [] && array_is_list($exceptions))) {
      $errors[] = 'exceptions must be an object keyed by container name';
      return $errors;
    }
    foreach ($exceptions as $name => $exception) {
      if (!is_array($exception) || array_diff(array_keys($exception), ['privileged', 'hostNetwork', 'devices'])) {
        $errors[] = "exceptions.$name may hold only privileged, hostNetwork, and devices";
        continue;
      }
      foreach (['privileged', 'hostNetwork'] as $key) {
        if (isset($exception[$key]) && !is_bool($exception[$key])) {
          $errors[] = "exceptions.$name.$key must be a boolean";
        }
      }
      $devices = $exception['devices'] ?? [];
      if (!is_array($devices) || !array_is_list($devices) || count(array_filter($devices, fn(mixed $d) => is_string($d) && str_starts_with($d, '/dev/'))) !== count($devices)) {
        $errors[] = "exceptions.$name.devices must be a list of paths under /dev/";
      }
    }
    return $errors;
  }

  /** REQ-AUTH-2 to REQ-AUTH-4. */
  public function allowsCaller(array $caller): bool {
    return ($caller['admin'] ?? false) === true || in_array($caller['id'] ?? '', $this->p['keyAllowlist'], true);
  }

  /** REQ-POL-2 to REQ-POL-7, and REQ-VAL-6 for the ExtraParams flags. */
  public function check(array $d): array {
    $errors = [];
    $exception = $this->p['exceptions'][$d['name']] ?? [];
    $roots = array_map(fn(string $root) => Mounts::resolve($root), $this->p['bindRoots']);
    foreach ($d['configEntries'] as $e) {
      if ($e['type'] === 'PATH') {
        $resolved = rtrim(Mounts::resolve($e['value']), '/') . '/';
        $inside = array_filter($roots, fn(string $root) => str_starts_with($resolved, rtrim($root, '/') . '/'));
        if (!$inside) {
          $errors[] = "path {$e['value']} is outside every directory in the policy's bindRoots";
        }
      } elseif ($e['type'] === 'DEVICE' && !in_array($e['value'], $exception['devices'] ?? [], true)) {
        $errors[] = "device {$e['value']} needs an entry in the policy's exceptions.{$d['name']}.devices";
      }
    }
    if ($d['privileged'] && ($exception['privileged'] ?? false) !== true) {
      $errors[] = "privileged needs \"privileged\": true in the policy's exceptions.{$d['name']}";
    }
    if ($d['network'] === 'host') {
      if (($exception['hostNetwork'] ?? false) !== true) {
        $errors[] = "network host needs \"hostNetwork\": true in the policy's exceptions.{$d['name']}";
      }
    } elseif (!in_array($d['network'], $this->p['networks'], true)) {
      $errors[] = "network {$d['network']} is not in the policy's networks";
    }
    try {
      foreach (Flags::parse($d['extraParams']) as [$flag]) {
        if (!in_array($flag, $this->p['extraParamFlags'], true)) {
          $errors[] = "ExtraParams: the policy's extraParamFlags does not list $flag";
        }
      }
    } catch (Refusal $refusal) {
      array_push($errors, ...$refusal->errors);
    }
    return $errors;
  }
}
