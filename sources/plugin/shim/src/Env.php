<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/** Where the plugin keeps its files. Tests point the data directory and the lock elsewhere. */
final class Env {
  public function __construct(
    public readonly string $dataDir,
    public readonly string $lockFile,
    public readonly string $autostartFile,
    public readonly string $docroot,
    public readonly string $testedBuildsFile,
    public readonly string $loadRecordFile,
    public readonly string $apiManifestFile,
  ) {}

  public static function fromGlobals(): self {
    global $dockerManPaths, $docroot;
    return new self(
      getenv('TOFUMAN_DATA_DIR') ?: '/boot/config/plugins/tofuman',
      getenv('TOFUMAN_LOCK') ?: '/var/run/tofuman.lock',
      $dockerManPaths['autostart-file'],
      $docroot,
      dirname(__DIR__) . '/tested-builds.json',
      getenv('TOFUMAN_LOADED_FILE') ?: '/var/run/tofuman-api.json',
      dirname(__DIR__, 2) . '/api/package.json',
    );
  }

  public function registryFile(): string {
    return "$this->dataDir/registry.json";
  }

  public function policyFile(): string {
    return "$this->dataDir/policy.json";
  }

  public function auditFile(): string {
    return "$this->dataDir/audit.jsonl";
  }
}
