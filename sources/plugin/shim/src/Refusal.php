<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/** A request that breaks a rule of the spec. The caller gets every error, not just the first. */
final class Refusal extends \Exception {
  /**
   * @param list<string> $errors
   * @param list<array{kind: string, value: string, container: string, text: string}> $gaps the policy gaps among the errors (Policy::gaps)
   */
  public function __construct(public readonly array $errors, public readonly array $gaps = []) {
    parent::__construct(implode('; ', $errors));
  }
}
