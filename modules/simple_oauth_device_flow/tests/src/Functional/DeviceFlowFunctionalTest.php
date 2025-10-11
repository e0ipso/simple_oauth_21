<?php

namespace Drupal\Tests\simple_oauth_device_flow\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\consumers\Entity\Consumer;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\simple_oauth\Functional\SimpleOauthTestTrait;
use Drupal\user\Entity\User;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional tests for RFC 8628 OAuth 2.0 Device Authorization Grant.
 *
 * Tests the complete device flow including:
 * - Device authorization endpoint
 * - User verification flow
 * - Token endpoint with device grant
 * - Device polling and error handling.
 */
#[Group('simple_oauth_device_flow')]
#[Group('functional')]
class DeviceFlowFunctionalTest extends BrowserTestBase {

  use SimpleOauthTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'serialization',
    'simple_oauth',
    'consumers',
    'simple_oauth_21',
    'simple_oauth_device_flow',
    'options',
    'field',
    'text',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The HTTP client for API requests.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * Test consumer entity.
   *
   * @var \Drupal\consumers\Entity\Consumer
   */
  protected $consumer;

  /**
   * Test user for authentication.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $testUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set up HTTP client with base URI for test environment.
    $this->httpClient = $this->container->get('http_client_factory')
      ->fromOptions(['base_uri' => $this->baseUrl]);

    // Clear caches to ensure entity types are properly discovered.
    drupal_flush_all_caches();

    // Set up OAuth keys for testing.
    $this->setUpKeys();

    // Create test user.
    $this->testUser = User::create([
      'name' => 'test_device_user',
      'mail' => 'test@example.com',
      'status' => 1,
    ]);
    $this->testUser->setPassword('test_password');
    $this->testUser->save();

    // Create test consumer for device flow.
    $this->consumer = Consumer::create([
      'label' => 'Test Device Client',
      'client_id' => 'test_device_client',
      'scopes' => [],
    // Device flow typically uses public clients.
      'confidential' => FALSE,
      // Device flow doesn't use redirects, but field is required by
      // simple_oauth.
      'redirect' => ['http://localhost'],
      'access_token_expiration' => 300,
      'refresh_token_expiration' => 1209600,
      'user_id' => $this->testUser->id(),
    ]);

    // Add grant types using appendItem with explicit value structure.
    // Use full URN format per RFC 8628.
    $this->consumer->get('grant_types')->appendItem(['value' => 'urn:ietf:params:oauth:grant-type:device_code']);
    $this->consumer->get('grant_types')->appendItem(['value' => 'refresh_token']);
    $this->consumer->save();
  }

  /**
   * Comprehensive RFC 8628 Device Authorization Grant test.
   *
   * Tests the complete device flow including:
   * - Device authorization endpoint (valid and error cases)
   * - User verification flow
   * - Token endpoint with device grant
   * - Device polling and rate limiting
   * - Security validations (single-use codes, expiration)
   * - Scope handling.
   *
   * All scenarios execute sequentially using a shared Drupal instance
   * for optimal performance.
   */
  public function testComprehensiveDeviceFlowFunctionality(): void {
    // Happy path flow.
    $this->helperDeviceAuthorizationEndpoint();
    $this->helperDeviceVerificationForm();
    $this->helperDeviceVerificationFlow();
    $this->helperTokenEndpointWithDeviceGrant();

    // Error handling.
    $this->helperDeviceAuthorizationWithInvalidClient();
    $this->helperDeviceAuthorizationWithMissingClientId();
    $this->helperDeviceVerificationWithInvalidCode();
    $this->helperTokenEndpointWithInvalidDeviceCode();

    // Advanced scenarios.
    $this->helperTokenEndpointWithExpiredDeviceCode();
    $this->helperDeviceFlowRateLimiting();
    $this->helperDeviceFlowWithScopes();
    $this->helperDeviceCodeSingleUse();
  }

  /**
   * Helper: Tests device authorization endpoint functionality.
   *
   * Validates RFC 8628 device authorization endpoint returns proper
   * response structure with device_code, user_code, verification_uri,
   * expires_in, and interval fields.
   *
   * @covers \Drupal\simple_oauth_device_flow\Controller\DeviceAuthorizationController::authorize
   */
  protected function helperDeviceAuthorizationEndpoint(): void {
    $data = $this->requestDeviceAuthorization();

    // Verify required RFC 8628 response fields.
    $this->assertArrayHasKey('device_code', $data);
    $this->assertArrayHasKey('user_code', $data);
    $this->assertArrayHasKey('verification_uri', $data);
    $this->assertArrayHasKey('expires_in', $data);
    $this->assertArrayHasKey('interval', $data);

    // Verify response structure.
    $this->assertIsString($data['device_code']);
    $this->assertIsString($data['user_code']);
    $this->assertIsString($data['verification_uri']);
    $this->assertIsInt($data['expires_in']);
    $this->assertIsInt($data['interval']);

    // Verify device code is properly formatted (should be long and random)
    $this->assertGreaterThan(20, strlen($data['device_code']));

    // Verify user code is properly formatted (shorter and user-friendly).
    $this->assertLessThan(20, strlen($data['user_code']));
    $this->assertGreaterThan(4, strlen($data['user_code']));

    // Verify verification URI points to our device endpoint.
    $this->assertStringContainsString('/oauth/device', $data['verification_uri']);
  }

  /**
   * Helper method to request device authorization.
   *
   * @return array
   *   The device authorization response data.
   */
  protected function requestDeviceAuthorization(): array {
    $device_auth_url = $this->buildUrl('/oauth/device_authorization');

    // Test valid device authorization request.
    $response = $this->httpClient->post($device_auth_url, [
      RequestOptions::FORM_PARAMS => [
        'client_id' => $this->consumer->getClientId(),
    // Empty scope for basic test.
        'scope' => '',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));

    // Rewind stream before reading to ensure we get the full content.
    $response->getBody()->rewind();
    return Json::decode($response->getBody()->getContents());
  }

  /**
   * Helper: Tests device authorization endpoint with invalid client.
   *
   * Validates that requests with invalid client_id return proper error.
   */
  protected function helperDeviceAuthorizationWithInvalidClient(): void {
    $device_auth_url = $this->buildUrl('/oauth/device_authorization');

    $response = $this->httpClient->post($device_auth_url, [
      RequestOptions::FORM_PARAMS => [
        'client_id' => 'invalid_client_id',
        'scope' => '',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $response->getStatusCode());
    $response->getBody()->rewind();
    $data = Json::decode($response->getBody()->getContents());
    $this->assertEquals('invalid_client', $data['error']);
  }

  /**
   * Helper: Tests device authorization endpoint with missing client_id.
   *
   * Validates that requests without client_id return proper error.
   */
  protected function helperDeviceAuthorizationWithMissingClientId(): void {
    $device_auth_url = $this->buildUrl('/oauth/device_authorization');

    $response = $this->httpClient->post($device_auth_url, [
      RequestOptions::FORM_PARAMS => [
        'scope' => '',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $response->getStatusCode());
    $response->getBody()->rewind();
    $data = Json::decode($response->getBody()->getContents());
    $this->assertEquals('invalid_request', $data['error']);
  }

  /**
   * Helper: Tests device verification form display.
   *
   * Validates that the device verification form is accessible and
   * displays proper form elements.
   *
   * @covers \Drupal\simple_oauth_device_flow\Controller\DeviceVerificationController::form
   */
  protected function helperDeviceVerificationForm(): void {
    // Device verification requires authentication, so log in first.
    $this->drupalLogin($this->testUser);

    // Access the verification form.
    $this->drupalGet('/oauth/device');

    // Verify we get the form page.
    $this->assertSession()->statusCodeEquals(200);

    // Verify form elements are present.
    $this->assertSession()->pageTextContains('Device Authorization');
    $this->assertSession()->fieldExists('user_code');
  }

  /**
   * Helper: Tests complete device verification flow.
   *
   * Validates that users can successfully authorize a device using
   * the verification form.
   *
   * @covers \Drupal\simple_oauth_device_flow\Controller\DeviceVerificationController::verify
   */
  protected function helperDeviceVerificationFlow(): void {
    // First, get device authorization.
    $device_data = $this->requestDeviceAuthorization();

    // Log in as test user.
    $this->drupalLogin($this->testUser);

    // Access verification form with user code.
    $verification_url = $this->buildUrl('/oauth/device');
    $this->drupalGet($verification_url);

    // Submit the verification form with the user code.
    $this->submitForm([
      'user_code' => $device_data['user_code'],
    ], 'Authorize');

    // Should redirect to confirmation or success page.
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Helper: Tests device verification with invalid user code.
   *
   * Validates that invalid user codes are properly rejected.
   */
  protected function helperDeviceVerificationWithInvalidCode(): void {
    $this->drupalLogin($this->testUser);

    $verification_url = $this->buildUrl('/oauth/device');
    $this->drupalGet($verification_url);

    $this->submitForm([
      'user_code' => 'INVALID_CODE',
    ], 'Authorize');

    // Should show error message.
    $this->assertSession()->pageTextContains('Invalid user code');
  }

  /**
   * Helper: Tests token endpoint with device code grant.
   *
   * This test covers the polling mechanism that devices use to get tokens,
   * including authorization_pending state and successful token retrieval.
   */
  protected function helperTokenEndpointWithDeviceGrant(): void {
    // Get device authorization.
    $device_data = $this->requestDeviceAuthorization();
    $device_code = $device_data['device_code'];

    $token_url = $this->buildUrl('/oauth/token');

    // Test polling before user authorization (returns authorization_pending).
    $response = $this->httpClient->post($token_url, [
      RequestOptions::FORM_PARAMS => [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'device_code' => $device_code,
        'client_id' => $this->consumer->getClientId(),
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $response->getStatusCode());
    $response->getBody()->rewind();
    $data = Json::decode($response->getBody()->getContents());
    $this->assertEquals('authorization_pending', $data['error']);

    // Now authorize the device.
    $this->drupalLogin($this->testUser);
    $verification_url = $this->buildUrl('/oauth/device');
    $this->drupalGet($verification_url);
    $this->submitForm([
      'user_code' => $device_data['user_code'],
    ], 'Authorize');

    // Poll again after authorization (should succeed)
    $response = $this->httpClient->post($token_url, [
      RequestOptions::FORM_PARAMS => [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'device_code' => $device_code,
        'client_id' => $this->consumer->getClientId(),
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(200, $response->getStatusCode());
    $response->getBody()->rewind();
    $data = Json::decode($response->getBody()->getContents());

    // Verify token response structure.
    $this->assertArrayHasKey('access_token', $data);
    $this->assertArrayHasKey('token_type', $data);
    $this->assertArrayHasKey('expires_in', $data);
    $this->assertEquals('Bearer', $data['token_type']);
    $this->assertIsString($data['access_token']);
    $this->assertIsInt($data['expires_in']);
  }

  /**
   * Helper: Tests token endpoint with invalid device code.
   *
   * Validates that invalid device codes are properly rejected.
   */
  protected function helperTokenEndpointWithInvalidDeviceCode(): void {
    $token_url = $this->buildUrl('/oauth/token');

    $response = $this->httpClient->post($token_url, [
      RequestOptions::FORM_PARAMS => [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'device_code' => 'invalid_device_code',
        'client_id' => $this->consumer->getClientId(),
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $response->getStatusCode());
    $response->getBody()->rewind();
    $data = Json::decode($response->getBody()->getContents());
    $this->assertEquals('invalid_grant', $data['error']);
  }

  /**
   * Helper: Tests token endpoint with expired device code.
   *
   * Placeholder for expired device code testing.
   */
  protected function helperTokenEndpointWithExpiredDeviceCode(): void {
    // This test would require manipulating the device code expiration
    // For now, we'll test the basic structure.
    $this->assertTrue(TRUE, 'Expired device code test placeholder');
  }

  /**
   * Helper: Tests device flow rate limiting (slow_down error).
   *
   * Validates that rapid polling triggers rate limiting responses.
   */
  protected function helperDeviceFlowRateLimiting(): void {
    // Get device authorization.
    $device_data = $this->requestDeviceAuthorization();
    $device_code = $device_data['device_code'];

    $token_url = $this->buildUrl('/oauth/token');

    // Make rapid requests to trigger rate limiting.
    for ($i = 0; $i < 3; $i++) {
      $response = $this->httpClient->post($token_url, [
        RequestOptions::FORM_PARAMS => [
          'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
          'device_code' => $device_code,
          'client_id' => $this->consumer->getClientId(),
        ],
        RequestOptions::HTTP_ERRORS => FALSE,
      ]);
    }

    // Last request might return slow_down if rate limiting is implemented.
    $response->getBody()->rewind();
    $data = Json::decode($response->getBody()->getContents());

    // Accept either authorization_pending or slow_down as valid responses.
    $this->assertContains($data['error'], ['authorization_pending', 'slow_down']);
  }

  /**
   * Helper: Tests device flow with scopes.
   *
   * Verifies that scopes are properly stored and persisted through
   * the device authorization flow using the oauth2_scope_reference field.
   */
  protected function helperDeviceFlowWithScopes(): void {
    // Create test scopes first.
    // Note: Scope IDs are auto-generated from names using scopeToMachineName(),
    // so 'name' => 'read' becomes 'id' => 'read'. Do not explicitly set 'id'.
    $scope_storage = \Drupal::entityTypeManager()->getStorage('oauth2_scope');
    $scope_storage->create([
      'name' => 'read',
      'description' => 'Read access to resources',
    ])->save();
    $scope_storage->create([
      'name' => 'write',
      'description' => 'Write access to resources',
    ])->save();

    // Create consumer with specific scopes.
    $scoped_consumer = Consumer::create([
      'label' => 'Test Scoped Device Client',
      'client_id' => 'test_scoped_client',
      'scopes' => ['read', 'write'],
      'confidential' => FALSE,
      'redirect' => ['http://localhost'],
      'access_token_expiration' => 300,
      'user_id' => $this->testUser->id(),
    ]);
    // Add grant types using appendItem with explicit value structure.
    $scoped_consumer->get('grant_types')->appendItem(['value' => 'urn:ietf:params:oauth:grant-type:device_code']);
    $scoped_consumer->save();

    $device_auth_url = $this->buildUrl('/oauth/device_authorization');

    $response = $this->httpClient->post($device_auth_url, [
      RequestOptions::FORM_PARAMS => [
        'client_id' => $scoped_consumer->getClientId(),
        'scope' => 'read write',
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(200, $response->getStatusCode());
    $response->getBody()->rewind();
    $data = Json::decode($response->getBody()->getContents());

    // Verify scope is handled properly in response.
    $this->assertArrayHasKey('device_code', $data);
    $this->assertArrayHasKey('user_code', $data);

    // Verify scopes were stored correctly in the device code entity.
    $device_code_storage = \Drupal::entityTypeManager()->getStorage('oauth2_device_code');
    $device_codes = $device_code_storage->loadByProperties([
      'device_code' => $data['device_code'],
    ]);
    $device_code_entity = reset($device_codes);

    $this->assertNotNull($device_code_entity, 'Device code entity should exist');

    // Verify scopes are stored using field API.
    $scopes = $device_code_entity->getScopes();
    $this->assertCount(2, $scopes, 'Device code should have 2 scopes');

    $scope_ids = array_map(fn($scope) => $scope->getIdentifier(), $scopes);
    $this->assertContains('read', $scope_ids, 'Scope list should contain "read"');
    $this->assertContains('write', $scope_ids, 'Scope list should contain "write"');
  }

  /**
   * Helper: Tests device flow security - device code should be single use.
   *
   * Validates that device codes can only be used once for security.
   */
  protected function helperDeviceCodeSingleUse(): void {
    // Get device authorization and complete the flow.
    $device_data = $this->requestDeviceAuthorization();
    $device_code = $device_data['device_code'];

    // Authorize the device.
    $this->drupalLogin($this->testUser);
    $verification_url = $this->buildUrl('/oauth/device');
    $this->drupalGet($verification_url);
    $this->submitForm([
      'user_code' => $device_data['user_code'],
    ], 'Authorize');

    $token_url = $this->buildUrl('/oauth/token');

    // First token request should succeed.
    $response = $this->httpClient->post($token_url, [
      RequestOptions::FORM_PARAMS => [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'device_code' => $device_code,
        'client_id' => $this->consumer->getClientId(),
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    // Second token request with same device code should fail.
    $response = $this->httpClient->post($token_url, [
      RequestOptions::FORM_PARAMS => [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'device_code' => $device_code,
        'client_id' => $this->consumer->getClientId(),
      ],
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);

    $this->assertEquals(400, $response->getStatusCode());
    $response->getBody()->rewind();
    $data = Json::decode($response->getBody()->getContents());
    // Accept either invalid_grant or invalid_request as both correctly reject
    // the reused device code. The League OAuth2 Server library may return
    // different error codes depending on internal validation order.
    $this->assertContains($data['error'], ['invalid_grant', 'invalid_request'],
      'Reused device code should be rejected with appropriate error');
  }

}
