<?php

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for discovering OAuth 2.0 endpoints.
 */
class EndpointDiscoveryService {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs an EndpointDiscoveryService object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   */
  public function __construct(RequestStack $request_stack) {
    $this->requestStack = $request_stack;
  }

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
   * Gets all core endpoints.
   *
   * @return array
   *   An array of core OAuth 2.0 endpoints.
   */
  public function getCoreEndpoints(): array {
    return [
      'issuer' => $this->getIssuer(),
      'authorization_endpoint' => $this->getAuthorizationEndpoint(),
      'token_endpoint' => $this->getTokenEndpoint(),
      'jwks_uri' => $this->getJwksUri(),
      'userinfo_endpoint' => $this->getUserInfoEndpoint(),
    ];
  }

}
