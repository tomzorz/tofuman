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
  public function __construct(private readonly Env $env) {}

  /** @return list<array> every managed container that exists */
  public function list(): array {
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

  public function get(?string $id, ?string $name): ?array {
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
    return $this->locked(fn(): ?array => $this->checked($mutation, $id, $definition, $caller));
  }

  public function create(array $definition, array $caller): array {
    return $this->locked(function () use ($definition, $caller): array {
      $d = $this->checked('createContainer', null, $definition, $caller);
      [$registry] = $this->state();
      $name = $d['name'];
      $id = $registry->idOf($name) ?? Registry::newId(); // REQ-OWN-12: a gone container's entry is reused
      try {
        $this->prepare($d);
        $path = Docker::templateWritePath($name);
        $this->writeTemplate($path, Definition::toXml($d, $id));
        try {
          $this->createContainer($path);
          $this->autostart()->set($name, $d['autostart']);
          if ($d['autostart']) {
            Docker::start($name);
          }
        } catch (Failure $failure) {
          $this->undoCreate($name, $path); // REQ-MUT-10
          throw $failure;
        }
        $registry->record($id, $name, Definition::hash($d));
        $registry->save();
        $this->audit()->append($caller, 'createContainer', $id, $name, 'succeeded', null);
        return $this->view($id, $registry->containers[$id], Docker::containers()) ?? throw new Failure('create', "$name vanished right after its creation");
      } catch (Failure $failure) {
        $this->audit()->append($caller, 'createContainer', $id, $name, 'failed', "{$failure->step}: {$failure->getMessage()}");
        throw $failure;
      }
    });
  }

  public function update(string $id, array $definition, array $caller): array {
    return $this->locked(function () use ($id, $definition, $caller): array {
      $d = $this->checked('updateContainer', $id, $definition, $caller);
      [$registry, $containers] = $this->state();
      $old = $registry->containers[$id]['name'];
      $new = $d['name'];
      try {
        if (!isset($containers[$old])) {
          throw new Failure('update', "the container $old does not exist");
        }
        $oldPath = Docker::templatePath($old) ?? throw new Failure('update', "the template of $old is missing");
        $this->prepare($d); // REQ-MUT-8: nothing has changed yet if this fails
        $previous = [
          'name' => $old,
          'path' => $oldPath,
          'xml' => (string)file_get_contents($oldPath),
          'running' => $containers[$old]['running'],
          'autostart' => $this->autostart()->isOn($old),
        ];
        $newPath = $new === $old ? $oldPath : Docker::templateWritePath($new);
        $this->writeTemplate($newPath, Definition::toXml($d, $id));
        try {
          if ($previous['running']) {
            Docker::stop($old);
          }
          Docker::remove($old);
          $this->createContainer($newPath);
          if ($newPath !== $oldPath) {
            unlink($oldPath); // REQ-MUT-11
            $this->autostart()->rename($old, $new);
          }
          $this->autostart()->set($new, $d['autostart']);
          if ($previous['running']) {
            Docker::start($new); // REQ-DEF-7
          }
        } catch (Failure $failure) {
          throw $this->restore($previous, $new, $newPath, $failure); // REQ-MUT-9
        }
        $registry->record($id, $new, Definition::hash($d));
        $registry->save();
        $this->audit()->append($caller, 'updateContainer', $id, $new, 'succeeded', null);
        return $this->view($id, $registry->containers[$id], Docker::containers()) ?? throw new Failure('update', "$new vanished right after its update");
      } catch (Failure $failure) {
        $this->audit()->append($caller, 'updateContainer', $id, $old, 'failed', "{$failure->step}: {$failure->getMessage()}");
        throw $failure;
      }
    });
  }

  public function delete(string $id, array $caller): array {
    return $this->locked(function () use ($id, $caller): array {
      $this->checked('deleteContainer', $id, null, $caller);
      [$registry, $containers] = $this->state();
      $name = $registry->containers[$id]['name'];
      try {
        if (isset($containers[$name])) { // REQ-MUT-12; the image and the host paths stay (REQ-MUT-13)
          if ($containers[$name]['running']) {
            Docker::stop($name);
          }
          Docker::remove($name);
        }
        $path = Docker::templatePath($name);
        if ($path !== null) {
          unlink($path);
        }
        $this->autostart()->set($name, false);
        $registry->remove($id);
        $registry->save();
        $this->audit()->append($caller, 'deleteContainer', $id, $name, 'succeeded', null);
        return ['id' => $id, 'name' => $name];
      } catch (Failure $failure) {
        $this->audit()->append($caller, 'deleteContainer', $id, $name, 'failed', "{$failure->step}: {$failure->getMessage()}");
        throw $failure;
      }
    });
  }

  /** REQ-TAB-4 to REQ-TAB-7: registry entry and marker, and no recreate. */
  public function adopt(string $name, array $caller): array {
    return $this->locked(function () use ($name, $caller): array {
      $id = null;
      try {
        [$registry, $containers] = $this->state();
        TestedBuild::refuseIfUntested($this->env);
        if ($registry->idOf($name) !== null) {
          throw new Refusal(["$name is already a managed container"]);
        }
        if (!isset($containers[$name])) {
          throw new Refusal(["no container has the name $name"]);
        }
        if ($containers[$name]['manager'] !== 'dockerman') {
          throw new Refusal(["$name is not a DockerMan container"]);
        }
        $path = Docker::templatePath($name) ?? throw new Refusal(["$name has no template"]);
        $var = Definition::read($path);
        if (Definition::tailscaleEnabled($var)) {
          throw new Refusal(["$name enables Tailscale, which tofuman does not support"]);
        }
        foreach (['ExtraParams', 'PostArgs'] as $field) {
          if (Arguments::hasShellSyntax($var[$field])) {
            throw new Refusal(["$field of $name uses shell syntax that tofuman cannot keep: {$var[$field]}"]);
          }
        }
        $d = Definition::fromVar($var, $this->autostart()->isOn($name));
        $unstable = Validator::roundTrip($d);
        if ($unstable) {
          throw new Refusal($unstable);
        }
        $id = Registry::newId();
        $this->writeTemplate($path, Definition::toXml($d, $id));
        $registry->record($id, $name, Definition::hash($d));
        $registry->save();
        $this->audit()->append($caller, 'adopt', $id, $name, 'succeeded', null);
        return $this->view($id, $registry->containers[$id], $containers) ?? throw new \RuntimeException("$name vanished during its adoption");
      } catch (Refusal $refusal) {
        $this->audit()->append($caller, 'adopt', $id, $name, 'refused', $refusal->getMessage());
        throw $refusal;
      }
    });
  }

  /** REQ-MUT-14 for requests that the API module refuses before it reaches the shim. */
  public function recordRefusal(string $mutation, ?string $id, string $name, string $error, array $caller): void {
    $this->locked(fn() => $this->audit()->append($caller, $mutation, $id, $name, 'refused', $error));
  }

  private function checked(string $mutation, ?string $id, ?array $definition, array $caller): ?array {
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
      $errors = [
        ...$this->ownershipErrors($mutation, $id, $name, $registry, $containers),
        ...Validator::fields($d, Docker::networks(), fn(string $container) => isset($containers[$container])),
        ...$policy->check($d),
      ];
      if (!$errors) {
        $errors = Validator::roundTrip($d);
      }
      if ($errors) {
        throw new Refusal($errors);
      }
      return $d;
    } catch (Refusal $refusal) {
      $this->audit()->append($caller, $mutation, $id, $name, 'refused', $refusal->getMessage());
      throw $refusal;
    }
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
  private function prepare(array $d): void {
    $errors = Mounts::check($d);
    if ($errors) {
      throw new Failure('mounts', implode('; ', $errors)); // REQ-MNT-2
    }
    Docker::pull($d['repository']);
  }

  private function createContainer(string $templatePath): void {
    [$command] = \xmlToCommand($templatePath, false); // REQ-MNT-3
    Docker::run($command);
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
      $this->createContainer($previous['path']);
      $this->autostart()->set($previous['name'], $previous['autostart']);
      if ($previous['running']) {
        Docker::start($previous['name']);
      }
      return new Failure($failure->step, "{$failure->getMessage()} The previous container {$previous['name']} is back.");
    } catch (Failure $restoreFailure) {
      return new Failure($failure->step, "{$failure->getMessage()} Putting back the previous container {$previous['name']} failed too: {$restoreFailure->getMessage()}");
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
