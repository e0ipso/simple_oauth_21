---
id: 6
group: 'testing'
dependencies: [3, 4, 5]
status: 'pending'
created: 2025-10-09
skills:
  - php
  - drupal-backend
---

# RFC 7009 Compliance Functional Tests

## Objective

Create comprehensive functional tests that validate the token revocation endpoint's RFC 7009 compliance, security controls, permission handling, and integration with server metadata through full HTTP request/response cycles.

## Skills Required

- **php**: Write PHPUnit test cases with assertions and test data setup
- **drupal-backend**: Use Drupal testing framework (BrowserTestBase), create test fixtures, and validate OAuth workflows

## Acceptance Criteria

**IMPORTANT: Copy the "Meaningful Test Strategy Guidelines" below into this task and keep them in mind:**

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

---

### Test Coverage Requirements

- [ ] Test successful token revocation with valid client credentials
- [ ] Test revocation with HTTP Basic Auth credentials
- [ ] Test revocation with POST body credentials
- [ ] Test public client authentication (no secret)
- [ ] Test revocation of access tokens
- [ ] Test revocation of refresh tokens
- [ ] Test client authentication failures (401 response)
- [ ] Test missing token parameter (400 response)
- [ ] Test permission-based bypass (admin revoking any token)
- [ ] Test ownership validation (client cannot revoke others' tokens)
- [ ] Test idempotent revocation (revoking already-revoked token returns 200)
- [ ] Test privacy preservation (non-existent token returns 200)
- [ ] Test server metadata includes revocation_endpoint URL
- [ ] All tests follow Drupal BrowserTestBase patterns
- [ ] Test class has comprehensive PHPDoc comments

## Technical Requirements

**File Location:** `simple_oauth_server_metadata/tests/src/Functional/TokenRevocationEndpointTest.php`

**Test Base Class:** `BrowserTestBase` (for full HTTP testing)

**Test Fixtures Needed:**

- OAuth consumer entities (confidential and public clients)
- OAuth tokens (access and refresh) owned by test clients
- Drupal user with `bypass token revocation restrictions` permission
- Test credentials for client authentication

**Key Test Scenarios:**

1. **Happy Path Tests:**
   - Valid revocation with Basic Auth
   - Valid revocation with POST body auth
   - Revocation by token owner
   - Revocation with bypass permission

2. **Error Path Tests:**
   - Missing client credentials (401)
   - Invalid client credentials (401)
   - Missing token parameter (400)
   - Ownership violation (200 but token not revoked)

3. **RFC Compliance Tests:**
   - HTTP 200 for non-existent tokens (privacy)
   - HTTP 200 for already-revoked tokens (idempotency)
   - Support for token_type_hint parameter
   - POST method only (GET/PUT/DELETE should fail)

4. **Integration Tests:**
   - Server metadata includes revocation_endpoint
   - Endpoint URL is absolute HTTPS

## Input Dependencies

**From Task 3:**

- `TokenRevocationController` must be implemented

**From Task 4:**

- Route `simple_oauth_server_metadata.revoke` must exist
- Permission `bypass token revocation restrictions` must be defined

**From Task 5:**

- `EndpointDiscoveryService` must include revocation endpoint in metadata

## Output Artifacts

- `simple_oauth_server_metadata/tests/src/Functional/TokenRevocationEndpointTest.php`

This test suite ensures:

- RFC 7009 compliance
- Security controls function correctly
- Integration with existing Simple OAuth infrastructure

## Implementation Notes

<details>
<summary>Detailed Implementation Guide</summary>

### Test Class Structure

```php
<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\consumer\Entity\Consumer;
use Drupal\simple_oauth\Entity\Oauth2Token;

/**
 * Tests the OAuth 2.0 token revocation endpoint (RFC 7009).
 *
 * @group simple_oauth_server_metadata
 */
final class TokenRevocationEndpointTest extends BrowserTestBase {

  protected static $modules = [
    'simple_oauth',
    'simple_oauth_server_metadata',
    'serialization',
  ];

  protected $defaultTheme = 'stark';

  private Consumer $testClient;
  private Oauth2Token $testToken;

  protected function setUp(): void {
    parent::setUp();
    // Create test fixtures
  }

  /**
   * Tests successful token revocation with Basic Auth.
   */
  public function testSuccessfulRevocationWithBasicAuth(): void {
    // Test implementation
  }

  // ... more test methods
}
```

### Creating Test Fixtures

```php
protected function setUp(): void {
  parent::setUp();

  // Create a test OAuth client (consumer)
  $this->testClient = Consumer::create([
    'label' => 'Test Client',
    'client_id' => 'test_client_id',
    'secret' => 'test_client_secret',
    'is_default' => FALSE,
    'confidential' => TRUE,
  ]);
  $this->testClient->save();

  // Create a test access token owned by this client
  $this->testToken = Oauth2Token::create([
    'auth_user_id' => $this->rootUser->id(),
    'client' => $this->testClient,
    'value' => 'test_token_value',
    'scopes' => [],
    'expire' => time() + 3600,
  ]);
  $this->testToken->save();
}
```

### Test: Successful Revocation with Basic Auth

```php
public function testSuccessfulRevocationWithBasicAuth(): void {
  $credentials = base64_encode('test_client_id:test_client_secret');

  $response = $this->drupalPost('/oauth/revoke', [
    'token' => 'test_token_value',
  ], [
    'headers' => [
      'Authorization' => 'Basic ' . $credentials,
    ],
  ]);

  $this->assertEquals(200, $response->getStatusCode());

  // Verify token was actually revoked
  $this->testToken = $this->reloadEntity($this->testToken);
  $this->assertTrue($this->testToken->isRevoked());
}
```

### Test: Authentication Failure

```php
public function testAuthenticationFailure(): void {
  $response = $this->drupalPost('/oauth/revoke', [
    'token' => 'test_token_value',
  ], [
    'headers' => [
      'Authorization' => 'Basic ' . base64_encode('invalid:credentials'),
    ],
  ]);

  $this->assertEquals(401, $response->getStatusCode());

  // Verify token was NOT revoked
  $this->testToken = $this->reloadEntity($this->testToken);
  $this->assertFalse($this->testToken->isRevoked());
}
```

### Test: Missing Token Parameter

```php
public function testMissingTokenParameter(): void {
  $credentials = base64_encode('test_client_id:test_client_secret');

  $response = $this->drupalPost('/oauth/revoke', [], [
    'headers' => [
      'Authorization' => 'Basic ' . $credentials,
    ],
  ]);

  $this->assertEquals(400, $response->getStatusCode());

  $data = json_decode($response->getBody(), TRUE);
  $this->assertEquals('invalid_request', $data['error']);
}
```

### Test: Privacy Preservation (Non-existent Token)

```php
public function testNonExistentTokenReturnsSuccess(): void {
  $credentials = base64_encode('test_client_id:test_client_secret');

  $response = $this->drupalPost('/oauth/revoke', [
    'token' => 'nonexistent_token_value',
  ], [
    'headers' => [
      'Authorization' => 'Basic ' . $credentials,
    ],
  ]);

  // RFC 7009: Should return 200 even for non-existent tokens (privacy)
  $this->assertEquals(200, $response->getStatusCode());
}
```

### Test: Bypass Permission

```php
public function testBypassPermissionAllowsRevokingAnyToken(): void {
  // Create another client and token
  $otherClient = Consumer::create([
    'label' => 'Other Client',
    'client_id' => 'other_client_id',
    'secret' => 'other_client_secret',
    'confidential' => TRUE,
  ]);
  $otherClient->save();

  $otherToken = Oauth2Token::create([
    'auth_user_id' => $this->rootUser->id(),
    'client' => $otherClient,
    'value' => 'other_token_value',
    'scopes' => [],
    'expire' => time() + 3600,
  ]);
  $otherToken->save();

  // Grant bypass permission to a user
  $adminUser = $this->createUser(['bypass token revocation restrictions']);
  $this->drupalLogin($adminUser);

  // testClient tries to revoke otherClient's token
  $credentials = base64_encode('test_client_id:test_client_secret');

  $response = $this->drupalPost('/oauth/revoke', [
    'token' => 'other_token_value',
  ], [
    'headers' => [
      'Authorization' => 'Basic ' . $credentials,
    ],
  ]);

  $this->assertEquals(200, $response->getStatusCode());

  // Verify token WAS revoked (because of bypass permission)
  $otherToken = $this->reloadEntity($otherToken);
  $this->assertTrue($otherToken->isRevoked());
}
```

### Test: Ownership Validation

```php
public function testOwnershipValidationPreventsUnauthorizedRevocation(): void {
  // Create another client and token
  $otherClient = Consumer::create([
    'label' => 'Other Client',
    'client_id' => 'other_client_id',
    'secret' => 'other_client_secret',
    'confidential' => TRUE,
  ]);
  $otherClient->save();

  $otherToken = Oauth2Token::create([
    'auth_user_id' => $this->rootUser->id(),
    'client' => $otherClient,
    'value' => 'other_token_value',
    'scopes' => [],
    'expire' => time() + 3600,
  ]);
  $otherToken->save();

  // testClient tries to revoke otherClient's token (no bypass permission)
  $credentials = base64_encode('test_client_id:test_client_secret');

  $response = $this->drupalPost('/oauth/revoke', [
    'token' => 'other_token_value',
  ], [
    'headers' => [
      'Authorization' => 'Basic ' . $credentials,
    ],
  ]);

  // RFC 7009: Return 200 (don't reveal ownership failure)
  $this->assertEquals(200, $response->getStatusCode());

  // Verify token was NOT revoked
  $otherToken = $this->reloadEntity($otherToken);
  $this->assertFalse($otherToken->isRevoked());
}
```

### Test: Server Metadata Integration

```php
public function testServerMetadataIncludesRevocationEndpoint(): void {
  $response = $this->drupalGet('/.well-known/oauth-authorization-server');
  $this->assertEquals(200, $response->getStatusCode());

  $metadata = json_decode($response->getBody(), TRUE);

  $this->assertArrayHasKey('revocation_endpoint', $metadata);
  $this->assertStringContainsString('/oauth/revoke', $metadata['revocation_endpoint']);

  // Verify it's an absolute URL
  $this->assertStringStartsWith('http', $metadata['revocation_endpoint']);
}
```

### Test: Idempotent Revocation

```php
public function testIdempotentRevocation(): void {
  $credentials = base64_encode('test_client_id:test_client_secret');

  // First revocation
  $response1 = $this->drupalPost('/oauth/revoke', [
    'token' => 'test_token_value',
  ], [
    'headers' => [
      'Authorization' => 'Basic ' . $credentials,
    ],
  ]);
  $this->assertEquals(200, $response1->getStatusCode());

  // Second revocation (same token)
  $response2 = $this->drupalPost('/oauth/revoke', [
    'token' => 'test_token_value',
  ], [
    'headers' => [
      'Authorization' => 'Basic ' . $credentials,
    ],
  ]);
  $this->assertEquals(200, $response2->getStatusCode());

  // Token should still be revoked
  $this->testToken = $this->reloadEntity($this->testToken);
  $this->assertTrue($this->testToken->isRevoked());
}
```

### Test: Refresh Token Revocation

```php
public function testRefreshTokenRevocation(): void {
  // Create a refresh token
  $refreshToken = Oauth2RefreshToken::create([
    'auth_user_id' => $this->rootUser->id(),
    'client' => $this->testClient,
    'value' => 'test_refresh_token',
    'scopes' => [],
    'expire' => time() + 7200,
  ]);
  $refreshToken->save();

  $credentials = base64_encode('test_client_id:test_client_secret');

  $response = $this->drupalPost('/oauth/revoke', [
    'token' => 'test_refresh_token',
    'token_type_hint' => 'refresh_token',
  ], [
    'headers' => [
      'Authorization' => 'Basic ' . $credentials,
    ],
  ]);

  $this->assertEquals(200, $response->getStatusCode());

  // Verify refresh token was revoked
  $refreshToken = $this->reloadEntity($refreshToken);
  $this->assertTrue($refreshToken->isRevoked());
}
```

### Running the Tests

```bash
# Run all tests for the module
cd /var/www/html && vendor/bin/phpunit web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/tests

# Run specific test class
vendor/bin/phpunit web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/tests/src/Functional/TokenRevocationEndpointTest.php

# Run with coverage report
vendor/bin/phpunit --coverage-html coverage/
```

### Test Organization

Group related tests using descriptive method names:

- `testSuccessful*` - Happy path scenarios
- `testAuthentication*` - Authentication-related tests
- `testError*` - Error condition tests
- `testRfc*` - RFC compliance tests
- `testPermission*` - Permission-based tests

### Common Testing Pitfalls to Avoid

1. **Token hashing:** Ensure test tokens are created with proper value hashing (check Simple OAuth's token creation)
2. **Client secrets:** Use plain text secrets in test fixtures (hashing handled by Consumer entity)
3. **Entity reloading:** Always reload entities from database to verify changes
4. **HTTP methods:** Use appropriate methods (POST for revocation, GET for metadata)
5. **Assertions:** Use specific assertions (`assertEquals`, not `assertTrue($a == $b)`)

### Code Coverage Goals

Aim for 100% code coverage of:

- `TokenRevocationController::revoke()`
- All error paths (401, 400)
- All success paths (200)
- Permission checking logic
- Ownership validation logic

</details>
