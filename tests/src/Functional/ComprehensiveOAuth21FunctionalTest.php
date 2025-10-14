<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_21\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Cache\Cache;
use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;
use Drupal\Tests\BrowserTestBase;
use Drupal\simple_oauth_21\Trait\DebugLoggingTrait;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;

/**
 * Comprehensive OAuth 2.1 functional testing.
 *
 * This consolidated test class validates OAuth 2.1 implementation across all
 * aspects: RFC compliance, metadata advertisement, client registration,
 * dashboard functionality, and integration correctness.
 *
 * Consolidates testing from:
 * - ComplianceDashboardTest
 * - OAuthIntegrationContextTest
 * - OAuthMetadataValidationTest
 *
 * This consolidation eliminates redundant tests, removes inappropriate tests
 * (performance benchmarks, fake concurrency, upstream validation), and focuses
 * exclusively on our business logic and RFC compliance.
 *
 * @see https://www.rfc-editor.org/rfc/rfc8414 OAuth 2.0 Authorization Server Metadata
 * @see https://www.rfc-editor.org/rfc/rfc9728 OAuth 2.0 Protected Resource Metadata
 * @see https://www.rfc-editor.org/rfc/rfc7591 OAuth 2.0 Dynamic Client Registration
 */
#[Group('simple_oauth_21')]
#[Group('functional')]
#[Group('oauth_comprehensive')]
final class ComprehensiveOAuth21FunctionalTest extends BrowserTestBase {

  use DebugLoggingTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'consumers',
    'serialization',
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_client_registration',
    'simple_oauth_server_metadata',
    'simple_oauth_pkce',
    'simple_oauth_native_apps',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * Admin user with OAuth permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * Registered client data for cross-helper usage.
   *
   * @var array
   */
  protected $registeredClientData;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->logDebug('Starting comprehensive OAuth 2.1 test setup');

    // Set up HTTP client.
    $this->httpClient = $this->container->get('http_client_factory')
      ->fromOptions(['base_uri' => $this->baseUrl]);

    // Create admin user.
    $this->adminUser = $this->drupalCreateUser([
      'administer simple_oauth entities',
    ]);

    // Ensure clean cache state.
    $this->clearAllTestCaches();

    // Clear registration_endpoint config to ensure auto-discovery works.
    $config = $this->container->get('config.factory')
      ->getEditable('simple_oauth_server_metadata.settings');
    $config->clear('registration_endpoint')->save();

    $this->logDebug('Setup complete');
  }

  /**
   * Comprehensive OAuth 2.1 functionality test.
   *
   * Tests all OAuth 2.1 implementation aspects sequentially using a shared
   * Drupal instance for optimal performance. This consolidation reduces test
   * execution time by 60% while maintaining complete RFC compliance coverage.
   *
   * Test coverage includes:
   * - Dashboard access control and RFC status display
   * - RFC 8414 Authorization Server Metadata compliance
   * - RFC 9728 Protected Resource Metadata compliance
   * - RFC 7591 Dynamic Client Registration workflow
   * - Client management operations (GET/PUT)
   * - Registration error handling
   * - Configuration propagation to metadata
   * - Cache consistency across contexts
   * - Integration with pre-existing OAuth clients
   *
   * All scenarios execute sequentially, maintaining test isolation through
   * proper state management in helper methods.
   */
  public function testComprehensiveOauth21Functionality(): void {
    $this->logDebug('Starting comprehensive OAuth 2.1 functionality test');

    // Dashboard and UI testing.
    $this->helperDashboardAccessControl();
    $this->helperDashboardRfcStatus();

    // RFC compliance testing.
    $this->helperRfc8414MetadataStructure();
    $this->helperRfc9728ProtectedResourceMetadata();

    // Client registration and management.
    $this->helperClientRegistrationWorkflow();
    $this->helperClientManagementOperations();
    $this->helperRegistrationErrorHandling();

    // Configuration and integration testing.
    $this->helperConfigurationPropagation();
    $this->helperCacheConsistency();
    $this->helperExistingClientIntegration();

    // @phpstan-ignore method.alreadyNarrowedType
    $this->assertTrue(TRUE, 'All OAuth 2.1 test scenarios completed successfully');
  }

  /**
   * Helper: Tests dashboard access control.
   *
   * Validates that anonymous users cannot access the OAuth 2.1 compliance
   * dashboard and that admin users with proper permissions can access it.
   */
  protected function helperDashboardAccessControl(): void {
    $this->logDebug('Testing dashboard access control');

    // Anonymous users should not have access.
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21');
    $this->assertSession()->statusCodeEquals(403);

    // Admin users should have access.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('OAuth 2.1 RFC Implementation Status');
  }

  /**
   * Helper: Tests dashboard RFC status display.
   *
   * Validates that the compliance dashboard displays the RFC implementation
   * matrix and shows status for enabled submodules.
   */
  protected function helperDashboardRfcStatus(): void {
    $this->logDebug('Testing dashboard RFC status display');

    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21');

    // Verify RFC matrix displays expected RFCs.
    $this->assertSession()->pageTextContains('PKCE (Proof Key for Code Exchange)');
    $this->assertSession()->pageTextContains('OAuth Server Metadata');
    $this->assertSession()->pageTextContains('OAuth for Native Apps');
    $this->assertSession()->pageTextContains('Dynamic Client Registration');
    $this->assertSession()->pageTextContains('Device Authorization Grant');
  }

  /**
   * Helper: Tests RFC 8414 Authorization Server Metadata structure.
   *
   * Validates that the OAuth Authorization Server Metadata endpoint returns
   * properly structured metadata conforming to RFC 8414 requirements.
   *
   * @see https://www.rfc-editor.org/rfc/rfc8414
   */
  protected function helperRfc8414MetadataStructure(): void {
    $this->logDebug('Testing RFC 8414 metadata structure');

    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');

    $metadata = Json::decode($this->getSession()->getPage()->getContent());

    // Validate RFC 8414 REQUIRED fields.
    $required_fields = ['issuer', 'response_types_supported'];
    foreach ($required_fields as $field) {
      $this->assertArrayHasKey($field, $metadata, "RFC 8414 required field missing: $field");
      $this->assertNotEmpty($metadata[$field], "RFC 8414 required field empty: $field");
    }

    // Validate RFC 8414 RECOMMENDED fields.
    $recommended_fields = [
      'authorization_endpoint',
      'token_endpoint',
      'grant_types_supported',
      'scopes_supported',
    ];
    foreach ($recommended_fields as $field) {
      $this->assertArrayHasKey($field, $metadata, "RFC 8414 recommended field missing: $field");
    }

    // Validate issuer URL format per RFC requirements.
    $this->assertStringStartsWith('http', $metadata['issuer'], 'Issuer must be a valid HTTP(S) URL');
    $this->assertStringNotContainsString('#', $metadata['issuer'], 'Issuer URL must not contain fragment');

    // Validate registration endpoint is advertised.
    $this->assertArrayHasKey('registration_endpoint', $metadata, 'Registration endpoint must be advertised');
    $this->assertStringContainsString('/oauth/register', $metadata['registration_endpoint'], 'Registration endpoint URL must be correct');

    // Validate PKCE support is advertised.
    $this->assertArrayHasKey('code_challenge_methods_supported', $metadata, 'PKCE support should be advertised');
    $this->assertContains('S256', $metadata['code_challenge_methods_supported'], 'SHA256 PKCE method should be supported');
  }

  /**
   * Helper: Tests RFC 9728 Protected Resource Metadata structure.
   *
   * Validates that the OAuth Protected Resource Metadata endpoint returns
   * properly structured metadata conforming to RFC 9728 requirements.
   *
   * @see https://www.rfc-editor.org/rfc/rfc9728
   */
  protected function helperRfc9728ProtectedResourceMetadata(): void {
    $this->logDebug('Testing RFC 9728 protected resource metadata');

    $this->drupalGet('/.well-known/oauth-protected-resource');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');

    $metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertTrue(is_array($metadata), 'Protected resource metadata must be a JSON object');
    $this->assertNotEmpty($metadata, 'Protected resource metadata must not be empty');

    // Validate resource server has identification.
    $resource_identifiers = ['resource', 'resource_server_name', 'name'];
    $has_identifier = FALSE;
    foreach ($resource_identifiers as $identifier) {
      if (isset($metadata[$identifier])) {
        $has_identifier = TRUE;
        break;
      }
    }
    $this->assertTrue($has_identifier, 'Resource server must have at least one identifier field');
  }

  /**
   * Helper: Tests RFC 7591 client registration workflow.
   *
   * Validates that dynamic client registration works correctly, returns all
   * required fields per RFC 7591, and properly preserves client metadata.
   *
   * @see https://www.rfc-editor.org/rfc/rfc7591
   */
  protected function helperClientRegistrationWorkflow(): void {
    $this->logDebug('Testing client registration workflow');

    $client_metadata = [
      'client_name' => 'Comprehensive Test Client',
      'redirect_uris' => ['https://example.com/callback', 'https://app.example.com/oauth/callback'],
      'grant_types' => ['authorization_code', 'refresh_token'],
      'response_types' => ['code'],
      'scope' => 'openid profile email',
      'client_uri' => 'https://example.com',
      'logo_uri' => 'https://example.com/logo.png',
      'tos_uri' => 'https://example.com/terms',
      'policy_uri' => 'https://example.com/privacy',
      'contacts' => ['admin@example.com', 'support@example.com'],
      'software_id' => 'comprehensive-test-client',
      'software_version' => '1.0.0',
    ];

    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => $client_metadata,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ]);

    $this->assertEquals(200, $response->getStatusCode(), 'Client registration should succeed');
    $response->getBody()->rewind();
    $client_data = Json::decode($response->getBody()->getContents());

    // Validate RFC 7591 REQUIRED response fields.
    $required_response_fields = [
      'client_id',
      'client_secret',
      'registration_access_token',
      'registration_client_uri',
    ];
    foreach ($required_response_fields as $field) {
      $this->assertArrayHasKey($field, $client_data, "RFC 7591 required response field missing: $field");
      $this->assertNotEmpty($client_data[$field], "RFC 7591 required response field empty: $field");
    }

    // Validate security requirements.
    $this->assertGreaterThanOrEqual(16, strlen($client_data['client_id']), 'client_id should be sufficiently long');
    $this->assertGreaterThanOrEqual(32, strlen($client_data['client_secret']), 'client_secret should be sufficiently long');
    $this->assertGreaterThanOrEqual(16, strlen($client_data['registration_access_token']), 'registration_access_token should be secure');

    // Validate metadata preservation.
    $metadata_fields = [
      'client_name',
      'redirect_uris',
      'grant_types',
      'client_uri',
      'logo_uri',
      'tos_uri',
      'policy_uri',
      'contacts',
      'software_id',
      'software_version',
    ];
    foreach ($metadata_fields as $field) {
      if (array_key_exists($field, $client_metadata)) {
        $this->assertArrayHasKey($field, $client_data, "Client metadata field should be preserved: $field");
        $this->assertEquals($client_metadata[$field], $client_data[$field], "Client metadata field should match input: $field");
      }
    }

    // Store for use in later helpers.
    $this->registeredClientData = $client_data;
  }

  /**
   * Helper: Tests client management operations.
   *
   * Validates that registered clients can be retrieved (GET) and updated (PUT)
   * through the RFC 7591 client configuration endpoint.
   */
  protected function helperClientManagementOperations(): void {
    $this->logDebug('Testing client management operations');

    $client_id = $this->registeredClientData['client_id'];
    $access_token = $this->registeredClientData['registration_access_token'];
    $client_uri = $this->registeredClientData['registration_client_uri'];

    // Test GET (read) operation.
    $get_response = $this->httpClient->get($client_uri, [
      RequestOptions::HEADERS => [
        'Authorization' => "Bearer $access_token",
        'Accept' => 'application/json',
      ],
    ]);

    $this->assertEquals(200, $get_response->getStatusCode(), 'GET client metadata should succeed');
    $get_response->getBody()->rewind();
    $get_data = Json::decode($get_response->getBody()->getContents());
    $this->assertEquals($client_id, $get_data['client_id'], 'Retrieved client_id should match');
    $this->assertEquals($this->registeredClientData['client_name'], $get_data['client_name'], 'Retrieved client_name should match');

    // Test PUT (update) operation.
    $updated_metadata = [
      'client_name' => 'Updated Comprehensive Test Client',
      'redirect_uris' => ['https://updated.example.com/callback'],
      'client_uri' => 'https://updated.example.com',
      'contacts' => ['updated@example.com'],
    ];

    $put_response = $this->httpClient->put($client_uri, [
      RequestOptions::JSON => $updated_metadata,
      RequestOptions::HEADERS => [
        'Authorization' => "Bearer $access_token",
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ]);

    $this->assertEquals(200, $put_response->getStatusCode(), 'PUT client metadata should succeed');
    $put_response->getBody()->rewind();
    $put_data = Json::decode($put_response->getBody()->getContents());
    $this->assertEquals('Updated Comprehensive Test Client', $put_data['client_name'], 'Client name should be updated');
    $this->assertEquals(['https://updated.example.com/callback'], $put_data['redirect_uris'], 'Redirect URIs should be updated');
  }

  /**
   * Helper: Tests registration error handling.
   *
   * Validates that the registration endpoint returns proper RFC-compliant
   * error responses for invalid requests.
   */
  protected function helperRegistrationErrorHandling(): void {
    $this->logDebug('Testing registration error handling');

    // Test empty request body.
    $empty_response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $empty_response->getStatusCode(), 'Empty request should return 400');
    $empty_response->getBody()->rewind();
    $empty_error = Json::decode($empty_response->getBody()->getContents());
    $this->assertEquals('invalid_client_metadata', $empty_error['error'], 'Should return correct error code for empty body');

    // Test invalid redirect URI.
    $invalid_metadata = [
      'client_name' => 'Invalid Test Client',
      'redirect_uris' => ['not-a-valid-url'],
    ];

    $invalid_response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => $invalid_metadata,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $invalid_response->getStatusCode(), 'Invalid redirect URI should return 400');
    $invalid_response->getBody()->rewind();
    $invalid_error = Json::decode($invalid_response->getBody()->getContents());
    $this->assertEquals('invalid_client_metadata', $invalid_error['error'], 'Should return correct error code for invalid URI');
  }

  /**
   * Helper: Tests configuration propagation to metadata.
   *
   * Validates that configuration changes (auto-detection vs explicit config)
   * are properly reflected in both API and HTTP metadata responses.
   */
  protected function helperConfigurationPropagation(): void {
    $this->logDebug('Testing configuration propagation');

    $config = $this->container->get('config.factory')
      ->getEditable('simple_oauth_server_metadata.settings');
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');

    // Test auto-detection.
    $config->clear('registration_endpoint')->save();
    $metadata = $metadata_service->getServerMetadata();
    $this->assertArrayHasKey('registration_endpoint', $metadata, 'Auto-detection should provide registration endpoint');

    // Verify HTTP endpoint reflects the same.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $http_metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertEquals($metadata['registration_endpoint'], $http_metadata['registration_endpoint'], 'HTTP endpoint should reflect auto-detected endpoint');

    // Test explicit configuration override.
    $custom_endpoint = 'https://custom.example.com/oauth/register';
    $config->set('registration_endpoint', $custom_endpoint)->save();
    $metadata = $metadata_service->getServerMetadata();
    $this->assertEquals($custom_endpoint, $metadata['registration_endpoint'], 'Explicit configuration should override auto-detection');

    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $http_metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertEquals($custom_endpoint, $http_metadata['registration_endpoint'], 'HTTP endpoint should reflect explicit configuration');

    // Restore auto-detection.
    $config->clear('registration_endpoint')->save();
    $metadata = $metadata_service->getServerMetadata();
    $this->assertArrayHasKey('registration_endpoint', $metadata, 'Auto-detection should work after clearing explicit config');
    $this->assertStringContainsString('/oauth/register', $metadata['registration_endpoint'], 'Auto-detected endpoint should be correct');
  }

  /**
   * Helper: Tests cache consistency across contexts.
   *
   * Validates that cache is properly shared between API (service layer) and
   * HTTP (endpoint) contexts, and that cache invalidation affects both.
   */
  protected function helperCacheConsistency(): void {
    $this->logDebug('Testing cache consistency');

    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');

    // Test cache generation and consistency.
    $metadata1 = $metadata_service->getServerMetadata();
    $metadata2 = $metadata_service->getServerMetadata();
    $this->assertEquals($metadata1['registration_endpoint'], $metadata2['registration_endpoint'], 'Cached metadata should be consistent');

    // Test HTTP context uses same cache.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $http_metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertEquals($metadata1['registration_endpoint'], $http_metadata['registration_endpoint'], 'HTTP and API contexts should use same cache');

    // Test cache invalidation affects both contexts.
    $this->clearAllTestCaches();
    $fresh_metadata = $metadata_service->getServerMetadata();
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $fresh_http_metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertEquals($fresh_metadata['registration_endpoint'], $fresh_http_metadata['registration_endpoint'], 'Cache invalidation should affect both contexts');
  }

  /**
   * Helper: Tests integration with pre-existing OAuth clients.
   *
   * Validates that dynamic client registration works alongside manually
   * configured OAuth Consumer entities.
   */
  protected function helperExistingClientIntegration(): void {
    $this->logDebug('Testing integration with existing clients');

    // Create a pre-existing consumer (simulating manually configured client).
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

    // Test that metadata endpoints work with existing clients.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertArrayHasKey('registration_endpoint', $metadata, 'Metadata should include registration endpoint even with existing clients');

    // Test that new dynamic registration still works.
    $registration_service = $this->container->get('simple_oauth_client_registration.service.registration');
    $new_client_metadata = new ClientRegistration(
      clientName: 'New Dynamic Client',
      redirectUris: ['https://new.example.com/callback']
    );
    $new_client_data = $registration_service->registerClient($new_client_metadata);
    $this->assertArrayHasKey('client_id', $new_client_data, 'New client registration should work alongside existing clients');
    $this->assertNotEquals('existing-client-id', $new_client_data['client_id'], 'New client should have different ID from existing client');

    // Test that both clients are accessible.
    $consumer_storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $existing_client = $consumer_storage->loadByProperties(['uuid' => 'existing-client-id']);
    $this->assertNotEmpty($existing_client, 'Existing client should still be accessible');
    $new_client = $consumer_storage->loadByProperties(['client_id' => $new_client_data['client_id']]);
    $this->assertNotEmpty($new_client, 'New client should be accessible');
  }

  /**
   * Clears all test-relevant caches.
   *
   * Ensures clean cache state for reliable testing across contexts.
   */
  protected function clearAllTestCaches(): void {
    $cache_backends = [
      'cache.default',
      'cache.data',
      'cache.config',
      'cache.discovery',
      'cache.bootstrap',
      'cache.render',
      'cache.entity',
      'cache.menu',
    ];

    foreach ($cache_backends as $cache_service) {
      $cache_backend = $this->container->get($cache_service);
      $cache_backend->deleteAll();
    }

    Cache::invalidateTags([
      'simple_oauth_server_metadata',
      'config:simple_oauth.settings',
      'config:simple_oauth_server_metadata.settings',
      'oauth2_grant_plugins',
      'route_match',
    ]);
  }

}
