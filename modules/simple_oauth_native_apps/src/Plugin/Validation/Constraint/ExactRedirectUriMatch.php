<?php

namespace Drupal\simple_oauth_native_apps\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Validation constraint for exact redirect URI matching per RFC 8252.
 *
 * @Constraint(
 *   id = "ExactRedirectUriMatch",
 *   label = @Translation("Exact redirect URI match", context = "Validation"),
 *   type = "string"
 * )
 */
class ExactRedirectUriMatch extends Constraint {

  /**
   * The violation message for exact match failures.
   *
   * @var string
   */
  public string $exactMatchFailedMessage = 'The redirect URI %request_uri does not exactly match the registered URI %registered_uri.';

  /**
   * The registered URI to compare against.
   *
   * @var string
   */
  public string $registeredUri = '';

}
