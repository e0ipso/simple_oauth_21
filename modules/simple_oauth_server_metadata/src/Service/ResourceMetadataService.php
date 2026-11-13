<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\simple_oauth_server_metadata\Trait\MetadataCacheabilityTrait;
use Drupal\simple_oauth_server_metadata\Trait\MetadataUrlTrait;
use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent;
use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Service for generating RFC 9728 protected resource metadata.
 */
class ResourceMetadataService implements CacheableDependencyInterface {

  use MetadataCacheabilityTrait;
  use MetadataUrlTrait;

  /**
   * Constructs a ResourceMetadataService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\simple_oauth_server_metadata\Service\EndpointDiscoveryService $endpointDiscovery
   *   The endpoint discovery service.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EndpointDiscoveryService $endpointDiscovery,
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {
    $this->cacheTags = [
      'config:simple_oauth.settings',
      'config:simple_oauth_server_metadata.settings',
    ];

    $this->cacheContexts = [
      'url.path',
      'url.site',
      'user.permissions',
    ];
  }

  /**
   * Gets the complete resource metadata per RFC 9728.
   *
   * @param array $config_override
   *   Optional configuration override for preview purposes.
   *
   * @return array
   *   The resource metadata array compliant with RFC 9728.
   */
  public function getResourceMetadata(array $config_override = []): array {
    $this->generationCacheability = NULL;
    $metadata = [];

    // Add required fields per RFC 9728.
    $metadata['resource'] = $this->endpointDiscovery->getIssuer();
    $metadata['authorization_servers'] = [$this->endpointDiscovery->getIssuer()];

    // Add bearer methods supported (default methods).
    $metadata['bearer_methods_supported'] = ['header', 'body', 'query'];

    // Add admin-configured fields.
    $config = $this->configFactory->get('simple_oauth_server_metadata.settings');
    $this->addConfigurableFields($metadata, $config, $config_override);

    // Dispatch event to allow modules to modify metadata.
    $event = new ResourceMetadataEvent($metadata);
    $this->eventDispatcher->dispatch($event, ResourceMetadataEvents::BUILD);
    $metadata = $event->metadata;
    $this->generationCacheability = CacheableMetadata::createFromObject($config)
      ->addCacheableDependency($event);

    // Remove empty optional fields per RFC 9728.
    $metadata = $this->filterEmptyFields($metadata);

    // Project known collections as JSON arrays without changing their sources.
    foreach ([
      'authorization_servers',
      'bearer_methods_supported',
    ] as $field) {
      if (isset($metadata[$field]) && is_array($metadata[$field])) {
        $metadata[$field] = array_values($metadata[$field]);
      }
    }

    return $metadata;
  }

  /**
   * Adds admin-configurable fields to metadata.
   *
   * @param array $metadata
   *   The metadata array to add fields to.
   * @param \Drupal\Core\Config\Config $config
   *   The configuration object.
   * @param array $config_override
   *   Optional configuration override values.
   */
  protected function addConfigurableFields(array &$metadata, $config, array $config_override = []): void {
    $configurable_fields = [
      'resource_documentation',
      'resource_policy_uri',
      'resource_tos_uri',
    ];

    // Fields that should be converted to absolute URLs if they are relative.
    $url_fields = [
      'resource_documentation',
      'resource_policy_uri',
      'resource_tos_uri',
    ];

    foreach ($configurable_fields as $field) {
      // Use override value if provided, otherwise use config value.
      if (array_key_exists($field, $config_override)) {
        $value = $config_override[$field];
      }
      else {
        $value = $config->get($field);
      }

      if (!empty($value)) {
        // Convert relative URLs to absolute URLs for URL fields.
        if (in_array($field, $url_fields) && is_string($value)) {
          $value = $this->ensureAbsoluteUrl($value);
        }
        $metadata[$field] = $value;
      }
    }
  }

  /**
   * Filters out empty optional fields.
   *
   * @param array $metadata
   *   The metadata array to filter.
   *
   * @return array
   *   The filtered metadata array.
   */
  protected function filterEmptyFields(array $metadata): array {
    // Required fields that must not be filtered.
    $required_fields = ['resource', 'authorization_servers'];

    return array_filter($metadata, function ($value, $key) use ($required_fields) {
      // Keep required fields even if empty (for validation).
      if (in_array($key, $required_fields)) {
        return TRUE;
      }
      // Keep meaningful scalar extension values, including FALSE and zero.
      return $value !== NULL && $value !== '' && $value !== [];
    }, ARRAY_FILTER_USE_BOTH);
  }

  /**
   * Validates metadata for RFC 9728 compliance.
   *
   * @param array $metadata
   *   The metadata array to validate.
   *
   * @return bool
   *   TRUE if metadata is RFC 9728 compliant, FALSE otherwise.
   */
  public function validateMetadata(array $metadata): bool {
    // Check required fields per RFC 9728.
    $required_fields = ['resource', 'authorization_servers'];

    foreach ($required_fields as $field) {
      if (empty($metadata[$field])) {
        return FALSE;
      }
    }

    return TRUE;
  }

}
