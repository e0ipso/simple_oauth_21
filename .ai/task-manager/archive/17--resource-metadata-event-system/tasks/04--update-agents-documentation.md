---
id: 4
group: "documentation"
dependencies: [1, 2, 3]
status: "pending"
created: 2025-11-03
skills:
  - "technical-writing"
---
# Update AGENTS.md with Event System Documentation

## Objective

Update the AGENTS.md file with comprehensive documentation on the event system, including usage examples, best practices, and integration guidance for developers extending resource metadata.

## Skills Required

- **technical-writing**: Clear technical documentation, code examples, and developer guidance

## Acceptance Criteria

- [ ] AGENTS.md updated with new "Method 3: Symfony Event Subscribers" section
- [ ] Complete event subscriber code example included
- [ ] Service registration pattern documented
- [ ] Common use cases described with examples
- [ ] Cache invalidation guidance added
- [ ] Best practices for avoiding field conflicts documented
- [ ] RFC 9728 compliance notes included
- [ ] Examples use actual code from test cases
- [ ] Documentation is clear, concise, and actionable

## Technical Requirements

Update the existing "Extension Patterns" section in AGENTS.md to add Method 3 after the existing service decoration pattern.

**New Section Structure:**

```markdown
### Method 3: Symfony Event Subscribers (Recommended)

**Use Case:** Cleanest approach for extending resource metadata without service decoration

The event system provides a type-safe, maintainable way to add or override metadata fields:

#### Creating an Event Subscriber

[Include complete working example from tests]

#### Service Registration

[Show YAML service definition]

#### Use Cases

**Adding Custom RFC 9728 Fields:**
[Example with resource_signing_alg_values_supported]

**Overriding Configured Fields:**
[Example overriding resource_documentation based on context]

**Multi-Module Integration:**
[Example combining capabilities from multiple modules]

#### Best Practices

**Cache Invalidation:**
[Explain when and how to invalidate cache]

**Avoiding Field Conflicts:**
[Guidance on field naming and priority]

**RFC 9728 Compliance:**
[Notes on required fields and validation]
```

## Input Dependencies

- Task 1: Event classes must be documented
- Task 2: Event integration code to reference
- Task 3: Test subscriber code to use as example

## Output Artifacts

- Updated `modules/simple_oauth_server_metadata/AGENTS.md`

## Implementation Notes

<details>
<summary>Detailed Implementation Guidance</summary>

### File Location

Edit: `web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/AGENTS.md`

### Section to Modify

Find the "Extension Patterns" section (around line 580-620) and add new Method 3 after Method 2 (Service Decoration).

### Content Guidelines

1. **Code Examples**:
   - Use actual working code from test subscriber
   - Include full class with namespace, use statements, and PHPDoc
   - Show complete service YAML registration
   - Provide multiple real-world examples

2. **Use Cases**:
   - Reference RFC 9728 specification
   - Show practical examples developers will actually use
   - Include context-aware metadata scenarios

3. **Best Practices**:
   - Cache tag management (critical for Drupal)
   - Field naming conventions to avoid conflicts
   - Priority ordering for multiple subscribers
   - When to use events vs service decoration

4. **Comparison with Other Methods**:
   - Update Method 1 (Configuration) to note when to use events instead
   - Update Method 2 (Service Decoration) to note events are lighter weight

### Example Event Subscriber Code

Use the test subscriber from Task 3 as the primary example:

```php
<?php

declare(strict_types=1);

namespace Drupal\your_module\EventSubscriber;

use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent;
use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for custom resource metadata.
 */
final class CustomResourceMetadataSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ResourceMetadataEvents::BUILD => ['onBuildMetadata', 0],
    ];
  }

  /**
   * Modifies resource metadata.
   *
   * @param \Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent $event
   *   The resource metadata event.
   */
  public function onBuildMetadata(ResourceMetadataEvent $event): void {
    $metadata = $event->getMetadata();

    // Add custom RFC 9728 fields
    $metadata['resource_signing_alg_values_supported'] = ['RS256', 'ES256'];

    // Override configured field based on context
    if ($this->shouldUseCustomDocs()) {
      $metadata['resource_documentation'] = 'https://custom.example.com/api';
    }

    $event->setMetadata($metadata);
  }

  /**
   * Determines if custom documentation URL should be used.
   *
   * @return bool
   *   TRUE if custom docs should be used.
   */
  private function shouldUseCustomDocs(): bool {
    // Your custom logic here
    return TRUE;
  }

}
```

**Service Registration:**
```yaml
# your_module.services.yml
services:
  your_module.resource_metadata_subscriber:
    class: Drupal\your_module\EventSubscriber\CustomResourceMetadataSubscriber
    tags:
      - { name: event_subscriber }
```

### Comparison Table

Add comparison of extension methods:

| Method | Use Case | Complexity | Flexibility |
|--------|----------|------------|-------------|
| Configuration | Simple string fields | Low | Low |
| Service Decoration | Complete service override | High | Very High |
| Event Subscribers | Dynamic metadata, field overrides | Medium | High |

### Reference Other Documentation

Link to:
- Symfony EventDispatcher documentation
- RFC 9728 specification
- Drupal event subscriber guide

### Keep It Concise

Following project minimalism:
- Focus on practical examples developers will use
- Avoid theoretical explanations
- No comprehensive lists of all possible use cases
- Direct, actionable guidance

</details>
