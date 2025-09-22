---
id: 4
group: 'testing'
dependencies: [1, 2]
status: 'completed'
created: '2025-01-22'
skills: ['phpunit', 'testing']
complexity_score: 3.0
complexity_notes: 'Standard functional testing for single endpoint'
---

# Write Functional Tests for OpenID Connect Discovery Endpoint

## Objective

Create comprehensive functional tests for the OpenID Connect Discovery endpoint to verify proper functionality, response format, cache behavior, error handling, and compliance with the OpenID Connect Discovery 1.0 specification.

## Skills Required

- **phpunit**: PHPUnit test framework, Drupal testing APIs, test assertions, and test data setup
- **testing**: Test case design, edge case identification, API testing, and validation logic

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

## Acceptance Criteria

- [ ] Functional test class created following Drupal testing patterns
- [ ] Tests endpoint accessibility without authentication
- [ ] Validates JSON response format and content type
- [ ] Verifies all required OpenID Connect Discovery fields are present
- [ ] Tests proper cache headers and behavior
- [ ] Verifies CORS headers are set correctly
- [ ] Tests error handling for service unavailability
- [ ] Validates metadata against OpenID Connect Discovery 1.0 specification
- [ ] Tests integration with configuration settings
- [ ] Achieves meaningful test coverage for custom logic

## Technical Requirements

**Test Scenarios:**

- Successful metadata retrieval with all required fields
- Response format validation (JSON, content-type headers)
- Cache behavior validation (cache headers, cache invalidation)
- CORS headers validation
- Error handling for service failures
- Configuration integration testing
- OpenID Connect Discovery 1.0 specification compliance

**Test Environment:**

- Drupal functional testing framework
- Test configuration setup
- Mock data for metadata fields
- Integration with existing simple_oauth test infrastructure

## Input Dependencies

- Task 1: OpenIdConfigurationController implementation
- Task 2: OpenIdConfigurationService implementation

## Output Artifacts

- `tests/src/Functional/OpenIdConfigurationFunctionalTest.php` - New functional test class

## Implementation Notes

<details>
<summary>Detailed implementation guidance</summary>

### Test Class Structure

```php
<?php

namespace Drupal\Tests\simple_oauth_server_metadata\Functional;

use Drupal\Tests\BrowserTestBase;

class OpenIdConfigurationFunctionalTest extends BrowserTestBase {

  protected static $modules = [
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_server_metadata',
  ];

  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();

    // Configure required settings
    $this->config('simple_oauth_server_metadata.settings')
      ->set('issuer', 'https://example.com')
      ->set('openid_discovery_enabled', TRUE)
      ->save();
  }

  /**
   * Test successful OpenID Connect Discovery metadata retrieval.
   */
  public function testOpenIdConfigurationEndpoint(): void {
    $response = $this->drupalGet('/.well-known/openid-configuration');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('Content-Type', 'application/json');

    $metadata = json_decode($response, TRUE);
    $this->assertIsArray($metadata);

    // Test required fields according to OpenID Connect Discovery 1.0
    $required_fields = [
      'issuer',
      'authorization_endpoint',
      'token_endpoint',
      'userinfo_endpoint',
      'jwks_uri',
      'scopes_supported',
      'response_types_supported',
      'subject_types_supported',
      'id_token_signing_alg_values_supported',
      'claims_supported',
    ];

    foreach ($required_fields as $field) {
      $this->assertArrayHasKey($field, $metadata, "Required field '$field' is missing");
      $this->assertNotEmpty($metadata[$field], "Required field '$field' is empty");
    }

    // Test field format validation
    $this->assertIsString($metadata['issuer']);
    $this->assertIsArray($metadata['scopes_supported']);
    $this->assertIsArray($metadata['response_types_supported']);
    $this->assertIsArray($metadata['subject_types_supported']);
    $this->assertIsArray($metadata['claims_supported']);

    // Test issuer URL format
    $this->assertStringStartsWith('https://', $metadata['issuer']);
  }

  /**
   * Test cache headers and behavior.
   */
  public function testCacheHeaders(): void {
    $response = $this->drupalGet('/.well-known/openid-configuration');

    // Verify cache headers are present
    $this->assertSession()->responseHeaderExists('Cache-Control');
    $this->assertSession()->responseHeaderExists('Expires');

    // Test that subsequent requests use cache
    $first_response = $this->getSession()->getPage()->getContent();
    $second_response = $this->drupalGet('/.well-known/openid-configuration');
    $this->assertEquals($first_response, $this->getSession()->getPage()->getContent());
  }

  /**
   * Test CORS headers for cross-origin requests.
   */
  public function testCorsHeaders(): void {
    $response = $this->drupalGet('/.well-known/openid-configuration');

    $this->assertSession()->responseHeaderEquals('Access-Control-Allow-Origin', '*');
    $this->assertSession()->responseHeaderEquals('Access-Control-Allow-Methods', 'GET');
  }

  /**
   * Test configuration integration.
   */
  public function testConfigurationIntegration(): void {
    // Test with custom issuer
    $custom_issuer = 'https://custom.example.com';
    $this->config('simple_oauth_server_metadata.settings')
      ->set('issuer', $custom_issuer)
      ->save();

    // Clear cache to ensure new configuration is used
    drupal_flush_all_caches();

    $response = $this->drupalGet('/.well-known/openid-configuration');
    $metadata = json_decode($response, TRUE);

    $this->assertEquals($custom_issuer, $metadata['issuer']);
  }

  /**
   * Test endpoint accessibility without authentication.
   */
  public function testPublicAccess(): void {
    // Test as anonymous user
    $this->drupalLogout();
    $response = $this->drupalGet('/.well-known/openid-configuration');
    $this->assertSession()->statusCodeEquals(200);

    // Verify JSON response
    $metadata = json_decode($response, TRUE);
    $this->assertIsArray($metadata);
    $this->assertArrayHasKey('issuer', $metadata);
  }

  /**
   * Test OpenID Connect Discovery 1.0 specification compliance.
   */
  public function testSpecificationCompliance(): void {
    $response = $this->drupalGet('/.well-known/openid-configuration');
    $metadata = json_decode($response, TRUE);

    // Test that subject_types_supported contains 'public'
    $this->assertContains('public', $metadata['subject_types_supported']);

    // Test that response_types_supported contains valid values
    $valid_response_types = ['code', 'token', 'id_token', 'code id_token'];
    foreach ($metadata['response_types_supported'] as $response_type) {
      $this->assertContains($response_type, $valid_response_types);
    }

    // Test that scopes_supported contains 'openid'
    $this->assertContains('openid', $metadata['scopes_supported']);

    // Test endpoint URL format
    $this->assertStringStartsWith('http', $metadata['authorization_endpoint']);
    $this->assertStringStartsWith('http', $metadata['token_endpoint']);
    $this->assertStringStartsWith('http', $metadata['userinfo_endpoint']);
    $this->assertStringStartsWith('http', $metadata['jwks_uri']);
  }

  /**
   * Test error handling scenarios.
   */
  public function testErrorHandling(): void {
    // Test with missing required configuration
    $this->config('simple_oauth_server_metadata.settings')
      ->set('issuer', '')
      ->save();

    drupal_flush_all_caches();

    $response = $this->drupalGet('/.well-known/openid-configuration');
    // Should return 503 Service Unavailable for missing configuration
    $this->assertSession()->statusCodeEquals(503);
  }
}
```

### Key Test Requirements

- Test all required OpenID Connect Discovery fields
- Validate JSON response format and content type
- Test cache behavior and headers
- Verify CORS headers for cross-origin requests
- Test public accessibility without authentication
- Validate specification compliance
- Test error scenarios (missing configuration)
- Integration testing with configuration settings

### Test Data Setup

- Configure simple_oauth_server_metadata.settings with test data
- Set up test issuer URL and other required configuration
- Clear caches when testing configuration changes
- Use realistic test data that matches production scenarios

### Meaningful Testing Focus

- Focus on testing the custom OpenID Connect Discovery logic
- Test integration points with existing services
- Validate specification compliance requirements
- Test error handling and edge cases
- Avoid testing Drupal framework functionality
- Concentrate on business logic specific to this endpoint
</details>
