<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/** The `docker create` flags that ExtraParams can carry (REQ-VAL-6 to REQ-VAL-8). */
final class Flags {
  /** Flags that bypass a structured field or the policy (REQ-VAL-7). No policy can allow them. */
  public const REFUSED = [
    '-v', '--volume', '--mount', '--volumes-from',
    '--privileged', '--cap-add', '--security-opt', '--device', '--cgroup-parent',
    '--pid', '--ipc', '--uts', '--userns',
    '--net', '--network', '--ip', '--ip6', '--mac-address', '-p', '--publish',
    '-e', '--env', '--env-file', '-l', '--label', '--label-file',
    '--cidfile',
  ];

  /** The flags a policy can list, each with whether it takes a value (REQ-VAL-8). */
  public const KNOWN = [
    '--add-host' => true,
    '--cpu-shares' => true,
    '--cpus' => true,
    '--dns' => true,
    '--dns-option' => true,
    '--dns-search' => true,
    '--domainname' => true,
    '--entrypoint' => true,
    '--gpus' => true,
    '--group-add' => true,
    '--health-cmd' => true,
    '--health-interval' => true,
    '--health-retries' => true,
    '--health-start-period' => true,
    '--health-timeout' => true,
    '--hostname' => true,
    '--init' => false,
    '--log-driver' => true,
    '--log-opt' => true,
    '--memory' => true,
    '--memory-reservation' => true,
    '--memory-swap' => true,
    '--no-healthcheck' => false,
    '--oom-score-adj' => true,
    '--pids-limit' => true,
    '--read-only' => false,
    '--restart' => true,
    '--runtime' => true,
    '--shm-size' => true,
    '--stop-signal' => true,
    '--stop-timeout' => true,
    '--sysctl' => true,
    '--tmpfs' => true,
    '--tty' => false,
    '--ulimit' => true,
    '--user' => true,
    '--workdir' => true,
  ];

  /** Short spellings, so that `-u 99:100` in an adopted template means `--user`. */
  public const ALIASES = ['-c' => '--cpu-shares', '-h' => '--hostname', '-m' => '--memory', '-t' => '--tty', '-u' => '--user', '-w' => '--workdir'];

  /**
   * @param list<string> $args
   * @return list<array{0: string, 1: ?string}> each flag in its long form, with its value
   */
  public static function parse(array $args): array {
    $flags = [];
    $errors = [];
    for ($i = 0, $count = count($args); $i < $count; $i++) {
      $arg = $args[$i];
      if (!str_starts_with($arg, '-')) {
        $errors[] = "ExtraParams: '$arg' is not a flag";
        continue;
      }
      [$flag, $value] = str_contains($arg, '=') ? explode('=', $arg, 2) : [$arg, null];
      $flag = self::ALIASES[$flag] ?? $flag;
      if (in_array($flag, self::REFUSED, true)) {
        $errors[] = "ExtraParams: $flag is never allowed";
        if ($value === null && isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '-')) {
          $i++; // its value, so it does not come back as a second error
        }
        continue;
      }
      if (!array_key_exists($flag, self::KNOWN)) {
        $errors[] = "ExtraParams: tofuman does not know the flag $flag";
        continue;
      }
      if (self::KNOWN[$flag] && $value === null) {
        if (!isset($args[$i + 1])) {
          $errors[] = "ExtraParams: $flag needs a value";
          continue;
        }
        $value = $args[++$i];
      } elseif (!self::KNOWN[$flag] && $value !== null && $value !== 'true' && $value !== 'false') {
        $errors[] = "ExtraParams: $flag takes no value";
        continue;
      }
      $flags[] = [$flag, $value];
    }
    if ($errors) {
      throw new Refusal($errors);
    }
    return $flags;
  }
}
