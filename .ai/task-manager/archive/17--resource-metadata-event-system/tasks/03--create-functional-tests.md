---
id: 3
group: "testing"
dependencies: [2]
status: "pending"
created: 2025-11-03
skills:
  - "phpunit"
  - "drupal-backend"
---
# Create Functional Tests for Event System

## Objective

Create functional tests that verify the resource metadata event system works correctly, including event dispatch, metadata modification, field overrides, and multiple subscriber priorities.

## Skills Required

- **phpunit**: PHPUnit test writing, assertions, and test organization
- **drupal-backend**: Drupal functional testing, event subscribers, and service registration

## Acceptance Criteria

- [ ] Test event subscriber class created for testing purposes
- [ ] Test verifies event is dispatched when metadata is generated
- [ ] Test confirms metadata modifications from event subscriber appear in response
- [ ] Test validates field override functionality
- [ ] Test checks multiple subscribers with different priorities work correctly
- [ ] Test ensures RFC 9728 required fields remain intact after events
- [ ] Test validates CORS headers still work after modifications
- [ ] All existing tests still pass
- [ ] Code follows project testing patterns and standards

## Technical Requirements

**IMPORTANT - Meaningful Test Strategy Guidelines**

Your critical mantra for test generation is: "write a few tests, mostly integration".

**Definition of "Meaningful Tests":**
Tests that verify custom business logic, critical paths, and edge cases specific to the application. Focus on testing YOUR code, not the framework or library functionality.

**For This Task:**
- Focus on testing the event dispatch mechanism (our custom code)
- Test event subscriber modifications (integration between event system and metadata generation)
- Test critical path: event → modification → output
- Do NOT test Symfony EventDispatcher itself (framework functionality)
- Do NOT test basic getter/setter methods in event class (trivial functionality)

**Test Implementation:**

Create test in existing functional test class `ServerMetadataFunctionalTest` or create new test class following same pattern.

Test subscriber example (for testing purposes only):
```php
final class TestResourceMetadataSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [
      ResourceMetadataEvents::BUILD => ['onBuildMetadata', 0],
    ];
  }

  public function onBuildMetadata(ResourceMetadataEvent $event): void {
    $metadata = $event->getMetadata();

    // Add custom field
    $metadata['test_custom_field'] = 'test_value';

    // Override configured field
    $metadata['resource_documentation'] = 'https://test.example.com/docs';

    $event->setMetadata($metadata);
  }

}
```

**Test Cases:**

1. **Test Event Dispatch and Custom Field:**
   - Register test event subscriber
   - Request `/.well-known/oauth-protected-resource`
   - Assert custom field appears in response
   - Verify JSON structure is valid

2. **Test Field Override:**
   - Configure field in settings
   - Register subscriber that overrides field
   - Verify subscriber value wins
   - Verify original config accessible via `getOriginalConfig()`

3. **Test Multiple Subscribers with Priorities:**
   - Register two subscribers with different priorities
   - Verify higher priority subscriber runs first
   - Verify metadata reflects final state after both

4. **Test RFC 9728 Compliance Maintained:**
   - Verify required fields (`resource`, `authorization_servers`) still present
   - Verify validation still passes
   - Verify endpoint returns 200 status

## Input Dependencies

- Task 2: Event system must be integrated into service
- Existing test infrastructure (`ServerMetadataFunctionalTest` as pattern)

## Output Artifacts

- Test event subscriber class (in test code)
- Test methods in functional test class
- All tests passing

## Implementation Notes

<details>
<summary>Detailed Implementation Guidance</summary>

### Test File Location

Add tests to:
`web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/tests/src/Functional/ServerMetadataFunctionalTest.php`

OR create new test class:
`web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/tests/src/Functional/ResourceMetadataEventTest.php`

### Test Subscriber Registration

**Option 1**: Create test subscriber as nested class in test file
**Option 2**: Register subscriber dynamically in test setup using container

Example registration in setUp():
```php
protected function setUp(): void {
  parent::setUp();

  // Register test event subscriber
  $subscriber = new TestResourceMetadataSubscriber();
  \Drupal::service('event_dispatcher')->addSubscriber($subscriber);
}
```

### Test Pattern

Follow existing functional test patterns:
```php
public function testResourceMetadataEventDispatch(): void {
  // Request metadata endpoint
  $this->drupalGet('/.well-known/oauth-protected-resource');
  $this->assertSession()->statusCodeEquals(200);

  // Parse JSON response
  $response = $this->getSession()->getPage()->getContent();
  $metadata = Json::decode($response);

  // Assert custom field added by event subscriber
  $this->assertArrayHasKey('test_custom_field', $metadata);
  $this->assertEquals('test_value', $metadata['test_custom_field']);

  // Assert required fields still present
  $this->assertArrayHasKey('resource', $metadata);
  $this->assertArrayHasKey('authorization_servers', $metadata);
}
```

### Running Tests

```bash
# Run specific test class
cd /var/www/html && vendor/bin/phpunit \
  web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/tests/src/Functional/ServerMetadataFunctionalTest.php

# Run all server metadata tests
cd /var/www/html && vendor/bin/phpunit \
  web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/tests/
```

### What NOT to Test

Following minimization principles, do NOT create separate tests for:
- Event class getter/setter methods (trivial, obvious functionality)
- Symfony EventDispatcher functionality (framework code)
- Simple CRUD operations on metadata array (basic PHP)
- Configuration reading (already tested elsewhere)

Focus only on:
- Event integration with metadata generation
- Custom business logic added through events
- Critical path verification

</details>
