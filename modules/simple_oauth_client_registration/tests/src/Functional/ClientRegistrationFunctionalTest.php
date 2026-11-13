<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_client_registration\Functional;

use Drupal\consumers\Entity\ConsumerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\simple_oauth\Plugin\Validation\Constraint\Oauth2PrivilegedConsumerField;
use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use Psr\Log\LoggerInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\simple_oauth\Functional\SimpleOauthTestTrait;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Functional tests for RFC 7591 OAuth Dynamic Client Registration.
 *
 * Tests the complete client registration functionality including:
 * - Client registration workflow
 * - Client management operations (GET, PUT)
 * - Registration error conditions
 * - Metadata endpoints discovery
 * - Registration token authentication
 * - Cache isolation and consistency.
 */
#[Group('simple_oauth_client_registration')]
#[Group('functional')]
#[RunTestsInSeparateProcesses]
final class ClientRegistrationFunctionalTest extends BrowserTestBase {

  use SimpleOauthTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_client_registration',
    'simple_oauth_registration_test',
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
  protected ClientInterface $httpClient;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set up HTTP client with base URI for test environment.
    // Must use http_client_factory from container, not new Client().
    $this->httpClient = $this->container->get('http_client_factory')
      ->fromOptions(['base_uri' => $this->baseUrl]);

    // Install entity base field definitions that were added by the module.
    // BrowserTestBase doesn't automatically install base fields from
    // hook_entity_base_field_info().
    $entity_definition_update_manager = \Drupal::entityDefinitionUpdateManager();
    $entity_type_manager = \Drupal::entityTypeManager();
    $consumer_entity_type = $entity_type_manager->getDefinition('consumer');
    $base_fields = simple_oauth_client_registration_entity_base_field_info($consumer_entity_type);

    foreach ($base_fields as $field_name => $storage_definition) {
      if (!$entity_definition_update_manager->getFieldStorageDefinition($field_name, 'consumer')) {
        $entity_definition_update_manager->installFieldStorageDefinition(
          $field_name,
          'consumer',
          'simple_oauth_client_registration',
          $storage_definition
        );
      }
    }

    // Perform comprehensive cache clearing for test isolation.
    $this->clearAllTestCaches();

    // Ensure the container is rebuilt to pick up route changes.
    $this->rebuildContainer();

    // Test and demonstrate the auto-detection mechanism.
    $config = $this->container->get('config.factory')
      ->getEditable('simple_oauth_server_metadata.settings');

    // First, clear registration_endpoint to test auto-detection.
    $config->clear('registration_endpoint');
    $config->save();

    // Test that auto-detection works correctly.
    $metadata_service = $this->container->get('simple_oauth_server_metadata.server_metadata');
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
   * Comprehensive RFC 7591 Dynamic Client Registration test.
   *
   * Tests the complete client registration functionality including:
   * - Client registration workflow (RFC 7591)
   * - Client management operations (GET, PUT)
   * - Registration error conditions
   * - Metadata endpoints discovery (RFC 8414, RFC 9728)
   * - Registration token authentication
   * - Cache isolation and consistency.
   *
   * All scenarios execute sequentially using a shared Drupal instance
   * for optimal performance.
   */
  public function testComprehensiveClientRegistrationFunctionality(): void {
    // Core workflow tests.
    $registration_data = $this->helperClientRegistrationWorkflow();
    $this->helperClientManagementOperations($registration_data);

    // Error handling tests.
    $this->helperRegistrationErrorConditions();

    // Metadata and discovery tests.
    $this->helperMetadataEndpoints();

    // Authentication tests.
    $this->helperRegistrationTokenAuthentication($registration_data);

    // Cache consistency tests.
    $this->helperCacheIsolationAndConsistency();

    // Final assertion to confirm all test scenarios completed successfully.
    // @phpstan-ignore method.alreadyNarrowedType
    $this->assertTrue(TRUE, 'All RFC 7591 client registration test scenarios completed successfully');
  }

  /**
   * Helper: Tests RFC 7591 client registration workflow.
   *
   * Validates the complete client registration process including:
   * - POST request to registration endpoint
   * - RFC 7591 response field validation
   * - Client metadata preservation.
   *
   * @return array<string, mixed>
   *   The client registration response data.
   */
  protected function helperClientRegistrationWorkflow(): array {
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
    try {
      $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
        RequestOptions::JSON => $client_metadata,
        RequestOptions::HEADERS => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
        ],
        RequestOptions::HTTP_ERRORS => TRUE,
      ]);
    }
    catch (\Exception $e) {
      // On error, try to get more details.
      $this->fail('Registration request failed: ' . $e->getMessage() . "\nURL: " . $this->buildUrl('/oauth/register'));
    }

    // Validate RFC 7591 response.
    $this->assertEquals(201, $response->getStatusCode(), 'Client registration succeeded');

    // Rewind stream before reading to ensure we get the full content.
    $response->getBody()->rewind();
    $response_data = Json::decode($response->getBody()->getContents());

    // Check RFC 7591 required response fields.
    $this->assertNotEmpty($response_data['client_id'], 'Client ID is generated');
    $this->assertNotEmpty($response_data['client_secret'], 'Client secret is generated');
    $this->assertNotEmpty($response_data['registration_access_token'], 'Registration access token is generated');
    $this->assertStringContainsString('/oauth/register/', $response_data['registration_client_uri'], 'Registration client URI is provided');

    // Check metadata fields are preserved.
    $this->assertEquals('Test OAuth Client', $response_data['client_name'], 'Client name matches');
    $this->assertEquals(['https://example.com/callback'], $response_data['redirect_uris'], 'Redirect URIs match');
    $this->assertEquals([
      'authorization_code',
      'refresh_token',
    ], $response_data['grant_types'], 'Grant types match');
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
  protected function warmMetadataCache(): void {}

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
    // Additional cache tag invalidation for HTTP responses.
    Cache::invalidateTags([
      'simple_oauth_server_metadata',
      'http_response',
      'rendered',
    ]);
  }

  /**
   * Helper: Tests client management operations using registration access token.
   *
   * Validates RFC 7591 client management capabilities including:
   * - GET client metadata retrieval
   * - PUT client metadata updates
   * - Authorization using registration access token.
   *
   * @param array<string, mixed> $registration_data
   *   The client registration response data containing client_id and
   *   registration_access_token.
   */
  protected function helperClientManagementOperations(array $registration_data): void {
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
    $get_response->getBody()->rewind();
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
    $put_response->getBody()->rewind();
    $put_data = Json::decode($put_response->getBody()->getContents());
    $this->assertEquals('Updated OAuth Client', $put_data['client_name'], 'Client name was updated');
    $this->assertEquals(['https://newexample.com/callback'], $put_data['redirect_uris'], 'Redirect URIs were updated');
  }

  /**
   * Helper: Tests registration error conditions.
   *
   * Validates RFC 7591 error handling including:
   * - Empty request body
   * - Invalid JSON format
   * - Invalid redirect URI validation.
   */
  protected function helperRegistrationErrorConditions(): void {
    // Test empty request body.
    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $response->getStatusCode(), 'Empty request returns 400');
    $response->getBody()->rewind();
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
    $response->getBody()->rewind();
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
    $response->getBody()->rewind();
    $error_data = Json::decode($response->getBody()->getContents());
    $this->assertEquals('invalid_client_metadata', $error_data['error'], 'Correct error code for invalid redirect URI');
  }

  /**
   * Helper: Tests metadata endpoints functionality.
   *
   * Validates RFC 8414 and RFC 9728 metadata discovery including:
   * - Authorization server metadata endpoint
   * - Required metadata fields validation
   * - Registration endpoint discovery
   * - Protected resource metadata endpoint
   * - Resource server identification.
   */
  protected function helperMetadataEndpoints(): void {
    // Test authorization server metadata (RFC 8414)
    $response = $this->httpClient->get($this->buildUrl('/.well-known/oauth-authorization-server'));
    $this->assertEquals(200, $response->getStatusCode(), 'Authorization server metadata endpoint returns 200');
    $response->getBody()->rewind();
    $auth_metadata = Json::decode($response->getBody()->getContents());

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
    $resource_response = $this->httpClient->get($this->buildUrl('/.well-known/oauth-protected-resource'));
    $this->assertEquals(200, $resource_response->getStatusCode(), 'Protected resource metadata endpoint returns 200');
    $resource_response->getBody()->rewind();
    $resource_metadata = Json::decode($resource_response->getBody()
      ->getContents());

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
   * Helper: Tests registration access token authentication.
   *
   * Validates RFC 7591 registration access token security including:
   * - Access without token (should fail)
   * - Access with invalid token (should fail)
   * - Access with valid token (should succeed).
   *
   * @param array<string, mixed> $registration_data
   *   The client registration response data containing client_id and
   *   registration_access_token.
   */
  protected function helperRegistrationTokenAuthentication(array $registration_data): void {
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
   * Helper: Tests cache isolation and consistency across test operations.
   *
   * Verifies that cache handling improvements ensure:
   * - Fresh metadata generation after configuration changes
   * - Cache isolation between test operations
   * - Consistent behavior across multiple requests
   * - No cache state leakage between operations.
   */
  protected function helperCacheIsolationAndConsistency(): void {
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
    $this->ensureCacheIsolation();

    // Make first HTTP request to metadata endpoint.
    $first_http_response = $this->httpClient->get($this->buildUrl('/.well-known/oauth-authorization-server'));
    $this->assertEquals(200, $first_http_response->getStatusCode(), 'First metadata request returns 200');
    $first_http_response->getBody()->rewind();
    $first_response = Json::decode($first_http_response->getBody()
      ->getContents());

    // Ensure cache isolation and make second request.
    $this->ensureCacheIsolation();
    $second_http_response = $this->httpClient->get($this->buildUrl('/.well-known/oauth-authorization-server'));
    $this->assertEquals(200, $second_http_response->getStatusCode(), 'Second metadata request returns 200');
    $second_http_response->getBody()->rewind();
    $second_response = Json::decode($second_http_response->getBody()
      ->getContents());

    // Verify consistency between requests.
    $this->assertEquals($first_response['registration_endpoint'], $second_response['registration_endpoint'],
      'Registration endpoint consistent between HTTP requests');
    $this->assertEquals($first_response['issuer'], $second_response['issuer'],
      'Issuer consistent between HTTP requests');

    // Test service-level cache refresh.
    $refreshed_metadata = $metadata_service->getServerMetadata();
    $this->assertEquals($initial_metadata['registration_endpoint'], $refreshed_metadata['registration_endpoint'],
      'Metadata consistency maintained after test cache refresh');

    // Final verification: ensure changes don't leak between operations.
    $final_metadata = $metadata_service->getServerMetadata();
    $this->assertEquals($initial_metadata['registration_endpoint'], $final_metadata['registration_endpoint'],
      'No cache state leakage detected between test operations');
  }

  /**
   * Rejects fragments anywhere in redirect lists without persisting changes.
   */
  public function testRedirectUriFragmentsAreAtomic(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $redirects = [
      'https://example.com/callback/path?state=one%23two',
      'com.example.app://oauth/callback?source=registration',
      'https://example.org/another/path?mode=login',
    ];
    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => [
        'client_name' => 'Fragment-free redirects',
        'application_type' => 'native',
        'redirect_uris' => $redirects,
      ],
    ]);
    $this->assertSame(201, $response->getStatusCode());
    $registration = Json::decode((string) $response->getBody());
    $this->assertSame($redirects, $registration['redirect_uris']);
    $management_uri = $this->buildUrl('/oauth/register/' . $registration['client_id']);
    $headers = ['Authorization' => 'Bearer ' . $registration['registration_access_token']];
    $response = $this->httpClient->get($management_uri, [RequestOptions::HEADERS => $headers]);
    $original_metadata = Json::decode((string) $response->getBody());
    $storage->resetCache();
    $count = count($storage->loadMultiple());

    foreach (array_keys($redirects) as $position) {
      foreach (['#fragment', '#'] as $fragment) {
        $invalid_redirects = $redirects;
        $invalid_redirects[$position] .= $fragment;
        foreach (['POST', 'PUT'] as $method) {
          $response = $this->httpClient->request($method, $method === 'POST' ? $this->buildUrl('/oauth/register') : $management_uri, [
            RequestOptions::JSON => [
              'client_name' => 'Rejected fragment update',
              'application_type' => 'native',
              'redirect_uris' => $invalid_redirects,
            ],
            RequestOptions::HEADERS => $method === 'PUT' ? $headers : [],
            RequestOptions::HTTP_ERRORS => FALSE,
          ]);
          $this->assertSame(400, $response->getStatusCode(), $method . ' rejects a fragment at list position ' . $position);
          $this->assertSame('invalid_client_metadata', Json::decode((string) $response->getBody())['error']);
          $storage->resetCache();
          $this->assertCount($count, $storage->loadMultiple(), 'Rejected metadata does not create a consumer.');
          $response = $this->httpClient->get($management_uri, [RequestOptions::HEADERS => $headers]);
          $this->assertSame($original_metadata, Json::decode((string) $response->getBody()), 'Rejected metadata does not change the registered client.');
        }
      }
    }

    $updated_redirects = array_reverse($redirects);
    $response = $this->httpClient->put($management_uri, [
      RequestOptions::JSON => ['redirect_uris' => $updated_redirects],
      RequestOptions::HEADERS => $headers,
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame($updated_redirects, Json::decode((string) $response->getBody())['redirect_uris']);
    $response = $this->httpClient->get($management_uri, [RequestOptions::HEADERS => $headers]);
    $this->assertSame($updated_redirects, Json::decode((string) $response->getBody())['redirect_uris']);
  }

  /**
   * Enforces redirect transport on creation and atomic metadata updates.
   */
  public function testRedirectTransportValidationIsAtomic(): void {
    $registration = $this->helperClientRegistrationWorkflow();
    $management_uri = $this->buildUrl('/oauth/register/' . $registration['client_id']);
    $headers = ['Authorization' => 'Bearer ' . $registration['registration_access_token']];
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $https = 'https://example.com/callback';
    $custom = 'com.example.app://oauth/callback';
    $ipv4 = 'http://127.0.0.1:49152/callback';
    $ipv6 = 'http://[::1]:49153/callback';
    $public_http = 'http://example.com/callback';
    $cases = [
      'inferred public HTTP' => [NULL, [$public_http], FALSE],
      'web public HTTP' => ['web', [$public_http], FALSE],
      'native public HTTP' => ['native', [$public_http], FALSE],
      'mixed invalid last' => ['native', [$https, $public_http], FALSE],
      'mixed invalid first' => ['native', [$public_http, $custom], FALSE],
      'web custom scheme' => ['web', [$custom], FALSE],
      'web IPv4 loopback' => ['web', [$ipv4], FALSE],
      'web IPv6 loopback' => ['web', [$ipv6], FALSE],
      'web HTTPS' => ['web', [$https], TRUE],
      'native custom scheme' => ['native', [$custom], TRUE],
      'native IPv4 loopback' => ['native', [$ipv4], TRUE],
      'native IPv6 loopback' => ['native', [$ipv6], TRUE],
      'inferred native redirects' => [NULL, [$custom, $ipv4, $ipv6], TRUE],
    ];
    foreach ($cases as $case => [$application_type, $redirects, $valid]) {
      foreach (['POST', 'PUT'] as $method) {
        $context = "$case / $method";
        $response = $this->httpClient->get($management_uri, [RequestOptions::HEADERS => $headers]);
        $original_metadata = Json::decode((string) $response->getBody());
        $storage->resetCache();
        $original_storage = [];
        foreach ($storage->loadMultiple() as $id => $consumer) {
          $original_storage[$id] = $consumer->toArray();
        }
        $metadata = [
          'client_name' => $context,
          'redirect_uris' => $redirects,
        ];
        if ($application_type !== NULL) {
          $metadata['application_type'] = $application_type;
        }
        $response = $this->httpClient->request($method, $method === 'POST' ? $this->buildUrl('/oauth/register') : $management_uri, [
          RequestOptions::JSON => $metadata,
          RequestOptions::HEADERS => $method === 'PUT' ? $headers : [],
          RequestOptions::HTTP_ERRORS => FALSE,
        ]);
        $this->assertSame($valid ? ($method === 'POST' ? 201 : 200) : 400, $response->getStatusCode(), $context);
        $data = Json::decode((string) $response->getBody());
        $storage->resetCache();
        if (!$valid) {
          $this->assertSame('invalid_client_metadata', $data['error'], $context);
          $current_storage = [];
          foreach ($storage->loadMultiple() as $id => $consumer) {
            $current_storage[$id] = $consumer->toArray();
          }
          $this->assertSame($original_storage, $current_storage, $context . ': rejected metadata leaves storage unchanged.');
          $response = $this->httpClient->get($management_uri, [RequestOptions::HEADERS => $headers]);
          $this->assertSame($original_metadata, Json::decode((string) $response->getBody()), $context);
          continue;
        }
        $this->assertSame($redirects, $data['redirect_uris'], $context);
        $consumers = $storage->loadByProperties(['client_id' => $data['client_id']]);
        $this->assertCount(1, $consumers, $context);
        $consumer = reset($consumers);
        $this->assertInstanceOf(ConsumerInterface::class, $consumer);
        $this->assertSame($redirects, array_column($consumer->get('redirect')->getValue(), 'value'), $context);
      }
    }

    // application_type applies to the current request. A name-only update must
    // preserve the native redirects stored by the preceding inferred update.
    $response = $this->httpClient->put($management_uri, [
      RequestOptions::JSON => ['client_name' => 'Renamed native client'],
      RequestOptions::HEADERS => $headers,
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $response = $this->httpClient->get($management_uri, [RequestOptions::HEADERS => $headers]);
    $data = Json::decode((string) $response->getBody());
    $this->assertSame('Renamed native client', $data['client_name']);
    $this->assertSame([$custom, $ipv4, $ipv6], $data['redirect_uris']);
    $storage->resetCache();
    $consumers = $storage->loadByProperties(['client_id' => $registration['client_id']]);
    $consumer = reset($consumers);
    $this->assertInstanceOf(ConsumerInterface::class, $consumer);
    $this->assertSame([$custom, $ipv4, $ipv6], array_column($consumer->get('redirect')->getValue(), 'value'));
  }

  /**
   * Tests registration validation, secret rotation and administrative batches.
   */
  public function testClientManagementLifecycle(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $initial_count = count($storage->loadMultiple());
    foreach ([
      ['client_name' => []],
      ['redirect_uris' => 'https://example.com/callback'],
      ['contacts' => ['admin@example.com', 17]],
      ['grant_types' => [[]]],
    ] as $invalid_metadata) {
      $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
        RequestOptions::JSON => $invalid_metadata + [
          'client_name' => 'Invalid metadata',
          'redirect_uris' => ['https://example.com/callback'],
        ],
        RequestOptions::HTTP_ERRORS => FALSE,
      ]);
      $this->assertSame(400, $response->getStatusCode());
      $this->assertSame('invalid_client_metadata', Json::decode((string) $response->getBody())['error']);
    }
    $storage->resetCache();
    $this->assertCount($initial_count, $storage->loadMultiple());

    $registration = $this->helperClientRegistrationWorkflow();
    $other_registration = $this->helperClientRegistrationWorkflow();
    $client_id = $registration['client_id'];
    $management_uri = $this->buildUrl('/oauth/register/' . $client_id);
    $headers = [
      'Authorization' => 'Bearer ' . $registration['registration_access_token'],
      'Accept' => 'application/json',
    ];

    // Missing credentials and another client's token cannot rotate this secret.
    foreach ([
      [],
      [
        'Authorization' => 'Bearer ' . $other_registration['registration_access_token'],
      ],
    ] as $invalid_headers) {
      $response = $this->httpClient->post($management_uri, [
        RequestOptions::HEADERS => $invalid_headers,
        RequestOptions::HTTP_ERRORS => FALSE,
      ]);
      $this->assertSame(400, $response->getStatusCode());
      $this->assertSame('invalid_request', Json::decode((string) $response->getBody())['error']);
    }

    $response = $this->httpClient->post($management_uri, [
      RequestOptions::HEADERS => $headers,
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    $rotated = Json::decode((string) $response->getBody());
    $this->assertSame($client_id, $rotated['client_id']);
    $this->assertNotSame($registration['client_secret'], $rotated['client_secret']);
    $storage->resetCache();
    $consumers = $storage->loadByProperties(['client_id' => $client_id]);
    $consumer = reset($consumers);
    $this->assertInstanceOf(ConsumerInterface::class, $consumer);
    $stored_secret = (string) $consumer->get('secret')->value;
    $password = $this->container->get('password');
    $this->assertTrue($password->check($rotated['client_secret'], $stored_secret));
    $this->assertFalse($password->check($registration['client_secret'], $stored_secret));

    // The original registration token works without disclosing the new secret.
    $response = $this->httpClient->get($management_uri, [
      RequestOptions::HEADERS => $headers,
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertArrayNotHasKey('client_secret', Json::decode((string) $response->getBody()));

    $settings_path = '/admin/config/services/simple-oauth/oauth-21/client-registration';
    $this->drupalGet($settings_path);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser());
    $this->drupalGet($settings_path);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser(['administer simple_oauth entities']));
    $this->drupalGet($settings_path);
    $this->assertSession()->statusCodeEquals(200);
    $settings_before = $this->config('simple_oauth_client_registration.settings')->getRawData();
    $client_ids = $client_id . "\n" . str_repeat('x', 32) . "\n" . $other_registration['client_id'];
    $update_values = [
      'bulk_operations[operation]' => 'update',
      'bulk_operations[client_ids]' => $client_ids,
      'bulk_operations[metadata]' => Json::encode(['client_name' => 'Batch updated client']),
    ];
    $this->submitForm($update_values, 'Apply to listed clients');
    $this->assertSession()->pageTextContains('access_denied');
    $this->assertSession()->pageTextContains('not_found');
    $storage->resetCache();
    foreach ([$client_id, $other_registration['client_id']] as $denied_id) {
      $consumers = $storage->loadByProperties(['client_id' => $denied_id]);
      $this->assertSame('Test OAuth Client', reset($consumers)->label());
    }

    $this->drupalLogin($this->drupalCreateUser([
      'administer simple_oauth entities',
      'administer consumer entities',
    ]));
    $this->drupalGet($settings_path);
    $this->submitForm($update_values, 'Apply to listed clients');
    $this->assertSession()->pageTextContains('success');
    $this->assertSession()->pageTextContains('not_found');
    $storage->resetCache();
    foreach ([$client_id, $other_registration['client_id']] as $updated_id) {
      $consumers = $storage->loadByProperties(['client_id' => $updated_id]);
      $this->assertSame('Batch updated client', reset($consumers)->label());
    }
    $this->assertSame($settings_before, $this->config('simple_oauth_client_registration.settings')->getRawData());

    $delete_values = [
      'bulk_operations[operation]' => 'delete',
      'bulk_operations[client_ids]' => $client_ids,
    ];
    $this->submitForm($delete_values, 'Apply to listed clients');
    $this->assertSession()->pageTextContains('Confirm deletion before applying this operation.');
    $storage->resetCache();
    $this->assertNotEmpty($storage->loadByProperties(['client_id' => $client_id]));
    $this->submitForm($delete_values + [
      'bulk_operations[confirm_delete]' => TRUE,
    ], 'Apply to listed clients');
    $this->assertSession()->pageTextContains('success');
    $this->assertSession()->pageTextContains('not_found');
    $storage->resetCache();
    $this->assertEmpty($storage->loadByProperties(['client_id' => $client_id]));
    $this->assertEmpty($storage->loadByProperties(['client_id' => $other_registration['client_id']]));
    $this->assertSame($settings_before, $this->config('simple_oauth_client_registration.settings')->getRawData());
  }

  /**
   * Tests default grant types behavior based on configuration.
   *
   * Validates that the default_grant_types setting correctly controls
   * which grant types are automatically assigned when clients register
   * without explicitly specifying grant_types.
   *
   * Test scenarios:
   * 1. Default config (authorization_code + refresh_token): Should include both
   * 2. Single grant type configured: Should include only configured grant
   * 3. Explicit grant_types: Client-specified values respected regardless
   */
  public function testDefaultRefreshTokenGrant(): void {
    // Scenario 1: Default config with both authorization_code and
    // refresh_token. Expected: Both grant types in response.
    $config = $this->config('simple_oauth_client_registration.settings');
    $config->set('default_grant_types', ['authorization_code', 'refresh_token']);
    $config->save();
    $this->clearAllTestCaches();

    $client_metadata_no_grants = [
      'client_name' => 'Test Client - Default Grants Enabled',
      'redirect_uris' => ['https://example.com/callback'],
    ];

    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => $client_metadata_no_grants,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ]);

    $this->assertEquals(201, $response->getStatusCode(), 'Registration succeeded with default grants');
    $response->getBody()->rewind();
    $response_data = Json::decode($response->getBody()->getContents());

    $this->assertArrayHasKey('grant_types', $response_data, 'Response contains grant_types');
    $this->assertContains('authorization_code', $response_data['grant_types'],
      'Default grants include authorization_code');
    $this->assertContains('refresh_token', $response_data['grant_types'],
      'Default grants include refresh_token');

    // Scenario 2: Config with authorization_code only.
    // Expected: authorization_code only, NO refresh_token.
    $config->set('default_grant_types', ['authorization_code']);
    $config->save();
    $this->clearAllTestCaches();

    $client_metadata_no_grants_disabled = [
      'client_name' => 'Test Client - Single Grant Type',
      'redirect_uris' => ['https://example2.com/callback'],
    ];

    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => $client_metadata_no_grants_disabled,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ]);

    $this->assertEquals(201, $response->getStatusCode(), 'Registration succeeded with single grant type');
    $response->getBody()->rewind();
    $response_data = Json::decode($response->getBody()->getContents());

    $this->assertArrayHasKey('grant_types', $response_data, 'Response contains grant_types');
    $this->assertContains('authorization_code', $response_data['grant_types'],
      'Default grants include authorization_code');
    $this->assertNotContains('refresh_token', $response_data['grant_types'],
      'Default grants do NOT include refresh_token when not configured');

    // Scenario 3: Explicit grant_types specified by client.
    // Expected: Client-specified values respected, setting has no effect.
    $config->set('default_grant_types', ['authorization_code', 'refresh_token']);
    $config->save();
    $this->clearAllTestCaches();

    $client_metadata_explicit_grants = [
      'client_name' => 'Test Client - Explicit Grants',
      'redirect_uris' => ['https://example3.com/callback'],
      'grant_types' => ['client_credentials'],
    ];

    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => $client_metadata_explicit_grants,
      RequestOptions::COOKIES => $this->registrationAdministratorCookies(),
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ]);

    $this->assertEquals(201, $response->getStatusCode(), 'Registration succeeded with explicit grant_types');
    $response->getBody()->rewind();
    $response_data = Json::decode($response->getBody()->getContents());

    $this->assertArrayHasKey('grant_types', $response_data, 'Response contains grant_types');
    $this->assertEquals(['client_credentials'], $response_data['grant_types'],
      'Explicit grant_types are respected exactly as specified');
    $this->assertNotContains('refresh_token', $response_data['grant_types'],
      'refresh_token is NOT added when client explicitly specifies grant_types');
  }

  /**
   * Tests persisted scopes, default authorization, and registration management.
   */
  public function testRequestedScopesArePersistedAndReported(): void {
    $this->createRegistrationScope('test:read', ['authorization_code']);
    $this->createRegistrationScope('test:write', ['authorization_code']);
    $this->createRegistrationScope('test:machine', ['client_credentials']);
    $this->clearAllTestCaches();

    // Omitted grant_types must use the configured authorization-code defaults.
    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => [
        'client_name' => 'Scope lifecycle client',
        'redirect_uris' => ['https://example.com/callback'],
        'scope' => 'test:read test:write test:machine unknown:scope test:read',
      ],
    ]);
    $this->assertSame(201, $response->getStatusCode());
    $registration = Json::decode((string) $response->getBody());
    $this->assertArrayHasKey('scope', $registration);
    $this->assertEqualsCanonicalizing(['test:read', 'test:write'], explode(' ', $registration['scope']));

    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $consumers = $storage->loadByProperties(['client_id' => $registration['client_id']]);
    $consumer = reset($consumers);
    $this->assertInstanceOf(ConsumerInterface::class, $consumer);
    $this->assertCount(2, $consumer->get('authorization_code_scopes')->getScopes());
    $this->assertTrue($consumer->get('scopes')->isEmpty());

    // Exercise the real scope repository without a scope request parameter.
    $client = $this->container->get('simple_oauth.repositories.client')->getClientEntity($registration['client_id']);
    $scopes = $this->container->get('simple_oauth.repositories.scope')->finalizeScopes(
      [], 'authorization_code', $client, (string) $this->rootUser->id(),
    );
    $this->assertEqualsCanonicalizing(['test:read', 'test:write'], array_map(
      static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
      $scopes,
    ));

    $uri = $this->buildUrl('/oauth/register/' . $registration['client_id']);
    $headers = ['Authorization' => 'Bearer ' . $registration['registration_access_token']];
    $response = $this->httpClient->get($uri, [RequestOptions::HEADERS => $headers]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame($registration['scope'], Json::decode((string) $response->getBody())['scope']);

    foreach ([
      ['scope' => 'test:write'],
      ['client_name' => 'Renamed scope client'],
      ['scope' => ''],
      ['scope' => 'unknown:scope'],
    ] as $metadata) {
      $response = $this->httpClient->put($uri, [
        RequestOptions::JSON => $metadata,
        RequestOptions::HEADERS => $headers,
      ]);
      $this->assertSame(200, $response->getStatusCode());
      // Omission preserves scopes, while an explicit empty value clears them.
      $expected = !isset($metadata['scope']) || $metadata['scope'] === 'test:write' ? 'test:write' : '';
      $this->assertSame($expected, Json::decode((string) $response->getBody())['scope']);
      $response = $this->httpClient->get($uri, [RequestOptions::HEADERS => $headers]);
      $this->assertSame($expected, Json::decode((string) $response->getBody())['scope']);
      $storage->resetCache();
      $stored = $storage->load($consumer->id());
      $this->assertSame($expected === '' ? 0 : 1, $stored->get('authorization_code_scopes')->count());
    }

    // Filtering every requested scope must still report the empty selection.
    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => [
        'redirect_uris' => ['https://example.com/callback'],
        'scope' => 'unknown:scope',
      ],
    ]);
    $this->assertSame(201, $response->getStatusCode());
    $this->assertSame('', Json::decode((string) $response->getBody())['scope']);
  }

  /**
   * Tests anonymous registration respects upstream grant privilege checks.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   If an HTTP request fails.
   */
  public function testClientCredentialsRegistrationPrivilege(): void {
    $metadata = [
      'client_name' => 'Client credentials privilege boundary',
      'redirect_uris' => ['https://example.com/callback'],
      'grant_types' => ['client_credentials'],
    ];
    $protected = class_exists(Oauth2PrivilegedConsumerField::class);
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $before = count($storage->loadMultiple());
    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => $metadata,
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);
    // Older supported upstream versions have no privileged-field constraint.
    $this->assertSame($protected ? 400 : 201, $response->getStatusCode());
    if ($protected) {
      $this->assertSame('invalid_client_metadata', Json::decode((string) $response->getBody())['error']);
      $storage->resetCache();
      $this->assertCount($before, $storage->loadMultiple());
    }
    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::JSON => $metadata,
      RequestOptions::COOKIES => $this->registrationAdministratorCookies(),
    ]);
    $this->assertSame(201, $response->getStatusCode());
    $this->assertSame(['client_credentials'], Json::decode((string) $response->getBody())['grant_types']);
  }

  /**
   * Creates an actor permitted to assign privileged consumer fields.
   *
   * Use these cookies only for fixture creation, so registration management
   * requests still run without administrative session authority.
   *
   * @return \GuzzleHttp\Cookie\CookieJar
   *   Session cookies for the fixture administrator.
   */
  private function registrationAdministratorCookies(): CookieJar {
    $definitions = $this->container->get('user.permissions')->getPermissions();
    $permission = 'administer consumer access settings';
    $actor = $this->drupalCreateUser(isset($definitions[$permission]) ? [$permission] : []);
    $this->drupalLogin($actor);
    return $this->getSessionCookies();
  }

  /**
   * Tests scope selection and deduplication across supported grant types.
   */
  public function testRequestedScopesRespectGrantTypes(): void {
    $this->createRegistrationScope('test:read', ['authorization_code']);
    $this->createRegistrationScope('test:machine', ['client_credentials']);
    $this->createRegistrationScope('test:shared', ['authorization_code', 'client_credentials']);
    $this->clearAllTestCaches();
    $registration_cookies = $this->registrationAdministratorCookies();

    foreach ([['client_credentials'], ['authorization_code', 'client_credentials']] as $grant_types) {
      $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
        RequestOptions::COOKIES => $registration_cookies,
        RequestOptions::JSON => [
          'redirect_uris' => ['https://example.com/callback'],
          'grant_types' => $grant_types,
          'scope' => 'test:read test:machine test:shared unknown:scope',
        ],
      ]);
      $this->assertSame(201, $response->getStatusCode());
      $registration = Json::decode((string) $response->getBody());
      $expected = count($grant_types) === 1
        ? ['test:machine', 'test:shared']
        : ['test:read', 'test:machine', 'test:shared'];
      $this->assertArrayHasKey('scope', $registration);
      $this->assertEqualsCanonicalizing($expected, explode(' ', $registration['scope']));

      $consumers = $this->container->get('entity_type.manager')->getStorage('consumer')
        ->loadByProperties(['client_id' => $registration['client_id']]);
      $consumer = reset($consumers);
      $this->assertInstanceOf(ConsumerInterface::class, $consumer);
      $this->assertSame(count($grant_types) === 1 ? 0 : 2, $consumer->get('authorization_code_scopes')->count());
      $this->assertCount(2, $consumer->get('scopes')->getScopes());

      // Updates replace authorization defaults and preserve the allowlist.
      $response = $this->httpClient->put($this->buildUrl('/oauth/register/' . $registration['client_id']), [
        RequestOptions::JSON => ['scope' => 'test:shared'],
        RequestOptions::HEADERS => ['Authorization' => 'Bearer ' . $registration['registration_access_token']],
      ]);
      $this->assertSame(200, $response->getStatusCode());
      $this->assertEqualsCanonicalizing(['test:machine', 'test:shared'], explode(' ', Json::decode((string) $response->getBody())['scope']));
      $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
      $storage->resetCache();
      $stored = $storage->load($consumer->id());
      $this->assertSame(count($grant_types) === 1 ? 0 : 1, $stored->get('authorization_code_scopes')->count());
      $this->assertCount(2, $stored->get('scopes')->getScopes());
    }
  }

  /**
   * Creates a scope restricted to the specified grant types.
   *
   * @param string $name
   *   The externally visible scope name.
   * @param string[] $grant_types
   *   The grant types permitted to use the scope.
   * @param string $permission
   *   The permission granted by the scope.
   *
   * @return \Drupal\simple_oauth\Entity\Oauth2Scope
   *   The saved scope entity.
   */
  private function createRegistrationScope(string $name, array $grant_types, string $permission = 'access content'): Oauth2Scope {
    $scope = Oauth2Scope::create([
      'name' => $name,
      'description' => 'Registration scope fixture',
      'grant_types' => array_fill_keys($grant_types, ['status' => TRUE]),
      'umbrella' => FALSE,
      'granularity_id' => Oauth2ScopeInterface::GRANULARITY_PERMISSION,
      'granularity_configuration' => ['permission' => $permission],
    ]);
    $scope->save();
    return $scope;
  }

  /**
   * Tests that clients cannot widen or clear an administrative allowlist.
   */
  public function testClientCredentialsAllowlistCannotBeChanged(): void {
    $this->setUpKeys();
    $allowed = $this->createRegistrationScope('test:machine', ['client_credentials']);
    $this->createRegistrationScope('test:admin', ['client_credentials'], 'administer site configuration');
    $user = $this->drupalCreateUser();
    $this->clearAllTestCaches();
    $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
      RequestOptions::COOKIES => $this->registrationAdministratorCookies(),
      RequestOptions::JSON => [
        'redirect_uris' => ['https://example.com/callback'],
        'grant_types' => ['client_credentials'],
        'scope' => 'test:machine test:admin',
      ],
    ]);
    $this->assertSame(201, $response->getStatusCode());
    $registration = Json::decode((string) $response->getBody());
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $consumers = $storage->loadByProperties(['client_id' => $registration['client_id']]);
    $consumer = reset($consumers);
    $this->assertInstanceOf(ConsumerInterface::class, $consumer);
    // An administrator activates the client with a restricted service account.
    $consumer->set('user_id', $user->id());
    $consumer->set('scopes', [['scope_id' => $allowed->id()]]);
    $consumer->save();

    foreach (['test:machine test:admin', 'test:admin', '', 'unknown:scope'] as $requested_scope) {
      $response = $this->httpClient->put($this->buildUrl('/oauth/register/' . $registration['client_id']), [
        RequestOptions::JSON => ['scope' => $requested_scope],
        RequestOptions::HEADERS => ['Authorization' => 'Bearer ' . $registration['registration_access_token']],
      ]);
      $this->assertSame(200, $response->getStatusCode());
      $this->assertSame('test:machine', Json::decode((string) $response->getBody())['scope']);
      $storage->resetCache();
      $stored = $storage->load($consumer->id());
      $this->assertSame([['scope_id' => $allowed->id()]], $stored->get('scopes')->getValue());
    }

    // The token endpoint must still enforce the administrative restriction.
    $parameters = [
      'grant_type' => 'client_credentials',
      'client_id' => $registration['client_id'],
      'client_secret' => $registration['client_secret'],
      'scope' => 'test:admin',
    ];
    $response = $this->httpClient->post($this->buildUrl('/oauth/token'), [
      RequestOptions::FORM_PARAMS => $parameters,
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('invalid_scope', Json::decode((string) $response->getBody())['error']);
    unset($parameters['scope']);
    $response = $this->httpClient->post($this->buildUrl('/oauth/token'), [
      RequestOptions::FORM_PARAMS => $parameters,
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $tokens = Json::decode((string) $response->getBody());
    $claims = Json::decode(base64_decode(strtr(explode('.', $tokens['access_token'])[1], '-_', '+/')));
    $this->assertSame(['test:machine'], $claims['scope']);
  }

  /**
   * Tests that empty static scope fields never disclose the full scope catalog.
   */
  public function testStaticScopeResponsesMatchStoredScopes(): void {
    $this->container->get('module_installer')->install([
      'simple_oauth_static_scope',
      'simple_oauth_static_scope_test',
    ]);
    $this->config('simple_oauth.settings')->set('scope_provider', 'static')->save();
    $this->clearAllTestCaches();
    $this->rebuildContainer();
    // Reproduce the real adapter's interpretation of an empty ID list.
    $this->assertNotEmpty($this->container->get('simple_oauth.oauth2_scope.provider')->loadMultiple([]));

    foreach ([[], ['scope' => 'unknown:scope'], ['scope' => 'static_scope:child:child']] as $metadata) {
      $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
        RequestOptions::JSON => $metadata + [
          'redirect_uris' => ['https://example.com/callback'],
          'grant_types' => ['authorization_code'],
        ],
      ]);
      $this->assertSame(201, $response->getStatusCode());
      $registration = Json::decode((string) $response->getBody());
      $expected = ($metadata['scope'] ?? '') === 'static_scope:child:child' ? 'static_scope:child:child' : '';
      $this->assertSame($expected, $registration['scope']);
      $uri = $this->buildUrl('/oauth/register/' . $registration['client_id']);
      $headers = ['Authorization' => 'Bearer ' . $registration['registration_access_token']];
      $response = $this->httpClient->get($uri, [RequestOptions::HEADERS => $headers]);
      $this->assertSame(200, $response->getStatusCode());
      $this->assertSame($expected, Json::decode((string) $response->getBody())['scope']);
      $response = $this->httpClient->put($uri, [
        RequestOptions::JSON => ['scope' => ''],
        RequestOptions::HEADERS => $headers,
      ]);
      $this->assertSame(200, $response->getStatusCode());
      $this->assertSame('', Json::decode((string) $response->getBody())['scope']);
    }
  }

  /**
   * Tests scope updates without the authorization-code defaults field.
   */
  public function testScopesWithoutAuthorizationCodeField(): void {
    $this->createRegistrationScope('test:legacy', ['authorization_code', 'client_credentials']);
    $this->container->get('state')->set('simple_oauth_registration_test.legacy_scope_field', TRUE);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->clearAllTestCaches();
    $registration_cookies = $this->registrationAdministratorCookies();

    foreach (['authorization_code', 'client_credentials'] as $grant_type) {
      $response = $this->httpClient->post($this->buildUrl('/oauth/register'), [
        RequestOptions::COOKIES => $grant_type === 'client_credentials' ? $registration_cookies : NULL,
        RequestOptions::JSON => [
          'redirect_uris' => ['https://example.com/callback'],
          'grant_types' => [$grant_type],
          'scope' => 'test:legacy',
        ],
        RequestOptions::HTTP_ERRORS => FALSE,
      ]);
      $this->assertSame(201, $response->getStatusCode());
      $registration = Json::decode((string) $response->getBody());
      $expected = $grant_type === 'client_credentials' ? 'test:legacy' : '';
      $this->assertSame($expected, $registration['scope']);
      $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
      $consumers = $storage->loadByProperties(['client_id' => $registration['client_id']]);
      $consumer = reset($consumers);
      $this->assertInstanceOf(ConsumerInterface::class, $consumer);
      $this->assertFalse($consumer->hasField('authorization_code_scopes'));
      $this->assertSame($grant_type === 'client_credentials' ? 1 : 0, $consumer->get('scopes')->count());

      $uri = $this->buildUrl('/oauth/register/' . $registration['client_id']);
      $headers = ['Authorization' => 'Bearer ' . $registration['registration_access_token']];
      foreach (['test:legacy', '', 'unknown:scope'] as $scope) {
        $response = $this->httpClient->put($uri, [
          RequestOptions::JSON => ['scope' => $scope],
          RequestOptions::HEADERS => $headers,
          RequestOptions::HTTP_ERRORS => FALSE,
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($expected, Json::decode((string) $response->getBody())['scope']);
        $response = $this->httpClient->get($uri, [RequestOptions::HEADERS => $headers]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($expected, Json::decode((string) $response->getBody())['scope']);
      }
    }
  }

  /**
   * Tests secret rollback after a real entity write and safe log contexts.
   */
  public function testSecretFailureAtomicityAndLogging(): void {
    $records = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('log')->willReturnCallback(static function ($level, $message, array $context) use (&$records): void {
      $records[] = [(string) $message, $context];
    });
    $this->container->get('logger.factory')->addLogger($logger);
    $service = $this->container->get('simple_oauth_client_registration.service.registration');
    $sentinel = 'registration-credential-sentinel';
    $registered = $service->registerClient(new ClientRegistration(
      clientName: 'Atomic registration fixture',
      redirectUris: ['https://client.example/callback'],
      clientSecret: $sentinel,
      registrationAccessToken: $sentinel . '-token',
    ));
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $matches = $storage->loadByProperties(['client_id' => $registered['client_id']]);
    $consumer = reset($matches);
    $this->assertInstanceOf(ConsumerInterface::class, $consumer);
    $original_hash = $consumer->get('secret')->value;
    $original_metadata = $service->getClientMetadata($registered['client_id']);
    $this->container->get('state')->set('simple_oauth_registration_test.fail_update', TRUE);
    try {
      $service->rotateClientSecret($registered['client_id']);
      $this->fail('The post-write fixture must reject secret persistence.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('Registration fixture persistence failure', $exception->getMessage());
    }
    finally {
      $this->container->get('state')->delete('simple_oauth_registration_test.fail_update');
    }
    $storage->resetCache();
    $matches = $storage->loadByProperties(['client_id' => $registered['client_id']]);
    $reloaded = reset($matches);
    $this->assertInstanceOf(ConsumerInterface::class, $reloaded);
    $this->assertSame($original_hash, $reloaded->get('secret')->value);
    $this->assertSame($original_hash, $consumer->get('secret')->value);
    $this->assertTrue($this->container->get('simple_oauth.repositories.client')->validateClient(
      $registered['client_id'], $registered['client_secret'], 'authorization_code'
    ));
    $this->assertSame($original_metadata, $service->getClientMetadata($registered['client_id']));
    $this->assertTrue($service->validateRegistrationToken($registered['client_id'], $registered['registration_access_token']));
    try {
      $service->registerClient(new ClientRegistration(redirectUris: [$sentinel]));
      $this->fail('The invalid redirect fixture must be rejected.');
    }
    catch (\InvalidArgumentException) {
      $this->assertNotEmpty($records);
    }
    $serialized = serialize($records);
    foreach ([$sentinel, $registered['client_secret'], $registered['registration_access_token']] as $credential) {
      $this->assertStringNotContainsString($credential, $serialized);
    }
    $this->assertStringContainsString($registered['client_id'], $serialized);
  }

}
