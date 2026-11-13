<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_native_apps\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\NullLogger;

/**
 * Service for analyzing user-agent strings to detect embedded webviews.
 *
 * This service implements RFC 8252 security recommendations by detecting
 * embedded webviews that may compromise OAuth 2.0 security for native apps.
 */
final class UserAgentAnalyzer {

  /**
   * The shared native security policy resolver.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\NativeClientDetector
   */
  private readonly NativeClientDetector $policyResolver;

  /**
   * Compiled regular expressions for efficient pattern matching.
   *
   * @var array
   */
  private array $compiledPatterns = [];

  /**
   * Embedded webview user-agent patterns organized by category.
   *
   * @var array
   */
  private array $embeddedWebviewPatterns = [
    'ios_native' => [
      '/WKWebView/i',
      '/UIWebView/i',
      // iOS CFNetwork framework.
      '/CFNetwork\/\d+\.\d+/i',
    ],
    'android_native' => [
      // WebView identifier.
      '/Android.*Version.*Chrome.*Mobile.*Safari.*wv\)/i',
      // Standalone WebView identifier.
      '/; wv\)/i',
      // Standard Android WebView.
      '/Android.*AppleWebKit.*\(KHTML.*Chrome.*Mobile.*Safari.*\)$/i',
    ],
    'social_media' => [
      // Facebook In-App Browser.
      '/FB_IAB/i',
      // Facebook App. cspell:ignore FBAN
      '/FBAN/i',
      // Facebook App Version. cspell:ignore FBAV
      '/FBAV/i',
      // Instagram In-App Browser.
      '/Instagram/i',
      // Twitter Android App.
      '/Twitter.*Android/i',
      // Twitter patterns.
      '/TWITTER_/i',
      // LinkedIn App.
      '/LinkedInApp/i',
      // WhatsApp.
      '/WhatsApp/i',
      // TikTok App.
      '/TikTok/i',
      // Snapchat.
      '/Snapchat/i',
      // Pinterest App.
      '/Pinterest/i',
      // Reddit App.
      '/Reddit/i',
      // Telegram App.
      '/Telegram/i',
    ],
    'messaging_browsers' => [
      // Line Messenger.
      '/Line\//i',
      // WeChat.
      '/MicroMessenger/i',
      // Baidu App. cspell:ignore baiduboxapp
      '/baiduboxapp/i',
      // QQ Browser.
      '/QQ\//i',
      // UC Browser.
      '/UCBrowser/i',
      // Samsung Internet (when embedded)
      '/SamsungBrowser/i',
      // Yandex Browser mobile.
      '/YaBrowser/i',
    ],
    'other_apps' => [
      // Google Search App.
      '/GSA\//i',
      // Yahoo Mobile App.
      '/YahooMobile/i',
      // iOS apps using SFSafariViewController (borderline case)
      '/MobileSafari.*Version.*Safari/i',
      // Chrome WebView explicit.
      '/Chrome.*Mobile.*Safari.*\[wv\]/i',
      // Opera Mini (proxy-based)
      '/Opera.*Mini/i',
    ],
    'cross_platform_frameworks' => [
      // Apache Cordova apps.
      '/Cordova/i',
      // PhoneGap framework.
      '/PhoneGap/i',
      // Ionic framework.
      '/Ionic/i',
      // React Native WebView.
      '/ReactNative/i',
      // Electron framework.
      '/Electron/i',
      // Capacitor framework.
      '/Capacitor/i',
      // Generic WebView identifier.
      '/\bWebView\b/i',
      // App-embedded WebKit.
      '/App\/.*WebKit/i',
      // Mobile Safari in WebView context.
      '/Mobile.*Safari.*WebView/i',
      // Chrome WebView with Mobile context.
      '/Chrome.*Mobile.*WebView/i',
    ],
  ];

  /**
   * Known safe browser patterns that should NOT be detected as webviews.
   *
   * @var array
   */
  private array $safeBrowserPatterns = [
    // Standard Mobile Safari.
    '/Mozilla.*Safari.*Version.*Mobile.*Safari(?!\s*\[wv\])$/i',
    // Standard Chrome Mobile.
    '/Chrome\/[\d.]+.*Mobile.*Safari\/[\d.]+$/i',
    // Standard Firefox Mobile.
    '/Firefox\/[\d.]+.*Mobile/i',
    // Microsoft Edge.
    '/Edge\/[\d.]+/i',
    // Standard Opera Mobile.
    '/Opera\/[\d.]+.*Version.*Mobile.*Safari/i',
    // Standard Samsung Internet.
    '/SamsungBrowser\/[\d.]+.*Mobile.*Safari/i',
  ];

  /**
   * Constructs a UserAgentAnalyzer service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\simple_oauth_native_apps\Service\NativeClientDetector|null $nativeClientDetector
   *   The shared resolver, optional for existing constructor callers.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    ?NativeClientDetector $nativeClientDetector = NULL,
  ) {
    $this->policyResolver = $nativeClientDetector ?? new NativeClientDetector($configFactory, new NullLogger());
    $this->compilePatterns();
  }

  /**
   * Analyzes user-agent string for embedded webview patterns.
   *
   * @param string $userAgent
   *   The user-agent string to analyze.
   *
   * @return bool
   *   TRUE if embedded webview detected.
   */
  public function isEmbeddedWebview(string $userAgent): bool {
    return $this->getWebviewType($userAgent) !== NULL;
  }

  /**
   * Wraps a configured regex body for case-insensitive user-agent matching.
   *
   * @param string $pattern
   *   The regex body, without delimiters. Slashes may be literal or escaped.
   *
   * @return string
   *   The delimited expression, with delimiter characters safely escaped.
   */
  public static function getRegexPattern(string $pattern): string {
    $expression = '';
    $escaped = FALSE;
    foreach (str_split($pattern) as $character) {
      if ($character === '~' && !$escaped) {
        $expression .= '\\';
      }
      $expression .= $character;
      $escaped = $character === '\\' ? !$escaped : FALSE;
    }

    return '~' . $expression . '~i';
  }

  /**
   * Gets the detected webview type and specific pattern.
   *
   * @param string $userAgent
   *   The user-agent string to analyze.
   *
   * @return array|null
   *   Array with 'category' and 'pattern', or NULL if not detected.
   */
  public function getWebviewType(string $userAgent): ?array {
    if (empty($userAgent)) {
      return NULL;
    }

    // Administrator rules and explicit markers precede generic browser hints.
    foreach ($this->getCustomPatterns() as $pattern) {
      if (@preg_match(self::getRegexPattern($pattern), $userAgent) === 1) {
        return [
          'category' => 'custom',
          'pattern' => $pattern,
          'user_agent' => $userAgent,
        ];
      }
    }

    $safe_browser = $this->isSafeBrowser($userAgent);
    $ambiguous_patterns = [
      '/Android.*AppleWebKit.*\(KHTML.*Chrome.*Mobile.*Safari.*\)$/i',
      '/SamsungBrowser/i',
      '/YaBrowser/i',
      '/MobileSafari.*Version.*Safari/i',
    ];
    foreach ($this->compiledPatterns as $category => $patterns) {
      foreach ($patterns as $pattern) {
        if ($safe_browser && in_array($pattern, $ambiguous_patterns, TRUE)) {
          continue;
        }
        if (preg_match($pattern, $userAgent)) {
          return [
            'category' => $category,
            'pattern' => $pattern,
            'user_agent' => $userAgent,
          ];
        }
      }
    }

    return NULL;
  }

  /**
   * Gets the current webview detection mode.
   *
   * @return string
   *   The detection mode: 'off', 'warn', or 'block'.
   */
  public function getDetectionMode(): string {
    return $this->policyResolver->getEffectivePolicy()['webview'];
  }

  /**
   * Checks if the detection is currently enabled.
   *
   * @return bool
   *   TRUE if webview detection is enabled.
   */
  public function isDetectionEnabled(): bool {
    return $this->getDetectionMode() !== 'off';
  }

  /**
   * Checks if webview blocking is enabled.
   *
   * @return bool
   *   TRUE if webview blocking is enabled.
   */
  public function isBlockingEnabled(): bool {
    return $this->getDetectionMode() === 'block';
  }

  /**
   * Gets security response headers for webview detection.
   *
   * @return array
   *   Array of HTTP headers to add to the response.
   */
  public function getSecurityHeaders(): array {
    $headers = [];

    if ($this->isDetectionEnabled()) {
      $headers['X-OAuth-Webview-Warning'] = 'Embedded webview detected. Use system browser for enhanced security.';

      if ($this->isBlockingEnabled()) {
        $headers['X-OAuth-Security-Policy'] = 'webview-blocked';
      }
    }

    return $headers;
  }

  /**
   * Compiles regular expressions for efficient pattern matching.
   */
  private function compilePatterns(): void {
    $this->compiledPatterns = [];

    foreach ($this->embeddedWebviewPatterns as $category => $patterns) {
      $this->compiledPatterns[$category] = $patterns;
    }
  }

  /**
   * Checks if the user-agent represents a known safe browser.
   *
   * @param string $userAgent
   *   The user-agent string to check.
   *
   * @return bool
   *   TRUE if this is a known safe browser.
   */
  private function isSafeBrowser(string $userAgent): bool {
    foreach ($this->safeBrowserPatterns as $pattern) {
      if (preg_match($pattern, $userAgent)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Gets custom webview patterns from configuration.
   *
   * @return array
   *   Array of custom patterns.
   */
  private function getCustomPatterns(): array {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');
    return $config->get('webview.patterns') ?? [];
  }

  /**
   * Adds a custom webview pattern.
   *
   * @param string $pattern
   *   The pattern to add.
   */
  public function addCustomPattern(string $pattern): void {
    $config = $this->configFactory->getEditable('simple_oauth_native_apps.settings');
    $patterns = $config->get('webview.patterns') ?? [];

    if (!in_array($pattern, $patterns)) {
      $patterns[] = $pattern;
      $config->set('webview.patterns', $patterns)->save();
    }
  }

  /**
   * Removes a custom webview pattern.
   *
   * @param string $pattern
   *   The pattern to remove.
   */
  public function removeCustomPattern(string $pattern): void {
    $config = $this->configFactory->getEditable('simple_oauth_native_apps.settings');
    $patterns = $config->get('webview.patterns') ?? [];

    $key = array_search($pattern, $patterns);
    if ($key !== FALSE) {
      unset($patterns[$key]);
      $config->set('webview.patterns', array_values($patterns))->save();
    }
  }

  /**
   * Gets statistics about webview detection patterns.
   *
   * @return array
   *   Statistics array with pattern counts by category.
   */
  public function getPatternStatistics(): array {
    $stats = [];

    foreach ($this->embeddedWebviewPatterns as $category => $patterns) {
      $stats[$category] = count($patterns);
    }

    $stats['custom'] = count($this->getCustomPatterns());
    $stats['safe_browsers'] = count($this->safeBrowserPatterns);
    $stats['total'] = array_sum($stats);

    return $stats;
  }

}
