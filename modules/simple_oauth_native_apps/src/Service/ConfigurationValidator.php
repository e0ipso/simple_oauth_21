<?php

namespace Drupal\simple_oauth_native_apps\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Configuration validator for native apps settings.
 */
class ConfigurationValidator {

  use StringTranslationTrait;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Constructs a new ConfigurationValidator.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    TranslationInterface $string_translation,
  ) {
    $this->loggerFactory = $logger_factory;
    $this->stringTranslation = $string_translation;
  }

  /**
   * Validates regex patterns.
   *
   * @param array $patterns
   *   Array of regex patterns to validate.
   *
   * @return array
   *   Array of validation errors, empty if all patterns are valid.
   */
  public function validateRegexPatterns(array $patterns): array {
    $errors = [];

    foreach ($patterns as $index => $pattern) {
      if (empty($pattern)) {
        continue;
      }

      // Test the regex pattern.
      $result = @preg_match('/' . $pattern . '/', '');
      if ($result === FALSE) {
        $errors[] = $this->t('Invalid regular expression at line @line: @pattern', [
          '@line' => $index + 1,
          '@pattern' => $pattern,
        ]);
      }
    }

    return $errors;
  }

  /**
   * Validates WebView detection configuration.
   *
   * @param array $config
   *   The WebView configuration array.
   *
   * @return array
   *   Array of validation errors, empty if valid.
   */
  public function validateWebViewConfig(array $config): array {
    $errors = [];

    // Validate detection policy.
    $valid_policies = ['off', 'warn', 'block'];
    $detection_policy = $config['webview']['detection'] ?? '';
    if (!in_array($detection_policy, $valid_policies, TRUE)) {
      $errors[] = $this->t('Invalid WebView detection policy. Must be one of: @policies', [
        '@policies' => implode(', ', $valid_policies),
      ]);
    }

    // Validate whitelist patterns if provided.
    if (!empty($config['webview_whitelist'])) {
      $whitelist_errors = $this->validateRegexPatterns($config['webview_whitelist']);
      foreach ($whitelist_errors as $error) {
        $errors[] = $this->t('WebView whitelist pattern error: @error', ['@error' => $error]);
      }
    }

    // Validate custom detection patterns if provided.
    if (!empty($config['webview_patterns'])) {
      $pattern_errors = $this->validateRegexPatterns($config['webview_patterns']);
      foreach ($pattern_errors as $error) {
        $errors[] = $this->t('WebView detection pattern error: @error', ['@error' => $error]);
      }
    }

    return $errors;
  }

  /**
   * Validates redirect URI configuration.
   *
   * @param array $config
   *   The redirect URI configuration array.
   *
   * @return array
   *   Array of validation errors, empty if valid.
   */
  public function validateRedirectUriConfig(array $config): array {
    $errors = [];

    // Check for logical conflicts.
    if (!empty($config['require_exact_redirect_match']) &&
        (empty($config['allow']['custom_uri_schemes']) && empty($config['allow']['loopback_redirects']))) {
      $errors[] = $this->t('Requiring exact redirect match without allowing custom schemes or loopback redirects may prevent native apps from functioning properly.');
    }

    return $errors;
  }

  /**
   * Validates PKCE configuration.
   *
   * @param array $config
   *   The PKCE configuration array.
   *
   * @return array
   *   Array of validation errors, empty if valid.
   */
  public function validatePkceConfig(array $config): array {
    $errors = [];

    // Currently no specific validations needed for PKCE config.
    // This method is reserved for future enhancements.
    return $errors;
  }

  /**
   * Validates the entire configuration array.
   *
   * @param array $config
   *   The complete configuration array.
   *
   * @return array
   *   Array of validation errors, empty if all configuration is valid.
   */
  public function validateConfiguration(array $config): array {
    $errors = [];

    // Validate WebView configuration.
    $webview_errors = $this->validateWebViewConfig($config);
    $errors = array_merge($errors, $webview_errors);

    // Validate redirect URI configuration.
    $redirect_errors = $this->validateRedirectUriConfig($config);
    $errors = array_merge($errors, $redirect_errors);

    // Validate PKCE configuration.
    $pkce_errors = $this->validatePkceConfig($config);
    $errors = array_merge($errors, $pkce_errors);

    // Validate logging configuration.
    $logging_errors = $this->validateLoggingConfig($config);
    $errors = array_merge($errors, $logging_errors);

    return $errors;
  }

  /**
   * Validates configuration and logs any issues.
   *
   * @param array $config
   *   The configuration array to validate.
   *
   * @return bool
   *   TRUE if configuration is valid, FALSE otherwise.
   */
  public function validateAndLog(array $config): bool {
    $errors = $this->validateConfiguration($config);

    if (!empty($errors)) {
      $logger = $this->loggerFactory->get('simple_oauth_native_apps');
      foreach ($errors as $error) {
        $logger->error('Configuration validation error: @error', ['@error' => $error]);
      }
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Validates logging configuration.
   *
   * @param array $config
   *   The configuration array to validate.
   *
   * @return array
   *   Array of validation error messages.
   */
  protected function validateLoggingConfig(array $config): array {
    $errors = [];

    // Validate logging level.
    $valid_levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
    if (isset($config['logging_level']) && !in_array($config['logging_level'], $valid_levels, TRUE)) {
      $errors[] = $this->t('Invalid logging level. Must be one of: @levels', [
        '@levels' => implode(', ', $valid_levels),
      ]);
    }

    return $errors;
  }

}
