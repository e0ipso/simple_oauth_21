<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporterEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Url;

/**
 * Service for managing Device Flow settings and configuration.
 */
final class DeviceFlowSettingsService implements EventSubscriberInterface {

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
   * @param \Drupal\Core\Path\PathValidatorInterface|null $pathValidator
   *   The path validator, or NULL to use the container.
   */
  public function __construct(ConfigFactoryInterface $configFactory, private readonly ?PathValidatorInterface $pathValidator = NULL) {
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
   * Resolves and validates the effective issuance settings without rewriting.
   *
   * The default-strength floor is module policy informed by RFC 8628 section
   * 5.1, not an RFC minimum. Code space must be at least
   * max(20^8, 2^32 * attempts * ceil(lifetime / 3600)).
   * The budget follows the existing per-user hourly
   * limiter. Multi-account, distributed and concurrent guessing is not globally
   * bounded by that limiter or by this calculation.
   *
   * @param array $data
   *   Complete incoming or effective settings, never merged with active config.
   *
   * @return array{length: int, charset: string, lifetime: int, attempts: int}
   *   Validated generation settings with supported absence defaults.
   *
   * @throws \UnexpectedValueException
   *   If the generation settings violate the module's safety policy.
   */
  public static function resolveGenerationSettings(array $data): array {
    $values = [];
    foreach (['user_code_length' => 8, 'device_code_lifetime' => 1800, 'max_authorization_attempts' => 3] as $key => $default) {
      $value = array_key_exists($key, $data) ? $data[$key] : $default;
      if (!is_int($value) || $value <= 0) {
        throw new \UnexpectedValueException('Device code generation requires positive integer length, lifetime and attempt settings.');
      }
      $values[$key] = $value;
    }
    $length = $values['user_code_length'];
    if ($length < 6 || $length > 12) {
      throw new \UnexpectedValueException('User code length must be between 6 and 12.');
    }
    $charset = $data['user_code_charset'] ?? '';
    if ($charset === '') {
      // Preserve the legacy excluded-character fallback, including its final
      // default when every character has been excluded.
      $excluded = $data['user_code_excluded_chars'] ?? '01OILO';
      if (!is_string($excluded) || strlen($excluded) > 256) {
        throw new \UnexpectedValueException('Invalid excluded user code characters.');
      }
      $charset = str_replace(str_split($excluded), '', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
      $charset = $charset !== '' ? $charset : UserCodeGenerator::DEFAULT_CHARSET;
    }
    if (!is_string($charset) || strlen($charset) > 36 || !preg_match('/^[A-Z0-9]+$/D', $charset)
      || strlen(count_chars($charset, 3)) !== strlen($charset)) {
      throw new \UnexpectedValueException('User codes require unique uppercase ASCII letters or digits.');
    }
    $space = '1';
    for ($i = 0; $i < $length; $i++) {
      $space = self::multiplyDecimal($space, (string) strlen($charset));
    }
    $windows = intdiv($values['device_code_lifetime'] - 1, 3600) + 1;
    $budget = self::multiplyDecimal('4294967296', (string) $values['max_authorization_attempts']);
    $budget = self::multiplyDecimal($budget, (string) $windows);
    foreach (['25600000000', $budget] as $minimum) {
      if (strlen($space) < strlen($minimum) || (strlen($space) === strlen($minimum) && strcmp($space, $minimum) < 0)) {
        throw new \UnexpectedValueException('User code space is too small for the default-strength floor or configured lifetime and per-user attempt budget. Increase the alphabet or length, or reduce the lifetime or attempt budget.');
      }
    }
    return [
      'length' => $length,
      'charset' => $charset,
      'lifetime' => $values['device_code_lifetime'],
      'attempts' => $values['max_authorization_attempts'],
    ];
  }

  /**
   * Multiplies positive decimal integers exactly without machine-size limits.
   *
   * Each intermediate digit calculation is at most 99. This avoids both integer
   * overflow and floating-point rounding at the code-space policy boundary.
   *
   * @param string $left
   *   The first positive decimal integer.
   * @param string $right
   *   The second positive decimal integer.
   *
   * @return string
   *   Their exact decimal product.
   */
  private static function multiplyDecimal(string $left, string $right): string {
    $digits = array_fill(0, strlen($left) + strlen($right), 0);
    for ($i = strlen($left) - 1; $i >= 0; $i--) {
      $carry = 0;
      for ($j = strlen($right) - 1; $j >= 0; $j--) {
        $index = $i + $j + 1;
        $product = (int) $left[$i] * (int) $right[$j] + $digits[$index] + $carry;
        $digits[$index] = $product % 10;
        $carry = intdiv($product, 10);
      }
      $digits[$i] = $carry;
    }
    return ltrim(implode('', $digits), '0');
  }

  /**
   * Gets validated effective issuance settings, including runtime overrides.
   *
   * @return array{length: int, charset: string, lifetime: int, attempts: int}
   *   Validated settings.
   *
   * @throws \UnexpectedValueException
   *   If generation is unsafe.
   */
  public function getGenerationSettings(): array {
    return self::resolveGenerationSettings($this->getConfig()->get());
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::IMPORT_VALIDATE => 'validateImport'];
  }

  /**
   * Rejects unsafe incoming settings before a normal configuration import.
   *
   * This subscriber requires the module to be active before import validation.
   * Runtime validation also protects the first activation of imported settings.
   *
   * @param \Drupal\Core\Config\ConfigImporterEvent $event
   *   The import event with the complete incoming storage snapshot.
   */
  public function validateImport(ConfigImporterEvent $event): void {
    $name = 'simple_oauth_device_flow.settings';
    if (!in_array($name, array_merge($event->getChangelist('create'), $event->getChangelist('update')), TRUE)) {
      return;
    }
    $importer = $event->getConfigImporter();
    $data = $importer->getStorageComparer()->getSourceStorage()->read($name);
    try {
      self::resolveGenerationSettings($data === FALSE ? [] : $data);
    }
    catch (\UnexpectedValueException $exception) {
      $importer->logError('Invalid device code generation settings: ' . $exception->getMessage());
    }
  }

  /**
   * Gets the device code expiration time in seconds.
   *
   * @return int
   *   The expiration time in seconds.
   */
  public function getDeviceCodeExpiration(): int {
    // 30 minutes default.
    return $this->getConfig()->get('device_code_lifetime') ?? 1800;
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
    return $this->getConfig()->get('verification_uri') ?? '/oauth/device';
  }

  /**
   * Resolves the configured local verification destination as an absolute URL.
   *
   * @return string
   *   The absolute verification URL, including the base path and alias.
   *
   * @throws \UnexpectedValueException
   *   If the configured path does not resolve to the verification form.
   */
  public function getAbsoluteVerificationUri(): string {
    $path = $this->getConfig()->get('verification_uri') ?? '/oauth/device';
    if (!is_string($path) || !preg_match('@^/(?!/)[A-Za-z0-9/_-]*$@D', $path)) {
      throw new \UnexpectedValueException('Invalid local device verification path.');
    }
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal -- Preserve the original constructor for external callers.
    $validator = $this->pathValidator ?? \Drupal::service('path.validator');
    $url = $validator->getUrlIfValid($path);
    if (!$url instanceof Url || !$url->isRouted() || $url->getRouteName() !== 'simple_oauth_device_flow.device_verification_form') {
      throw new \UnexpectedValueException('Device verification path does not resolve to the verification form.');
    }
    return $url->setAbsolute()->toString();
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
    // Invalid generation settings must not disable pending-code verification.
    $attempts = $this->getConfig()->get('max_authorization_attempts');
    return is_int($attempts) ? $attempts : 3;
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
