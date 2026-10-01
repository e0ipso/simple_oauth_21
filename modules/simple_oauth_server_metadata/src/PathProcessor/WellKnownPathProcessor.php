<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\PathProcessor;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\PathProcessor\OutboundPathProcessorInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Symfony\Component\HttpFoundation\Request;

/**
 * Keeps the discovery documents at the document root on multilingual sites.
 *
 * RFC 8414, RFC 9728 and OpenID Connect Discovery each place their document at
 * a fixed path directly below the host. A site using URL prefix language
 * negotiation otherwise breaks that in two ways, and this processor sits on
 * both sides of the language path processor to prevent each:
 *
 * - Outbound, the language prefix is removed again, so nothing generates a
 *   prefixed URL for these paths. That matters beyond tidiness: anything
 *   comparing a request against its canonical URL, such as the Redirect
 *   module's route normalizer, would otherwise redirect clients to a prefixed
 *   copy. Drupal core's .htaccess refuses those outright, because it only
 *   exempts `.well-known` as the leading path segment, so on Apache discovery
 *   fails with a 403 rather than merely being non-canonical.
 * - Inbound, a prefixed request is sent to a path no route matches, so the one
 *   document the specifications define is served from exactly one URL.
 *
 * @internal This class is not part of the module's public programming API.
 */
final class WellKnownPathProcessor implements InboundPathProcessorInterface, OutboundPathProcessorInterface {

  /**
   * Paths of the discovery documents, as declared in the routing file.
   *
   * @see simple_oauth_server_metadata.routing.yml
   */
  private const DISCOVERY_PATHS = [
    '/.well-known/oauth-authorization-server',
    '/.well-known/oauth-protected-resource',
    '/.well-known/openid-configuration',
  ];

  /**
   * Suffix that turns a prefixed request into a path no route matches.
   */
  private const UNROUTABLE_SUFFIX = '-served-only-at-the-document-root';

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request): string {
    if (in_array($path, self::DISCOVERY_PATHS, TRUE)) {
      return $path;
    }

    foreach (self::DISCOVERY_PATHS as $discovery_path) {
      if (str_ends_with($path, $discovery_path)) {
        // Nothing routes to this, so the router returns 404 instead of
        // serving a second copy of the document from a prefixed path.
        return $discovery_path . self::UNROUTABLE_SUFFIX;
      }
    }

    return $path;
  }

  /**
   * {@inheritdoc}
   */
  public function processOutbound($path, &$options = [], ?Request $request = NULL, ?BubbleableMetadata $bubbleable_metadata = NULL): string {
    if (!in_array($path, self::DISCOVERY_PATHS, TRUE)) {
      return $path;
    }

    // Undo what the language path processor added for these paths.
    unset($options['language']);
    $options['prefix'] = '';

    if ($bubbleable_metadata instanceof BubbleableMetadata) {
      // The URL is identical in every language, so it must not vary by one.
      $cache_contexts = array_filter(
        $bubbleable_metadata->getCacheContexts(),
        static fn (string $cache_context): bool => $cache_context !== 'languages:language_url',
      );
      $bubbleable_metadata->setCacheContexts(array_values($cache_contexts));
    }

    return $path;
  }

}
