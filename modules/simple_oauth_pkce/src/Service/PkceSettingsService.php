<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_pkce\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for managing PKCE settings and configuration.
 */
class PkceSettingsService {

  /**
   * Constructs a new PkceSettingsService.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Get the PKCE enforcement level.
   *
   * @return string
   *   The enforcement level ('mandatory', 'optional', or 'disabled'). Missing
   *   or malformed settings use mandatory enforcement without changing storage.
   */
  public function getEnforcementLevel(): string {
    $level = $this->configFactory->get('simple_oauth_pkce.settings')->get('enforcement');
    return in_array($level, ['mandatory', 'optional', 'disabled'], TRUE) ? $level : 'mandatory';
  }

  /**
   * Check if PKCE is mandatory.
   *
   * @return bool
   *   TRUE if PKCE is mandatory.
   */
  public function isMandatory(): bool {
    return $this->getEnforcementLevel() === 'mandatory';
  }

  /**
   * Check if PKCE is disabled.
   *
   * @return bool
   *   TRUE if PKCE is disabled.
   */
  public function isDisabled(): bool {
    return $this->getEnforcementLevel() === 'disabled';
  }

  /**
   * Check if S256 challenge method is enabled.
   *
   * @return bool
   *   TRUE if S256 is enabled.
   */
  public function isS256Enabled(): bool {
    return (bool) $this->configFactory->get('simple_oauth_pkce.settings')->get('s256_enabled');
  }

  /**
   * Check if plain challenge method is enabled.
   *
   * @return bool
   *   TRUE if plain is enabled.
   */
  public function isPlainEnabled(): bool {
    return (bool) $this->configFactory->get('simple_oauth_pkce.settings')->get('plain_enabled');
  }

  /**
   * Get supported PKCE challenge methods.
   *
   * @return array
   *   Array of supported methods.
   */
  public function getSupportedMethods(): array {
    $methods = [];

    if ($this->isS256Enabled()) {
      $methods[] = 'S256';
    }

    if ($this->isPlainEnabled()) {
      $methods[] = 'plain';
    }

    return $methods;
  }

}
