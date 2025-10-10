---
id: 6
group: 'functional-consolidation'
dependencies: [1]
status: 'pending'
created: '2025-10-10'
skills:
  - php
  - phpunit
  - drupal-backend
---

# Consolidate simple_oauth_server_metadata Functional Tests

## Objective

Consolidate the simple_oauth_server_metadata module's THREE functional test classes into a single consolidated test class with one comprehensive test method, merging ServerMetadataFunctionalTest, OpenIdConfigurationFunctionalTest, and TokenRevocationEndpointTest.

## Skills Required

- **php**: PHP refactoring and class merging
- **phpunit**: Understanding PHPUnit/Drupal BrowserTestBase patterns
- **drupal-backend**: Knowledge of Drupal testing and OAuth server metadata (RFC 8414/9728)

## Acceptance Criteria

- [ ] Single functional test class `ServerMetadataFunctionalTest` with one public test method
- [ ] All test methods from 3 source classes converted to protected helper methods
- [ ] Module dependencies merged from all source classes
- [ ] Main test method calls all helpers sequentially
- [ ] All RFC 8414 and RFC 9728 compliance tests preserved
- [ ] Test passes successfully after consolidation
- [ ] Two source test files deleted after successful merge

## Technical Requirements

- Source files to merge:
  - `modules/simple_oauth_server_metadata/tests/src/Functional/ServerMetadataFunctionalTest.php`
  - `modules/simple_oauth_server_metadata/tests/src/Functional/OpenIdConfigurationFunctionalTest.php`
  - `modules/simple_oauth_server_metadata/tests/src/Functional/TokenRevocationEndpointTest.php`
- Understanding of RFC 8414 (OAuth Server Metadata) and RFC 9728 (OAuth Resource Metadata)
- Understanding of OpenID Connect Discovery

## Input Dependencies

- Baseline metrics from task #1 for comparison

## Output Artifacts

- Single consolidated `ServerMetadataFunctionalTest.php` file
- Deleted: `OpenIdConfigurationFunctionalTest.php`
- Deleted: `TokenRevocationEndpointTest.php`

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Analyze All Three Test Classes

Read each source file and document:

1. **ServerMetadataFunctionalTest**
   - List all test methods
   - Note module dependencies
   - Identify setup logic

2. **OpenIdConfigurationFunctionalTest**
   - List all test methods
   - Note any additional module dependencies
   - Check for unique setup requirements

3. **TokenRevocationEndpointTest**
   - List all test methods
   - Note token revocation-specific setup
   - Check for HTTP client usage

### Step 2: Choose Base Class

Use **ServerMetadataFunctionalTest** as the base class since it's the primary test for this module.

### Step 3: Merge Module Dependencies

Combine `$modules` arrays from all three classes:

```php
protected static $modules = [
  'user',
  'serialization',
  'simple_oauth',
  'consumers',
  'simple_oauth_21',
  'simple_oauth_server_metadata',
  // Add any additional modules from other test classes
];
```

### Step 4: Merge setUp() Methods

Combine initialization logic from all three `setUp()` methods:

```php
protected function setUp(): void {
  parent::setUp();

  // Logic from ServerMetadataFunctionalTest::setUp()
  // ...

  // Additional logic from OpenIdConfigurationFunctionalTest::setUp()
  // ...

  // Additional logic from TokenRevocationEndpointTest::setUp()
  // ...
}
```

### Step 5: Create Comprehensive Test Method

```php
/**
 * Comprehensive OAuth server metadata and discovery test.
 *
 * Tests RFC 8414 (OAuth Server Metadata), RFC 9728 (OAuth Resource
 * Metadata), OpenID Connect Discovery, and token revocation endpoints.
 *
 * Test scenarios:
 * - OAuth authorization server metadata endpoint
 * - OAuth resource server metadata endpoint
 * - OpenID Connect discovery (/.well-known/openid-configuration)
 * - Token revocation endpoint (RFC 7009)
 * - Grant type discovery
 * - Metadata validation and compliance
 */
public function testComprehensiveServerMetadataFunctionality(): void {
  // Server metadata tests (from ServerMetadataFunctionalTest)
  $this->helperServerMetadataEndpoint();
  $this->helperResourceMetadataEndpoint();
  $this->helperMetadataValidation();

  // OpenID configuration tests (from OpenIdConfigurationFunctionalTest)
  $this->helperOpenIdDiscoveryEndpoint();
  $this->helperOpenIdConfigurationStructure();
  $this->helperOpenIdIssuerValidation();

  // Token revocation tests (from TokenRevocationEndpointTest)
  $this->helperTokenRevocationEndpoint();
  $this->helperRevocationWithInvalidToken();
  $this->helperRevocationAuthentication();
}
```

### Step 6: Convert All Test Methods to Helpers

For each test method across all three classes:

1. Copy method to consolidated class
2. Rename `testXyz()` to `helperXyz()`
3. Add "Originally from: [ClassName]" to docblock
4. Preserve all `@covers` annotations
5. Keep test logic unchanged

Example:

```php
/**
 * Helper: Tests OpenID Connect discovery endpoint.
 *
 * Validates the /.well-known/openid-configuration endpoint returns
 * proper OpenID Connect Discovery metadata.
 *
 * Originally from: OpenIdConfigurationFunctionalTest::testDiscoveryEndpoint()
 *
 * @covers \Drupal\simple_oauth_server_metadata\Controller\OpenIdConfigurationController::metadata
 */
protected function helperOpenIdDiscoveryEndpoint(): void {
  // Original test logic
}
```

### Step 7: Handle Class Properties

Merge any protected properties from all three classes:

```php
/**
 * Test consumer for OAuth testing.
 *
 * @var \Drupal\consumers\Entity\Consumer
 */
protected $consumer;

/**
 * Test access token for revocation testing.
 *
 * @var string
 */
protected $accessToken;

// Add other properties as needed
```

### Step 8: Preserve Helper Methods

If any of the three classes have existing protected helper methods (not test methods), copy them to the consolidated class.

### Step 9: Test Execution

```bash
cd /var/www/html
vendor/bin/phpunit modules/simple_oauth_server_metadata/tests/src/Functional/ServerMetadataFunctionalTest.php -v
```

### Step 10: Delete Merged Source Files

After successful test execution:

```bash
rm modules/simple_oauth_server_metadata/tests/src/Functional/OpenIdConfigurationFunctionalTest.php
rm modules/simple_oauth_server_metadata/tests/src/Functional/TokenRevocationEndpointTest.php
```

### Step 11: Verify No References

Check for broken references:

```bash
grep -r "OpenIdConfigurationFunctionalTest" modules/simple_oauth_server_metadata/
grep -r "TokenRevocationEndpointTest" modules/simple_oauth_server_metadata/
```

### Critical Validations

**Must preserve from ServerMetadataFunctionalTest**:
- RFC 8414 authorization server metadata endpoint
- RFC 9728 resource server metadata endpoint
- Metadata structure validation
- Grant type discovery

**Must preserve from OpenIdConfigurationFunctionalTest**:
- OpenID Connect Discovery endpoint
- OIDC metadata structure
- Issuer validation
- Integration with OAuth metadata

**Must preserve from TokenRevocationEndpointTest**:
- RFC 7009 token revocation endpoint
- Revocation authentication
- Error handling (invalid token, missing auth)
- Security validations

**Metadata Testing Context**:

Server metadata (RFC 8414) provides discovery endpoints for OAuth clients:
- `/.well-known/oauth-authorization-server`
- `/.well-known/oauth-resource-server`

OpenID Connect adds:
- `/.well-known/openid-configuration`

These are critical for client auto-configuration. All compliance tests MUST be preserved.

</details>
