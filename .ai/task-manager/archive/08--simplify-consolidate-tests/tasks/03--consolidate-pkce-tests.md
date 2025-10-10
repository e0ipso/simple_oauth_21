---
id: 3
group: 'functional-consolidation'
dependencies: [1]
status: 'pending'
created: '2025-10-10'
skills:
  - php
  - phpunit
  - drupal-backend
---

# Consolidate simple_oauth_pkce Functional Tests

## Objective

Consolidate the simple_oauth_pkce module's functional test class(es) into a single test class with one comprehensive test method that calls multiple helper methods sequentially, reusing the Drupal instance for performance optimization.

## Skills Required

- **php**: PHP refactoring and method extraction
- **phpunit**: Understanding PHPUnit/Drupal BrowserTestBase patterns
- **drupal-backend**: Knowledge of Drupal testing framework and module structure

## Acceptance Criteria

- [ ] Single functional test class `PkceConfigurationFunctionalTest` with one public test method
- [ ] All original test methods converted to protected helper methods
- [ ] Main test method calls helpers sequentially
- [ ] All test documentation preserved in helper docblocks
- [ ] Test passes successfully after consolidation
- [ ] No business logic removed or changed

## Technical Requirements

- File location: `modules/simple_oauth_pkce/tests/src/Functional/PkceConfigurationFunctionalTest.php`
- Drupal 11.1 with BrowserTestBase
- PHPUnit testing framework
- Understanding of PKCE (RFC 7636) functionality

## Input Dependencies

- Baseline metrics from task #1 for comparison

## Output Artifacts

- Consolidated functional test class file
- All original test scenarios preserved as helper methods

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Analyze Current Test Structure

Read `modules/simple_oauth_pkce/tests/src/Functional/PkceConfigurationFunctionalTest.php`:

- Count the number of public test methods (methods starting with `test`)
- Identify any existing helper methods
- Review `setUp()` method for initialization logic
- Note module dependencies in `$modules` property

### Step 2: Plan Consolidation Strategy

For each `public function testXyz()` method:

1. Rename to `protected function helperXyz()`
2. Preserve all docblock comments (including `@covers` annotations)
3. Preserve all test logic unchanged
4. Note any setup/teardown specific to that test

### Step 3: Create Consolidated Test Method

At the top of the class (after `setUp()`), create:

```php
/**
 * Comprehensive PKCE functionality test.
 *
 * Tests all PKCE configuration and validation scenarios sequentially,
 * reusing the Drupal instance for performance optimization.
 *
 * This consolidated test includes:
 * - [List each original test method name and what it validates]
 */
public function testComprehensivePkceFunctionality(): void {
  // Call helpers in logical order
  $this->helperPkceConfigurationForm();
  $this->helperPkceEnforcement();
  $this->helperCodeChallengeValidation();
  // ... add all helpers
}
```

### Step 4: Convert Test Methods to Helpers

For each test method, transform:

**Before:**

```php
/**
 * Tests PKCE enforcement.
 */
public function testPkceEnforcement(): void {
  // Test logic here
}
```

**After:**

```php
/**
 * Helper: Tests PKCE enforcement.
 *
 * Validates that PKCE challenge is required when configured.
 * Originally: testPkceEnforcement()
 */
protected function helperPkceEnforcement(): void {
  // Same test logic
}
```

### Step 5: Handle State Management

Between helper calls, add state cleanup if needed:

```php
public function testComprehensivePkceFunctionality(): void {
  $this->helperScenario1();

  // Clean state if needed
  \Drupal::cache()->invalidateAll();

  $this->helperScenario2();
}
```

**Important**: Only add cleanup if tests actually interfere with each other. Drupal's test framework handles most cleanup automatically.

### Step 6: Verify Consolidation

Run the consolidated test:

```bash
cd /var/www/html
vendor/bin/phpunit modules/simple_oauth_pkce/tests/src/Functional/PkceConfigurationFunctionalTest.php
```

The test should pass and execute faster than running individual test methods.

### Step 7: Measure Performance Improvement

Time the execution:

```bash
time vendor/bin/phpunit modules/simple_oauth_pkce/tests/src/Functional/PkceConfigurationFunctionalTest.php
```

Compare against the baseline for this specific test file.

### Step 8: Code Quality Checks

- [ ] All helper methods have descriptive docblocks
- [ ] Main test method documents the test flow
- [ ] No trailing whitespace
- [ ] File ends with newline
- [ ] Follows Drupal coding standards
- [ ] `declare(strict_types=1);` at top of file

### Important Considerations

**PKCE-Specific Testing**: This module implements RFC 7636 PKCE. Ensure tests validate:

- Code challenge generation and validation
- Code verifier validation
- S256 and plain challenge methods
- PKCE enforcement configuration

**Do NOT remove or modify**:

- Security-critical PKCE validation tests
- OAuth RFC compliance checks
- Integration tests with simple_oauth core

**Can consider trimming** (if present):

- Tests that only validate configuration schema syntax
- Tests that validate Drupal core form API functionality
- Tests of simple getter/setter methods

</details>
