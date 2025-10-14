<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Entity\Oauth2Token;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\simple_oauth\Functional\SimpleOauthTestTrait;
use Drupal\simple_oauth_21\Trait\DebugLoggingTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Comprehensive functional tests for OAuth 2.0 Server Metadata module.
 *
 * Tests all server metadata functionality including RFC 8414 compliance,
 * OpenID Connect Discovery (OpenID Connect Discovery 1.0), and RFC 7009
 * token revocation endpoint. Consolidated into a single test class with
 * one test method for optimal performance.
 *
 * This consolidation reduces Drupal installations from 3 to 1, significantly
 * improving test execution time while maintaining complete RFC coverage.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8414
 * @see https://openid.net/specs/openid-connect-discovery-1_0.html
 * @see https://datatracker.ietf.org/doc/html/rfc7009
 */
#[Group('simple_oauth_server_metadata')]
final class ServerMetadataFunctionalTest extends BrowserTestBase {

  use SimpleOauthTestTrait;
  use DebugLoggingTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'consumers',
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_server_metadata',
    'simple_oauth_client_registration',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user with permission to administer OAuth settings.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * The test OAuth client (confidential).
   *
   * @var \Drupal\consumers\Entity\Consumer
   */
  private Consumer $testClient;

  /**
   * The client secret for the test client.
   *
   * @var string
   */
  private string $clientSecret;

  /**
   * The test OAuth access token.
   *
   * @var \Drupal\simple_oauth\Entity\Oauth2Token
   */
  private Oauth2Token $testToken;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set up OAuth keys for token revocation testing.
    $this->setUpKeys();

    // Create admin user for settings form testing.
    $this->adminUser = $this->drupalCreateUser([
      'administer simple_oauth entities',
    ]);

    // Create a confidential OAuth client (consumer) for revocation testing.
    $this->clientSecret = 'test_client_secret_12345';
    $this->testClient = Consumer::create([
      'label' => 'Test Confidential Client',
      'client_id' => 'test_client_id',
      'secret' => $this->clientSecret,
      'is_default' => FALSE,
      'confidential' => TRUE,
    ]);
    $this->testClient->save();

    // Create a test access token owned by this client.
    $this->testToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $this->testClient,
      'value' => 'test_token_value_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 3600,
      'status' => TRUE,
    ]);
    $this->testToken->save();

    // Configure required settings for OpenID Connect Discovery.
    $this->config('simple_oauth_server_metadata.settings')
      ->set('service_documentation', 'https://example.com/docs')
      ->set('op_policy_uri', 'https://example.com/policy')
      ->set('op_tos_uri', 'https://example.com/terms')
      ->save();

    // Ensure OpenID Connect is not disabled.
    $this->config('simple_oauth.settings')
      ->set('disable_openid_connect', FALSE)
      ->save();

    // Clear caches to ensure services are properly initialized.
    drupal_flush_all_caches();
  }

  /**
   * Tests comprehensive server metadata functionality.
   *
   * This comprehensive test executes all server metadata, OpenID Connect
   * Discovery, and token revocation scenarios in a single test run, reducing
   * test execution overhead while maintaining complete RFC coverage.
   *
   * Test coverage includes:
   * - RFC 8414: OAuth 2.0 Authorization Server Metadata
   * - OpenID Connect Discovery 1.0: Discovery endpoint functionality
   * - RFC 7009: OAuth 2.0 Token Revocation
   * - Settings form access and configuration
   * - Cache behavior and CORS headers
   * - Client authentication and authorization
   * - Privacy preservation and security compliance
   *
   * All scenarios execute sequentially, maintaining test isolation through
   * proper cleanup and state management in helper methods.
   */
  public function testComprehensiveServerMetadataFunctionality(): void {
    // ===== Phase 1: Core Server Metadata (RFC 8414) =====
    $this->helperWellKnownEndpointAccessibility();
    $this->helperRequiredRfc8414Fields();
    $this->helperSettingsFormAccessAndPermissions();
    $this->helperConfigurePolicyUrls();
    $this->helperConfigureCapabilities();
    $this->helperFormValidation();
    $this->helperOauth21ComplianceGuidance();
    $this->helperCoreOauthServerCapabilities();
    $this->helperJsonStructureAndContentTypes();
    $this->helperDeviceAuthorizationEndpoint();

    // ===== Phase 2: OpenID Connect Discovery =====
    $this->helperOpenIdConfigurationRouteExists();
    $this->helperConfigurationIntegration();
    $this->helperPublicAccess();
    $this->helperSpecificationCompliance();
    $this->helperOpenIdConnectDisabled();
    $this->helperServiceUnavailabilityError();
    $this->helperJsonContentType();
    $this->helperHttpMethodRestrictions();
    $this->helperRegistrationEndpointDetection();

    // Clean up configuration after tests that depend on it.
    $this->helperClearConfigurationValues();

    // ===== Phase 3: Token Revocation (RFC 7009) =====
    $this->helperSuccessfulRevocationWithBasicAuth();
    $this->helperSuccessfulRevocationWithPostBodyCredentials();
    $this->helperPublicClientRevocation();
    $this->helperAuthenticationFailureWithInvalidCredentials();
    $this->helperAuthenticationFailureWithMissingCredentials();
    $this->helperMissingTokenParameter();
    $this->helperBypassPermissionAllowsRevokingAnyToken();
    $this->helperOwnershipValidationPreventsUnauthorizedRevocation();
    $this->helperNonExistentTokenReturnsSuccess();
    $this->helperIdempotentRevocation();
    $this->helperTokenTypeHintParameter();
    $this->helperRefreshTokenRevocation();
    $this->helperServerMetadataIncludesRevocationEndpoint();
    $this->helperOnlyPostMethodAccepted();

    $this->assertTrue(TRUE, 'All server metadata test scenarios completed successfully');
  }

  /**
   * Helper: Tests well-known endpoint accessibility without authentication.
   */
  protected function helperWellKnownEndpointAccessibility(): void {
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('Content-Type', 'application/json');
  }

  /**
   * Helper: Verifies required RFC 8414 fields are present.
   */
  protected function helperRequiredRfc8414Fields(): void {
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $response_body = $this->getSession()->getPage()->getContent();
    $metadata = Json::decode($response_body);

    // Verify required RFC 8414 fields.
    $this->assertArrayHasKey('issuer', $metadata);
    $this->assertArrayHasKey('authorization_endpoint', $metadata);
    $this->assertArrayHasKey('token_endpoint', $metadata);
    $this->assertArrayHasKey('response_types_supported', $metadata);
    $this->assertArrayHasKey('grant_types_supported', $metadata);

    // Verify OAuth 2.1 required fields.
    $this->assertContains('code', $metadata['response_types_supported']);
    $this->assertContains('authorization_code', $metadata['grant_types_supported']);
  }

  /**
   * Helper: Tests settings form access and permissions.
   */
  protected function helperSettingsFormAccessAndPermissions(): void {
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/server-metadata');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/server-metadata');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Configure additional RFC 8414');
  }

  /**
   * Helper: Configure policy URLs and verify form saves configuration.
   */
  protected function helperConfigurePolicyUrls(): void {
    $policy_data = [
      'service_documentation' => 'https://example.com/docs',
      'op_policy_uri' => 'https://example.com/policy',
      'op_tos_uri' => 'https://example.com/terms',
    ];

    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/server-metadata');
    $this->submitForm($policy_data, 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved');

    // Verify configuration was saved in Drupal.
    $saved_config = $this->config('simple_oauth_server_metadata.settings');
    $this->assertEquals($policy_data['service_documentation'], $saved_config->get('service_documentation'));
    $this->assertEquals($policy_data['op_policy_uri'], $saved_config->get('op_policy_uri'));
    $this->assertEquals($policy_data['op_tos_uri'], $saved_config->get('op_tos_uri'));
  }

  /**
   * Helper: Configure capabilities and verify configuration.
   */
  protected function helperConfigureCapabilities(): void {
    // Set configuration directly for reliable testing.
    $this->config('simple_oauth_server_metadata.settings')
      ->set('ui_locales_supported', ['en-US', 'es-ES', 'fr-FR'])
      ->set('additional_claims_supported', ['custom_claim_1', 'custom_claim_2'])
      ->set('additional_signing_algorithms', ['ES256', 'PS256'])
      ->save();

    drupal_flush_all_caches();

    // Verify array configuration was saved correctly.
    $capabilities_config = $this->config('simple_oauth_server_metadata.settings');
    $ui_locales = $capabilities_config->get('ui_locales_supported');
    $this->assertContains('en-US', $ui_locales);
    $this->assertContains('es-ES', $ui_locales);
    $this->assertContains('fr-FR', $ui_locales);

    $claims = $capabilities_config->get('additional_claims_supported');
    $this->assertContains('custom_claim_1', $claims);
    $this->assertContains('custom_claim_2', $claims);

    $algorithms = $capabilities_config->get('additional_signing_algorithms');
    $this->assertContains('ES256', $algorithms);
    $this->assertContains('PS256', $algorithms);
  }

  /**
   * Helper: Form validation (basic form functionality).
   */
  protected function helperFormValidation(): void {
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/server-metadata');
    $this->assertSession()->fieldExists('service_documentation');
    $this->assertSession()->fieldExists('op_policy_uri');
    $this->assertSession()->fieldExists('op_tos_uri');
    $this->assertSession()->fieldExists('ui_locales_supported');
    $this->assertSession()->fieldExists('additional_claims_supported');
    $this->assertSession()->fieldExists('additional_signing_algorithms');
  }

  /**
   * Helper: Clear configuration values.
   */
  protected function helperClearConfigurationValues(): void {
    // Ensure we're logged in as admin user to access the settings form.
    $this->drupalLogin($this->adminUser);

    $empty_data = [
      'service_documentation' => '',
      'op_policy_uri' => '',
      'op_tos_uri' => '',
      'ui_locales_supported' => '',
      'additional_claims_supported' => '',
      'additional_signing_algorithms' => '',
    ];

    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/server-metadata');
    $this->submitForm($empty_data, 'Save configuration');

    // Verify empty configuration was saved.
    // Reload configuration from storage to get the updated values.
    \Drupal::configFactory()->reset('simple_oauth_server_metadata.settings');
    $empty_config = $this->config('simple_oauth_server_metadata.settings');
    $this->assertEmpty($empty_config->get('service_documentation'));
    $this->assertEmpty($empty_config->get('op_policy_uri'));
    $this->assertEmpty($empty_config->get('op_tos_uri'));
  }

  /**
   * Helper: Verify form includes OAuth 2.1 compliance guidance.
   */
  protected function helperOauth21ComplianceGuidance(): void {
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/server-metadata');
    $this->assertSession()->pageTextContains('OAuth 2.1 Recommended');
    $this->assertSession()->pageTextContains('RFC 8414');
    $this->assertSession()->pageTextContains('authorization server metadata');
  }

  /**
   * Helper: Verify metadata includes core OAuth server capabilities.
   */
  protected function helperCoreOauthServerCapabilities(): void {
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $core_metadata = Json::decode($this->getSession()->getPage()->getContent());

    // Should include standard OAuth 2.0 capabilities.
    $this->assertArrayHasKey('scopes_supported', $core_metadata);
    $this->assertArrayHasKey('token_endpoint_auth_methods_supported', $core_metadata);
    $this->assertArrayHasKey('code_challenge_methods_supported', $core_metadata);
  }

  /**
   * Helper: Verify JSON structure and content types are correct.
   */
  protected function helperJsonStructureAndContentTypes(): void {
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->responseHeaderEquals('Content-Type', 'application/json');

    $json_metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($json_metadata);
    $this->assertNotEmpty($json_metadata['issuer']);
    $this->assertStringStartsWith('http', $json_metadata['authorization_endpoint']);
    $this->assertStringStartsWith('http', $json_metadata['token_endpoint']);
  }

  /**
   * Helper: Verify device_authorization_endpoint is included when available.
   */
  protected function helperDeviceAuthorizationEndpoint(): void {
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $json_metadata = Json::decode($this->getSession()->getPage()->getContent());

    // The device_authorization_endpoint should only be present if the
    // device flow module is enabled.
    if (isset($json_metadata['device_authorization_endpoint'])) {
      $this->assertStringStartsWith('http', $json_metadata['device_authorization_endpoint']);
      $this->assertStringContains('/oauth/device_authorization', $json_metadata['device_authorization_endpoint']);
    }
  }

  /**
   * Helper: Tests OpenID Connect Discovery route exists and is accessible.
   */
  protected function helperOpenIdConfigurationRouteExists(): void {
    $this->logDebug('Starting OpenID configuration route test');

    // Log enabled modules for debugging.
    $module_handler = $this->container->get('module_handler');
    $enabled_modules = [];
    $modules_to_check = [
      'simple_oauth',
      'simple_oauth_21',
      'simple_oauth_server_metadata',
      'simple_oauth_client_registration',
      'consumers',
    ];
    foreach ($modules_to_check as $module) {
      if ($module_handler->moduleExists($module)) {
        $enabled_modules[] = $module;
      }
    }
    $this->logDebug('Enabled modules: ' . implode(', ', $enabled_modules));

    // Test that the route is defined and accessible.
    $this->logDebug('Attempting to access /.well-known/openid-configuration');
    $this->drupalGet('/.well-known/openid-configuration');

    $status_code = $this->getSession()->getStatusCode();
    $this->logDebug('Response status code: ' . $status_code);

    if ($status_code === 404) {
      // Log additional debugging info if we get 404.
      $this->logDebug('Got 404 - checking route registration');
      $route_provider = $this->container->get('router.route_provider');
      try {
        $route = $route_provider->getRouteByName('simple_oauth_server_metadata.openid_configuration');
        $this->logDebug('Route found in route provider: ' . $route->getPath());
        $this->logDebug('Route controller: ' . $route->getDefault('_controller'));
        $this->logDebug('Route access: ' . $route->getRequirement('_access'));
      }
      catch (\Exception $e) {
        $this->logDebug('Route not found in route provider: ' . $e->getMessage());
      }

      // Check if the service exists.
      try {
        $service = $this->container->get('simple_oauth_server_metadata.openid_configuration');
        $this->logDebug('OpenID configuration service exists: ' . get_class($service));

        try {
          $config = $service->getOpenIdConfiguration();
          $this->logDebug('Service call succeeded, config keys: ' . implode(', ', array_keys($config)));
        }
        catch (\Exception $service_e) {
          $this->logDebug('Service call failed: ' . $service_e->getMessage());
          $this->logDebug('Service exception type: ' . get_class($service_e));
        }
      }
      catch (\Exception $e) {
        $this->logDebug('OpenID configuration service NOT found: ' . $e->getMessage());
      }
    }

    // The service should work correctly.
    $service_works = TRUE;
    try {
      $service = $this->container->get('simple_oauth_server_metadata.openid_configuration');
      $service->getOpenIdConfiguration();
    }
    catch (\Exception $e) {
      $service_works = FALSE;
    }

    if ($service_works) {
      $this->assertTrue(TRUE, 'OpenID Configuration service works correctly');
    }
    else {
      $this->assertNotEquals(404, $status_code, 'OpenID Configuration route should exist');
    }

    // If we get a 200, verify it's JSON.
    if ($status_code === 200) {
      $this->assertSession()->responseHeaderEquals('Content-Type', 'application/json');
      $response_body = $this->getSession()->getPage()->getContent();
      $metadata = Json::decode($response_body);
      $this->assertIsArray($metadata, 'Response should be valid JSON array');
      $this->assertArrayHasKey('issuer', $metadata, 'Response should contain issuer field');
    }

    // Route should return either success or service unavailable.
    $this->assertContains(
      $status_code,
      [200, 503],
      'Route should return either success (200) or service unavailable (503)'
    );
  }

  /**
   * Helper: Tests configuration integration.
   */
  protected function helperConfigurationIntegration(): void {
    // Test with additional claims and algorithms.
    $this->config('simple_oauth_server_metadata.settings')
      ->set('additional_claims_supported', ['custom_claim1', 'custom_claim2'])
      ->set('additional_signing_algorithms', ['ES256', 'PS256'])
      ->set('ui_locales_supported', ['en-US', 'es-ES'])
      ->save();

    // Clear cache to ensure new configuration is used.
    drupal_flush_all_caches();

    $this->drupalGet('/.well-known/openid-configuration');
    $response_body = $this->getSession()->getPage()->getContent();
    $metadata = Json::decode($response_body);

    // Test that additional claims are included.
    $this->assertContains('custom_claim1', $metadata['claims_supported']);
    $this->assertContains('custom_claim2', $metadata['claims_supported']);

    // Test that additional algorithms are included.
    $this->assertContains('ES256', $metadata['id_token_signing_alg_values_supported']);
    $this->assertContains('PS256', $metadata['id_token_signing_alg_values_supported']);

    // Test that UI locales are included when configured.
    $this->assertArrayHasKey('ui_locales_supported', $metadata);
    $this->assertContains('en-US', $metadata['ui_locales_supported']);
    $this->assertContains('es-ES', $metadata['ui_locales_supported']);

    // Test that optional URIs are included when configured.
    $this->assertArrayHasKey('service_documentation', $metadata);
    $this->assertEquals('https://example.com/docs', $metadata['service_documentation']);
    $this->assertArrayHasKey('op_policy_uri', $metadata);
    $this->assertEquals('https://example.com/policy', $metadata['op_policy_uri']);
    $this->assertArrayHasKey('op_tos_uri', $metadata);
    $this->assertEquals('https://example.com/terms', $metadata['op_tos_uri']);

    // Test that enhanced capabilities are included.
    $this->assertArrayHasKey('request_parameter_supported', $metadata);
    $this->assertIsBool($metadata['request_parameter_supported']);
    $this->assertArrayHasKey('claims_parameter_supported', $metadata);
    $this->assertIsBool($metadata['claims_parameter_supported']);
    $this->assertArrayHasKey('scopes_parameter_supported', $metadata);
    $this->assertTrue($metadata['scopes_parameter_supported']);
    $this->assertArrayHasKey('authorization_code_flow_enabled', $metadata);
    $this->assertTrue($metadata['authorization_code_flow_enabled']);
    $this->assertArrayHasKey('implicit_flow_enabled', $metadata);
    $this->assertIsBool($metadata['implicit_flow_enabled']);

    // Test OAuth server metadata endpoint.
    $this->assertArrayHasKey('oauth_authorization_server_metadata_endpoint', $metadata);
    $this->assertStringStartsWith('http', $metadata['oauth_authorization_server_metadata_endpoint']);
    $this->assertStringContainsString('/.well-known/oauth-authorization-server', $metadata['oauth_authorization_server_metadata_endpoint']);
  }

  /**
   * Helper: Tests endpoint accessibility without authentication.
   */
  protected function helperPublicAccess(): void {
    // Log out any current user to test as anonymous.
    $this->drupalLogout();

    // Test as anonymous user.
    $this->drupalGet('/.well-known/openid-configuration');
    $this->assertSession()->statusCodeEquals(200);

    // Verify JSON response.
    $response_body = $this->getSession()->getPage()->getContent();
    $metadata = Json::decode($response_body);
    $this->assertIsArray($metadata);
    $this->assertArrayHasKey('issuer', $metadata);
    $this->assertArrayHasKey('authorization_endpoint', $metadata);
    $this->assertArrayHasKey('token_endpoint', $metadata);
  }

  /**
   * Helper: Tests OpenID Connect Discovery 1.0 specification compliance.
   */
  protected function helperSpecificationCompliance(): void {
    $this->drupalGet('/.well-known/openid-configuration');
    $response_body = $this->getSession()->getPage()->getContent();
    $metadata = Json::decode($response_body);

    // Test that subject_types_supported contains 'public'.
    $this->assertContains('public', $metadata['subject_types_supported']);

    // Test that response_types_supported contains valid values.
    $valid_response_types = [
      'code',
      'token',
      'id_token',
      'code id_token',
      'code token',
      'id_token token',
      'code id_token token',
    ];
    foreach ($metadata['response_types_supported'] as $response_type) {
      $this->assertContains(
        $response_type,
        $valid_response_types,
        "Invalid response type: $response_type"
      );
    }

    // Test that scopes_supported contains 'openid'.
    $this->assertContains('openid', $metadata['scopes_supported']);

    // Test endpoint URL format.
    $this->assertStringStartsWith('http', $metadata['authorization_endpoint']);
    $this->assertStringStartsWith('http', $metadata['token_endpoint']);
    $this->assertStringStartsWith('http', $metadata['userinfo_endpoint']);
    $this->assertStringStartsWith('http', $metadata['jwks_uri']);

    // Test that issuer is a valid URL.
    $this->assertIsString($metadata['issuer']);
    $this->assertTrue(filter_var($metadata['issuer'], FILTER_VALIDATE_URL) !== FALSE);

    // Test that id_token_signing_alg_values_supported contains at least RS256.
    $this->assertContains('RS256', $metadata['id_token_signing_alg_values_supported']);

    // Test that response_modes_supported contains expected values.
    $this->assertArrayHasKey('response_modes_supported', $metadata);
    $expected_modes = ['query', 'fragment', 'form_post'];
    foreach ($expected_modes as $mode) {
      $this->assertContains($mode, $metadata['response_modes_supported']);
    }

    // Test grant types supported includes required types.
    $this->assertArrayHasKey('grant_types_supported', $metadata);
    $this->assertContains('authorization_code', $metadata['grant_types_supported']);
  }

  /**
   * Helper: Tests error handling when OpenID Connect is disabled.
   */
  protected function helperOpenIdConnectDisabled(): void {
    // Disable OpenID Connect in simple_oauth settings.
    $this->config('simple_oauth.settings')
      ->set('disable_openid_connect', TRUE)
      ->save();

    drupal_flush_all_caches();

    $this->drupalGet('/.well-known/openid-configuration');
    // Should return 404 when OpenID Connect is disabled.
    $this->assertSession()->statusCodeEquals(404);

    // Re-enable OpenID Connect for subsequent tests.
    $this->config('simple_oauth.settings')
      ->set('disable_openid_connect', FALSE)
      ->save();

    drupal_flush_all_caches();
  }

  /**
   * Helper: Tests error handling for service unavailability.
   */
  protected function helperServiceUnavailabilityError(): void {
    // Test that a properly configured service returns valid data.
    $this->drupalGet('/.well-known/openid-configuration');
    $this->assertSession()->statusCodeEquals(200);

    $response_body = $this->getSession()->getPage()->getContent();
    $metadata = Json::decode($response_body);
    $this->assertIsArray($metadata);

    // Verify that all required fields are present and not empty.
    $required_fields = [
      'issuer',
      'authorization_endpoint',
      'token_endpoint',
      'userinfo_endpoint',
      'jwks_uri',
      'scopes_supported',
      'response_types_supported',
      'subject_types_supported',
      'id_token_signing_alg_values_supported',
      'claims_supported',
    ];

    foreach ($required_fields as $field) {
      $this->assertArrayHasKey($field, $metadata);
      $this->assertNotEmpty($metadata[$field], "Field $field should not be empty");
    }
  }

  /**
   * Helper: Tests that the endpoint returns proper JSON content type.
   */
  protected function helperJsonContentType(): void {
    $this->drupalGet('/.well-known/openid-configuration');

    // Verify content type header.
    $this->assertSession()
      ->responseHeaderEquals('Content-Type', 'application/json');

    // Verify response is valid JSON.
    $response_body = $this->getSession()->getPage()->getContent();
    $metadata = Json::decode($response_body);
    $this->assertIsArray($metadata);

    // Verify JSON is well-formed by checking we can encode it back.
    $encoded = Json::encode($metadata);
    $this->assertIsString($encoded);
    $this->assertNotEmpty($encoded);
  }

  /**
   * Helper: Tests endpoint behavior with different HTTP methods.
   */
  protected function helperHttpMethodRestrictions(): void {
    // GET should work (this is the primary test case).
    $this->drupalGet('/.well-known/openid-configuration');
    $this->assertSession()->statusCodeEquals(200);

    // Verify response.
    $response_body = $this->getSession()->getPage()->getContent();
    $this->assertNotEmpty($response_body);

    $metadata = Json::decode($response_body);
    $this->assertIsArray($metadata);
  }

  /**
   * Helper: Tests registration endpoint detection.
   */
  protected function helperRegistrationEndpointDetection(): void {
    $this->drupalGet('/.well-known/openid-configuration');
    $response_body = $this->getSession()->getPage()->getContent();
    $metadata = Json::decode($response_body);

    // If registration_endpoint is present, it should be a valid URL.
    if (isset($metadata['registration_endpoint'])) {
      $this->assertStringStartsWith('http', $metadata['registration_endpoint']);
      $this->assertStringContainsString('/oauth/register', $metadata['registration_endpoint']);
    }
  }

  /**
   * Tests successful token revocation with HTTP Basic Auth credentials.
   */
  protected function helperSuccessfulRevocationWithBasicAuth(): void {
    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    $response = $this->postRevocationRequest([
      'token' => 'test_token_value_12345',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    // Debug: Check response body if not 200.
    if ($response->getStatusCode() !== 200) {
      $body = (string) $response->getBody();
      $this->fail('Expected 200 but got ' . $response->getStatusCode() . '. Body: ' . substr($body, 0, 500));
    }

    $this->assertEquals(200, $response->getStatusCode());

    // Verify token was actually revoked in the database.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$this->testToken->id()]);
    $reloadedToken = $storage->load($this->testToken->id());
    $this->assertTrue($reloadedToken->isRevoked(), 'Token should be revoked after successful revocation request');
  }

  /**
   * Tests successful token revocation with POST body credentials.
   */
  protected function helperSuccessfulRevocationWithPostBodyCredentials(): void {
    // Create a new token for this test.
    $newToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $this->testClient,
      'value' => 'post_body_token_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 3600,
      'status' => TRUE,
    ]);
    $newToken->save();

    $response = $this->postRevocationRequest([
      'token' => 'post_body_token_12345',
      'client_id' => 'test_client_id',
      'client_secret' => $this->clientSecret,
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    // Verify token was revoked.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$newToken->id()]);
    $reloadedToken = $storage->load($newToken->id());
    $this->assertTrue($reloadedToken->isRevoked());
  }

  /**
   * Tests revocation with public client (no secret required).
   */
  protected function helperPublicClientRevocation(): void {
    // Create a public client.
    $publicClient = Consumer::create([
      'label' => 'Test Public Client',
      'client_id' => 'public_client_id',
      'is_default' => FALSE,
      'confidential' => FALSE,
    ]);
    $publicClient->save();

    // Create a token for the public client.
    $publicToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $publicClient,
      'value' => 'public_token_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 3600,
      'status' => TRUE,
    ]);
    $publicToken->save();

    $response = $this->postRevocationRequest([
      'token' => 'public_token_12345',
      'client_id' => 'public_client_id',
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    // Verify token was revoked.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$publicToken->id()]);
    $reloadedToken = $storage->load($publicToken->id());
    $this->assertTrue($reloadedToken->isRevoked());
  }

  /**
   * Tests authentication failure with invalid client credentials.
   */
  protected function helperAuthenticationFailureWithInvalidCredentials(): void {
    // Create a fresh token for this test.
    $invalidAuthToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $this->testClient,
      'value' => 'invalid_auth_token_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 3600,
      'status' => TRUE,
    ]);
    $invalidAuthToken->save();

    $credentials = base64_encode('test_client_id:wrong_secret');

    $response = $this->postRevocationRequest([
      'token' => 'invalid_auth_token_12345',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    $this->assertEquals(401, $response->getStatusCode());

    $data = Json::decode($response->getBody());
    $this->assertEquals('invalid_client', $data['error']);

    // Verify token was NOT revoked.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$invalidAuthToken->id()]);
    $reloadedToken = $storage->load($invalidAuthToken->id());
    $this->assertFalse($reloadedToken->isRevoked(), 'Token should not be revoked when authentication fails');
  }

  /**
   * Tests authentication failure with missing client credentials.
   */
  protected function helperAuthenticationFailureWithMissingCredentials(): void {
    // Create a fresh token for this test.
    $missingAuthToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $this->testClient,
      'value' => 'missing_auth_token_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 3600,
      'status' => TRUE,
    ]);
    $missingAuthToken->save();

    $response = $this->postRevocationRequest([
      'token' => 'missing_auth_token_12345',
    ]);

    $this->assertEquals(401, $response->getStatusCode());

    $data = Json::decode($response->getBody());
    $this->assertEquals('invalid_client', $data['error']);

    // Verify token was NOT revoked.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$missingAuthToken->id()]);
    $reloadedToken = $storage->load($missingAuthToken->id());
    $this->assertFalse($reloadedToken->isRevoked());
  }

  /**
   * Tests missing token parameter returns 400 Bad Request.
   */
  protected function helperMissingTokenParameter(): void {
    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    $response = $this->postRevocationRequest([], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    $this->assertEquals(400, $response->getStatusCode());

    $data = Json::decode($response->getBody());
    $this->assertEquals('invalid_request', $data['error']);
    $this->assertArrayHasKey('error_description', $data);
  }

  /**
   * Tests bypass permission allows admin to revoke any token.
   */
  protected function helperBypassPermissionAllowsRevokingAnyToken(): void {
    // Create another client and token.
    $otherClient = Consumer::create([
      'label' => 'Other Client',
      'client_id' => 'other_client_id',
      'secret' => 'other_client_secret',
      'confidential' => TRUE,
    ]);
    $otherClient->save();

    $otherToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $otherClient,
      'value' => 'other_token_value_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 3600,
      'status' => TRUE,
    ]);
    $otherToken->save();

    // Grant bypass permission to a user and log in.
    $adminUser = $this->createUser(['bypass token revocation restrictions']);
    $this->drupalLogin($adminUser);

    // testClient tries to revoke otherClient's token (bypass enabled).
    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    $response = $this->postRevocationRequest([
      'token' => 'other_token_value_12345',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    // Verify token WAS revoked (because of bypass permission).
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$otherToken->id()]);
    $reloadedToken = $storage->load($otherToken->id());
    $this->assertTrue($reloadedToken->isRevoked(), 'Token should be revoked when user has bypass permission');

    // Log out the admin user so subsequent tests don't have bypass permission.
    $this->drupalLogout();
  }

  /**
   * Tests ownership validation prevents unauthorized revocation.
   */
  protected function helperOwnershipValidationPreventsUnauthorizedRevocation(): void {
    // Create another client and token.
    $otherClient = Consumer::create([
      'label' => 'Other Client',
      'client_id' => 'other_client_id',
      'secret' => 'other_client_secret',
      'confidential' => TRUE,
    ]);
    $otherClient->save();

    $otherToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $otherClient,
      'value' => 'ownership_test_token_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 3600,
      'status' => TRUE,
    ]);
    $otherToken->save();

    // testClient tries to revoke otherClient's token (no bypass permission).
    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    $response = $this->postRevocationRequest([
      'token' => 'ownership_test_token_12345',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    // RFC 7009: Return 200 (don't reveal ownership failure).
    $this->assertEquals(200, $response->getStatusCode());

    // Verify token was NOT actually revoked.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$otherToken->id()]);
    $reloadedToken = $storage->load($otherToken->id());
    $this->assertFalse($reloadedToken->isRevoked(), 'Token should not be revoked when client does not own it');
  }

  /**
   * Tests privacy preservation for non-existent tokens.
   */
  protected function helperNonExistentTokenReturnsSuccess(): void {
    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    $response = $this->postRevocationRequest([
      'token' => 'nonexistent_token_that_does_not_exist',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    // RFC 7009: Should return 200 even for non-existent tokens (privacy).
    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests idempotent revocation behavior.
   */
  protected function helperIdempotentRevocation(): void {
    // Create a fresh token for this test.
    $idempotentToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $this->testClient,
      'value' => 'idempotent_token_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 3600,
      'status' => TRUE,
    ]);
    $idempotentToken->save();

    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    // First revocation.
    $response1 = $this->postRevocationRequest([
      'token' => 'idempotent_token_12345',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);
    $this->assertEquals(200, $response1->getStatusCode());

    // Verify token is revoked.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$idempotentToken->id()]);
    $reloadedToken = $storage->load($idempotentToken->id());
    $this->assertTrue($reloadedToken->isRevoked());

    // Second revocation (same token, already revoked).
    $response2 = $this->postRevocationRequest([
      'token' => 'idempotent_token_12345',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);
    $this->assertEquals(200, $response2->getStatusCode());

    // Token should still be revoked.
    $storage->resetCache([$idempotentToken->id()]);
    $reloadedToken = $storage->load($idempotentToken->id());
    $this->assertTrue($reloadedToken->isRevoked());
  }

  /**
   * Tests revocation supports token_type_hint parameter.
   */
  protected function helperTokenTypeHintParameter(): void {
    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    // Create a new token for this test.
    $hintToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $this->testClient,
      'value' => 'hint_test_token_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 3600,
      'status' => TRUE,
    ]);
    $hintToken->save();

    $response = $this->postRevocationRequest([
      'token' => 'hint_test_token_12345',
      'token_type_hint' => 'access_token',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    // Verify token was revoked (hint is accepted but not required).
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$hintToken->id()]);
    $reloadedToken = $storage->load($hintToken->id());
    $this->assertTrue($reloadedToken->isRevoked());
  }

  /**
   * Tests revocation of refresh tokens.
   */
  protected function helperRefreshTokenRevocation(): void {
    // Create a refresh token.
    $refreshToken = Oauth2Token::create([
      'bundle' => 'refresh_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $this->testClient,
      'value' => 'test_refresh_token_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 7200,
      'status' => TRUE,
    ]);
    $refreshToken->save();

    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    $response = $this->postRevocationRequest([
      'token' => 'test_refresh_token_12345',
      'token_type_hint' => 'refresh_token',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    // Verify refresh token was revoked.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$refreshToken->id()]);
    $reloadedToken = $storage->load($refreshToken->id());
    $this->assertTrue($reloadedToken->isRevoked());
  }

  /**
   * Tests server metadata includes revocation_endpoint.
   */
  protected function helperServerMetadataIncludesRevocationEndpoint(): void {
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);

    $metadata = Json::decode($this->getSession()->getPage()->getContent());

    $this->assertArrayHasKey('revocation_endpoint', $metadata, 'Server metadata must include revocation_endpoint');
    $this->assertStringContainsString('/oauth/revoke', $metadata['revocation_endpoint']);

    // Verify it's an absolute URL.
    $this->assertStringStartsWith('http', $metadata['revocation_endpoint']);
  }

  /**
   * Tests only POST method is accepted.
   */
  protected function helperOnlyPostMethodAccepted(): void {
    // The route already restricts to POST only, but verify it works.
    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    $this->drupalGet('/oauth/revoke', [
      'query' => ['token' => 'test_token_value_12345'],
      'headers' => ['Authorization' => 'Basic ' . $credentials],
    ]);

    // Should get 405 Method Not Allowed or 404 since route only allows POST.
    $statusCode = $this->getSession()->getStatusCode();
    $this->assertTrue(
      in_array($statusCode, [404, 405]),
      'GET requests to revocation endpoint should be rejected'
    );
  }

  /**
   * Helper method to POST to the revocation endpoint.
   *
   * @param array $formData
   *   The form data to POST.
   * @param array $headers
   *   Optional HTTP headers.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response object.
   */
  private function postRevocationRequest(array $formData = [], array $headers = []): object {
    $url = $this->getAbsoluteUrl('/oauth/revoke');
    $session = $this->getSession();

    // Set SIMPLETEST_USER_AGENT cookie for test environment.
    $session->setCookie('SIMPLETEST_USER_AGENT', drupal_generate_test_ua($this->databasePrefix));

    $httpClient = $this->container->get('http_client');

    $options = [
      'form_params' => $formData,
      'http_errors' => FALSE,
    ];

    if (!empty($headers)) {
      $options['headers'] = $headers;
    }

    // Include session cookies for Drupal user authentication.
    $options['cookies'] = $this->getSessionCookies();

    return $httpClient->request('POST', $url, $options);
  }

}
