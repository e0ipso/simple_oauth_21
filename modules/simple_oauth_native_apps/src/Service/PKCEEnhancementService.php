<?php

namespace Drupal\simple_oauth_native_apps\Service;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for enhanced PKCE validation for native OAuth clients.
 *
 * Implements RFC 8252 enhanced PKCE requirements including mandatory S256
 * method, entropy validation, and native client specific security checks.
 */
class PKCEEnhancementService {

  /**
   * Minimum entropy bits for code verifiers.
   */
  const MINIMUM_ENTROPY_BITS = 128;

  /**
   * Minimum code verifier length (RFC 7636).
   */
  const MINIMUM_VERIFIER_LENGTH = 43;

  /**
   * Maximum code verifier length (RFC 7636).
   */
  const MAXIMUM_VERIFIER_LENGTH = 128;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The native client detector service.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\NativeClientDetector
   */
  protected NativeClientDetector $nativeClientDetector;

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Cache for validation results.
   *
   * @var array
   */
  protected array $validationCache = [];

  /**
   * Constructs a PKCEEnhancementService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\simple_oauth_native_apps\Service\NativeClientDetector $native_client_detector
   *   The native client detector service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    NativeClientDetector $native_client_detector,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->configFactory = $config_factory;
    $this->nativeClientDetector = $native_client_detector;
    $this->logger = $logger_factory->get('simple_oauth_native_apps');
  }

  /**
   * Validates PKCE parameters with enhanced security for native clients.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The OAuth client.
   * @param string|null $code_challenge
   *   The code challenge parameter.
   * @param string|null $code_challenge_method
   *   The code challenge method.
   * @param string|null $code_verifier
   *   The code verifier (for token requests).
   *
   * @return array
   *   Validation result with 'valid' boolean and 'errors' array.
   */
  public function validatePkceParameters(
    Consumer $client,
    ?string $code_challenge = NULL,
    ?string $code_challenge_method = NULL,
    ?string $code_verifier = NULL,
  ): array {
    $is_native = $this->nativeClientDetector->isNativeClient($client);
    $requires_enhanced = $this->nativeClientDetector->requiresEnhancedPkce($client);

    // If enhanced PKCE is not required, use standard validation.
    if (!$requires_enhanced) {
      return $this->validateStandardPkce($client, $code_challenge, $code_challenge_method, $code_verifier);
    }

    // Enhanced validation for native clients.
    $validation_result = $this->performEnhancedValidation(
      $client,
      $code_challenge,
      $code_challenge_method,
      $code_verifier,
      $is_native
    );

    // Log validation results for debugging purposes.
    $this->logValidationResult($client, $validation_result);

    return $validation_result;
  }

  /**
   * Checks if S256 method is required for the client.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The OAuth client.
   *
   * @return bool
   *   TRUE if S256 is required.
   */
  public function requiresS256Method(Consumer $client): bool {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');

    // Check global enforcement setting.
    if (!$config->get('native.enhanced_pkce')) {
      return FALSE;
    }

    // Native clients require S256.
    return $this->nativeClientDetector->isNativeClient($client);
  }

  /**
   * Validates code verifier entropy requirements.
   *
   * @param string $code_verifier
   *   The code verifier to validate.
   *
   * @return array
   *   Validation result with entropy information.
   */
  public function validateCodeVerifierEntropy(string $code_verifier): array {
    $result = [
      'valid' => TRUE,
      'entropy_bits' => 0,
      'meets_minimum' => FALSE,
      'errors' => [],
    ];

    // Validate format first.
    if (!$this->isValidVerifierFormat($code_verifier)) {
      $result['valid'] = FALSE;
      $result['errors'][] = 'Invalid code verifier format';
      return $result;
    }

    // Calculate entropy.
    $entropy_bits = $this->calculateEntropy($code_verifier);
    $result['entropy_bits'] = $entropy_bits;
    $result['meets_minimum'] = $entropy_bits >= self::MINIMUM_ENTROPY_BITS;

    if (!$result['meets_minimum']) {
      $result['valid'] = FALSE;
      $result['errors'][] = sprintf(
        'Code verifier entropy (%d bits) below minimum requirement (%d bits)',
        $entropy_bits,
        self::MINIMUM_ENTROPY_BITS
      );
    }

    return $result;
  }

  /**
   * Validates challenge method for native clients.
   *
   * @param string $method
   *   The challenge method.
   * @param bool $is_native
   *   Whether the client is native.
   *
   * @return array
   *   Validation result.
   */
  public function validateChallengeMethod(string $method, bool $is_native): array {
    $result = [
      'valid' => TRUE,
      'errors' => [],
    ];

    $config = $this->configFactory->get('simple_oauth_native_apps.settings');

    // Check if method is supported.
    if (!in_array($method, ['S256', 'plain'], TRUE)) {
      $result['valid'] = FALSE;
      $result['errors'][] = "Unsupported challenge method: {$method}";
      return $result;
    }

    // Native clients must use S256 if enforcement is enabled.
    $enforced_method = $config->get('native.enforce', 'off');

    // Defensive programming: validate config value before use.
    if (empty($enforced_method) || !in_array($enforced_method, ['off', 'S256', 'plain'], TRUE)) {
      // Fallback to safe default if config is invalid/missing.
      $enforced_method = 'S256';
      $this->logger->warning('Invalid or missing native.enforce configuration, falling back to S256');
    }

    if ($is_native && $enforced_method !== 'off' && $method !== $enforced_method) {
      $result['valid'] = FALSE;
      $result['errors'][] = "Native clients must use {$enforced_method} challenge method";
      return $result;
    }

    // Warn about plain method for native clients.
    if ($is_native && $method === 'plain') {
      $result['warnings'][] = 'Plain method is not recommended for native clients';
    }

    return $result;
  }

  /**
   * Performs enhanced PKCE validation for native clients.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The OAuth client.
   * @param string|null $code_challenge
   *   The code challenge.
   * @param string|null $code_challenge_method
   *   The challenge method.
   * @param string|null $code_verifier
   *   The code verifier.
   * @param bool $is_native
   *   Whether client is native.
   *
   * @return array
   *   Enhanced validation result.
   */
  protected function performEnhancedValidation(
    Consumer $client,
    ?string $code_challenge,
    ?string $code_challenge_method,
    ?string $code_verifier,
    bool $is_native,
  ): array {
    $result = [
      'valid' => TRUE,
      'errors' => [],
      'warnings' => [],
      'enhanced_applied' => TRUE,
    ];

    // PKCE is mandatory for native clients.
    if ($is_native && empty($code_challenge) && empty($code_verifier)) {
      $result['valid'] = FALSE;
      $result['errors'][] = 'PKCE parameters are mandatory for native clients';
      return $result;
    }

    // Validate challenge method if provided.
    if ($code_challenge_method) {
      $method_validation = $this->validateChallengeMethod($code_challenge_method, $is_native);
      if (!$method_validation['valid']) {
        $result['valid'] = FALSE;
        $result['errors'] = array_merge($result['errors'], $method_validation['errors']);
      }
      if (isset($method_validation['warnings'])) {
        $result['warnings'] = array_merge($result['warnings'], $method_validation['warnings']);
      }
    }

    // Validate challenge format if provided.
    if ($code_challenge && !$this->isValidChallengeFormat($code_challenge)) {
      $result['valid'] = FALSE;
      $result['errors'][] = 'Invalid code challenge format';
    }

    // Validate verifier entropy if provided.
    if ($code_verifier) {
      $entropy_validation = $this->validateCodeVerifierEntropy($code_verifier);
      if (!$entropy_validation['valid']) {
        $result['valid'] = FALSE;
        $result['errors'] = array_merge($result['errors'], $entropy_validation['errors']);
      }
    }

    // Validate challenge-verifier pair if both present.
    if ($code_challenge && $code_verifier && $code_challenge_method) {
      if (!$this->validateChallengeVerifierPair($code_challenge, $code_verifier, $code_challenge_method)) {
        $result['valid'] = FALSE;
        $result['errors'][] = 'Code challenge does not match verifier';
      }
    }

    return $result;
  }

  /**
   * Validates standard PKCE parameters (non-enhanced).
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The OAuth client.
   * @param string|null $code_challenge
   *   The code challenge.
   * @param string|null $code_challenge_method
   *   The challenge method.
   * @param string|null $code_verifier
   *   The code verifier.
   *
   * @return array
   *   Validation result.
   */
  protected function validateStandardPkce(
    Consumer $client,
    ?string $code_challenge,
    ?string $code_challenge_method,
    ?string $code_verifier,
  ): array {
    $result = [
      'valid' => TRUE,
      'errors' => [],
      'warnings' => [],
      'enhanced_applied' => FALSE,
    ];

    // Basic format validation.
    if ($code_challenge && !$this->isValidChallengeFormat($code_challenge)) {
      $result['valid'] = FALSE;
      $result['errors'][] = 'Invalid code challenge format';
    }

    if ($code_verifier && !$this->isValidVerifierFormat($code_verifier)) {
      $result['valid'] = FALSE;
      $result['errors'][] = 'Invalid code verifier format';
    }

    if ($code_challenge_method && !in_array($code_challenge_method, ['S256', 'plain'], TRUE)) {
      $result['valid'] = FALSE;
      $result['errors'][] = "Unsupported challenge method: {$code_challenge_method}";
    }

    return $result;
  }

  /**
   * Validates challenge format according to RFC 7636.
   *
   * @param string $challenge
   *   The challenge to validate.
   *
   * @return bool
   *   TRUE if valid format.
   */
  protected function isValidChallengeFormat(string $challenge): bool {
    $length = strlen($challenge);
    if ($length < self::MINIMUM_VERIFIER_LENGTH || $length > self::MAXIMUM_VERIFIER_LENGTH) {
      return FALSE;
    }

    // Challenge must use base64url encoding without padding.
    return preg_match('/^[A-Za-z0-9_-]+$/', $challenge) === 1;
  }

  /**
   * Validates verifier format according to RFC 7636.
   *
   * @param string $verifier
   *   The verifier to validate.
   *
   * @return bool
   *   TRUE if valid format.
   */
  protected function isValidVerifierFormat(string $verifier): bool {
    $length = strlen($verifier);
    if ($length < self::MINIMUM_VERIFIER_LENGTH || $length > self::MAXIMUM_VERIFIER_LENGTH) {
      return FALSE;
    }

    // Verifier can use unreserved characters.
    return preg_match('/^[A-Za-z0-9_~.-]+$/', $verifier) === 1;
  }

  /**
   * Validates challenge-verifier pair for given method.
   *
   * @param string $challenge
   *   The code challenge.
   * @param string $verifier
   *   The code verifier.
   * @param string $method
   *   The challenge method.
   *
   * @return bool
   *   TRUE if pair is valid.
   */
  protected function validateChallengeVerifierPair(string $challenge, string $verifier, string $method): bool {
    if ($method === 'S256') {
      $expected_challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, TRUE)), '+/', '-_'), '=');
      return hash_equals($challenge, $expected_challenge);
    }

    if ($method === 'plain') {
      return $challenge === $verifier;
    }

    return FALSE;
  }

  /**
   * Calculates entropy of a string.
   *
   * @param string $string
   *   The string to analyze.
   *
   * @return float
   *   Entropy in bits.
   */
  protected function calculateEntropy(string $string): float {
    $length = strlen($string);
    if ($length === 0) {
      return 0.0;
    }

    // Count character frequencies.
    $frequencies = array_count_values(str_split($string));
    $entropy = 0.0;

    foreach ($frequencies as $frequency) {
      $probability = $frequency / $length;
      $entropy -= $probability * log($probability, 2);
    }

    // Return total entropy (bits per character * length)
    return $entropy * $length;
  }

  /**
   * Logs validation results.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The OAuth client.
   * @param array $result
   *   The validation result.
   */
  protected function logValidationResult(Consumer $client, array $result): void {
    $level = $result['valid'] ? 'info' : 'warning';
    $status = $result['valid'] ? 'VALID' : 'INVALID';
    $enhanced = $result['enhanced_applied'] ? 'enhanced' : 'standard';

    $message = "PKCE validation ({$enhanced}) for client @client_id: @status";
    $context = [
      '@client_id' => $client->getClientId(),
      '@status' => $status,
    ];

    if (!empty($result['errors'])) {
      $message .= ', errors: @errors';
      $context['@errors'] = implode(', ', $result['errors']);
    }

    if (!empty($result['warnings'])) {
      $message .= ', warnings: @warnings';
      $context['@warnings'] = implode(', ', $result['warnings']);
    }

    $this->logger->log($level, $message, $context);
  }

  /**
   * Clears the validation cache.
   */
  public function clearCache(): void {
    $this->validationCache = [];
  }

  /**
   * Gets service configuration.
   *
   * @return array
   *   Current service configuration.
   */
  public function getConfiguration(): array {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');

    return [
      'enhanced_pkce_enabled' => (bool) ($config->get('native.enhanced_pkce') ?? TRUE),
      'enforce_method' => $config->get('native.enforce', 'off'),
      'minimum_entropy_bits' => self::MINIMUM_ENTROPY_BITS,
      'log_validations' => FALSE,
    ];
  }

}
