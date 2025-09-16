<?php

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Url;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Main service for generating RFC 8414 server metadata.
 */
class ServerMetadataService {

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
   * The grant type discovery service.
   *
   * @var \Drupal\simple_oauth_server_metadata\Service\GrantTypeDiscoveryService
   */
  protected $grantTypeDiscovery;

  /**
   * The scope discovery service.
   *
   * @var \Drupal\simple_oauth_server_metadata\Service\ScopeDiscoveryService
   */
  protected $scopeDiscovery;

  /**
   * The claims and auth discovery service.
   *
   * @var \Drupal\simple_oauth_server_metadata\Service\ClaimsAuthDiscoveryService
   */
  protected $claimsAuthDiscovery;

  /**
   * Constructs a ServerMetadataService object.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\simple_oauth_server_metadata\Service\EndpointDiscoveryService $endpoint_discovery
   *   The endpoint discovery service.
   * @param \Drupal\simple_oauth_server_metadata\Service\GrantTypeDiscoveryService $grant_type_discovery
   *   The grant type discovery service.
   * @param \Drupal\simple_oauth_server_metadata\Service\ScopeDiscoveryService $scope_discovery
   *   The scope discovery service.
   * @param \Drupal\simple_oauth_server_metadata\Service\ClaimsAuthDiscoveryService $claims_auth_discovery
   *   The claims and auth discovery service.
   */
  public function __construct(
    CacheBackendInterface $cache,
    ConfigFactoryInterface $config_factory,
    EndpointDiscoveryService $endpoint_discovery,
    GrantTypeDiscoveryService $grant_type_discovery,
    ScopeDiscoveryService $scope_discovery,
    ClaimsAuthDiscoveryService $claims_auth_discovery,
  ) {
    $this->cache = $cache;
    $this->configFactory = $config_factory;
    $this->endpointDiscovery = $endpoint_discovery;
    $this->grantTypeDiscovery = $grant_type_discovery;
    $this->scopeDiscovery = $scope_discovery;
    $this->claimsAuthDiscovery = $claims_auth_discovery;
  }

  /**
   * Gets the complete server metadata per RFC 8414 with caching.
   *
   * @param array $config_override
   *   Optional configuration override for preview purposes.
   *
   * @return array
   *   The server metadata array compliant with RFC 8414.
   */
  public function getServerMetadata(array $config_override = []): array {
    // Skip cache if using config override (for preview).
    if (!empty($config_override)) {
      return $this->generateMetadata($config_override);
    }

    $cache_id = 'simple_oauth_server_metadata:metadata';
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
   *   The server metadata array compliant with RFC 8414.
   */
  protected function generateMetadata(array $config_override = []): array {
    $metadata = [];

    // Add required fields per RFC 8414.
    $metadata['issuer'] = $this->endpointDiscovery->getIssuer();
    $metadata['response_types_supported'] = $this->grantTypeDiscovery->getResponseTypesSupported();

    // Add core endpoints.
    $core_endpoints = $this->endpointDiscovery->getCoreEndpoints();
    // Already set above.
    unset($core_endpoints['issuer']);
    $metadata = array_merge($metadata, $core_endpoints);

    // Add grant types and scopes.
    $metadata['grant_types_supported'] = $this->grantTypeDiscovery->getGrantTypesSupported();
    $metadata['scopes_supported'] = $this->scopeDiscovery->getScopesSupported();

    // Add authentication and signing methods.
    $metadata['token_endpoint_auth_methods_supported'] = $this->claimsAuthDiscovery->getTokenEndpointAuthMethodsSupported();
    $metadata['token_endpoint_auth_signing_alg_values_supported'] = $this->claimsAuthDiscovery->getTokenEndpointAuthSigningAlgValuesSupported();

    // Add PKCE support.
    $metadata['code_challenge_methods_supported'] = $this->claimsAuthDiscovery->getCodeChallengeMethodsSupported();

    // Add OpenID Connect fields if enabled.
    $claims = $this->claimsAuthDiscovery->getClaimsSupported();
    if (!empty($claims)) {
      $metadata['claims_supported'] = $claims;
      $metadata['subject_types_supported'] = $this->claimsAuthDiscovery->getSubjectTypesSupported();
      $metadata['id_token_signing_alg_values_supported'] = $this->claimsAuthDiscovery->getIdTokenSigningAlgValuesSupported();
    }

    // Add admin-configured fields.
    $config = $this->configFactory->get('simple_oauth_server_metadata.settings');
    $this->addConfigurableFields($metadata, $config, $config_override);

    // Remove empty optional fields per RFC 8414.
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
      'registration_endpoint',
      'revocation_endpoint',
      'introspection_endpoint',
      'service_documentation',
      'op_policy_uri',
      'op_tos_uri',
      'ui_locales_supported',
      'additional_claims_supported',
      'additional_signing_algorithms',
    ];

    // Fields that should be converted to absolute URLs if they are relative.
    $url_fields = [
      'registration_endpoint',
      'revocation_endpoint',
      'introspection_endpoint',
      'service_documentation',
      'op_policy_uri',
      'op_tos_uri',
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
    $required_fields = ['issuer', 'response_types_supported'];

    return array_filter($metadata, function ($value, $key) use ($required_fields) {
      // Keep required fields even if empty (for validation)
      if (in_array($key, $required_fields)) {
        return TRUE;
      }
      // Filter out empty optional fields.
      return !empty($value);
    }, ARRAY_FILTER_USE_BOTH);
  }

  /**
   * Validates metadata for RFC 8414 compliance.
   *
   * @param array $metadata
   *   The metadata array to validate.
   *
   * @return bool
   *   TRUE if metadata is RFC 8414 compliant, FALSE otherwise.
   */
  public function validateMetadata(array $metadata): bool {
    // Check required fields per RFC 8414.
    $required_fields = ['issuer', 'response_types_supported'];

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
      'user_role_list',
      'oauth2_grant_plugins',
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
    $this->getServerMetadata();
  }

}
