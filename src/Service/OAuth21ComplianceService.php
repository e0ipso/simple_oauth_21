<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_21\Service;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Simplified service for OAuth 2.1 compliance checking.
 *
 * Provides basic module status checks and simplified RFC compliance status
 * without complex scoring or percentage calculations.
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
    ],
    'simple_oauth_server_metadata' => [
      'submodule' => 'simple_oauth_21_server_metadata',
      'contrib' => 'simple_oauth_server_metadata',
    ],
    'simple_oauth_native_apps' => [
      'submodule' => 'simple_oauth_21_native_apps',
      'contrib' => 'simple_oauth_native_apps',
    ],
    'simple_oauth_device_flow' => [
      'submodule' => 'simple_oauth_21_device_flow',
      'contrib' => 'simple_oauth_device_flow',
    ],
    'simple_oauth_client_registration' => [
      'submodule' => 'simple_oauth_21_client_registration',
      'contrib' => 'simple_oauth_client_registration',
    ],
  ];

  /**
   * Constructs an OAuth21ComplianceService object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory service.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Gets simplified RFC compliance status.
   *
   * @return array
   *   Array of RFC compliance status with simple 3-state system.
   */
  public function getRfcComplianceStatus(): array {
    $rfcs = [];

    // RFC 7636 - PKCE.
    $pkce_status = $this->getModuleStatus('simple_oauth_pkce');
    $rfcs['rfc_7636'] = [
      'status' => $this->determineRfcStatus('simple_oauth_pkce'),
      'module' => 'simple_oauth_pkce',
      'enabled' => $pkce_status['enabled'],
      'recommendation' => $pkce_status['enabled'] ? 'PKCE is enabled for OAuth 2.1 compliance' : 'Enable PKCE module for OAuth 2.1 compliance',
    ];

    // RFC 8414 - Server Metadata.
    $metadata_status = $this->getModuleStatus('simple_oauth_server_metadata');
    $rfcs['rfc_8414'] = [
      'status' => $this->determineRfcStatus('simple_oauth_server_metadata'),
      'module' => 'simple_oauth_server_metadata',
      'enabled' => $metadata_status['enabled'],
      'recommendation' => $metadata_status['enabled'] ? 'Server metadata is available for discovery' : 'Enable server metadata module for better client integration',
    ];

    // RFC 8252 - Native Apps.
    $native_status = $this->getModuleStatus('simple_oauth_native_apps');
    $rfcs['rfc_8252'] = [
      'status' => $this->determineRfcStatus('simple_oauth_native_apps'),
      'module' => 'simple_oauth_native_apps',
      'enabled' => $native_status['enabled'],
      'recommendation' => $native_status['enabled'] ? 'Native app security features are enabled' : 'Enable native apps module for mobile app security',
    ];

    // RFC 8628 - Device Flow.
    $device_status = $this->getModuleStatus('simple_oauth_device_flow');
    $rfcs['rfc_8628'] = [
      'status' => $this->determineRfcStatus('simple_oauth_device_flow'),
      'module' => 'simple_oauth_device_flow',
      'enabled' => $device_status['enabled'],
      'recommendation' => $device_status['enabled'] ? 'Device flow is available for constrained devices' : 'Enable device flow module for IoT and TV apps',
    ];

    // RFC 7591 - Client Registration.
    $registration_status = $this->getModuleStatus('simple_oauth_client_registration');
    $rfcs['rfc_7591'] = [
      'status' => $this->determineRfcStatus('simple_oauth_client_registration'),
      'module' => 'simple_oauth_client_registration',
      'enabled' => $registration_status['enabled'],
      'recommendation' => $registration_status['enabled'] ? 'Dynamic client registration is available' : 'Enable client registration module for automated client setup',
    ];

    return $rfcs;
  }

  /**
   * Determines RFC compliance status for a module.
   *
   * @param string $module_name
   *   The module name to check.
   *
   * @return string
   *   Status: 'configured', 'needs_attention', or 'not_available'.
   */
  private function determineRfcStatus(string $module_name): string {
    if (!$this->isModuleEnabledWithFallback($module_name)) {
      return 'not_available';
    }

    $config = $this->getModuleConfigWithFallback($module_name);
    if (!$config) {
      return 'needs_attention';
    }

    // Basic configuration checks by module.
    return match ($module_name) {
      'simple_oauth_pkce' => $this->checkPkceStatus($config),
      'simple_oauth_server_metadata' => $this->checkServerMetadataStatus($config),
      'simple_oauth_native_apps' => $this->checkNativeAppsStatus($config),
      default => 'configured',
    };
  }

  /**
   * Checks PKCE configuration status.
   *
   * @param \Drupal\Core\Config\Config $config
   *   PKCE configuration object.
   *
   * @return string
   *   Status: 'configured', 'needs_attention', or 'not_available'.
   */
  private function checkPkceStatus(Config $config): string {
    $enforcement = $config->get('enforcement') ?? 'optional';
    $s256_enabled = (bool) ($config->get('s256_enabled') ?? FALSE);

    return ($enforcement === 'mandatory' && $s256_enabled) ? 'configured' : 'needs_attention';
  }

  /**
   * Checks server metadata configuration status.
   *
   * @param \Drupal\Core\Config\Config $config
   *   Server metadata configuration object.
   *
   * @return string
   *   Status: 'configured', 'needs_attention', or 'not_available'.
   */
  private function checkServerMetadataStatus(Config $config): string {
    $issuer = $config->get('issuer') ?? '';
    $authorization_endpoint = $config->get('authorization_endpoint') ?? '';
    $token_endpoint = $config->get('token_endpoint') ?? '';

    return (!empty($issuer) && !empty($authorization_endpoint) && !empty($token_endpoint)) ? 'configured' : 'needs_attention';
  }

  /**
   * Checks native apps configuration status.
   *
   * @param \Drupal\Core\Config\Config $config
   *   Native apps configuration object.
   *
   * @return string
   *   Status: 'configured', 'needs_attention', or 'not_available'.
   */
  private function checkNativeAppsStatus(Config $config): string {
    $custom_schemes_setting = $config->get('allow.custom_uri_schemes');
    $loopback_setting = $config->get('allow.loopback_redirects');
    $allow_custom_schemes = in_array($custom_schemes_setting, ['native', 'auto-detect'], TRUE);
    $allow_loopback = in_array($loopback_setting, ['native', 'auto-detect'], TRUE);

    return ($allow_custom_schemes && $allow_loopback) ? 'configured' : 'needs_attention';
  }

  /**
   * Enhanced module detection with submodule support and fallback logic.
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
      return $this->moduleHandler->moduleExists($base_module_name);
    }

    // Check submodule, contrib, and base variants.
    return $this->moduleHandler->moduleExists($mapping['submodule']) ||
           $this->moduleHandler->moduleExists($mapping['contrib']) ||
           $this->moduleHandler->moduleExists($base_module_name);
  }

  /**
   * Gets detailed module status information.
   *
   * @param string $base_module_name
   *   The base module name to get status for.
   *
   * @return array
   *   Array with detailed status information.
   */
  public function getModuleStatus(string $base_module_name): array {
    $mapping = self::SUBMODULE_MAPPING[$base_module_name] ?? [];
    $enabled = $this->isModuleEnabledWithFallback($base_module_name);

    return [
      'base_name' => $base_module_name,
      'enabled' => $enabled,
      'available' => $enabled || !empty($mapping),
    ];
  }

  /**
   * Gets configuration for a module with fallback support.
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
    $mapping = self::SUBMODULE_MAPPING[$base_module_name] ?? [];

    if (empty($mapping)) {
      return $this->getModuleConfig($base_module_name . '.' . $config_suffix);
    }

    // Try submodule first, then contrib module.
    $candidates = [$mapping['submodule'], $mapping['contrib'], $base_module_name];

    foreach ($candidates as $candidate) {
      if ($this->moduleHandler->moduleExists($candidate)) {
        $config = $this->getModuleConfig($candidate . '.' . $config_suffix);
        if ($config) {
          return $config;
        }
      }
    }

    return NULL;
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
  private function getModuleConfig(string $config_name): ?Config {
    try {
      $config = $this->configFactory->get($config_name);
      return $config->isNew() ? NULL : $config;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}
