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

  /** What the tab shows: the text of the policy file, and why it is invalid, if it is. */
  public static function read(string $path): array {
    if (!is_file($path)) {
      return ['text' => null, 'errors' => ["the policy $path does not exist"]];
    }
    $text = (string)file_get_contents($path);
    try {
      $policy = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
      return ['text' => $text, 'errors' => ["the policy is not JSON: {$e->getMessage()}"]];
    }
    return ['text' => $text, 'errors' => self::validate($policy)];
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
      if ($flag === Flags::IPC) {
        $errors[] = "extraParamFlags: --ipc belongs in exceptions, as \"ipcHost\": true for each container that needs it"; // REQ-POL-11
      } elseif (!array_key_exists($flag, Flags::KNOWN)) {
        $errors[] = "extraParamFlags: tofuman does not know the flag '$flag'"; // REQ-VAL-8
      }
    }
    $exceptions = $p['exceptions'] ?? null;
    if (!is_array($exceptions) || ($exceptions !== [] && array_is_list($exceptions))) {
      $errors[] = 'exceptions must be an object keyed by container name';
      return $errors;
    }
    foreach ($exceptions as $name => $exception) {
      if (!is_array($exception) || array_diff(array_keys($exception), ['privileged', 'hostNetwork', 'ipcHost', 'devices'])) {
        $errors[] = "exceptions.$name may hold only privileged, hostNetwork, ipcHost, and devices";
        continue;
      }
      foreach (['privileged', 'hostNetwork', 'ipcHost'] as $key) {
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

  /** REQ-POL-2 to REQ-POL-7 and REQ-POL-11, and REQ-VAL-6, REQ-VAL-7, and REQ-VAL-21 for the ExtraParams flags. */
  public function check(array $d): array {
    try {
      Flags::parse($d['extraParams']);
      $errors = [];
    } catch (Refusal $refusal) {
      $errors = $refusal->errors; // flags that no policy can allow; gaps() leaves them out
    }
    return [...$errors, ...array_column($this->gaps($d), 'text')];
  }

  /**
   * The policy gaps of a definition: what a person could add to the policy so that it passes.
   * The tab offers each one (REQ-TAB-26 to REQ-TAB-29), and a refusal counts them (REQ-PRV-18).
   *
   * @return list<array{kind: string, value: string, container: string, text: string}> kind: bindRoot, network, flag, privileged, hostNetwork, ipcHost, or device
   */
  public function gaps(array $d): array {
    $name = $d['name'];
    $exception = $this->p['exceptions'][$name] ?? [];
    $gap = fn(string $kind, string $value, string $text) => ['kind' => $kind, 'value' => $value, 'container' => $name, 'text' => $text];
    $gaps = [];
    foreach ($d['configEntries'] as $e) {
      if ($e['type'] === 'PATH') {
        if (!$this->insideBindRoots($e['value'])) {
          $gaps[] = $gap('bindRoot', self::proposedRoot($e['value']), "path {$e['value']} is outside every directory in the policy's bindRoots") + ['path' => $e['value']];
        }
      } elseif ($e['type'] === 'DEVICE' && !in_array($e['value'], $exception['devices'] ?? [], true)) {
        $gaps[] = $gap('device', $e['value'], "device {$e['value']} needs an entry in the policy's exceptions.$name.devices");
      }
    }
    if ($d['privileged'] && ($exception['privileged'] ?? false) !== true) {
      $gaps[] = $gap('privileged', 'true', "privileged needs \"privileged\": true in the policy's exceptions.$name");
    }
    if ($d['network'] === 'host') {
      if (($exception['hostNetwork'] ?? false) !== true) {
        $gaps[] = $gap('hostNetwork', 'true', "network host needs \"hostNetwork\": true in the policy's exceptions.$name");
      }
    } elseif (!in_array($d['network'], $this->p['networks'], true)) {
      $gaps[] = $gap('network', $d['network'], "network {$d['network']} is not in the policy's networks");
    }
    try {
      $flags = Flags::parse($d['extraParams']);
    } catch (Refusal) {
      $flags = []; // check() names what no policy can allow, and the gaps of the other flags wait for that fix
    }
    foreach ($flags as [$flag]) {
      if ($flag === Flags::IPC) {
        if (($exception['ipcHost'] ?? false) !== true) {
          $gaps[] = $gap('ipcHost', 'true', "--ipc host needs \"ipcHost\": true in the policy's exceptions.$name");
        }
      } elseif (!in_array($flag, $this->p['extraParamFlags'], true)) {
        $gaps[] = $gap('flag', $flag, "ExtraParams: the policy's extraParamFlags does not list $flag");
      }
    }
    return $gaps;
  }

  /** Whether the policy, as it stands now, has what a gap asked for: a gap remembered from a refusal may be closed since. */
  public function covers(array $gap): bool {
    $exception = $this->p['exceptions'][$gap['container'] ?? ''] ?? [];
    return match ($gap['kind'] ?? '') {
      'bindRoot' => $this->insideBindRoots((string)($gap['path'] ?? $gap['value'])),
      'network' => in_array($gap['value'], $this->p['networks'], true),
      'flag' => in_array($gap['value'], $this->p['extraParamFlags'], true),
      'device' => in_array($gap['value'], $exception['devices'] ?? [], true),
      'privileged', 'hostNetwork', 'ipcHost' => ($exception[$gap['kind']] ?? false) === true,
      default => false,
    };
  }

  /** REQ-POL-2 and REQ-POL-3: inside a bind root once both sides are resolved. */
  private function insideBindRoots(string $path): bool {
    $resolved = rtrim(Mounts::resolve($path), '/') . '/';
    foreach ($this->p['bindRoots'] as $root) {
      if (str_starts_with($resolved, rtrim(Mounts::resolve($root), '/') . '/')) {
        return true;
      }
    }
    return false;
  }

  /** REQ-TAB-29: the directory that the first three segments of the host path name. */
  public static function proposedRoot(string $hostPath): string {
    $segments = array_values(array_filter(explode('/', $hostPath), fn(string $segment) => $segment !== ''));
    return '/' . implode('/', array_slice($segments, 0, 3)) . '/';
  }
}
