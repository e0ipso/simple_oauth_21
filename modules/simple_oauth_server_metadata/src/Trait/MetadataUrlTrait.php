<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Trait;

use Drupal\Core\Url;

/**
 * Normalizes optional discovery URLs without changing their configured values.
 */
trait MetadataUrlTrait {

  /**
   * Ensures a configured URL is absolute when URL generation is available.
   *
   * @param string $url
   *   The configured URL.
   *
   * @return string
   *   The absolute URL, or the original value if conversion fails.
   */
  protected function ensureAbsoluteUrl(string $url): string {
    if (str_starts_with($url, '/')) {
      try {
        return Url::fromUserInput($url)->setAbsolute()->toString();
      }
      catch (\Exception) {
        return $url;
      }
    }
    return $url;
  }

}
