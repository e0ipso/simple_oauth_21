---
id: 2
group: 'server-metadata-test-consolidation'
dependencies: []
status: 'completed'
created: '2025-10-11'
completed: '2025-10-11'
skills:
  - phpunit
  - drupal-testing
---

# Consolidate TokenRevocationEndpointTest into Single Test Method

## Objective

Refactor `TokenRevocationEndpointTest` from 14 individual test methods into a single `testComprehensiveTokenRevocationFunctionality()` method that calls protected helper methods, maintaining RFC 7009 compliance verification while reducing Drupal installation overhead.

## Skills Required

- **phpunit**: Expertise in PHPUnit test organization, method visibility, and complex test scenarios
- **drupal-testing**: Understanding of Drupal BrowserTestBase, OAuth entity testing, and HTTP client testing patterns

## Acceptance Criteria

- [ ] Single comprehensive test method `testComprehensiveTokenRevocationFunctionality()` created
- [ ] All 14 existing test methods converted to protected `helper*()` methods
- [ ] Private helper method `postRevocationRequest()` preserved unchanged
- [ ] RFC 7009 compliance test coverage maintained
- [ ] Test execution flow: setup → happy path → error cases → edge cases → metadata validation
- [ ] Class-level and method-level PHPDoc updated
- [ ] All tests pass after refactoring
- [ ] No test coverage loss

## Technical Requirements

**File to modify:**

- `modules/simple_oauth_server_metadata/tests/src/Functional/TokenRevocationEndpointTest.php`

**Refactoring pattern:**

```php
// Before:
public function testSuccessfulRevocationWithBasicAuth(): void { ... }
public function testAuthenticationFailureWithInvalidCredentials(): void { ... }

// After:
public function testComprehensiveTokenRevocationFunctionality(): void {
    // Happy path
    $this->helperSuccessfulRevocationWithBasicAuth();
    $this->helperSuccessfulRevocationWithPostBodyCredentials();

    // Error cases
    $this->helperAuthenticationFailureWithInvalidCredentials();
    // ... all other helpers
}

protected function helperSuccessfulRevocationWithBasicAuth(): void { ... }
protected function helperAuthenticationFailureWithInvalidCredentials(): void { ... }
```

**Methods to convert (14 total):**

1. `testSuccessfulRevocationWithBasicAuth()` → `helperSuccessfulRevocationWithBasicAuth()`
2. `testSuccessfulRevocationWithPostBodyCredentials()` → `helperSuccessfulRevocationWithPostBodyCredentials()`
3. `testPublicClientRevocation()` → `helperPublicClientRevocation()`
4. `testAuthenticationFailureWithInvalidCredentials()` → `helperAuthenticationFailureWithInvalidCredentials()`
5. `testAuthenticationFailureWithMissingCredentials()` → `helperAuthenticationFailureWithMissingCredentials()`
6. `testMissingTokenParameter()` → `helperMissingTokenParameter()`
7. `testBypassPermissionAllowsRevokingAnyToken()` → `helperBypassPermissionAllowsRevokingAnyToken()`
8. `testOwnershipValidationPreventsUnauthorizedRevocation()` → `helperOwnershipValidationPreventsUnauthorizedRevocation()`
9. `testNonExistentTokenReturnsSuccess()` → `helperNonExistentTokenReturnsSuccess()`
10. `testIdempotentRevocation()` → `helperIdempotentRevocation()`
11. `testTokenTypeHintParameter()` → `helperTokenTypeHintParameter()`
12. `testRefreshTokenRevocation()` → `helperRefreshTokenRevocation()`
13. `testServerMetadataIncludesRevocationEndpoint()` → `helperServerMetadataIncludesRevocationEndpoint()`
14. `testOnlyPostMethodAccepted()` → `helperOnlyPostMethodAccepted()`

**Preserve:**

- `setUp()` method unchanged (creates test client, token, sets up keys)
- Private helper method `postRevocationRequest()` - keep as `private`
- All class properties (`$testClient`, `$clientSecret`, `$testToken`)
- SimpleOauthTestTrait usage
- All OAuth entity creation and management

## Input Dependencies

None - this task can run in parallel with task 1.

## Output Artifacts

- Refactored `TokenRevocationEndpointTest.php` with single test method
- Updated PHPDoc documenting RFC 7009 compliance coverage

## Implementation Notes

<details>
<summary>Detailed Refactoring Steps</summary>

### Step 1: Create Comprehensive Test Method

Add the new comprehensive test method that calls all helpers in RFC 7009 test order:

```php
/**
 * Comprehensive RFC 7009 token revocation functionality test.
 *
 * Tests all OAuth 2.0 token revocation scenarios sequentially using a shared
 * Drupal instance for optimal performance. This consolidation reduces test
 * execution time by eliminating repeated Drupal installations.
 *
 * RFC 7009 compliance test coverage includes:
 * - Successful token revocation (Basic Auth and POST body credentials)
 * - Public client revocation
 * - Authentication failures (invalid/missing credentials)
 * - Missing token parameter validation
 * - Permission-based bypass for administrative revocation
 * - Ownership validation and privacy preservation
 * - Non-existent token handling
 * - Idempotent revocation behavior
 * - Token type hint parameter support
 * - Refresh token revocation
 * - Server metadata advertisement
 * - HTTP method restrictions
 *
 * All scenarios execute sequentially, maintaining test isolation through
 * proper cleanup and state management in helper methods.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7009
 */
public function testComprehensiveTokenRevocationFunctionality(): void {
  // Happy path scenarios
  $this->helperSuccessfulRevocationWithBasicAuth();
  $this->helperSuccessfulRevocationWithPostBodyCredentials();
  $this->helperPublicClientRevocation();

  // Authentication error cases
  $this->helperAuthenticationFailureWithInvalidCredentials();
  $this->helperAuthenticationFailureWithMissingCredentials();
  $this->helperMissingTokenParameter();

  // Permission and ownership scenarios
  $this->helperBypassPermissionAllowsRevokingAnyToken();
  $this->helperOwnershipValidationPreventsUnauthorizedRevocation();

  // Edge cases and RFC 7009 privacy requirements
  $this->helperNonExistentTokenReturnsSuccess();
  $this->helperIdempotentRevocation();

  // Token type handling
  $this->helperTokenTypeHintParameter();
  $this->helperRefreshTokenRevocation();

  // Metadata and HTTP method validation
  $this->helperServerMetadataIncludesRevocationEndpoint();
  $this->helperOnlyPostMethodAccepted();
}
```

### Step 2: Convert Test Methods to Helper Methods

For each existing test method:

1. Change method visibility from `public` to `protected`
2. Rename method from `test*()` to `helper*()`
3. Keep all method body code unchanged
4. Preserve existing PHPDoc with RFC references
5. Maintain all `@covers` annotations

**Example conversion:**

```php
// BEFORE
/**
 * Tests successful token revocation with HTTP Basic Auth credentials.
 *
 * Validates that a client can revoke its own token using Basic Auth
 * for client authentication as specified in RFC 7009.
 */
public function testSuccessfulRevocationWithBasicAuth(): void {
  // ... test code ...
}

// AFTER
/**
 * Helper: Tests successful token revocation with HTTP Basic Auth credentials.
 *
 * Validates that a client can revoke its own token using Basic Auth
 * for client authentication as specified in RFC 7009.
 */
protected function helperSuccessfulRevocationWithBasicAuth(): void {
  // ... same test code ...
}
```

### Step 3: Preserve Private Helper Method

The class contains a private helper method `postRevocationRequest()` that is used by multiple test methods. **Do NOT change this method** - it should remain `private`:

```php
/**
 * Helper method to POST to the revocation endpoint.
 *
 * @param array $formData
 *   The form data to POST.
 * @param array $headers
 *   Optional HTTP headers.
 *
 * @return \Psr\Http\Message\ResponseInterface
 *   The response object.
 */
private function postRevocationRequest(array $formData = [], array $headers = []): object {
  // ... existing implementation unchanged ...
}
```

### Step 4: Handle Entity Creation in setUp()

The `setUp()` method creates test OAuth entities (client, token). **Verify these are still created correctly** for use across all helper methods:

```php
protected function setUp(): void {
  parent::setUp();
  $this->setUpKeys();

  // Test client and token creation
  $this->clientSecret = 'test_client_secret_12345';
  $this->testClient = Consumer::create([...]);
  $this->testToken = Oauth2Token::create([...]);
}
```

### Step 5: Update Class-level PHPDoc

Update the class docblock to reflect consolidation and RFC 7009 compliance:

```php
/**
 * Tests the OAuth 2.0 token revocation endpoint (RFC 7009).
 *
 * Validates RFC 7009 compliance including client authentication,
 * token ownership validation, privacy preservation, and permission-based
 * bypass functionality for administrative token revocation.
 *
 * Tests are consolidated into a single comprehensive test method for
 * performance optimization while maintaining full RFC compliance coverage.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7009
 */
#[Group('simple_oauth_server_metadata')]
final class TokenRevocationEndpointTest extends BrowserTestBase {
```

### Step 6: Verify Test Execution Order

The helper execution order follows RFC 7009 testing flow:

1. **Happy path**: Successful revocations with various auth methods
2. **Authentication errors**: Invalid/missing credentials
3. **Authorization**: Bypass permissions and ownership validation
4. **Privacy & Edge cases**: Non-existent tokens, idempotency
5. **Token types**: Hints and refresh tokens
6. **Infrastructure**: Metadata advertisement, HTTP methods

This order ensures:

- Basic functionality works before testing errors
- State dependencies are clear
- RFC compliance scenarios are grouped logically

### Step 7: Entity Storage Cache Management

Several helpers reload entities to verify revocation. **Ensure cache clearing is present**:

```php
$storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
$storage->resetCache([$this->testToken->id()]);
$reloadedToken = $storage->load($this->testToken->id());
```

This pattern is already in the existing tests - verify it's preserved.

### Step 8: User Authentication for Bypass Permission Test

The `testBypassPermissionAllowsRevokingAnyToken()` test creates and logs in a user. This state should **not leak** to subsequent helpers. Consider adding logout or user switching if needed.

### Step 9: Run Tests

```bash
cd /var/www/html && vendor/bin/phpunit web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/tests/src/Functional/TokenRevocationEndpointTest.php
```

Verify:

- All 14 scenarios pass
- OAuth entities are created correctly
- Token revocation is actually verified (not just 200 responses)
- No state leakage between helpers

</details>

**Reference Implementation:**
Look at `DeviceFlowFunctionalTest::testComprehensiveDeviceFlowFunctionality()` which also tests OAuth flows with entity creation and complex scenarios.

**Critical Reminders:**

- **RFC 7009 Compliance**: All test scenarios must verify spec requirements
- **Entity Management**: Verify token revocation actually happens (not just HTTP 200)
- **Privacy Requirements**: Tests for non-existent tokens must return 200 (RFC privacy)
- **State Isolation**: User login/logout must not affect subsequent tests
- Use `final` keyword for test class (already present)
- Preserve `declare(strict_types=1);` at file top
