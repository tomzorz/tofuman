<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/**
 * What the API module and the tab ask the shim to do (spec sections 5, 9, 11, and 13). Every
 * action holds one lock: the API module runs one operation at a time, but the tab can adopt
 * a container in the middle of one.
 */
final class Operations {
  /** REQ-MUT-16. */
  public const START_CHECK_DEFAULT = 10;
  public const START_CHECK_MAX = 600;

  /** REQ-TAB-27: how many containers the RAM file of recent policy gaps remembers. */
  public const GAPS_KEEP = 20;

  public function __construct(private readonly Env $env) {}

  /** REQ-AUTH-2 to REQ-AUTH-4 for queries, which the policy otherwise leaves alone (REQ-POL-9). */
  public function authorize(array $caller): bool {
    if (($caller['admin'] ?? false) === true) {
      return true;
    }
    if (!Policy::load($this->env->policyFile())->allowsCaller($caller)) {
      throw new Refusal(['the caller ' . ($caller['id'] ?? 'unknown') . ' is not on the key allowlist']);
    }
    return true;
  }

  /** @return list<array> every managed container that exists */
  public function list(array $caller): array {
    $this->authorize($caller);
    return $this->locked(function (): array {
      [$registry, $containers] = $this->state();
      $views = [];
      foreach ($registry->containers as $id => $entry) {
        $view = $this->view((string)$id, $entry, $containers);
        if ($view !== null) {
          $views[] = $view;
        }
      }
      return $views;
    });
  }

  public function get(?string $id, ?string $name, array $caller): ?array {
    $this->authorize($caller);
    if (($id === null) === ($name === null)) {
      throw new Refusal(['a get takes exactly one of id or name']);
    }
    return $this->locked(function () use ($id, $name): ?array {
      [$registry, $containers] = $this->state();
      $id ??= $registry->idOf((string)$name);
      return $id !== null && isset($registry->containers[$id]) ? $this->view($id, $registry->containers[$id], $containers) : null;
    });
  }

  /**
   * Every check of sections 5 to 8 and 12 for one mutation (REQ-MUT-2). The API module runs
   * this before it starts an operation, and each operation runs it again under the lock.
   *
   * @return ?array the definition as the shim will store it; null for deleteContainer
   */
  public function check(string $mutation, ?string $id, ?array $definition, array $caller): ?array {
    return $this->locked(fn(): ?array => $this->checked($mutation, $id, $definition, $caller, true));
  }

  /**
   * The query `check` (REQ-MUT-25, REQ-POL-9, REQ-PRV-15): the checks of a mutation, without the
   * mutation and without a line in the audit log. A definition alone checks createContainer, an
   * ID and a definition check updateContainer, and an ID alone checks deleteContainer.
   *
   * @return array{failedChecks: list<string>, policyGaps: int}
   */
  public function checkQuery(?string $id, ?array $definition, array $caller): array {
    $this->authorize($caller);
    $mutation = match (true) {
      $definition === null && $id === null => throw new Refusal(['a check takes an id, a definition, or both']),
      $definition === null => 'deleteContainer',
      $id === null => 'createContainer',
      default => 'updateContainer',
    };
    try {
      $this->locked(fn(): ?array => $this->checked($mutation, $id, $definition, $caller, false));
      return ['failedChecks' => [], 'policyGaps' => 0];
    } catch (Refusal $refusal) {
      return ['failedChecks' => $refusal->errors, 'policyGaps' => count($refusal->gaps)];
    }
  }

  /** @param array{id?: string, queuedAt?: string, startCheck?: int} $operation what the API module knows of the operation */
  public function create(array $definition, array $caller, array $operation = []): array {
    return $this->operation('createContainer', $caller, $operation, null, $definition, function (OperationLog $log) use ($definition, $caller, $operation): array {
      $d = $this->checked('createContainer', null, $definition, $caller, true);
      [$registry] = $this->state();
      $name = $d['name'];
      $id = $registry->idOf($name) ?? Registry::newId(); // REQ-OWN-12: a gone container's entry is reused
      $log->about($id, $name);
      $log->secrets(Secrets::of($d));
      try {
        $this->prepare($d, $log);
        $log->step('template');
        $path = Docker::templateWritePath($name);
        $this->writeTemplate($path, Definition::toXml($d, $id));
        try {
          $this->createContainer($path, $log);
          $this->autostart()->set($name, $d['autostart']);
          if ($d['autostart']) {
            $this->startAndCheck($name, $log, self::startCheckSeconds($operation));
          }
        } catch (Failure $failure) {
          $this->undoCreate($name, $path); // REQ-MUT-10
          throw $failure;
        }
        $registry->record($id, $name, Definition::hash($d));
        $registry->save();
        $view = $this->view($id, $registry->containers[$id], Docker::containers()) ?? throw new Failure('create', "$name vanished right after its creation");
      } catch (Failure $failure) {
        throw $this->failed($log, $caller, 'createContainer', $id, $name, $failure, []);
      }
      $log->end(null);
      $this->audit()->append($caller, 'createContainer', $id, $name, 'succeeded', null, $log);
      return $view;
    });
  }

  /** @param array{id?: string, queuedAt?: string, startCheck?: int} $operation */
  public function update(string $id, array $definition, array $caller, array $operation = []): array {
    return $this->operation('updateContainer', $caller, $operation, $id, $definition, function (OperationLog $log) use ($id, $definition, $caller, $operation): array {
      $d = $this->checked('updateContainer', $id, $definition, $caller, true);
      [$registry, $containers] = $this->state();
      $old = $registry->containers[$id]['name'];
      $new = $d['name'];
      $log->about($id, $new);
      $changes = [];
      try {
        if (!isset($containers[$old])) {
          throw new Failure('update', "the container $old does not exist");
        }
        $oldPath = Docker::templatePath($old) ?? throw new Failure('update', "the template of $old is missing");
        $before = Definition::fromVar(Definition::read($oldPath), $this->autostart()->isOn($old));
        $changes = Definition::changes($before, $d);
        $log->changes($changes);
        $log->secrets(Secrets::of($d, $before));
        $this->prepare($d, $log); // REQ-MUT-8: nothing has changed yet if this fails
        $previous = [
          'name' => $old,
          'path' => $oldPath,
          'xml' => (string)file_get_contents($oldPath),
          'running' => $containers[$old]['running'],
          'autostart' => $this->autostart()->isOn($old),
        ];
        $log->step('template');
        $newPath = $new === $old ? $oldPath : Docker::templateWritePath($new);
        $this->writeTemplate($newPath, Definition::toXml($d, $id));
        try {
          if ($previous['running']) {
            $log->step('stop');
            Docker::stop($old);
          }
          $log->step('remove');
          Docker::remove($old);
          $this->createContainer($newPath, $log);
          if ($newPath !== $oldPath) {
            unlink($oldPath); // REQ-MUT-11
            $this->autostart()->rename($old, $new);
          }
          $this->autostart()->set($new, $d['autostart']);
          if ($previous['running']) {
            $this->startAndCheck($new, $log, self::startCheckSeconds($operation)); // REQ-DEF-7
          }
        } catch (Failure $failure) {
          throw $this->restore($previous, $new, $newPath, $failure); // REQ-MUT-9
        }
        $registry->record($id, $new, Definition::hash($d));
        $registry->save();
        $view = $this->view($id, $registry->containers[$id], Docker::containers()) ?? throw new Failure('update', "$new vanished right after its update");
      } catch (Failure $failure) {
        throw $this->failed($log, $caller, 'updateContainer', $id, $old, $failure, $changes);
      }
      $log->end(null);
      $this->audit()->append($caller, 'updateContainer', $id, $new, 'succeeded', null, $log, $changes);
      return $view;
    });
  }

  /** @param array{id?: string, queuedAt?: string} $operation */
  public function delete(string $id, array $caller, array $operation = []): array {
    return $this->operation('deleteContainer', $caller, $operation, $id, null, function (OperationLog $log) use ($id, $caller): array {
      $this->checked('deleteContainer', $id, null, $caller, true);
      [$registry, $containers] = $this->state();
      $name = $registry->containers[$id]['name'];
      $log->about($id, $name);
      try {
        if (isset($containers[$name])) { // REQ-MUT-12; the image and the host paths stay (REQ-MUT-13)
          if ($containers[$name]['running']) {
            $log->step('stop');
            Docker::stop($name);
          }
          $log->step('remove');
          Docker::remove($name);
        }
        $log->step('template');
        $path = Docker::templatePath($name);
        if ($path !== null) {
          unlink($path);
        }
        $this->autostart()->set($name, false);
        $registry->remove($id);
        $registry->save();
      } catch (Failure $failure) {
        throw $this->failed($log, $caller, 'deleteContainer', $id, $name, $failure, []);
      }
      $log->end(null);
      $this->audit()->append($caller, 'deleteContainer', $id, $name, 'succeeded', null, $log);
      return ['id' => $id, 'name' => $name];
    });
  }

  /**
   * REQ-TAB-4 and REQ-TAB-23: the DockerMan containers without a registry entry, which the tab
   * offers to adopt, each with the checks that would refuse its adoption.
   */
  public function handMade(array $caller): array {
    $this->requireAdmin($caller);
    return $this->locked(function (): array {
      [$registry, $containers] = $this->state();
      $untested = TestedBuild::mismatches($this->env) ? ['the webgui files match no tested build of this plugin release'] : [];
      $handMade = [];
      foreach ($containers as $name => $container) {
        if ($container['manager'] === 'dockerman' && $registry->idOf((string)$name) === null) {
          $handMade[] = [
            'name' => (string)$name,
            'image' => $container['image'],
            'running' => $container['running'],
            'refusals' => [...$untested, ...$this->adoptionRefusals((string)$name, $containers)],
          ];
        }
      }
      return $handMade;
    });
  }

  /**
   * REQ-TAB-26: the policy gaps of each managed container, as the policy stands. An invalid
   * policy has no gaps to offer; the tab shows its errors instead.
   *
   * @return list<array{kind: string, value: string, container: string, text: string}>
   */
  public function managedGaps(array $caller): array {
    $this->requireAdmin($caller);
    try {
      $policy = Policy::load($this->env->policyFile());
    } catch (Refusal) {
      return [];
    }
    $gaps = [];
    foreach ($this->list($caller) as $view) {
      array_push($gaps, ...$policy->gaps($view['definition']));
    }
    return $gaps;
  }

  /**
   * REQ-TAB-27: the policy gaps of the last containers that the policy refused, newest first,
   * without the gaps that the policy has closed since.
   */
  public function recentGaps(): array {
    try {
      $recent = Json::read($this->env->gapsFile());
      $policy = Policy::load($this->env->policyFile());
    } catch (\RuntimeException | Refusal) {
      return [];
    }
    $open = [];
    foreach (is_array($recent) ? $recent : [] as $refusal) {
      $refusal['gaps'] = array_values(array_filter($refusal['gaps'] ?? [], fn(array $gap) => !$policy->covers($gap)));
      if ($refusal['gaps']) {
        $open[] = $refusal;
      }
    }
    return $open;
  }

  /**
   * REQ-TAB-8 and REQ-TAB-9. The policy travels as text, so an empty object stays an object on
   * its way through PHP.
   */
  public function savePolicy(string $text, array $caller): array {
    $this->requireAdmin($caller); // REQ-POL-8
    try {
      $policy = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
      throw new Refusal(["the policy is not JSON: {$e->getMessage()}"]);
    }
    $errors = Policy::validate($policy);
    if ($errors) {
      throw new Refusal($errors);
    }
    return $this->locked(function () use ($text): array {
      Json::write($this->env->policyFile(), json_decode($text, false, 64, JSON_THROW_ON_ERROR));
      return Policy::read($this->env->policyFile());
    });
  }

  /** REQ-TAB-4 to REQ-TAB-7: registry entry and marker, and no recreate. */
  public function adopt(string $name, array $caller): array {
    $this->requireAdmin($caller); // REQ-OWN-7: only a person in the tab adopts
    return $this->locked(function () use ($name, $caller): array {
      try {
        [$registry, $containers] = $this->state();
        TestedBuild::refuseIfUntested($this->env);
        if ($registry->idOf($name) !== null) {
          throw new Refusal(["$name is already a managed container"]);
        }
        $refusals = $this->adoptionRefusals($name, $containers);
        if ($refusals) {
          throw new Refusal($refusals);
        }
        $path = (string)Docker::templatePath($name);
        $d = Definition::fromVar(Definition::read($path), $this->autostart()->isOn($name));
        $id = Registry::newId();
        $this->writeTemplate($path, Definition::toXml($d, $id));
        $registry->record($id, $name, Definition::hash($d));
        $registry->save();
        $this->audit()->append($caller, 'adopt', $id, $name, 'succeeded', null);
        return $this->view($id, $registry->containers[$id], $containers) ?? throw new \RuntimeException("$name vanished during its adoption");
      } catch (Refusal $refusal) {
        $this->audit()->append($caller, 'adopt', null, $name, 'refused', $refusal->getMessage());
        throw $refusal;
      }
    });
  }

  /**
   * REQ-TAB-24: adopts each container on its own, so one refusal leaves the others adopted.
   *
   * @param list<string> $names
   * @return list<array{name: string, adopted: bool, errors: list<string>}>
   */
  public function adoptMany(array $names, array $caller): array {
    $results = [];
    foreach ($names as $name) {
      try {
        $this->adopt((string)$name, $caller);
        $results[] = ['name' => (string)$name, 'adopted' => true, 'errors' => []];
      } catch (Refusal $refusal) {
        $results[] = ['name' => (string)$name, 'adopted' => false, 'errors' => $refusal->errors];
      }
    }
    return $results;
  }

  /** REQ-MUT-14 for requests that the API module refuses before it reaches the shim. */
  public function recordRefusal(string $mutation, ?string $id, string $name, string $error, array $caller): void {
    $this->locked(fn() => $this->audit()->append($caller, $mutation, $id, $name, 'refused', $error));
  }

  private function checked(string $mutation, ?string $id, ?array $definition, array $caller, bool $audit): ?array {
    $name = is_string($definition['name'] ?? null) ? $definition['name'] : '';
    try {
      [$registry, $containers] = $this->state();
      $policy = Policy::load($this->env->policyFile());
      if (!$policy->allowsCaller($caller)) {
        throw new Refusal(['the caller ' . ($caller['id'] ?? 'unknown') . ' is not on the key allowlist']);
      }
      TestedBuild::refuseIfUntested($this->env);
      if ($mutation !== 'createContainer') {
        if ($id === null || !isset($registry->containers[$id])) {
          throw new Refusal(["no managed container has the ID $id"]); // REQ-OWN-5
        }
        $name = $registry->containers[$id]['name'];
      }
      if ($mutation === 'deleteContainer') {
        return null;
      }
      $errors = Validator::shape($definition);
      if ($errors) {
        throw new Refusal($errors);
      }
      $d = Definition::normalize($definition);
      $name = $d['name'];
      $gaps = $policy->gaps($d);
      $errors = [
        ...$this->ownershipErrors($mutation, $id, $name, $registry, $containers),
        ...Validator::fields($d, Docker::networks(), fn(string $container) => isset($containers[$container])),
        ...$policy->check($d),
      ];
      if (!$errors) {
        $errors = Validator::roundTrip($d);
      }
      if ($errors) {
        throw new Refusal($errors, $gaps);
      }
      return $d;
    } catch (Refusal $refusal) {
      if ($refusal->gaps) {
        $this->rememberGaps($name, $refusal->gaps);
      }
      if ($audit) {
        $this->audit()->append($caller, $mutation, $id, $name, 'refused', $refusal->getMessage());
      }
      throw $refusal;
    }
  }

  /**
   * Runs the body of an operation under the lock, with its line in the operation log. Whatever
   * escapes the body still ends that line, so the tab never shows a step that runs forever.
   */
  private function operation(string $mutation, array $caller, array $operation, ?string $id, ?array $definition, callable $body): array {
    return $this->locked(function () use ($mutation, $caller, $operation, $id, $definition, $body): array {
      $log = new OperationLog($this->env, $mutation, $caller, $operation, $id, is_string($definition['name'] ?? null) ? $definition['name'] : '');
      try {
        return $body($log);
      } catch (\Throwable $error) {
        $log->end($error); // a no-op when the operation ended already
        throw $error;
      }
    });
  }

  /** The end of a failed operation: its line, its audit line, its notification, and the error without secrets. */
  private function failed(OperationLog $log, array $caller, string $mutation, ?string $id, string $name, Failure $failure, array $changes): Failure {
    $log->end($failure);
    $this->audit()->append($caller, $mutation, $id, $name, 'failed', "{$failure->step}: {$failure->getMessage()}", $log, $changes);
    Notify::raise($this->env, 'warning', "tofuman: $mutation of $name failed", "The step {$failure->step} failed. The tofuman tab on the Docker page shows the operation {$log->id()}.");
    return new Failure($failure->step, $log->mask($failure->getMessage()));
  }

  /** REQ-TAB-27: the RAM file keeps the gaps of the last containers that the policy refused. */
  private function rememberGaps(string $name, array $gaps): void {
    try {
      $recent = Json::read($this->env->gapsFile());
    } catch (\RuntimeException) {
      $recent = null;
    }
    $recent = is_array($recent) ? $recent : [];
    unset($recent[$name]);
    $recent = [$name => ['container' => $name, 'time' => Registry::now(), 'gaps' => $gaps]] + $recent;
    Json::write($this->env->gapsFile(), array_slice($recent, 0, self::GAPS_KEEP, true));
  }

  /** REQ-OWN-6, and REQ-OWN-12 for a create that reuses the entry of a container that is gone. */
  private function ownershipErrors(string $mutation, ?string $id, string $name, Registry $registry, array $containers): array {
    $owner = $registry->idOf($name);
    if ($owner !== null) {
      $reuse = $mutation === 'createContainer' && !isset($containers[$name]);
      return $owner === $id || $reuse ? [] : ["the name $name belongs to the managed container $owner"];
    }
    return isset($containers[$name]) || Docker::templatePath($name) !== null ? ["the name $name belongs to a hand-made container or template"] : [];
  }

  /**
   * REQ-TAB-15 to REQ-TAB-17 for one container, without the adoption: each check that would
   * refuse it. The tested build (REQ-TAB-14) is the caller's business.
   *
   * @return list<string>
   */
  private function adoptionRefusals(string $name, array $containers): array {
    if (!isset($containers[$name])) {
      return ["no container has the name $name"];
    }
    if ($containers[$name]['manager'] !== 'dockerman') {
      return ["$name is not a DockerMan container"];
    }
    $path = Docker::templatePath($name);
    if ($path === null) {
      return ["$name has no template"];
    }
    $var = Definition::read($path);
    $errors = [];
    if (Definition::tailscaleEnabled($var)) {
      $errors[] = "$name enables Tailscale, which tofuman does not support";
    }
    foreach (['ExtraParams', 'PostArgs'] as $field) {
      if (Arguments::hasShellSyntax($var[$field])) {
        $errors[] = "$field of $name uses shell syntax that tofuman cannot keep: {$var[$field]}";
      }
    }
    if ($errors) {
      return $errors;
    }
    return Validator::roundTrip(Definition::fromVar($var, $this->autostart()->isOn($name)));
  }

  /** REQ-OWN-8 on every action. */
  private function state(): array {
    $registry = Registry::load($this->env->registryFile());
    $containers = Docker::containers();
    $before = $registry->containers;
    $registry->reconcile($containers, fn(string $name) => Docker::templatePath($name) !== null);
    if ($registry->containers !== $before) {
      $registry->save();
    }
    return [$registry, $containers];
  }

  /** REQ-OWN-12: an entry whose container is gone reads as absent. */
  private function view(string $id, array $entry, array $containers): ?array {
    $name = $entry['name'];
    $path = isset($containers[$name]) ? Docker::templatePath($name) : null;
    if ($path === null) {
      return null;
    }
    $definition = Definition::fromVar(Definition::read($path), $this->autostart()->isOn($name));
    return [
      'id' => $id,
      'definition' => $definition,
      'running' => $containers[$name]['running'],
      'lastMutationAt' => $entry['lastMutationAt'],
      'changedSinceLastMutation' => Definition::hash($definition) !== $entry['definitionHash'],
    ];
  }

  /** The steps that come before the template changes: the mounts (REQ-MNT-1) and the pull. */
  private function prepare(array $d, OperationLog $log): void {
    $log->step('mounts');
    $errors = Mounts::check($d);
    if ($errors) {
      throw new Failure('mounts', implode('; ', $errors)); // REQ-MNT-2
    }
    $log->step('pull');
    $log->fact('digest', Docker::pull($d['repository']));
  }

  private function createContainer(string $templatePath, OperationLog $log): void {
    $log->step('create');
    [$command] = \xmlToCommand($templatePath, false); // REQ-MNT-3
    $log->fact('command', $command);
    $log->fact('output', Docker::run($command));
  }

  /** REQ-DEF-7 and the start check of REQ-MUT-17 to REQ-MUT-19. */
  private function startAndCheck(string $name, OperationLog $log, int $seconds): void {
    $log->step('start');
    Docker::start($name);
    if ($seconds === 0) {
      return;
    }
    $log->step('start check');
    $result = Docker::startCheck($name, $seconds);
    if ($result === null) {
      return;
    }
    $log->fact('exitCode', $result['exitCode']);
    $log->fact('log', $result['log']);
    $what = $result['restarted'] ? 'restarted' : 'stopped';
    $lines = $result['log'] === [] ? 'It wrote no log lines.' : "Its last log lines:\n" . implode("\n", $result['log']);
    throw new Failure('start check', "$name $what within $seconds seconds of its start, with exit code {$result['exitCode']}. $lines");
  }

  /** REQ-MUT-16: the length that the API module sent, within its bounds. */
  private static function startCheckSeconds(array $operation): int {
    $seconds = $operation['startCheck'] ?? self::START_CHECK_DEFAULT;
    return is_int($seconds) ? max(0, min(self::START_CHECK_MAX, $seconds)) : self::START_CHECK_DEFAULT;
  }

  private function writeTemplate(string $path, string $xml): void {
    if (file_put_contents($path, $xml) === false) {
      throw new Failure('template', "cannot write $path");
    }
  }

  private function undoCreate(string $name, string $path): void {
    Docker::removeIfExists($name);
    if (is_file($path)) {
      unlink($path);
    }
    $this->autostart()->set($name, false);
  }

  /** Puts the previous template and container back, and returns the failure to throw. */
  private function restore(array $previous, string $new, string $newPath, Failure $failure): Failure {
    try {
      Docker::removeIfExists($new);
      if ($newPath !== $previous['path'] && is_file($newPath)) {
        unlink($newPath);
        $this->autostart()->rename($new, $previous['name']);
      }
      $this->writeTemplate($previous['path'], $previous['xml']);
      Docker::removeIfExists($previous['name']);
      [$command] = \xmlToCommand($previous['path'], false);
      Docker::run($command);
      $this->autostart()->set($previous['name'], $previous['autostart']);
      if ($previous['running']) {
        Docker::start($previous['name']);
      }
      return new Failure($failure->step, "{$failure->getMessage()} The previous container {$previous['name']} is back.");
    } catch (Failure $restoreFailure) {
      return new Failure($failure->step, "{$failure->getMessage()} Putting back the previous container {$previous['name']} failed too: {$restoreFailure->getMessage()}");
    }
  }

  /** The actions of the tab, which the API module never offers. */
  private function requireAdmin(array $caller): void {
    if (($caller['admin'] ?? false) !== true) {
      throw new Refusal(['only an administrator in the tab can do this']);
    }
  }

  private function autostart(): Autostart {
    return new Autostart($this->env->autostartFile);
  }

  private function audit(): Audit {
    return new Audit($this->env->auditFile());
  }

  private function locked(callable $action): mixed {
    $lock = fopen($this->env->lockFile, 'c');
    if ($lock === false) {
      throw new \RuntimeException("cannot open the lock file {$this->env->lockFile}");
    }
    flock($lock, LOCK_EX);
    try {
      return $action();
    } finally {
      flock($lock, LOCK_UN);
      fclose($lock);
    }
  }
}
