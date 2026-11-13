<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_native_apps\Service;

use Drupal\consumers\Entity\Consumer;

/**
 * Describes detection reasons and client type recommendations.
 *
 * Open for service replacements that customize this focused responsibility.
 */
class NativeClientDescription {

  /**
   * Provides reasons for native client classification.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   * @param \Closure $analyze_uris
   *   The facade URI analysis hook.
   *
   * @return array
   *   Array of classification reasons.
   */
  public function getClassificationReasons(Consumer $client, \Closure $analyze_uris): array {
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
    $uri_analysis = $analyze_uris($client);

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
   * Gets details for a client type.
   *
   * @param string $type
   *   The client type.
   * @param mixed $enhanced_pkce
   *   The unmodified enhanced PKCE setting.
   * @param \Closure $custom_allowed
   *   The facade global custom scheme policy hook.
   * @param \Closure $loopback_allowed
   *   The facade global loopback policy hook.
   *
   * @return array
   *   Type details array.
   */
  public function getTypeDetails(
    string $type,
    mixed $enhanced_pkce,
    \Closure $custom_allowed,
    \Closure $loopback_allowed,
  ): array {
    return match ($type) {
      'terminal' => [
        'confidential' => FALSE,
        'pkce_required' => TRUE,
        'custom_schemes_allowed' => FALSE,
        'loopback_required' => TRUE,
        'enhanced_pkce' => $enhanced_pkce,
        'description' => 'Terminal/CLI application using loopback redirects',
      ],
      'mobile', 'desktop' => [
        'confidential' => FALSE,
        'pkce_required' => TRUE,
        'custom_schemes_allowed' => $custom_allowed(),
        'loopback_allowed' => $loopback_allowed(),
        'enhanced_pkce' => $enhanced_pkce,
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

}
