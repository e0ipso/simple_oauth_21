---
id: 8
group: 'kernel-test-review'
dependencies: [1]
status: 'pending'
created: '2025-10-10'
skills:
  - php
  - phpunit
  - drupal-backend
---

# Review and Refine Kernel Tests

## Objective

Review all kernel tests across modules to identify and remove tests that only validate configuration schema syntax without functional impact, while preserving tests that validate service integration, database operations, and module interdependencies.

## Skills Required

- **php**: Understanding PHP and Drupal service patterns
- **phpunit**: Knowledge of Drupal KernelTestBase
- **drupal-backend**: Understanding Drupal kernel testing, service container, and entity CRUD

## Acceptance Criteria

- [ ] All 4-5 kernel tests reviewed and classified
- [ ] Configuration-schema-only tests removed (if any)
- [ ] Service integration tests preserved
- [ ] Database and entity CRUD tests preserved
- [ ] Module interdependency tests preserved
- [ ] All remaining kernel tests pass

## Technical Requirements

- Kernel tests to review (approximately 4-5 files):
  - `modules/simple_oauth_device_flow/tests/src/Kernel/DeviceFlowIntegrationTest.php`
  - `modules/simple_oauth_native_apps/tests/src/Kernel/OAuthFlowIntegrationTest.php`
  - `modules/simple_oauth_native_apps/tests/src/Kernel/ServiceIntegrationTest.php`
  - `modules/simple_oauth_pkce/tests/src/Kernel/PkceConfigurationTest.php`
  - `modules/simple_oauth_server_metadata/tests/src/Kernel/Service/GrantTypeDiscoveryServiceKernelTest.php`

## Input Dependencies

- Baseline metrics from task #1 for comparison

## Output Artifacts

- Potentially deleted kernel test files (if only schema validation)
- Potentially trimmed kernel test methods (remove schema-only tests, keep integration tests)
- Documentation of review decisions

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Understand Kernel Test Purpose

Kernel tests provide **lightweight integration testing** with minimal Drupal bootstrap:

- Service container available
- Database available
- Entity system available
- No full web server or routing

**Keep kernel tests that:**

- Validate service instantiation and dependency injection
- Test database operations and entity CRUD
- Verify module interdependencies
- Test configuration with functional impact (not just schema validation)

**Remove kernel tests that:**

- Only validate configuration schema syntax
- Test simple getter/setter methods
- Validate Drupal core functionality
- Duplicate functional test coverage

### Step 2: Review DeviceFlowIntegrationTest

File: `modules/simple_oauth_device_flow/tests/src/Kernel/DeviceFlowIntegrationTest.php`

**Expected content**: OAuth device flow integration with Drupal's entity system

**Review checklist:**

- [ ] Does it test device code entity CRUD operations?
- [ ] Does it validate OAuth flow integration with simple_oauth?
- [ ] Does it test service discovery and dependency injection?

**Decision**: **LIKELY KEEP** - Integration tests are valuable for validating OAuth flow mechanics.

If it ONLY tests configuration schema without functional validation, consider removal.

### Step 3: Review OAuthFlowIntegrationTest

File: `modules/simple_oauth_native_apps/tests/src/Kernel/OAuthFlowIntegrationTest.php`

**Expected content**: OAuth flow integration for native apps

**Review checklist:**

- [ ] Does it test custom URI scheme validation?
- [ ] Does it validate PKCE integration?
- [ ] Does it test loopback redirect URI handling?

**Decision**: **LIKELY KEEP** - Native app flow integration is critical.

### Step 4: Review ServiceIntegrationTest

File: `modules/simple_oauth_native_apps/tests/src/Kernel/ServiceIntegrationTest.php`

**Expected content**: Service wiring and dependency injection

**Review checklist:**

- [ ] Does it verify services are properly registered in container?
- [ ] Does it test service method functionality?
- [ ] Does it validate service dependencies?

**Decision**: **LIKELY KEEP** - Service integration testing is valuable.

### Step 5: Review PkceConfigurationTest

File: `modules/simple_oauth_pkce/tests/src/Kernel/PkceConfigurationTest.php`

**Expected content**: PKCE configuration testing

**Critical review needed:**

- [ ] Does it test PKCE enforcement logic?
- [ ] Does it validate code challenge/verifier processing?
- [ ] OR does it only validate configuration schema syntax?

**Decision criteria:**

- If it tests **business logic** (PKCE validation, enforcement): **KEEP**
- If it ONLY tests **schema validation**: **REMOVE**

Read the file carefully to make the correct decision.

### Step 6: Review GrantTypeDiscoveryServiceKernelTest

File: `modules/simple_oauth_server_metadata/tests/src/Kernel/Service/GrantTypeDiscoveryServiceKernelTest.php`

**Expected content**: Grant type discovery service testing

**Review checklist:**

- [ ] Does it test grant type discovery mechanism?
- [ ] Does it validate plugin discovery integration?
- [ ] Does it test service functionality?

**Decision**: **LIKELY KEEP** - Discovery service is complex business logic.

### Step 7: Classification Template

For each kernel test, document:

```
File: [filename]
Classification: KEEP | REMOVE | TRIM
Rationale: [Why this decision was made]
Test methods reviewed: [List of methods]
Methods to remove (if TRIM): [List]
```

### Step 8: Execute Removal/Trimming

For tests classified as **REMOVE**:

```bash
rm [path/to/test/file]
```

For tests classified as **TRIM**:

1. Open file
2. Remove schema-validation-only methods
3. Keep integration and business logic tests
4. Update class docblock to reflect remaining scope

### Step 9: Validate Remaining Tests

Run all kernel tests:

```bash
cd /var/www/html
vendor/bin/phpunit web/modules/contrib/simple_oauth_21__consolidate_tests --testsuite=kernel
```

All tests should pass.

### Step 10: Document Review Decisions

Create `.ai/task-manager/plans/08--simplify-consolidate-tests/kernel-test-review.txt`:

```
Kernel Test Review Results
==========================

KEPT TESTS:
-----------
1. DeviceFlowIntegrationTest
   Rationale: Tests device code entity CRUD and OAuth flow integration
   Methods: [list]

2. OAuthFlowIntegrationTest
   Rationale: Tests native app OAuth flow integration
   Methods: [list]

... (continue for all kept tests)

REMOVED TESTS:
--------------
1. [TestName] (if any)
   Rationale: Only validated configuration schema without functional testing

TRIMMED TESTS:
--------------
1. [TestName] (if any)
   Removed methods: [list]
   Rationale: Removed schema-only validation, kept integration tests
```

### Important Considerations

**Configuration Testing Context**:

- **Schema-only tests**: Just verify YAML syntax is valid → Can be removed
- **Functional configuration tests**: Verify configuration affects behavior → Must keep

Example of **schema-only** (can remove):

```php
public function testConfigSchema(): void {
  $config = $this->config('simple_oauth_pkce.settings');
  $this->assertNotNull($config);
  $this->assertTrue($config->get('enforce_pkce'));
}
```

Example of **functional** (must keep):

```php
public function testPkceEnforcement(): void {
  // Set configuration
  $this->config('simple_oauth_pkce.settings')
    ->set('enforce_pkce', TRUE)
    ->save();

  // Verify behavior changes based on config
  $grant = $this->container->get('simple_oauth_pkce.pkce_grant');
  $this->assertTrue($grant->isPkceRequired());

  // Test that authorization fails without PKCE
  $this->expectException(OAuthServerException::class);
  $grant->respondToAccessTokenRequest($request, $response, $interval);
}
```

The second example tests **business logic affected by configuration** - this must be kept.

### Kernel vs Functional Test Distinction

If a kernel test duplicates functional test coverage:

- **Keep kernel test** if it's faster and provides adequate coverage
- **Remove kernel test** if functional test provides better end-to-end coverage

Use judgment based on test execution time and coverage value.

</details>
