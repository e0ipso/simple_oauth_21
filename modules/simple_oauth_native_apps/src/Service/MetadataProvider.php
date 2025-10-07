<?php

namespace Drupal\simple_oauth_native_apps\Service;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;

/**
 * Service for providing native app OAuth metadata.
 *
 * Implements RFC 8414 OAuth 2.0 Authorization Server Metadata with
 * RFC 8252 native app specific enhancements.
 */
class MetadataProvider implements CacheableDependencyInterface {

  use CacheableDependencyTrait;

  /**
   * Constructs a new MetadataProvider.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $urlGenerator
   *   The URL generator.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UrlGeneratorInterface $urlGenerator,
  ) {
    $this->cacheTags = [
      'config:simple_oauth_native_apps.settings',
      'config:simple_oauth.settings',
    ];
  }

  /**
   * Provides OAuth metadata for native applications.
   *
   * @return array
   *   OAuth metadata array following RFC 8414 and RFC 8252.
   */
  public function getMetadata(): array {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');
    $metadata = [];

    // Add RFC 8252 compliance fields.
    $this->addRfc8252Metadata($metadata, $config);

    // Add PKCE support information.
    $this->addPkceMetadata($metadata, $config);

    // Add native app capability advertisement.
    $this->addNativeAppCapabilities($metadata, $config);

    // Add redirect URI support information.
    $this->addRedirectUriSupport($metadata, $config);

    return $metadata;
  }

  /**
   * Adds RFC 8252 compliance metadata.
   *
   * @param array $metadata
   *   Metadata array to populate.
   * @param \Drupal\Core\Config\Config $config
   *   Module configuration.
   */
  protected function addRfc8252Metadata(array &$metadata, $config): void {
    // RFC 8252 Section 8.4 - Request URI parameter not supported.
    $metadata['request_uri_parameter_supported'] = FALSE;

    // RFC 8252 Section 8.4 - Request URI registration not required.
    $metadata['require_request_uri_registration'] = FALSE;

    // Native app support advertisement.
    $metadata['native_app_support'] = TRUE;

    // Enhanced security indication.
    $metadata['enhanced_native_security'] = (bool) $config->get('enforce_native_security');
  }

  /**
   * Adds PKCE metadata information.
   *
   * @param array $metadata
   *   Metadata array to populate.
   * @param \Drupal\Core\Config\Config $config
   *   Module configuration.
   */
  protected function addPkceMetadata(array &$metadata, $config): void {
    // RFC 7636 PKCE methods supported.
    $pkce_methods = ['S256'];

    // Check if plain method is allowed (not recommended for production)
    if ($config->get('allow_plain_pkce')) {
      $pkce_methods[] = 'plain';
    }

    $metadata['code_challenge_methods_supported'] = $pkce_methods;

    // Enhanced PKCE for native apps.
    if ($config->get('enforce_native_security')) {
      $metadata['native_apps_pkce_required'] = TRUE;
      $metadata['pkce_required'] = TRUE;
      $metadata['pkce_s256_enforced'] = TRUE;
    }
  }

  /**
   * Adds native app capability information.
   *
   * @param array $metadata
   *   Metadata array to populate.
   * @param \Drupal\Core\Config\Config $config
   *   Module configuration.
   */
  protected function addNativeAppCapabilities(array &$metadata, $config): void {
    // Native app support advertisement - only if native security is enabled.
    $metadata['native_apps_supported'] = (bool) $config->get('enforce_native_security', FALSE);

    // WebView detection capability - based on detection mode.
    $detection_mode = $config->get('webview.detection', 'warn');
    $metadata['webview_detection_supported'] = $detection_mode !== 'off';
    $metadata['webview_detection_policy'] = $detection_mode;

    // Client type detection - only if native security is enabled.
    $metadata['automatic_client_detection'] = (bool) $config->get('enforce_native_security', FALSE);
    $metadata['client_type_override_supported'] = (bool) $config->get('enforce_native_security', FALSE);

    // Security enhancements.
    if ($config->get('enforce_native_security')) {
      $metadata['enhanced_redirect_uri_validation'] = TRUE;
      $metadata['strict_native_client_validation'] = TRUE;
    }
  }

  /**
   * Adds redirect URI support information.
   *
   * @param array $metadata
   *   Metadata array to populate.
   * @param \Drupal\Core\Config\Config $config
   *   Module configuration.
   */
  protected function addRedirectUriSupport(array &$metadata, $config): void {
    // Custom URI schemes support.
    $metadata['custom_uri_schemes_supported'] = (bool) $config->get('allow.custom_uri_schemes');

    // Loopback interface support (RFC 8252)
    $metadata['loopback_redirects_supported'] = (bool) $config->get('allow.loopback_redirects');

    // Terminal applications support.
    $metadata['terminal_applications_supported'] = (bool) $config->get('allow.loopback_redirects');

    // Private-use URI scheme registration.
    if ($config->get('allow.custom_uri_schemes')) {
      $metadata['private_use_uri_schemes_allowed'] = TRUE;
    }
  }

}
