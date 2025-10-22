---
id: 5
group: 'testing'
dependencies: [1, 3, 4]
status: 'completed'
created: 2025-10-22
skills:
  - drupal-backend
  - phpunit
---

# Add Functional Test for Default Refresh Token Grant

## Objective

Extend the existing `ClientRegistrationFunctionalTest` with a comprehensive test method that verifies the default grant type behavior under different configuration states and client request scenarios.

## Skills Required

- `drupal-backend`: Drupal testing framework, configuration management, entity APIs
- `phpunit`: PHPUnit assertions, test method structure, test data setup

## Acceptance Criteria

- [ ] New test method `testDefaultRefreshTokenGrant()` added to `ClientRegistrationFunctionalTest`
- [ ] Test covers setting enabled: verifies `['authorization_code', 'refresh_token']` when client omits grant_types
- [ ] Test covers setting disabled: verifies `['authorization_code']` only when client omits grant_types
- [ ] Test covers explicit grant_types: verifies client-specified values are respected regardless of setting
- [ ] Test uses proper configuration API to toggle the setting
- [ ] Test follows existing test patterns in the file
- [ ] All tests pass including existing tests (no regressions)

## Technical Requirements

**Module**: `simple_oauth_client_registration`

**File to Modify**: `modules/simple_oauth_client_registration/tests/src/Functional/ClientRegistrationFunctionalTest.php`

**Test Method Name**: `testDefaultRefreshTokenGrant()`

**Test Scenarios**:

1. **Setting Enabled + No Grant Types**: Response should contain `['authorization_code', 'refresh_token']`
2. **Setting Disabled + No Grant Types**: Response should contain `['authorization_code']`
3. **Setting Enabled + Explicit Grant Types**: Response should contain exact client-specified grant types
4. **Setting Disabled + Explicit Grant Types**: Response should contain exact client-specified grant types

## Input Dependencies

- Task 1: Configuration must exist
- Task 3: Service logic must be implemented
- Task 4: Routing must be configured (for cache rebuild, not directly tested)
- Existing `ClientRegistrationFunctionalTest` structure and helper methods

## Output Artifacts

- Updated test file with new comprehensive test method
- Verification that feature works correctly under all scenarios
- Regression protection for existing DCR functionality

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

**IMPORTANT - Meaningful Test Strategy Guidelines**

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

---

### Step 1: Read Existing Test File

```bash
cd /var/www/html/web/modules/contrib/simple_oauth_21/modules/simple_oauth_client_registration
```

Read `tests/src/Functional/ClientRegistrationFunctionalTest.php` to understand:

- Existing test structure and setup methods
- Helper methods like `helperClientRegistrationWorkflow()`
- How to make DCR requests and parse responses
- Cache clearing patterns used

### Step 2: Add Test Method

Add new test method to the class (after existing tests):

```php
  /**
   * Tests default refresh_token grant behavior with configuration.
   *
   * Verifies that the auto_enable_refresh_token setting controls default
   * grant types for DCR clients that don't explicitly specify grant_types.
   *
   * @covers \Drupal\simple_oauth_client_registration\Service\ClientRegistrationService::createConsumer
   */
  public function testDefaultRefreshTokenGrant(): void {
    // Scenario 1: Setting enabled + no grant_types specified.
    // Expected: ['authorization_code', 'refresh_token'].
    $this->config('simple_oauth_client_registration.settings')
      ->set('auto_enable_refresh_token', TRUE)
      ->save();
    $this->clearTestCaches();

    $metadata = [
      'client_name' => 'Test Client Default Grants Enabled',
      'redirect_uris' => ['https://example.com/callback'],
      // Intentionally omit grant_types to test default behavior.
    ];

    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => $metadata,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => TRUE,
    ]);

    $this->assertEquals(200, $response->getStatusCode());
    $response->getBody()->rewind();
    $response_data = Json::decode($response->getBody()->getContents());

    $this->assertContains('authorization_code', $response_data['grant_types']);
    $this->assertContains('refresh_token', $response_data['grant_types']);

    // Scenario 2: Setting disabled + no grant_types specified.
    // Expected: ['authorization_code'] only.
    $this->config('simple_oauth_client_registration.settings')
      ->set('auto_enable_refresh_token', FALSE)
      ->save();
    $this->clearTestCaches();

    $metadata['client_name'] = 'Test Client Default Grants Disabled';
    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => $metadata,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => TRUE,
    ]);

    $this->assertEquals(200, $response->getStatusCode());
    $response->getBody()->rewind();
    $response_data = Json::decode($response->getBody()->getContents());

    $this->assertContains('authorization_code', $response_data['grant_types']);
    $this->assertNotContains('refresh_token', $response_data['grant_types']);

    // Scenario 3: Explicit grant_types override (setting enabled).
    // Expected: Client-specified grant types are always respected.
    $this->config('simple_oauth_client_registration.settings')
      ->set('auto_enable_refresh_token', TRUE)
      ->save();
    $this->clearTestCaches();

    $metadata['client_name'] = 'Test Client Explicit Grants';
    $metadata['grant_types'] = ['client_credentials'];
    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => $metadata,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => TRUE,
    ]);

    $this->assertEquals(200, $response->getStatusCode());
    $response->getBody()->rewind();
    $response_data = Json::decode($response->getBody()->getContents());

    $this->assertEquals(['client_credentials'], $response_data['grant_types']);
  }
```

### Step 3: Verify Test Execution

```bash
cd /var/www/html
vendor/bin/phpunit web/modules/contrib/simple_oauth_21/modules/simple_oauth_client_registration/tests/src/Functional/ClientRegistrationFunctionalTest.php::testDefaultRefreshTokenGrant
```

### Step 4: Run Full Test Suite

```bash
vendor/bin/phpunit web/modules/contrib/simple_oauth_21/modules/simple_oauth_client_registration/tests
```

Verify all tests pass (both new and existing).

### Key Testing Patterns

- **Configuration Management**: Use `$this->config()->set()->save()` to toggle setting
- **Cache Clearing**: Use existing `clearTestCaches()` method after config changes
- **HTTP Requests**: Follow existing pattern with `$this->httpClient->post()`
- **Response Parsing**: Use `Json::decode()` after rewinding stream
- **Assertions**: Use `assertContains()` for array membership, `assertEquals()` for exact matches
- **PHPDoc**: Include `@covers` annotation for code coverage tracking

### Coding Standards

- Method must be public
- Return type: `void`
- PHPDoc with description and `@covers` annotation
- Descriptive assertion messages help debug failures
- Follow existing test patterns in the file

</details>
