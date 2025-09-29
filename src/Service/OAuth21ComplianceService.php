<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_21\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Service for OAuth 2.1 compliance detection and assessment.
 *
 * This service automatically detects configuration status across all Simple
 * OAuth modules and provides comprehensive compliance assessment without
 * direct database queries, using Drupal's configuration system and module
 * handler service. Adapted for the simple_oauth_21 umbrella module structure.
 *
 * @package Drupal\simple_oauth_21\Service
 */
final class OAuth21ComplianceService {

  /**
   * Mapping of submodule names to their potential installation paths.
   */
  private const SUBMODULE_MAPPING = [
    'simple_oauth_pkce' => [
      'submodule' => 'simple_oauth_21_pkce',
      'contrib' => 'simple_oauth_pkce',
      'path' => 'modules/simple_oauth_pkce',
    ],
    'simple_oauth_server_metadata' => [
      'submodule' => 'simple_oauth_21_server_metadata',
      'contrib' => 'simple_oauth_server_metadata',
      'path' => 'modules/simple_oauth_server_metadata',
    ],
    'simple_oauth_native_apps' => [
      'submodule' => 'simple_oauth_21_native_apps',
      'contrib' => 'simple_oauth_native_apps',
      'path' => 'modules/simple_oauth_native_apps',
    ],
    'simple_oauth_device_flow' => [
      'submodule' => 'simple_oauth_21_device_flow',
      'contrib' => 'simple_oauth_device_flow',
      'path' => 'modules/simple_oauth_device_flow',
    ],
    'simple_oauth_client_registration' => [
      'submodule' => 'simple_oauth_21_client_registration',
      'contrib' => 'simple_oauth_client_registration',
      'path' => 'modules/simple_oauth_client_registration',
    ],
  ];

  /**
   * Constructs an OAuth21ComplianceService object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $extensionListModule
   *   The extension list module service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend service.
   * @param \Drupal\Core\Routing\RouteProviderInterface $routeProvider
   *   The route provider service.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleExtensionList $extensionListModule,
    private readonly LoggerInterface $logger,
    private readonly CacheBackendInterface $cache,
    private readonly RouteProviderInterface $routeProvider,
  ) {}

  /**
   * Gets comprehensive OAuth 2.1 compliance status.
   *
   * @return array
   *   Complete compliance assessment with categorized requirements.
   *
   * @throws \Exception
   *   When critical service dependencies are unavailable.
   */
  public function getComplianceStatus(): array {
    try {
      $core_requirements = $this->checkCoreRequirements();
      $server_metadata = $this->checkServerMetadata();
      $best_practices = $this->checkBestPractices();

      return [
        'core_requirements' => $core_requirements,
        'server_metadata' => $server_metadata,
        'best_practices' => $best_practices,
        'overall_status' => $this->calculateOverallStatus($core_requirements, $server_metadata, $best_practices),
        'summary' => $this->generateComplianceSummary($core_requirements, $server_metadata, $best_practices),
        'service_health' => $this->getServiceHealth(),
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get compliance status: @error', [
        '@error' => $e->getMessage(),
      ]);

      // Return a safe fallback status.
      return $this->getFailsafeComplianceStatus($e);
    }
  }

  /**
   * Checks OAuth 2.1 core requirements (MANDATORY).
   *
   * @return array
   *   Array of core requirement compliance status.
   */
  public function checkCoreRequirements(): array {
    $requirements = [];

    // PKCE Module Status - Enhanced submodule detection.
    $pkce_enabled = $this->isModuleEnabledWithFallback('simple_oauth_pkce');
    $requirements['pkce_module'] = [
      'status' => $pkce_enabled ? 'compliant' : 'non_compliant',
      'title' => 'PKCE Module Enabled',
      'description' => 'OAuth 2.1 requires PKCE for all authorization code flows (<a href="https://datatracker.ietf.org/doc/draft-ietf-oauth-v2-1/" target="_blank">OAuth 2.1 Draft Section 4.1</a>, <a href="https://datatracker.ietf.org/doc/html/rfc7636" target="_blank">RFC 7636</a>)',
      'message' => $pkce_enabled
        ? 'PKCE module is enabled and available'
        : 'PKCE module must be installed and enabled for OAuth 2.1 compliance. Install simple_oauth_21_pkce submodule or standalone simple_oauth_pkce',
      'level' => 'mandatory',
    ];

    if ($pkce_enabled) {
      // PKCE Enforcement Level.
      $pkce_config = $this->getModuleConfig('simple_oauth_pkce.settings');
      $enforcement = $pkce_config?->get('enforcement') ?? 'optional';

      $requirements['pkce_enforcement'] = [
        'status' => $enforcement === 'mandatory' ? 'compliant' : 'non_compliant',
        'title' => 'PKCE Mandatory Enforcement',
        'description' => 'PKCE must be required for all authorization code flows (<a href="https://datatracker.ietf.org/doc/draft-ietf-oauth-v2-1/" target="_blank">OAuth 2.1 Draft Section 4.1</a>)',
        'message' => match($enforcement) {
          'mandatory' => 'PKCE is correctly set to mandatory',
          'optional' => 'PKCE should be set to mandatory for OAuth 2.1 compliance',
          'disabled' => 'PKCE cannot be disabled for OAuth 2.1 compliance',
          default => 'PKCE enforcement level is not properly configured',
        },
        'level' => 'mandatory',
      ];

      // S256 Challenge Method.
      $s256_enabled = (bool) ($pkce_config?->get('s256_enabled') ?? FALSE);
      $requirements['pkce_s256'] = [
        'status' => $s256_enabled ? 'compliant' : 'non_compliant',
        'title' => 'S256 Challenge Method Enabled',
        'description' => 'OAuth 2.1 requires support for SHA256 challenge method (<a href="https://datatracker.ietf.org/doc/html/rfc7636#section-4.2" target="_blank">RFC 7636 Section 4.2</a>)',
        'message' => $s256_enabled
          ? 'S256 challenge method is enabled'
          : 'S256 challenge method must be enabled for OAuth 2.1 compliance',
        'level' => 'mandatory',
      ];

      // Plain Method Status (should be disabled for security)
      $plain_enabled = (bool) ($pkce_config?->get('plain_enabled') ?? FALSE);
      $requirements['pkce_plain_disabled'] = [
        'status' => $plain_enabled ? 'warning' : 'compliant',
        'title' => 'Plain Challenge Method Disabled',
        'description' => 'Plain text challenge method should be disabled for security (<a href="https://datatracker.ietf.org/doc/html/rfc7636#section-7.2" target="_blank">RFC 7636 Section 7.2</a>)',
        'message' => $plain_enabled
          ? 'Plain challenge method should be disabled unless legacy client support is required'
          : 'Plain challenge method is properly disabled',
        'level' => 'recommended',
      ];
    }

    // Implicit Grant Disabled.
    $simple_oauth_config = $this->getModuleConfig('simple_oauth.settings');
    $implicit_disabled = !(bool) ($simple_oauth_config?->get('use_implicit') ?? FALSE);

    $requirements['implicit_grant_disabled'] = [
      'status' => $implicit_disabled ? 'compliant' : 'non_compliant',
      'title' => 'Implicit Grant Disabled',
      'description' => 'OAuth 2.1 deprecates the implicit grant flow (<a href="https://datatracker.ietf.org/doc/draft-ietf-oauth-v2-1/#section-2.1.2" target="_blank">OAuth 2.1 Draft Section 2.1.2</a>)',
      'message' => $implicit_disabled
        ? 'Implicit grant is properly disabled'
        : 'Implicit grant should be disabled for OAuth 2.1 compliance',
      'level' => 'mandatory',
    ];

    // Native Apps Requirements (OAuth 2.1 + RFC 8252).
    if ($this->isModuleEnabledWithFallback('simple_oauth_native_apps')) {
      $native_config = $this->getModuleConfig('simple_oauth_native_apps.settings');

      // Custom URI Schemes Support (RFC 8252 requirement).
      $custom_schemes_setting = $native_config?->get('allow.custom_uri_schemes');
      // Consider it allowed if set to 'native' or 'auto-detect', not allowed
      // if 'web'.
      $allow_custom_schemes = in_array($custom_schemes_setting, ['native', 'auto-detect'], TRUE);
      $requirements['custom_uri_schemes'] = [
        'status' => $allow_custom_schemes ? 'compliant' : 'non_compliant',
        'title' => 'Custom URI Schemes for Native Apps',
        'description' => 'RFC 8252 requires support for custom URI schemes for native app redirects (<a href="https://datatracker.ietf.org/doc/html/rfc8252#section-7.1" target="_blank">RFC 8252 Section 7.1</a>)',
        'message' => $allow_custom_schemes
          ? 'Custom URI schemes are allowed for native apps'
          : 'Custom URI schemes must be allowed for native app OAuth flows (RFC 8252)',
        'level' => 'mandatory',
      ];

      // Loopback Redirects Support (RFC 8252 requirement).
      $loopback_setting = $native_config?->get('allow.loopback_redirects');
      // Consider it allowed if set to 'native' or 'auto-detect', not allowed
      // if 'web'.
      $allow_loopback = in_array($loopback_setting, ['native', 'auto-detect'], TRUE);
      $requirements['loopback_redirects'] = [
        'status' => $allow_loopback ? 'compliant' : 'non_compliant',
        'title' => 'Loopback Redirects for Native Apps',
        'description' => 'RFC 8252 requires support for loopback redirects for native app development (<a href="https://datatracker.ietf.org/doc/html/rfc8252#section-7.3" target="_blank">RFC 8252 Section 7.3</a>)',
        'message' => $allow_loopback
          ? 'Loopback redirects are allowed for native apps'
          : 'Loopback redirects must be allowed for native app development (RFC 8252)',
        'level' => 'mandatory',
      ];

      // Exact Redirect URI Matching (OAuth 2.1 requirement).
      $exact_redirect = (bool) ($native_config?->get('require_exact_redirect_match') ?? FALSE);
      $requirements['exact_redirect_uri_matching'] = [
        'status' => $exact_redirect ? 'compliant' : 'non_compliant',
        'title' => 'Exact Redirect URI Matching',
        'description' => 'OAuth 2.1 requires exact string matching for redirect URIs (<a href="https://datatracker.ietf.org/doc/draft-ietf-oauth-v2-1/#section-4.1.3" target="_blank">OAuth 2.1 Draft Section 4.1.3</a>)',
        'message' => $exact_redirect
          ? 'Exact redirect URI matching is enforced'
          : 'OAuth 2.1 requires exact redirect URI matching - partial matches must be disabled',
        'level' => 'mandatory',
      ];
    }

    // Redirect URI Fragment Validation.
    $requirements['redirect_uri_fragments'] = [
      'status' => 'compliant',
      'title' => 'Redirect URI Fragment Validation',
      'description' => 'OAuth 2.1 prohibits fragment components (#) in redirect URIs (<a href="https://datatracker.ietf.org/doc/draft-ietf-oauth-v2-1/#section-4.1.3" target="_blank">OAuth 2.1 Draft Section 4.1.3</a>)',
      'message' => 'Redirect URI fragment validation is implemented and enforced',
      'level' => 'mandatory',
    ];

    // HTTPS Enforcement Check.
    $https_status = $this->checkHttpsEnforcement();
    $requirements['https_enforcement'] = [
      'status' => $https_status['compliant'] ? 'compliant' : 'non_compliant',
      'title' => 'HTTPS Enforcement',
      'description' => 'OAuth 2.1 requires HTTPS for all endpoints except loopback interfaces (<a href="https://datatracker.ietf.org/doc/draft-ietf-oauth-v2-1/#section-3.1" target="_blank">OAuth 2.1 Draft Section 3.1</a>, <a href="https://datatracker.ietf.org/doc/html/rfc8252#section-8.1" target="_blank">RFC 8252 Section 8.1</a>)',
      'message' => $https_status['message'],
      'level' => 'mandatory',
    ];

    return $requirements;
  }

  /**
   * Checks OAuth 2.1 server metadata compliance (REQUIRED).
   *
   * @return array
   *   Array of server metadata requirement compliance status.
   */
  public function checkServerMetadata(): array {
    $requirements = [];

    // Server Metadata Module Status - Enhanced submodule detection.
    $metadata_enabled = $this->isModuleEnabledWithFallback('simple_oauth_server_metadata');
    $requirements['metadata_module'] = [
      'status' => $metadata_enabled ? 'compliant' : 'non_compliant',
      'title' => 'Server Metadata Module Enabled',
      'description' => 'RFC 8414 server metadata endpoint provides OAuth 2.1 discoverability (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-3" target="_blank">RFC 8414 Section 3</a>)',
      'message' => $metadata_enabled
        ? 'Server metadata module is enabled'
        : 'Server metadata module should be enabled for OAuth 2.1 compliance. Enable simple_oauth_21_server_metadata submodule or standalone simple_oauth_server_metadata',
      'level' => 'required',
    ];

    if ($metadata_enabled) {
      // Metadata Endpoint Availability.
      try {
        $endpoint_url = Url::fromRoute('simple_oauth_server_metadata.well_known')->setAbsolute()->toString();
        $requirements['metadata_endpoint'] = [
          'status' => 'compliant',
          'title' => 'Metadata Endpoint Active',
          'description' => 'OAuth server metadata endpoint is accessible (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-3" target="_blank">RFC 8414 Section 3</a>)',
          'message' => 'Server metadata endpoint is active at /.well-known/oauth-authorization-server',
          'level' => 'required',
          'endpoint_url' => $endpoint_url,
        ];
      }
      catch (\Exception $e) {
        $requirements['metadata_endpoint'] = [
          'status' => 'non_compliant',
          'title' => 'Metadata Endpoint Active',
          'description' => 'OAuth server metadata endpoint should be accessible (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-3" target="_blank">RFC 8414 Section 3</a>)',
          'message' => 'Server metadata endpoint is not properly configured',
          'level' => 'required',
        ];
      }

      // PKCE Methods Advertised.
      $pkce_enabled = $this->isModuleEnabledWithFallback('simple_oauth_pkce');
      if ($pkce_enabled) {
        $pkce_config = $this->getModuleConfig('simple_oauth_pkce.settings');
        $s256_enabled = (bool) ($pkce_config?->get('s256_enabled') ?? FALSE);
        $plain_enabled = (bool) ($pkce_config?->get('plain_enabled') ?? FALSE);

        $methods_available = $s256_enabled || $plain_enabled;
        $requirements['pkce_methods_advertised'] = [
          'status' => $methods_available ? 'compliant' : 'non_compliant',
          'title' => 'PKCE Methods Advertised',
          'description' => 'Server metadata should advertise supported PKCE challenge methods (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-2" target="_blank">RFC 8414 Section 2</a>)',
          'message' => $methods_available
            ? 'PKCE challenge methods are advertised in server metadata'
            : 'No PKCE challenge methods are available to advertise',
          'level' => 'required',
        ];
      }

    }

    return $requirements;
  }

  /**
   * Checks OAuth 2.1 security best practices (RECOMMENDED).
   *
   * @return array
   *   Array of best practice compliance status.
   */
  public function checkBestPractices(): array {
    $requirements = [];

    // Native Apps Module Status - Enhanced submodule detection.
    $native_apps_enabled = $this->isModuleEnabledWithFallback('simple_oauth_native_apps');
    $requirements['native_apps_module'] = [
      'status' => $native_apps_enabled ? 'compliant' : 'recommended',
      'title' => 'Native Apps Security Module',
      'description' => 'Enhanced security for native OAuth clients per RFC 8252 (<a href="https://datatracker.ietf.org/doc/html/rfc8252#section-8" target="_blank">RFC 8252 Section 8</a>)',
      'message' => $native_apps_enabled
        ? 'Native apps security module is enabled'
        : 'Consider enabling native apps module for enhanced mobile security. Enable simple_oauth_21_native_apps submodule or standalone simple_oauth_native_apps',
      'level' => 'recommended',
    ];

    if ($native_apps_enabled) {
      $native_config = $this->getModuleConfig('simple_oauth_native_apps.settings');

      // WebView Detection Policy (RFC 8252 recommended for native apps).
      $webview_detection = $native_config?->get('webview.detection') ?? 'off';
      $requirements['webview_detection_policy'] = [
        'status' => $webview_detection !== 'off' ? 'compliant' : 'recommended',
        'title' => 'WebView Detection Policy',
        'description' => 'RFC 8252 recommends detecting and blocking embedded WebViews for native apps (<a href="https://datatracker.ietf.org/doc/html/rfc8252#section-8.12" target="_blank">RFC 8252 Section 8.12</a>)',
        'message' => match($webview_detection) {
          'block' => 'WebView clients are blocked for enhanced security',
          'warn' => 'WebView clients receive security warnings',
          'off' => 'Consider enabling WebView detection to discourage embedded WebView usage',
          default => 'WebView detection configuration should be reviewed',
        },
        'level' => 'recommended',
      ];

      // Native Security Enforcement.
      $native_security = (bool) ($native_config?->get('enforce_native_security') ?? FALSE);
      $requirements['native_security'] = [
        'status' => $native_security ? 'compliant' : 'recommended',
        'title' => 'Native Client Security Enforcement',
        'description' => 'Enhanced security checks for native OAuth clients (<a href="https://datatracker.ietf.org/doc/html/rfc8252#section-8" target="_blank">RFC 8252 Section 8</a>)',
        'message' => $native_security
          ? 'Native client security enforcement is enabled'
          : 'Consider enabling native security enforcement',
        'level' => 'recommended',
      ];

      // Enhanced PKCE for Native Apps.
      $enhanced_pkce_setting = $native_config?->get('native.enhanced_pkce');
      // Consider it enabled if the setting is 'enhanced'.
      $enhanced_pkce = ($enhanced_pkce_setting === 'enhanced');

      $requirements['enhanced_pkce_native'] = [
        'status' => $enhanced_pkce ? 'compliant' : 'recommended',
        'title' => 'Enhanced PKCE for Native Apps',
        'description' => 'Additional PKCE validation and entropy requirements for native applications (<a href="https://datatracker.ietf.org/doc/html/rfc8252#section-8.1" target="_blank">RFC 8252 Section 8.1</a>)',
        'message' => $enhanced_pkce
          ? 'Enhanced PKCE for native apps is enabled'
          : 'Consider enabling enhanced PKCE validation for stronger native app security',
        'level' => 'recommended',
      ];
    }

    // OAuth 2.1 Recommended Server Metadata Endpoints.
    if ($this->isModuleEnabledWithFallback('simple_oauth_server_metadata')) {
      $metadata_config = $this->getModuleConfig('simple_oauth_server_metadata.settings');
      if ($metadata_config) {
        // Token Revocation Endpoint (RFC 7009 - OAuth 2.1 recommended).
        $revocation_endpoint = trim($metadata_config->get('revocation_endpoint') ?? '');
        $requirements['revocation_endpoint'] = [
          'status' => !empty($revocation_endpoint) ? 'compliant' : 'recommended',
          'title' => 'Token Revocation Endpoint',
          'description' => 'OAuth 2.1 recommends providing a token revocation endpoint (<a href="https://datatracker.ietf.org/doc/html/rfc7009#section-2" target="_blank">RFC 7009 Section 2</a>)',
          'message' => !empty($revocation_endpoint)
            ? 'Token revocation endpoint is configured'
            : 'Consider configuring a token revocation endpoint for better security',
          'level' => 'recommended',
        ];

        // Token Introspection Endpoint (RFC 7662 - OAuth 2.1 recommended).
        $introspection_endpoint = trim($metadata_config->get('introspection_endpoint') ?? '');
        $requirements['introspection_endpoint'] = [
          'status' => !empty($introspection_endpoint) ? 'compliant' : 'recommended',
          'title' => 'Token Introspection Endpoint',
          'description' => 'OAuth 2.1 recommends providing a token introspection endpoint (<a href="https://datatracker.ietf.org/doc/html/rfc7662#section-2" target="_blank">RFC 7662 Section 2</a>)',
          'message' => !empty($introspection_endpoint)
            ? 'Token introspection endpoint is configured'
            : 'Consider configuring a token introspection endpoint for enhanced token validation',
          'level' => 'recommended',
        ];

        // Registration Endpoint (RFC 7591 - OAuth 2.1 recommended).
        $registration_endpoint = trim($metadata_config->get('registration_endpoint') ?? '');
        $requirements['registration_endpoint'] = [
          'status' => !empty($registration_endpoint) ? 'compliant' : 'recommended',
          'title' => 'Client Registration Endpoint',
          'description' => 'OAuth 2.1 recommends providing a dynamic client registration endpoint (<a href="https://datatracker.ietf.org/doc/html/rfc7591#section-3" target="_blank">RFC 7591 Section 3</a>)',
          'message' => !empty($registration_endpoint)
            ? 'Client registration endpoint is configured'
            : 'Consider configuring a client registration endpoint for dynamic client registration',
          'level' => 'recommended',
        ];

        // Service Documentation (RFC 8414 - OAuth 2.1 recommended).
        $service_documentation = trim($metadata_config->get('service_documentation') ?? '');
        $requirements['service_documentation'] = [
          'status' => !empty($service_documentation) ? 'compliant' : 'recommended',
          'title' => 'Service Documentation URL',
          'description' => 'OAuth 2.1 recommends providing service documentation URL for better client integration (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-2" target="_blank">RFC 8414 Section 2</a>)',
          'message' => !empty($service_documentation)
            ? 'Service documentation URL is configured'
            : 'Consider configuring a service documentation URL to help developers integrate with your OAuth server',
          'level' => 'recommended',
        ];

        // Operator Policy URI (RFC 8414 - OAuth 2.1 recommended).
        $op_policy_uri = trim($metadata_config->get('op_policy_uri') ?? '');
        $requirements['op_policy_uri'] = [
          'status' => !empty($op_policy_uri) ? 'compliant' : 'recommended',
          'title' => 'Operator Policy URI',
          'description' => 'OAuth 2.1 recommends providing operator policy URI for transparency (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-2" target="_blank">RFC 8414 Section 2</a>)',
          'message' => !empty($op_policy_uri)
            ? 'Operator policy URI is configured'
            : 'Consider configuring an operator policy URI to inform clients about your privacy and data handling policies',
          'level' => 'recommended',
        ];

        // Operator Terms of Service URI (RFC 8414 - OAuth 2.1 recommended).
        $op_tos_uri = trim($metadata_config->get('op_tos_uri') ?? '');
        $requirements['op_tos_uri'] = [
          'status' => !empty($op_tos_uri) ? 'compliant' : 'recommended',
          'title' => 'Operator Terms of Service URI',
          'description' => 'OAuth 2.1 recommends providing terms of service URI for legal clarity (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-2" target="_blank">RFC 8414 Section 2</a>)',
          'message' => !empty($op_tos_uri)
            ? 'Terms of service URI is configured'
            : 'Consider configuring a terms of service URI to provide legal terms for your OAuth service',
          'level' => 'recommended',
        ];

        // UI Locales Supported (RFC 8414 - OAuth 2.1 recommended).
        $ui_locales_supported = $metadata_config->get('ui_locales_supported') ?? [];
        $requirements['ui_locales_supported'] = [
          'status' => !empty($ui_locales_supported) ? 'compliant' : 'recommended',
          'title' => 'Supported UI Locales',
          'description' => 'OAuth 2.1 recommends advertising supported UI locales for better user experience (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-2" target="_blank">RFC 8414 Section 2</a>)',
          'message' => !empty($ui_locales_supported)
            ? 'UI locales are configured (' . count($ui_locales_supported) . ' locales)'
            : 'Consider configuring supported UI locales to help clients provide localized authorization flows',
          'level' => 'recommended',
        ];

        // Additional Claims Supported (RFC 8414 - OAuth 2.1 recommended).
        $additional_claims_supported = $metadata_config->get('additional_claims_supported') ?? [];
        $requirements['additional_claims_supported'] = [
          'status' => !empty($additional_claims_supported) ? 'compliant' : 'recommended',
          'title' => 'Additional Claims Supported',
          'description' => 'OAuth 2.1 recommends advertising additional claims for better client integration (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-2" target="_blank">RFC 8414 Section 2</a>)',
          'message' => !empty($additional_claims_supported)
            ? 'Additional claims are configured (' . count($additional_claims_supported) . ' claims)'
            : 'Consider configuring additional claims to help clients understand available user data',
          'level' => 'recommended',
        ];

        // Additional Signing Algorithms (RFC 8414 - OAuth 2.1 recommended).
        $additional_signing_algorithms = $metadata_config->get('additional_signing_algorithms') ?? [];
        $requirements['additional_signing_algorithms'] = [
          'status' => !empty($additional_signing_algorithms) ? 'compliant' : 'recommended',
          'title' => 'Additional Signing Algorithms',
          'description' => 'OAuth 2.1 recommends advertising additional signing algorithms for algorithm flexibility (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-2" target="_blank">RFC 8414 Section 2</a>)',
          'message' => !empty($additional_signing_algorithms)
            ? 'Additional signing algorithms are configured (' . count($additional_signing_algorithms) . ' algorithms)'
            : 'Consider configuring additional signing algorithms beyond RS256 to support diverse client security requirements',
          'level' => 'recommended',
        ];
      }
    }

    // Refresh Token Security.
    $simple_oauth_config = $this->getModuleConfig('simple_oauth.settings');
    if ($simple_oauth_config) {
      $access_token_expiration = (int) ($simple_oauth_config->get('access_token_expiration') ?? 3600);
      $refresh_token_expiration = (int) ($simple_oauth_config->get('refresh_token_expiration') ?? 1209600);

      // 30 days
      $reasonable_expiration = $access_token_expiration <= 3600 && $refresh_token_expiration <= 2592000;
      $requirements['token_expiration'] = [
        'status' => $reasonable_expiration ? 'compliant' : 'recommended',
        'title' => 'Reasonable Token Expiration',
        'description' => 'Short-lived tokens reduce security impact of token theft (<a href="https://datatracker.ietf.org/doc/draft-ietf-oauth-v2-1/#section-4.4.2" target="_blank">OAuth 2.1 Draft Section 4.4.2</a>)',
        'message' => $reasonable_expiration
          ? 'Token expiration times are appropriately configured'
          : 'Consider shorter token expiration times for enhanced security',
        'level' => 'recommended',
      ];
    }

    return $requirements;
  }

  /**
   * Checks if a specific module is enabled.
   *
   * This method supports both submodules (simple_oauth_21_*) and standalone
   * contrib modules (simple_oauth_*).
   *
   * @param string $module
   *   The module name to check.
   *
   * @return bool
   *   TRUE if the module is enabled, FALSE otherwise.
   */
  public function isModuleEnabled(string $module): bool {
    return $this->moduleHandler->moduleExists($module);
  }

  /**
   * Enhanced module detection with submodule support and fallback logic.
   *
   * This method prioritizes submodules over contrib modules and provides
   * intelligent fallback detection for the new hierarchical structure.
   *
   * @param string $base_module_name
   *   The base module name (e.g., 'simple_oauth_pkce').
   *
   * @return bool
   *   TRUE if any variant of the module is enabled.
   */
  public function isModuleEnabledWithFallback(string $base_module_name): bool {
    $mapping = self::SUBMODULE_MAPPING[$base_module_name] ?? [];

    if (empty($mapping)) {
      // For core modules like 'simple_oauth', check directly without warning.
      return $this->moduleHandler->moduleExists($base_module_name);
    }

    // Priority 1: Check submodule variant (simple_oauth_21_*).
    if ($this->moduleHandler->moduleExists($mapping['submodule'])) {
      return TRUE;
    }

    // Priority 2: Check contrib module variant (simple_oauth_*).
    if ($this->moduleHandler->moduleExists($mapping['contrib'])) {
      return TRUE;
    }

    // Priority 3: Check direct base name.
    if ($this->moduleHandler->moduleExists($base_module_name)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Gets the actual enabled module name for a given base module.
   *
   * Returns the specific module name that is actually enabled, following
   * the priority order: submodule -> contrib -> base name.
   *
   * @param string $base_module_name
   *   The base module name to resolve.
   *
   * @return string|null
   *   The actual enabled module name, or NULL if none are enabled.
   */
  public function getEnabledModuleName(string $base_module_name): ?string {
    $mapping = self::SUBMODULE_MAPPING[$base_module_name] ?? [];

    if (empty($mapping)) {
      return $this->moduleHandler->moduleExists($base_module_name) ? $base_module_name : NULL;
    }

    // Check in priority order.
    $candidates = [$mapping['submodule'], $mapping['contrib'], $base_module_name];

    foreach ($candidates as $candidate) {
      if ($this->moduleHandler->moduleExists($candidate)) {
        return $candidate;
      }
    }

    return NULL;
  }

  /**
   * Checks if a submodule is available (installed but not necessarily enabled).
   *
   * This method checks if a submodule exists in the filesystem within the
   * simple_oauth_21 umbrella module structure.
   *
   * @param string $base_module_name
   *   The base module name to check availability for.
   *
   * @return bool
   *   TRUE if the submodule is available in the filesystem.
   */
  public function isSubmoduleAvailable(string $base_module_name): bool {
    $mapping = self::SUBMODULE_MAPPING[$base_module_name] ?? [];

    if (empty($mapping)) {
      return FALSE;
    }

    // Check if the submodule exists in the extension system.
    try {
      return $this->extensionListModule->exists($mapping['submodule']) ||
             $this->extensionListModule->exists($mapping['contrib']) ||
             $this->extensionListModule->exists($base_module_name);
    }
    catch (\Exception $e) {
      $this->logger->error('Error checking submodule availability for @module: @error', [
        '@module' => $base_module_name,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Gets detailed module status information.
   *
   * Provides comprehensive status information about a module including
   * availability, enabled status, and installation type.
   *
   * @param string $base_module_name
   *   The base module name to get status for.
   *
   * @return array
   *   Array with detailed status information.
   */
  public function getModuleStatus(string $base_module_name): array {
    $mapping = self::SUBMODULE_MAPPING[$base_module_name] ?? [];
    $enabled_name = $this->getEnabledModuleName($base_module_name);
    $is_available = $this->isSubmoduleAvailable($base_module_name);

    $status = [
      'base_name' => $base_module_name,
      'enabled' => $enabled_name !== NULL,
      'enabled_name' => $enabled_name,
      'available' => $is_available,
      'submodule_preferred' => !empty($mapping['submodule']),
      'fallback_available' => FALSE,
    ];

    if ($enabled_name && !empty($mapping)) {
      if ($enabled_name === $mapping['contrib']) {
        $status['fallback_available'] = $this->extensionListModule->exists($mapping['submodule']);
      }
    }

    return $status;
  }

  /**
   * Gets configuration for a specific module.
   *
   * @param string $config_name
   *   The configuration name to retrieve.
   *
   * @return \Drupal\Core\Config\Config|null
   *   The configuration object or NULL if not found.
   */
  public function getModuleConfig(string $config_name): ?Config {
    try {
      $config = $this->configFactory->get($config_name);
      // Check if config actually exists by checking if it's new (not saved).
      if ($config->isNew()) {
        return NULL;
      }
      return $config;
    }
    catch (\Exception $e) {
      $this->logger->debug('Configuration not found: @config_name - @error', [
        '@config_name' => $config_name,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Gets configuration for a module with intelligent fallback support.
   *
   * This method attempts to load configuration for submodules with fallback
   * to contrib module configuration names.
   *
   * @param string $base_module_name
   *   The base module name (e.g., 'simple_oauth_pkce').
   * @param string $config_suffix
   *   The configuration suffix (e.g., 'settings').
   *
   * @return \Drupal\Core\Config\Config|null
   *   The configuration object or NULL if not found.
   */
  public function getModuleConfigWithFallback(string $base_module_name, string $config_suffix = 'settings'): ?Config {
    $enabled_name = $this->getEnabledModuleName($base_module_name);

    if (!$enabled_name) {
      return NULL;
    }

    $config_name = $enabled_name . '.' . $config_suffix;
    return $this->getModuleConfig($config_name);
  }

  /**
   * Gets service health information for diagnostic purposes.
   *
   * @return array
   *   Service health status and diagnostic information.
   */
  public function getServiceHealth(): array {
    $health = [
      'status' => 'healthy',
      'issues' => [],
      'warnings' => [],
      'dependencies' => [],
    ];

    // Check core service dependencies.
    try {
      $this->moduleHandler->getModuleList();
      $health['dependencies']['module_handler'] = 'available';
    }
    catch (\Exception $e) {
      $health['dependencies']['module_handler'] = 'unavailable';
      $health['issues'][] = 'Module handler service unavailable';
      $health['status'] = 'degraded';
    }

    try {
      $this->configFactory->get('system.site');
      $health['dependencies']['config_factory'] = 'available';
    }
    catch (\Exception $e) {
      $health['dependencies']['config_factory'] = 'unavailable';
      $health['issues'][] = 'Configuration factory service unavailable';
      $health['status'] = 'degraded';
    }

    try {
      $this->extensionListModule->getList();
      $health['dependencies']['extension_list'] = 'available';
    }
    catch (\Exception $e) {
      $health['dependencies']['extension_list'] = 'unavailable';
      $health['warnings'][] = 'Extension list service unavailable (affects submodule detection)';
    }

    // Check module ecosystem health.
    $ecosystem_issues = $this->checkEcosystemHealth();
    if (!empty($ecosystem_issues)) {
      $health['warnings'] = array_merge($health['warnings'], $ecosystem_issues);
    }

    if (!empty($health['issues'])) {
      $health['status'] = 'unhealthy';
    }
    elseif (!empty($health['warnings'])) {
      $health['status'] = 'healthy_with_warnings';
    }

    return $health;
  }

  /**
   * Checks for ecosystem-level health issues.
   *
   * @return array
   *   Array of ecosystem health warnings.
   */
  private function checkEcosystemHealth(): array {
    $warnings = [];

    // Check for missing core OAuth module.
    if (!$this->moduleHandler->moduleExists('simple_oauth')) {
      $warnings[] = 'Core Simple OAuth module is not enabled';
    }

    // Check for conflicting module installations.
    foreach (self::SUBMODULE_MAPPING as $base_name => $mapping) {
      $submodule_exists = $this->moduleHandler->moduleExists($mapping['submodule']);
      $contrib_exists = $this->moduleHandler->moduleExists($mapping['contrib']);

      if ($submodule_exists && $contrib_exists) {
        $warnings[] = sprintf(
          'Both submodule (%s) and contrib (%s) versions are enabled for %s',
          $mapping['submodule'],
          $mapping['contrib'],
          $base_name
        );
      }
    }

    return $warnings;
  }

  /**
   * Gets comprehensive submodule capability information.
   *
   * Provides detailed capability data for all OAuth 2.1 submodules including
   * use cases, features, endpoints, and installation status.
   *
   * @return array
   *   Array of submodule capabilities with detailed status information.
   */
  public function getSubmoduleCapabilities(): array {
    $cid = 'simple_oauth_21:submodule_capabilities';
    $cached = $this->cache->get($cid);

    if ($cached && $cached->data) {
      return $cached->data;
    }

    $capabilities = [
      'simple_oauth_pkce' => [
        'use_cases' => ['Mobile Apps', 'SPAs', 'Native Applications', 'Public Clients'],
        'features' => ['PKCE Code Challenge', 'S256 Challenge Method', 'Authorization Code Flow Protection'],
        'endpoints' => ['/oauth/authorize', '/oauth/token'],
        'status' => $this->getModuleCapabilityStatus('simple_oauth_pkce'),
        'rfc' => 'RFC 7636',
        'description' => 'Proof Key for Code Exchange (PKCE) adds security to OAuth authorization code flows',
        'priority' => 'mandatory',
      ],
      'simple_oauth_server_metadata' => [
        'use_cases' => ['API Discovery', 'Client Configuration', 'Service Documentation'],
        'features' => ['Server Metadata Endpoint', 'OAuth Discovery', 'Configuration Advertising'],
        'endpoints' => ['/.well-known/oauth-authorization-server'],
        'status' => $this->getModuleCapabilityStatus('simple_oauth_server_metadata'),
        'rfc' => 'RFC 8414',
        'description' => 'Provides OAuth server metadata for automatic client configuration and discovery',
        'priority' => 'required',
      ],
      'simple_oauth_native_apps' => [
        'use_cases' => ['Mobile Apps', 'Desktop Applications', 'CLI Tools'],
        'features' => ['Custom URI Schemes', 'Loopback Redirects', 'WebView Detection', 'Enhanced Security'],
        'endpoints' => ['/oauth/authorize', '/oauth/token'],
        'status' => $this->getModuleCapabilityStatus('simple_oauth_native_apps'),
        'rfc' => 'RFC 8252',
        'description' => 'Enhanced security and usability for OAuth in native applications',
        'priority' => 'recommended',
      ],
      'simple_oauth_device_flow' => [
        'use_cases' => ['Smart TVs', 'IoT Devices', 'Gaming Consoles', 'Input-Constrained Devices'],
        'features' => ['Device Authorization Grant', 'User Code Verification', 'Polling for Tokens'],
        'endpoints' => ['/oauth/device_authorization', '/oauth/device'],
        'status' => $this->getModuleCapabilityStatus('simple_oauth_device_flow'),
        'rfc' => 'RFC 8628',
        'description' => 'OAuth flow for devices with limited input capabilities or no web browser',
        'priority' => 'optional',
      ],
      'simple_oauth_client_registration' => [
        'use_cases' => ['Dynamic Client Creation', 'Automated Onboarding', 'Multi-tenant Applications'],
        'features' => ['Dynamic Client Registration', 'Client Metadata Management', 'Automated Configuration'],
        'endpoints' => ['/oauth/register'],
        'status' => $this->getModuleCapabilityStatus('simple_oauth_client_registration'),
        'rfc' => 'RFC 7591',
        'description' => 'Enables dynamic registration of OAuth clients without manual intervention',
        'priority' => 'optional',
      ],
    ];

    // Cache for 5 minutes with module-specific tags for invalidation.
    $cache_tags = ['config:module_list'];
    foreach (array_keys($capabilities) as $module_name) {
      $cache_tags[] = "config:{$module_name}.settings";
    }

    $this->cache->set($cid, $capabilities, time() + 300, $cache_tags);

    return $capabilities;
  }

  /**
   * Gets business use case to module mapping.
   *
   * Maps common OAuth implementation scenarios to the required modules
   * and provides guidance on module selection.
   *
   * @return array
   *   Array of use cases mapped to required modules with descriptions.
   */
  public function getUseCaseModuleMapping(): array {
    $cid = 'simple_oauth_21:use_case_mapping';
    $cached = $this->cache->get($cid);

    if ($cached && $cached->data) {
      return $cached->data;
    }

    $use_cases = [
      'mobile_apps' => [
        'modules' => ['simple_oauth_pkce', 'simple_oauth_native_apps'],
        'description' => 'Secure OAuth for mobile applications with enhanced native app support',
        'priority' => 'high',
        'implementation_notes' => 'PKCE is mandatory for mobile apps, native apps module adds security features',
        'security_level' => 'high',
      ],
      'single_page_applications' => [
        'modules' => ['simple_oauth_pkce'],
        'description' => 'OAuth for browser-based SPAs using authorization code flow with PKCE',
        'priority' => 'high',
        'implementation_notes' => 'PKCE replaces implicit flow for better security in public clients',
        'security_level' => 'high',
      ],
      'device_integration' => [
        'modules' => ['simple_oauth_device_flow', 'simple_oauth_pkce'],
        'description' => 'OAuth for smart TVs, IoT devices, and input-constrained devices',
        'priority' => 'medium',
        'implementation_notes' => 'Device flow enables OAuth on devices without web browsers',
        'security_level' => 'medium',
      ],
      'api_discovery' => [
        'modules' => ['simple_oauth_server_metadata'],
        'description' => 'Automatic client configuration and OAuth server discovery',
        'priority' => 'medium',
        'implementation_notes' => 'Enables clients to automatically discover OAuth capabilities',
        'security_level' => 'low',
      ],
      'dynamic_client_onboarding' => [
        'modules' => ['simple_oauth_client_registration', 'simple_oauth_server_metadata'],
        'description' => 'Automated client registration and configuration',
        'priority' => 'low',
        'implementation_notes' => 'Useful for multi-tenant applications and automated deployment',
        'security_level' => 'medium',
      ],
      'enterprise_web_applications' => [
        'modules' => ['simple_oauth_server_metadata'],
        'description' => 'Traditional server-side web applications with OAuth integration',
        'priority' => 'low',
        'implementation_notes' => 'Confidential clients using authorization code flow',
        'security_level' => 'high',
      ],
      'complete_oauth_ecosystem' => [
        'modules' => [
          'simple_oauth_pkce',
          'simple_oauth_server_metadata',
          'simple_oauth_native_apps',
          'simple_oauth_device_flow',
          'simple_oauth_client_registration',
        ],
        'description' => 'Full OAuth 2.1 implementation supporting all client types and flows',
        'priority' => 'high',
        'implementation_notes' => 'Comprehensive OAuth server supporting all modern OAuth patterns',
        'security_level' => 'maximum',
      ],
    ];

    // Cache for 10 minutes with module list tags.
    $this->cache->set($cid, $use_cases, time() + 600, ['config:module_list']);

    return $use_cases;
  }

  /**
   * Gets feature availability matrix for OAuth flows and capabilities.
   *
   * Provides information about available OAuth features based on currently
   * enabled modules and their configuration status.
   *
   * @return array
   *   Matrix of OAuth features and their availability status.
   */
  public function getFeatureAvailabilityMatrix(): array {
    $cid = 'simple_oauth_21:feature_availability';
    $cached = $this->cache->get($cid);

    if ($cached && $cached->data) {
      return $cached->data;
    }

    $features = [
      'authorization_code_flow' => [
        'available' => $this->moduleHandler->moduleExists('simple_oauth'),
        'modules' => ['simple_oauth'],
        'enhanced_by' => ['simple_oauth_pkce'],
        'description' => 'Standard OAuth authorization code flow',
        'security_notes' => 'Enhanced security with PKCE when available',
      ],
      'pkce_protection' => [
        'available' => $this->isModuleEnabledWithFallback('simple_oauth_pkce'),
        'modules' => ['simple_oauth_pkce'],
        'requires' => ['simple_oauth'],
        'description' => 'Proof Key for Code Exchange protection for authorization code flows',
        'security_notes' => 'Mandatory for OAuth 2.1 compliance and public clients',
      ],
      'device_authorization_flow' => [
        'available' => $this->isModuleEnabledWithFallback('simple_oauth_device_flow'),
        'modules' => ['simple_oauth_device_flow'],
        'requires' => ['simple_oauth'],
        'description' => 'OAuth flow for input-constrained devices',
        'security_notes' => 'Enables OAuth on devices without web browsers',
      ],
      'server_metadata_discovery' => [
        'available' => $this->isModuleEnabledWithFallback('simple_oauth_server_metadata'),
        'modules' => ['simple_oauth_server_metadata'],
        'requires' => ['simple_oauth'],
        'description' => 'Automatic OAuth server capability discovery',
        'security_notes' => 'Helps clients configure security settings automatically',
      ],
      'native_app_security' => [
        'available' => $this->isModuleEnabledWithFallback('simple_oauth_native_apps'),
        'modules' => ['simple_oauth_native_apps'],
        'requires' => ['simple_oauth'],
        'enhanced_by' => ['simple_oauth_pkce'],
        'description' => 'Enhanced security features for native applications',
        'security_notes' => 'Includes WebView detection and custom URI scheme support',
      ],
      'dynamic_client_registration' => [
        'available' => $this->isModuleEnabledWithFallback('simple_oauth_client_registration'),
        'modules' => ['simple_oauth_client_registration'],
        'requires' => ['simple_oauth'],
        'description' => 'Automated OAuth client registration and management',
        'security_notes' => 'Enables programmatic client creation with proper validation',
      ],
      'oauth_21_compliance' => [
        'available' => $this->checkOauth21Compliance(),
        'modules' => ['simple_oauth_pkce'],
        'requires' => ['simple_oauth'],
        'enhanced_by' => ['simple_oauth_server_metadata', 'simple_oauth_native_apps'],
        'description' => 'Full OAuth 2.1 specification compliance',
        'security_notes' => 'Requires PKCE and deprecates implicit grant flow',
      ],
    ];

    // Add endpoint availability information.
    foreach ($features as $feature_name => &$feature) {
      $feature['endpoints'] = $this->getFeatureEndpoints($feature_name, $feature['modules']);
    }

    // Cache for 5 minutes with configuration and module tags.
    $cache_tags = ['config:module_list', 'config:simple_oauth.settings'];
    foreach (array_keys(self::SUBMODULE_MAPPING) as $module_name) {
      $cache_tags[] = "config:{$module_name}.settings";
    }

    $this->cache->set($cid, $features, time() + 300, $cache_tags);

    return $features;
  }

  /**
   * Gets detailed capability status for a specific module.
   *
   * Provides granular status information about module installation,
   * configuration, and functional readiness.
   *
   * @param string $module_name
   *   The base module name to check.
   *
   * @return array
   *   Detailed capability status information.
   */
  private function getModuleCapabilityStatus(string $module_name): array {
    $module_status = $this->getModuleStatus($module_name);
    $config = $this->getModuleConfigWithFallback($module_name);

    $status = [
      'installation_status' => $this->determineInstallationStatus($module_status),
      'configuration_status' => $this->determineConfigurationStatus($module_name, $config),
      'endpoint_status' => $this->checkModuleEndpoints($module_name),
      'enabled' => $module_status['enabled'],
      'available' => $module_status['available'],
      'installation_type' => $module_status['installation_type'],
    ];

    // Overall readiness assessment.
    $status['overall_readiness'] = $this->calculateOverallReadiness($status);

    return $status;
  }

  /**
   * Determines the installation status level for a module.
   *
   * @param array $module_status
   *   Module status information from getModuleStatus().
   *
   * @return string
   *   Installation status level.
   */
  private function determineInstallationStatus(array $module_status): string {
    if (!$module_status['available']) {
      return 'not_installed';
    }

    if (!$module_status['enabled']) {
      return 'installed_disabled';
    }

    return 'enabled';
  }

  /**
   * Determines the configuration status for a module.
   *
   * @param string $module_name
   *   The module name to check configuration for.
   * @param \Drupal\Core\Config\Config|null $config
   *   The module configuration object.
   *
   * @return string
   *   Configuration status level.
   */
  private function determineConfigurationStatus(string $module_name, ?Config $config): string {
    if (!$config) {
      return 'no_configuration';
    }

    // Module-specific configuration checks.
    $configured_properly = match ($module_name) {
      'simple_oauth_pkce' => $this->checkPkceConfiguration($config),
      'simple_oauth_server_metadata' => $this->checkServerMetadataConfiguration($config),
      'simple_oauth_native_apps' => $this->checkNativeAppsConfiguration($config),
      'simple_oauth_device_flow' => $this->checkDeviceFlowConfiguration($config),
      'simple_oauth_client_registration' => $this->checkClientRegistrationConfiguration($config),
      default => TRUE,
    };

    return $configured_properly ? 'fully_configured' : 'partially_configured';
  }

  /**
   * Checks PKCE module configuration completeness.
   *
   * @param \Drupal\Core\Config\Config $config
   *   PKCE configuration object.
   *
   * @return bool
   *   TRUE if properly configured.
   */
  private function checkPkceConfiguration(Config $config): bool {
    $enforcement = $config->get('enforcement') ?? 'optional';
    $s256_enabled = (bool) ($config->get('s256_enabled') ?? FALSE);

    // For OAuth 2.1 compliance, PKCE should be mandatory and S256 enabled.
    return $enforcement === 'mandatory' && $s256_enabled;
  }

  /**
   * Checks server metadata module configuration completeness.
   *
   * @param \Drupal\Core\Config\Config $config
   *   Server metadata configuration object.
   *
   * @return bool
   *   TRUE if properly configured.
   */
  private function checkServerMetadataConfiguration(Config $config): bool {
    // Basic check: ensure essential metadata is configured.
    $issuer = $config->get('issuer') ?? '';
    $authorization_endpoint = $config->get('authorization_endpoint') ?? '';
    $token_endpoint = $config->get('token_endpoint') ?? '';

    return !empty($issuer) && !empty($authorization_endpoint) && !empty($token_endpoint);
  }

  /**
   * Checks native apps module configuration completeness.
   *
   * @param \Drupal\Core\Config\Config $config
   *   Native apps configuration object.
   *
   * @return bool
   *   TRUE if properly configured.
   */
  private function checkNativeAppsConfiguration(Config $config): bool {
    $custom_schemes_setting = $config->get('allow.custom_uri_schemes');
    $loopback_setting = $config->get('allow.loopback_redirects');

    // Consider them allowed if set to 'native' or 'auto-detect'.
    $allow_custom_schemes = in_array($custom_schemes_setting, ['native', 'auto-detect'], TRUE);
    $allow_loopback = in_array($loopback_setting, ['native', 'auto-detect'], TRUE);

    // For native apps, both custom schemes and loopback redirects should be
    // allowed.
    return $allow_custom_schemes && $allow_loopback;
  }

  /**
   * Checks device flow module configuration completeness.
   *
   * @param \Drupal\Core\Config\Config $config
   *   Device flow configuration object.
   *
   * @return bool
   *   TRUE if properly configured.
   */
  private function checkDeviceFlowConfiguration(Config $config): bool {
    $verification_uri = $config->get('verification_uri') ?? '';
    $device_code_expiration = (int) ($config->get('device_code_expiration') ?? 0);

    return !empty($verification_uri) && $device_code_expiration > 0;
  }

  /**
   * Checks client registration module configuration completeness.
   *
   * @param \Drupal\Core\Config\Config $config
   *   Client registration configuration object.
   *
   * @return bool
   *   TRUE if properly configured.
   */
  private function checkClientRegistrationConfiguration(Config $config): bool {
    $allow_dynamic_registration = (bool) ($config->get('allow_dynamic_registration') ?? FALSE);
    $require_authentication = (bool) ($config->get('require_authentication') ?? TRUE);

    return $allow_dynamic_registration && $require_authentication;
  }

  /**
   * Checks endpoint availability for a specific module.
   *
   * @param string $module_name
   *   The module name to check endpoints for.
   *
   * @return array
   *   Array of endpoint status information.
   */
  private function checkModuleEndpoints(string $module_name): array {
    $endpoints = match ($module_name) {
      'simple_oauth_pkce' => ['/oauth/authorize', '/oauth/token'],
      'simple_oauth_server_metadata' => ['/.well-known/oauth-authorization-server'],
      'simple_oauth_native_apps' => ['/oauth/authorize', '/oauth/token'],
      'simple_oauth_device_flow' => ['/oauth/device_authorization', '/oauth/device'],
      'simple_oauth_client_registration' => ['/oauth/register'],
      default => [],
    };

    $endpoint_status = [];
    foreach ($endpoints as $endpoint) {
      $endpoint_status[$endpoint] = $this->checkEndpointAvailability($endpoint);
    }

    return $endpoint_status;
  }

  /**
   * Checks if a specific endpoint is available and accessible.
   *
   * @param string $path
   *   The endpoint path to check.
   *
   * @return array
   *   Endpoint availability information.
   */
  private function checkEndpointAvailability(string $path): array {
    $route_name = $this->getRouteNameForPath($path);

    return [
      'path' => $path,
      'route_exists' => $route_name !== NULL,
      'route_name' => $route_name,
      'accessible' => $route_name !== NULL ? $this->testRouteAccess($route_name) : FALSE,
    ];
  }

  /**
   * Gets the route name for a given path.
   *
   * @param string $path
   *   The path to find the route for.
   *
   * @return string|null
   *   The route name if found, NULL otherwise.
   */
  private function getRouteNameForPath(string $path): ?string {
    $route_map = [
      '/oauth/authorize' => 'oauth2_token.authorize',
      '/oauth/token' => 'oauth2_token.token',
      '/.well-known/oauth-authorization-server' => 'simple_oauth_server_metadata.well_known',
      '/oauth/device_authorization' => 'simple_oauth_device_flow.device_authorization',
      '/oauth/device' => 'simple_oauth_device_flow.device_token',
      '/oauth/register' => 'simple_oauth_client_registration.register',
    ];

    return $route_map[$path] ?? NULL;
  }

  /**
   * Tests if a route is accessible.
   *
   * @param string $route_name
   *   The route name to test.
   *
   * @return bool
   *   TRUE if the route is accessible.
   */
  private function testRouteAccess(string $route_name): bool {
    try {
      $this->routeProvider->getRouteByName($route_name);
      return TRUE;
    }
    catch (RouteNotFoundException $e) {
      return FALSE;
    }
  }

  /**
   * Gets endpoint information for a specific feature.
   *
   * @param string $feature_name
   *   The feature name.
   * @param array $modules
   *   The modules that provide this feature.
   *
   * @return array
   *   Endpoint information for the feature.
   */
  private function getFeatureEndpoints(string $feature_name, array $modules): array {
    $endpoints = [];

    foreach ($modules as $module) {
      if ($this->isModuleEnabledWithFallback($module)) {
        $module_endpoints = $this->checkModuleEndpoints($module);
        $endpoints = array_merge($endpoints, $module_endpoints);
      }
    }

    return $endpoints;
  }

  /**
   * Calculates overall readiness assessment for a module.
   *
   * @param array $status
   *   The detailed status array.
   *
   * @return string
   *   Overall readiness level.
   */
  private function calculateOverallReadiness(array $status): string {
    if (!$status['enabled']) {
      return $status['available'] ? 'available_not_enabled' : 'not_available';
    }

    if ($status['configuration_status'] === 'fully_configured') {
      $working_endpoints = count(array_filter($status['endpoint_status'], fn($ep) => $ep['accessible']));
      $total_endpoints = count($status['endpoint_status']);

      if ($working_endpoints === $total_endpoints && $total_endpoints > 0) {
        return 'fully_ready';
      }
      elseif ($working_endpoints > 0) {
        return 'partially_ready';
      }
      else {
        return 'configured_not_functional';
      }
    }

    return match ($status['configuration_status']) {
      'partially_configured' => 'needs_configuration',
      'no_configuration' => 'needs_configuration',
      default => 'unknown',
    };
  }

  /**
   * Checks OAuth 2.1 compliance status for feature matrix.
   *
   * @return bool
   *   TRUE if OAuth 2.1 compliant.
   */
  private function checkOauth21Compliance(): bool {
    $compliance = $this->getComplianceStatus();
    return $compliance['overall_status']['status'] === 'fully_compliant' ||
           $compliance['overall_status']['status'] === 'mostly_compliant';
  }

  /**
   * Provides a failsafe compliance status when the service encounters errors.
   *
   * @param \Exception $exception
   *   The exception that caused the service failure.
   *
   * @return array
   *   Safe fallback compliance status.
   */
  private function getFailsafeComplianceStatus(\Exception $exception): array {
    return [
      'core_requirements' => [
        'service_error' => [
          'status' => 'non_compliant',
          'title' => 'Service Error',
          'description' => 'Compliance service encountered an error',
          'message' => 'Unable to assess compliance due to service error: ' . $exception->getMessage(),
          'level' => 'mandatory',
        ],
      ],
      'server_metadata' => [],
      'best_practices' => [],
      'overall_status' => [
        'status' => 'non_compliant',
        'mandatory_score' => ['compliant' => 0, 'total' => 1, 'percentage' => 0],
        'required_score' => ['compliant' => 0, 'total' => 0, 'percentage' => 0],
        'recommended_score' => ['compliant' => 0, 'total' => 0, 'percentage' => 0],
      ],
      'summary' => [
        'message' => 'Compliance assessment is unavailable due to service errors. Please check system logs and configuration.',
        'critical_issues' => ['Service error: ' . $exception->getMessage()],
        'recommendations' => ['Check system logs', 'Verify module configuration'],
        'has_critical_issues' => TRUE,
        'has_recommendations' => TRUE,
      ],
      'service_health' => [
        'status' => 'unhealthy',
        'issues' => ['Service failure: ' . $exception->getMessage()],
        'warnings' => [],
        'dependencies' => [],
      ],
    ];
  }

  /**
   * Calculates overall compliance status.
   *
   * @param array $core_requirements
   *   Core requirements compliance data.
   * @param array $server_metadata
   *   Server metadata compliance data.
   * @param array $best_practices
   *   Best practices compliance data.
   *
   * @return array
   *   Overall compliance assessment.
   */
  private function calculateOverallStatus(array $core_requirements, array $server_metadata, array $best_practices): array {
    $mandatory_compliant = $this->countStatusByLevel($core_requirements, 'mandatory', 'compliant');
    $mandatory_total = $this->countStatusByLevel($core_requirements, 'mandatory');

    $required_compliant = $this->countStatusByLevel($server_metadata, 'required', 'compliant');
    $required_total = $this->countStatusByLevel($server_metadata, 'required');

    $recommended_compliant = $this->countStatusByLevel($best_practices, 'recommended', 'compliant');
    $recommended_total = $this->countStatusByLevel($best_practices, 'recommended');

    $mandatory_percentage = $mandatory_total > 0 ? ($mandatory_compliant / $mandatory_total) * 100 : 0;
    $required_percentage = $required_total > 0 ? ($required_compliant / $required_total) * 100 : 100;
    $recommended_percentage = $recommended_total > 0 ? ($recommended_compliant / $recommended_total) * 100 : 100;

    // Overall status determination.
    $overall_status = 'non_compliant';
    if ($mandatory_percentage == 100) {
      if ($required_percentage == 100) {
        $overall_status = $recommended_percentage >= 80 ? 'fully_compliant' : 'mostly_compliant';
      }
      else {
        $overall_status = 'partially_compliant';
      }
    }

    return [
      'status' => $overall_status,
      'mandatory_score' => [
        'compliant' => $mandatory_compliant,
        'total' => $mandatory_total,
        'percentage' => floor($mandatory_percentage),
      ],
      'required_score' => [
        'compliant' => $required_compliant,
        'total' => $required_total,
        'percentage' => floor($required_percentage),
      ],
      'recommended_score' => [
        'compliant' => $recommended_compliant,
        'total' => $recommended_total,
        'percentage' => floor($recommended_percentage),
      ],
    ];
  }

  /**
   * Generates a human-readable compliance summary.
   *
   * @param array $core_requirements
   *   Core requirements compliance data.
   * @param array $server_metadata
   *   Server metadata compliance data.
   * @param array $best_practices
   *   Best practices compliance data.
   *
   * @return array
   *   Compliance summary with messages and recommendations.
   */
  private function generateComplianceSummary(array $core_requirements, array $server_metadata, array $best_practices): array {
    $issues = [];
    $recommendations = [];

    // Check for critical issues.
    foreach ([$core_requirements, $server_metadata] as $category) {
      foreach ($category as $requirement) {
        if ($requirement['status'] === 'non_compliant') {
          $issues[] = $requirement['message'];
        }
      }
    }

    // Check for recommendations.
    foreach ($best_practices as $practice) {
      if ($practice['status'] === 'recommended') {
        $recommendations[] = $practice['message'];
      }
    }

    $summary_message = '';
    if (empty($issues)) {
      if (empty($recommendations)) {
        $summary_message = 'Your OAuth implementation is fully compliant with OAuth 2.1 requirements and best practices.';
      }
      else {
        $summary_message = 'Your OAuth implementation meets all mandatory OAuth 2.1 requirements. Consider the recommendations below for enhanced security.';
      }
    }
    else {
      $summary_message = 'Your OAuth implementation has compliance issues that should be addressed for OAuth 2.1 compatibility.';
    }

    return [
      'message' => $summary_message,
      'critical_issues' => $issues,
      'recommendations' => $recommendations,
      'has_critical_issues' => !empty($issues),
      'has_recommendations' => !empty($recommendations),
    ];
  }

  /**
   * Counts requirements by level and optionally by status.
   *
   * @param array $requirements
   *   Array of requirements to count.
   * @param string $level
   *   The requirement level to filter by.
   * @param string|null $status
   *   Optional status to filter by.
   *
   * @return int
   *   Count of matching requirements.
   */
  private function countStatusByLevel(array $requirements, string $level, ?string $status = NULL): int {
    return count(array_filter($requirements, static function ($req) use ($level, $status) {
      $level_match = ($req['level'] ?? '') === $level;
      $status_match = $status === NULL || ($req['status'] ?? '') === $status;
      return $level_match && $status_match;
    }));
  }

  /**
   * Checks HTTPS enforcement for OAuth endpoints.
   *
   * @return array
   *   Array with 'compliant' boolean and 'message' string.
   */
  private function checkHttpsEnforcement(): array {
    // Check current request scheme.
    $is_https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = $_SERVER['HTTP_HOST'] ?? 'unknown';

    // Check if this is a loopback interface (exempt from HTTPS per RFC 8252).
    $is_loopback = $this->isLoopbackHost($host);

    if (!$is_https && !$is_loopback) {
      return [
        'compliant' => FALSE,
        'message' => sprintf(
          'OAuth 2.1 requires HTTPS for all endpoints except loopback interfaces. Current host "%s" is using HTTP.',
          $host
        ),
      ];
    }

    if ($is_loopback && !$is_https) {
      return [
        'compliant' => TRUE,
        'message' => sprintf(
          'HTTP is allowed for loopback interface "%s" per RFC 8252.',
          $host
        ),
      ];
    }

    return [
      'compliant' => TRUE,
      'message' => sprintf(
        'HTTPS is properly enforced for host "%s".',
        $host
      ),
    ];
  }

  /**
   * Checks if a host is a loopback interface.
   *
   * @param string $host
   *   The host to check.
   *
   * @return bool
   *   TRUE if host is a loopback interface.
   */
  private function isLoopbackHost(string $host): bool {
    // Remove port if present.
    $host_without_port = preg_replace('/:\d+$/', '', $host);

    $loopback_hosts = [
      '127.0.0.1',
      'localhost',
      '::1',
      '[::1]',
    ];

    return in_array(strtolower($host_without_port), array_map('strtolower', $loopback_hosts));
  }

}
