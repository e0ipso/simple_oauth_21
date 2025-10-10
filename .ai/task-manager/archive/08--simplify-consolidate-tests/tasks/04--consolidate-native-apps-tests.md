---
id: 4
group: 'functional-consolidation'
dependencies: [1]
status: 'pending'
created: '2025-10-10'
skills:
  - php
  - phpunit
  - drupal-backend
---

# Consolidate simple_oauth_native_apps Functional Tests

## Objective

Consolidate the simple_oauth_native_apps module's functional test into a single test class with one comprehensive test method, converting existing test methods to helpers for Drupal instance reuse.

## Skills Required

- **php**: PHP refactoring and method extraction
- **phpunit**: Understanding PHPUnit/Drupal BrowserTestBase patterns
- **drupal-backend**: Knowledge of Drupal testing framework and OAuth native app patterns

## Acceptance Criteria

- [ ] Single functional test class `NativeAppConfigurationTest` with one public test method
- [ ] All original test methods converted to protected helper methods
- [ ] Main test method calls helpers sequentially
- [ ] All test documentation preserved in helper docblocks
- [ ] Test passes successfully after consolidation
- [ ] RFC 8252 compliance tests preserved

## Technical Requirements

- File location: `modules/simple_oauth_native_apps/tests/src/Functional/NativeAppConfigurationTest.php`
- Module also has 2 kernel tests that should be reviewed separately in task #8
- Understanding of RFC 8252 (OAuth 2.0 for Native Apps)

## Input Dependencies

- Baseline metrics from task #1 for comparison

## Output Artifacts

- Consolidated functional test class file
- All original test scenarios preserved as helper methods

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Analyze Current Test Structure

Read `modules/simple_oauth_native_apps/tests/src/Functional/NativeAppConfigurationTest.php`:

- Identify all public test methods
- Review what aspects of RFC 8252 are being tested
- Note any setup specific to native app testing (custom URI schemes, redirect handling, etc.)

### Step 2: Consolidation Pattern

Follow the same pattern as task #3:

1. Create single `testComprehensiveNativeAppFunctionality()` method
2. Convert all `testXyz()` methods to `helperXyz()` methods
3. Call helpers sequentially from main test method
4. Preserve all documentation and `@covers` annotations

### Step 3: Native App-Specific Considerations

RFC 8252 addresses OAuth for native applications (mobile apps, desktop apps). Ensure tests validate:

- **Custom URI scheme handling** (e.g., `myapp://oauth/callback`)
- **Loopback redirect URIs** (e.g., `http://127.0.0.1:randomport/callback`)
- **PKCE requirement** for native apps (should always use PKCE)
- **Token storage security** considerations

### Step 4: Implementation Structure

```php
/**
 * Comprehensive native app OAuth functionality test.
 *
 * Tests RFC 8252 OAuth 2.0 for Native Apps implementation including:
 * - Custom URI scheme registration and validation
 * - Loopback redirect URI handling
 * - PKCE enforcement for native apps
 * - [Add other scenarios based on original test methods]
 */
public function testComprehensiveNativeAppFunctionality(): void {
  $this->helperCustomUriSchemeValidation();
  $this->helperLoopbackRedirectHandling();
  $this->helperPkceEnforcement();
  // Add all other helpers
}
```

### Step 5: Test Execution

Run the consolidated test:

```bash
cd /var/www/html
vendor/bin/phpunit modules/simple_oauth_native_apps/tests/src/Functional/NativeAppConfigurationTest.php
```

### Step 6: Verification Checklist

- [ ] All RFC 8252 compliance checks preserved
- [ ] Native app-specific security validations intact
- [ ] Integration with PKCE module tested
- [ ] Custom URI scheme validation functional
- [ ] Test execution faster than baseline

### Important Notes

**Do NOT remove**:
- Security-critical native app validations
- RFC 8252 compliance tests
- PKCE integration tests
- Custom URI scheme validation

**Can consider trimming** (if present):
- Configuration schema-only tests
- Simple getter/setter tests
- Drupal form API validation tests

**Kernel Tests**: This module also has `OAuthFlowIntegrationTest` and `ServiceIntegrationTest` in the Kernel directory. These should be handled in task #8, not this task.

</details>
