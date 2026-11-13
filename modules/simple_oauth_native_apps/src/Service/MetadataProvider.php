<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_native_apps\Service;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\NullLogger;

/**
 * Service for describing global native app OAuth capabilities.
 *
 * Implements RFC 8414 OAuth 2.0 Authorization Server Metadata with
 * RFC 8252 native app specific enhancements. These service outputs do not
 * register or extend an HTTP discovery endpoint.
 */
final class MetadataProvider implements CacheableDependencyInterface {

  use CacheableDependencyTrait;

  /**
   * The shared native security policy resolver.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\NativeClientDetector
   */
  private readonly NativeClientDetector $policyResolver;

  /**
   * Constructs a new MetadataProvider.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\simple_oauth_native_apps\Service\NativeClientDetector|null $nativeClientDetector
   *   The shared resolver, optional for existing constructor callers.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    ?NativeClientDetector $nativeClientDetector = NULL,
  ) {
    $this->policyResolver = $nativeClientDetector ?? new NativeClientDetector($configFactory, new NullLogger());
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
    // Rebuild for each generation so temporary overrides cannot accumulate.
    $cacheability = CacheableMetadata::createFromObject($config);
    $cacheability->addCacheTags(['config:simple_oauth.settings']);
    $this->cacheTags = $cacheability->getCacheTags();
    $this->cacheContexts = $cacheability->getCacheContexts();
    $this->cacheMaxAge = $cacheability->getCacheMaxAge();
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

    // Auto-detect enables enhanced PKCE for native clients.
    $enhanced_pkce = $this->policyResolver->getEffectivePolicy()['enhanced_pkce'];
    $enforced_method = $this->policyResolver->getEnforcedPkceMethod();
    $metadata['native_apps_pkce_required'] = $enhanced_pkce;
    $metadata['pkce_required'] = $enhanced_pkce;
    $metadata['pkce_s256_enforced'] = $enhanced_pkce && $enforced_method === 'S256';
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
    $metadata['native_apps_supported'] = (bool) ($config->get('enforce_native_security') ?? FALSE);

    // WebView detection capability - based on detection mode.
    $detection_mode = $this->policyResolver->getEffectivePolicy()['webview'];
    $metadata['webview_detection_supported'] = $detection_mode !== 'off';
    $metadata['webview_detection_policy'] = $detection_mode;

    // Client type detection - only if native security is enabled.
    $metadata['automatic_client_detection'] = (bool) ($config->get('enforce_native_security') ?? FALSE);
    $metadata['client_type_override_supported'] = (bool) ($config->get('enforce_native_security') ?? FALSE);

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
    $policy = $this->policyResolver->getEffectivePolicy();

    // Custom URI schemes support.
    $metadata['custom_uri_schemes_supported'] = $policy['custom_schemes'];

    // Loopback interface support (RFC 8252)
    $metadata['loopback_redirects_supported'] = $policy['loopback'];

    // Terminal applications support.
    $metadata['terminal_applications_supported'] = $policy['loopback'];

    // Private-use URI scheme registration.
    $metadata['private_use_uri_schemes_allowed'] = $metadata['custom_uri_schemes_supported'];
  }

}
