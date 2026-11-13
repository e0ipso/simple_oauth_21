<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_native_apps\Service;

use Drupal\consumers\Entity\Consumer;

/**
 * Scores native indicators and classifies redirect URI patterns.
 *
 * Open for service replacements that customize this focused responsibility.
 */
class NativeClientClassifier {

  /**
   * Analyzes redirect URIs to detect native patterns.
   *
   * @param array $redirectUris
   *   Array of redirect URI values.
   * @param \Closure $is_native
   *   The facade native URI hook.
   *
   * @return bool
   *   TRUE if native patterns detected.
   */
  public function detectNativeFromRedirectUris(array $redirectUris, \Closure $is_native): bool {
    if (empty($redirectUris)) {
      return FALSE;
    }

    $native_count = 0;
    $total_count = count($redirectUris);

    foreach ($redirectUris as $uri) {
      if ($is_native($uri)) {
        $native_count++;
      }
    }

    // Return TRUE if majority of URIs are native.
    return ($native_count / $total_count) >= 0.5;
  }

  /**
   * Calculates the algorithmic detection score.
   *
   * @param float $redirect_score
   *   The facade redirect URI analysis score.
   * @param float $grant_score
   *   The facade grant indicator score.
   * @param float $config_score
   *   The facade client configuration score.
   *
   * @return float
   *   Score between 0.0 and 1.0.
   */
  public function calculateAlgorithmicScore(float $redirect_score, float $grant_score, float $config_score): float {
    $score = 0.0;

    // Redirect URI analysis (40% weight)
    $score += $redirect_score * 0.4;

    // Grant type analysis (30% weight)
    $score += $grant_score * 0.3;

    // Client configuration (30% weight)
    $score += $config_score * 0.3;

    return min($score, 1.0);
  }

  /**
   * Analyzes redirect URIs for native patterns.
   *
   * @param array $redirect_uris
   *   Redirect URI strings selected by the facade.
   * @param \Closure $is_native
   *   The facade native URI hook.
   * @param \Closure $is_web
   *   The facade web URI hook.
   *
   * @return float
   *   Score between 0.0 and 1.0.
   */
  public function analyzeRedirectUris(array $redirect_uris, \Closure $is_native, \Closure $is_web): float {
    if (empty($redirect_uris)) {
      return 0.0;
    }

    $native_score = 0.0;
    $web_score = 0.0;

    foreach ($redirect_uris as $uri) {
      if ($is_native($uri)) {
        $native_score += 1.0;
      }
      elseif ($is_web($uri)) {
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
  public function analyzeGrantTypes(Consumer $client): float {
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
   * @param \Closure $has_native_name
   *   The facade name heuristic hook.
   *
   * @return float
   *   Score between 0.0 and 1.0.
   */
  public function analyzeClientConfig(Consumer $client, \Closure $has_native_name): float {
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
    if ($label && $has_native_name($label)) {
      $score += 0.2;
    }

    return min($score, 1.0);
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
  public function hasNativeAppNamePattern(string $name): bool {
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
   * Classifies a single redirect URI.
   *
   * @param string $uri
   *   The redirect URI to classify.
   * @param \Closure $terminal
   *   The facade terminal classification hook.
   * @param \Closure $mobile
   *   The facade mobile classification hook.
   * @param \Closure $desktop
   *   The facade desktop classification hook.
   * @param \Closure $web
   *   The facade web classification hook.
   * @param \Closure $edge
   *   The facade edge classification hook.
   *
   * @return string
   *   The detected client type.
   */
  public function classifyRedirectUri(
    string $uri,
    \Closure $terminal,
    \Closure $mobile,
    \Closure $desktop,
    \Closure $web,
    \Closure $edge,
  ): string {
    // Priority order: Terminal -> Mobile -> Desktop -> Web.
    if ($terminal($uri)) {
      return 'terminal';
    }

    if ($mobile($uri)) {
      return 'mobile';
    }

    if ($desktop($uri)) {
      return 'desktop';
    }

    if ($web($uri)) {
      return 'web';
    }

    // Handle edge cases.
    return $edge($uri);
  }

  /**
   * Checks if URI is for a terminal application.
   *
   * @param string $uri
   *   The redirect URI.
   * @param \Closure $matches
   *   The facade pattern matching hook.
   *
   * @return bool
   *   TRUE if terminal application URI.
   */
  public function isTerminalRedirectUri(string $uri, \Closure $matches): bool {
    $terminal_patterns = [
      '/^https?:\/\/127\.0\.0\.1:(\d+|0)\//',
      '/^https?:\/\/\[::1\]:(\d+|0)\//',
      '/^https?:\/\/localhost:(\d+|0)\//',
    ];

    return $matches($uri, $terminal_patterns);
  }

  /**
   * Checks if URI is for a mobile application.
   *
   * @param string $uri
   *   The redirect URI.
   * @param \Closure $native
   *   The facade native URI hook.
   *
   * @return bool
   *   TRUE if mobile application URI.
   */
  public function isMobileRedirectUri(string $uri, \Closure $native): bool {
    $scheme = parse_url($uri, PHP_URL_SCHEME);
    $host = parse_url($uri, PHP_URL_HOST);
    return $native($uri)
      && is_string($scheme)
      && !in_array(strtolower($scheme), ['http', 'https'], TRUE)
      && !in_array($host, ['127.0.0.1', 'localhost', '[::1]'], TRUE);
  }

  /**
   * Checks if URI is for a desktop application.
   *
   * @param string $uri
   *   The redirect URI.
   * @param \Closure $native
   *   The facade native URI hook.
   * @param \Closure $terminal
   *   The facade terminal URI hook.
   * @param \Closure $mobile
   *   The facade mobile URI hook.
   *
   * @return bool
   *   TRUE if desktop application URI.
   */
  public function isDesktopRedirectUri(string $uri, \Closure $native, \Closure $terminal, \Closure $mobile): bool {
    return $native($uri)
      && !$terminal($uri)
      && !$mobile($uri);
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
  public function matchesPatterns(string $uri, array $patterns): bool {
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
  public function handleEdgeCases(string $uri): string {
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
   * @param \Closure $details
   *   The facade type description hook.
   *
   * @return array
   *   Final detection result.
   */
  public function resolveClientType(array $detected_types, \Closure $details): array {
    $unique_types = array_unique($detected_types);

    // Single type - high confidence.
    if (count($unique_types) === 1) {
      return [
        'type' => $unique_types[0],
        'confidence' => 'high',
        'details' => $details($unique_types[0]),
      ];
    }

    // Mixed types - apply priority rules.
    if (in_array('terminal', $unique_types)) {
      return [
        'type' => 'terminal',
        'confidence' => 'medium',
        'details' => $details('terminal'),
        'mixed_types' => $unique_types,
      ];
    }

    if (in_array('mobile', $unique_types) || in_array('desktop', $unique_types)) {
      $native_type = in_array('mobile', $unique_types) ? 'mobile' : 'desktop';
      return [
        'type' => $native_type,
        'confidence' => 'medium',
        'details' => $details($native_type),
        'mixed_types' => $unique_types,
      ];
    }

    // Default to web.
    return [
      'type' => 'web',
      'confidence' => 'low',
      'details' => $details('web'),
      'mixed_types' => $unique_types,
    ];
  }

}
