---
id: 1
group: "event-infrastructure"
dependencies: []
status: "pending"
created: 2025-11-03
skills:
  - "symfony-events"
  - "drupal-backend"
---
# Create ResourceMetadataEvent and Constants Classes

## Objective

Create the Symfony event infrastructure for resource metadata extensibility, including the event class with helper methods and the constants class defining event names.

## Skills Required

- **symfony-events**: Symfony EventDispatcher patterns and event class implementation
- **drupal-backend**: Drupal coding standards, PHPDoc, and service architecture

## Acceptance Criteria

- [ ] `ResourceMetadataEvent` class created in `src/Event/ResourceMetadataEvent.php`
- [ ] Event extends `Symfony\Contracts\EventDispatcher\Event`
- [ ] Event contains mutable metadata array and immutable original config
- [ ] Helper methods implemented: `getMetadata()`, `setMetadata()`, `addMetadataField()`, `removeMetadataField()`, `hasMetadataField()`, `getMetadataField()`, `getOriginalConfig()`
- [ ] `ResourceMetadataEvents` constants class created in `src/Event/ResourceMetadataEvents.php`
- [ ] Constant `BUILD` defined with event name `simple_oauth_server_metadata.resource_metadata.build`
- [ ] All code uses `declare(strict_types=1)`, `final` keyword, typed properties, and comprehensive PHPDoc
- [ ] Code follows project coding standards (AGENTS.md)

## Technical Requirements

**Event Class Structure:**
```php
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
```

**Constants Class Structure:**
```php
<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Event;

/**
 * Defines events for OAuth 2.0 Protected Resource Metadata.
 */
final class ResourceMetadataEvents {

  /**
   * Event dispatched when building resource metadata.
   *
   * Allows modules to add or modify OAuth 2.0 Protected Resource
   * Metadata (RFC 9728) before it's sent to clients.
   *
   * Subscribers can add custom fields, override configured values,
   * or contribute resource-specific capabilities.
   *
   * @Event("Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent")
   *
   * @var string
   */
  public const BUILD = 'simple_oauth_server_metadata.resource_metadata.build';

}
```

## Input Dependencies

None - this is the foundational task.

## Output Artifacts

- `src/Event/ResourceMetadataEvent.php` - Event class
- `src/Event/ResourceMetadataEvents.php` - Constants class

## Implementation Notes

<details>
<summary>Detailed Implementation Guidance</summary>

### File Locations

Create two new files in the module:
- `web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/src/Event/ResourceMetadataEvent.php`
- `web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/src/Event/ResourceMetadataEvents.php`

### Key Implementation Points

1. **Event Class**:
   - Use `declare(strict_types=1);` at the top
   - Mark class as `final` (no inheritance needed)
   - Extend `Symfony\Contracts\EventDispatcher\Event`
   - Make `$metadata` mutable (private without readonly)
   - Make `$originalConfig` immutable (readonly)
   - All methods must have full type declarations
   - Comprehensive PHPDoc for class and all methods

2. **Constants Class**:
   - Use `declare(strict_types=1);` at the top
   - Mark class as `final`
   - Define `BUILD` constant with descriptive event name
   - Include `@Event` annotation referencing event class
   - Add comprehensive PHPDoc explaining when event is dispatched

3. **Coding Standards**:
   - Follow Drupal coding standards
   - Use PSR-4 autoloading namespace
   - 2-space indentation
   - No trailing whitespace
   - Newline at end of file

### Testing

After creation, verify:
```bash
# Check syntax
php -l src/Event/ResourceMetadataEvent.php
php -l src/Event/ResourceMetadataEvents.php

# Verify class can be loaded
vendor/bin/drush php:eval "var_dump(class_exists('Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent'));"
```

### Reference Implementation

Look at existing event subscriber in the module:
- `src/EventSubscriber/IntrospectionExceptionSubscriber.php` - shows event pattern

</details>
