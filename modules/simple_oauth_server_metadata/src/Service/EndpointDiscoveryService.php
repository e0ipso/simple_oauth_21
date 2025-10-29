<?php

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
    return Url::fromRoute('oauth2_token.authorize')->setAbsolute()->toString();
  }

  /**
   * Gets the token endpoint URL.
   *
   * @return string
   *   The token endpoint URL.
   */
  public function getTokenEndpoint(): string {
    return Url::fromRoute('oauth2_token.token')->setAbsolute()->toString();
  }

  /**
   * Gets the JWKS URI.
   *
   * @return string
   *   The JWKS URI.
   */
  public function getJwksUri(): string {
    return Url::fromRoute('simple_oauth.jwks')->setAbsolute()->toString();
  }

  /**
   * Gets the UserInfo endpoint URL.
   *
   * @return string
   *   The UserInfo endpoint URL.
   */
  public function getUserInfoEndpoint(): string {
    return Url::fromRoute('simple_oauth.userinfo')->setAbsolute()->toString();
  }

  /**
   * Gets the registration endpoint URL if available.
   *
   * @return string|null
   *   The registration endpoint URL, or NULL if not available.
   */
  public function getRegistrationEndpoint(): ?string {
    try {
      return Url::fromRoute('simple_oauth_client_registration.register')->setAbsolute()->toString();
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
      return Url::fromRoute('simple_oauth_server_metadata.revoke')->setAbsolute()->toString();
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
      return Url::fromRoute('simple_oauth_server_metadata.token_introspection')->setAbsolute()->toString();
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
    return Url::fromRoute('simple_oauth_server_metadata.well_known')->setAbsolute()->toString();
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
        $url = Url::fromRoute('simple_oauth_device_flow.device_authorization');
        $endpoints['device_authorization_endpoint'] = $url->setAbsolute()
          ->toString();
      }
      catch (\Exception $e) {
        // Route doesn't exist or error generating URL.
      }
    }

    // Add OAuth server metadata endpoint.
    $endpoints['oauth_authorization_server_metadata_endpoint'] = $this->getOauthServerMetadataEndpoint();

    return $endpoints;
  }

}
