<?php

namespace Drupal\Tests\simple_oauth_21\Functional;

use Drupal\Core\Cache\Cache;
use Drupal\Tests\BrowserTestBase;
use Drupal\Component\Serialization\Json;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\Group;

/**
 * Comprehensive validation tests for OAuth RFC compliance and metadata.
 *
 * This test class performs comprehensive validation and integration testing
 * to ensure OAuth metadata advertisement is working correctly across all
 * contexts and that our OAuth RFC compliance implementation is robust.
 */
#[Group('simple_oauth_21')]
#[Group('functional')]
#[Group('oauth_validation')]
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
   * Comprehensive OAuth RFC compliance and functionality test.
   *
   * This test consolidates all OAuth RFC testing for improved performance.
   * Tests RFC 8414, RFC 9728, RFC 7591, client management, error handling,
   * metadata consistency, performance, and PKCE integration.
   */
  public function testComprehensiveOauthRfcCompliance() {
    // === RFC 8414 Authorization Server Metadata Compliance ===
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');

    $auth_metadata = Json::decode($this->getSession()->getPage()->getContent());

    // Validate RFC 8414 REQUIRED fields.
    $required_fields = ['issuer', 'response_types_supported'];
    foreach ($required_fields as $field) {
      $this->assertArrayHasKey($field, $auth_metadata, "RFC 8414 required field missing: $field");
      $this->assertNotEmpty($auth_metadata[$field], "RFC 8414 required field empty: $field");
    }

    // Validate RFC 8414 RECOMMENDED fields.
    $recommended_fields = [
      'authorization_endpoint',
      'token_endpoint',
      'grant_types_supported',
      'scopes_supported',
    ];
    foreach ($recommended_fields as $field) {
      $this->assertArrayHasKey($field, $auth_metadata, "RFC 8414 recommended field missing: $field");
    }

    // Validate issuer URL format.
    $this->assertStringStartsWith('http', $auth_metadata['issuer'], 'Issuer must be a valid HTTP(S) URL');
    $this->assertStringNotContainsString('#', $auth_metadata['issuer'], 'Issuer URL must not contain fragment');

    // Validate endpoint URLs are absolute and well-formed.
    $endpoint_fields = [
      'authorization_endpoint',
      'token_endpoint',
      'jwks_uri',
      'registration_endpoint',
    ];
    foreach ($endpoint_fields as $field) {
      if (isset($auth_metadata[$field])) {
        $this->assertStringStartsWith('http', $auth_metadata[$field], "$field must be a valid HTTP(S) URL");
        $this->assertTrue(filter_var($auth_metadata[$field], FILTER_VALIDATE_URL) !== FALSE, "$field must be a valid URL");
      }
    }

    // Validate registration endpoint (key requirement)
    $this->assertArrayHasKey('registration_endpoint', $auth_metadata, 'Registration endpoint must be advertised');
    $this->assertStringContainsString('/oauth/register', $auth_metadata['registration_endpoint'], 'Registration endpoint URL must be correct');
    $registration_endpoint = $auth_metadata['registration_endpoint'];

    // === RFC 9728 Protected Resource Metadata Compliance ===
    $this->drupalGet('/.well-known/oauth-protected-resource');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');

    $resource_metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertTrue(is_array($resource_metadata), 'Protected resource metadata must be a JSON object');
    $this->assertNotEmpty($resource_metadata, 'Protected resource metadata must not be empty');

    // Validate resource server identification.
    $resource_identifiers = ['resource', 'resource_server_name', 'name'];
    $has_identifier = FALSE;
    foreach ($resource_identifiers as $identifier) {
      if (isset($resource_metadata[$identifier])) {
        $has_identifier = TRUE;
        break;
      }
    }
    $this->assertTrue($has_identifier, 'Resource server must have at least one identifier field');

    // === RFC 7591 Dynamic Client Registration and Management ===
    // Test valid client registration
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

    $response = $this->httpClient->post($registration_endpoint, [
      RequestOptions::JSON => $client_metadata,
      RequestOptions::HEADERS => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
    ]);
    $this->assertEquals(200, $response->getStatusCode(), 'Client registration should succeed');
    $client_data = Json::decode($response->getBody()->getContents());

    // Validate RFC 7591 REQUIRED response fields.
    $required_response_fields = ['client_id', 'client_secret', 'registration_access_token', 'registration_client_uri'];
    foreach ($required_response_fields as $field) {
      $this->assertArrayHasKey($field, $client_data, "RFC 7591 required response field missing: $field");
      $this->assertNotEmpty($client_data[$field], "RFC 7591 required response field empty: $field");
    }

    // Validate security requirements.
    $this->assertGreaterThanOrEqual(16, strlen($client_data['client_id']), 'client_id should be at least 16 characters');
    $this->assertGreaterThanOrEqual(32, strlen($client_data['client_secret']), 'client_secret should be at least 32 characters');
    $this->assertGreaterThanOrEqual(16, strlen($client_data['registration_access_token']), 'registration_access_token should be secure');
    $this->assertStringStartsWith('http', $client_data['registration_client_uri'], 'registration_client_uri must be a valid URL');
    $this->assertStringContainsString($client_data['client_id'], $client_data['registration_client_uri'], 'registration_client_uri must contain client_id');

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

    // === Client Management Operations ===
    $client_id = $client_data['client_id'];
    $access_token = $client_data['registration_access_token'];
    $client_uri = $client_data['registration_client_uri'];

    // Test GET (read) operation.
    $get_response = $this->httpClient->get($client_uri, [
      RequestOptions::HEADERS => ['Authorization' => "Bearer $access_token", 'Accept' => 'application/json'],
    ]);
    $this->assertEquals(200, $get_response->getStatusCode(), 'GET client metadata should succeed');
    $get_data = Json::decode($get_response->getBody()->getContents());
    $this->assertEquals($client_id, $get_data['client_id'], 'Retrieved client_id should match');
    $this->assertEquals($client_data['client_name'], $get_data['client_name'], 'Retrieved client_name should match');

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
    $put_data = Json::decode($put_response->getBody()->getContents());
    $this->assertEquals('Updated Comprehensive Test Client', $put_data['client_name'], 'Client name should be updated');
    $this->assertEquals(['https://updated.example.com/callback'], $put_data['redirect_uris'], 'Redirect URIs should be updated');

    // === Error Handling and Edge Cases ===
    // Test empty request body
    $error_response = $this->httpClient->post($registration_endpoint, [
      RequestOptions::HEADERS => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);
    $this->assertEquals(400, $error_response->getStatusCode(), 'Empty request should return 400');
    $error_data = Json::decode($error_response->getBody()->getContents());
    $this->assertEquals('invalid_client_metadata', $error_data['error'], 'Should return correct error code');

    // Test invalid redirect URI.
    $invalid_metadata = ['client_name' => 'Invalid Test Client', 'redirect_uris' => ['not-a-valid-url']];
    $invalid_response = $this->httpClient->post($registration_endpoint, [
      RequestOptions::JSON => $invalid_metadata,
      RequestOptions::HEADERS => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);
    $this->assertEquals(400, $invalid_response->getStatusCode(), 'Invalid redirect URI should return 400');
    $invalid_error = Json::decode($invalid_response->getBody()->getContents());
    $this->assertEquals('invalid_client_metadata', $invalid_error['error'], 'Should return correct error code for invalid URI');

    // Test unauthorized access.
    $unauthorized_response = $this->httpClient->get($client_uri, [
      RequestOptions::HEADERS => ['Accept' => 'application/json'],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);
    $this->assertEquals(400, $unauthorized_response->getStatusCode(), 'Unauthorized access should fail');

    // === Metadata Consistency and Performance ===
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');
    $api_metadata = $metadata_service->getServerMetadata();

    // Test API and HTTP endpoint consistency.
    $key_fields = [
      'issuer',
      'authorization_endpoint',
      'token_endpoint',
      'registration_endpoint',
    ];
    foreach ($key_fields as $field) {
      if (isset($api_metadata[$field]) && isset($auth_metadata[$field])) {
        $this->assertEquals($api_metadata[$field], $auth_metadata[$field], "Field '$field' should be consistent between API and HTTP responses");
      }
    }

    // Test performance.
    $start_time = microtime(TRUE);
    $fresh_metadata = $metadata_service->getServerMetadata();
    $generation_time = microtime(TRUE) - $start_time;
    $this->assertLessThan(1.0, $generation_time, 'Metadata generation should complete within 1 second');
    $this->assertArrayHasKey('registration_endpoint', $fresh_metadata, 'Fresh metadata should contain registration endpoint');

    // === PKCE and Native App Integration ===
    // Verify PKCE support is advertised
    $this->assertArrayHasKey('code_challenge_methods_supported', $auth_metadata, 'PKCE support should be advertised');
    $supported_methods = $auth_metadata['code_challenge_methods_supported'];
    $this->assertContains('S256', $supported_methods, 'SHA256 PKCE method should be supported');

    // Test native app client registration.
    $native_client_metadata = [
      'client_name' => 'Native App Test Client',
      'redirect_uris' => ['com.example.app://oauth/callback'],
      'grant_types' => ['authorization_code', 'refresh_token'],
      'response_types' => ['code'],
      'token_endpoint_auth_method' => 'none',
      'application_type' => 'native',
    ];
    $native_response = $this->httpClient->post($registration_endpoint, [
      RequestOptions::JSON => $native_client_metadata,
      RequestOptions::HEADERS => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
    ]);
    $this->assertEquals(200, $native_response->getStatusCode(), 'Native app client registration should succeed');
    $native_data = Json::decode($native_response->getBody()->getContents());
    $this->assertArrayNotHasKey('client_secret', $native_data, 'Native app should not receive client secret');
    $this->assertArrayHasKey('client_id', $native_data, 'Native app should receive client ID');
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

    // Clear server metadata service cache.
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
  }

}
