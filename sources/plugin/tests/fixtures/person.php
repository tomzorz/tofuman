<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// The person in the webgui for the acceptance test of the e2e tool (REQ-TST-12), run by the test
// server: it creates a hand-made container from the adoption test template, as Add Container
// does, and adopts it, as the tab does. Its one argument is the name of the container.

declare(strict_types=1);

require dirname(__DIR__, 2) . '/shim/bootstrap.php';
ob_end_clean();
require dirname(__DIR__) . '/lib.php';

$name = $argv[1] ?? '';
if (!preg_match('/^tofumantest-[a-z0-9-]+$/', $name)) {
  fwrite(STDERR, "person.php takes the name of a container that starts with tofumantest-\n");
  exit(2);
}
from_adoption_test_template($name);
(new Tofuman\Operations(Tofuman\Env::fromGlobals()))->adopt($name, caller(true));
echo "adopted $name\n";
