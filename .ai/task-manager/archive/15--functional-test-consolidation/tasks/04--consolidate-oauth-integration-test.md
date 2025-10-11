---
id: 4
group: 'main-module-test-consolidation'
dependencies: []
status: 'completed'
created: '2025-10-11'
completed: '2025-10-11'
skills:
  - phpunit
  - drupal-testing
---

# Consolidate OAuthIntegrationContextTest into Single Test Method

## Objective

Refactor `OAuthIntegrationContextTest` from 2 large test methods into a single `testComprehensiveOAuthIntegrationFunctionality()` method with 8+ granular helper methods, improving test organization while maintaining cross-context integration testing coverage.

## Skills Required

- **phpunit**: Expertise in PHPUnit test organization, complex multi-scenario testing, and test decomposition
- **drupal-testing**: Understanding of Drupal BrowserTestBase, cross-context testing (web, API, cache), and integration patterns

## Acceptance Criteria

- [ ] Single comprehensive test method `testComprehensiveOAuthIntegrationFunctionality()` created
- [ ] Existing method `testComprehensiveOauthIntegrationAcrossContexts()` decomposed into 6 helper methods
- [ ] Existing method `testConfigurationAndExistingClientIntegration()` decomposed into 2 helper methods
- [ ] Total of 8+ granular helper methods created
- [ ] Protected utility method `clearAllTestCaches()` preserved
- [ ] DebugLoggingTrait integration maintained
- [ ] Cross-context test isolation verified
- [ ] Class-level and method-level PHPDoc updated
- [ ] All tests pass after refactoring

## Technical Requirements

**File to modify:**

- `tests/src/Functional/OAuthIntegrationContextTest.php`

**Current structure (2 very large methods):**

```php
public function testComprehensiveOauthIntegrationAcrossContexts() {
  // Web Context OAuth Workflow
  // API Context OAuth Functionality
  // Cache Behavior Across Contexts
  // Cross-Context Client Management
  // Error Handling Consistency
  // Route Discovery and Concurrent Access
}

public function testConfigurationAndExistingClientIntegration() {
  // Configuration Changes Propagation
  // Integration with Existing OAuth Clients
}
```

**Target structure:**

```php
public function testComprehensiveOAuthIntegrationFunctionality(): void {
  $this->helperWebContextOAuthWorkflow();
  $this->helperApiContextOAuthFunctionality();
  $this->helperCacheBehaviorAcrossContexts();
  $this->helperCrossContextClientManagement();
  $this->helperErrorHandlingConsistency();
  $this->helperRouteDiscoveryAndConcurrentAccess();
  $this->helperConfigurationChangesPropagation();
  $this->helperIntegrationWithExistingClients();
}

protected function helperWebContextOAuthWorkflow(): void { ... }
// ... 7 more helpers ...
```

## Input Dependencies

None - this task can run in parallel with tasks 1, 2, and 3.

## Output Artifacts

- Refactored `OAuthIntegrationContextTest.php` with single test method and 8+ helpers
- Updated PHPDoc documenting cross-context integration coverage
- Preserved cache management utility

## Implementation Notes

<details>
<summary>Detailed Refactoring Steps</summary>

### Step 1: Understand Current Structure

The existing test has **large, monolithic methods** that test multiple scenarios. We need to **extract logical sections** into helper methods.

**Current method 1**: `testComprehensiveOauthIntegrationAcrossContexts()`

- ~270 lines
- 6 distinct test sections (marked with `===` comments)

**Current method 2**: `testConfigurationAndExistingClientIntegration()`

- ~105 lines
- 2 distinct test sections

### Step 2: Create Comprehensive Test Method

```php
/**
 * Comprehensive OAuth integration testing across all contexts.
 *
 * Tests OAuth functionality across web, API, and cache contexts to ensure
 * consistent behavior and proper integration. This consolidation reduces
 * test execution time while maintaining comprehensive cross-context coverage.
 *
 * Integration test coverage includes:
 * - Web context OAuth workflow (HTTP endpoints)
 * - API context OAuth functionality (service layer)
 * - Cache behavior and consistency across contexts
 * - Cross-context client management
 * - Error handling consistency across contexts
 * - Route discovery and concurrent access patterns
 * - Configuration change propagation
 * - Integration with existing OAuth clients
 *
 * All scenarios execute sequentially, maintaining test isolation through
 * proper cache clearing and state management in helper methods.
 */
public function testComprehensiveOAuthIntegrationFunctionality(): void {
  $this->logDebug('Starting comprehensive OAuth integration test');

  // Web and API context testing
  $this->helperWebContextOAuthWorkflow();
  $this->helperApiContextOAuthFunctionality();

  // Cache and state management
  $this->helperCacheBehaviorAcrossContexts();

  // Client management and error handling
  $this->helperCrossContextClientManagement();
  $this->helperErrorHandlingConsistency();

  // Concurrent access and discovery
  $this->helperRouteDiscoveryAndConcurrentAccess();

  // Configuration and integration
  $this->helperConfigurationChangesPropagation();
  $this->helperIntegrationWithExistingClients();
}
```

### Step 3: Extract Helpers from testComprehensiveOauthIntegrationAcrossContexts

#### Helper 1: Web Context OAuth Workflow

Extract the "Web Context OAuth Workflow" section (lines ~115-145):

```php
/**
 * Helper: Tests OAuth workflow in web context (HTTP requests).
 *
 * Validates that OAuth metadata endpoints are accessible via HTTP and
 * that client registration works through web requests.
 */
protected function helperWebContextOAuthWorkflow(): void {
  $this->logDebug('Testing OAuth metadata endpoint');

  // Test metadata endpoints are accessible via HTTP
  $this->drupalGet('/.well-known/oauth-authorization-server');
  $this->logDebug('OAuth metadata endpoint response code: ' . $this->getSession()->getStatusCode());
  $this->assertSession()->statusCodeEquals(200);
  $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');

  $auth_metadata = Json::decode($this->getSession()->getPage()->getContent());
  $this->assertArrayHasKey('registration_endpoint', $auth_metadata);
  $this->logDebug('Registration endpoint from metadata: ' . ($auth_metadata['registration_endpoint'] ?? 'NULL'));

  // Test client registration via HTTP
  $web_client_metadata = [
    'client_name' => 'Web Context Test Client',
    'redirect_uris' => ['https://example.com/callback'],
    'grant_types' => ['authorization_code'],
  ];

  $this->logDebug('About to POST to: ' . $auth_metadata['registration_endpoint']);
  $web_response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
    RequestOptions::JSON => $web_client_metadata,
    RequestOptions::HEADERS => [
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    ],
  ]);

  $this->assertEquals(200, $web_response->getStatusCode());
  $web_response->getBody()->rewind();
  $web_client_data = Json::decode($web_response->getBody()->getContents());
  $this->assertArrayHasKey('client_id', $web_client_data);
}
```

#### Helper 2: API Context OAuth Functionality

Extract the "API Context OAuth Functionality" section (lines ~147-168):

```php
/**
 * Helper: Tests OAuth functionality in API context (service layer).
 *
 * Validates that OAuth services work correctly when called directly
 * without HTTP layer.
 */
protected function helperApiContextOAuthFunctionality(): void {
  // Get metadata service directly
  $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');
  $api_metadata = $metadata_service->getServerMetadata();
  $this->assertArrayHasKey('registration_endpoint', $api_metadata);
  $this->assertStringContainsString('/oauth/register', $api_metadata['registration_endpoint']);

  // Test client registration service directly
  $registration_service = $this->container->get('simple_oauth_client_registration.service.registration');
  $api_client_metadata = new ClientRegistration(
    clientName: 'API Context Test Client',
    redirectUris: ['https://api.example.com/callback'],
    grantTypes: ['authorization_code', 'refresh_token']
  );

  $api_client_data = $registration_service->registerClient($api_client_metadata);
  $this->assertArrayHasKey('client_id', $api_client_data);
  $this->assertArrayHasKey('client_secret', $api_client_data);
  $this->assertArrayHasKey('registration_access_token', $api_client_data);

  // Test client retrieval
  $retrieved_metadata = $registration_service->getClientMetadata($api_client_data['client_id']);
  $this->assertEquals($api_client_metadata->clientName, $retrieved_metadata['client_name']);
}
```

#### Helper 3: Cache Behavior Across Contexts

Extract the "Cache Behavior Across Contexts" section (lines ~170-188):

```php
/**
 * Helper: Tests cache behavior and consistency across contexts.
 *
 * Validates that cache is shared between web and API contexts and that
 * cache invalidation affects both contexts.
 */
protected function helperCacheBehaviorAcrossContexts(): void {
  $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');

  // Test cache generation and consistency
  $metadata1 = $metadata_service->getServerMetadata();
  $metadata2 = $metadata_service->getServerMetadata();
  $this->assertEquals($metadata1['registration_endpoint'], $metadata2['registration_endpoint']);

  // Test HTTP context uses same cache
  $this->drupalGet('/.well-known/oauth-authorization-server');
  $this->assertSession()->statusCodeEquals(200);
  $http_metadata = Json::decode($this->getSession()->getPage()->getContent());
  $this->assertEquals($metadata1['registration_endpoint'], $http_metadata['registration_endpoint']);

  // Test cache invalidation affects both contexts
  $fresh_metadata = $metadata_service->getServerMetadata();
  $this->drupalGet('/.well-known/oauth-authorization-server');
  $this->assertSession()->statusCodeEquals(200);
  $fresh_http_metadata = Json::decode($this->getSession()->getPage()->getContent());
  $this->assertEquals($fresh_metadata['registration_endpoint'], $fresh_http_metadata['registration_endpoint']);
}
```

#### Helper 4: Cross-Context Client Management

Extract the "Cross-Context Client Management" section (lines ~190-207):

```php
/**
 * Helper: Tests client management across different contexts.
 *
 * Validates that clients registered in different contexts can coexist
 * and be managed independently.
 */
protected function helperCrossContextClientManagement(): void {
  // Note: This helper depends on clients created in previous helpers
  // We'll need to retrieve them or recreate them here

  // For simplicity, create new test clients
  $registration_service = $this->container->get('simple_oauth_client_registration.service.registration');

  $web_client = $registration_service->registerClient(new ClientRegistration(
    clientName: 'Cross-Context Web Client',
    redirectUris: ['https://web.example.com/callback']
  ));

  $api_client = $registration_service->registerClient(new ClientRegistration(
    clientName: 'Cross-Context API Client',
    redirectUris: ['https://api.example.com/callback']
  ));

  // Verify different clients
  $this->assertNotEquals($web_client['client_id'], $api_client['client_id']);

  // Test updating web-registered client via HTTP
  $updated_metadata_http = [
    'client_name' => 'Updated Cross-Context Web Client',
    'client_uri' => 'https://updated-web.example.com',
  ];

  $update_response = $this->httpClient->put($web_client['registration_client_uri'], [
    RequestOptions::JSON => $updated_metadata_http,
    RequestOptions::HEADERS => [
      'Authorization' => 'Bearer ' . $web_client['registration_access_token'],
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    ],
  ]);

  $this->assertEquals(200, $update_response->getStatusCode());
  $update_response->getBody()->rewind();
  $updated_data = Json::decode($update_response->getBody()->getContents());
  $this->assertEquals('Updated Cross-Context Web Client', $updated_data['client_name']);
}
```

#### Helper 5: Error Handling Consistency

Extract the "Error Handling Consistency" section (lines ~209-234):

```php
/**
 * Helper: Tests error handling consistency across contexts.
 *
 * Validates that both HTTP and API contexts return appropriate errors
 * for invalid data.
 */
protected function helperErrorHandlingConsistency(): void {
  // Test HTTP context error handling
  $invalid_metadata = [
    'client_name' => 'Invalid Client',
    'redirect_uris' => ['not-a-url'],
  ];

  $http_error_response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
    RequestOptions::JSON => $invalid_metadata,
    RequestOptions::HEADERS => [
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    ],
    RequestOptions::HTTP_ERRORS => FALSE,
  ]);

  $this->assertEquals(400, $http_error_response->getStatusCode());
  $http_error_response->getBody()->rewind();
  $http_error = Json::decode($http_error_response->getBody()->getContents());
  $this->assertEquals('invalid_client_metadata', $http_error['error']);

  // Test API context error handling
  $exception_thrown = FALSE;
  try {
    $registration_service = $this->container->get('simple_oauth_client_registration.service.registration');
    $invalid_dto = new ClientRegistration(
      clientName: 'Invalid Client',
      redirectUris: ['not-a-url']
    );
    $registration_service->registerClient($invalid_dto);
  }
  catch (\Exception $e) {
    $exception_thrown = TRUE;
    $this->assertStringContainsString('Invalid redirect URI', $e->getMessage());
  }
  $this->assertTrue($exception_thrown);
}
```

#### Helper 6: Route Discovery and Concurrent Access

Extract the "Route Discovery and Concurrent Access" section (lines ~236-273):

```php
/**
 * Helper: Tests route discovery and concurrent access patterns.
 *
 * Validates that metadata endpoints remain consistent under concurrent
 * access and cache regeneration.
 */
protected function helperRouteDiscoveryAndConcurrentAccess(): void {
  $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');

  // Test multiple cache invalidations and regenerations
  for ($i = 0; $i < 3; $i++) {
    $metadata = $metadata_service->getServerMetadata();
    $this->assertArrayHasKey('registration_endpoint', $metadata);
    $this->assertStringContainsString('/oauth/register', $metadata['registration_endpoint']);

    // Verify HTTP access still works
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $http_iter_metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertEquals($metadata['registration_endpoint'], $http_iter_metadata['registration_endpoint']);
  }

  // Simulate concurrent metadata generation
  $metadata_results = [];
  for ($i = 0; $i < 5; $i++) {
    $metadata_results[] = $metadata_service->getServerMetadata();
  }

  // All results should be identical (no race conditions)
  $first_result = $metadata_results[0];
  foreach ($metadata_results as $index => $result) {
    $this->assertEquals($first_result['registration_endpoint'], $result['registration_endpoint']);
  }

  // Test concurrent client registrations
  $registration_service = $this->container->get('simple_oauth_client_registration.service.registration');
  $client_results = [];
  for ($i = 0; $i < 3; $i++) {
    $concurrent_metadata = new ClientRegistration(
      clientName: "Concurrent Test Client $i",
      redirectUris: ["https://example$i.com/callback"]
    );
    $client_results[] = $registration_service->registerClient($concurrent_metadata);
  }

  // All clients should have unique IDs
  $client_ids = array_column($client_results, 'client_id');
  $unique_ids = array_unique($client_ids);
  $this->assertEquals(count($client_ids), count($unique_ids));
}
```

### Step 4: Extract Helpers from testConfigurationAndExistingClientIntegration

#### Helper 7: Configuration Changes Propagation

Extract the "Configuration Changes Propagation" section (lines ~286-317):

```php
/**
 * Helper: Tests configuration change propagation across contexts.
 *
 * Validates that configuration changes are reflected in both API and
 * HTTP contexts.
 */
protected function helperConfigurationChangesPropagation(): void {
  $this->logDebug('Testing configuration changes propagation');

  $config = $this->container->get('config.factory')->getEditable('simple_oauth_server_metadata.settings');
  $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');

  // Test auto-detection
  $config->clear('registration_endpoint')->save();
  $metadata = $metadata_service->getServerMetadata();
  $this->assertArrayHasKey('registration_endpoint', $metadata);

  // Verify HTTP endpoint reflects the same
  $this->drupalGet('/.well-known/oauth-authorization-server');
  $this->assertSession()->statusCodeEquals(200);
  $http_metadata = Json::decode($this->getSession()->getPage()->getContent());
  $this->assertEquals($metadata['registration_endpoint'], $http_metadata['registration_endpoint']);

  // Test explicit configuration override
  $custom_endpoint = 'https://custom.example.com/oauth/register';
  $config->set('registration_endpoint', $custom_endpoint)->save();
  $metadata = $metadata_service->getServerMetadata();
  $this->assertEquals($custom_endpoint, $metadata['registration_endpoint']);

  $this->drupalGet('/.well-known/oauth-authorization-server');
  $this->assertSession()->statusCodeEquals(200);
  $http_metadata = Json::decode($this->getSession()->getPage()->getContent());
  $this->assertEquals($custom_endpoint, $http_metadata['registration_endpoint']);

  // Restore auto-detection
  $config->clear('registration_endpoint')->save();
  $metadata = $metadata_service->getServerMetadata();
  $this->assertArrayHasKey('registration_endpoint', $metadata);
  $this->assertStringContainsString('/oauth/register', $metadata['registration_endpoint']);
}
```

#### Helper 8: Integration with Existing Clients

Extract the "Integration with Existing OAuth Clients" section (lines ~319-355):

```php
/**
 * Helper: Tests integration with pre-existing OAuth clients.
 *
 * Validates that dynamic client registration works alongside manually
 * configured OAuth clients.
 */
protected function helperIntegrationWithExistingClients(): void {
  // Create a pre-existing consumer (simulating manually configured client)
  $consumer = Consumer::create([
    'uuid' => 'existing-client-id',
    'label' => 'Existing OAuth Client',
    'description' => 'Pre-existing manually configured client',
    'grant_types' => ['authorization_code', 'refresh_token'],
    'redirect' => ['https://existing.example.com/callback'],
    'confidential' => TRUE,
    'secret' => 'existing-secret',
    'roles' => ['authenticated'],
  ]);
  $consumer->save();

  // Test that metadata endpoints work with existing clients
  $this->drupalGet('/.well-known/oauth-authorization-server');
  $this->assertSession()->statusCodeEquals(200);
  $metadata_with_existing = Json::decode($this->getSession()->getPage()->getContent());
  $this->assertArrayHasKey('registration_endpoint', $metadata_with_existing);

  // Test that new dynamic registration still works
  $registration_service = $this->container->get('simple_oauth_client_registration.service.registration');
  $new_client_metadata = new ClientRegistration(
    clientName: 'New Dynamic Client',
    redirectUris: ['https://new.example.com/callback']
  );
  $new_client_data = $registration_service->registerClient($new_client_metadata);
  $this->assertArrayHasKey('client_id', $new_client_data);
  $this->assertNotEquals('existing-client-id', $new_client_data['client_id']);

  // Test that both clients are accessible
  $consumer_storage = $this->container->get('entity_type.manager')->getStorage('consumer');
  $existing_client = $consumer_storage->loadByProperties(['uuid' => 'existing-client-id']);
  $this->assertNotEmpty($existing_client);
  $new_client = $consumer_storage->loadByProperties(['client_id' => $new_client_data['client_id']]);
  $this->assertNotEmpty($new_client);
}
```

### Step 5: Preserve Utility Method

Keep `clearAllTestCaches()` as **protected** (not a helper):

```php
/**
 * Clears all test-relevant caches.
 */
protected function clearAllTestCaches(): void {
  // ... existing implementation unchanged ...
}
```

### Step 6: Update Class-level PHPDoc

```php
/**
 * Integration tests for OAuth across different execution contexts.
 *
 * Tests OAuth functionality across web, CLI, and test environments to ensure
 * consistent behavior and proper cache handling in all contexts.
 *
 * Tests are consolidated into a single comprehensive test method with
 * granular helper methods for performance optimization.
 */
#[Group('simple_oauth_21')]
#[Group('functional')]
#[Group('oauth_integration')]
class OAuthIntegrationContextTest extends BrowserTestBase {
```

### Step 7: Run Tests

```bash
cd /var/www/html && vendor/bin/phpunit web/modules/contrib/simple_oauth_21/tests/src/Functional/OAuthIntegrationContextTest.php
```

Verify:

- All 8 helpers execute correctly
- Cross-context consistency maintained
- No state leakage between helpers
- DebugLoggingTrait output is helpful

</details>

**Key Challenge**: Decomposing large monolithic test methods into logical helper sections while maintaining test flow.

**Critical Reminders:**

- **Test Decomposition**: Extract logical sections, not arbitrary splits
- **State Management**: Some helpers depend on entities created by previous helpers
- **DebugLoggingTrait**: Preserve `logDebug()` calls for troubleshooting
- **Cross-Context Testing**: Verify web and API contexts remain consistent
- Use `ClientRegistration` DTO for API-level testing
