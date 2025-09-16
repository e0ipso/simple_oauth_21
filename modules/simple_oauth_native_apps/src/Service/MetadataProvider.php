<?php

namespace Drupal\simple_oauth_native_apps\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;

/**
 * Service for providing native app OAuth metadata.
 *
 * Implements RFC 8414 OAuth 2.0 Authorization Server Metadata with
 * RFC 8252 native app specific enhancements.
 */
class MetadataProvider {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The URL generator.
   *
   * @var \Drupal\Core\Routing\UrlGeneratorInterface
   */
  protected $urlGenerator;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Constructs a new MetadataProvider.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $url_generator
   *   The URL generator.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   */
  public function __construct(ConfigFactoryInterface $config_factory, UrlGeneratorInterface $url_generator, CacheBackendInterface $cache) {
    $this->configFactory = $config_factory;
    $this->urlGenerator = $url_generator;
    $this->cache = $cache;
  }

  /**
   * Provides OAuth metadata for native applications.
   *
   * @return array
   *   OAuth metadata array following RFC 8414 and RFC 8252.
   */
  public function getMetadata(): array {
    $cid = 'simple_oauth_native_apps:metadata';

    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $metadata = $this->generateMetadata();
    $this->cache->set($cid, $metadata, Cache::PERMANENT, [
      'config:simple_oauth_native_apps.settings',
      'config:simple_oauth.settings',
    ]);

    return $metadata;
  }

  /**
   * Generates the complete OAuth metadata.
   *
   * @return array
   *   Generated metadata array.
   */
  protected function generateMetadata(): array {
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
    // WebView detection capability.
    $metadata['webview_detection_supported'] = TRUE;
    $metadata['webview_detection_policy'] = $config->get('webview_detection', 'warn');

    // Client type detection.
    $metadata['automatic_client_detection'] = TRUE;
    $metadata['client_type_override_supported'] = TRUE;

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
    $metadata['custom_uri_schemes_supported'] = (bool) $config->get('allow_custom_uri_schemes');

    // Loopback interface support (RFC 8252)
    $metadata['loopback_redirects_supported'] = (bool) $config->get('allow_loopback_redirects');

    // Terminal applications support.
    $metadata['terminal_applications_supported'] = (bool) $config->get('allow_loopback_redirects');

    // Private-use URI scheme registration.
    if ($config->get('allow_custom_uri_schemes')) {
      $metadata['private_use_uri_schemes_allowed'] = TRUE;
    }
  }

  /**
   * Clears the metadata cache.
   */
  public function clearMetadataCache(): void {
    $this->cache->invalidateTags([
      'config:simple_oauth_native_apps.settings',
      'config:simple_oauth.settings',
    ]);
  }

}
