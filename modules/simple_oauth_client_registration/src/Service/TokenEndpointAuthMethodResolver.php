<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Service;

use Drupal\consumers\Entity\ConsumerInterface;

/**
 * Selects the built-in Simple OAuth public or shared-secret profile.
 *
 * Basic is a supported replacement under RFC 7591 section 3.2.1, including for
 * requests for post or JWT methods. The stored confidential flag cannot retain
 * the requested method. JWT support requires an explicit integration resolver
 * and matching authentication implementation; it is never inferred here.
 */
final class TokenEndpointAuthMethodResolver implements TokenEndpointAuthMethodResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(ConsumerInterface $consumer): string {
    return (bool) $consumer->get('confidential')->value ? 'client_secret_basic' : 'none';
  }

}
