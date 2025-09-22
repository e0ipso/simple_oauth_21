<?php

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\simple_oauth\Oauth2ScopeProviderInterface;

/**
 * Service for discovering OAuth 2.0 scopes.
 */
class ScopeDiscoveryService {

  /**
   * Constructs a ScopeDiscoveryService object.
   *
   * @param \Drupal\simple_oauth\Oauth2ScopeProviderInterface $scopeProvider
   *   The OAuth2 scope provider.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    private readonly Oauth2ScopeProviderInterface $scopeProvider,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

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
      // Load all scopes from the configured scope provider.
      $oauth_scopes = $this->scopeProvider->loadMultiple();

      foreach ($oauth_scopes as $scope) {
        // Use the actual scope name (e.g., "tutorial:admin", "user:profile")
        $scopes[] = $scope->getName();
      }
    }
    catch (\Exception $e) {
      // If scope loading fails, log it but don't break the metadata response.
      $logger = $this->loggerFactory->get('simple_oauth_server_metadata');
      $logger->warning('Failed to load OAuth scopes: @message', [
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
