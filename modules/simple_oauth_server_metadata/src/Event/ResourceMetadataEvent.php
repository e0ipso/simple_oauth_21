<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when building OAuth 2.0 Protected Resource Metadata.
 *
 * Allows modules to add custom RFC 9728 metadata fields or override globally
 * configured fields before metadata is sent to clients.
 *
 * Event subscribers can directly access and modify the metadata array through
 * the public property, or use the provided helper methods for common
 * operations.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9728
 */
final class ResourceMetadataEvent extends Event {

  /**
   * Constructs a ResourceMetadataEvent.
   *
   * @param array $metadata
   *   The resource metadata array compliant with RFC 9728. This array can be
   *   directly modified by event subscribers to add custom fields or override
   *   existing values.
   */
  public function __construct(
    public array $metadata,
  ) {}

  /**
   * Adds or updates a metadata field.
   *
   * Convenience method for adding or updating individual metadata fields
   * without requiring direct array access.
   *
   * @param string $key
   *   The metadata field key. Should be a valid RFC 9728 field name or a
   *   properly namespaced custom field.
   * @param mixed $value
   *   The metadata field value. The value type should match RFC 9728
   *   specifications for the given field.
   */
  public function addMetadataField(string $key, mixed $value): void {
    $this->metadata[$key] = $value;
  }

  /**
   * Removes a metadata field.
   *
   * Convenience method for removing metadata fields without requiring direct
   * array access.
   *
   * @param string $key
   *   The metadata field key to remove.
   */
  public function removeMetadataField(string $key): void {
    unset($this->metadata[$key]);
  }

  /**
   * Checks if a metadata field exists.
   *
   * Convenience method for checking field existence without requiring direct
   * array access.
   *
   * @param string $key
   *   The metadata field key to check.
   *
   * @return bool
   *   TRUE if the field exists in the metadata array, FALSE otherwise.
   */
  public function hasMetadataField(string $key): bool {
    return array_key_exists($key, $this->metadata);
  }

  /**
   * Gets a specific metadata field value.
   *
   * Convenience method for retrieving individual field values without
   * requiring direct array access.
   *
   * @param string $key
   *   The metadata field key to retrieve.
   *
   * @return mixed
   *   The field value if it exists, NULL otherwise.
   */
  public function getMetadataField(string $key): mixed {
    return $this->metadata[$key] ?? NULL;
  }

}
