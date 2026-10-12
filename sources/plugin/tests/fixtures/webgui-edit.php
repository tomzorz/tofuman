<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// An edit in the webgui that runs beside an update of the shim, in a process of its own and
// without the lock of the shim, as CreateDocker.php does. It reads the template, says "ready",
// waits until the update writes its own template, and then does one of two things:
//
//   remove          The test wrote the edited template, as CreateDocker.php does first. This
//                   removes the container while the stop of the update still waits for it,
//                   and then runs the command of the edit (REQ-MUT-9, REQ-MUT-28).
//   rewrite EDITED  This waits until the update has created its container, and then copies
//                   the file EDITED over the template, while the start check runs (REQ-MUT-27).
//
// Arguments: the mode, the name of a container that starts with tofumantest-, and EDITED.

declare(strict_types=1);

require dirname(__DIR__, 2) . '/shim/bootstrap.php';
ob_end_clean();
require dirname(__DIR__) . '/lib.php';

function wait_until(callable $done, string $what): void {
  $deadline = microtime(true) + 60;
  while (!$done()) {
    if (microtime(true) > $deadline) {
      fwrite(STDERR, "$what within 60 seconds\n");
      exit(1);
    }
    usleep(5_000);
  }
}

[, $mode, $name, $edited] = $argv + [null, '', '', ''];
if (!preg_match('/^tofumantest-[a-z0-9-]+$/', $name) || !($mode === 'remove' || ($mode === 'rewrite' && is_file($edited)))) {
  fwrite(STDERR, "webgui-edit.php takes remove NAME, or rewrite NAME EDITED\n");
  exit(2);
}
$template = template_file($name);
$before = (string)file_get_contents($template);
$id = $DockerClient->getContainerDetails($name)['Id'] ?? '';
if ($mode === 'remove') {
  [$command] = xmlToCommand($before, false); // CreateDocker.php takes its command from the edit, before anything else
}
echo "ready\n";
wait_until(fn() => (string)@file_get_contents($template) !== $before, "the update did not write the template of $name");
if ($mode === 'rewrite') {
  wait_until(fn() => !in_array($DockerClient->getContainerDetails($name)['Id'] ?? '', ['', $id], true), "the update did not create a new $name");
  copy($edited, $template);
  echo "rewrote the template\n";
  exit(0);
}
echo 'removed: ' . var_export(removeContainer($name, false, false), true) . "\n"; // DockerMan's own helper
exec(str_replace('/docker create ', '/docker run -d ', $command) . ' 2>&1', $output, $code);
echo "ran with exit code $code: " . implode("\n", $output) . "\n";
