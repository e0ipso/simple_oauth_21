<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Unit\Fixture;

use Drupal\simple_oauth\Plugin\Oauth2GrantInterface;
use Drupal\simple_oauth_21\Plugin\Oauth2GrantIdentifierInterface;

/**
 * Combines grant operations and wire identifiers for portable test doubles.
 */
interface DiscoveryGrantInterface extends Oauth2GrantInterface, Oauth2GrantIdentifierInterface {}
