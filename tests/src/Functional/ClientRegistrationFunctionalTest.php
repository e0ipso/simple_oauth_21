<?php

namespace Drupal\Tests\simple_oauth_21\Functional;

use Drupal\Core\Cache\Cache;
use Drupal\Tests\BrowserTestBase;
use Drupal\Component\Serialization\Json;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Client;

/**
 * Functional tests for RFC 7591 OAuth Dynamic Client Registration.
 *
 * @group simple_oauth_21
 * @group functional
 */
class ClientRegistrationFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'options',
    'consumers',
    'simple_oauth',
    'simple_oauth_test',
    'simple_oauth_21',
    'simple_oauth_client_registration',
    'simple_oauth_server_metadata',
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

    // Drupal 11 workaround: Ensure routes are properly registered
    // The functional test environment needs explicit cache clearing.
    // @todo Investigate why D11 requires this additional cache clear
    if (version_compare(\Drupal::VERSION, '11.0', '>=')) {
      // Rebuild routes to ensure OAuth endpoints are available.
      $this->container->get('router.builder')->rebuild();
    }

    // Perform comprehensive cache clearing for test isolation.
    $this->clearAllTestCaches();

    // Test and demonstrate the auto-detection mechanism.
    $config = $this->container->get('config.factory')->getEditable('simple_oauth_server_metadata.settings');

    // First, clear registration_endpoint to test auto-detection.
    $config->clear('registration_endpoint');
    $config->save();

    // Test that auto-detection works correctly.
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');
    $metadata_service->invalidateCache();
    $test_metadata = $metadata_service->getServerMetadata();

    // Verify auto-detection mechanism works - this is the core fix.
    $this->assertArrayHasKey('registration_endpoint', $test_metadata,
      'Auto-detection should provide registration endpoint in test environments');
    $this->assertStringContainsString('/oauth/register', $test_metadata['registration_endpoint'], 'Auto-detected endpoint should be correct');

    // Now configure the endpoint explicitly for HTTP endpoint testing
    // This avoids the test environment HTTP cache issues while proving
    // auto-detection works.
    $config->set('registration_endpoint', $test_metadata['registration_endpoint']);
    $config->save();

    // Clear caches again and warm the metadata cache for consistent test
    // performance.
    $this->clearAllTestCaches();
    $this->warmMetadataCache();

    // Rebuild container to ensure service definitions are fresh.
    $this->rebuildContainer();
  }

  /**
   * Test RFC 7591 client registration workflow.
   *
   * @return array<string, mixed>
   *   The client registration response data.
   */
  public function testClientRegistrationWorkflow(): array {
    // Prepare valid RFC 7591 client registration request.
    $client_metadata = [
      'client_name' => 'Test OAuth Client',
      'redirect_uris' => ['https://example.com/callback'],
      'grant_types' => ['authorization_code', 'refresh_token'],
      'response_types' => ['code'],
      'scope' => 'openid profile',
      'client_uri' => 'https://example.com',
      'logo_uri' => 'https://example.com/logo.png',
      'contacts' => ['admin@example.com'],
    ];

    // Make POST request to registration endpoint.
    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => $client_metadata,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ]);

    // Validate RFC 7591 response.
    $this->assertEquals(200, $response->getStatusCode(), 'Client registration succeeded');

    $response_data = Json::decode($response->getBody()->getContents());

    // Check RFC 7591 required response fields.
    $this->assertNotEmpty($response_data['client_id'], 'Client ID is generated');
    $this->assertNotEmpty($response_data['client_secret'], 'Client secret is generated');
    $this->assertNotEmpty($response_data['registration_access_token'], 'Registration access token is generated');
    $this->assertStringContainsString('/oauth/register/', $response_data['registration_client_uri'], 'Registration client URI is provided');

    // Check metadata fields are preserved.
    $this->assertEquals('Test OAuth Client', $response_data['client_name'], 'Client name matches');
    $this->assertEquals(['https://example.com/callback'], $response_data['redirect_uris'], 'Redirect URIs match');
    $this->assertEquals(['authorization_code', 'refresh_token'], $response_data['grant_types'], 'Grant types match');
    $this->assertEquals('https://example.com', $response_data['client_uri'], 'Client URI matches');
    $this->assertEquals(['admin@example.com'], $response_data['contacts'], 'Contacts match');

    return $response_data;
  }

  /**
   * Clears all test-relevant caches for proper isolation.
   *
   * This method ensures that each test starts with a clean cache state,
   * preventing interference between test methods and ensuring reliable,
   * deterministic test behavior.
   */
  protected function clearAllTestCaches(): void {
    // Clear all standard cache backends that could affect OAuth functionality.
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

    // Clear server metadata service cache specifically.
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');
    $metadata_service->invalidateCache();

    // Invalidate cache tags that are specific to OAuth server metadata.
    Cache::invalidateTags([
      'simple_oauth_server_metadata',
      'config:simple_oauth.settings',
      'config:simple_oauth_server_metadata.settings',
      'oauth2_grant_plugins',
      'route_match',
    ]);
  }

  /**
   * Warms the metadata cache for consistent test performance.
   *
   * Pre-generates and caches metadata to ensure HTTP requests in tests
   * have predictable performance and don't encounter cache misses that
   * could cause timing-related test failures.
   */
  protected function warmMetadataCache(): void {
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');
    // Use test-specific cache refresh method for better reliability.
    $metadata_service->refreshCacheForTesting();
  }

  /**
   * Ensures OAuth routes are properly discovered and available.
   */
  protected function ensureOauthRoutesAvailable(): void {
    // First ensure the simple_oauth module is enabled.
    $module_handler = $this->container->get('module_handler');
    if (!$module_handler->moduleExists('simple_oauth')) {
      $this->fail('simple_oauth module is not enabled - check test module dependencies');
    }

    // Force route rebuild for D11+ environments.
    if (version_compare(\Drupal::VERSION, '11.0', '>=')) {
      $this->container->get('router.builder')->rebuild();
      $this->clearAllTestCaches();
    }

    // Verify routes are actually available.
    $route_provider = $this->container->get('router.route_provider');
    $retry_count = 0;
    $max_retries = 5;

    while ($retry_count < $max_retries) {
      try {
        // Test that the route exists.
        $route_provider->getRouteByName('simple_oauth.server_metadata');
        break;
      }
      catch (\Exception $e) {
        $retry_count++;
        if ($retry_count >= $max_retries) {
          // Get more debugging info.
          $all_routes = [];
          foreach ($route_provider->getAllRoutes() as $name => $route) {
            if (strpos($name, 'simple_oauth') === 0) {
              $all_routes[] = $name;
            }
          }
          $this->fail('OAuth routes not available after ' . $max_retries . ' rebuild attempts. Error: ' . $e->getMessage() . '. Available simple_oauth routes: ' . implode(', ', $all_routes));
        }
        // Force another rebuild and clear all relevant caches.
        $this->container->get('router.builder')->rebuild();
        $this->clearAllTestCaches();
      }
    }
  }

  /**
   * Ensures cache isolation before critical test operations.
   *
   * This method can be called at the beginning of test methods that
   * require guaranteed fresh cache state, especially for HTTP-based
   * operations that depend on metadata consistency.
   */
  protected function ensureCacheIsolation(): void {
    // Clear HTTP response caches that might interfere with fresh requests.
    $this->container->get('cache.page')->deleteAll();
    $this->container->get('cache.dynamic_page_cache')->deleteAll();

    // Ensure metadata service cache is fresh.
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');
    $metadata_service->invalidateCache();

    // Additional cache tag invalidation for HTTP responses.
    Cache::invalidateTags([
      'simple_oauth_server_metadata',
      'http_response',
      'rendered',
    ]);
  }

  /**
   * Test client management operations using registration access token.
   */
  public function testClientManagementOperations(): void {
    // First register a client.
    $registration_data = $this->testClientRegistrationWorkflow();
    $client_id = $registration_data['client_id'];
    $access_token = $registration_data['registration_access_token'];

    // Test GET client metadata.
    $get_response = $this->httpClient->get($this->buildUrl("/oauth/register/{$client_id}"), [
      RequestOptions::HEADERS => [
        'Authorization' => "Bearer {$access_token}",
        'Accept' => 'application/json',
      ],
    ]);

    $this->assertEquals(200, $get_response->getStatusCode(), 'GET client metadata succeeded');
    $get_data = Json::decode($get_response->getBody()->getContents());
    $this->assertEquals('Test OAuth Client', $get_data['client_name'], 'Retrieved client name matches');

    // Test PUT (update) client metadata.
    $updated_metadata = [
      'client_name' => 'Updated OAuth Client',
      'redirect_uris' => ['https://newexample.com/callback'],
      'client_uri' => 'https://newexample.com',
    ];

    $put_response = $this->httpClient->put($this->buildUrl("/oauth/register/{$client_id}"), [
      RequestOptions::JSON => $updated_metadata,
      RequestOptions::HEADERS => [
        'Authorization' => "Bearer {$access_token}",
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ]);

    $this->assertEquals(200, $put_response->getStatusCode(), 'PUT client metadata succeeded');
    $put_data = Json::decode($put_response->getBody()->getContents());
    $this->assertEquals('Updated OAuth Client', $put_data['client_name'], 'Client name was updated');
    $this->assertEquals(['https://newexample.com/callback'], $put_data['redirect_uris'], 'Redirect URIs were updated');
  }

  /**
   * Test registration error conditions.
   */
  public function testRegistrationErrorConditions(): void {
    // Test empty request body.
    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $response->getStatusCode(), 'Empty request returns 400');
    $error_data = Json::decode($response->getBody()->getContents());
    $this->assertEquals('invalid_client_metadata', $error_data['error'], 'Correct error code for empty request');

    // Test invalid JSON.
    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::BODY => 'invalid json{',
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $response->getStatusCode(), 'Invalid JSON returns 400');
    $error_data = Json::decode($response->getBody()->getContents());
    $this->assertEquals('invalid_client_metadata', $error_data['error'], 'Correct error code for invalid JSON');

    // Test invalid redirect URI.
    $invalid_metadata = [
      'client_name' => 'Invalid Client',
      'redirect_uris' => ['not-a-url'],
    ];

    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => $invalid_metadata,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $response->getStatusCode(), 'Invalid redirect URI returns 400');
    $error_data = Json::decode($response->getBody()->getContents());
    $this->assertEquals('invalid_client_metadata', $error_data['error'], 'Correct error code for invalid redirect URI');
  }

  /**
   * Test metadata endpoints functionality.
   */
  public function testMetadataEndpoints(): void {
    // Ensure routes are available before testing.
    $this->ensureOauthRoutesAvailable();

    // Ensure cache isolation for HTTP-based metadata endpoint testing.
    $this->ensureCacheIsolation();

    // Test authorization server metadata (RFC 8414)
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $auth_metadata = Json::decode($this->getSession()->getPage()->getContent());

    // Validate key RFC 8414 metadata fields.
    $required_fields = [
      'issuer',
      'authorization_endpoint',
      'token_endpoint',
      'jwks_uri',
      'scopes_supported',
      'response_types_supported',
    ];

    foreach ($required_fields as $field) {
      $this->assertArrayHasKey($field, $auth_metadata, "Authorization server metadata contains required field: $field");
    }

    // Validate registration endpoint is included.
    $this->assertArrayHasKey('registration_endpoint', $auth_metadata, 'Registration endpoint is advertised in metadata');
    $this->assertNotEmpty($auth_metadata['registration_endpoint'], 'Registration endpoint has value');
    $this->assertStringContainsString('/oauth/register', $auth_metadata['registration_endpoint'], 'Registration endpoint URL is correct');

    // Test protected resource metadata (RFC 9728)
    $this->drupalGet('/.well-known/oauth-protected-resource');
    $this->assertSession()->statusCodeEquals(200);
    $resource_metadata = Json::decode($this->getSession()->getPage()->getContent());

    // Validate RFC 9728 resource server metadata.
    $this->assertTrue(is_array($resource_metadata), 'Resource metadata is an array');
    $this->assertNotEmpty($resource_metadata, 'Resource metadata is not empty');

    // Check for required resource server identification.
    $has_resource_identifier = isset($resource_metadata['resource']) ||
                               isset($resource_metadata['resource_server_name']) ||
                               isset($resource_metadata['name']);
    $this->assertTrue($has_resource_identifier, 'Resource server metadata contains resource identifier');

    // Check for authorization server information.
    $has_auth_info = isset($resource_metadata['authorization_servers']) ||
                     isset($resource_metadata['bearer_methods_supported']) ||
                     isset($resource_metadata['authorization_endpoint']);
    $this->assertTrue($has_auth_info, 'Resource server metadata contains authorization information');
  }

  /**
   * Test registration access token authentication.
   */
  public function testRegistrationTokenAuthentication(): void {
    // Register a client first.
    $registration_data = $this->testClientRegistrationWorkflow();
    $client_id = $registration_data['client_id'];

    // Test access without token.
    $response = $this->httpClient->get($this->buildUrl("/oauth/register/{$client_id}"), [
      RequestOptions::HEADERS => ['Accept' => 'application/json'],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $response->getStatusCode(), 'Request without token returns 400');

    // Test access with invalid token.
    $response = $this->httpClient->get($this->buildUrl("/oauth/register/{$client_id}"), [
      RequestOptions::HEADERS => [
        'Authorization' => 'Bearer invalid-token',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $response->getStatusCode(), 'Request with invalid token returns 400');

    // Test access with valid token (should work)
    $access_token = $registration_data['registration_access_token'];
    $response = $this->httpClient->get($this->buildUrl("/oauth/register/{$client_id}"), [
      RequestOptions::HEADERS => [
        'Authorization' => "Bearer {$access_token}",
        'Accept' => 'application/json',
      ],
    ]);

    $this->assertEquals(200, $response->getStatusCode(), 'Request with valid token succeeds');
  }

  /**
   * Test cache isolation and consistency across test operations.
   *
   * This test verifies that cache handling improvements ensure:
   * - Fresh metadata generation after configuration changes
   * - Cache isolation between test operations
   * - Consistent behavior across multiple requests.
   */
  public function testCacheIsolationAndConsistency(): void {
    // Get initial metadata service.
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');

    // Get initial metadata to establish baseline.
    $initial_metadata = $metadata_service->getServerMetadata();
    $this->assertArrayHasKey('registration_endpoint', $initial_metadata, 'Initial metadata contains registration endpoint');

    // Clear cache and verify fresh generation.
    $this->clearAllTestCaches();
    $fresh_metadata = $metadata_service->getServerMetadata();
    $this->assertEquals($initial_metadata['registration_endpoint'], $fresh_metadata['registration_endpoint'],
      'Metadata consistency maintained after cache clearing');

    // Test HTTP endpoint consistency (verifies HTTP cache isolation)
    $this->ensureOauthRoutesAvailable();
    $this->ensureCacheIsolation();

    // Make first HTTP request to metadata endpoint.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $first_response = Json::decode($this->getSession()->getPage()->getContent());

    // Ensure cache isolation and make second request.
    $this->ensureCacheIsolation();
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $second_response = Json::decode($this->getSession()->getPage()->getContent());

    // Verify consistency between requests.
    $this->assertEquals($first_response['registration_endpoint'], $second_response['registration_endpoint'],
      'Registration endpoint consistent between HTTP requests');
    $this->assertEquals($first_response['issuer'], $second_response['issuer'],
      'Issuer consistent between HTTP requests');

    // Test service-level cache refresh.
    $metadata_service->refreshCacheForTesting();
    $refreshed_metadata = $metadata_service->getServerMetadata();
    $this->assertEquals($initial_metadata['registration_endpoint'], $refreshed_metadata['registration_endpoint'],
      'Metadata consistency maintained after test cache refresh');

    // Final verification: ensure changes don't leak between operations.
    $final_metadata = $metadata_service->getServerMetadata();
    $this->assertEquals($initial_metadata['registration_endpoint'], $final_metadata['registration_endpoint'],
      'No cache state leakage detected between test operations');
  }

}
