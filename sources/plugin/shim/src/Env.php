<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/** Where the plugin keeps its files. Tests point the data directory, the RAM files, the lock, and the notify script elsewhere. */
final class Env {
  public function __construct(
    public readonly string $dataDir,
    public readonly string $lockFile,
    public readonly string $autostartFile,
    public readonly string $docroot,
    public readonly string $testedBuildsFile,
    public readonly string $loadRecordFile,
    public readonly string $apiManifestFile,
    /** RAM: the operation that runs (REQ-MUT-22) and the recent policy gaps (REQ-TAB-27). */
    public readonly string $runDir,
    /** The webgui's notify script (REQ-MUT-23, REQ-UPG-7), run with php. */
    public readonly string $notifyScript,
    /** For the diagnostics file (section 16.5). */
    public readonly string $apiLogFile = '/var/log/graphql-api.log',
    public readonly string $unraidVersionFile = '/etc/unraid-version',
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
      getenv('TOFUMAN_RUN_DIR') ?: '/var/run',
      getenv('TOFUMAN_NOTIFY') ?: "$docroot/webGui/scripts/notify",
      getenv('TOFUMAN_API_LOG') ?: '/var/log/graphql-api.log',
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

  public function operationsFile(): string {
    return "$this->dataDir/operations.jsonl";
  }

  public function currentOperationFile(): string {
    return "$this->runDir/tofuman-operation.json";
  }

  public function gapsFile(): string {
    return "$this->runDir/tofuman-gaps.json";
  }

  /** The plugin's version: the package build writes it into the manifest of the API module. */
  public function pluginVersion(): ?string {
    $manifest = Json::read($this->apiManifestFile);
    return is_array($manifest) && is_string($manifest['version'] ?? null) ? $manifest['version'] : null;
  }
}
