<?php
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace Tofuman;

/**
 * ExtraParams and PostArgs as lists of arguments (REQ-VAL-3 to REQ-VAL-5).
 *
 * DockerMan pastes both fields into a /bin/sh command line unescaped, on every rebuild. Stored
 * as single-quoted arguments, they can only ever be arguments to docker.
 */
final class Arguments {
  /** @param list<string> $args */
  public static function join(array $args): string {
    return implode(' ', array_map(escapeshellarg(...), $args));
  }

  /**
   * Splits text into arguments by the quoting rules of a POSIX shell: single quotes, double
   * quotes with their four backslash escapes, and backslash outside quotes. It expands
   * nothing, so `$x` stays `$x`; hasShellSyntax() is what refuses text that needs expansion.
   *
   * @return list<string>
   */
  public static function split(string $text): array {
    $args = [];
    $current = '';
    $open = false; // an argument has started, even an empty '' one
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
      $c = $text[$i];
      if ($c === "'") {
        $end = strpos($text, "'", $i + 1);
        if ($end === false) {
          throw new Refusal(["unterminated single quote in: $text"]);
        }
        $current .= substr($text, $i + 1, $end - $i - 1);
        $i = $end;
        $open = true;
      } elseif ($c === '"') {
        for ($i++; $i < $length && $text[$i] !== '"'; $i++) {
          if ($text[$i] === '\\' && $i + 1 < $length && str_contains('"\\$`', $text[$i + 1])) {
            $i++;
          }
          $current .= $text[$i];
        }
        if ($i >= $length) {
          throw new Refusal(["unterminated double quote in: $text"]);
        }
        $open = true;
      } elseif ($c === '\\') {
        if (++$i < $length) {
          $current .= $text[$i];
        }
        $open = true;
      } elseif (ctype_space($c)) {
        if ($open) {
          $args[] = $current;
          $current = '';
          $open = false;
        }
      } else {
        $current .= $c;
        $open = true;
      }
    }
    if ($open) {
      $args[] = $current;
    }
    return $args;
  }

  /**
   * True when the shell would do more with the text than split it into words: a separator,
   * a redirection, a subshell, an expansion, a glob, a comment, or a quote left open.
   * Adopting such text and storing it quoted would change what the container runs.
   */
  public static function hasShellSyntax(string $text): bool {
    $length = strlen($text);
    $wordStart = true;
    for ($i = 0; $i < $length; $i++) {
      $c = $text[$i];
      if ($c === "'") {
        $end = strpos($text, "'", $i + 1);
        if ($end === false) {
          return true;
        }
        $i = $end;
      } elseif ($c === '"') {
        for ($i++; $i < $length && $text[$i] !== '"'; $i++) {
          if ($text[$i] === '\\') {
            $i++;
          } elseif ($text[$i] === '$' || $text[$i] === '`') {
            return true;
          }
        }
        if ($i >= $length) {
          return true;
        }
      } elseif ($c === '\\') {
        $i++;
      } elseif (str_contains(";&|<>()\$`*?[\n", $c) || ($wordStart && ($c === '#' || $c === '~'))) {
        return true;
      }
      $wordStart = ctype_space($c);
    }
    return false;
  }
}
