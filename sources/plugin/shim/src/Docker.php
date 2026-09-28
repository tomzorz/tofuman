<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/**
 * Containers, images, and templates, through DockerMan's own DockerClient and DockerTemplates
 * wherever they give an answer the shim can use.
 */
final class Docker {
  /** @return array<string, array{running: bool, marker: ?string, manager: ?string}> by container name */
  public static function containers(): array {
    global $DockerClient;
    $containers = [];
    foreach ($DockerClient->getDockerJSON('/containers/json?all=1') as $ct) {
      if (!is_array($ct) || empty($ct['Names'][0])) {
        continue;
      }
      $containers[ltrim($ct['Names'][0], '/')] = [
        'running' => ($ct['State'] ?? '') === 'running',
        'marker' => $ct['Labels'][Definition::MARKER_TARGET] ?? null,
        'manager' => $ct['Labels']['net.unraid.docker.managed'] ?? null,
      ];
    }
    return $containers;
  }

  /** @return list<string> the networks that exist, as DockerMan sees them */
  public static function networks(): array {
    global $subnet;
    return array_map('strval', array_keys($subnet));
  }

  /** The template whose <Name> is the name, found the way DockerMan's readers find it. */
  public static function templatePath(string $name): ?string {
    global $DockerTemplates;
    $path = $DockerTemplates->getUserTemplate($name);
    return $path === false ? null : $path;
  }

  /** The file a template for the name belongs in, chosen the way DockerMan's writers choose it. */
  public static function templateWritePath(string $name): string {
    global $DockerTemplates;
    return $DockerTemplates->getUserTemplatePath($name);
  }

  /**
   * REQ-IMG-3. DockerMan's pullImage() drops the error text, so this repeats its callback and
   * keeps the text. It also catches the failure DockerMan misses: for a repository that does
   * not exist, the Docker API answers with an HTTP error and a `message`, not with an `error`
   * event in the stream.
   */
  public static function pull(string $image): void {
    global $DockerClient, $DockerUpdate;
    $image = self::withTag($image);
    $error = '';
    $response = $DockerClient->pullImage($image, function (string $line) use (&$error, $image, $DockerUpdate): void {
      $event = json_decode($line, true);
      if (!is_array($event)) {
        return;
      }
      if (isset($event['error'])) {
        $error = (string)$event['error'];
      }
      $status = (string)($event['status'] ?? '');
      if (str_starts_with($status, 'Digest: ')) {
        $DockerUpdate->setUpdateStatus($image, substr($status, 8));
      }
    });
    if ($error === '' && isset($response['message'])) {
      $error = (string)$response['message'];
    }
    if ($error !== '') {
      throw new Failure('pull', "the pull of $image failed: $error");
    }
  }

  /**
   * A reference without a tag or a digest gets :latest. DockerMan's own check reads the port
   * of a registry (`host:5000/image`) as a tag, and the Docker API then pulls every tag.
   */
  public static function withTag(string $image): string {
    $slash = strrpos($image, '/');
    $last = $slash === false ? $image : substr($image, $slash + 1);
    return str_contains($last, ':') || str_contains($last, '@') ? $image : "$image:latest";
  }

  /**
   * Runs the command from xmlToCommand through /bin/sh, as DockerMan's execCommand does, but
   * keeps the output for the error.
   */
  public static function run(string $command): void {
    global $DockerClient;
    $process = proc_open(['/bin/sh', '-c', "$command 2>&1"], [1 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
      throw new Failure('create', 'cannot start /bin/sh');
    }
    $output = trim((string)stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    $code = proc_close($process);
    $DockerClient->flushCaches();
    if ($code !== 0) {
      throw new Failure('create', "docker create failed with exit code $code: $output");
    }
  }

  public static function start(string $name): void {
    global $DockerClient;
    $result = $DockerClient->startContainer($name);
    if ($result !== true && $result !== 'Container already started') {
      throw new Failure('start', "the start of $name failed: $result");
    }
  }

  public static function stop(string $name): void {
    global $DockerClient;
    $result = $DockerClient->stopContainer($name);
    if ($result !== true && $result !== 'Container already started') { // DockerClient's text for 304, not modified
      throw new Failure('stop', "the stop of $name failed: $result");
    }
  }

  public static function remove(string $name): void {
    global $DockerClient;
    $result = $DockerClient->removeContainer($name, false, 1);
    if ($result !== true) {
      throw new Failure('remove', "the removal of $name failed: $result");
    }
  }

  /** For cleanup after a failure, where a container that is already gone is fine. */
  public static function removeIfExists(string $name): void {
    if (isset(self::containers()[$name])) {
      self::remove($name);
    }
  }
}
