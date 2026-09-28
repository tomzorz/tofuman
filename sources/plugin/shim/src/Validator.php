<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/** The checks of spec section 6. Each method returns every error it finds, each one naming its field. */
final class Validator {
  /** The reference grammar of github.com/distribution/reference, without IPv6 registry hosts. */
  private const REFERENCE = '~^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?)*(?::[0-9]+)?/)?'
    . '[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*(?:/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)*'
    . '(?::[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?(?:@sha256:[a-f0-9]{64})?$~';

  private const URL_FIELDS = ['webUi', 'support', 'project', 'readMe', 'donateLink', 'icon', 'templateUrl', 'registry'];

  /** The definition has exactly the fields of spec section 15, each of the right type. */
  public static function shape(mixed $d): array {
    if (!is_array($d) || ($d !== [] && array_is_list($d))) {
      return ['the definition is not an object'];
    }
    $errors = [];
    foreach (array_diff(array_keys($d), [...Definition::STRINGS, ...Definition::BOOLEANS, ...Definition::LISTS, 'configEntries']) as $unknown) {
      $errors[] = "the definition has an unknown field $unknown";
    }
    foreach (Definition::STRINGS as $key) {
      if (!is_string($d[$key] ?? null)) {
        $errors[] = "$key must be a string";
      }
    }
    foreach (Definition::BOOLEANS as $key) {
      if (!is_bool($d[$key] ?? null)) {
        $errors[] = "$key must be a boolean";
      }
    }
    foreach (Definition::LISTS as $key) {
      if (!self::isStringList($d[$key] ?? null)) {
        $errors[] = "$key must be a list of strings";
      }
    }
    if (!is_array($d['configEntries'] ?? null) || !array_is_list($d['configEntries'])) {
      return [...$errors, 'configEntries must be a list'];
    }
    foreach ($d['configEntries'] as $i => $entry) {
      if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
        $errors[] = "configEntries[$i] is not an object";
        continue;
      }
      foreach (array_diff(array_keys($entry), [...Definition::ENTRY_STRINGS, ...Definition::ENTRY_BOOLEANS]) as $unknown) {
        $errors[] = "configEntries[$i] has an unknown field $unknown";
      }
      foreach (Definition::ENTRY_STRINGS as $key) {
        if (!is_string($entry[$key] ?? null)) {
          $errors[] = "configEntries[$i].$key must be a string";
        }
      }
      foreach (Definition::ENTRY_BOOLEANS as $key) {
        if (!is_bool($entry[$key] ?? null)) {
          $errors[] = "configEntries[$i].$key must be a boolean";
        }
      }
    }
    return $errors;
  }

  /**
   * REQ-VAL-2 and REQ-VAL-9 to REQ-VAL-18. The ExtraParams flags are the policy's business.
   *
   * @param list<string> $networks the networks that exist on the server
   * @param callable(string): bool $containerExists
   */
  public static function fields(array $d, array $networks, callable $containerExists): array {
    $errors = [];
    array_walk_recursive($d, function (mixed $value, int|string $key) use (&$errors): void {
      if (is_string($value) && str_contains($value, "\0")) {
        $errors[] = "$key contains a NUL byte";
      }
    });
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]+$/', $d['name'])) {
      $errors[] = "name '{$d['name']}' is not a valid container name";
    }
    if (!preg_match(self::REFERENCE, $d['repository'])) {
      $errors[] = "repository '{$d['repository']}' is not an image reference";
    }
    foreach (self::URL_FIELDS as $key) {
      $url = $d[$key];
      if ($url !== '' && (!preg_match('~^https?://~', $url) || preg_match('/[\s"\'<>`\\\\]/', $url))) {
        $errors[] = "$key must be an http or https URL without whitespace, quotes, <, >, backquotes, or backslashes";
      }
    }
    foreach ($d['ipAddresses'] as $ip) {
      if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        $errors[] = "ipAddresses: '$ip' is neither an IPv4 address nor an IPv6 address";
      }
    }
    if ($d['macAddress'] !== '' && !preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $d['macAddress'])) {
      $errors[] = "macAddress '{$d['macAddress']}' is not 6 pairs of hexadecimal digits joined by ':'";
    }
    if ($d['cpuset'] !== '' && !preg_match('/^[0-9]+([-,][0-9]+)*$/', $d['cpuset'])) {
      $errors[] = "cpuset '{$d['cpuset']}' is not a CPU list";
    }
    if (!in_array($d['shell'], ['sh', 'bash'], true)) {
      $errors[] = "shell must be sh or bash, not '{$d['shell']}'";
    }
    if (preg_match('/^container:(.+)$/', $d['network'], $m)) {
      if (!$containerExists($m[1])) {
        $errors[] = "network {$d['network']}: no container has the name {$m[1]}";
      }
    } elseif (!in_array($d['network'], $networks, true)) {
      $errors[] = "network {$d['network']} does not exist on the server";
    }
    foreach ($d['configEntries'] as $entry) {
      array_push($errors, ...self::entry($entry));
    }
    return $errors;
  }

  /**
   * REQ-VAL-20: DockerMan's read path changes some strings on its own (it strips tags, strips
   * slashes from Overview, trims names), and a definition that does not come back unchanged
   * would show drift after every apply. So the definition goes through postToXML and xmlToVar
   * once before it is ever written, and each field that changes is named.
   */
  public static function roundTrip(array $d): array {
    $back = Definition::fromVar(Definition::readXml(Definition::toXml($d, Registry::NIL_ID)), $d['autostart']);
    $errors = [];
    foreach ($d as $key => $value) {
      if ($key !== 'configEntries' && $back[$key] !== $value) {
        $errors[] = "$key does not survive DockerMan's own round trip: " . json_encode($value, JSON_UNESCAPED_SLASHES) . ' comes back as ' . json_encode($back[$key], JSON_UNESCAPED_SLASHES);
      }
    }
    if (count($back['configEntries']) !== count($d['configEntries'])) {
      $errors[] = "configEntries does not survive DockerMan's own round trip";
      return $errors;
    }
    foreach ($d['configEntries'] as $i => $entry) {
      foreach ($entry as $key => $value) {
        if ($back['configEntries'][$i][$key] !== $value) {
          $errors[] = "config entry {$entry['target']}: $key does not survive DockerMan's own round trip: " . json_encode($value, JSON_UNESCAPED_SLASHES) . ' comes back as ' . json_encode($back['configEntries'][$i][$key], JSON_UNESCAPED_SLASHES);
        }
      }
    }
    return $errors;
  }

  private static function entry(array $e): array {
    $at = "config entry {$e['type']} {$e['target']}";
    $errors = [];
    if (!isset(Definition::TYPES[$e['type']])) {
      return ["$at: type must be one of " . implode(', ', array_keys(Definition::TYPES))];
    }
    if (!in_array($e['display'], Definition::DISPLAYS, true)) {
      $errors[] = "$at: display must be one of " . implode(', ', Definition::DISPLAYS);
    }
    switch ($e['type']) {
      case 'PATH':
        if (!self::isCleanAbsolutePath($e['value'])) {
          $errors[] = "$at: the host path '{$e['value']}' must be absolute, without . or .. segments";
        }
        if (!self::isCleanAbsolutePath($e['target'])) {
          $errors[] = "$at: the container path must be absolute, without . or .. segments";
        }
        if (!in_array($e['mode'], Definition::PATH_MODES, true)) {
          $errors[] = "$at: mode must be one of " . implode(', ', Definition::PATH_MODES);
        }
        break;
      case 'PORT':
        if (!self::isPort($e['target'])) {
          $errors[] = "$at: the container port must be a number from 1 through 65535";
        }
        if ($e['value'] !== '' && !self::isPort($e['value'])) {
          $errors[] = "$at: the host port must be a number from 1 through 65535";
        }
        if (!in_array($e['mode'], Definition::PORT_MODES, true)) {
          $errors[] = "$at: protocol must be tcp or udp";
        }
        break;
      case 'VARIABLE':
        if (!preg_match('/^[^=\s]+$/', $e['target'])) {
          $errors[] = "$at: the variable name must not be empty or contain = or whitespace";
        }
        break;
      case 'LABEL':
        if (!preg_match('/^[^=\s]+$/', $e['target'])) {
          $errors[] = "$at: the label key must not be empty or contain = or whitespace";
        } elseif (str_starts_with($e['target'], 'net.unraid.docker.') || str_starts_with($e['target'], 'tofuman.')) {
          $errors[] = "$at: label keys that start with net.unraid.docker. or tofuman. are reserved"; // REQ-VAL-9
        }
        break;
      case 'DEVICE':
        if (!str_starts_with($e['value'], '/dev/') || !self::isCleanAbsolutePath($e['value'])) {
          $errors[] = "$at: the device must be a path under /dev/";
        }
        break;
    }
    return $errors;
  }

  private static function isStringList(mixed $value): bool {
    return is_array($value) && array_is_list($value) && count(array_filter($value, is_string(...))) === count($value);
  }

  private static function isCleanAbsolutePath(string $path): bool {
    if (!str_starts_with($path, '/') || str_contains($path, '//')) {
      return false;
    }
    foreach (explode('/', trim($path, '/')) as $segment) {
      if ($segment === '.' || $segment === '..') {
        return false;
      }
    }
    return true;
  }

  private static function isPort(string $value): bool {
    return preg_match('/^[1-9][0-9]{0,4}$/', $value) === 1 && (int)$value <= 65535;
  }
}
