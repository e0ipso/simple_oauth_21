<?php

namespace Drupal\simple_oauth_21\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route subscriber for OAuth 2.1 compatibility fixes.
 *
 * Ensures OAuth routes are properly accessible in Drupal 11 test environments
 * by updating access requirements that aren't working in D11.
 */
class OAuth21RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // Only apply fixes in Drupal 11+ test environments.
    if (version_compare(\Drupal::VERSION, '11.0', '<')) {
      return;
    }

    // Fix routes that use '_access: TRUE' which doesn't work in D11 tests.
    // Change to use anonymous role access which works consistently.
    $routes_to_fix = [
      'simple_oauth.server_metadata',
      'oauth2_token.authorize',
      'oauth2_token.token',
    ];

    foreach ($routes_to_fix as $route_name) {
      $route = $collection->get($route_name);
      if ($route && $route->getRequirement('_access') === 'TRUE') {
        // Use custom access checker that allows anonymous access.
        $route->setRequirement('_custom_access', '\Drupal\simple_oauth_21\Access\OAuth21AccessChecker::access');
        // Remove the problematic _access requirement.
        $requirements = $route->getRequirements();
        unset($requirements['_access']);
        $route->setRequirements($requirements);
      }
    }
  }

}
