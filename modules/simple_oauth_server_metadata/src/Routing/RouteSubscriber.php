<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Routing;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * Constructs the route subscriber.
   *
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface|null $cacheTagsInvalidator
   *   The cache invalidator, or NULL for legacy constructor callers.
   */
  public function __construct(
    private readonly ?CacheTagsInvalidatorInterface $cacheTagsInvalidator = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // Rebuilt routes can add or remove any discovered optional endpoint.
    $tags = ['simple_oauth_server_metadata', 'route_match'];
    if ($this->cacheTagsInvalidator !== NULL) {
      $this->cacheTagsInvalidator->invalidateTags($tags);
    }
    else {
      Cache::invalidateTags($tags);
    }

    // Override the simple_oauth server metadata route with our enhanced
    // version.
    if ($route = $collection->get('simple_oauth.server_metadata')) {
      // Replace the controller with our enhanced one.
      $route->setDefault('_controller', '\Drupal\simple_oauth_server_metadata\Controller\ServerMetadataController');
      $route->setOption('no_cache', TRUE);
    }
  }

}
