<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// Stands in for the webgui's notify script in the tests: it records its arguments, one JSON
// list per line, in the file that TOFUMAN_NOTIFY_LOG names.

file_put_contents((string)getenv('TOFUMAN_NOTIFY_LOG'), json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
