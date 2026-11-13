<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_native_apps\Service;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Config\ConfigFactoryInterface;
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
   * Fingerprints for the single current score slot of each saved consumer ID.
   *
   * @var array<string, string>
   */
  private array $scoreCacheFingerprints = [];

  /**
   * Configuration used by cached client type descriptions.
   *
   * @var array|null
   */
  private ?array $typeCacheSettings = NULL;

  /**
   * Submitted overrides scoped to an unsaved form entity, never persisted here.
   *
   * @var \WeakMap<\Drupal\consumers\Entity\Consumer, array>
   */
  private \WeakMap $submittedOverrides;

  /**
   * The shared consumer policy boundary.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\ConsumerPolicyResolver
   */
  private readonly ConsumerPolicyResolver $policyResolver;

  /**
   * Whole policy scoped to one effective-policy calculation, never a cache.
   *
   * @var array
   */
  private array $resolvedOverrides = [];

  /**
   * Native client URI scheme patterns.
   *
   * @var array
   */
  protected array $nativeUriPatterns = [
    // Custom URI schemes (excluding common web schemes)
    'custom_scheme' => '/^(?!https?:)[a-z][a-z0-9+.-]*:\/.+$/i',
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
   * The effective native security policy.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\NativeSecurityPolicy
   */
  private readonly NativeSecurityPolicy $securityPolicy;

  /**
   * The native indicator scoring and URI classifier.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\NativeClientClassifier
   */
  private readonly NativeClientClassifier $classifier;

  /**
   * The stable detector descriptions and recommendations.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\NativeClientDescription
   */
  private readonly NativeClientDescription $description;

  /**
   * Constructs a NativeClientDetector object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface|\Psr\Log\LoggerInterface $logger_factory
   *   The logger channel factory or an existing channel.
   * @param \Drupal\simple_oauth_native_apps\Service\ConsumerPolicyResolver|null $policy_resolver
   *   The policy boundary, or NULL for original constructor compatibility.
   * @param \Drupal\simple_oauth_native_apps\Service\NativeSecurityPolicy|null $security_policy
   *   The effective policy, optional for original callers.
   * @param \Drupal\simple_oauth_native_apps\Service\NativeClientClassifier|null $classifier
   *   The URI classifier, optional for original callers.
   * @param \Drupal\simple_oauth_native_apps\Service\NativeClientDescription|null $description
   *   The descriptions, optional for original callers.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface|LoggerInterface $logger_factory,
    ?ConsumerPolicyResolver $policy_resolver = NULL,
    ?NativeSecurityPolicy $security_policy = NULL,
    ?NativeClientClassifier $classifier = NULL,
    ?NativeClientDescription $description = NULL,
  ) {
    $this->policyResolver = $policy_resolver ?? new ConsumerPolicyResolver($configFactory);
    $this->securityPolicy = $security_policy ?? new NativeSecurityPolicy();
    $this->classifier = $classifier ?? new NativeClientClassifier();
    $this->description = $description ?? new NativeClientDescription();
    $this->submittedOverrides = new \WeakMap();
    $this->logger = $logger_factory instanceof LoggerInterface
      ? $logger_factory
      : $logger_factory->get('simple_oauth_native_apps');
  }

  /**
   * Sets pending form overrides on the entity being validated.
   *
   * The form saves these values through its existing config submit handler.
   * Weak references keep pending policy separate from stored consumer entities.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The form entity built for validation.
   * @param array $overrides
   *   Submitted overrides, including explicit empty inheritance values.
   *
   * @internal
   */
  public function setSubmittedOverrides(Consumer $client, array $overrides): void {
    $this->submittedOverrides[$client] = $overrides;
  }

  /**
   * Reads a submitted override or the consumer's stored override configuration.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer being validated or used at runtime.
   * @param string $key
   *   The existing override config key.
   *
   * @return string|null
   *   The override, or NULL to inherit.
   *
   * @throws \UnexpectedValueException
   *   Stored policy ownership requires reconciliation.
   */
  private function getConsumerOverride(Consumer $client, string $key): ?string {
    if (isset($this->submittedOverrides[$client])) {
      return $this->submittedOverrides[$client][$key] ?? NULL;
    }
    $policy = ($this->resolvedOverrides[0] ?? NULL) === $client
      ? $this->resolvedOverrides[1]
      : $this->policyResolver->getPolicy($client);
    return $policy[$key] ?? NULL;
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
    return $this->classifier->detectNativeFromRedirectUris($redirectUris, $this->isNativeRedirectUri(...));
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
    return $this->getEffectivePolicy($client)['enhanced_pkce'];
  }

  /**
   * Gets the effective native PKCE method without changing stored settings.
   *
   * @return string
   *   One of off, plain or S256. Missing or invalid values require S256.
   */
  public function getEnforcedPkceMethod(): string {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');
    return $this->securityPolicy->resolvePkceMethod($config->get('native.enforce'));
  }

  /**
   * Resolves native security policy without changing stored overrides.
   *
   * Explicit config overrides take precedence over entity controls, then global
   * settings. Empty config values inherit. The entity's automatic default also
   * inherits, so existing global policies retain their meaning.
   *
   * @param \Drupal\consumers\Entity\Consumer|null $client
   *   The consumer, or NULL for global capability reporting.
   *
   * @return array
   *   Effective enabled, enhanced_pkce, custom_schemes, loopback and webview
   *   values.
   *
   * @throws \UnexpectedValueException
   *   Stored policy ownership requires reconciliation.
   */
  public function getEffectivePolicy(?Consumer $client = NULL): array {
    $previous = $this->resolvedOverrides;
    if ($client !== NULL) {
      $this->resolvedOverrides = [$client, $this->policyResolver->getPolicy($client)];
    }
    try {
      return $this->resolveEffectivePolicy($client);
    }
    finally {
      $this->resolvedOverrides = $previous;
    }
  }

  /**
   * Calculates effective policy using one selected record for all controls.
   *
   * @param \Drupal\consumers\Entity\Consumer|null $client
   *   The consumer, or NULL for global capability reporting.
   *
   * @return array
   *   Effective native security controls.
   */
  private function resolveEffectivePolicy(?Consumer $client): array {
    return $this->securityPolicy->resolveEffectivePolicy(
      $this->configFactory->get('simple_oauth_native_apps.settings'),
      $client,
      $this->getConsumerEnhancedPkceOverride(...),
      fn(Consumer $consumer): ?string => $this->getConsumerOverride($consumer, 'webview_detection_override'),
      $this->isNativeClient(...),
      $this->resolveCustomUriSchemesAllowed(...),
      $this->resolveLoopbackRedirectsAllowed(...),
    );
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
    return $this->getConsumerOverride($client, 'enhanced_pkce_override');
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
    return $this->securityPolicy->resolveRedirectPermission(
      $config->get('allow.custom_uri_schemes') ?? 'auto-detect',
      $client !== NULL ? $this->getConsumerCustomUriSchemesOverride($client) : NULL,
      fn(string $value): bool => $this->resolveBooleanFromEnum($value, $client),
    );
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
    return $this->securityPolicy->resolveRedirectPermission(
      $config->get('allow.loopback_redirects') ?? 'auto-detect',
      $client !== NULL ? $this->getConsumerLoopbackRedirectsOverride($client) : NULL,
      fn(string $value): bool => $this->resolveBooleanFromEnum($value, $client),
    );
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
    return $this->getConsumerOverride($client, 'allow_custom_schemes_override');
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
    return $this->getConsumerOverride($client, 'allow_loopback_override');
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
    return $this->securityPolicy->resolveBoolean(
      $enum_value,
      fn(): bool => $client ? $this->isNativeClient($client) : TRUE,
    );
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
    return $this->description->getClassificationReasons($client, $this->analyzeRedirectUris(...));
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

    // Unsaved entities must never share a score slot, even with an assigned ID.
    $client_id = (string) $client->id();
    $cache_key = 'native_detection_' . $client_id;
    $fingerprint = NULL;
    if ((bool) $config->get('cache_detection_results') && !$client->isNew() && $client_id !== '') {
      $fingerprint = $this->getDetectionCacheFingerprint($client);
      if (($this->scoreCacheFingerprints[$cache_key] ?? NULL) === $fingerprint && isset($this->detectionCache[$cache_key])) {
        return $this->detectionCache[$cache_key];
      }
    }
    else {
      unset($this->detectionCache[$cache_key], $this->scoreCacheFingerprints[$cache_key]);
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
    if ($fingerprint !== NULL) {
      $this->detectionCache[$cache_key] = $score;
      $this->scoreCacheFingerprints[$cache_key] = $fingerprint;
    }

    return $score;
  }

  /**
   * Identifies the current entity inputs, settings and URI scoring patterns.
   *
   * All field data, including UUID and extension fields, participates. Only a
   * digest is retained in request-local memory, never entity or secret values.
   * A changed snapshot replaces the previous score for that numeric ID.
   *
   * Subclasses reading external state in analysis hooks must extend this
   * fingerprint, call clearClientCache()/clearCache() when that state changes,
   * or disable score caching. Analysis hooks still dispatch through the facade.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The saved consumer snapshot being classified.
   *
   * @return string
   *   The opaque identity of the current scoring inputs.
   */
  protected function getDetectionCacheFingerprint(Consumer $client): string {
    return hash('sha256', serialize([
      $client::class,
      $client->language()->getId(),
      $client->toArray(),
      $this->configFactory->get('simple_oauth_native_apps.settings')->get(),
      $this->nativeUriPatterns,
      $this->loopbackPatterns,
      $this->webUriPatterns,
    ]));
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
    return $this->classifier->calculateAlgorithmicScore(
      $this->analyzeRedirectUris($client),
      $this->analyzeGrantTypes($client),
      $this->analyzeClientConfig($client),
    );
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
    return $this->classifier->analyzeRedirectUris(
      $this->getRedirectUris($client),
      $this->isNativeRedirectUri(...),
      $this->isWebRedirectUri(...),
    );
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
    return $this->classifier->analyzeGrantTypes($client);
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
    return $this->classifier->analyzeClientConfig($client, $this->hasNativeAppNamePattern(...));
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
    return $this->classifier->matchesPatterns($uri, $this->nativeUriPatterns)
      || $this->classifier->matchesPatterns($uri, $this->loopbackPatterns);
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
    return $this->classifier->matchesPatterns($uri, $this->webUriPatterns);
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
    return $this->classifier->hasNativeAppNamePattern($name);
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

    // Descriptions depend on current policy as well as redirect URI identity.
    $settings = $this->configFactory->get('simple_oauth_native_apps.settings')->get();
    if ($this->typeCacheSettings !== NULL && $this->typeCacheSettings !== $settings) {
      foreach (array_keys($this->detectionCache) as $key) {
        if (str_starts_with($key, 'native_client_detector:type:')) {
          unset($this->detectionCache[$key]);
        }
      }
    }
    $this->typeCacheSettings = $settings;

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
    return $this->classifier->classifyRedirectUri(
      $uri,
      $this->isTerminalRedirectUri(...),
      $this->isMobileRedirectUri(...),
      $this->isDesktopRedirectUri(...),
      $this->isWebRedirectUri(...),
      $this->handleEdgeCases(...),
    );
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
    return $this->classifier->isTerminalRedirectUri($uri, $this->matchesPatterns(...));
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
    return $this->classifier->isMobileRedirectUri($uri, $this->isNativeRedirectUri(...));
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
    return $this->classifier->isDesktopRedirectUri(
      $uri, $this->isNativeRedirectUri(...), $this->isTerminalRedirectUri(...), $this->isMobileRedirectUri(...),
    );
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
    return $this->classifier->matchesPatterns($uri, $patterns);
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
    return $this->classifier->handleEdgeCases($uri);
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
    return $this->classifier->resolveClientType($detected_types, $this->getTypeDetails(...));
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
    return $this->description->getTypeDetails(
      $type,
      $config->get('native.enhanced_pkce'),
      fn(): bool => $this->resolveCustomUriSchemesAllowed($config, NULL),
      fn(): bool => $this->resolveLoopbackRedirectsAllowed($config, NULL),
    );
  }

  /**
   * Clears the detection cache.
   */
  public function clearCache(): void {
    $this->detectionCache = [];
    $this->scoreCacheFingerprints = [];
    $this->typeCacheSettings = NULL;
  }

  /**
   * Clears detection and pending form policy after a client is persisted.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   */
  public function clearClientCache(Consumer $client): void {
    $cache_key = 'native_detection_' . $client->id();
    unset($this->detectionCache[$cache_key], $this->scoreCacheFingerprints[$cache_key], $this->submittedOverrides[$client]);
  }

}
