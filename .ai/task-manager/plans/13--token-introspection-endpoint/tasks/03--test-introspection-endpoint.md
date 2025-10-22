---
id: 3
group: 'token-introspection'
dependencies: [1, 2]
status: 'pending'
created: '2025-10-22'
skills:
  - drupal-backend
  - phpunit
---

# Test Token Introspection Endpoint

## Objective

Create comprehensive functional test validating all RFC 7662 behaviors, security constraints, and integration points for the token introspection endpoint.

## Skills Required

- **drupal-backend**: Drupal functional testing with BrowserTestBase, OAuth token creation, test data setup
- **phpunit**: PHPUnit assertions, test organization, functional test patterns

## Acceptance Criteria

- [ ] Functional test created at `modules/simple_oauth_server_metadata/tests/src/Functional/TokenIntrospectionTest.php`
- [ ] All RFC 7662 behaviors validated: authentication, authorization, token validation, request parameters, response format
- [ ] Integration points tested: server metadata advertisement, compliance dashboard status
- [ ] Security constraints verified: no token enumeration, consistent error responses, authorization enforcement
- [ ] Test follows single-method pattern for performance (all assertions in one test method)
- [ ] Test passes without errors when executed
- [ ] Code follows Drupal testing standards

Use your internal Todo tool to track these and keep on track.

## Technical Requirements

### Test File Structure

- **File**: `modules/simple_oauth_server_metadata/tests/src/Functional/TokenIntrospectionTest.php`
- **Base class**: `BrowserTestBase`
- **Modules**: `['simple_oauth', 'simple_oauth_21', 'simple_oauth_server_metadata', 'consumers']`
- **Test method**: `testTokenIntrospectionEndpoint()` with internal helper methods

### Test Coverage Requirements

**IMPORTANT**: This section contains critical guidance for test creation.

**Meaningful Test Strategy Guidelines**

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

**Test Task Creation Rules:**

- Combine related test scenarios into single tasks (e.g., "Test user authentication flow" not separate tasks for login, logout, validation)
- Focus on integration and critical path testing over unit test coverage
- Avoid creating separate tasks for testing each CRUD operation individually
- Question whether simple functions need dedicated test tasks

### Specific Test Assertions Required

1. **Authentication Tests**:
   - Valid Bearer token allows introspection (200 OK)
   - Missing Bearer token returns 401 Unauthorized
   - Invalid Bearer token returns 401 Unauthorized

2. **Authorization Tests**:
   - Token owner can introspect their own token (returns active: true)
   - Token owner cannot introspect other users' tokens (returns active: false)
   - User with bypass permission can introspect any token (returns active: true)
   - User without bypass permission limited to own tokens

3. **Token Validation Tests**:
   - Active valid token returns active: true with metadata
   - Expired token returns active: false
   - Revoked token returns active: false
   - Non-existent token returns active: false

4. **Request Parameter Tests**:
   - Missing `token` parameter returns 400 Bad Request
   - `token_type_hint` parameter accepted (access_token, refresh_token)
   - Both access tokens and refresh tokens can be introspected

5. **Response Format Tests**:
   - Required `active` field always present
   - Optional fields included for active tokens (scope, client_id, username, exp, etc.)
   - Inactive token response minimal (only {"active": false})
   - All RFC 7662 fields properly formatted (integers for timestamps, strings for IDs, etc.)

6. **Integration Tests**:
   - Introspection endpoint appears in server metadata (/.well-known/oauth-authorization-server)
   - Compliance dashboard shows RFC 7662 as configured
   - Endpoint accessible at /oauth/introspect

7. **Security Tests**:
   - Token values never exposed in responses
   - Consistent response for non-existent vs unauthorized tokens
   - No information disclosure in error messages

## Input Dependencies

- Task 1: TokenIntrospectionController, route, permission must exist
- Task 2: Server metadata and compliance integrations must be complete

## Output Artifacts

- `modules/simple_oauth_server_metadata/tests/src/Functional/TokenIntrospectionTest.php`
- Passing test results validating all RFC 7662 behaviors

## Implementation Notes

<details>
<summary>Detailed Implementation Guide</summary>

### Step 1: Examine Existing Test Patterns

Before writing the test, examine:

- `modules/simple_oauth_server_metadata/tests/src/Functional/ServerMetadataTest.php` - Functional test pattern reference
- Other functional tests in simple_oauth module for OAuth token creation patterns

### Step 2: Create Test File Structure

**File**: `modules/simple_oauth_server_metadata/tests/src/Functional/TokenIntrospectionTest.php`

**Basic structure**:

```php
<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for RFC 7662 Token Introspection endpoint.
 *
 * @group simple_oauth_server_metadata
 */
final class TokenIntrospectionTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_server_metadata',
    'consumers',
  ];

  /**
   * Test users.
   */
  private $user1;
  private $user2;
  private $adminUser;

  /**
   * Test OAuth clients.
   */
  private $client;

  /**
   * Test tokens.
   */
  private $validToken;
  private $expiredToken;
  private $revokedToken;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Create test users, clients, tokens
  }

  /**
   * Test token introspection endpoint RFC 7662 compliance.
   */
  public function testTokenIntrospectionEndpoint(): void {
    // All test assertions in single method for performance
    $this->doAuthenticationTests();
    $this->doAuthorizationTests();
    $this->doTokenValidationTests();
    $this->doRequestParameterTests();
    $this->doResponseFormatTests();
    $this->doIntegrationTests();
    $this->doSecurityTests();
  }

  // Private helper methods for each test category
}
```

### Step 3: Implement setUp() Method

Create test fixtures:

1. **Users**:
   - `$this->user1` - Regular user, owner of test tokens
   - `$this->user2` - Regular user, NOT owner of test tokens
   - `$this->adminUser` - User with "bypass token introspection restrictions" permission

2. **OAuth Client**:
   - Create consumer entity for OAuth client

3. **Tokens**:
   - `$this->validToken` - Active, non-expired access token for user1
   - `$this->expiredToken` - Expired access token for user1
   - `$this->revokedToken` - Revoked access token for user1
   - Create refresh token for user1 to test token_type_hint

**Example token creation**:

```php
$this->validToken = $this->entityTypeManager
  ->getStorage('oauth2_token')
  ->create([
    'auth_user_id' => $this->user1->id(),
    'client' => $this->client->id(),
    'value' => 'test_access_token_' . $this->randomMachineName(),
    'expire' => time() + 3600, // Valid for 1 hour
    'token_type' => 'access_token',
    'scope' => 'authenticated',
  ]);
$this->validToken->save();
```

### Step 4: Implement Helper Test Methods

Create private helper methods for each test category:

**doAuthenticationTests()**:

- Test POST to /oauth/introspect with valid Bearer token (expect 200)
- Test POST without Authorization header (expect 401)
- Test POST with invalid Bearer token (expect 401)

**doAuthorizationTests()**:

- Authenticate as user1, introspect user1's token (expect active: true)
- Authenticate as user2, introspect user1's token (expect active: false)
- Authenticate as adminUser, introspect user1's token (expect active: true)

**doTokenValidationTests()**:

- Introspect valid token (expect active: true with metadata)
- Introspect expired token (expect active: false)
- Introspect revoked token (expect active: false)
- Introspect non-existent token value (expect active: false)

**doRequestParameterTests()**:

- POST without `token` parameter (expect 400)
- POST with `token_type_hint=access_token` (expect success)
- POST with `token_type_hint=refresh_token` (expect success)
- Introspect refresh token (verify it works)

**doResponseFormatTests()**:

- Verify `active` field present in all responses
- Verify optional fields present for active tokens: scope, client_id, username, token_type, exp, iat, sub, aud, iss
- Verify inactive token response only contains `{"active": false}`
- Verify field types: exp/iat are integers, active is boolean, others are strings

**doIntegrationTests()**:

- Fetch `/.well-known/oauth-authorization-server` and verify `introspection_endpoint` field present
- Navigate to compliance dashboard, verify RFC 7662 status shown as "configured"

**doSecurityTests()**:

- Verify token value never appears in response JSON
- Verify non-existent token response identical to unauthorized token response
- Verify error responses don't contain sensitive information

### Step 5: Make HTTP Requests

Use Drupal test client for requests:

```php
// Example: POST to introspection endpoint
$response = $this->drupalPost(
  '/oauth/introspect',
  'application/x-www-form-urlencoded',
  ['token' => $this->validToken->get('value')->value],
  [
    'Authorization' => 'Bearer ' . $authenticating_token_value,
    'Content-Type' => 'application/x-www-form-urlencoded',
  ]
);

$json = json_decode($response, TRUE);
$this->assertTrue($json['active'], 'Active token should return active: true');
```

### Step 6: Run the Test

Execute the test:

```bash
cd /var/www/html && vendor/bin/phpunit --filter TokenIntrospectionTest web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/tests
```

**Expected output**: All assertions pass (green)

### Step 7: Handle Test Failures

If tests fail:

1. Check Drupal logs: `vendor/bin/drush watchdog:show`
2. Review test output in `sites/simpletest/browser_output/`
3. Verify route exists: `vendor/bin/drush route:debug simple_oauth_server_metadata.token_introspection`
4. Ensure cache is cleared: `vendor/bin/drush cache:rebuild`
5. Debug controller logic with error_log() or Xdebug

### Testing Best Practices

**Performance optimization**:

- Single test method reduces bootstrap overhead
- Create minimal test fixtures (only what's needed)
- Use helper methods for organization without separate test methods

**Assertion quality**:

- Use specific assertions (`assertEquals`, `assertTrue`, `assertArrayHasKey`) over generic `assert()`
- Include descriptive messages for each assertion
- Test both positive and negative cases

**RFC compliance**:

- Reference RFC 7662 specification for expected behavior
- Validate all required response fields
- Test optional fields are included when available

**Security validation**:

- Never assume security features work - test explicitly
- Verify unauthorized access is properly denied
- Check error responses don't leak information

### Example Full Assertion

```php
private function doAuthorizationTests(): void {
  // Create authenticating token for user1
  $user1_auth_token = $this->createToken($this->user1);

  // User1 introspects their own token - should succeed
  $response = $this->drupalPost(
    '/oauth/introspect',
    'application/x-www-form-urlencoded',
    ['token' => $this->validToken->get('value')->value],
    ['Authorization' => 'Bearer ' . $user1_auth_token]
  );
  $json = json_decode($response, TRUE);
  $this->assertTrue($json['active'], 'Token owner can introspect their own token');
  $this->assertArrayHasKey('scope', $json, 'Active token response includes scope');

  // Create authenticating token for user2
  $user2_auth_token = $this->createToken($this->user2);

  // User2 introspects user1's token - should fail (return active: false)
  $response = $this->drupalPost(
    '/oauth/introspect',
    'application/x-www-form-urlencoded',
    ['token' => $this->validToken->get('value')->value],
    ['Authorization' => 'Bearer ' . $user2_auth_token]
  );
  $json = json_decode($response, TRUE);
  $this->assertFalse($json['active'], 'User cannot introspect other users\' tokens');
  $this->assertCount(1, $json, 'Unauthorized response only contains active field');

  // Admin with bypass permission introspects user1's token - should succeed
  $admin_auth_token = $this->createToken($this->adminUser);
  $response = $this->drupalPost(
    '/oauth/introspect',
    'application/x-www-form-urlencoded',
    ['token' => $this->validToken->get('value')->value],
    ['Authorization' => 'Bearer ' . $admin_auth_token]
  );
  $json = json_decode($response, TRUE);
  $this->assertTrue($json['active'], 'User with bypass permission can introspect any token');
}
```

</details>
