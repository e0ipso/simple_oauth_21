<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\simple_oauth\Oauth2ScopeProviderInterface;
use Drupal\simple_oauth_server_metadata\Trait\MetadataCacheabilityTrait;

/**
 * Service for discovering OAuth 2.0 scopes.
 */
class ScopeDiscoveryService implements CacheableDependencyInterface {

  use MetadataCacheabilityTrait;

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
    $config = $this->configFactory->get('simple_oauth.settings');
    $cacheability = CacheableMetadata::createFromObject($config);
    $provider_id = $config->get('scope_provider') ?? 'dynamic';
    if ($this->scopeProvider instanceof CacheableDependencyInterface) {
      // Custom providers can describe collection changes as well as items.
      $cacheability->addCacheableDependency($this->scopeProvider);
    }
    if ($provider_id === 'dynamic') {
      // The config entity declares this list tag, including empty collections.
      $cacheability->addCacheTags(['oauth2_scope']);
    }
    elseif ($provider_id === 'static') {
      // Static scope definitions change with the installed module collection.
      $cacheability->addCacheTags(['config:core.extension']);
    }
    elseif (!$this->scopeProvider instanceof CacheableDependencyInterface) {
      // Unknown external collections cannot promise tag-based invalidation.
      $cacheability->mergeCacheMaxAge(300);
    }
    $this->generationCacheability = $cacheability;
    $scopes = [];

    // Add actual OAuth 2.0 scopes from Simple OAuth's scope provider.
    $oauth_scopes = $this->getOauthScopes();
    $scopes = array_merge($scopes, $oauth_scopes);

    // Add OpenID Connect scopes if enabled.
    if ($this->isOpenIdConnectEnabled()) {
      $oidc_scopes = $this->getOpenIdConnectScopes();
      $scopes = array_merge($scopes, $oidc_scopes);
    }

    return array_values(array_unique($scopes));
  }

  /**
   * Gets actual OAuth 2.0 scopes from Simple OAuth's scope provider.
   *
   * @return array
   *   Array of OAuth 2.0 scope names.
   */
  protected function getOauthScopes(): array {
    $this->generationCacheability ??= new CacheableMetadata();
    $scopes = [];

    try {
      // Load all scopes from the configured scope provider.
      $oauth_scopes = $this->scopeProvider->loadMultiple();

      foreach ($oauth_scopes as $scope) {
        // Use the actual scope name (e.g., "tutorial:admin", "user:profile")
        $scopes[] = $scope->getName();
        if ($scope instanceof CacheableDependencyInterface) {
          $this->generationCacheability->addCacheableDependency($scope);
        }
      }
    }
    catch (\Exception $e) {
      $this->generationCacheability->setCacheMaxAge(0);
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
