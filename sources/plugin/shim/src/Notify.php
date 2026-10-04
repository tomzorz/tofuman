<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/** Unraid notifications through the webgui's own notify script (REQ-MUT-23, REQ-MUT-24, REQ-UPG-7). */
final class Notify {
  /** The page that the notification opens: the Docker page, where the tab lives. */
  public const LINK = '/Docker';

  /** Never throws: a notification that cannot be raised must not change what happened (REQ-MUT-24). */
  public static function raise(Env $env, string $importance, string $subject, string $description): bool {
    if (!is_file($env->notifyScript)) {
      return false;
    }
    $command = ['php', $env->notifyScript, '-e', 'tofuman', '-s', $subject, '-d', $description, '-i', $importance, '-l', self::LINK];
    $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    return is_resource($process) && proc_close($process) === 0;
  }
}
