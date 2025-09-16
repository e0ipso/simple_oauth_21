<?php

namespace Drupal\simple_oauth_native_apps\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Validation constraint for native app redirect URIs per RFC 8252.
 *
 * @Constraint(
 *   id = "NativeAppRedirectUri",
 *   label = @Translation("Native app redirect URI", context = "Validation"),
 *   type = "string"
 * )
 */
class NativeAppRedirectUri extends Constraint {

  /**
   * The default violation message for invalid URIs.
   *
   * @var string
   */
  public string $invalidUriMessage = 'The redirect URI %uri is not valid for native applications.';

  /**
   * The violation message for disallowed custom schemes.
   *
   * @var string
   */
  public string $customSchemeDisallowedMessage = 'Custom URI schemes are not allowed. URI: %uri';

  /**
   * The violation message for invalid custom schemes.
   *
   * @var string
   */
  public string $invalidCustomSchemeMessage = 'The custom URI scheme in %uri is not valid. Must follow reverse domain notation (e.g., com.example.app://).';

  /**
   * The violation message for disallowed loopback redirects.
   *
   * @var string
   */
  public string $loopbackDisallowedMessage = 'Loopback redirects are not allowed. URI: %uri';

  /**
   * The violation message for invalid loopback addresses.
   *
   * @var string
   */
  public string $invalidLoopbackMessage = 'The loopback address in %uri is not valid. Only localhost, 127.0.0.1, and [::1] are allowed.';

  /**
   * The violation message for dangerous schemes.
   *
   * @var string
   */
  public string $dangerousSchemeMessage = 'The URI scheme in %uri is potentially dangerous and not allowed.';

  /**
   * The violation message for exact match failures.
   *
   * @var string
   */
  public string $exactMatchFailedMessage = 'The redirect URI %request_uri does not exactly match the registered URI %registered_uri.';

}
