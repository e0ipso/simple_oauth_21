<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when building OAuth 2.0 Protected Resource Metadata.
 *
 * Allows modules to add custom RFC 9728 metadata fields or override
 * globally configured fields before metadata is sent to clients.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9728
 */
final class ResourceMetadataEvent extends Event {

  /**
   * The resource metadata array.
   */
  private array $metadata;

  /**
   * The original configuration override values.
   */
  private readonly array $originalConfig;

  /**
   * Constructs a ResourceMetadataEvent.
   *
   * @param array $metadata
   *   The initial metadata array.
   * @param array $originalConfig
   *   The original configuration override values (for reference).
   */
  public function __construct(array $metadata, array $originalConfig = []) {
    $this->metadata = $metadata;
    $this->originalConfig = $originalConfig;
  }

  /**
   * Gets the resource metadata array.
   *
   * @return array
   *   The metadata array.
   */
  public function getMetadata(): array {
    return $this->metadata;
  }

  /**
   * Sets the resource metadata array.
   *
   * @param array $metadata
   *   The metadata array to set.
   */
  public function setMetadata(array $metadata): void {
    $this->metadata = $metadata;
  }

  /**
   * Adds a metadata field.
   *
   * @param string $key
   *   The metadata field key.
   * @param mixed $value
   *   The metadata field value.
   */
  public function addMetadataField(string $key, mixed $value): void {
    $this->metadata[$key] = $value;
  }

  /**
   * Removes a metadata field.
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
   * @param string $key
   *   The metadata field key.
   *
   * @return bool
   *   TRUE if the field exists, FALSE otherwise.
   */
  public function hasMetadataField(string $key): bool {
    return array_key_exists($key, $this->metadata);
  }

  /**
   * Gets a specific metadata field value.
   *
   * @param string $key
   *   The metadata field key.
   *
   * @return mixed
   *   The field value, or NULL if not set.
   */
  public function getMetadataField(string $key): mixed {
    return $this->metadata[$key] ?? NULL;
  }

  /**
   * Gets the original configuration override values.
   *
   * @return array
   *   The original configuration array (read-only reference).
   */
  public function getOriginalConfig(): array {
    return $this->originalConfig;
  }

}
