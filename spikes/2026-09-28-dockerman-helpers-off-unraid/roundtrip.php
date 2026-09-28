<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Spike: run DockerMan's template helpers (webgui Helpers.php) outside Unraid.
//
//   php roundtrip.php write   <webgui-checkout> <templates-dir> <out-dir>
//   php roundtrip.php compare <webgui-checkout> <templates-dir> <out-dir>
//
// write:   per template, xmlToVar -> post shape -> postToXML -> xmlToVar, report what the
//          write changed, and save <Name>.xml (the rewritten template) and <Name>.sh (the
//          xmlToCommand output, made runnable on a plain Docker host).
// compare: read <Name>.inspect.json (docker inspect of what <Name>.sh created) and check it
//          against the template.
//
// CUSTOM_NETWORKS (comma list, default br0) names the host's custom networks, all macvlan.

[, $mode, $webgui, $templatesDir, $outDir] = $argv;

// Unraid loads _var() from webGui/include/Wrappers.php. The helpers only use it for Tailscale.
function _var(&$name, $key = null, $default = '') {
  return is_null($key) ? ($name ?? $default) : ($name[$key] ?? $default);
}

// Globals the helpers read. On Unraid they come from /var/local/emhttp/var.ini and the live
// docker network list. xmlToVar only checks $subnet's keys; xmlToCommand reads $driver.
$docroot = "$webgui/emhttp";
$var     = ['timeZone' => 'Etc/UTC', 'NAME' => 'tower'];
$subnet  = ['bridge' => '', 'host' => '', 'none' => ''];
$driver  = ['bridge' => 'bridge', 'host' => 'host', 'none' => 'none'];
foreach (explode(',', getenv('CUSTOM_NETWORKS') ?: 'br0') as $net) {
  $subnet[$net] = '';
  $driver[$net] = 'macvlan';
}

require "$docroot/plugins/dynamix.docker.manager/include/Helpers.php";

// The webgui's Edit form turns a template into this shape in the browser. The plugin has to
// do the same on the server, so this mapping is the part of the spike that carries over.
function varToPost(array $v): array {
  $post = [
    'contName'        => $v['Name'],
    'contRepository'  => $v['Repository'],
    'contRegistry'    => $v['Registry'],
    'contMyIP'        => $v['MyIP'],
    'contMyMAC'       => $v['MyMAC'],
    'contShell'       => $v['Shell'],
    'contPrivileged'  => strtolower($v['Privileged']) === 'true' ? 'on' : '',
    'contSupport'     => $v['Support'],
    'contProject'     => $v['Project'],
    'contReadMe'      => $v['ReadMe'],
    'contOverview'    => $v['Overview'],
    'contCategory'    => $v['Category'],
    'contWebUI'       => $v['WebUI'],
    'contTemplateURL' => $v['TemplateURL'],
    'contIcon'        => $v['Icon'],
    'contExtraParams' => $v['ExtraParams'],
    'contPostArgs'    => $v['PostArgs'],
    'contCPUset'      => $v['CPUset'],
    'contDonateText'  => $v['DonateText'],
    'contDonateLink'  => $v['DonateLink'],
    'contRequires'    => $v['Requires'],
    'TSstatedir'      => $v['TailscaleStateDir'],
  ];
  if (preg_match('/^container:(.*)/', $v['Network'], $m)) {
    $post['contNetwork'] = 'container';
    $post['netCONT']     = $m[1];
  } else {
    $post['contNetwork'] = $v['Network'];
  }
  if (strtolower($v['TailscaleEnabled']) === 'true') {
    fwrite(STDERR, "{$v['Name']}: Tailscale templates are not mapped by this spike\n");
  }
  foreach (['Name', 'Target', 'Default', 'Mode', 'Description', 'Type', 'Display', 'Required', 'Mask'] as $attr) {
    $post["conf$attr"] = array_map(fn($c) => $c[$attr] ?? '', $v['Config']);
  }
  $post['confValue'] = array_map(fn($c) => $c['Value'], $v['Config']);
  return $post;
}

// Template -> [path => value], so two versions of it can be diffed.
function flatten(string $xml): array {
  $x = simplexml_load_string($xml);
  $flat = [];
  foreach ($x->children() as $el) {
    if ($el->getName() !== 'Config') $flat[$el->getName()] = trim((string)$el);
  }
  $i = 0;
  foreach ($x->Config as $c) {
    $flat["Config[$i]"] = (string)$c;
    foreach ($c->attributes() as $k => $val) $flat["Config[$i]@$k"] = (string)$val;
    $i++;
  }
  return $flat;
}

function diffVars(array $a, array $b, string $at = ''): array {
  $out = [];
  foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
    $x = $a[$k] ?? null;
    $y = $b[$k] ?? null;
    if (is_array($x) && is_array($y)) $out = array_merge($out, diffVars($x, $y, "$at/$k"));
    elseif ($x !== $y) $out["$at/$k"] = [$x, $y];
  }
  return $out;
}

function write(string $templatesDir, string $outDir): void {
  global $docroot;
  @mkdir($outDir, 0777, true);
  $stable = 0;
  $files  = glob("$templatesDir/*.xml");
  foreach ($files as $file) {
    $v1   = xmlToVar($file);
    $xml2 = postToXML(varToPost($v1));
    $v2   = xmlToVar($xml2);
    $drift = diffVars($v1, $v2);
    if (!$drift) $stable++;

    $a = flatten(file_get_contents($file));
    $b = flatten($xml2);
    unset($a['DateInstalled'], $b['DateInstalled']);
    $dropped = array_keys(array_diff_key($a, $b));
    $changed = [];
    foreach (array_intersect_key($a, $b) as $k => $val) {
      if ($val !== $b[$k]) $changed[$k] = [$val, $b[$k]];
    }
    echo "== {$v1['Name']}: " . ($drift ? 'NOT stable' : 'stable') . "\n";
    if ($drift)   echo "   xmlToVar drift after one write: " . json_encode($drift, JSON_UNESCAPED_SLASHES) . "\n";
    if ($dropped) echo "   dropped by the write: " . implode(', ', $dropped) . "\n";
    if ($changed) echo "   changed by the write: " . json_encode($changed, JSON_UNESCAPED_SLASHES) . "\n";

    // xmlToCommand builds a string for Unraid's docker wrapper; point it at plain docker, a
    // small stand-in image, and a prefixed name so nothing collides on the test host.
    [$cmd, $name, $repo] = xmlToCommand($xml2);
    $cmd = str_replace(
      ["$docroot/plugins/dynamix.docker.manager/scripts/docker create", escapeshellarg($repo), '--name=' . escapeshellarg($name)],
      ['docker create', escapeshellarg('busybox:latest'), '--name=' . escapeshellarg("tofuman-spike-$name")],
      $cmd);
    file_put_contents("$outDir/$name.xml", $xml2);
    file_put_contents("$outDir/$name.sh", $cmd . "\n");
  }
  printf("%d/%d templates stable through xmlToVar -> postToXML -> xmlToVar\n", $stable, count($files));
}

// Check what docker actually created against the template.
function compare(string $templatesDir, string $outDir): void {
  global $driver;
  foreach (glob("$templatesDir/*.xml") as $file) {
    $v = xmlToVar($file);
    $inspectFile = "$outDir/{$v['Name']}.inspect.json";
    if (!is_file($inspectFile)) {
      echo "== {$v['Name']}: not created (see run output)\n";
      continue;
    }
    $c = json_decode(file_get_contents($inspectFile), true)[0];
    $misses = [];
    $binds  = $c['HostConfig']['Binds'] ?? [];
    $env    = $c['Config']['Env'] ?? [];
    $labels = $c['Config']['Labels'] ?? [];
    foreach ($v['Config'] as $cf) {
      $value = strlen($cf['Value']) ? $cf['Value'] : $cf['Default'];
      switch (strtolower($cf['Type'])) {
        case 'path':
          if (trim($value) && trim($cf['Target']) && !in_array("$value:{$cf['Target']}:{$cf['Mode']}", $binds)) $misses[] = "bind $value:{$cf['Target']}";
          break;
        case 'variable':
          if (strlen($cf['Target']) && !in_array("{$cf['Target']}=$value", $env)) $misses[] = "env {$cf['Target']}";
          break;
        case 'label':
          if (($labels[$cf['Target']] ?? null) !== $value) $misses[] = "label {$cf['Target']}";
          break;
      }
    }
    if (($labels['net.unraid.docker.managed'] ?? '') !== 'dockerman') $misses[] = 'managed label';
    $net = $c['NetworkSettings']['Networks'][$v['Network']] ?? null;
    if (($driver[$v['Network']] ?? '') === 'macvlan') {
      if ($v['MyIP'] && ($net['IPAMConfig']['IPv4Address'] ?? '') !== $v['MyIP']) $misses[] = "ip {$v['MyIP']}";
      if ($v['MyMAC'] && strtolower($net['MacAddress'] ?? '') !== strtolower($v['MyMAC'])) $misses[] = "mac {$v['MyMAC']} (got '" . ($net['MacAddress'] ?? '') . "')";
    }
    if (strlen(trim($v['PostArgs'])) && implode(' ', $c['Config']['Cmd'] ?? []) !== trim($v['PostArgs'])) $misses[] = 'PostArgs as Cmd';
    echo "== {$v['Name']}: " . ($misses ? 'MISSING ' . implode('; ', $misses) : 'matches the template') . "\n";
    if (strlen($v['ExtraParams'])) {
      $hc = $c['HostConfig'];
      echo "   ExtraParams '{$v['ExtraParams']}' -> User=" . json_encode($c['Config']['User'] ?? '') . ' Hostname=' . json_encode($c['Config']['Hostname'] ?? '')
        . ' Dns=' . json_encode($hc['Dns'] ?? []) . ' Sysctls=' . json_encode($hc['Sysctls'] ?? []) . ' Restart=' . json_encode($hc['RestartPolicy']['Name'] ?? '')
        . ' Runtime=' . json_encode($hc['Runtime'] ?? '') . ' DeviceRequests=' . json_encode($hc['DeviceRequests'] ?? []) . "\n";
    }
  }
}

$mode === 'write' ? write($templatesDir, $outDir) : compare($templatesDir, $outDir);
