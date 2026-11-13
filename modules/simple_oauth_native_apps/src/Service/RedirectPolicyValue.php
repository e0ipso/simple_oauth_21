<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_native_apps\Service;

/**
 * Interprets the two global redirect permissions declared by config schema.
 *
 * Legacy string Booleans belong only to allow.custom_uri_schemes and
 * allow.loopback_redirects. Consumer overrides and PKCE use separate enums.
 */
final class RedirectPolicyValue {

  /**
   * Reads a supported global permission without changing stored configuration.
   *
   * @param mixed $value
   *   A raw global redirect permission.
   *
   * @return string|null
   *   The canonical enum, or NULL for an unsupported value.
   */
  public static function normalize(mixed $value): ?string {
    return match ($value) {
      '1', 'native' => 'native',
      '0', 'web' => 'web',
      'auto-detect' => 'auto-detect',
      default => NULL,
    };
  }

}
