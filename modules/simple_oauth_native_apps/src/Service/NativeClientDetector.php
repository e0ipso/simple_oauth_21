<?php

namespace Drupal\simple_oauth_native_apps\Service;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for detecting and classifying native OAuth clients.
 *
 * Implements RFC 8252 native client detection based on redirect URI patterns,
 * grant type usage, and client configuration flags.
 */
class NativeClientDetector {

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Cache for detection results.
   *
   * @var array
   */
  protected array $detectionCache = [];

  /**
   * Native client URI scheme patterns.
   *
   * @var array
   */
  protected array $nativeUriPatterns = [
    // Custom URI schemes (excluding common web schemes)
    'custom_scheme' => '/^(?!https?:\/\/)[a-z][a-z0-9+.-]*:\/\/.+$/i',
    // Mobile deep links.
    'mobile_deep_link' => '/^(app|myapp|[a-z]+-[a-z]+):\/\/.+$/i',
    // Platform-specific schemes.
    'platform_scheme' => '/^(ms-app|x-app-id|intent):\/\/.+$/i',
  ];

  /**
   * Loopback interface patterns.
   *
   * @var array
   */
  protected array $loopbackPatterns = [
    // IPv4 loopback.
    'ipv4_loopback' => '/^https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?(\/.*)?$/i',
    // IPv6 loopback.
    'ipv6_loopback' => '/^https?:\/\/\[::1\](:\d+)?(\/.*)?$/i',
  ];

  /**
   * Web client patterns.
   *
   * @var array
   */
  protected array $webUriPatterns = [
    // HTTPS with domain.
    'https_domain' => '/^https:\/\/[a-z0-9.-]+(:\d+)?(\/.*)?$/i',
    // HTTP with domain (not localhost)
    'http_domain' => '/^http:\/\/(?!127\.0\.0\.1|localhost|\[::1\])[a-z0-9.-]+(:\d+)?(\/.*)?$/i',
  ];

  /**
   * Constructs a NativeClientDetector object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $logger_factory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->logger = $logger_factory->get('simple_oauth_native_apps');
  }

  /**
   * Determines if a consumer is a native client.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity to analyze.
   *
   * @return bool
   *   TRUE if the client is classified as native.
   */
  public function isNativeClient(Consumer $client): bool {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');
    $threshold = $config->get('detection_sensitivity') ?? 0.7;

    $score = $this->calculateNativeScore($client);
    $is_native = $score >= $threshold;

    // Log detection decision if enabled.
    if ($config->get('log.detection_decisions')) {
      $reasons = $this->getClassificationReasons($client);
      $this->logger->info('Native client detection for @client_id: score=@score, threshold=@threshold, result=@result, reasons=@reasons', [
        '@client_id' => $client->getClientId(),
        '@score' => round($score, 3),
        '@threshold' => $threshold,
        '@result' => $is_native ? 'NATIVE' : 'WEB',
        '@reasons' => implode(', ', $reasons),
      ]);
    }

    return $is_native;
  }

  /**
   * Analyzes redirect URIs to detect native patterns.
   *
   * @param array $redirectUris
   *   Array of redirect URI values.
   *
   * @return bool
   *   TRUE if native patterns detected.
   */
  public function detectNativeFromRedirectUris(array $redirectUris): bool {
    if (empty($redirectUris)) {
      return FALSE;
    }

    $native_count = 0;
    $total_count = count($redirectUris);

    foreach ($redirectUris as $uri) {
      if ($this->isNativeRedirectUri($uri)) {
        $native_count++;
      }
    }

    // Return TRUE if majority of URIs are native.
    return ($native_count / $total_count) >= 0.5;
  }

  /**
   * Checks if client requires enhanced PKCE validation.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   *
   * @return bool
   *   TRUE if enhanced PKCE is required.
   */
  public function requiresEnhancedPkce(Consumer $client): bool {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');

    // Get global setting: 'auto-detect', 'enhanced', or 'not-enhanced'.
    $global_setting = $config->get('native.enhanced_pkce') ?? 'auto-detect';

    // Defensive programming: validate config value before use.
    if (empty($global_setting) || !in_array($global_setting, ['auto-detect', 'enhanced', 'not-enhanced'], TRUE)) {
      // Fallback to safe default if config is invalid/missing.
      $global_setting = 'auto-detect';
      $this->logger->warning('Invalid or missing native.enhanced_pkce configuration, falling back to auto-detect');
    }

    // If globally disabled, return FALSE unless overridden.
    if ($global_setting === 'not-enhanced') {
      // Still check for consumer override.
      $consumer_override = $this->getConsumerEnhancedPkceOverride($client);
      if ($consumer_override === 'enhanced') {
        return TRUE;
      }
      elseif ($consumer_override === 'auto-detect') {
        return $this->isNativeClient($client);
      }
      return FALSE;
    }

    // If globally enabled, return TRUE unless overridden.
    if ($global_setting === 'enhanced') {
      $consumer_override = $this->getConsumerEnhancedPkceOverride($client);
      if ($consumer_override === 'not-enhanced') {
        return FALSE;
      }
      elseif ($consumer_override === 'auto-detect') {
        return $this->isNativeClient($client);
      }
      return TRUE;
    }

    // If global setting is 'auto-detect' or any other value.
    $consumer_override = $this->getConsumerEnhancedPkceOverride($client);
    if ($consumer_override === 'enhanced') {
      return TRUE;
    }
    elseif ($consumer_override === 'not-enhanced') {
      return FALSE;
    }

    // Default: auto-detect based on client type.
    return $this->isNativeClient($client);
  }

  /**
   * Gets the consumer-specific enhanced PKCE override setting.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   *
   * @return string|null
   *   The override value or NULL if not set.
   */
  protected function getConsumerEnhancedPkceOverride(Consumer $client): ?string {
    $consumer_id = $client->id();
    if (!$consumer_id) {
      return NULL;
    }

    $consumer_config = $this->configFactory->get("simple_oauth_native_apps.consumer.$consumer_id");
    return $consumer_config->get('enhanced_pkce_override') ?? NULL;
  }

  /**
   * Resolves whether custom URI schemes are allowed for a consumer.
   *
   * @param object $config
   *   The global config object.
   * @param \Drupal\consumers\Entity\Consumer|null $client
   *   The consumer entity (optional).
   *
   * @return bool
   *   TRUE if custom URI schemes are allowed.
   */
  protected function resolveCustomUriSchemesAllowed($config, ?Consumer $client = NULL): bool {
    $global_setting = $config->get('allow.custom_uri_schemes') ?? 'auto-detect';

    // Check for consumer override if client provided.
    if ($client) {
      $consumer_override = $this->getConsumerCustomUriSchemesOverride($client);
      if ($consumer_override) {
        return $this->resolveBooleanFromEnum($consumer_override, $client);
      }
    }

    // Use global setting.
    return $this->resolveBooleanFromEnum($global_setting, $client);
  }

  /**
   * Resolves whether loopback redirects are allowed for a consumer.
   *
   * @param object $config
   *   The global config object.
   * @param \Drupal\consumers\Entity\Consumer|null $client
   *   The consumer entity (optional).
   *
   * @return bool
   *   TRUE if loopback redirects are allowed.
   */
  protected function resolveLoopbackRedirectsAllowed($config, ?Consumer $client = NULL): bool {
    $global_setting = $config->get('allow.loopback_redirects') ?? 'auto-detect';

    // Check for consumer override if client provided.
    if ($client) {
      $consumer_override = $this->getConsumerLoopbackRedirectsOverride($client);
      if ($consumer_override) {
        return $this->resolveBooleanFromEnum($consumer_override, $client);
      }
    }

    // Use global setting.
    return $this->resolveBooleanFromEnum($global_setting, $client);
  }

  /**
   * Gets the consumer-specific custom URI schemes override setting.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   *
   * @return string|null
   *   The override value or NULL if not set.
   */
  protected function getConsumerCustomUriSchemesOverride(Consumer $client): ?string {
    $consumer_id = $client->id();
    if (!$consumer_id) {
      return NULL;
    }

    $consumer_config = $this->configFactory->get("simple_oauth_native_apps.consumer.$consumer_id");
    return $consumer_config->get('allow_custom_schemes_override') ?? NULL;
  }

  /**
   * Gets the consumer-specific loopback redirects override setting.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   *
   * @return string|null
   *   The override value or NULL if not set.
   */
  protected function getConsumerLoopbackRedirectsOverride(Consumer $client): ?string {
    $consumer_id = $client->id();
    if (!$consumer_id) {
      return NULL;
    }

    $consumer_config = $this->configFactory->get("simple_oauth_native_apps.consumer.$consumer_id");
    return $consumer_config->get('allow_loopback_override') ?? NULL;
  }

  /**
   * Resolves a boolean value from an enum setting.
   *
   * @param string $enum_value
   *   The enum value ('auto-detect', 'native', 'web').
   * @param \Drupal\consumers\Entity\Consumer|null $client
   *   The consumer entity (for auto-detect).
   *
   * @return bool
   *   The resolved boolean value.
   */
  protected function resolveBooleanFromEnum(string $enum_value, ?Consumer $client = NULL): bool {
    switch ($enum_value) {
      case 'native':
        return TRUE;

      case 'web':
        return FALSE;

      case 'auto-detect':
      default:
        // Auto-detect based on client type.
        return $client ? $this->isNativeClient($client) : TRUE;
    }
  }

  /**
   * Gets the confidence level of native client detection.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   *
   * @return float
   *   Confidence level (0.0 to 1.0).
   */
  public function getDetectionConfidence(Consumer $client): float {
    return $this->calculateNativeScore($client);
  }

  /**
   * Provides reasons for native client classification.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   *
   * @return array
   *   Array of classification reasons.
   */
  public function getClassificationReasons(Consumer $client): array {
    $reasons = [];

    // Check manual override.
    if ($client->hasField('native_app_override')) {
      $override = $client->get('native_app_override')->value;
      // Only add reason if explicitly set (not empty or 'auto-detect')
      if ($override === 'native') {
        $reasons[] = 'Manual override: marked as native';
      }
      elseif ($override === 'web') {
        $reasons[] = 'Manual override: marked as web';
      }
      // If empty or 'auto-detect', no manual override reason.
    }

    // Analyze redirect URIs.
    $uri_analysis = $this->analyzeRedirectUris($client);

    if ($uri_analysis > 0.7) {
      $reasons[] = 'Strong native URI patterns detected';
    }
    elseif ($uri_analysis > 0.3) {
      $reasons[] = 'Some native URI patterns detected';
    }
    else {
      $reasons[] = 'Web-based URI patterns detected';
    }

    // Analyze client configuration.
    $is_confidential = $client->get('confidential')->value ?? TRUE;
    if (!$is_confidential) {
      $reasons[] = 'Public client (typical for native apps)';
    }
    else {
      $reasons[] = 'Confidential client (typical for web apps)';
    }

    // Analyze third-party status.
    $is_third_party = $client->get('third_party')->value ?? FALSE;
    if ($is_third_party) {
      $reasons[] = 'Third-party client';
    }

    return $reasons;
  }

  /**
   * Calculates the native client detection score.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   *
   * @return float
   *   Score between 0.0 and 1.0.
   */
  protected function calculateNativeScore(Consumer $client): float {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');

    // Check cache first if enabled.
    if ($config->get('cache_detection_results')) {
      $cache_key = 'native_detection_' . $client->id();
      if (isset($this->detectionCache[$cache_key])) {
        return $this->detectionCache[$cache_key];
      }
    }

    $score = 0.0;

    // Check manual override first (100% weight if set)
    if ($client->hasField('native_app_override')) {
      $override = $client->get('native_app_override')->value;
      // Only apply override if explicitly set to 'native' or 'web'.
      if ($override === 'native') {
        $score = 1.0;
      }
      elseif ($override === 'web') {
        $score = 0.0;
      }
      else {
        // Empty, 'auto-detect', or any other value: use algorithmic detection.
        $score = $this->calculateAlgorithmicScore($client);
      }
    }
    else {
      // No override field, use algorithmic detection.
      $score = $this->calculateAlgorithmicScore($client);
    }

    // Cache result if enabled.
    if ($config->get('cache_detection_results')) {
      $this->detectionCache[$cache_key] = $score;
    }

    return $score;
  }

  /**
   * Calculates the algorithmic detection score.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   *
   * @return float
   *   Score between 0.0 and 1.0.
   */
  protected function calculateAlgorithmicScore(Consumer $client): float {
    $score = 0.0;

    // Redirect URI analysis (40% weight)
    $redirect_score = $this->analyzeRedirectUris($client);
    $score += $redirect_score * 0.4;

    // Grant type analysis (30% weight)
    $grant_score = $this->analyzeGrantTypes($client);
    $score += $grant_score * 0.3;

    // Client configuration (30% weight)
    $config_score = $this->analyzeClientConfig($client);
    $score += $config_score * 0.3;

    return min($score, 1.0);
  }

  /**
   * Analyzes redirect URIs for native patterns.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   *
   * @return float
   *   Score between 0.0 and 1.0.
   */
  protected function analyzeRedirectUris(Consumer $client): float {
    $redirect_uris = $this->getRedirectUris($client);

    if (empty($redirect_uris)) {
      return 0.0;
    }

    $native_score = 0.0;
    $web_score = 0.0;

    foreach ($redirect_uris as $uri) {
      if ($this->isNativeRedirectUri($uri)) {
        $native_score += 1.0;
      }
      elseif ($this->isWebRedirectUri($uri)) {
        $web_score += 1.0;
      }
      // Unrecognized patterns get neutral score.
    }

    // Calculate ratio favoring native URIs.
    if ($native_score > 0 && $web_score === 0.0) {
      // All native URIs.
      return 1.0;
    }
    elseif ($native_score === 0.0 && $web_score > 0) {
      // All web URIs.
      return 0.0;
    }
    elseif ($native_score + $web_score === 0.0) {
      // Unrecognized patterns, neutral.
      return 0.5;
    }
    else {
      // Mixed patterns, calculate ratio.
      return $native_score / ($native_score + $web_score);
    }
  }

  /**
   * Analyzes grant types for native patterns.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   *
   * @return float
   *   Score between 0.0 and 1.0.
   */
  protected function analyzeGrantTypes(Consumer $client): float {
    $score = 0.0;

    // Check if client uses authorization code flow (typical for native)
    // Note: Simple OAuth doesn't store grant types per client by default,
    // so we use general indicators.
    // Public clients are more likely to be native.
    $is_confidential = $client->get('confidential')->value ?? TRUE;
    if (!$is_confidential) {
      // Public clients are often native.
      $score += 0.6;
    }

    // Check if PKCE is enabled/used (would need additional tracking)
    // For now, assume modern setup encourages PKCE for public clients.
    if (!$is_confidential) {
      // Assume PKCE usage for public clients.
      $score += 0.4;
    }

    return min($score, 1.0);
  }

  /**
   * Analyzes client configuration for native indicators.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   *
   * @return float
   *   Score between 0.0 and 1.0.
   */
  protected function analyzeClientConfig(Consumer $client): float {
    $score = 0.0;

    // Public client indicator (native apps typically can't keep secrets)
    $is_confidential = $client->get('confidential')->value ?? TRUE;
    if (!$is_confidential) {
      $score += 0.5;
    }

    // Third-party indicator (many native apps are third-party)
    $is_third_party = $client->get('third_party')->value ?? FALSE;
    if ($is_third_party) {
      $score += 0.3;
    }

    // Client name patterns (heuristic)
    $label = $client->label();
    if ($label && $this->hasNativeAppNamePattern($label)) {
      $score += 0.2;
    }

    return min($score, 1.0);
  }

  /**
   * Checks if a redirect URI matches native patterns.
   *
   * @param string $uri
   *   The redirect URI to check.
   *
   * @return bool
   *   TRUE if the URI matches native patterns.
   */
  protected function isNativeRedirectUri(string $uri): bool {
    // Check custom schemes.
    foreach ($this->nativeUriPatterns as $pattern) {
      if (preg_match($pattern, $uri)) {
        return TRUE;
      }
    }

    // Check loopback interfaces.
    foreach ($this->loopbackPatterns as $pattern) {
      if (preg_match($pattern, $uri)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if a redirect URI matches web patterns.
   *
   * @param string $uri
   *   The redirect URI to check.
   *
   * @return bool
   *   TRUE if the URI matches web patterns.
   */
  protected function isWebRedirectUri(string $uri): bool {
    foreach ($this->webUriPatterns as $pattern) {
      if (preg_match($pattern, $uri)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if client name suggests native app.
   *
   * @param string $name
   *   The client name.
   *
   * @return bool
   *   TRUE if name suggests native app.
   */
  protected function hasNativeAppNamePattern(string $name): bool {
    $native_keywords = [
      'mobile', 'app', 'ios', 'android', 'desktop', 'native',
      'mobile app', 'desktop app', 'phone', 'tablet',
    ];

    $name_lower = strtolower($name);
    foreach ($native_keywords as $keyword) {
      if (strpos($name_lower, $keyword) !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Gets redirect URIs from a consumer entity.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   *
   * @return array
   *   Array of redirect URI strings.
   */
  protected function getRedirectUris(Consumer $client): array {
    $redirect_uris = [];

    if ($client->hasField('redirect')) {
      foreach ($client->get('redirect')->getValue() as $redirect) {
        if (!empty($redirect['value'])) {
          $redirect_uris[] = $redirect['value'];
        }
      }
    }

    return $redirect_uris;
  }

  /**
   * Detects specific client type based on redirect URIs.
   *
   * @param array $redirect_uris
   *   Array of redirect URI strings.
   *
   * @return array
   *   Detection result with type, confidence, and details.
   */
  public function detectClientType(array $redirect_uris): array {
    if (empty($redirect_uris)) {
      return [
        'type' => 'web',
        'confidence' => 'low',
        'details' => ['reason' => 'No redirect URIs provided'],
      ];
    }

    $cid = 'native_client_detector:type:' . md5(serialize($redirect_uris));

    if (isset($this->detectionCache[$cid])) {
      return $this->detectionCache[$cid];
    }

    $result = $this->performClientTypeDetection($redirect_uris);
    $this->detectionCache[$cid] = $result;

    return $result;
  }

  /**
   * Performs the actual client type detection.
   *
   * @param array $redirect_uris
   *   Array of redirect URIs.
   *
   * @return array
   *   Detection result.
   */
  protected function performClientTypeDetection(array $redirect_uris): array {
    $detected_types = [];

    foreach ($redirect_uris as $uri) {
      $type = $this->classifyRedirectUri($uri);
      $detected_types[] = $type;
    }

    return $this->resolveClientType($detected_types, $redirect_uris);
  }

  /**
   * Classifies a single redirect URI.
   *
   * @param string $uri
   *   The redirect URI to classify.
   *
   * @return string
   *   The detected client type.
   */
  protected function classifyRedirectUri(string $uri): string {
    // Priority order: Terminal -> Mobile -> Desktop -> Web.
    if ($this->isTerminalRedirectUri($uri)) {
      return 'terminal';
    }

    if ($this->isMobileRedirectUri($uri)) {
      return 'mobile';
    }

    if ($this->isDesktopRedirectUri($uri)) {
      return 'desktop';
    }

    if ($this->isWebRedirectUri($uri)) {
      return 'web';
    }

    // Handle edge cases.
    return $this->handleEdgeCases($uri);
  }

  /**
   * Checks if URI is for a terminal application.
   *
   * @param string $uri
   *   The redirect URI.
   *
   * @return bool
   *   TRUE if terminal application URI.
   */
  protected function isTerminalRedirectUri(string $uri): bool {
    $terminal_patterns = [
      '/^https?:\/\/127\.0\.0\.1:(\d+|0)\//',
      '/^https?:\/\/\[::1\]:(\d+|0)\//',
      '/^https?:\/\/localhost:(\d+|0)\//',
    ];

    return $this->matchesPatterns($uri, $terminal_patterns);
  }

  /**
   * Checks if URI is for a mobile application.
   *
   * @param string $uri
   *   The redirect URI.
   *
   * @return bool
   *   TRUE if mobile application URI.
   */
  protected function isMobileRedirectUri(string $uri): bool {
    $mobile_patterns = [
      '/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//',
      '/^(app|myapp|com\.[a-zA-Z0-9.]+):\/\//',
    ];

    // Exclude localhost schemes (those are desktop)
    if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\/(127\.0\.0\.1|localhost|\[::1\])/', $uri)) {
      return FALSE;
    }

    return $this->matchesPatterns($uri, $mobile_patterns);
  }

  /**
   * Checks if URI is for a desktop application.
   *
   * @param string $uri
   *   The redirect URI.
   *
   * @return bool
   *   TRUE if desktop application URI.
   */
  protected function isDesktopRedirectUri(string $uri): bool {
    // First check if it's a localhost scheme (desktop)
    if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\/(127\.0\.0\.1|localhost)/', $uri)) {
      return TRUE;
    }

    // Then check if it's a custom scheme but not terminal or mobile.
    if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//', $uri) &&
        !$this->isTerminalRedirectUri($uri) &&
        !$this->isMobileRedirectUri($uri)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Checks if URI matches any pattern in the set.
   *
   * @param string $uri
   *   The URI to check.
   * @param array $patterns
   *   Array of regex patterns.
   *
   * @return bool
   *   TRUE if URI matches any pattern.
   */
  protected function matchesPatterns(string $uri, array $patterns): bool {
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $uri)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Handles edge cases for URI classification.
   *
   * @param string $uri
   *   The URI to classify.
   *
   * @return string
   *   The client type.
   */
  protected function handleEdgeCases(string $uri): string {
    // Handle localhost without port (development web app)
    if (preg_match('/^https?:\/\/localhost\/$/', $uri)) {
      return 'web';
    }

    // Handle missing protocol.
    if (preg_match('/^localhost:\d+\//', $uri)) {
      // Assume terminal if localhost with port.
      return 'terminal';
    }

    // Default to web for unknown patterns.
    return 'web';
  }

  /**
   * Resolves final client type from detected URI types.
   *
   * @param array $detected_types
   *   Array of detected types for each URI.
   * @param array $redirect_uris
   *   Original redirect URIs.
   *
   * @return array
   *   Final detection result.
   */
  protected function resolveClientType(array $detected_types, array $redirect_uris): array {
    $unique_types = array_unique($detected_types);

    // Single type - high confidence.
    if (count($unique_types) === 1) {
      return [
        'type' => $unique_types[0],
        'confidence' => 'high',
        'details' => $this->getTypeDetails($unique_types[0]),
      ];
    }

    // Mixed types - apply priority rules.
    if (in_array('terminal', $unique_types)) {
      return [
        'type' => 'terminal',
        'confidence' => 'medium',
        'details' => $this->getTypeDetails('terminal'),
        'mixed_types' => $unique_types,
      ];
    }

    if (in_array('mobile', $unique_types) || in_array('desktop', $unique_types)) {
      $native_type = in_array('mobile', $unique_types) ? 'mobile' : 'desktop';
      return [
        'type' => $native_type,
        'confidence' => 'medium',
        'details' => $this->getTypeDetails($native_type),
        'mixed_types' => $unique_types,
      ];
    }

    // Default to web.
    return [
      'type' => 'web',
      'confidence' => 'low',
      'details' => $this->getTypeDetails('web'),
      'mixed_types' => $unique_types,
    ];
  }

  /**
   * Gets details for a client type.
   *
   * @param string $type
   *   The client type.
   *
   * @return array
   *   Type details array.
   */
  protected function getTypeDetails(string $type): array {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');

    return match ($type) {
      'terminal' => [
        'confidential' => FALSE,
        'pkce_required' => TRUE,
        'custom_schemes_allowed' => FALSE,
        'loopback_required' => TRUE,
        'enhanced_pkce' => $config->get('native.enhanced_pkce'),
        'description' => 'Terminal/CLI application using loopback redirects',
      ],
      'mobile', 'desktop' => [
        'confidential' => FALSE,
        'pkce_required' => TRUE,
        'custom_schemes_allowed' => $this->resolveCustomUriSchemesAllowed($config, NULL),
        'loopback_allowed' => $this->resolveLoopbackRedirectsAllowed($config, NULL),
        'enhanced_pkce' => $config->get('native.enhanced_pkce'),
        'description' => ucfirst($type) . ' native application',
      ],
      default => [
        'confidential' => NULL,
        'pkce_required' => FALSE,
        'https_required' => TRUE,
        'enhanced_pkce' => FALSE,
        'description' => 'Web application',
      ],
    };
  }

  /**
   * Clears the detection cache.
   */
  public function clearCache(): void {
    $this->detectionCache = [];
  }

  /**
   * Clears cache for a specific client.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   */
  public function clearClientCache(Consumer $client): void {
    $cache_key = 'native_detection_' . $client->id();
    unset($this->detectionCache[$cache_key]);
  }

}
