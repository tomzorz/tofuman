<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// Something for the tab preview to show, in the default data directory of the shim: the test
// policy; one managed container that an update changed; a create that died at its start check;
// a check that the policy refused, which leaves policy gaps; one hand-made container that is
// ready to adopt and one that is not.

declare(strict_types=1);

require dirname(__DIR__, 2) . '/shim/bootstrap.php';
ob_end_clean();
require dirname(__DIR__) . '/lib.php';

reset_state();
$env = Tofuman\Env::fromGlobals();
write_policy($env);
$operations = new Tofuman\Operations($env);
$provider = caller() + ['providerVersion' => '0.2.0'];
$id = $operations->create(definition(), $provider)['id'];
$operations->update($id, definition(['extraParams' => ['--hostname', 'beta']]), $provider);
try {
  $operations->create(definition([
    'name' => 'tofumantest-fresh-build',
    'autostart' => true,
    'postArgs' => ['sh', '-c', 'echo starting the fresh build; echo missing setting DATABASE_URL >&2; exit 3'],
    'configEntries' => [entry('PATH', 'Config', '/config', MOUNTED . '/appdata/fresh', '', 'rw')],
  ]), $provider, ['startCheck' => 3]);
} catch (Tofuman\Failure) {
  // the point: the operation log and the activity show it
}
$operations->checkQuery(null, definition(['name' => 'tofumantest-gpu', 'network' => 'host', 'extraParams' => ['--gpus', 'all']]), $provider);
hand_made('my-tofumantest-hand.xml', 'tofumantest-hand');
hand_made('my-tofumantest-shell.xml', 'tofumantest-shell', false);
echo "seeded {$env->dataDir}\n";
