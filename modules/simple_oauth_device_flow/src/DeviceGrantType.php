<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow;

use Drupal\consumers\Entity\Consumer;

/**
 * Selects the configured canonical device grant or its supported legacy alias.
 */
final class DeviceGrantType {

  /**
   * The RFC 8628 and League grant identifier.
   */
  public const CANONICAL = 'urn:ietf:params:oauth:grant-type:device_code';

  /**
   * The legacy value retained for existing consumer grant lists.
   */
  public const LEGACY = 'device_code';

  /**
   * Selects the canonical grant first, then the explicitly supported alias.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer with its unchanged saved grant list.
   *
   * @return string|null
   *   The configured identifier, or NULL for an ineligible client.
   */
  public static function select(Consumer $client): ?string {
    $grants = array_column($client->get('grant_types')->getValue(), 'value');
    foreach ([self::CANONICAL, self::LEGACY] as $grant) {
      if (in_array($grant, $grants, TRUE)) {
        return $grant;
      }
    }
    return NULL;
  }

}
