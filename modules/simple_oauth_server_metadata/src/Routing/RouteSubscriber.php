<?php

namespace Drupal\simple_oauth_server_metadata\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // Override the simple_oauth server metadata route with our enhanced version.
    if ($route = $collection->get('simple_oauth.server_metadata')) {
      // Replace the controller with our enhanced one.
      $route->setDefault('_controller', '\Drupal\simple_oauth_server_metadata\Controller\ServerMetadataController::metadata');
      $route->setOption('no_cache', TRUE);
    }
  }

}
