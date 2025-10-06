<?php

namespace Drupal\simple_oauth_device_flow\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\simple_oauth_device_flow\Repository\DeviceCodeRepository;
use Psr\Log\LoggerInterface;

/**
 * Service for generating and validating user codes for OAuth Device Flow.
 *
 * Generates cryptographically secure, human-readable user codes that exclude
 * ambiguous characters and follow configurable formatting patterns.
 */
class UserCodeGenerator {

  /**
   * Default character set excluding ambiguous characters.
   *
   * Excludes: 0, O, 1, I, l to avoid confusion.
   */
  public const DEFAULT_CHARSET = 'BCDFGHJKLMNPQRSTVWXYZ23456789';

  /**
   * Maximum attempts to generate a unique user code.
   */
  public const MAX_GENERATION_ATTEMPTS = 10;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The device code repository.
   *
   * @var \Drupal\simple_oauth_device_flow\Repository\DeviceCodeRepository
   */
  protected DeviceCodeRepository $deviceCodeRepository;

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a UserCodeGenerator service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\simple_oauth_device_flow\Repository\DeviceCodeRepository $deviceCodeRepository
   *   The device code repository for uniqueness checks.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    DeviceCodeRepository $deviceCodeRepository,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->configFactory = $configFactory;
    $this->deviceCodeRepository = $deviceCodeRepository;
    $this->logger = $loggerFactory->get('simple_oauth_device_flow');
  }

  /**
   * Generates a unique, cryptographically secure user code.
   *
   * @return string
   *   The generated user code in formatted form (e.g., 'ABCD-EFGH').
   *
   * @throws \RuntimeException
   *   When unable to generate a unique code after maximum attempts.
   */
  public function generateUserCode(): string {
    $attempts = 0;

    while ($attempts < self::MAX_GENERATION_ATTEMPTS) {
      $attempts++;

      try {
        // Generate raw code.
        $rawCode = $this->generateRawUserCode();

        // Format the code.
        $formattedCode = $this->formatUserCode($rawCode);

        // Check uniqueness.
        if ($this->isUserCodeUnique($formattedCode)) {
          $this->logger->info('User code generated successfully in @attempts attempts', [
            '@attempts' => $attempts,
          ]);
          return $formattedCode;
        }

        $this->logger->debug('User code collision on attempt @attempt', [
          '@attempt' => $attempts,
        ]);
      }
      catch (\Exception $e) {
        $this->logger->error('Error generating user code on attempt @attempt: @message', [
          '@attempt' => $attempts,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $this->logger->error('Failed to generate unique user code after @attempts attempts', [
      '@attempts' => self::MAX_GENERATION_ATTEMPTS,
    ]);

    throw new \RuntimeException('Unable to generate unique user code after maximum attempts');
  }

  /**
   * Validates the format of a user code.
   *
   * @param string $userCode
   *   The user code to validate.
   * @param bool $allowUnformatted
   *   Whether to allow unformatted codes (default: TRUE).
   *
   * @return bool
   *   TRUE if the code format is valid, FALSE otherwise.
   */
  public function validateCodeFormat(string $userCode, bool $allowUnformatted = TRUE): bool {
    if (empty($userCode)) {
      return FALSE;
    }

    $config = $this->getConfig();
    $length = $config->get('user_code_length') ?? 8;
    $charset = $this->getCharset();

    // Normalize the code for validation.
    $normalizedCode = $this->normalizeUserCode($userCode);

    // Check length.
    if (strlen($normalizedCode) !== $length) {
      return FALSE;
    }

    // Check character set.
    $charsetPattern = '/^[' . preg_quote($charset, '/') . ']+$/';
    if (!preg_match($charsetPattern, $normalizedCode)) {
      return FALSE;
    }

    // If we don't allow unformatted codes, check the format pattern.
    if (!$allowUnformatted) {
      $formatPattern = $this->getFormatPattern();
      if (!preg_match($formatPattern, $userCode)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Normalizes a user code by removing formatting characters.
   *
   * @param string $userCode
   *   The user code to normalize.
   *
   * @return string
   *   The normalized user code without formatting.
   */
  public function normalizeUserCode(string $userCode): string {
    // Convert to uppercase first, then remove non-alphanumeric characters.
    $uppercase = strtoupper($userCode);
    $normalized = preg_replace('/[^A-Z0-9]/', '', $uppercase);
    return $normalized;
  }

  /**
   * Formats a raw user code according to configuration.
   *
   * @param string $rawCode
   *   The raw user code to format.
   *
   * @return string
   *   The formatted user code.
   */
  public function formatUserCode(string $rawCode): string {
    $config = $this->getConfig();
    $format = $config->get('user_code_format') ?? 'XXXX-XXXX';

    // For XXXX-XXXX format with 8-character codes.
    if ($format === 'XXXX-XXXX' && strlen($rawCode) === 8) {
      return substr($rawCode, 0, 4) . '-' . substr($rawCode, 4, 4);
    }

    // For other lengths, split in half with a dash.
    $length = strlen($rawCode);
    if ($length >= 4 && $length % 2 === 0) {
      $half = $length / 2;
      return substr($rawCode, 0, $half) . '-' . substr($rawCode, $half);
    }

    // Return as-is if we can't format it nicely.
    return $rawCode;
  }

  /**
   * Generates a raw (unformatted) user code.
   *
   * @return string
   *   The raw user code without formatting.
   *
   * @throws \RuntimeException
   *   When unable to generate cryptographically secure random bytes.
   */
  protected function generateRawUserCode(): string {
    $config = $this->getConfig();
    $length = $config->get('user_code_length') ?? 8;
    $charset = $this->getCharset();
    $charsetLength = strlen($charset);

    if ($charsetLength === 0) {
      throw new \RuntimeException('Character set cannot be empty');
    }

    try {
      // Generate cryptographically secure random bytes.
      $randomBytes = random_bytes($length);
    }
    catch (\Exception $e) {
      throw new \RuntimeException('Failed to generate cryptographically secure random bytes: ' . $e->getMessage(), 0, $e);
    }

    $code = '';
    for ($i = 0; $i < $length; $i++) {
      // Convert byte to index in charset.
      $index = ord($randomBytes[$i]) % $charsetLength;
      $code .= $charset[$index];
    }

    return $code;
  }

  /**
   * Checks if a user code is unique (not already in use).
   *
   * @param string $userCode
   *   The user code to check.
   *
   * @return bool
   *   TRUE if the code is unique, FALSE otherwise.
   */
  protected function isUserCodeUnique(string $userCode): bool {
    try {
      $existingEntity = $this->deviceCodeRepository->getDeviceCodeEntityByUserCode($userCode);
      return $existingEntity === NULL;
    }
    catch (\Exception $e) {
      $this->logger->error('Error checking user code uniqueness: @message', [
        '@message' => $e->getMessage(),
      ]);
      // Assume not unique on error for safety.
      return FALSE;
    }
  }

  /**
   * Gets the character set for user code generation.
   *
   * @return string
   *   The character set to use.
   */
  protected function getCharset(): string {
    $config = $this->getConfig();
    $charset = $config->get('user_code_charset');

    if (empty($charset)) {
      // Build charset by excluding specified characters.
      $excludedChars = $config->get('user_code_excluded_chars') ?? '01OILO';
      $allChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
      $charset = '';

      for ($i = 0; $i < strlen($allChars); $i++) {
        $char = $allChars[$i];
        if (strpos($excludedChars, $char) === FALSE) {
          $charset .= $char;
        }
      }

      // Fallback to default if empty.
      if (empty($charset)) {
        $charset = self::DEFAULT_CHARSET;
      }
    }

    return $charset;
  }

  /**
   * Gets the format pattern for user code validation.
   *
   * @return string
   *   The regex pattern for format validation.
   */
  protected function getFormatPattern(): string {
    $config = $this->getConfig();
    $format = $config->get('user_code_format') ?? 'XXXX-XXXX';
    $charset = $this->getCharset();

    // Escape charset for regex.
    $charsetPattern = preg_quote($charset, '/');

    // Convert format pattern to regex.
    // Replace X with character class and keep other characters literal.
    $pattern = '/^' . str_replace('X', '[' . $charsetPattern . ']', preg_quote($format, '/')) . '$/';

    return $pattern;
  }

  /**
   * Gets the device flow configuration.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The device flow configuration.
   */
  protected function getConfig() {
    return $this->configFactory->get('simple_oauth_device_flow.settings');
  }

}
