<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Service;

use Drupal\consumers\Entity\ConsumerInterface;

/**
 * Reports an effective method supported by the installed authentication flow.
 *
 * Integrations replacing this service must coordinate with their actual client
 * authentication implementation. This resolver does not enable authentication
 * methods or remember the requested registration method.
 */
interface TokenEndpointAuthMethodResolverInterface {

  /**
   * Selects a supported authentication method without changing the consumer.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer whose effective method is being reported.
   *
   * @return string
   *   The effective token_endpoint_auth_method, without issuing credentials.
   */
  public function resolve(ConsumerInterface $consumer): string;

}
