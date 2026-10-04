<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/**
 * Containers, images, and templates, through DockerMan's own DockerClient and DockerTemplates
 * wherever they give an answer the shim can use.
 */
final class Docker {
  /** The icon that DockerMan records for every container without one of its own. */
  public const DEFAULT_ICON = '/plugins/dynamix.docker.manager/images/question.png';

  /** How many log lines a failed start check keeps (REQ-MUT-19). */
  public const LOG_LINES = 30;

  /** @return array<string, array{running: bool, marker: ?string, manager: ?string, image: string}> by container name */
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
        'image' => (string)($ct['Image'] ?? ''),
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
   *
   * @return ?string the digest of the image that the pull left, for the operation log
   */
  public static function pull(string $image): ?string {
    global $DockerClient, $DockerUpdate;
    $image = self::withTag($image);
    $error = '';
    $digest = null;
    $response = $DockerClient->pullImage($image, function (string $line) use (&$error, &$digest, $image, $DockerUpdate): void {
      $event = json_decode($line, true);
      if (!is_array($event)) {
        return;
      }
      if (isset($event['error'])) {
        $error = (string)$event['error'];
      }
      $status = (string)($event['status'] ?? '');
      if (str_starts_with($status, 'Digest: ')) {
        $digest = substr($status, 8);
        $DockerUpdate->setUpdateStatus($image, $digest);
      }
    });
    if ($error === '' && isset($response['message'])) {
      $error = (string)$response['message'];
    }
    if ($error !== '') {
      throw new Failure('pull', "the pull of $image failed: $error");
    }
    return $digest;
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
   *
   * @return string the output: the ID of the new container
   */
  public static function run(string $command): string {
    global $DockerClient;
    [$code, $output] = self::shell("$command 2>&1");
    $DockerClient->flushCaches();
    if ($code !== 0) {
      throw new Failure('create', "docker create failed with exit code $code: $output");
    }
    return $output;
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
    $result = $DockerClient->removeContainer($name, false, self::iconCacheLevel($name));
    if ($result !== true) {
      throw new Failure('remove', "the removal of $name failed: $result");
    }
  }

  /**
   * REQ-MUT-26. DockerMan's removeContainer deletes the icon it recorded for the container when
   * the cache level is 1, and on webgui 7.3.2 that record is the shared default icon for every
   * container without one of its own: the Docker page loses that icon until the next boot.
   */
  public static function iconCacheLevel(string $name): int {
    global $dockerManPaths;
    $info = \DockerUtil::loadJSON($dockerManPaths['webui-info']);
    return ($info[$name]['icon'] ?? null) === self::DEFAULT_ICON ? 0 : 1;
  }

  /**
   * What the tab shows of a container the way the Docker page shows it (REQ-TAB-2): the icon
   * that DockerMan cached, and the WebUI link with its placeholders filled in.
   *
   * @return array{icon: string, webUi: ?string}
   */
  public static function tabFacts(string $name, ?array $d): array {
    global $dockerManPaths;
    $info = \DockerUtil::loadJSON($dockerManPaths['webui-info'])[$name] ?? [];
    $cached = is_string($info['icon'] ?? null) ? $info['icon'] : '';
    return [
      'icon' => $cached !== '' && $cached !== self::DEFAULT_ICON ? $cached : (string)($d['icon'] ?? ''),
      'webUi' => $d === null || $d['webUi'] === '' ? null : self::webUiUrl($info, $d),
    ];
  }

  /**
   * The WebUI link as the Docker page last resolved it, or resolved here the way getControlURL
   * does: [IP] is the container's own address on a custom network and the server's otherwise,
   * and [PORT:n] is the host port of n on the bridge network and n itself otherwise.
   */
  private static function webUiUrl(array $info, array $d): string {
    $cached = is_string($info['url'] ?? null) ? $info['url'] : '';
    if ($cached !== '' && !str_contains($cached, '[')) {
      return $cached;
    }
    $ip = in_array($d['network'], ['bridge', 'host'], true) || $d['ipAddresses'] === [] ? (string)\DockerUtil::host() : $d['ipAddresses'][0];
    return (string)preg_replace_callback('/\[PORT:(\d+)\]/', function (array $match) use ($d): string {
      foreach ($d['configEntries'] as $e) {
        if ($d['network'] === 'bridge' && $e['type'] === 'PORT' && $e['target'] === $match[1] && $e['value'] !== '') {
          return $e['value'];
        }
      }
      return $match[1];
    }, str_replace('[IP]', $ip, $d['webUi']));
  }

  /** For cleanup after a failure, where a container that is already gone is fine. */
  public static function removeIfExists(string $name): void {
    if (isset(self::containers()[$name])) {
      self::remove($name);
    }
  }

  /** The state of the container as `docker inspect` gives it, or null if it does not exist. */
  public static function state(string $name): ?array {
    global $DockerClient;
    $code = null;
    $info = $DockerClient->getDockerJSON('/containers/' . rawurlencode($name) . '/json', 'GET', $code);
    return $code === true && is_array($info['State'] ?? null) ? $info['State'] + ['RestartCount' => (int)($info['RestartCount'] ?? 0)] : null;
  }

  /**
   * REQ-MUT-17 and REQ-MUT-18: watches a container that the operation started. The check fails
   * when the container stops, or when Docker restarts it after a crash, before the time passes.
   *
   * @return ?array{exitCode: int, restarted: bool, log: list<string>} null when the container kept running
   */
  public static function startCheck(string $name, int $seconds): ?array {
    $restarts = self::state($name)['RestartCount'] ?? 0;
    $deadline = microtime(true) + $seconds;
    while (true) {
      $state = self::state($name);
      $restarted = $state !== null && ($state['Restarting'] === true || $state['RestartCount'] > $restarts);
      if ($state === null || $state['Running'] !== true || $restarted) {
        return ['exitCode' => (int)($state['ExitCode'] ?? -1), 'restarted' => $restarted, 'log' => self::logTail($name, self::LOG_LINES)];
      }
      if (microtime(true) >= $deadline) {
        return null;
      }
      usleep(500_000);
    }
  }

  /** @return list<string> the last lines that the container wrote, stdout and stderr together */
  public static function logTail(string $name, int $lines): array {
    [, $output] = self::shell('docker logs --tail ' . $lines . ' ' . escapeshellarg($name) . ' 2>&1');
    return $output === '' ? [] : explode("\n", $output);
  }

  /** @return array{0: int, 1: string} the exit code and the trimmed output */
  private static function shell(string $command): array {
    $process = proc_open(['/bin/sh', '-c', $command], [1 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
      throw new Failure('create', 'cannot start /bin/sh');
    }
    $output = trim((string)stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    return [proc_close($process), $output];
  }
}
