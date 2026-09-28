<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/** A request that breaks a rule of the spec. The caller gets every error, not just the first. */
final class Refusal extends \Exception {
  /** @param list<string> $errors */
  public function __construct(public readonly array $errors) {
    parent::__construct(implode('; ', $errors));
  }
}
