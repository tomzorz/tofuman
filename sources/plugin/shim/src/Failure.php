<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/** A step of an operation that failed after every check passed (spec section 9). */
final class Failure extends \Exception {
  public function __construct(public readonly string $step, string $message) {
    parent::__construct($message);
  }
}
