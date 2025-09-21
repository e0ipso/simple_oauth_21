<?php

namespace Drupal\Tests\simple_oauth_21\Functional;

use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;
use Drupal\Core\Cache\Cache;
use Drupal\Tests\BrowserTestBase;
use Drupal\Component\Serialization\Json;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Client;
use Drupal\consumers\Entity\Consumer;

/**
 * Integration tests for OAuth across different execution contexts.
 *
 * Tests OAuth functionality across web, CLI, and test environments to ensure
 * consistent behavior and proper cache handling in all contexts.
 *
 * @group simple_oauth_21
 * @group functional
 * @group oauth_integration
 */
class OAuthIntegrationContextTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Drupal 11 workaround: Ensure routes are properly registered
    // The functional test environment needs explicit cache clearing.
    // @todo Investigate why D11 requires this additional cache clear
    if (version_compare(\Drupal::VERSION, '11.0', '>=')) {
      // Rebuild routes to ensure OAuth endpoints are available.
      $this->container->get('router.builder')->rebuild();
    }

    $this->httpClient = new Client();

    // Ensure clean state.
    $this->clearAllTestCaches();
  }

  /**
   * Comprehensive OAuth integration testing across all contexts.
   *
   * This test consolidates OAuth functionality testing across web, API, and
   * cache contexts for improved performance while maintaining comprehensive
   * coverage.
   */
  public function testComprehensiveOauthIntegrationAcrossContexts() {
    // === Web Context OAuth Workflow ===
    // Test metadata endpoints are accessible via HTTP
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');

    $auth_metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertArrayHasKey('registration_endpoint', $auth_metadata, 'Registration endpoint must be advertised in web context');

    // Test client registration via HTTP.
    $web_client_metadata = [
      'client_name' => 'Web Context Test Client',
      'redirect_uris' => ['https://example.com/callback'],
      'grant_types' => ['authorization_code'],
    ];
    $web_response = $this->httpClient->post($auth_metadata['registration_endpoint'], [
      RequestOptions::JSON => $web_client_metadata,
      RequestOptions::HEADERS => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
    ]);
    $this->assertEquals(200, $web_response->getStatusCode(), 'Client registration should work in web context');
    $web_client_data = Json::decode($web_response->getBody()->getContents());
    $this->assertArrayHasKey('client_id', $web_client_data, 'Client ID should be generated in web context');

    // === API Context OAuth Functionality ===
    // Get metadata service directly
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');
    $api_metadata = $metadata_service->getServerMetadata();
    $this->assertArrayHasKey('registration_endpoint', $api_metadata, 'Registration endpoint must be available in API context');
    $this->assertStringContainsString('/oauth/register', $api_metadata['registration_endpoint'], 'Registration endpoint URL must be correct in API context');

    // Test client registration service directly.
    $registration_service = $this->container->get('simple_oauth_client_registration.service.registration');
    $api_client_metadata = new ClientRegistration(
      clientName: 'API Context Test Client',
      redirectUris: ['https://api.example.com/callback'],
      grantTypes: ['authorization_code', 'refresh_token']
    );
    $api_client_data = $registration_service->registerClient($api_client_metadata);
    $this->assertArrayHasKey('client_id', $api_client_data, 'Client ID should be generated in API context');
    $this->assertArrayHasKey('client_secret', $api_client_data, 'Client secret should be generated in API context');
    $this->assertArrayHasKey('registration_access_token', $api_client_data, 'Registration access token should be generated in API context');

    // Test client retrieval.
    $retrieved_metadata = $registration_service->getClientMetadata($api_client_data['client_id']);
    $this->assertEquals($api_client_metadata->clientName, $retrieved_metadata['client_name'], 'Client metadata should be retrievable in API context');

    // === Cache Behavior Across Contexts ===
    // Test cache generation and consistency
    $metadata_service->invalidateCache();
    $metadata1 = $metadata_service->getServerMetadata();
    $metadata2 = $metadata_service->getServerMetadata();
    $this->assertEquals($metadata1['registration_endpoint'], $metadata2['registration_endpoint'], 'Cached metadata should be consistent');

    // Test HTTP context uses same cache.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $http_metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertEquals($metadata1['registration_endpoint'], $http_metadata['registration_endpoint'], 'HTTP and API contexts should use same cache');

    // Test cache invalidation affects both contexts.
    $metadata_service->invalidateCache();
    $fresh_metadata = $metadata_service->getServerMetadata();
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $fresh_http_metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertEquals($fresh_metadata['registration_endpoint'], $fresh_http_metadata['registration_endpoint'], 'Cache invalidation should affect both contexts');

    // === Cross-Context Client Management ===
    // Verify that clients registered in different contexts can coexist
    $this->assertNotEquals($web_client_data['client_id'], $api_client_data['client_id'], 'Web and API clients should have different IDs');

    // Test updating web-registered client via HTTP.
    $updated_metadata_http = ['client_name' => 'Updated Web Client', 'client_uri' => 'https://updated-web.example.com'];
    $update_response = $this->httpClient->put($web_client_data['registration_client_uri'], [
      RequestOptions::JSON => $updated_metadata_http,
      RequestOptions::HEADERS => [
        'Authorization' => 'Bearer ' . $web_client_data['registration_access_token'],
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ]);
    $this->assertEquals(200, $update_response->getStatusCode(), 'Client should be updatable via HTTP');
    $updated_data = Json::decode($update_response->getBody()->getContents());
    $this->assertEquals('Updated Web Client', $updated_data['client_name'], 'Client name should be updated via HTTP');

    // === Error Handling Consistency ===
    // Test HTTP context error handling
    $invalid_metadata = ['client_name' => 'Invalid Client', 'redirect_uris' => ['not-a-url']];
    $http_error_response = $this->httpClient->post($auth_metadata['registration_endpoint'], [
      RequestOptions::JSON => $invalid_metadata,
      RequestOptions::HEADERS => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);
    $this->assertEquals(400, $http_error_response->getStatusCode(), 'HTTP context should return 400 for invalid data');
    $http_error = Json::decode($http_error_response->getBody()->getContents());
    $this->assertEquals('invalid_client_metadata', $http_error['error'], 'HTTP context should return correct error code');

    // Test API context error handling.
    $exception_thrown = FALSE;
    try {
      $invalid_dto = new ClientRegistration(
        clientName: 'Invalid Client',
        redirectUris: ['not-a-url']
      );
      $registration_service->registerClient($invalid_dto);
    }
    catch (\Exception $e) {
      $exception_thrown = TRUE;
      $this->assertStringContainsString('Invalid redirect URI', $e->getMessage(), 'API context should throw meaningful exception');
    }
    $this->assertTrue($exception_thrown, 'API context should throw exception for invalid data');

    // === Route Discovery and Concurrent Access ===
    // Test multiple cache invalidations and regenerations
    for ($i = 0; $i < 3; $i++) {
      $metadata_service->invalidateCache();
      $metadata = $metadata_service->getServerMetadata();
      $this->assertArrayHasKey('registration_endpoint', $metadata, "Registration endpoint should be discoverable in iteration $i");
      $this->assertStringContainsString('/oauth/register', $metadata['registration_endpoint'], "Registration endpoint URL should be correct in iteration $i");

      // Verify HTTP access still works.
      $this->drupalGet('/.well-known/oauth-authorization-server');
      $this->assertSession()->statusCodeEquals(200);
      $http_iter_metadata = Json::decode($this->getSession()->getPage()->getContent());
      $this->assertEquals($metadata['registration_endpoint'], $http_iter_metadata['registration_endpoint'], "HTTP and API endpoints should match in iteration $i");
    }

    // Simulate concurrent metadata generation.
    $metadata_service->invalidateCache();
    $metadata_results = [];
    for ($i = 0; $i < 5; $i++) {
      $metadata_results[] = $metadata_service->getServerMetadata();
    }
    // All results should be identical (no race conditions)
    $first_result = $metadata_results[0];
    foreach ($metadata_results as $index => $result) {
      $this->assertEquals($first_result['registration_endpoint'], $result['registration_endpoint'], "Concurrent access result $index should be consistent");
    }

    // Test concurrent client registrations.
    $client_results = [];
    for ($i = 0; $i < 3; $i++) {
      $concurrent_metadata = new ClientRegistration(
        clientName: "Concurrent Test Client $i",
        redirectUris: ["https://example$i.com/callback"]
      );
      $client_results[] = $registration_service->registerClient($concurrent_metadata);
    }
    // All clients should have unique IDs.
    $client_ids = array_column($client_results, 'client_id');
    $unique_ids = array_unique($client_ids);
    $this->assertEquals(count($client_ids), count($unique_ids), 'All client IDs should be unique');
  }

  /**
   * Test configuration changes and existing client integration.
   *
   * Tests configuration propagation across contexts and integration with
   * pre-existing OAuth clients.
   */
  public function testConfigurationAndExistingClientIntegration() {
    // === Configuration Changes Propagation ===
    $config = $this->container->get('config.factory')->getEditable('simple_oauth_server_metadata.settings');
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');

    // Test auto-detection.
    $config->clear('registration_endpoint')->save();
    $metadata_service->invalidateCache();
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
    $metadata_service->invalidateCache();
    $metadata = $metadata_service->getServerMetadata();
    $this->assertEquals($custom_endpoint, $metadata['registration_endpoint'], 'Explicit configuration should override auto-detection');

    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $http_metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertEquals($custom_endpoint, $http_metadata['registration_endpoint'], 'HTTP endpoint should reflect explicit configuration');

    // Restore auto-detection.
    $config->clear('registration_endpoint')->save();
    $metadata_service->invalidateCache();
    $metadata = $metadata_service->getServerMetadata();
    $this->assertArrayHasKey('registration_endpoint', $metadata, 'Auto-detection should work after clearing explicit config');
    $this->assertStringContainsString('/oauth/register', $metadata['registration_endpoint'], 'Auto-detected endpoint should be correct');

    // === Integration with Existing OAuth Clients ===
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

    // Test that metadata endpoints work with existing clients.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $metadata_with_existing = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertArrayHasKey('registration_endpoint', $metadata_with_existing, 'Metadata should include registration endpoint even with existing clients');

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

    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');
    $metadata_service->invalidateCache();

    Cache::invalidateTags([
      'simple_oauth_server_metadata',
      'config:simple_oauth.settings',
      'config:simple_oauth_server_metadata.settings',
      'oauth2_grant_plugins',
      'route_match',
    ]);
  }

}
