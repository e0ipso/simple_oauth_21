<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Event;

/**
 * Defines events for OAuth 2.0 Protected Resource Metadata.
 */
final class ResourceMetadataEvents {

  /**
   * Event dispatched when building resource metadata.
   *
   * Allows modules to add or modify OAuth 2.0 Protected Resource
   * Metadata (RFC 9728) before it's sent to clients.
   *
   * Subscribers can add custom fields, override configured values,
   * or contribute resource-specific capabilities.
   *
   * @Event("Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent")
   *
   * @var string
   */
  public const BUILD = 'simple_oauth_server_metadata.resource_metadata.build';

}
