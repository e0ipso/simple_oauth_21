<?php

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\simple_oauth\Plugin\Oauth2GrantManager;

/**
 * Service for discovering OAuth 2.0 grant types and response types.
 */
class GrantTypeDiscoveryService {

  /**
   * Constructs a GrantTypeDiscoveryService object.
   *
   * @param \Drupal\simple_oauth\Plugin\Oauth2GrantManager $grantManager
   *   The OAuth2 Grant Manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   */
  public function __construct(
    private readonly Oauth2GrantManager $grantManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Gets supported grant types.
   *
   * @return array
   *   Array of supported grant type strings per RFC 8414.
   */
  public function getGrantTypesSupported(): array {
    $grant_types = [];
    $definitions = $this->grantManager->getDefinitions();

    foreach ($definitions as $plugin_id => $definition) {
      // Try to create the plugin instance to check if it's actually available.
      // The implicit grant plugin self-excludes when use_implicit is FALSE
      // by throwing PluginNotFoundException in its constructor.
      try {
        $this->grantManager->createInstance($plugin_id);
        $grant_types[] = $plugin_id;
      }
      catch (\Exception $e) {
        // Plugin is not available, skip it.
      }
    }

    return $grant_types;
  }

  /**
   * Gets supported response types.
   *
   * @return array
   *   Array of supported response type strings per RFC 8414.
   */
  public function getResponseTypesSupported(): array {
    $response_types = [];
    $grant_types = $this->getGrantTypesSupported();
    $oidc_enabled = !$this->isOpenIdConnectDisabled();

    // Track which response types have been added.
    $has_code = FALSE;

    foreach ($grant_types as $grant_type) {
      switch ($grant_type) {
        case 'authorization_code':
        case 'code':
          // Both 'authorization_code' and 'code' plugins support code response.
          if (!$has_code) {
            $response_types[] = 'code';
            $has_code = TRUE;
          }
          break;

        // Note: The implicit grant has been removed in Simple OAuth 6.x
        // as it's insecure per OAuth 2.0 Security Best Current Practice.
        // The 'token' response type is no longer supported.
        // client_credentials, password, and refresh_token don't add response
        // types as they don't use the authorization endpoint.
      }
    }

    // Add OIDC response types if enabled.
    if ($oidc_enabled) {
      if ($has_code) {
        $response_types[] = 'id_token';
      }
      // 'id_token token' combination is not supported without implicit grant
    }

    return $response_types;
  }

  /**
   * Gets supported response modes.
   *
   * @return array
   *   Array of supported response mode strings per RFC 8414.
   */
  public function getResponseModesSupported(): array {
    $response_modes = [];
    $grant_types = $this->getGrantTypesSupported();

    // Check for authorization code grant which supports different response
    // modes.
    $has_authorization_code = array_intersect(
      ['authorization_code', 'code'],
      $grant_types
    );

    if (!empty($has_authorization_code)) {
      // Standard OAuth 2.0 response modes.
      $response_modes[] = 'query';
      $response_modes[] = 'fragment';

      // Form post response mode is also supported.
      $response_modes[] = 'form_post';
    }

    return $response_modes;
  }

  /**
   * Checks if OpenID Connect is disabled.
   *
   * @return bool
   *   TRUE if OpenID Connect is disabled, FALSE otherwise.
   */
  protected function isOpenIdConnectDisabled(): bool {
    $config = $this->configFactory->get('simple_oauth.settings');
    return (bool) $config->get('disable_openid_connect');
  }

}
