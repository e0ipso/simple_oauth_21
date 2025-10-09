---
id: 4
group: 'testing'
dependencies: [1, 2, 3]
status: 'pending'
created: '2025-10-09'
skills:
  - drupal-backend
  - phpunit
---

# Update Tests for Field-Based Scope Storage

## Objective

Update all Device Flow tests to work with oauth2_scope_reference field instead of serialized data, ensuring comprehensive coverage of the refactored implementation.

## Skills Required

- **drupal-backend**: Understanding of Drupal testing patterns, entity assertions, and field API testing
- **phpunit**: Proficiency in PHPUnit test writing, test fixtures, and assertion methods

## Acceptance Criteria

- [ ] All existing Device Flow tests pass with updated implementation
- [ ] Scope assertions updated to use field API
- [ ] Multi-value scope assignment tested
- [ ] Field validation behavior verified
- [ ] Migration update hook tested
- [ ] Zero test failures after refactoring

Use your internal Todo tool to track these and keep on track.

## Technical Requirements

**IMPORTANT** - Follow these Meaningful Test Strategy Guidelines:

Your critical mantra for test generation is: "write a few tests, mostly integration".

**Definition of "Meaningful Tests":**
Tests that verify custom business logic, critical paths, and edge cases specific to the application. Focus on testing YOUR code, not the framework or library functionality.

**When TO Write Tests:**

- Custom business logic and algorithms
- Critical user workflows and data transformations
- Edge cases and error conditions for core functionality
- Integration points between different system components
- Complex validation logic or calculations

**When NOT to Write Tests:**

- Third-party library functionality (already tested upstream)
- Framework features (React hooks, Express middleware, etc.)
- Simple CRUD operations without custom logic
- Getter/setter methods or basic property access
- Configuration files or static data
- Obvious functionality that would break immediately if incorrect

**Files to update:**

1. **Entity Tests**: Test field-based scope storage and retrieval
2. **Service Tests**: Test DeviceCodeService with field API
3. **Functional Tests**: Test end-to-end device flow with scopes
4. **Migration Test**: Test update hook data migration

**Key test updates:**

- Replace serialization checks with field API assertions
- Test multi-value scope storage
- Verify scope validation
- Test migration data integrity

## Input Dependencies

- Tasks 1, 2, 3 completed (entity, schema, and service updates)
- Current test files in `modules/simple_oauth_device_flow/tests/src/`
- Understanding of Drupal's test base classes and patterns

## Output Artifacts

- Updated test files with field API assertions
- New migration test for update hooks
- Passing test suite for device flow module
- Test coverage for scope field behavior

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Identify Test Files to Update

Locate all test files:

```bash
find modules/simple_oauth_device_flow/tests -name "*Test.php"
```

Key test files to update:

- `tests/src/Kernel/DeviceCodeEntityTest.php` (if exists)
- `tests/src/Unit/DeviceCodeServiceTest.php` (if exists)
- `tests/src/Functional/DeviceFlowFunctionalTest.php` (if exists)

### Step 2: Update Entity Tests

**Replace serialization assertions:**

```php
// BEFORE:
$scopes_data = $device_code->get('scopes')->value;
$this->assertNotEmpty($scopes_data);
$scopes = unserialize($scopes_data, ['allowed_classes' => FALSE]);
$this->assertIsArray($scopes);
$this->assertContains('read', $scopes);

// AFTER:
$scopes = $device_code->get('scopes')->getScopes();
$this->assertIsArray($scopes);
$this->assertCount(1, $scopes);
$this->assertEquals('read', $scopes[0]->getIdentifier());
```

**Test multi-value scope storage:**

```php
public function testMultipleScopeStorage() {
  $device_code = DeviceCode::create([
    'device_code' => 'test_device_code',
    'user_code' => 'ABCD-EFGH',
    'client_id' => 'test_client',
    'created_at' => time(),
    'expires_at' => time() + 600,
  ]);

  // Add multiple scopes.
  $device_code->get('scopes')->appendItem(['scope_id' => 'read']);
  $device_code->get('scopes')->appendItem(['scope_id' => 'write']);
  $device_code->get('scopes')->appendItem(['scope_id' => 'delete']);
  $device_code->save();

  // Reload and verify.
  $device_code = DeviceCode::load($device_code->id());
  $scopes = $device_code->get('scopes')->getScopes();

  $this->assertCount(3, $scopes);
  $scope_ids = array_map(fn($s) => $s->getIdentifier(), $scopes);
  $this->assertContains('read', $scope_ids);
  $this->assertContains('write', $scope_ids);
  $this->assertContains('delete', $scope_ids);
}
```

### Step 3: Update Service Tests

**Update DeviceCodeService tests:**

```php
public function testDeviceAuthorizationWithScopes() {
  $service = \Drupal::service('simple_oauth_device_flow.device_code');

  $result = $service->generateDeviceAuthorization(
    'test_client',
    'read write'  // Space-separated scopes
  );

  $device_code = DeviceCode::load($result['device_code']);
  $scopes = $device_code->get('scopes')->getScopes();

  $this->assertCount(2, $scopes);
  $scope_ids = array_map(fn($s) => $s->getIdentifier(), $scopes);
  $this->assertContains('read', $scope_ids);
  $this->assertContains('write', $scope_ids);
}
```

### Step 4: Update Functional Tests

**Update end-to-end scope flow tests:**

```php
public function testDeviceFlowWithScopes() {
  // Request device code with scopes.
  $response = $this->post('/oauth/device', [
    'client_id' => $this->client->uuid(),
    'scope' => 'authenticated profile:read',
  ]);

  $this->assertSession()->statusCodeEquals(200);
  $data = Json::decode($response);

  // Authorize device with scopes.
  $this->drupalLogin($this->user);
  $this->drupalGet('/oauth/device/verify', [
    'query' => ['user_code' => $data['user_code']],
  ]);

  // Verify scopes are properly stored.
  $device_code = \Drupal::entityTypeManager()
    ->getStorage('oauth2_device_code')
    ->loadByProperties(['user_code' => $data['user_code']]);

  $device_code = reset($device_code);
  $scopes = $device_code->get('scopes')->getScopes();
  $this->assertCount(2, $scopes);
}
```

### Step 5: Create Migration Test

**Create new test for update hooks:**

```php
namespace Drupal\Tests\simple_oauth_device_flow\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth_device_flow\Entity\DeviceCode;

/**
 * Tests the device code scope field migration.
 *
 * @group simple_oauth_device_flow
 */
class DeviceCodeScopeMigrationTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'simple_oauth',
    'simple_oauth_device_flow',
  ];

  public function testScopeMigration() {
    $this->installEntitySchema('oauth2_device_code');
    $this->installEntitySchema('oauth2_scope');

    // Create test scopes.
    $scope1 = \Drupal::entityTypeManager()
      ->getStorage('oauth2_scope')
      ->create(['name' => 'read']);
    $scope1->save();

    $scope2 = \Drupal::entityTypeManager()
      ->getStorage('oauth2_scope')
      ->create(['name' => 'write']);
    $scope2->save();

    // Create device code with serialized scopes (simulating old data).
    $database = \Drupal::database();
    $database->insert('oauth2_device_code')
      ->fields([
        'device_code' => 'test_code',
        'user_code' => 'TEST1234',
        'client_id' => 'test_client',
        'scopes' => serialize(['read', 'write']),
        'created_at' => time(),
        'expires_at' => time() + 600,
        'interval' => 5,
      ])
      ->execute();

    // Run the migration update hook.
    module_load_include('install', 'simple_oauth_device_flow');
    $sandbox = [];
    simple_oauth_device_flow_update_11002($sandbox);

    // Verify migration.
    $device_code = DeviceCode::load('test_code');
    $scopes = $device_code->get('scopes')->getScopes();

    $this->assertCount(2, $scopes);
    $scope_ids = array_map(fn($s) => $s->getIdentifier(), $scopes);
    $this->assertContains('read', $scope_ids);
    $this->assertContains('write', $scope_ids);
  }

  public function testMigrationWithInvalidScope() {
    $this->installEntitySchema('oauth2_device_code');
    $this->installEntitySchema('oauth2_scope');

    // Create device code with non-existent scope.
    $database = \Drupal::database();
    $database->insert('oauth2_device_code')
      ->fields([
        'device_code' => 'test_code_invalid',
        'user_code' => 'TEST5678',
        'client_id' => 'test_client',
        'scopes' => serialize(['nonexistent_scope']),
        'created_at' => time(),
        'expires_at' => time() + 600,
        'interval' => 5,
      ])
      ->execute();

    // Run migration - should not fail.
    module_load_include('install', 'simple_oauth_device_flow');
    $sandbox = [];
    simple_oauth_device_flow_update_11002($sandbox);

    // Verify device code exists but has no scopes.
    $device_code = DeviceCode::load('test_code_invalid');
    $scopes = $device_code->get('scopes')->getScopes();
    $this->assertEmpty($scopes);
  }
}
```

### Step 6: Run Tests and Verify

After updating tests, run the test suite:

```bash
cd /var/www/html
vendor/bin/phpunit web/modules/contrib/simple_oauth_21/modules/simple_oauth_device_flow/tests
```

### Important Considerations

- **Focus on business logic**: Test scope storage, retrieval, and migration - not Field API itself
- **Integration tests**: Focus on end-to-end workflows rather than unit testing field operations
- **Edge cases**: Test empty scopes, invalid scopes, and multiple scopes
- **Migration testing**: Verify data integrity during migration
- **NEVER write test-specific code**: Don't add environment detection or conditional logic to production code
- **Fix root causes**: If tests fail, fix the implementation, not the test

### Common Test Patterns to Update

1. **Scope retrieval**: Use `$device_code->get('scopes')->getScopes()`
2. **Scope addition**: Use `$device_code->get('scopes')->appendItem(['scope_id' => $id])`
3. **Scope count**: Use `count($device_code->get('scopes')->getScopes())`
4. **Scope IDs**: Use `array_map(fn($s) => $s->getIdentifier(), $scopes)`

</details>
