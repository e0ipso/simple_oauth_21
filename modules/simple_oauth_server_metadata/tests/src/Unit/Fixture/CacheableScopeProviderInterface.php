<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Unit\Fixture;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\simple_oauth\Oauth2ScopeProviderInterface;

/**
 * Combines provider operations and cacheability for portable test doubles.
 */
interface CacheableScopeProviderInterface extends Oauth2ScopeProviderInterface, CacheableDependencyInterface {}
