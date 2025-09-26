---
id: 10
group: 'testing'
dependencies: [9]
status: 'pending'
created: '2025-09-26'
skills: ['phpunit', 'drupal-backend']
---

# Implement Functional Testing for Device Flow

## Objective

Create comprehensive functional tests that validate the complete RFC 8628 device flow end-to-end, including device authorization, user verification, token exchange, and error conditions.

## Skills Required

- **phpunit**: Test writing, assertions, mocking
- **drupal-backend**: Drupal testing patterns, functional tests

## Acceptance Criteria

- [ ] Complete device flow integration test
- [ ] Device authorization endpoint tests
- [ ] User verification form tests
- [ ] Token exchange with device_code grant tests
- [ ] Error condition tests (expired, invalid codes)
- [ ] Security validation tests
- [ ] Performance and cleanup tests

## Technical Requirements

- Write meaningful tests focusing on custom business logic
- Test critical user workflows and integration points
- Validate RFC 8628 compliance
- Use Drupal's BrowserTestBase for functional tests
- Mock external dependencies appropriately

## Input Dependencies

- All implementation tasks (1-9) completed
- Device flow components functional

## Output Artifacts

- tests/src/Functional/DeviceFlowTest.php
- Complete end-to-end test coverage
- Security and compliance validation

## Implementation Notes

<details>
<summary>Detailed implementation guidance</summary>

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

**Test structure:**

```php
class DeviceFlowTest extends BrowserTestBase {

  protected $defaultTheme = 'stark';
  protected static $modules = [
    'simple_oauth',
    'consumers',
    'simple_oauth_21',
    'simple_oauth_device_flow',
  ];

  public function testCompleteDeviceFlow(): void {
    // Create OAuth client
    $client = $this->createOAuthClient();

    // 1. Test device authorization request
    $response = $this->requestDeviceAuthorization($client);
    $this->assertDeviceAuthorizationResponse($response);

    // 2. Test user verification flow
    $userCode = $response['user_code'];
    $this->verifyUserCode($userCode);

    // 3. Test token exchange
    $deviceCode = $response['device_code'];
    $tokenResponse = $this->exchangeDeviceCodeForToken($client, $deviceCode);
    $this->assertValidAccessToken($tokenResponse);
  }

  public function testDeviceAuthorizationEndpoint(): void {
    $client = $this->createOAuthClient();

    // Test valid request
    $response = $this->requestDeviceAuthorization($client);
    $this->assertArrayHasKey('device_code', $response);
    $this->assertArrayHasKey('user_code', $response);
    $this->assertArrayHasKey('verification_uri', $response);

    // Test invalid client
    $this->requestDeviceAuthorizationWithInvalidClient();
    $this->assertSession()->statusCodeEquals(400);
  }

  public function testUserVerificationForm(): void {
    // Create device code
    $deviceCode = $this->createTestDeviceCode();

    // Test form display
    $this->drupalGet('/oauth/device');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('user_code');

    // Test valid code submission
    $this->submitForm(['user_code' => $deviceCode->getUserCode()], 'Authorize Device');
    $this->assertSession()->pageTextContains('Device authorization successful');
  }

  public function testTokenExchangeStates(): void {
    $client = $this->createOAuthClient();
    $deviceCode = $this->createTestDeviceCode();

    // Test authorization_pending
    $response = $this->exchangeDeviceCodeForToken($client, $deviceCode->getIdentifier());
    $this->assertEquals('authorization_pending', $response['error']);

    // Test after authorization
    $deviceCode->setUserApproved(true);
    $deviceCode->setUserIdentifier($this->createUser()->id());
    $deviceCode->save();

    $response = $this->exchangeDeviceCodeForToken($client, $deviceCode->getIdentifier());
    $this->assertArrayHasKey('access_token', $response);
  }

  public function testErrorConditions(): void {
    $client = $this->createOAuthClient();

    // Test expired device code
    $expiredCode = $this->createExpiredDeviceCode();
    $response = $this->exchangeDeviceCodeForToken($client, $expiredCode->getIdentifier());
    $this->assertEquals('expired_token', $response['error']);

    // Test invalid device code
    $response = $this->exchangeDeviceCodeForToken($client, 'invalid-code');
    $this->assertEquals('invalid_grant', $response['error']);
  }

  public function testPollingInterval(): void {
    $client = $this->createOAuthClient();
    $deviceCode = $this->createTestDeviceCode();

    // First request should work
    $this->exchangeDeviceCodeForToken($client, $deviceCode->getIdentifier());

    // Immediate second request should return slow_down
    $response = $this->exchangeDeviceCodeForToken($client, $deviceCode->getIdentifier());
    $this->assertEquals('slow_down', $response['error']);
  }

  public function testCleanupProcess(): void {
    // Create expired device codes
    $expiredCodes = $this->createExpiredDeviceCodes(5);

    // Run cleanup
    $service = \Drupal::service('simple_oauth_device_flow.device_code_service');
    $cleaned = $service->cleanupExpiredCodes();

    $this->assertEquals(5, $cleaned);

    // Verify codes are deleted
    foreach ($expiredCodes as $code) {
      $this->assertNull($this->reloadEntity($code));
    }
  }

  // Helper methods
  private function createOAuthClient(): Consumer { /* ... */ }
  private function requestDeviceAuthorization(Consumer $client): array { /* ... */ }
  private function verifyUserCode(string $userCode): void { /* ... */ }
  private function exchangeDeviceCodeForToken(Consumer $client, string $deviceCode): array { /* ... */ }
}
```

**Focus areas for testing:**

1. Complete device flow workflow (most important)
2. Error conditions and edge cases
3. Security validations (polling intervals, expiration)
4. Integration with Simple OAuth infrastructure
5. Cleanup and lifecycle management

**Avoid testing:**

- League/oauth2-server internal functionality
- Drupal's Form API basic operations
- Standard entity CRUD without custom logic
- Configuration storage mechanisms
</details>
