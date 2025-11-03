<?php

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent;
use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Service for generating RFC 9728 protected resource metadata.
 */
class ResourceMetadataService implements CacheableDependencyInterface {

  use CacheableDependencyTrait;

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

    // Remove empty optional fields per RFC 9728.
    $metadata = $this->filterEmptyFields($metadata);

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
   * Ensures a URL is absolute.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return string
   *   The absolute URL.
   */
  protected function ensureAbsoluteUrl(string $url): string {
    // If URL already contains scheme, it's absolute.
    if (preg_match('/^https?:\/\//', $url)) {
      return $url;
    }

    // If URL starts with /, it's a relative path - convert to absolute.
    if (strpos($url, '/') === 0) {
      try {
        return Url::fromUserInput($url)->setAbsolute()->toString();
      }
      catch (\Exception $e) {
        // If URL conversion fails, return as-is.
        return $url;
      }
    }

    // Return as-is for other cases.
    return $url;
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
      // Filter out empty optional fields.
      return !empty($value);
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
