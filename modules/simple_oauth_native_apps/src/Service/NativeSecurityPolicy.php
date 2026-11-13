<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_native_apps\Service;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Config\ImmutableConfig;

/**
 * Resolves native security controls from explicit policy inputs.
 *
 * Open for service replacements that customize this focused responsibility.
 */
class NativeSecurityPolicy {

  /**
   * Resolves a configured PKCE method, defaulting to S256 for absent values.
   *
   * @param mixed $configured
   *   The configured method, including missing or invalid legacy values.
   *
   * @return string
   *   One of off, plain or S256. Invalid values require S256.
   */
  public function resolvePkceMethod(mixed $configured): string {
    return in_array($configured, ['off', 'plain', 'S256'], TRUE) ? $configured : 'S256';
  }

  /**
   * Calculates effective policy using one selected record for all controls.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The current global configuration.
   * @param \Drupal\consumers\Entity\Consumer|null $client
   *   The consumer, or NULL for global capability reporting.
   * @param \Closure $enhanced_override
   *   The facade enhanced PKCE override hook.
   * @param \Closure $webview_override
   *   The selected consumer WebView override.
   * @param \Closure $is_native
   *   The facade native detection hook.
   * @param \Closure $custom_allowed
   *   The facade custom scheme permission hook.
   * @param \Closure $loopback_allowed
   *   The facade loopback permission hook.
   *
   * @return array
   *   Effective native security controls.
   */
  public function resolveEffectivePolicy(
    ImmutableConfig $config,
    ?Consumer $client,
    \Closure $enhanced_override,
    \Closure $webview_override,
    \Closure $is_native,
    \Closure $custom_allowed,
    \Closure $loopback_allowed,
  ): array {
    $enabled = (bool) $config->get('enforce_native_security');
    $enhanced = $client !== NULL ? $enhanced_override($client) : NULL;
    if (($enhanced === NULL || $enhanced === '') && $client?->hasField('native_app_enhanced_pkce')) {
      $enhanced = match ($client->get('native_app_enhanced_pkce')->value) {
        'native' => 'enhanced',
        'web' => 'not-enhanced',
        default => NULL,
      };
    }
    if ($enhanced === NULL || $enhanced === '') {
      $enhanced = $config->get('native.enhanced_pkce') ?? 'auto-detect';
    }
    $requires_enhanced = match ($enhanced) {
      'enhanced' => TRUE,
      'not-enhanced' => FALSE,
      default => $client !== NULL ? $is_native($client) : TRUE,
    };
    $webview = NULL;
    if ($client !== NULL) {
      $webview = $webview_override($client);
    }
    if ($webview === NULL || $webview === '') {
      $webview = $config->get('webview.detection') ?? 'warn';
    }

    return [
      'enabled' => $enabled,
      'enhanced_pkce' => $enabled && $requires_enhanced,
      'custom_schemes' => $custom_allowed($config, $client),
      'loopback' => $loopback_allowed($config, $client),
      'webview' => $enabled ? $webview : 'off',
    ];
  }

  /**
   * Resolves a redirect permission while preserving consumer enum dispatch.
   *
   * @param mixed $global
   *   The global value, including the two declared legacy strings.
   * @param string|null $override
   *   The consumer override, or NULL to inherit the global setting.
   * @param \Closure $resolve_enum
   *   The facade enum hook, bound to the current consumer.
   *
   * @return bool
   *   Whether redirects of this kind are allowed.
   */
  public function resolveRedirectPermission(mixed $global, ?string $override, \Closure $resolve_enum): bool {
    if ($override !== NULL && $override !== '' && $override !== '0') {
      return $resolve_enum($override);
    }
    return $resolve_enum(RedirectPolicyValue::normalize($global) ?? 'web');
  }

  /**
   * Resolves a canonical permission using the facade automatic detection hook.
   *
   * @param string $value
   *   The canonical enum value.
   * @param \Closure $automatic
   *   The facade native detection hook, bound to the current consumer.
   *
   * @return bool
   *   The resolved permission.
   */
  public function resolveBoolean(string $value, \Closure $automatic): bool {
    return match ($value) {
      'native' => TRUE,
      'web' => FALSE,
      default => $automatic(),
    };
  }

}
