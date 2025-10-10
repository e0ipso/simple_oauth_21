---
id: 5
group: 'functional-consolidation'
dependencies: [1]
status: 'pending'
created: '2025-10-10'
skills:
  - php
  - phpunit
  - drupal-backend
---

# Consolidate simple_oauth_device_flow Functional Tests

## Objective

Consolidate the simple_oauth_device_flow module's functional test(s) into a single test class with one comprehensive test method. This is the most complex functional test consolidation with ~9 test methods to convert to helpers.

## Skills Required

- **php**: PHP refactoring and method extraction
- **phpunit**: Understanding PHPUnit/Drupal BrowserTestBase patterns
- **drupal-backend**: Knowledge of Drupal testing framework and OAuth device flow

## Acceptance Criteria

- [ ] Single functional test class `DeviceFlowFunctionalTest` with one public test method
- [ ] All ~9 original test methods converted to protected helper methods
- [ ] Main test method calls helpers in logical flow order
- [ ] All test documentation and `@covers` annotations preserved
- [ ] RFC 8628 compliance tests fully preserved
- [ ] Test passes successfully after consolidation

## Technical Requirements

- File location: `modules/simple_oauth_device_flow/tests/src/Functional/DeviceFlowFunctionalTest.php`
- Understanding of RFC 8628 (Device Authorization Grant)
- Device flow involves: device authorization endpoint, user verification, token polling

## Input Dependencies

- Baseline metrics from task #1 for comparison

## Output Artifacts

- Consolidated functional test class with ~9 helper methods
- All RFC 8628 test scenarios preserved

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Analyze Complex Test Structure

Read `modules/simple_oauth_device_flow/tests/src/Functional/DeviceFlowFunctionalTest.php`:

Based on the plan analysis, this file has approximately 9 test methods including:
- `testDeviceAuthorizationEndpoint()` - ~26 lines
- `testDeviceAuthorizationWithInvalidClient()` - ~15 lines
- `testDeviceAuthorizationWithMissingClientId()` - ~13 lines
- `testDeviceVerificationForm()` - ~10 lines
- `testDeviceVerificationFlow()` - ~19 lines
- `testDeviceVerificationWithInvalidCode()` - ~12 lines
- `testTokenEndpointWithDeviceGrant()` - ~52 lines (most complex)
- `testTokenEndpointWithInvalidDeviceCode()` - ~15 lines
- Additional test methods for rate limiting, scopes, security, etc.

### Step 2: Identify Helper Method Dependencies

Some tests depend on others:
1. Device authorization must happen before verification
2. Verification must happen before successful token retrieval
3. Error cases can be tested independently

Organize helpers into:
- **Setup helpers**: `helperRequestDeviceAuthorization()` (already exists as protected method)
- **Happy path helpers**: Device auth → verification → token retrieval
- **Error case helpers**: Invalid client, missing params, expired codes, etc.

### Step 3: Create Comprehensive Test Method

```php
/**
 * Comprehensive RFC 8628 Device Authorization Grant test.
 *
 * Tests the complete device flow including:
 * - Device authorization endpoint (valid and error cases)
 * - User verification flow
 * - Token endpoint with device grant
 * - Device polling and rate limiting
 * - Security validations (single-use codes, expiration)
 * - Scope handling
 *
 * All scenarios execute sequentially using a shared Drupal instance
 * for optimal performance.
 */
public function testComprehensiveDeviceFlowFunctionality(): void {
  // Happy path flow
  $this->helperDeviceAuthorizationEndpoint();
  $this->helperDeviceVerificationForm();
  $this->helperDeviceVerificationFlow();
  $this->helperTokenEndpointWithDeviceGrant();

  // Error handling
  $this->helperDeviceAuthorizationWithInvalidClient();
  $this->helperDeviceAuthorizationWithMissingClientId();
  $this->helperDeviceVerificationWithInvalidCode();
  $this->helperTokenEndpointWithInvalidDeviceCode();

  // Advanced scenarios
  $this->helperDeviceFlowRateLimiting();
  $this->helperDeviceFlowWithScopes();
  $this->helperDeviceCodeSingleUse();
}
```

### Step 4: Convert Test Methods to Helpers

For each test method:

**Before:**
```php
/**
 * Tests device authorization endpoint functionality.
 *
 * @covers \Drupal\simple_oauth_device_flow\Controller\DeviceAuthorizationController::authorize
 */
public function testDeviceAuthorizationEndpoint(): void {
  $data = $this->requestDeviceAuthorization();

  // Verify required RFC 8628 response fields...
  $this->assertArrayHasKey('device_code', $data);
  // ... more assertions
}
```

**After:**
```php
/**
 * Helper: Tests device authorization endpoint functionality.
 *
 * Validates RFC 8628 device authorization endpoint returns proper
 * response structure with device_code, user_code, verification_uri,
 * expires_in, and interval fields.
 *
 * Originally: testDeviceAuthorizationEndpoint()
 *
 * @covers \Drupal\simple_oauth_device_flow\Controller\DeviceAuthorizationController::authorize
 */
protected function helperDeviceAuthorizationEndpoint(): void {
  $data = $this->requestDeviceAuthorization();

  // Verify required RFC 8628 response fields...
  $this->assertArrayHasKey('device_code', $data);
  // ... same assertions
}
```

### Step 5: Handle Test Interdependencies

Some helpers require fresh device codes:

```php
protected function helperTokenEndpointWithDeviceGrant(): void {
  // Get fresh device authorization for this test
  $device_data = $this->requestDeviceAuthorization();
  $device_code = $device_data['device_code'];

  // Test polling before authorization
  $response = $this->httpClient->post($token_url, [/* ... */]);
  $this->assertEquals('authorization_pending', $data['error']);

  // Authorize device
  $this->drupalLogin($this->testUser);
  $this->submitForm(['user_code' => $device_data['user_code']], 'Authorize');

  // Test successful token retrieval
  $response = $this->httpClient->post($token_url, [/* ... */]);
  $this->assertEquals(200, $response->getStatusCode());
}
```

### Step 6: State Management Between Helpers

Device flow tests create entities (device codes, tokens). Between helpers, ensure:

```php
public function testComprehensiveDeviceFlowFunctionality(): void {
  $this->helperDeviceAuthorizationEndpoint();

  // Logout if test logged in user
  if ($this->loggedInUser) {
    $this->drupalLogout();
  }

  $this->helperDeviceVerificationForm();
}
```

**Important**: Most state cleanup happens automatically through Drupal's test framework. Only add explicit cleanup if tests actually interfere.

### Step 7: Preserve Existing Helper Method

The file already has `protected function requestDeviceAuthorization()`. Keep this as-is; it's used by multiple test helpers.

### Step 8: Test Execution

```bash
cd /var/www/html
vendor/bin/phpunit modules/simple_oauth_device_flow/tests/src/Functional/DeviceFlowFunctionalTest.php -v
```

Monitor output to ensure all helper scenarios execute and pass.

### Step 9: Performance Validation

Time execution and compare to baseline:

```bash
time vendor/bin/phpunit modules/simple_oauth_device_flow/tests/src/Functional/DeviceFlowFunctionalTest.php
```

With 9 test methods consolidated into 1, expect ~8-9x faster execution (from 9 Drupal installations to 1).

### Critical Validations

**Must preserve**:
- RFC 8628 compliance tests (device code format, user code format, required fields)
- Security tests (device code single-use, expiration handling)
- Error handling (invalid client, missing params, authorization pending, slow_down)
- Integration tests (scope handling, token generation)

**Can trim** (if found):
- Tests that only validate response format without business logic
- Tests of upstream HTTP client behavior
- Duplicate error case tests

### Device Flow-Specific Testing Notes

RFC 8628 Device Flow is security-critical for IoT devices and limited-input devices. Key validations:

1. **Device codes must be single-use** - `helperDeviceCodeSingleUse()`
2. **Rate limiting** - `helperDeviceFlowRateLimiting()`
3. **User code format** - Must be user-friendly (short, clear characters)
4. **Polling interval enforcement** - Prevent rapid polling attacks
5. **Expiration handling** - Device codes expire

All these security validations MUST be preserved.

</details>
