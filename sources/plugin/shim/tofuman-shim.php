<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// The shim: one action per run, a JSON request on stdin, a JSON response on stdout.
// The API module and the tab are its only callers.

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

exit(Tofuman\Shim::run(stream_get_contents(STDIN) ?: ''));
