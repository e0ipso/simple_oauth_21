<?php

namespace Drupal\simple_oauth_native_apps\Service;

/**
 * Maps between different config structure formats to prevent validation errors.
 */
class ConfigStructureMapper {

  /**
   * Maps form values to the structure expected by validators.
   *
   * This centralizes the mapping to prevent the recurring issue of structure
   * mismatches between form validation and configuration validators.
   *
   * @param array $form_config
   *   Configuration array from form values (flat structure).
   *
   * @return array
   *   Configuration array in the structure expected by validators (nested).
   */
  public function mapFormToValidatorStructure(array $form_config): array {
    $validator_config = [];

    // Map webview detection - validator expects nested structure.
    if (isset($form_config['webview_detection'])) {
      $validator_config['webview']['detection'] = $form_config['webview_detection'];
    }

    // Map webview whitelist and patterns - validator expects nested structure.
    if (isset($form_config['webview_whitelist'])) {
      $validator_config['webview']['whitelist'] = $form_config['webview_whitelist'];
    }
    if (isset($form_config['webview_patterns'])) {
      $validator_config['webview']['patterns'] = $form_config['webview_patterns'];
    }

    // Map redirect URI settings - validator expects nested 'allow' structure.
    if (isset($form_config['allow_custom_uri_schemes'])) {
      $validator_config['allow']['custom_uri_schemes'] = $form_config['allow_custom_uri_schemes'];
    }
    if (isset($form_config['allow_loopback_redirects'])) {
      $validator_config['allow']['loopback_redirects'] = $form_config['allow_loopback_redirects'];
    }
    if (isset($form_config['require_exact_redirect_match'])) {
      $validator_config['require_exact_redirect_match'] = $form_config['require_exact_redirect_match'];
    }

    // Map PKCE settings - validator expects nested 'native' structure.
    if (isset($form_config['enhanced_pkce_for_native'])) {
      $validator_config['native']['enhanced_pkce'] = $form_config['enhanced_pkce_for_native'];
    }
    if (isset($form_config['enforce_method'])) {
      $validator_config['native']['enforce'] = $form_config['enforce_method'];
    }

    // Map logging settings.
    if (isset($form_config['logging_level'])) {
      $validator_config['logging_level'] = $form_config['logging_level'];
    }

    return $validator_config;
  }

  /**
   * Maps configuration to the flat structure used by main settings form.
   *
   * This converts nested validator config structure to flat form structure.
   *
   * @param array $validator_config
   *   Configuration array from validator (nested structure).
   *
   * @return array
   *   Configuration array in flat structure for forms.
   */
  public function mapValidatorToFormStructure(array $validator_config): array {
    $form_config = [];

    // Map webview detection from nested to flat.
    if (isset($validator_config['webview']['detection'])) {
      $form_config['webview_detection'] = $validator_config['webview']['detection'];
    }

    // Map webview whitelist and patterns.
    if (isset($validator_config['webview_whitelist'])) {
      $form_config['webview_whitelist'] = $validator_config['webview_whitelist'];
    }
    if (isset($validator_config['webview_patterns'])) {
      $form_config['webview_patterns'] = $validator_config['webview_patterns'];
    }

    // Map redirect URI settings from nested to flat.
    if (isset($validator_config['allow']['custom_uri_schemes'])) {
      $form_config['allow_custom_uri_schemes'] = $validator_config['allow']['custom_uri_schemes'];
    }
    if (isset($validator_config['allow']['loopback_redirects'])) {
      $form_config['allow_loopback_redirects'] = $validator_config['allow']['loopback_redirects'];
    }
    if (isset($validator_config['require_exact_redirect_match'])) {
      $form_config['require_exact_redirect_match'] = $validator_config['require_exact_redirect_match'];
    }

    // Map PKCE settings.
    if (isset($validator_config['enhanced_pkce_for_native'])) {
      $form_config['enhanced_pkce_for_native'] = $validator_config['enhanced_pkce_for_native'];
    }

    // Map logging settings.
    if (isset($validator_config['logging_level'])) {
      $form_config['logging_level'] = $validator_config['logging_level'];
    }

    return $form_config;
  }

  /**
   * Gets the expected validator structure documentation.
   *
   * This documents the nested structure expected by ConfigurationValidator
   * to help prevent future mismatches.
   *
   * @return array
   *   Documentation of expected validator structure.
   */
  public function getValidatorStructureDocumentation(): array {
    return [
      'description' => 'Structure expected by ConfigurationValidator service',
      'structure' => [
        'webview' => [
          'detection' => 'string: off|warn|block',
        ],
        'webview_whitelist' => 'array: regex patterns for whitelisted user agents',
        'webview_patterns' => 'array: additional regex patterns for detection',
        'allow' => [
          'custom_uri_schemes' => 'bool: allow myapp:// style redirects',
          'loopback_redirects' => 'bool: allow 127.0.0.1 redirects',
        ],
        'require_exact_redirect_match' => 'bool: require exact URI matching',
        'enhanced_pkce_for_native' => 'bool: require S256 PKCE for native clients',
        'logging_level' => 'string: emergency|alert|critical|error|warning|notice|info|debug',
      ],
    ];
  }

  /**
   * Gets the form structure documentation.
   *
   * This documents the flat structure used by forms to help prevent
   * future mismatches.
   *
   * @return array
   *   Documentation of expected form structure.
   */
  public function getFormStructureDocumentation(): array {
    return [
      'description' => 'Flat structure used by form values and main config',
      'structure' => [
        'webview_detection' => 'string: off|warn|block',
        'webview_whitelist' => 'array: regex patterns for whitelisted user agents',
        'webview_patterns' => 'array: additional regex patterns for detection',
        'allow_custom_uri_schemes' => 'bool: allow myapp:// style redirects',
        'allow_loopback_redirects' => 'bool: allow 127.0.0.1 redirects',
        'require_exact_redirect_match' => 'bool: require exact URI matching',
        'enhanced_pkce_for_native' => 'bool: require S256 PKCE for native clients',
        'logging_level' => 'string: emergency|alert|critical|error|warning|notice|info|debug',
      ],
    ];
  }

}
