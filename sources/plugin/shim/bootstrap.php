<?php
// SPDX-License-Identifier: GPL-2.0-or-later
//
// Loads DockerMan the way its own scripts/update_container does, then the shim's classes.
// This has to run at file scope: DockerClient.php sets $driver and $dockerManPaths as
// globals while it loads, and xmlToVar/xmlToCommand read $subnet, $var and $driver
// through `global`. Required from the file scope of the entry point and of the tests.

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);
ob_start(); // webgui code can echo; stdout belongs to the JSON response

$docroot = getenv('TOFUMAN_DOCROOT') ?: '/usr/local/emhttp';
require_once "$docroot/webGui/include/Wrappers.php";
extract(parse_plugin_cfg('dynamix', true));
$_SERVER['REQUEST_URI'] = '';
$login_locale = _var($display, 'locale'); // being set is what keeps Translations.php from session_start()
require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";
$var = (array)@parse_ini_file(getenv('TOFUMAN_VAR_INI') ?: '/var/local/emhttp/var.ini');
$custom = DockerUtil::custom();
$subnet = DockerUtil::network($custom);
$DockerClient = new DockerClient();
$DockerUpdate = new DockerUpdate();
$DockerTemplates = new DockerTemplates();
$tofumanLoadedFiles = get_included_files(); // the webgui files that a tested build covers (REQ-UPG-2)

foreach (['Refusal', 'Failure', 'Env', 'Json', 'Arguments', 'Flags', 'Definition', 'Validator', 'Policy', 'Registry', 'Autostart', 'Mounts', 'TestedBuild', 'Docker', 'Audit', 'LoadRecord', 'Operations', 'Shim'] as $class) {
  require_once __DIR__ . "/src/$class.php";
}
