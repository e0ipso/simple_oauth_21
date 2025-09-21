<?php

namespace Drupal\simple_oauth_21\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\Route;
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
    // Only apply fixes in Drupal 11+ environments.
    if (version_compare(\Drupal::VERSION, '11.0', '<')) {
      return;
    }

    // Define missing routes that aren't being registered properly in D11.
    $this->addMissingOAuthRoutes($collection);

    // Fix routes that use '_access: TRUE' which doesn't work in D11 tests.
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

  /**
   * Add missing OAuth routes that aren't being registered in D11.
   */
  protected function addMissingOAuthRoutes(RouteCollection $collection) {
    // Add simple_oauth.server_metadata route if missing.
    if (!$collection->get('simple_oauth.server_metadata')) {
      $route = new Route(
        '/.well-known/oauth-authorization-server',
        [
          '_controller' => '\Drupal\simple_oauth\Controller\ServerMetadata::metadata',
          '_title' => 'OAuth 2.1 Server Metadata',
        ],
        [
          '_custom_access' => '\Drupal\simple_oauth_21\Access\OAuth21AccessChecker::access',
        ],
        [],
        '',
        [],
        ['GET']
      );
      $collection->add('simple_oauth.server_metadata', $route);
    }

    // Add oauth2_token.authorize route if missing.
    if (!$collection->get('oauth2_token.authorize')) {
      $route = new Route(
        '/oauth/authorize',
        [
          '_controller' => '\Drupal\simple_oauth\Controller\Oauth2AuthorizeController::authorize',
          '_title' => 'Grant Access to Client',
        ],
        [
          '_custom_access' => '\Drupal\simple_oauth_21\Access\OAuth21AccessChecker::access',
        ],
        [
          'no_cache' => TRUE,
        ],
        '',
        [],
        ['GET', 'POST']
      );
      $collection->add('oauth2_token.authorize', $route);
    }

    // Add oauth2_token.token route if missing.
    if (!$collection->get('oauth2_token.token')) {
      $route = new Route(
        '/oauth/token',
        [
          '_controller' => '\Drupal\simple_oauth\Controller\Oauth2Token::token',
        ],
        [
          '_custom_access' => '\Drupal\simple_oauth_21\Access\OAuth21AccessChecker::access',
        ],
        [],
        '',
        [],
        ['POST']
      );
      $collection->add('oauth2_token.token', $route);
    }
  }

}
