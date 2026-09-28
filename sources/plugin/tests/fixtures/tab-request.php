<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// One request to the endpoint of the tab, in a process of its own the way php-fpm would run it:
//   php tab-request.php '{"method": "POST", "params": {"action": "savePolicy", "text": "..."}}'

declare(strict_types=1);

$request = json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR);
$_SERVER['REQUEST_METHOD'] = $request['method'];
$_GET = $request['method'] === 'GET' ? $request['params'] : [];
$_POST = $request['method'] === 'POST' ? $request['params'] : [];
require dirname(__DIR__, 2) . '/tab/include/endpoint.php';
