<?php

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\simple_oauth\Oauth2ScopeProviderInterface;

/**
 * Service for discovering OAuth 2.0 scopes.
 */
class ScopeDiscoveryService {

  /**
   * The OAuth2 scope provider.
   *
   * @var \Drupal\simple_oauth\Oauth2ScopeProviderInterface
   */
  protected $scopeProvider;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a ScopeDiscoveryService object.
   *
   * @param \Drupal\simple_oauth\Oauth2ScopeProviderInterface $scope_provider
   *   The OAuth2 scope provider.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(Oauth2ScopeProviderInterface $scope_provider, ConfigFactoryInterface $config_factory) {
    $this->scopeProvider = $scope_provider;
    $this->configFactory = $config_factory;
  }

  /**
   * Gets supported scopes.
   *
   * @return array
   *   Array of supported OAuth 2.0 scopes.
   */
  public function getScopesSupported(): array {
    $scopes = [];

    // Add actual OAuth 2.0 scopes from Simple OAuth's scope provider.
    $oauth_scopes = $this->getOauthScopes();
    $scopes = array_merge($scopes, $oauth_scopes);

    // Add OpenID Connect scopes if enabled.
    if ($this->isOpenIdConnectEnabled()) {
      $oidc_scopes = $this->getOpenIdConnectScopes();
      $scopes = array_merge($scopes, $oidc_scopes);
    }

    return array_unique(array_values($scopes));
  }

  /**
   * Gets actual OAuth 2.0 scopes from Simple OAuth's scope provider.
   *
   * @return array
   *   Array of OAuth 2.0 scope names.
   */
  protected function getOauthScopes(): array {
    $scopes = [];

    try {
      // Load all scopes from the configured scope provider (dynamic, static, etc.)
      $oauth_scopes = $this->scopeProvider->loadMultiple();

      foreach ($oauth_scopes as $scope) {
        // Use the actual scope name (e.g., "tutorial:admin", "user:profile")
        $scopes[] = $scope->getName();
      }
    }
    catch (\Exception $e) {
      // If scope loading fails, log it but don't break the metadata response.
      \Drupal::logger('simple_oauth_server_metadata')->warning('Failed to load OAuth scopes: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $scopes;
  }

  /**
   * Gets OpenID Connect scopes.
   *
   * @return array
   *   Array of OpenID Connect scopes.
   */
  protected function getOpenIdConnectScopes(): array {
    return [
      'openid',
      'profile',
      'email',
      'phone',
      'address',
    ];
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
