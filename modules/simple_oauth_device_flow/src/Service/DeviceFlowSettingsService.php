<?php

namespace Drupal\simple_oauth_device_flow\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for managing Device Flow settings and configuration.
 */
class DeviceFlowSettingsService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a DeviceFlowSettingsService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->configFactory = $configFactory;
  }

  /**
   * Gets the device flow configuration.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The device flow configuration.
   */
  public function getConfig() {
    return $this->configFactory->get('simple_oauth_device_flow.settings');
  }

  /**
   * Gets the device code expiration time in seconds.
   *
   * @return int
   *   The expiration time in seconds.
   */
  public function getDeviceCodeExpiration(): int {
    // 30 minutes default.
    return $this->getConfig()->get('device_code_expiration') ?? 1800;
  }

  /**
   * Gets the user code length.
   *
   * @return int
   *   The user code length.
   */
  public function getUserCodeLength(): int {
    return $this->getConfig()->get('user_code_length') ?? 8;
  }

  /**
   * Gets the user code character set.
   *
   * @return string|null
   *   The user code character set, or NULL to use default.
   */
  public function getUserCodeCharset(): ?string {
    return $this->getConfig()->get('user_code_charset');
  }

  /**
   * Gets the user code format pattern.
   *
   * @return string
   *   The user code format (e.g., 'XXXX-XXXX').
   */
  public function getUserCodeFormat(): string {
    return $this->getConfig()->get('user_code_format') ?? 'XXXX-XXXX';
  }

  /**
   * Gets the polling interval in seconds.
   *
   * @return int
   *   The polling interval in seconds.
   */
  public function getPollingInterval(): int {
    return $this->getConfig()->get('polling_interval') ?? 5;
  }

  /**
   * Gets the verification URI.
   *
   * @return string
   *   The verification URI.
   */
  public function getVerificationUri(): string {
    return $this->getConfig()->get('verification_uri') ?? '/oauth/device/verify';
  }

  /**
   * Checks if user code format should use uppercase.
   *
   * @return bool
   *   TRUE if uppercase, FALSE otherwise.
   */
  public function isUserCodeUppercase(): bool {
    return $this->getConfig()->get('user_code_uppercase') ?? TRUE;
  }

  /**
   * Gets the excluded characters for user code generation.
   *
   * @return string
   *   String of excluded characters.
   */
  public function getUserCodeExcludedChars(): string {
    return $this->getConfig()->get('user_code_excluded_chars') ?? '01OILO';
  }

  /**
   * Gets the maximum number of authorization attempts.
   *
   * @return int
   *   The maximum attempts.
   */
  public function getMaxAuthorizationAttempts(): int {
    return $this->getConfig()->get('max_authorization_attempts') ?? 3;
  }

  /**
   * Checks if expired device codes should be automatically cleaned up.
   *
   * @return bool
   *   TRUE if cleanup is enabled.
   */
  public function isCleanupEnabled(): bool {
    return $this->getConfig()->get('cleanup_enabled') ?? TRUE;
  }

  /**
   * Gets the cleanup interval in seconds.
   *
   * @return int
   *   The cleanup interval in seconds.
   */
  public function getCleanupInterval(): int {
    // 1 hour default.
    return $this->getConfig()->get('cleanup_interval') ?? 3600;
  }

}
