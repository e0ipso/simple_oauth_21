<?php

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for generating OpenID Connect Discovery metadata.
 */
class OpenIdConfigurationService implements CacheableDependencyInterface {

  use CacheableDependencyTrait;

  /**
   * Constructs an OpenIdConfigurationService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\simple_oauth_server_metadata\Service\EndpointDiscoveryService $endpointDiscoveryService
   *   The endpoint discovery service.
   * @param \Drupal\simple_oauth_server_metadata\Service\ScopeDiscoveryService $scopeDiscoveryService
   *   The scope discovery service.
   * @param \Drupal\simple_oauth_server_metadata\Service\GrantTypeDiscoveryService $grantTypeDiscoveryService
   *   The grant type discovery service.
   * @param \Drupal\simple_oauth_server_metadata\Service\ClaimsAuthDiscoveryService $claimsAuthDiscoveryService
   *   The claims and authentication discovery service.
   * @param array $supportedClaims
   *   The array of supported OpenID claims from parameters.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EndpointDiscoveryService $endpointDiscoveryService,
    private readonly ScopeDiscoveryService $scopeDiscoveryService,
    private readonly GrantTypeDiscoveryService $grantTypeDiscoveryService,
    private readonly ClaimsAuthDiscoveryService $claimsAuthDiscoveryService,
    private readonly array $supportedClaims,
  ) {}

  /**
   * Gets the complete OpenID Connect Discovery metadata.
   *
   * @return array
   *   The OpenID Connect Discovery metadata array.
   *
   * @throws \InvalidArgumentException
   *   When required metadata fields are missing or invalid.
   */
  public function getOpenIdConfiguration(): array {
    $config = $this->configFactory->get('simple_oauth_server_metadata.settings');

    $metadata = [
      // Required OpenID Connect Discovery fields.
      'issuer' => $this->endpointDiscoveryService->getIssuer(),
      'authorization_endpoint' => $this->endpointDiscoveryService->getAuthorizationEndpoint(),
      'token_endpoint' => $this->endpointDiscoveryService->getTokenEndpoint(),
      'userinfo_endpoint' => $this->endpointDiscoveryService->getUserInfoEndpoint(),
      'jwks_uri' => $this->endpointDiscoveryService->getJwksUri(),
      'scopes_supported' => $this->getSupportedScopes(),
      'response_types_supported' => $this->getSupportedResponseTypes(),
      'subject_types_supported' => $this->claimsAuthDiscoveryService->getSubjectTypesSupported(),
      'id_token_signing_alg_values_supported' => $this->claimsAuthDiscoveryService->getIdTokenSigningAlgValuesSupported(),
      'claims_supported' => $this->getClaimsSupported(),

      // Optional but commonly expected fields.
      'response_modes_supported' => $this->getSupportedResponseModes(),
      'grant_types_supported' => $this->grantTypeDiscoveryService->getGrantTypesSupported(),
      'token_endpoint_auth_methods_supported' => $this->claimsAuthDiscoveryService->getTokenEndpointAuthMethodsSupported(),
      'token_endpoint_auth_signing_alg_values_supported' => $this->claimsAuthDiscoveryService->getTokenEndpointAuthSigningAlgValuesSupported(),
      'code_challenge_methods_supported' => $this->claimsAuthDiscoveryService->getCodeChallengeMethodsSupported(),
      'request_uri_parameter_supported' => $this->claimsAuthDiscoveryService->getRequestUriParameterSupported(),
      'require_request_uri_registration' => $this->claimsAuthDiscoveryService->getRequireRequestUriRegistration(),
    ];

    // Add optional configured endpoints and URIs.
    $optional_fields = [
      'service_documentation' => $config->get('service_documentation'),
      'op_policy_uri' => $config->get('op_policy_uri'),
      'op_tos_uri' => $config->get('op_tos_uri'),
    ];

    foreach ($optional_fields as $field => $value) {
      if (!empty($value)) {
        $metadata[$field] = $value;
      }
    }

    // Add supported UI locales if configured.
    $ui_locales = $config->get('ui_locales_supported');
    if (!empty($ui_locales)) {
      $metadata['ui_locales_supported'] = $ui_locales;
    }

    // Add additional signing algorithms if configured.
    $additional_algorithms = $config->get('additional_signing_algorithms');
    if (!empty($additional_algorithms)) {
      $metadata['id_token_signing_alg_values_supported'] = array_merge(
        $metadata['id_token_signing_alg_values_supported'],
        $additional_algorithms
      );
    }

    // Validate the metadata before returning.
    $this->validateMetadata($metadata);

    return $metadata;
  }

  /**
   * Gets supported scopes for OpenID Connect.
   *
   * @return array
   *   Array of supported scope values.
   */
  protected function getSupportedScopes(): array {
    $scopes = $this->scopeDiscoveryService->getScopesSupported();

    // Ensure 'openid' scope is included as it's required for OpenID Connect.
    if (!in_array('openid', $scopes, TRUE)) {
      array_unshift($scopes, 'openid');
    }

    return array_unique($scopes);
  }

  /**
   * Gets supported response types for OpenID Connect.
   *
   * @return array
   *   Array of supported response types.
   */
  protected function getSupportedResponseTypes(): array {
    // Standard OAuth 2.0 and OpenID Connect response types.
    return [
      'code',
      'token',
      'id_token',
      'code id_token',
      'code token',
      'id_token token',
      'code id_token token',
    ];
  }

  /**
   * Gets supported response modes.
   *
   * @return array
   *   Array of supported response modes.
   */
  protected function getSupportedResponseModes(): array {
    return ['query', 'fragment', 'form_post'];
  }

  /**
   * Gets the complete list of supported claims.
   *
   * @return array
   *   Array of supported claim names.
   */
  protected function getClaimsSupported(): array {
    // Start with claims from the ClaimsAuthDiscoveryService.
    $claims = $this->claimsAuthDiscoveryService->getClaimsSupported();

    // Merge with configured claims parameter.
    if (!empty($this->supportedClaims)) {
      $claims = array_merge($claims, $this->supportedClaims);
    }

    // Add additional configured claims.
    $config = $this->configFactory->get('simple_oauth_server_metadata.settings');
    $additional_claims = $config->get('additional_claims_supported');
    if (!empty($additional_claims)) {
      $claims = array_merge($claims, $additional_claims);
    }

    // Ensure unique values and re-index to get a proper array.
    return array_values(array_unique($claims));
  }

  /**
   * Validates the OpenID Connect metadata.
   *
   * @param array $metadata
   *   The metadata array to validate.
   *
   * @throws \InvalidArgumentException
   *   When required fields are missing or empty.
   */
  protected function validateMetadata(array $metadata): void {
    $required_fields = [
      'issuer',
      'authorization_endpoint',
      'token_endpoint',
      'userinfo_endpoint',
      'jwks_uri',
      'scopes_supported',
      'response_types_supported',
      'subject_types_supported',
      'id_token_signing_alg_values_supported',
      'claims_supported',
    ];

    foreach ($required_fields as $field) {
      if (!isset($metadata[$field]) || empty($metadata[$field])) {
        throw new \InvalidArgumentException("Required OpenID Connect Discovery field '$field' is missing or empty");
      }
    }

    // Validate that essential scopes are present.
    if (!in_array('openid', $metadata['scopes_supported'], TRUE)) {
      throw new \InvalidArgumentException("Required 'openid' scope is missing from scopes_supported");
    }

    // Validate that issuer is a valid HTTPS URL.
    if (!filter_var($metadata['issuer'], FILTER_VALIDATE_URL) ||
        !str_starts_with($metadata['issuer'], 'https://')) {
      throw new \InvalidArgumentException("Issuer must be a valid HTTPS URL");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return ['url.site'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return [
      'config:simple_oauth_server_metadata.settings',
      'config:simple_oauth.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    // Cache for 1 hour as this metadata rarely changes.
    return 3600;
  }

}
