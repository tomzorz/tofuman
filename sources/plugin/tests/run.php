<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// The test runner, in plain PHP: every function named test_* in tests/*Test.php runs once,
// optionally only those whose name contains the first argument. Run it through ci/test.sh,
// which provides the webgui checkout, the Docker socket, and the test network.

declare(strict_types=1);

require __DIR__ . '/../shim/bootstrap.php';
ob_end_clean(); // the bootstrap's buffer is for the shim's JSON response; tests print as they go
require __DIR__ . '/lib.php';
foreach (glob(__DIR__ . '/*Test.php') as $file) {
  require $file;
}

$filter = $argv[1] ?? '';
$ran = 0;
$failed = 0;
foreach (get_defined_functions()['user'] as $function) {
  if (!str_starts_with($function, 'test_') || ($filter !== '' && !str_contains($function, strtolower($filter)))) {
    continue;
  }
  $ran++;
  try {
    reset_state();
    $function();
    echo "ok   $function\n";
  } catch (Throwable $error) {
    $failed++;
    echo "FAIL $function: {$error->getMessage()}\n     at {$error->getFile()}:{$error->getLine()}\n";
  }
}
reset_state();
echo "$ran tests, $failed failed\n";
exit($failed > 0 || $ran === 0 ? 1 : 0);
