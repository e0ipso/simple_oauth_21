<?php

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;

/**
 * Service for generating RFC 9728 protected resource metadata.
 */
class ResourceMetadataService {

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The endpoint discovery service.
   *
   * @var \Drupal\simple_oauth_server_metadata\Service\EndpointDiscoveryService
   */
  protected $endpointDiscovery;

  /**
   * Constructs a ResourceMetadataService object.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\simple_oauth_server_metadata\Service\EndpointDiscoveryService $endpoint_discovery
   *   The endpoint discovery service.
   */
  public function __construct(
    CacheBackendInterface $cache,
    ConfigFactoryInterface $config_factory,
    EndpointDiscoveryService $endpoint_discovery,
  ) {
    $this->cache = $cache;
    $this->configFactory = $config_factory;
    $this->endpointDiscovery = $endpoint_discovery;
  }

  /**
   * Gets the complete resource metadata per RFC 9728 with caching.
   *
   * @param array $config_override
   *   Optional configuration override for preview purposes.
   *
   * @return array
   *   The resource metadata array compliant with RFC 9728.
   */
  public function getResourceMetadata(array $config_override = []): array {
    // Skip cache if using config override (for preview).
    if (!empty($config_override)) {
      return $this->generateMetadata($config_override);
    }

    $cache_id = 'simple_oauth_server_metadata:resource_metadata';
    $cache_tags = $this->getCacheTags();

    // Try to get from cache first.
    $cached = $this->cache->get($cache_id);
    if ($cached && $cached->valid) {
      return $cached->data;
    }

    // Generate metadata if not cached.
    $metadata = $this->generateMetadata();

    // Cache for 1 hour with appropriate tags.
    $this->cache->set(
      $cache_id,
      $metadata,
      time() + 3600,
      $cache_tags
    );

    return $metadata;
  }

  /**
   * Generates metadata without caching (for internal use).
   *
   * @param array $config_override
   *   Optional configuration override for preview purposes.
   *
   * @return array
   *   The resource metadata array compliant with RFC 9728.
   */
  protected function generateMetadata(array $config_override = []): array {
    $metadata = [];

    // Add required fields per RFC 9728.
    $metadata['resource'] = $this->endpointDiscovery->getIssuer();
    $metadata['authorization_servers'] = [$this->endpointDiscovery->getIssuer()];

    // Add bearer methods supported (default methods).
    $metadata['bearer_methods_supported'] = ['header', 'body', 'query'];

    // Add admin-configured fields.
    $config = $this->configFactory->get('simple_oauth_server_metadata.settings');
    $this->addConfigurableFields($metadata, $config, $config_override);

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

  /**
   * Gets cache tags for metadata invalidation.
   *
   * @return array
   *   Array of cache tags.
   */
  protected function getCacheTags(): array {
    return [
      'config:simple_oauth.settings',
      'config:simple_oauth_server_metadata.settings',
    ];
  }

  /**
   * Invalidates the metadata cache.
   */
  public function invalidateCache(): void {
    Cache::invalidateTags($this->getCacheTags());
  }

  /**
   * Warms the cache by pre-generating metadata.
   */
  public function warmCache(): void {
    $this->getResourceMetadata();
  }

}
