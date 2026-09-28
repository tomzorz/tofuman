<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// Something for the tab preview to show: the test policy, one managed container, and one
// hand-made container, in the default data directory of the shim.

declare(strict_types=1);

require dirname(__DIR__, 2) . '/shim/bootstrap.php';
ob_end_clean();
require dirname(__DIR__) . '/lib.php';

reset_state();
$env = Tofuman\Env::fromGlobals();
write_policy($env);
(new Tofuman\Operations($env))->create(definition(), caller());
hand_made('my-tofumantest-hand.xml', 'tofumantest-hand');
echo "seeded {$env->dataDir}\n";
