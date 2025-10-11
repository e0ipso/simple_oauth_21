---
id: 3
group: 'main-module-test-consolidation'
dependencies: []
status: 'completed'
created: '2025-10-11'
completed: '2025-10-11'
skills:
  - phpunit
  - drupal-testing
---

# Consolidate ClientRegistrationFunctionalTest into Single Test Method

## Objective

Refactor `ClientRegistrationFunctionalTest` from 6 individual test methods into a single `testComprehensiveClientRegistrationFunctionality()` method that calls protected helper methods, maintaining RFC 7591 compliance verification while eliminating repeated Drupal installations.

## Skills Required

- **phpunit**: Expertise in PHPUnit test organization, method visibility, and data dependencies between tests
- **drupal-testing**: Understanding of Drupal BrowserTestBase, HTTP client testing, and cache isolation patterns

## Acceptance Criteria

- [ ] Single comprehensive test method `testComprehensiveClientRegistrationFunctionality()` created
- [ ] All 6 existing test methods converted to protected `helper*()` methods
- [ ] `testClientRegistrationWorkflow()` refactored to helper that returns registration data
- [ ] Protected utility methods preserved: `clearAllTestCaches()`, `warmMetadataCache()`, `ensureCacheIsolation()`
- [ ] RFC 7591 compliance test coverage maintained
- [ ] Cache isolation patterns preserved
- [ ] Class-level and method-level PHPDoc updated
- [ ] All tests pass after refactoring
- [ ] No test coverage loss

## Technical Requirements

**File to modify:**

- `tests/src/Functional/ClientRegistrationFunctionalTest.php`

**Refactoring pattern with data sharing:**

```php
// Before:
public function testClientRegistrationWorkflow(): array {
    // ... registration logic ...
    return $response_data;
}

public function testClientManagementOperations(): void {
    $registration_data = $this->testClientRegistrationWorkflow();
    // ... use $registration_data ...
}

// After:
public function testComprehensiveClientRegistrationFunctionality(): void {
    $registration_data = $this->helperClientRegistrationWorkflow();
    $this->helperClientManagementOperations($registration_data);
    // ... other helpers
}

protected function helperClientRegistrationWorkflow(): array {
    // ... registration logic unchanged ...
    return $response_data;
}

protected function helperClientManagementOperations(array $registration_data): void {
    // ... use $registration_data ...
}
```

**Methods to convert (6 total):**

1. `testClientRegistrationWorkflow()` → `helperClientRegistrationWorkflow()` (returns array)
2. `testClientManagementOperations()` → `helperClientManagementOperations(array $registration_data)`
3. `testRegistrationErrorConditions()` → `helperRegistrationErrorConditions()`
4. `testMetadataEndpoints()` → `helperMetadataEndpoints()`
5. `testRegistrationTokenAuthentication()` → `helperRegistrationTokenAuthentication()`
6. `testCacheIsolationAndConsistency()` → `helperCacheIsolationAndConsistency()`

**Preserve (keep as protected):**

- `clearAllTestCaches()` method
- `warmMetadataCache()` method
- `ensureCacheIsolation()` method

## Input Dependencies

None - this task can run in parallel with tasks 1 and 2.

## Output Artifacts

- Refactored `ClientRegistrationFunctionalTest.php` with single test method
- Updated PHPDoc documenting RFC 7591 compliance coverage
- Preserved cache management utility methods

## Implementation Notes

<details>
<summary>Detailed Refactoring Steps</summary>

### Step 1: Analyze Data Dependencies

The `testClientRegistrationWorkflow()` method **returns data** used by other tests:

```php
public function testClientRegistrationWorkflow(): array {
  // ... registration logic ...
  return $response_data; // Contains client_id, registration_access_token, etc.
}

public function testClientManagementOperations(): void {
  $registration_data = $this->testClientRegistrationWorkflow();
  $client_id = $registration_data['client_id'];
  // ... uses registration data ...
}
```

**Solution**: Convert to helper that returns data and pass it to dependent helpers.

### Step 2: Create Comprehensive Test Method

```php
/**
 * Comprehensive client registration functionality test.
 *
 * Tests all RFC 7591 client registration and management scenarios
 * sequentially using a shared Drupal instance for optimal performance.
 * This consolidation reduces test execution time by eliminating repeated
 * Drupal installations.
 *
 * RFC 7591 compliance test coverage includes:
 * - Client registration workflow (POST /oauth/register)
 * - Client metadata retrieval (GET /oauth/register/{client_id})
 * - Client metadata updates (PUT /oauth/register/{client_id})
 * - Registration error conditions (empty body, invalid JSON, invalid URIs)
 * - OAuth metadata endpoints (RFC 8414, RFC 9728)
 * - Registration access token authentication
 * - Cache isolation and consistency across contexts
 *
 * All scenarios execute sequentially, maintaining test isolation through
 * proper cache clearing and state management in helper methods.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7591
 */
public function testComprehensiveClientRegistrationFunctionality(): void {
  // Register a client and get registration data
  $registration_data = $this->helperClientRegistrationWorkflow();

  // Test client management using registration data
  $this->helperClientManagementOperations($registration_data);

  // Test error handling
  $this->helperRegistrationErrorConditions();

  // Test metadata endpoints
  $this->helperMetadataEndpoints();

  // Test registration token authentication
  $this->helperRegistrationTokenAuthentication($registration_data);

  // Test cache isolation
  $this->helperCacheIsolationAndConsistency();
}
```

### Step 3: Refactor testClientRegistrationWorkflow

This is the **critical refactoring** because other tests depend on its return value:

```php
/**
 * Helper: Tests RFC 7591 client registration workflow.
 *
 * Validates that clients can register dynamically via the OAuth registration
 * endpoint and receive proper credentials and metadata.
 *
 * @return array<string, mixed>
 *   The client registration response data containing client_id,
 *   client_secret, registration_access_token, and registration_client_uri.
 */
protected function helperClientRegistrationWorkflow(): array {
  // Prepare valid RFC 7591 client registration request
  $client_metadata = [
    'client_name' => 'Test OAuth Client',
    'redirect_uris' => ['https://example.com/callback'],
    'grant_types' => ['authorization_code', 'refresh_token'],
    'response_types' => ['code'],
    'scope' => 'openid profile',
    'client_uri' => 'https://example.com',
    'logo_uri' => 'https://example.com/logo.png',
    'contacts' => ['admin@example.com'],
  ];

  // Make POST request to registration endpoint
  try {
    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => $client_metadata,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => TRUE,
    ]);
  }
  catch (\Exception $e) {
    $this->fail('Registration request failed: ' . $e->getMessage());
  }

  // Validate RFC 7591 response
  $this->assertEquals(200, $response->getStatusCode());
  $response->getBody()->rewind();
  $response_data = Json::decode($response->getBody()->getContents());

  // Assert required fields
  $this->assertNotEmpty($response_data['client_id']);
  $this->assertNotEmpty($response_data['client_secret']);
  $this->assertNotEmpty($response_data['registration_access_token']);
  $this->assertStringContainsString('/oauth/register/', $response_data['registration_client_uri']);

  // Assert metadata preservation
  $this->assertEquals('Test OAuth Client', $response_data['client_name']);
  $this->assertEquals(['https://example.com/callback'], $response_data['redirect_uris']);

  return $response_data;
}
```

### Step 4: Refactor Dependent Test Methods

Methods that previously called `testClientRegistrationWorkflow()` now receive data as parameter:

```php
/**
 * Helper: Tests client management operations using registration access token.
 *
 * Validates GET (read) and PUT (update) operations for registered clients.
 *
 * @param array<string, mixed> $registration_data
 *   Client registration data from helperClientRegistrationWorkflow().
 */
protected function helperClientManagementOperations(array $registration_data): void {
  $client_id = $registration_data['client_id'];
  $access_token = $registration_data['registration_access_token'];

  // Test GET client metadata
  $get_response = $this->httpClient->get($this->buildUrl("/oauth/register/{$client_id}"), [
    RequestOptions::HEADERS => [
      'Authorization' => "Bearer {$access_token}",
      'Accept' => 'application/json',
    ],
  ]);

  $this->assertEquals(200, $get_response->getStatusCode());
  // ... rest of test logic unchanged ...
}
```

### Step 5: Convert Independent Test Methods

Methods without dependencies are straightforward conversions:

```php
/**
 * Helper: Tests registration error conditions.
 *
 * Validates error handling for empty requests, invalid JSON, and invalid
 * redirect URIs per RFC 7591 error codes.
 */
protected function helperRegistrationErrorConditions(): void {
  // Test empty request body
  $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
    RequestOptions::HEADERS => [
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    ],
    RequestOptions::HTTP_ERRORS => FALSE,
  ]);

  $this->assertEquals(400, $response->getStatusCode());
  // ... rest of test logic unchanged ...
}
```

### Step 6: Preserve Cache Management Utilities

These methods should remain **protected** (not converted to helpers):

```php
/**
 * Clears all test-relevant caches for proper isolation.
 */
protected function clearAllTestCaches(): void {
  // ... existing implementation unchanged ...
}

/**
 * Warms the metadata cache for consistent test performance.
 */
protected function warmMetadataCache(): void {
  // ... existing implementation unchanged ...
}

/**
 * Ensures cache isolation before critical test operations.
 */
protected function ensureCacheIsolation(): void {
  // ... existing implementation unchanged ...
}
```

These are **utility methods** used by multiple helpers, not test scenarios themselves.

### Step 7: Handle setUp() Special Logic

The `setUp()` method has **special cache management and configuration**:

```php
protected function setUp(): void {
  parent::setUp();

  // ... HTTP client setup ...

  // Install entity base fields
  // ... field installation logic ...

  // Perform comprehensive cache clearing
  $this->clearAllTestCaches();

  // Ensure container rebuilt
  $this->rebuildContainer();

  // Test auto-detection mechanism
  // ... config and metadata service testing ...

  // Clear caches and warm metadata cache
  $this->clearAllTestCaches();
  $this->warmMetadataCache();
  $this->rebuildContainer();
}
```

**Do NOT modify setUp()** - this critical setup ensures the test environment is properly configured.

### Step 8: Update Class-level PHPDoc

```php
/**
 * Functional tests for RFC 7591 OAuth Dynamic Client Registration.
 *
 * Tests consolidated into a single comprehensive test method for performance
 * optimization while maintaining full RFC 7591 compliance coverage.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7591
 */
#[Group('simple_oauth_21')]
#[Group('functional')]
class ClientRegistrationFunctionalTest extends BrowserTestBase {
```

### Step 9: Run Tests

```bash
cd /var/www/html && vendor/bin/phpunit web/modules/contrib/simple_oauth_21/tests/src/Functional/ClientRegistrationFunctionalTest.php
```

Verify:

- Client registration workflow executes and returns data
- Dependent helpers receive and use registration data correctly
- Cache isolation patterns work correctly
- All assertions pass

</details>

**Key Challenge**: Data dependencies between tests require careful parameter passing.

**Reference Implementation:**
Look at `DeviceFlowFunctionalTest` which also has helpers that create test data (device codes) used by subsequent helpers.

**Critical Reminders:**

- **Data Flow**: `helperClientRegistrationWorkflow()` MUST return data
- **Parameter Passing**: Dependent helpers receive data as parameters
- **Cache Management**: Preserve all three cache utility methods unchanged
- **setUp() Complexity**: Do not modify the complex setUp() logic
- Follow RFC 7591 compliance requirements
