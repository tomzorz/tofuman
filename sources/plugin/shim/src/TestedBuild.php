<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/**
 * Tested builds (spec section 12). DockerMan's helpers are internal to the webgui and change
 * between Unraid releases without notice, so the shim writes nothing unless every webgui file
 * it loaded matches a set of hashes that a plugin release tested.
 */
final class TestedBuild {
  /** Not loaded, but every create runs through it. */
  public const DOCKER_SCRIPT = 'plugins/dynamix.docker.manager/scripts/docker';

  /**
   * The webgui files in this process: everything loaded from under the docroot except the
   * plugin's own files, which live there too on a server. On Unraid that includes
   * local_prepend.php, which php.ini prepends to every run.
   *
   * @return array<string, string> path under the docroot => SHA-256 of the file
   */
  public static function current(string $docroot): array {
    global $tofumanLoadedFiles;
    $root = rtrim(realpath($docroot) ?: $docroot, '/') . '/';
    $own = rtrim(realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2), '/') . '/';
    $files = [];
    foreach ($tofumanLoadedFiles as $file) {
      if (str_starts_with($file, $root) && !str_starts_with($file, $own)) {
        $files[substr($file, strlen($root))] = hash_file('sha256', $file);
      }
    }
    $files[self::DOCKER_SCRIPT] = hash_file('sha256', $root . self::DOCKER_SCRIPT);
    ksort($files);
    return $files;
  }

  /** The name of the tested build that the files match, or null. */
  public static function match(array $current, string $testedBuildsFile): ?string {
    foreach (self::builds($testedBuildsFile) as $build) {
      if ($build['files'] == $current) {
        return $build['name'];
      }
    }
    return null;
  }

  /** REQ-UPG-3. */
  public static function refuseIfUntested(Env $env): void {
    $mismatches = self::mismatches($env);
    if ($mismatches) {
      throw new Refusal($mismatches);
    }
  }

  /** @return list<string> why the webgui files match no tested build, each file named; empty when they match one */
  public static function mismatches(Env $env): array {
    $current = self::current($env->docroot);
    if (self::match($current, $env->testedBuildsFile) !== null) {
      return [];
    }
    $builds = self::builds($env->testedBuildsFile);
    $errors = [];
    foreach ($current as $path => $hash) {
      if (!array_filter($builds, fn(array $build) => ($build['files'][$path] ?? null) === $hash)) {
        $errors[] = "the webgui file $path matches no tested build of this plugin release";
      }
    }
    return $errors ?: ['the webgui files match no tested build of this plugin release as a set'];
  }

  private static function builds(string $testedBuildsFile): array {
    return Json::read($testedBuildsFile)['builds'] ?? [];
  }
}
