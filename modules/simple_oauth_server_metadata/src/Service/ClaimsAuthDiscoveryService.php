<?php

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for discovering claims, authentication methods, and algorithms.
 */
class ClaimsAuthDiscoveryService {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a ClaimsAuthDiscoveryService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Gets supported claims.
   *
   * @return array
   *   An array of supported claims.
   */
  public function getClaimsSupported(): array {
    $claims = [];

    if ($this->isOpenIdConnectEnabled()) {
      // Standard OpenID Connect claims.
      $claims = [
        'sub',
        'iss',
        'aud',
        'exp',
        'iat',
        'auth_time',
        'name',
        'given_name',
        'family_name',
        'preferred_username',
        'email',
        'email_verified',
      ];
    }

    return $claims;
  }

  /**
   * Gets supported token endpoint authentication methods.
   *
   * @return array
   *   An array of supported authentication methods.
   */
  public function getTokenEndpointAuthMethodsSupported(): array {
    // Simple OAuth supports these standard methods.
    return [
      'client_secret_post',
      'client_secret_basic',
    ];
  }

  /**
   * Gets supported token endpoint authentication signing algorithms.
   *
   * @return array
   *   An array of supported signing algorithms.
   */
  public function getTokenEndpointAuthSigningAlgValuesSupported(): array {
    // Based on Simple OAuth's RSA key configuration.
    return ['RS256'];
  }

  /**
   * Gets supported ID token signing algorithms.
   *
   * @return array
   *   An array of supported ID token signing algorithms.
   */
  public function getIdTokenSigningAlgValuesSupported(): array {
    // Simple OAuth uses RS256 for JWT signing.
    return ['RS256'];
  }

  /**
   * Gets supported subject types.
   *
   * @return array
   *   An array of supported subject types.
   */
  public function getSubjectTypesSupported(): array {
    // Simple OAuth implements public subject identifier type.
    return ['public'];
  }

  /**
   * Gets supported code challenge methods for PKCE.
   *
   * @return array
   *   An array of supported code challenge methods.
   */
  public function getCodeChallengeMethodsSupported(): array {
    // Simple OAuth supports PKCE with these methods.
    return ['S256', 'plain'];
  }

  /**
   * Checks if request URI parameter is supported.
   *
   * @return bool
   *   TRUE if request URI parameter is supported, FALSE otherwise.
   */
  public function getRequestUriParameterSupported(): bool {
    // Simple OAuth does not currently support request URI parameter.
    // This feature allows clients to pass request parameters by reference.
    return FALSE;
  }

  /**
   * Checks if request URI registration is required.
   *
   * @return bool
   *   TRUE if request URI registration is required, FALSE otherwise.
   */
  public function getRequireRequestUriRegistration(): bool {
    // Since request URI parameter is not supported, registration is not
    // required.
    return FALSE;
  }

  /**
   * Checks if OpenID Connect is enabled.
   *
   * @return bool
   *   TRUE if OpenID Connect is enabled, FALSE otherwise.
   */
  protected function isOpenIdConnectEnabled(): bool {
    $config = $this->configFactory->get('simple_oauth.settings');
    return !(bool) $config->get('disable_openid_connect');
  }

}
