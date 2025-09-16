---
id: 8
group: 'testing'
dependencies: [3, 4, 5, 6, 7]
status: 'pending'
created: '2025-09-16'
skills: ['functional-testing', 'drupal-testing']
complexity_score: 5.0
---

# Test OAuth Registration and Metadata Flows

## Objective

Create focused functional tests for the OAuth client registration and metadata endpoints, testing critical business logic and integration flows rather than framework functionality.

## Skills Required

- **functional-testing**: Drupal functional test framework, HTTP client testing
- **drupal-testing**: BrowserTestBase, test patterns

## Acceptance Criteria

- [ ] Functional test for complete client registration flow (POST `/oauth/register`)
- [ ] Test for client management operations (GET/PUT/DELETE)
- [ ] Functional test for protected resource metadata endpoint
- [ ] Test for authorization server metadata completeness
- [ ] Tests validate RFC compliance of JSON responses
- [ ] No regression in existing Simple OAuth functionality

## Technical Requirements

**Meaningful Test Strategy Guidelines**

Your critical mantra for test generation is: "write a few tests, mostly integration".

**Test Coverage Focus:**

- Complete OAuth registration workflow
- Metadata endpoint responses and RFC compliance
- Token-based client management operations
- Error conditions for registration abuse

**Test Class Structure:**

- Extend `BrowserTestBase` following Simple OAuth patterns
- Test real HTTP requests to endpoints
- Validate complete JSON response structures

## Input Dependencies

- Task 3, 4, 5: Client registration functionality must be complete
- Task 6, 7: Metadata endpoints must be functional

## Output Artifacts

- Functional test suite for OAuth registration flow
- Metadata endpoint validation tests

## Implementation Notes

<details>
<summary>Detailed Implementation Instructions</summary>

Create test classes following existing Simple OAuth test patterns:

**Test Structure:**

```php
<?php

namespace Drupal\Tests\simple_oauth_client_registration\Functional;

use Drupal\Tests\BrowserTestBase;

class ClientRegistrationFunctionalTest extends BrowserTestBase {

  protected static $modules = [
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_client_registration',
    'simple_oauth_server_metadata',
  ];

  public function testClientRegistrationWorkflow() {
    // Test complete registration flow
  }

  public function testClientManagementOperations() {
    // Test GET/PUT/DELETE operations
  }
}
```

**Key Test Cases:**

1. **Client Registration Flow:**
   - POST valid registration request to `/oauth/register`
   - Validate RFC 7591 response structure
   - Verify Consumer entity creation with proper fields
   - Test registration access token functionality

2. **Client Management:**
   - Use registration access token for client operations
   - Test metadata updates via PUT
   - Test client deletion via DELETE
   - Validate token-based access control

3. **Metadata Endpoints:**
   - GET `/.well-known/oauth-authorization-server`
   - GET `/.well-known/oauth-protected-resource`
   - Validate JSON schema compliance with RFCs
   - Test cache headers and CORS

4. **Error Conditions:**
   - Invalid registration requests (400 responses)
   - Unauthorized management operations (401/403)
   - Malformed JSON handling

**Test Patterns:**

- Use `$this->drupalGet()` and `$this->drupalPost()` for HTTP requests
- Use `$this->assertSession()->responseContains()` for content validation
- Parse JSON responses and validate structure
- Follow existing test patterns from Simple OAuth test suite

Focus on integration testing rather than unit testing individual methods.

</details>
