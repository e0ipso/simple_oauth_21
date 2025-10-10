---
id: 7
group: 'functional-consolidation'
dependencies: [1]
status: 'pending'
created: '2025-10-10'
skills:
  - php
  - phpunit
  - drupal-backend
---

# Consolidate simple_oauth_21 Main Module Functional Tests

## Objective

Consolidate the simple_oauth_21 main module's FOUR functional test classes into a single consolidated test class with one comprehensive test method, merging ComplianceDashboardTest, ClientRegistrationFunctionalTest, OAuthMetadataValidationTest, and OAuthIntegrationContextTest.

## Skills Required

- **php**: PHP refactoring and class merging
- **phpunit**: Understanding PHPUnit/Drupal BrowserTestBase patterns
- **drupal-backend**: Knowledge of Drupal testing and OAuth 2.1 compliance

## Acceptance Criteria

- [ ] Single functional test class with one public test method
- [ ] All test methods from 4 source classes converted to protected helper methods
- [ ] Module dependencies merged from all source classes
- [ ] Main test method calls all helpers sequentially
- [ ] All OAuth 2.1 compliance tests preserved
- [ ] Test passes successfully after consolidation
- [ ] Three source test files deleted after successful merge

## Technical Requirements

- Source files to merge:
  - `tests/src/Functional/ComplianceDashboardTest.php` (keep as base)
  - `tests/src/Functional/ClientRegistrationFunctionalTest.php`
  - `tests/src/Functional/OAuthMetadataValidationTest.php`
  - `tests/src/Functional/OAuthIntegrationContextTest.php`
- Understanding of OAuth 2.1 compliance requirements
- Knowledge of simple_oauth_21 umbrella module architecture

## Input Dependencies

- Baseline metrics from task #1 for comparison

## Output Artifacts

- Single consolidated test class (recommend keeping `ComplianceDashboardTest` as primary name)
- Deleted: `ClientRegistrationFunctionalTest.php`
- Deleted: `OAuthMetadataValidationTest.php`
- Deleted: `OAuthIntegrationContextTest.php`

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Analyze All Four Test Classes

Read each source file:

1. **ComplianceDashboardTest** - Based on code review, has one consolidated test method already:
   - `testComprehensiveDashboardFunctionality()` - Tests dashboard access, RFC matrix display, module installation effects

2. **ClientRegistrationFunctionalTest**
   - Tests RFC 7591 Dynamic Client Registration
   - List all test methods

3. **OAuthMetadataValidationTest**
   - Tests metadata validation and compliance
   - List all test methods

4. **OAuthIntegrationContextTest**
   - Tests integration between modules
   - List all test methods

### Step 2: Choose Base Class

Use **ComplianceDashboardTest** as the base since it's already partially consolidated and represents the core functionality of the umbrella module.

### Step 3: Merge Module Dependencies

The main module coordinates all sub-modules, so module dependencies will be extensive:

```php
protected static $modules = [
  'system',
  'user',
  'serialization',
  'simple_oauth',
  'consumers',
  'simple_oauth_21',
  // Dynamically enable sub-modules as needed in tests
  'simple_oauth_pkce',
  'simple_oauth_native_apps',
  'simple_oauth_device_flow',
  'simple_oauth_server_metadata',
  'simple_oauth_client_registration',
];
```

**Note**: Some tests may dynamically enable modules during execution. Preserve this pattern.

### Step 4: Merge setUp() Methods

Combine initialization logic from all four classes:

```php
protected function setUp(): void {
  parent::setUp();

  // Admin user creation (from ComplianceDashboardTest)
  $this->adminUser = $this->drupalCreateUser([
    'administer simple_oauth entities',
  ]);

  // Additional setup from ClientRegistrationFunctionalTest
  // ...

  // Additional setup from OAuthMetadataValidationTest
  // ...

  // Additional setup from OAuthIntegrationContextTest
  // ...
}
```

### Step 5: Extend Comprehensive Test Method

ComplianceDashboardTest already has `testComprehensiveDashboardFunctionality()`. Expand it:

```php
/**
 * Comprehensive OAuth 2.1 compliance and integration test.
 *
 * Tests the simple_oauth_21 umbrella module functionality including:
 * - Compliance dashboard display and RFC matrix
 * - Dynamic client registration (RFC 7591)
 * - OAuth metadata validation
 * - Integration context between sub-modules
 * - Module installation and interaction effects
 *
 * All scenarios execute sequentially using a shared Drupal instance.
 */
public function testComprehensiveOAuth21Functionality(): void {
  // Compliance dashboard tests (existing)
  $this->helperDashboardAccess();
  $this->helperRfcMatrixDisplay();
  $this->helperModuleInstallationEffects();

  // Client registration tests (from ClientRegistrationFunctionalTest)
  $this->helperDynamicClientRegistration();
  $this->helperClientRegistrationValidation();
  $this->helperRegistrationEndpointSecurity();

  // Metadata validation tests (from OAuthMetadataValidationTest)
  $this->helperMetadataComplianceValidation();
  $this->helperMetadataStructureValidation();
  $this->helperCrossModuleMetadataIntegration();

  // Integration context tests (from OAuthIntegrationContextTest)
  $this->helperSubModuleIntegration();
  $this->helperComplianceServiceIntegration();
  $this->helperEndToEndOAuthFlow();
}
```

### Step 6: Refactor Existing Test

ComplianceDashboardTest has `testComprehensiveDashboardFunctionality()` which already consolidates multiple scenarios. Break it into helpers:

**Current structure:**

```php
public function testComprehensiveDashboardFunctionality() {
  // Test 1: Dashboard access and permissions
  $this->drupalGet('/admin/config/people/simple_oauth/oauth-21');
  $this->assertSession()->statusCodeEquals(403);

  // Test 2: RFC matrix is displayed
  // ...

  // Test 3: Dashboard updates with PKCE module
  // ...

  // Test 4: Dashboard updates with full submodule installation
  // ...

  // Test 5: Dashboard displays RFC implementation status
  // ...
}
```

**Refactor to:**

```php
protected function helperDashboardAccess(): void {
  // Test 1: Dashboard access and permissions
  // Anonymous users should not have access.
  $this->drupalGet('/admin/config/people/simple_oauth/oauth-21');
  $this->assertSession()->statusCodeEquals(403);

  // Admin users should have access.
  $this->drupalLogin($this->adminUser);
  $this->drupalGet('/admin/config/people/simple_oauth/oauth-21');
  $this->assertSession()->statusCodeEquals(200);
  $this->assertSession()->pageTextContains('OAuth 2.1 RFC Implementation Status');
}

protected function helperRfcMatrixDisplay(): void {
  // Test 2: RFC matrix is displayed
  // ...
}

protected function helperModuleInstallationEffects(): void {
  // Tests 3-5: Dynamic module installation effects
  // ...
}
```

### Step 7: Import Test Methods from Other Classes

For each test method in the other three classes:

```php
/**
 * Helper: Tests dynamic client registration endpoint.
 *
 * Validates RFC 7591 Dynamic Client Registration endpoint functionality
 * and proper client credential generation.
 *
 * Originally from: ClientRegistrationFunctionalTest::testClientRegistration()
 *
 * @covers \Drupal\simple_oauth_client_registration\Controller\ClientRegistrationController::register
 */
protected function helperDynamicClientRegistration(): void {
  // Original test logic from ClientRegistrationFunctionalTest
}
```

### Step 8: Handle Module State

The compliance dashboard tests dynamically install modules. Ensure proper ordering:

```php
public function testComprehensiveOAuth21Functionality(): void {
  // Test with minimal modules first
  $this->helperDashboardAccess();
  $this->helperRfcMatrixDisplay();

  // Install PKCE module
  $this->container->get('module_installer')->install(['simple_oauth_pkce']);

  // Test with PKCE enabled
  $this->helperPkceComplianceDisplay();

  // Install all sub-modules
  $this->container->get('module_installer')->install([
    'simple_oauth_native_apps',
    'simple_oauth_server_metadata',
    'simple_oauth_client_registration',
    'simple_oauth_device_flow',
  ]);

  // Test full integration
  $this->helperFullSubModuleIntegration();
}
```

### Step 9: Test Execution

```bash
cd /var/www/html
vendor/bin/phpunit tests/src/Functional/ComplianceDashboardTest.php -v
```

### Step 10: Delete Merged Source Files

After successful execution:

```bash
cd tests/src/Functional
rm ClientRegistrationFunctionalTest.php
rm OAuthMetadataValidationTest.php
rm OAuthIntegrationContextTest.php
```

### Critical Validations

**Must preserve**:

- OAuth 2.1 compliance dashboard functionality
- RFC implementation status tracking
- Dynamic client registration (RFC 7591)
- Metadata validation across modules
- Integration between sub-modules
- OAuth21ComplianceService integration
- Module installation effects on dashboard

**Integration Testing Context**:

The simple_oauth_21 main module is an **umbrella coordination module** that:

1. Provides compliance dashboard showing RFC implementation status
2. Coordinates between sub-modules
3. Integrates with OAuth21ComplianceService
4. Tracks which RFC standards are implemented

All tests validating this coordination MUST be preserved.

### Special Considerations

**Dynamic Module Installation**: Tests that use `$this->container->get('module_installer')->install()` to dynamically enable sub-modules should be preserved. This validates the dashboard updates correctly as modules are enabled.

**Service Integration**: Tests validating OAuth21ComplianceService integration must be preserved as they verify the core coordination mechanism.

</details>
