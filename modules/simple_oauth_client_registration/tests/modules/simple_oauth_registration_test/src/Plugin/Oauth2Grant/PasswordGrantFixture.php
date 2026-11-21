<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_registration_test\Plugin\Oauth2Grant;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\simple_oauth\Plugin\Oauth2GrantBase;
use League\OAuth2\Server\Grant\GrantTypeInterface;

/**
 * Registers a grant plugin with the ID of the removed password grant.
 *
 * Models a contrib module that provides the password grant, so tests can
 * prove that registration rejects it anyway.
 *
 * @Oauth2Grant(
 *   id = "password",
 *   label = @Translation("Password test fixture")
 * )
 */
final class PasswordGrantFixture extends Oauth2GrantBase {

  /**
   * {@inheritdoc}
   */
  public function getGrantType(ConsumerInterface $client): GrantTypeInterface {
    throw new \LogicException('The password grant fixture cannot issue tokens.');
  }

}
