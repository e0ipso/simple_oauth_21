<?php

namespace Drupal\Tests\simple_oauth_21\Functional;

use Drupal\Core\Cache\Cache;
use Drupal\Tests\BrowserTestBase;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Client;

/**
 * Comprehensive validation tests for OAuth RFC compliance and metadata.
 *
 * This test class performs comprehensive validation and integration testing
 * to ensure OAuth metadata advertisement is working correctly across all
 * contexts and that our OAuth RFC compliance implementation is robust.
 *
 * @group simple_oauth_21
 * @group functional
 * @group oauth_validation
 */
class OAuthMetadataValidationTest extends BrowserTestBase {

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

    // Ensure clean cache state for reliable testing.
    $this->clearAllTestCaches();

    // Warm metadata cache for consistent performance.
    $this->warmMetadataCache();
  }

  /**
   * Test RFC 8414 Authorization Server Metadata compliance.
   */
  public function testRFC8414AuthorizationServerMetadataCompliance() {
    // Test metadata endpoint discovery.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');

    $metadata = Json::decode($this->getSession()->getPage()->getContent());

    // Validate RFC 8414 REQUIRED fields.
    $required_fields = [
      'issuer',
      'response_types_supported',
    ];

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

    // Validate issuer URL format.
    $this->assertStringStartsWith('http', $metadata['issuer'], 'Issuer must be a valid HTTP(S) URL');
    $this->assertStringNotContainsString('#', $metadata['issuer'], 'Issuer URL must not contain fragment');

    // Validate response_types_supported.
    $this->assertTrue(is_array($metadata['response_types_supported']), 'response_types_supported must be an array');
    $this->assertNotEmpty($metadata['response_types_supported'], 'response_types_supported must not be empty');

    // Validate endpoint URLs are absolute and well-formed.
    $endpoint_fields = [
      'authorization_endpoint',
      'token_endpoint',
      'jwks_uri',
      'registration_endpoint',
    ];

    foreach ($endpoint_fields as $field) {
      if (isset($metadata[$field])) {
        $this->assertStringStartsWith('http', $metadata[$field], "$field must be a valid HTTP(S) URL");
        $this->assertTrue(filter_var($metadata[$field], FILTER_VALIDATE_URL) !== FALSE, "$field must be a valid URL");
      }
    }

    // Validate registration endpoint is present (key requirement for this project).
    $this->assertArrayHasKey('registration_endpoint', $metadata, 'Registration endpoint must be advertised');
    $this->assertStringContainsString('/oauth/register', $metadata['registration_endpoint'], 'Registration endpoint URL must be correct');

    return $metadata;
  }

  /**
   * Test RFC 9728 Protected Resource Metadata compliance.
   */
  public function testRFC9728ProtectedResourceMetadataCompliance() {
    $this->drupalGet('/.well-known/oauth-protected-resource');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');

    $metadata = Json::decode($this->getSession()->getPage()->getContent());

    // Validate RFC 9728 structure.
    $this->assertTrue(is_array($metadata), 'Protected resource metadata must be a JSON object');
    $this->assertNotEmpty($metadata, 'Protected resource metadata must not be empty');

    // Validate resource server identification (at least one must be present).
    $resource_identifiers = ['resource', 'resource_server_name', 'name'];
    $has_identifier = FALSE;
    foreach ($resource_identifiers as $identifier) {
      if (isset($metadata[$identifier])) {
        $has_identifier = TRUE;
        break;
      }
    }
    $this->assertTrue($has_identifier, 'Resource server must have at least one identifier field');

    // Validate authorization server information.
    $auth_fields = [
      'authorization_servers',
      'bearer_methods_supported',
      'authorization_endpoint',
    ];
    $has_auth_info = FALSE;
    foreach ($auth_fields as $field) {
      if (isset($metadata[$field])) {
        $has_auth_info = TRUE;
        break;
      }
    }
    $this->assertTrue($has_auth_info, 'Resource server metadata must contain authorization information');

    return $metadata;
  }

  /**
   * Test RFC 7591 Dynamic Client Registration compliance.
   */
  public function testRFC7591DynamicClientRegistrationCompliance() {
    // Test client registration endpoint availability.
    $auth_metadata = $this->testRFC8414AuthorizationServerMetadataCompliance();
    $this->assertArrayHasKey('registration_endpoint', $auth_metadata, 'Registration endpoint must be in authorization server metadata');

    $registration_endpoint = $auth_metadata['registration_endpoint'];

    // Test valid client registration.
    $client_metadata = [
      'client_name' => 'RFC 7591 Compliance Test Client',
      'redirect_uris' => ['https://example.com/callback', 'https://app.example.com/oauth/callback'],
      'grant_types' => ['authorization_code', 'refresh_token'],
      'response_types' => ['code'],
      'scope' => 'openid profile email',
      'client_uri' => 'https://example.com',
      'logo_uri' => 'https://example.com/logo.png',
      'tos_uri' => 'https://example.com/terms',
      'policy_uri' => 'https://example.com/privacy',
      'contacts' => ['admin@example.com', 'support@example.com'],
      'software_id' => 'example-oauth-client',
      'software_version' => '1.0.0',
    ];

    $response = $this->httpClient->post($registration_endpoint, [
      RequestOptions::JSON => $client_metadata,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ]);

    $this->assertEquals(200, $response->getStatusCode(), 'Client registration should succeed');

    $response_data = Json::decode($response->getBody()->getContents());

    // Validate RFC 7591 REQUIRED response fields.
    $required_response_fields = [
      'client_id',
      'client_secret',
      'registration_access_token',
      'registration_client_uri',
    ];

    foreach ($required_response_fields as $field) {
      $this->assertArrayHasKey($field, $response_data, "RFC 7591 required response field missing: $field");
      $this->assertNotEmpty($response_data[$field], "RFC 7591 required response field empty: $field");
    }

    // Validate client_id is a string and reasonably secure.
    $this->assertTrue(is_string($response_data['client_id']), 'client_id must be a string');
    $this->assertGreaterThanOrEqual(16, strlen($response_data['client_id']), 'client_id should be at least 16 characters');

    // Validate client_secret is secure.
    $this->assertTrue(is_string($response_data['client_secret']), 'client_secret must be a string');
    $this->assertGreaterThanOrEqual(32, strlen($response_data['client_secret']), 'client_secret should be at least 32 characters');

    // Validate registration_access_token.
    $this->assertTrue(is_string($response_data['registration_access_token']), 'registration_access_token must be a string');
    $this->assertGreaterThanOrEqual(16, strlen($response_data['registration_access_token']), 'registration_access_token should be secure');

    // Validate registration_client_uri.
    $this->assertStringStartsWith('http', $response_data['registration_client_uri'], 'registration_client_uri must be a valid URL');
    $this->assertStringContainsString($response_data['client_id'], $response_data['registration_client_uri'], 'registration_client_uri must contain client_id');

    // Validate client metadata preservation.
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
      if (isset($client_metadata[$field])) {
        $this->assertArrayHasKey($field, $response_data, "Client metadata field should be preserved: $field");
        $this->assertEquals($client_metadata[$field], $response_data[$field], "Client metadata field should match input: $field");
      }
    }

    // Validate optional timestamps.
    if (isset($response_data['client_id_issued_at'])) {
      $this->assertTrue(is_numeric($response_data['client_id_issued_at']), 'client_id_issued_at must be a numeric timestamp');
      $this->assertLessThanOrEqual(time(), $response_data['client_id_issued_at'], 'client_id_issued_at cannot be in the future');
    }

    if (isset($response_data['client_secret_expires_at'])) {
      $this->assertTrue(is_numeric($response_data['client_secret_expires_at']), 'client_secret_expires_at must be numeric');
    }

    return $response_data;
  }

  /**
   * Test client management operations (GET, PUT, DELETE).
   */
  public function testClientManagementOperationsCompliance() {
    // Register a client first.
    $client_data = $this->testRFC7591DynamicClientRegistrationCompliance();
    $client_id = $client_data['client_id'];
    $access_token = $client_data['registration_access_token'];
    $client_uri = $client_data['registration_client_uri'];

    // Test GET (read) operation.
    $get_response = $this->httpClient->get($client_uri, [
      RequestOptions::HEADERS => [
        'Authorization' => "Bearer $access_token",
        'Accept' => 'application/json',
      ],
    ]);

    $this->assertEquals(200, $get_response->getStatusCode(), 'GET client metadata should succeed');
    $get_data = Json::decode($get_response->getBody()->getContents());

    // Validate retrieved data matches registered data.
    $this->assertEquals($client_id, $get_data['client_id'], 'Retrieved client_id should match');
    $this->assertEquals($client_data['client_name'], $get_data['client_name'], 'Retrieved client_name should match');

    // Test PUT (update) operation.
    $updated_metadata = [
      'client_name' => 'Updated RFC 7591 Test Client',
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
    $put_data = Json::decode($put_response->getBody()->getContents());

    // Validate updates were applied.
    $this->assertEquals('Updated RFC 7591 Test Client', $put_data['client_name'], 'Client name should be updated');
    $this->assertEquals(['https://updated.example.com/callback'], $put_data['redirect_uris'], 'Redirect URIs should be updated');

    // Test access without token (should fail).
    $unauthorized_response = $this->httpClient->get($client_uri, [
      RequestOptions::HEADERS => ['Accept' => 'application/json'],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $unauthorized_response->getStatusCode(), 'Unauthorized access should fail');

    // Test access with invalid token (should fail).
    $invalid_token_response = $this->httpClient->get($client_uri, [
      RequestOptions::HEADERS => [
        'Authorization' => 'Bearer invalid-token',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $invalid_token_response->getStatusCode(), 'Invalid token access should fail');
  }

  /**
   * Test error handling and edge cases.
   */
  public function testErrorHandlingAndEdgeCases() {
    $auth_metadata = $this->testRFC8414AuthorizationServerMetadataCompliance();
    $registration_endpoint = $auth_metadata['registration_endpoint'];

    // Test 1: Empty request body.
    $response = $this->httpClient->post($registration_endpoint, [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $response->getStatusCode(), 'Empty request should return 400');
    $error_data = Json::decode($response->getBody()->getContents());
    $this->assertEquals('invalid_client_metadata', $error_data['error'], 'Should return correct error code');

    // Test 2: Invalid JSON.
    $response = $this->httpClient->post($registration_endpoint, [
      RequestOptions::BODY => 'invalid json{',
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $response->getStatusCode(), 'Invalid JSON should return 400');
    $error_data = Json::decode($response->getBody()->getContents());
    $this->assertEquals('invalid_client_metadata', $error_data['error'], 'Should return correct error code');

    // Test 3: Invalid redirect URI.
    $invalid_metadata = [
      'client_name' => 'Test Client',
      'redirect_uris' => ['not-a-valid-url', 'also-invalid'],
    ];

    $response = $this->httpClient->post($registration_endpoint, [
      RequestOptions::JSON => $invalid_metadata,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $response->getStatusCode(), 'Invalid redirect URI should return 400');
    $error_data = Json::decode($response->getBody()->getContents());
    $this->assertEquals('invalid_client_metadata', $error_data['error'], 'Should return correct error code for invalid URI');

    // Test 4: Invalid contact email.
    $invalid_metadata = [
      'client_name' => 'Test Client',
      'redirect_uris' => ['https://example.com/callback'],
      'contacts' => ['not-an-email', 'also-not-email'],
    ];

    $response = $this->httpClient->post($registration_endpoint, [
      RequestOptions::JSON => $invalid_metadata,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $response->getStatusCode(), 'Invalid email should return 400');
    $error_data = Json::decode($response->getBody()->getContents());
    $this->assertEquals('invalid_client_metadata', $error_data['error'], 'Should return correct error code for invalid email');

    // Test 5: Missing Content-Type header.
    $response = $this->httpClient->post($registration_endpoint, [
      RequestOptions::BODY => json_encode(['client_name' => 'Test']),
      RequestOptions::HEADERS => [
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    // Should still work or return appropriate error.
    $this->assertContains($response->getStatusCode(), [200, 400], 'Should handle missing Content-Type gracefully');
  }

  /**
   * Test metadata consistency across multiple contexts.
   */
  public function testMetadataConsistencyAcrossContexts() {
    // Clear caches to ensure fresh data.
    $this->clearAllTestCaches();

    // Test 1: Get metadata through Drupal API.
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');
    $api_metadata = $metadata_service->getServerMetadata();

    // Test 2: Get metadata through HTTP endpoint.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $http_metadata = Json::decode($this->getSession()->getPage()->getContent());

    // Test 3: Compare key fields for consistency.
    $key_fields = [
      'issuer',
      'authorization_endpoint',
      'token_endpoint',
      'registration_endpoint',
      'response_types_supported',
      'grant_types_supported',
    ];

    foreach ($key_fields as $field) {
      if (isset($api_metadata[$field]) && isset($http_metadata[$field])) {
        $this->assertEquals(
          $api_metadata[$field],
          $http_metadata[$field],
          "Field '$field' should be consistent between API and HTTP responses"
        );
      }
    }

    // Test 4: Verify registration endpoint consistency.
    $this->assertArrayHasKey('registration_endpoint', $api_metadata, 'API metadata should contain registration endpoint');
    $this->assertArrayHasKey('registration_endpoint', $http_metadata, 'HTTP metadata should contain registration endpoint');
    $this->assertEquals(
      $api_metadata['registration_endpoint'],
      $http_metadata['registration_endpoint'],
      'Registration endpoint should be consistent'
    );

    // Test 5: Test after cache invalidation.
    $metadata_service->invalidateCache();
    $fresh_metadata = $metadata_service->getServerMetadata();

    $this->assertEquals(
      $api_metadata['registration_endpoint'],
      $fresh_metadata['registration_endpoint'],
      'Registration endpoint should remain consistent after cache invalidation'
    );
  }

  /**
   * Test performance and caching behavior.
   */
  public function testPerformanceAndCaching() {
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');

    // Test 1: Measure uncached metadata generation.
    $metadata_service->invalidateCache();
    $start_time = microtime(TRUE);
    $metadata1 = $metadata_service->getServerMetadata();
    $uncached_time = microtime(TRUE) - $start_time;

    // Test 2: Measure cached metadata retrieval.
    $start_time = microtime(TRUE);
    $metadata2 = $metadata_service->getServerMetadata();
    $cached_time = microtime(TRUE) - $start_time;

    // Test 3: Verify cached retrieval is faster.
    $this->assertLessThan($uncached_time, $cached_time, 'Cached metadata retrieval should be faster than uncached');

    // Test 4: Verify cache consistency.
    $this->assertEquals($metadata1, $metadata2, 'Cached and uncached metadata should be identical');

    // Test 5: Performance threshold (should complete within reasonable time).
    $this->assertLessThan(1.0, $uncached_time, 'Uncached metadata generation should complete within 1 second');
    $this->assertLessThan(0.1, $cached_time, 'Cached metadata retrieval should complete within 0.1 seconds');

    // Test 6: HTTP endpoint performance.
    $start_time = microtime(TRUE);
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $http_time = microtime(TRUE) - $start_time;

    $this->assertSession()->statusCodeEquals(200);
    $this->assertLessThan(2.0, $http_time, 'HTTP metadata endpoint should respond within 2 seconds');
  }

  /**
   * Test PKCE and native app support integration.
   */
  public function testPKCEAndNativeAppIntegration() {
    $auth_metadata = $this->testRFC8414AuthorizationServerMetadataCompliance();

    // Verify PKCE support is advertised.
    $this->assertArrayHasKey('code_challenge_methods_supported', $auth_metadata, 'PKCE support should be advertised');
    $supported_methods = $auth_metadata['code_challenge_methods_supported'];
    $this->assertContains('S256', $supported_methods, 'SHA256 PKCE method should be supported');

    // Test native app client registration.
    $native_client_metadata = [
      'client_name' => 'Native App Test Client',
      'redirect_uris' => ['com.example.app://oauth/callback'],
      'grant_types' => ['authorization_code', 'refresh_token'],
      'response_types' => ['code'],
    // Public client.
      'token_endpoint_auth_method' => 'none',
      'application_type' => 'native',
    ];

    $response = $this->httpClient->post($auth_metadata['registration_endpoint'], [
      RequestOptions::JSON => $native_client_metadata,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ]);

    $this->assertEquals(200, $response->getStatusCode(), 'Native app client registration should succeed');

    $response_data = Json::decode($response->getBody()->getContents());

    // Native app (public client) should not receive a client secret.
    $this->assertArrayNotHasKey('client_secret', $response_data, 'Native app should not receive client secret');
    $this->assertArrayHasKey('client_id', $response_data, 'Native app should receive client ID');
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

    // Clear server metadata service cache.
    if ($this->container->has('simple_oauth_server_metadata.server_metadata')) {
      $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');
      if (method_exists($metadata_service, 'invalidateCache')) {
        $metadata_service->invalidateCache();
      }
    }

    // Invalidate relevant cache tags.
    Cache::invalidateTags([
      'simple_oauth_server_metadata',
      'config:simple_oauth.settings',
      'config:simple_oauth_server_metadata.settings',
      'oauth2_grant_plugins',
      'route_match',
    ]);
  }

  /**
   * Warms the metadata cache for consistent performance.
   */
  protected function warmMetadataCache(): void {
    if ($this->container->has('simple_oauth_server_metadata.server_metadata')) {
      $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');
      if (method_exists($metadata_service, 'refreshCacheForTesting')) {
        $metadata_service->refreshCacheForTesting();
      }
      elseif (method_exists($metadata_service, 'warmCache')) {
        $metadata_service->warmCache();
      }
      else {
        $metadata_service->getServerMetadata();
      }
    }
  }

}
