<?php

namespace Drupal\Tests\simple_oauth_server_metadata\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests OAuth 2.0 server metadata functionality and RFC 8414 compliance.
 *
 * @group simple_oauth_server_metadata
 */
class ServerMetadataFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_server_metadata',
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->adminUser = $this->drupalCreateUser([
      'administer simple_oauth entities',
    ]);
  }

  /**
   * Tests comprehensive server metadata functionality.
   */
  public function testComprehensiveServerMetadataFunctionality(): void {
    // Test 1: Well-known endpoint accessibility without authentication.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('Content-Type', 'application/json');

    $response_body = $this->getSession()->getPage()->getContent();
    $metadata = Json::decode($response_body);

    // Verify required RFC 8414 fields are present.
    $this->assertArrayHasKey('issuer', $metadata);
    $this->assertArrayHasKey('authorization_endpoint', $metadata);
    $this->assertArrayHasKey('token_endpoint', $metadata);
    $this->assertArrayHasKey('response_types_supported', $metadata);
    $this->assertArrayHasKey('grant_types_supported', $metadata);

    // Verify OAuth 2.1 required fields.
    $this->assertContains('code', $metadata['response_types_supported']);
    $this->assertContains('authorization_code', $metadata['grant_types_supported']);

    // Test 2: Settings form access and permissions.
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/server-metadata');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/server-metadata');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Configure additional RFC 8414');

    // Test 3: Configure policy URLs and verify form saves configuration.
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

    // Test 4: Configure capabilities and verify configuration.
    $capabilities_data = [
      'ui_locales_supported' => "en-US\nes-ES\nfr-FR",
      'additional_claims_supported' => "custom_claim_1\ncustom_claim_2",
      'additional_signing_algorithms' => "ES256\nPS256",
    ];

    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/server-metadata');
    $this->submitForm($capabilities_data, 'Save configuration');

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

    // Test 5: Form validation (basic form functionality)
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/server-metadata');
    $this->assertSession()->fieldExists('service_documentation');
    $this->assertSession()->fieldExists('op_policy_uri');
    $this->assertSession()->fieldExists('op_tos_uri');
    $this->assertSession()->fieldExists('ui_locales_supported');
    $this->assertSession()->fieldExists('additional_claims_supported');
    $this->assertSession()->fieldExists('additional_signing_algorithms');

    // Test 6: Clear configuration values.
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
    $empty_config = $this->config('simple_oauth_server_metadata.settings');
    $this->assertEmpty($empty_config->get('service_documentation'));
    $this->assertEmpty($empty_config->get('op_policy_uri'));
    $this->assertEmpty($empty_config->get('op_tos_uri'));

    // Test 7: Verify form includes OAuth 2.1 compliance guidance.
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/server-metadata');
    $this->assertSession()->pageTextContains('OAuth 2.1 Recommended');
    $this->assertSession()->pageTextContains('RFC 8414');
    $this->assertSession()->pageTextContains('authorization server metadata');

    // Test 8: Verify metadata includes core OAuth server capabilities.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $core_metadata = Json::decode($this->getSession()->getPage()->getContent());

    // Should include standard OAuth 2.0 capabilities.
    $this->assertArrayHasKey('scopes_supported', $core_metadata);
    $this->assertArrayHasKey('token_endpoint_auth_methods_supported', $core_metadata);
    $this->assertArrayHasKey('code_challenge_methods_supported', $core_metadata);

    // Test 9: Verify JSON structure and content types are correct.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->responseHeaderEquals('Content-Type', 'application/json');

    $json_metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($json_metadata);
    $this->assertNotEmpty($json_metadata['issuer']);
    $this->assertStringStartsWith('http', $json_metadata['authorization_endpoint']);
    $this->assertStringStartsWith('http', $json_metadata['token_endpoint']);
  }

}
