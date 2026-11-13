<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\simple_oauth_device_flow\Repository\UserCodeLookupInterface;
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
   * @var \Drupal\simple_oauth_device_flow\Repository\UserCodeLookupInterface
   */
  protected UserCodeLookupInterface $deviceCodeRepository;

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
   * @param \Drupal\simple_oauth_device_flow\Repository\UserCodeLookupInterface $deviceCodeRepository
   *   The device code repository for uniqueness checks.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    UserCodeLookupInterface $deviceCodeRepository,
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
   * @throws \UnexpectedValueException
   *   When effective settings violate the issuance safety policy.
   * @throws \RuntimeException
   *   When unable to generate a unique code after maximum attempts.
   */
  public function generateUserCode(): string {
    // Enforce policy even when subclasses override raw generation.
    DeviceFlowSettingsService::resolveGenerationSettings($this->getConfig()->get());
    $attempts = 0;

    while ($attempts < self::MAX_GENERATION_ATTEMPTS) {
      $attempts++;

      try {
        // Generate raw code.
        $rawCode = $this->generateRawUserCode();

        // Format the code.
        $formattedCode = $this->formatUserCode($rawCode);

        // Keep formatter and raw-code extensions within the configured shape.
        if (!$this->validateCodeFormat($formattedCode)) {
          throw new \RuntimeException('Generated user code format is invalid.');
        }

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
        $this->logger->error('Error generating user code on attempt @attempt: @exception_type', [
          '@attempt' => $attempts,
          '@exception_type' => get_class($e),
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
   * Builds bounded exact lookup candidates independent of issuance settings.
   *
   * @param string $userCode
   *   Raw user input, bounded before trimming or case normalization.
   *
   * @return string[]
   *   At most four exact layouts: raw, middle dash, submitted and formatter
   *   output. No candidates are returned for empty or oversized input.
   */
  public function getVerificationCandidates(string $userCode): array {
    // Storage permits 32 characters, each occupying at most four UTF-8 bytes.
    if (strlen($userCode) > 128 || mb_strlen($userCode, 'UTF-8') > 32) {
      return [];
    }
    $code = strtoupper(trim($userCode));
    // Preserve bounded separator normalization and public formatter extensions.
    $raw = $this->normalizeUserCode($code);
    if (!preg_match('/^[A-Z0-9]+$/D', $raw) || strlen($raw) > 32) {
      return [];
    }
    $length = strlen($raw);
    $candidates = [$raw];
    if ($length >= 4 && $length % 2 === 0 && $length < 32) {
      $half = intdiv($length, 2);
      $candidates[] = substr($raw, 0, $half) . '-' . substr($raw, $half);
    }
    // Exact historical formatting remains usable after formatter replacement.
    $candidates[] = $code;
    try {
      $formatted = $this->formatUserCode($raw);
      if (strlen($formatted) <= 128 && mb_strlen($formatted, 'UTF-8') <= 32
        && $this->normalizeUserCode($formatted) === $raw) {
        $candidates[] = $formatted;
      }
    }
    catch (\Throwable) {
      // Optional formatter hints cannot disable lookup of stored pending codes.
    }
    return array_values(array_unique($candidates));
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
   *   When cryptographically secure sampling fails.
   */
  protected function generateRawUserCode(): string {
    $data = $this->getConfig()->get();
    $settings = DeviceFlowSettingsService::resolveGenerationSettings($data);
    // Preserve the protected charset extension point under the same policy.
    $charset = $this->getCharset();
    if ($charset !== $settings['charset']) {
      $data['user_code_charset'] = $charset;
      $settings = DeviceFlowSettingsService::resolveGenerationSettings($data);
    }
    $length = $settings['length'];
    $charset = $settings['charset'];
    $charsetLength = strlen($charset);
    $code = '';
    try {
      for ($i = 0; $i < $length; $i++) {
        // random_int() samples uniformly, including non-power-of-two alphabets.
        $code .= $charset[random_int(0, $charsetLength - 1)];
      }
    }
    catch (\Exception $exception) {
      throw new \RuntimeException('Failed to generate a cryptographically secure user code.', 0, $exception);
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
      $this->logger->error('Error checking user code uniqueness: @exception_type', [
        '@exception_type' => get_class($e),
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
