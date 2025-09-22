<?php

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for discovering OAuth 2.0 endpoints.
 */
class EndpointDiscoveryService {

  /**
   * Constructs an EndpointDiscoveryService object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack service.
   */
  public function __construct(
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Gets the issuer identifier.
   *
   * @return string
   *   The issuer identifier URL.
   */
  public function getIssuer(): string {
    $request = $this->requestStack->getCurrentRequest();
    return $request->getSchemeAndHttpHost();
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
   * Gets the OAuth server metadata endpoint URL.
   *
   * @return string
   *   The OAuth server metadata endpoint URL.
   */
  public function getOauthServerMetadataEndpoint(): string {
    return Url::fromRoute('simple_oauth.server_metadata')->setAbsolute()->toString();
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

    // Add OAuth server metadata endpoint.
    $endpoints['oauth_authorization_server_metadata_endpoint'] = $this->getOauthServerMetadataEndpoint();

    return $endpoints;
  }

}
