<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;

/**
 * Service for discovering OAuth 2.0 endpoints.
 */
class EndpointDiscoveryService {

  /**
   * Constructs an EndpointDiscoveryService object.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   */
  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Gets the issuer identifier.
   *
   * @return string
   *   The issuer identifier URL.
   */
  public function getIssuer(): string {
    // Generate the issuer URL using the same approach as OpenID Connect.
    // Per RFC 8725 Section 3.1, the iss claim should be included for security.
    $language_none = $this->languageManager->getLanguage(LanguageInterface::LANGCODE_NOT_APPLICABLE);
    return Url::fromUri('internal:/', ['language' => $language_none, 'https' => TRUE])->setAbsolute()->toString();
  }

  /**
   * Gets the authorization endpoint URL.
   *
   * @return string
   *   The authorization endpoint URL.
   */
  public function getAuthorizationEndpoint(): string {
    return $this->endpointUrl('oauth2_token.authorize');
  }

  /**
   * Gets the token endpoint URL.
   *
   * @return string
   *   The token endpoint URL.
   */
  public function getTokenEndpoint(): string {
    return $this->endpointUrl('oauth2_token.token');
  }

  /**
   * Gets the JWKS URI.
   *
   * @return string
   *   The JWKS URI.
   */
  public function getJwksUri(): string {
    return $this->endpointUrl('simple_oauth.jwks');
  }

  /**
   * Gets the UserInfo endpoint URL.
   *
   * @return string
   *   The UserInfo endpoint URL.
   */
  public function getUserInfoEndpoint(): string {
    return $this->endpointUrl('simple_oauth.userinfo');
  }

  /**
   * Gets the registration endpoint URL if available.
   *
   * @return string|null
   *   The registration endpoint URL, or NULL if not available.
   */
  public function getRegistrationEndpoint(): ?string {
    try {
      return $this->endpointUrl('simple_oauth_client_registration.register');
    }
    catch (\Exception $e) {
      // Route doesn't exist, return NULL.
      return NULL;
    }
  }

  /**
   * Gets the revocation endpoint URL if available.
   *
   * @return string|null
   *   The revocation endpoint URL, or NULL if not available.
   */
  public function getRevocationEndpoint(): ?string {
    try {
      return $this->endpointUrl('simple_oauth_server_metadata.revoke');
    }
    catch (\Exception $e) {
      // Route doesn't exist, return NULL.
      return NULL;
    }
  }

  /**
   * Gets the introspection endpoint URL if available.
   *
   * @return string|null
   *   The introspection endpoint URL, or NULL if not available.
   */
  public function getIntrospectionEndpoint(): ?string {
    try {
      return $this->endpointUrl('simple_oauth_server_metadata.token_introspection');
    }
    catch (\Exception $e) {
      // Route doesn't exist, return NULL.
      return NULL;
    }
  }

  /**
   * Gets the OAuth server metadata endpoint URL.
   *
   * @return string
   *   The OAuth server metadata endpoint URL.
   */
  public function getOauthServerMetadataEndpoint(): string {
    return $this->endpointUrl('simple_oauth_server_metadata.well_known');
  }

  /**
   * Gets all core endpoints.
   *
   * @return array
   *   An array of core OAuth 2.0 endpoints.
   */
  public function getCoreEndpoints(): array {
    $endpoints = [
      'issuer' => $this->getIssuer(),
      'authorization_endpoint' => $this->getAuthorizationEndpoint(),
      'token_endpoint' => $this->getTokenEndpoint(),
      'jwks_uri' => $this->getJwksUri(),
      'userinfo_endpoint' => $this->getUserInfoEndpoint(),
    ];

    // Add optional endpoints when available.
    $registration_endpoint = $this->getRegistrationEndpoint();
    if ($registration_endpoint !== NULL) {
      $endpoints['registration_endpoint'] = $registration_endpoint;
    }

    $revocation_endpoint = $this->getRevocationEndpoint();
    if ($revocation_endpoint !== NULL) {
      $endpoints['revocation_endpoint'] = $revocation_endpoint;
    }

    $introspection_endpoint = $this->getIntrospectionEndpoint();
    if ($introspection_endpoint !== NULL) {
      $endpoints['introspection_endpoint'] = $introspection_endpoint;
    }

    // Check for Device Flow module.
    if ($this->moduleHandler->moduleExists('simple_oauth_device_flow')) {
      try {
        $endpoints['device_authorization_endpoint'] = $this->endpointUrl('simple_oauth_device_flow.device_authorization');
      }
      catch (\Exception $e) {
        // Route doesn't exist or error generating URL.
      }
    }

    // Add OAuth server metadata endpoint.
    $endpoints['oauth_authorization_server_metadata_endpoint'] = $this->getOauthServerMetadataEndpoint();

    return $endpoints;
  }

  /**
   * Builds the absolute URL of an endpoint, without a language prefix.
   *
   * Clients call the endpoints at the URLs the metadata lists, so the URLs
   * must not depend on the language of the request that built the metadata.
   *
   * @param string $route_name
   *   The route of the endpoint.
   *
   * @return string
   *   The absolute URL of the endpoint.
   */
  private function endpointUrl(string $route_name): string {
    return Url::fromRoute($route_name, [], [
      'absolute' => TRUE,
      'language' => $this->languageManager->getLanguage(LanguageInterface::LANGCODE_NOT_APPLICABLE),
    ])->toString();
  }

}
