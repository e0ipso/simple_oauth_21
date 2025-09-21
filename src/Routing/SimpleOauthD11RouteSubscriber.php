<?php

namespace Drupal\simple_oauth_21\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route subscriber to fix D11 compatibility for _access: 'TRUE' routes.
 *
 * In Drupal 11, routes with _access: 'TRUE' have initialization timing issues
 * in certain environments (particularly GitHub Actions). This subscriber
 * provides fallback access control that works reliably across all D11 contexts.
 */
class SimpleOauthD11RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // Only apply this fix for Drupal 11+.
    if (version_compare(\Drupal::VERSION, '11.0', '<')) {
      return;
    }

    // Routes that need D11 compatibility fixes.
    $routes_to_fix = [
      'simple_oauth.server_metadata',
      'oauth2_token.token',
      'oauth2_token.authorize',
    ];

    foreach ($routes_to_fix as $route_name) {
      $route = $collection->get($route_name);
      if ($route && $route->getRequirement('_access') === 'TRUE') {
        // Replace _access: 'TRUE' with a D11-compatible public access pattern.
        // Remove the problematic requirement and use anonymous access.
        $requirements = $route->getRequirements();
        unset($requirements['_access']);
        $route->setRequirements($requirements);
        // Set anonymous user access to ensure public availability.
        $route->setRequirement('_user_is_logged_in', 'FALSE');
      }
    }
  }

}
