<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_21\Trait;

use Drupal\TestTools\Extension\HtmlLogging\HtmlOutputLogger;

/**
 * Provides debug logging functionality for test classes.
 */
trait DebugLoggingTrait {

  /**
   * Prints a message to the console during tests.
   *
   * @param string $message
   *   The message to print.
   */
  protected function logDebug(string $message): void {
    if (getenv('BROWSERTEST_OUTPUT_FILE') === FALSE) {
      return;
    }
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
    if (!isset($backtrace[1])) {
      return;
    }
    $caller = $backtrace[1];
    $class = $caller['class'] ?? 'UnknownClass';
    try {
      $class_name = (new \ReflectionClass($class))->getShortName();
    }
    catch (\ReflectionException $e) {
      return;
    }
    $function = $caller['function'] ?: 'unknownFunction';
    $line = $backtrace[0]['line'] ?? 'unknown_line';

    $formatted_message = sprintf('%s [DEBUG] [%s::%s (L%s)] %s', $this::emojiForString($class), $class_name, $function, $line, $message);

    // Use HtmlOutputLogger if available (Drupal 11+ with PHPUnit 10).
    if (class_exists(HtmlOutputLogger::class)) {
      // @phpstan-ignore-next-line
      HtmlOutputLogger::log($formatted_message);
    }
  }

  /**
   * Chooses an emoji representative for the input string.
   *
   * @param string $input
   *   The input string.
   *
   * @return string
   *   The emoji code.
   */
  protected static function emojiForString(string $input): string {
    // Compute a cheap and reproducible float between 0 and 1 for based on the
    // input.
    $max_length = 500;
    $input = strtolower($input);
    $input = strtr($input, '/ -_:', '00000');
    $input = substr($input, 0, $max_length);
    $chars = str_split($input);
    $chars = array_pad($chars, 20, '0');
    $sum = array_reduce($chars, static fn(int $total, string $char) => $total + ord($char), 0);
    $num = $sum / 4880;

    // Compute an int between 129338 and 129431, which is the sequential emoji
    // range we are interested in. We chose this range because all integers in
    // between correspond to an emoji. These emojis depict sports, food, and
    // animals.
    $html_entity = floor(129338 + $num * (129431 - 129338));
    return mb_convert_encoding("&#$html_entity;", 'UTF-8', 'HTML-ENTITIES');
  }

}
