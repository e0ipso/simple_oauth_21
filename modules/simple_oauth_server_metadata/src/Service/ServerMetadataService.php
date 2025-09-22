<?php

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;

/**
 * Main service for generating RFC 8414 server metadata.
 */
class ServerMetadataService implements CacheableDependencyInterface {

  use CacheableDependencyTrait;

  /**
   * Constructs a ServerMetadataService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\simple_oauth_server_metadata\Service\EndpointDiscoveryService $endpointDiscovery
   *   The endpoint discovery service.
   * @param \Drupal\simple_oauth_server_metadata\Service\GrantTypeDiscoveryService $grantTypeDiscovery
   *   The grant type discovery service.
   * @param \Drupal\simple_oauth_server_metadata\Service\ScopeDiscoveryService $scopeDiscovery
   *   The scope discovery service.
   * @param \Drupal\simple_oauth_server_metadata\Service\ClaimsAuthDiscoveryService $claimsAuthDiscovery
   *   The claims and auth discovery service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EndpointDiscoveryService $endpointDiscovery,
    private readonly GrantTypeDiscoveryService $grantTypeDiscovery,
    private readonly ScopeDiscoveryService $scopeDiscovery,
    private readonly ClaimsAuthDiscoveryService $claimsAuthDiscovery,
  ) {
    $this->cacheTags = [
      'config:simple_oauth.settings',
      'config:simple_oauth_server_metadata.settings',
      'user_role_list',
      'oauth2_grant_plugins',
      'route_match',
      'simple_oauth_server_metadata',
      'route:simple_oauth_client_registration.register',
      'route:entity.consumer.add_form',
    ];

    $this->cacheContexts = [
      'url.path',
      'user.permissions',
    ];
  }

  /**
   * Gets the complete server metadata per RFC 8414.
   *
   * @param array $config_override
   *   Optional configuration override for preview purposes.
   *
   * @return array
   *   The server metadata array compliant with RFC 8414.
   */
  public function getServerMetadata(array $config_override = []): array {
    $metadata = [];

    // Add required fields per RFC 8414.
    $metadata['issuer'] = $this->endpointDiscovery->getIssuer();
    $metadata['response_types_supported'] = $this->grantTypeDiscovery->getResponseTypesSupported();

    // Add response modes supported.
    $metadata['response_modes_supported'] = $this->grantTypeDiscovery->getResponseModesSupported();

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

    // Add request URI parameter support.
    $metadata['request_uri_parameter_supported'] = $this->claimsAuthDiscovery->getRequestUriParameterSupported();
    $metadata['require_request_uri_registration'] = $this->claimsAuthDiscovery->getRequireRequestUriRegistration();

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

      // Auto-derive registration endpoint if not configured and route exists.
      if ($field === 'registration_endpoint' && !$value) {
        try {
          $value = Url::fromRoute('simple_oauth_client_registration.register', [], ['absolute' => TRUE])
            ->toString();
        }
        catch (\Exception $e) {
          $value = NULL;
        }
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

    // Boolean fields that should be preserved even when FALSE.
    $boolean_fields = [
      'request_uri_parameter_supported',
      'require_request_uri_registration',
    ];

    return array_filter($metadata, function ($value, $key) use ($required_fields, $boolean_fields) {
      // Keep required fields even if empty (for validation)
      if (in_array($key, $required_fields)) {
        return TRUE;
      }
      // Keep boolean fields even when FALSE (meaningful information).
      if (in_array($key, $boolean_fields) && is_bool($value)) {
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

}
