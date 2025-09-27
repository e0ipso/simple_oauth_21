---
id: 1
group: 'test-infrastructure'
dependencies: []
status: 'completed'
created: '2025-09-27'
completed: '2025-09-27'
skills: ['phpunit', 'php']
---

# Fix PHPUnit Assertion Methods

## Objective

Replace missing `assertStringContains()` method calls with supported PHPUnit 11.5 alternatives to resolve the 2 test errors in OpenIdConfigurationFunctionalTest.

## Skills Required

PHPUnit expertise and PHP development skills to identify and implement correct assertion replacements.

## Acceptance Criteria

- [x] Replace `assertStringContains()` at line 299 with supported alternative
- [x] Replace `assertStringContains()` at line 482 with supported alternative
- [x] Both test methods execute without errors
- [x] No new test failures introduced

Use your internal Todo tool to track these and keep on track.

## Technical Requirements

- PHPUnit 11.5 compatible assertion methods
- Drupal BrowserTestBase functional test patterns
- File: `/var/www/html/web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/tests/src/Functional/OpenIdConfigurationFunctionalTest.php`

## Input Dependencies

None - this is the first task.

## Output Artifacts

- Modified test file with working assertion methods
- Foundation for running the full test suite

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

1. **Analyze the incorrect assertion usage**:
   - Read the test file at lines 299 and 482
   - Identify what the test is trying to assert (substring containment)

2. **Replace with correct PHPUnit 11 method**:
   - The method `assertStringContains()` doesn't exist in PHPUnit
   - Use `$this->assertStringContainsString($needle, $haystack)` instead
   - Note the parameter order: needle (substring) first, then haystack (full string)

3. **Specific replacements**:
   - Line 299: Change `$this->assertStringContains('/.well-known/oauth-authorization-server', $metadata['oauth_authorization_server_metadata_endpoint'])`
     to `$this->assertStringContainsString('/.well-known/oauth-authorization-server', $metadata['oauth_authorization_server_metadata_endpoint'])`
   - Line 482: Change `$this->assertStringContains('/oauth/register', $metadata['registration_endpoint'])`
     to `$this->assertStringContainsString('/oauth/register', $metadata['registration_endpoint'])`

4. **Verification**:
   - Run the specific failing tests to confirm the errors are resolved:
   ```bash
   vendor/bin/phpunit --filter testConfigurationIntegration
   vendor/bin/phpunit --filter testRegistrationEndpointDetection
   ```
   </details>
