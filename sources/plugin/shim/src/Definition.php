<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/**
 * A template in the shape that the API module and the provider exchange (spec sections 11
 * and 15). Reads always go through DockerMan's xmlToVar and writes through its postToXML,
 * so a definition means exactly what the webgui would make of it.
 */
final class Definition {
  public const TYPES = ['PATH' => 'Path', 'PORT' => 'Port', 'VARIABLE' => 'Variable', 'LABEL' => 'Label', 'DEVICE' => 'Device'];
  public const DISPLAYS = ['always', 'always-hide', 'advanced', 'advanced-hide'];
  public const PATH_MODES = ['rw', 'ro', 'rw,slave', 'rw,shared', 'ro,slave', 'ro,shared'];
  public const PORT_MODES = ['tcp', 'udp'];
  public const MARKER_TARGET = 'tofuman.id';
  public const MARKER_NAME = 'Managed by tofu';
  public const MARKER_DESCRIPTION = 'tofu manages this container. A change here shows up as drift, and the next apply reverts the change.';

  public const STRINGS = ['name', 'repository', 'network', 'macAddress', 'cpuset', 'shell', 'webUi', 'icon', 'overview', 'category', 'support', 'project', 'readMe', 'templateUrl', 'registry', 'donateText', 'donateLink', 'requires'];
  public const BOOLEANS = ['autostart', 'privileged'];
  public const LISTS = ['ipAddresses', 'extraParams', 'postArgs'];
  public const ENTRY_STRINGS = ['type', 'name', 'target', 'value', 'default', 'mode', 'description', 'display'];
  public const ENTRY_BOOLEANS = ['required', 'mask'];

  /** DockerMan's reading of a template file. */
  public static function read(string $path): array {
    return \xmlToVar($path);
  }

  /** DockerMan's reading of template text. xmlToVar also takes text, but probes it with is_file() first. */
  public static function readXml(string $xml): array {
    $path = tempnam(sys_get_temp_dir(), 'tofuman');
    try {
      file_put_contents($path, $xml);
      return \xmlToVar($path);
    } finally {
      unlink($path);
    }
  }

  /** The definition of a template. The marker is not part of it, and the autostart flag lives outside the template. */
  public static function fromVar(array $v, bool $autostart): array {
    $entries = [];
    foreach ($v['Config'] as $c) {
      $type = strtoupper((string)($c['Type'] ?? ''));
      if ($type === 'LABEL' && ($c['Target'] ?? '') === self::MARKER_TARGET) {
        continue;
      }
      $entries[] = [
        'type' => $type,
        'name' => (string)($c['Name'] ?? ''),
        'target' => (string)($c['Target'] ?? ''),
        'value' => (string)($c['Value'] ?? ''),
        'default' => (string)($c['Default'] ?? ''),
        // CA templates carry junk such as Mode="{3}" on variables; DockerMan ignores the mode there
        'mode' => in_array($type, ['PATH', 'PORT'], true) ? (string)($c['Mode'] ?? '') : '',
        'description' => (string)($c['Description'] ?? ''),
        'display' => in_array($c['Display'] ?? '', self::DISPLAYS, true) ? $c['Display'] : 'always',
        'required' => ($c['Required'] ?? '') === 'true',
        'mask' => ($c['Mask'] ?? '') === 'true',
      ];
    }
    return [
      'name' => $v['Name'],
      'repository' => $v['Repository'],
      'network' => $v['Network'],
      'ipAddresses' => preg_split('/[\s,]+/', trim($v['MyIP']), -1, PREG_SPLIT_NO_EMPTY),
      'macAddress' => $v['MyMAC'],
      'autostart' => $autostart,
      'privileged' => strtolower($v['Privileged']) === 'true',
      'cpuset' => $v['CPUset'],
      'shell' => $v['Shell'] === '' ? 'sh' : $v['Shell'],
      'extraParams' => Arguments::split($v['ExtraParams']),
      'postArgs' => Arguments::split($v['PostArgs']),
      'webUi' => $v['WebUI'],
      'icon' => $v['Icon'],
      'overview' => $v['Overview'],
      'category' => $v['Category'],
      'support' => $v['Support'],
      'project' => $v['Project'],
      'readMe' => $v['ReadMe'],
      'templateUrl' => $v['TemplateURL'],
      'registry' => $v['Registry'],
      'donateText' => $v['DonateText'],
      'donateLink' => $v['DonateLink'],
      'requires' => $v['Requires'],
      'configEntries' => self::sortEntries($entries),
    ];
  }

  public static function markerOf(array $v): ?string {
    foreach ($v['Config'] as $c) {
      if (strtolower((string)($c['Type'] ?? '')) === 'label' && ($c['Target'] ?? '') === self::MARKER_TARGET) {
        return (string)$c['Value'];
      }
    }
    return null;
  }

  public static function tailscaleEnabled(array $v): bool {
    return strtolower((string)($v['TailscaleEnabled'] ?? '')) === 'true';
  }

  /** Accepted spellings that DockerMan would store differently, turned into the stored form. */
  public static function normalize(array $d): array {
    if ($d['shell'] === '') {
      $d['shell'] = 'sh';
    }
    $mac = \normalizeMacAddress($d['macAddress']);
    if ($mac !== '') {
      $d['macAddress'] = $mac; // an invalid address stays as sent, for the validator to name
    }
    foreach ($d['configEntries'] as &$entry) {
      if ($entry['value'] === '') {
        $entry['value'] = $entry['default']; // REQ-DEF-4
      }
      if (!in_array($entry['type'], ['PATH', 'PORT'], true)) {
        $entry['mode'] = '';
      }
    }
    unset($entry);
    $d['configEntries'] = self::sortEntries($d['configEntries']);
    return $d;
  }

  public static function toXml(array $d, string $managedId): string {
    return \postToXML(self::toPost($d, $managedId));
  }

  /** The form fields that the webgui's Edit page would post for this definition, marker last (REQ-OWN-3). */
  public static function toPost(array $d, string $managedId): array {
    $entries = $d['configEntries'];
    $entries[] = [
      'type' => 'LABEL', 'name' => self::MARKER_NAME, 'target' => self::MARKER_TARGET, 'value' => $managedId, 'default' => '',
      'mode' => '', 'description' => self::MARKER_DESCRIPTION, 'display' => 'always', 'required' => true, 'mask' => false,
    ];
    $post = [
      'contName' => $d['name'],
      'contRepository' => $d['repository'],
      'contRegistry' => $d['registry'],
      'contMyIP' => implode(',', $d['ipAddresses']),
      'contMyMAC' => $d['macAddress'],
      'contShell' => $d['shell'],
      'contPrivileged' => $d['privileged'] ? 'on' : '',
      'contSupport' => $d['support'],
      'contProject' => $d['project'],
      'contReadMe' => $d['readMe'],
      'contOverview' => $d['overview'],
      'contCategory' => $d['category'],
      'contWebUI' => $d['webUi'],
      'contTemplateURL' => $d['templateUrl'],
      'contIcon' => $d['icon'],
      'contExtraParams' => Arguments::join($d['extraParams']),
      'contPostArgs' => Arguments::join($d['postArgs']),
      'contCPUset' => $d['cpuset'],
      'contDonateText' => $d['donateText'],
      'contDonateLink' => $d['donateLink'],
      'contRequires' => $d['requires'],
      'TSstatedir' => '',
    ];
    if (preg_match('/^container:(.+)$/', $d['network'], $m)) {
      $post['contNetwork'] = 'container';
      $post['netCONT'] = $m[1];
    } else {
      $post['contNetwork'] = $d['network'];
    }
    $post['confType'] = array_map(fn(array $e) => self::TYPES[$e['type']] ?? $e['type'], $entries);
    foreach (['Name' => 'name', 'Target' => 'target', 'Value' => 'value', 'Default' => 'default', 'Mode' => 'mode', 'Description' => 'description', 'Display' => 'display'] as $field => $key) {
      $post["conf$field"] = array_column($entries, $key);
    }
    $post['confRequired'] = array_map(fn(array $e) => $e['required'] ? 'true' : 'false', $entries);
    $post['confMask'] = array_map(fn(array $e) => $e['mask'] ? 'true' : 'false', $entries);
    return $post;
  }

  public static function hash(array $d): string {
    return Json::hash($d);
  }

  /** REQ-DEF-10: type order path, port, variable, label, device; the order within each type stays. */
  private static function sortEntries(array $entries): array {
    $rank = array_flip(array_keys(self::TYPES));
    $indexed = array_map(null, array_keys($entries), $entries);
    usort($indexed, fn(array $a, array $b) => [$rank[$a[1]['type']] ?? 99, $a[0]] <=> [$rank[$b[1]['type']] ?? 99, $b[0]]);
    return array_column($indexed, 1);
  }
}
