---
id: 4
group: 'testing'
dependencies: [1, 2]
status: 'pending'
created: 2025-10-09
skills:
  - phpunit
  - drupal-backend
---

# Run Existing Test Suite to Verify No Regressions

## Objective

Execute the existing PHPUnit test suite for simple_oauth_native_apps module to ensure the code changes don't introduce regressions in native app detection, PKCE validation, or other OAuth functionality.

## Skills Required

- **phpunit**: Running and interpreting PHPUnit test results
- **drupal-backend**: Understanding Drupal testing framework and test execution

## Acceptance Criteria

- [ ] All existing unit tests pass
- [ ] All existing kernel tests pass
- [ ] All existing functional tests pass
- [ ] No new test failures introduced by the changes
- [ ] Test execution completes without errors
- [ ] Coverage of native app detection logic verified

## Technical Requirements

**Test files to execute:**

- `modules/simple_oauth_native_apps/tests/src/Unit/`
- `modules/simple_oauth_native_apps/tests/src/Kernel/`
- `modules/simple_oauth_native_apps/tests/src/Functional/`

**Test execution command:**

```bash
cd /var/www/html && vendor/bin/phpunit web/modules/contrib/simple_oauth_21/modules/simple_oauth_native_apps/tests
```

**Key test files:**

- `ServiceIntegrationTest.php` - Tests native client detector service
- `OAuthFlowIntegrationTest.php` - Tests OAuth flow with native apps
- `NativeAppConfigurationTest.php` - Tests configuration and settings

## Input Dependencies

- Task 1: Obsolete hooks removed (ensures clean codebase)
- Task 2: Base field definitions verified (ensures correct field configuration)

## Output Artifacts

- PHPUnit test execution report
- List of passing/failing tests
- Any error messages or stack traces
- Confirmation that native app functionality works as expected

## Implementation Notes

<details>
<summary>Detailed Test Execution Steps</summary>

### Test Preparation

1. **Clear all caches before testing:**

   ```bash
   vendor/bin/drush cr
   ```

2. **Verify module is installed:**
   ```bash
   vendor/bin/drush pm:list | grep simple_oauth_native_apps
   ```

### Run Test Suite

1. **Execute all tests for the module:**

   ```bash
   cd /var/www/html && \
   vendor/bin/phpunit web/modules/contrib/simple_oauth_21/modules/simple_oauth_native_apps/tests
   ```

2. **If tests fail, run by test type to isolate issues:**

   **Unit tests only:**

   ```bash
   vendor/bin/phpunit --testsuite=unit \
     web/modules/contrib/simple_oauth_21/modules/simple_oauth_native_apps/tests
   ```

   **Kernel tests only:**

   ```bash
   vendor/bin/phpunit --testsuite=kernel \
     web/modules/contrib/simple_oauth_21/modules/simple_oauth_native_apps/tests
   ```

   **Functional tests only:**

   ```bash
   vendor/bin/phpunit --testsuite=functional \
     web/modules/contrib/simple_oauth_21/modules/simple_oauth_native_apps/tests
   ```

### Key Tests to Verify

1. **ServiceIntegrationTest.php:**
   - Tests NativeClientDetector service
   - Verifies automatic client type detection
   - Checks field value handling ('auto-detect', 'web', 'native')
   - Validates cache functionality

2. **OAuthFlowIntegrationTest.php:**
   - Tests OAuth authorization flow with native clients
   - Verifies PKCE enforcement for native apps
   - Checks redirect URI validation
   - Tests WebView detection

3. **NativeAppConfigurationTest.php:**
   - Tests configuration form and settings
   - Verifies field display and validation
   - Checks form integration with consumer entity

### Expected Results

**All tests should pass with output similar to:**

```
PHPUnit 9.x by Sebastian Bergmann and contributors.

Testing Drupal\Tests\simple_oauth_native_apps
...                                                                  3 / 3 (100%)

Time: 00:05.123, Memory: 45.00 MB

OK (3 tests, 15 assertions)
```

### Troubleshooting Test Failures

**If tests fail:**

1. **Check for field definition issues:**
   - Verify base field values are correct in .module file
   - Ensure 'auto-detect' value is properly handled

2. **Review test expectations:**
   - Some tests may reference old field values (`''`, `'0'`, `'1'`)
   - Update test assertions to use new values (`'auto-detect'`, `'web'`, `'native'`)

3. **Verify entity field access:**
   - Tests should access fields via entity API
   - No direct database queries for base fields

4. **Check cache issues:**
   - Run `vendor/bin/drush cr` before retesting
   - Clear test database if needed

### Validation Checklist

- [ ] No "Call to undefined method" errors (indicates missing code)
- [ ] No "Column not found" errors (indicates field definition issues)
- [ ] No cache-related failures (indicates stale cached definitions)
- [ ] All native app detection tests pass
- [ ] All PKCE validation tests pass
- [ ] All form integration tests pass

### Documentation

Record test results including:

- Total tests run
- Number of assertions
- Pass/fail status for each test file
- Any warnings or notices
- Execution time and memory usage
- Any test modifications needed to pass

**Success criteria:**

- All existing tests pass without modification
- No new failures introduced by removing .install hooks
- Native app functionality verified through automated tests

</details>
