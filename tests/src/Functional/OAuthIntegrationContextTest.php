<?php

namespace Drupal\Tests\simple_oauth_21\Functional;

use Drupal\Core\Cache\Cache;
use Drupal\Tests\BrowserTestBase;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
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
    $this->httpClient = new Client();

    // Ensure clean state.
    $this->clearAllTestCaches();
  }

  /**
   * Test OAuth workflow in web context (HTTP requests).
   */
  public function testWebContextOAuthWorkflow() {
    // Test metadata endpoints are accessible via HTTP.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');

    $auth_metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertArrayHasKey('registration_endpoint', $auth_metadata, 'Registration endpoint must be advertised in web context');

    // Test client registration via HTTP.
    $client_metadata = [
      'client_name' => 'Web Context Test Client',
      'redirect_uris' => ['https://example.com/callback'],
      'grant_types' => ['authorization_code'],
    ];

    $response = $this->httpClient->post($auth_metadata['registration_endpoint'], [
      RequestOptions::JSON => $client_metadata,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ]);

    $this->assertEquals(200, $response->getStatusCode(), 'Client registration should work in web context');
    $client_data = Json::decode($response->getBody()->getContents());
    $this->assertArrayHasKey('client_id', $client_data, 'Client ID should be generated in web context');

    return $client_data;
  }

  /**
   * Test OAuth functionality in API/service context (direct service calls).
   */
  public function testAPIContextOAuthFunctionality() {
    // Get metadata service directly.
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');
    $metadata = $metadata_service->getServerMetadata();

    $this->assertArrayHasKey('registration_endpoint', $metadata, 'Registration endpoint must be available in API context');
    $this->assertStringContainsString('/oauth/register', $metadata['registration_endpoint'], 'Registration endpoint URL must be correct in API context');

    // Test client registration service directly.
    $registration_service = $this->container->get('simple_oauth_client_registration.service.registration');

    $client_metadata = [
      'client_name' => 'API Context Test Client',
      'redirect_uris' => ['https://api.example.com/callback'],
      'grant_types' => ['authorization_code', 'refresh_token'],
    ];

    $client_data = $registration_service->registerClient($client_metadata);

    $this->assertArrayHasKey('client_id', $client_data, 'Client ID should be generated in API context');
    $this->assertArrayHasKey('client_secret', $client_data, 'Client secret should be generated in API context');
    $this->assertArrayHasKey('registration_access_token', $client_data, 'Registration access token should be generated in API context');

    // Test client retrieval.
    $retrieved_metadata = $registration_service->getClientMetadata($client_data['client_id']);
    $this->assertEquals($client_metadata['client_name'], $retrieved_metadata['client_name'], 'Client metadata should be retrievable in API context');

    return $client_data;
  }

  /**
   * Test cache behavior across different contexts.
   */
  public function testCacheBehaviorAcrossContexts() {
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');

    // Test 1: Generate metadata and cache it.
    $metadata_service->invalidateCache();
    $metadata1 = $metadata_service->getServerMetadata();

    // Test 2: Retrieve from cache.
    $metadata2 = $metadata_service->getServerMetadata();

    // Verify consistency.
    $this->assertEquals($metadata1['registration_endpoint'], $metadata2['registration_endpoint'], 'Cached metadata should be consistent');

    // Test 3: HTTP context should use same cache.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $http_metadata = Json::decode($this->getSession()->getPage()->getContent());

    $this->assertEquals($metadata1['registration_endpoint'], $http_metadata['registration_endpoint'], 'HTTP and API contexts should use same cache');

    // Test 4: Cache invalidation affects both contexts.
    $metadata_service->invalidateCache();
    $fresh_metadata = $metadata_service->getServerMetadata();

    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $fresh_http_metadata = Json::decode($this->getSession()->getPage()->getContent());

    $this->assertEquals($fresh_metadata['registration_endpoint'], $fresh_http_metadata['registration_endpoint'], 'Cache invalidation should affect both contexts');
  }

  /**
   * Test error handling consistency across contexts.
   */
  public function testErrorHandlingConsistencyAcrossContexts() {
    // Test HTTP context error handling.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $auth_metadata = Json::decode($this->getSession()->getPage()->getContent());

    $invalid_metadata = [
      'client_name' => 'Invalid Client',
      'redirect_uris' => ['not-a-url'],
    ];

    $http_response = $this->httpClient->post($auth_metadata['registration_endpoint'], [
      RequestOptions::JSON => $invalid_metadata,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $http_response->getStatusCode(), 'HTTP context should return 400 for invalid data');
    $http_error = Json::decode($http_response->getBody()->getContents());
    $this->assertEquals('invalid_client_metadata', $http_error['error'], 'HTTP context should return correct error code');

    // Test API context error handling.
    $registration_service = $this->container->get('simple_oauth_client_registration.service.registration');

    $exception_thrown = FALSE;
    try {
      $registration_service->registerClient($invalid_metadata);
    }
    catch (\Exception $e) {
      $exception_thrown = TRUE;
      $this->assertStringContainsString('Invalid redirect URI', $e->getMessage(), 'API context should throw meaningful exception');
    }

    $this->assertTrue($exception_thrown, 'API context should throw exception for invalid data');
  }

  /**
   * Test client management across contexts.
   */
  public function testClientManagementAcrossContexts() {
    // Register client via HTTP.
    $web_client_data = $this->testWebContextOAuthWorkflow();

    // Manage client via API.
    $registration_service = $this->container->get('simple_oauth_client_registration.service.registration');

    $retrieved_metadata = $registration_service->getClientMetadata($web_client_data['client_id']);
    $this->assertEquals($web_client_data['client_name'], $retrieved_metadata['client_name'], 'Client registered via HTTP should be accessible via API');

    // Update client via API.
    $updated_metadata = [
      'client_name' => 'Updated via API',
      'client_uri' => 'https://updated.example.com',
    ];

    $updated_data = $registration_service->updateClientMetadata($web_client_data['client_id'], $updated_metadata);
    $this->assertEquals('Updated via API', $updated_data['client_name'], 'Client should be updatable via API');

    // Verify update via HTTP.
    $http_response = $this->httpClient->get($web_client_data['registration_client_uri'], [
      RequestOptions::HEADERS => [
        'Authorization' => 'Bearer ' . $web_client_data['registration_access_token'],
        'Accept' => 'application/json',
      ],
    ]);

    $this->assertEquals(200, $http_response->getStatusCode(), 'Updated client should be accessible via HTTP');
    $http_data = Json::decode($http_response->getBody()->getContents());
    $this->assertEquals('Updated via API', $http_data['client_name'], 'Updates via API should be visible via HTTP');
  }

  /**
   * Test route discovery and URL generation across contexts.
   */
  public function testRouteDiscoveryAcrossContexts() {
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');

    // Test multiple cache invalidations and regenerations.
    for ($i = 0; $i < 3; $i++) {
      $metadata_service->invalidateCache();
      $metadata = $metadata_service->getServerMetadata();

      $this->assertArrayHasKey('registration_endpoint', $metadata, "Registration endpoint should be discoverable in iteration $i");
      $this->assertStringContainsString('/oauth/register', $metadata['registration_endpoint'], "Registration endpoint URL should be correct in iteration $i");

      // Verify HTTP access still works.
      $this->drupalGet('/.well-known/oauth-authorization-server');
      $this->assertSession()->statusCodeEquals(200);
      $http_metadata = Json::decode($this->getSession()->getPage()->getContent());

      $this->assertEquals($metadata['registration_endpoint'], $http_metadata['registration_endpoint'], "HTTP and API endpoints should match in iteration $i");
    }
  }

  /**
   * Test concurrent access and race conditions.
   */
  public function testConcurrentAccessAndRaceConditions() {
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');

    // Simulate concurrent metadata generation.
    $metadata_service->invalidateCache();

    $metadata_results = [];
    for ($i = 0; $i < 5; $i++) {
      $metadata_results[] = $metadata_service->getServerMetadata();
    }

    // All results should be identical (no race conditions).
    $first_result = $metadata_results[0];
    foreach ($metadata_results as $index => $result) {
      $this->assertEquals($first_result['registration_endpoint'], $result['registration_endpoint'], "Concurrent access result $index should be consistent");
    }

    // Test concurrent client registrations.
    $registration_service = $this->container->get('simple_oauth_client_registration.service.registration');

    $client_results = [];
    for ($i = 0; $i < 3; $i++) {
      $client_metadata = [
        'client_name' => "Concurrent Test Client $i",
        'redirect_uris' => ["https://example$i.com/callback"],
      ];

      $client_results[] = $registration_service->registerClient($client_metadata);
    }

    // All clients should have unique IDs.
    $client_ids = array_column($client_results, 'client_id');
    $unique_ids = array_unique($client_ids);
    $this->assertEquals(count($client_ids), count($unique_ids), 'All client IDs should be unique');
  }

  /**
   * Test configuration changes propagation across contexts.
   */
  public function testConfigurationChangesPropagationAcrossContexts() {
    $config = $this->container->get('config.factory')->getEditable('simple_oauth_server_metadata.settings');
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');

    // Test 1: Clear registration endpoint config to test auto-detection.
    $config->clear('registration_endpoint')->save();
    $metadata_service->invalidateCache();

    $metadata = $metadata_service->getServerMetadata();
    $this->assertArrayHasKey('registration_endpoint', $metadata, 'Auto-detection should provide registration endpoint');

    // Verify HTTP endpoint reflects the same.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $http_metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertEquals($metadata['registration_endpoint'], $http_metadata['registration_endpoint'], 'HTTP endpoint should reflect auto-detected endpoint');

    // Test 2: Set explicit registration endpoint.
    $custom_endpoint = 'https://custom.example.com/oauth/register';
    $config->set('registration_endpoint', $custom_endpoint)->save();
    $metadata_service->invalidateCache();

    $metadata = $metadata_service->getServerMetadata();
    $this->assertEquals($custom_endpoint, $metadata['registration_endpoint'], 'Explicit configuration should override auto-detection');

    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $http_metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertEquals($custom_endpoint, $http_metadata['registration_endpoint'], 'HTTP endpoint should reflect explicit configuration');

    // Test 3: Restore auto-detection.
    $config->clear('registration_endpoint')->save();
    $metadata_service->invalidateCache();

    $metadata = $metadata_service->getServerMetadata();
    $this->assertArrayHasKey('registration_endpoint', $metadata, 'Auto-detection should work after clearing explicit config');
    $this->assertStringContainsString('/oauth/register', $metadata['registration_endpoint'], 'Auto-detected endpoint should be correct');
  }

  /**
   * Test integration with existing OAuth clients.
   */
  public function testIntegrationWithExistingOAuthClients() {
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

    $new_client_metadata = [
      'client_name' => 'New Dynamic Client',
      'redirect_uris' => ['https://new.example.com/callback'],
    ];

    $new_client_data = $registration_service->registerClient($new_client_metadata);
    $this->assertArrayHasKey('client_id', $new_client_data, 'New client registration should work alongside existing clients');
    $this->assertNotEquals('existing-client-id', $new_client_data['client_id'], 'New client should have different ID from existing client');

    // Test that both clients are accessible.
    $consumer_storage = $this->container->get('entity_type.manager')->getStorage('consumer');

    $existing_client = $consumer_storage->loadByProperties(['uuid' => 'existing-client-id']);
    $this->assertNotEmpty($existing_client, 'Existing client should still be accessible');

    $new_client = $consumer_storage->loadByProperties(['uuid' => $new_client_data['client_id']]);
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
      if ($this->container->has($cache_service)) {
        $cache_backend = $this->container->get($cache_service);
        if ($cache_backend instanceof CacheBackendInterface) {
          $cache_backend->deleteAll();
        }
      }
    }

    if ($this->container->has('simple_oauth_server_metadata.server_metadata')) {
      $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');
      if (method_exists($metadata_service, 'invalidateCache')) {
        $metadata_service->invalidateCache();
      }
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
