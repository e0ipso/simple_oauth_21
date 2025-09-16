<?php

namespace Drupal\Tests\simple_oauth_pkce\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests PKCE configuration functionality.
 *
 * @group simple_oauth_pkce
 */
class PkceConfigurationFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'consumers',
    'image',
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_pkce',
    'serialization',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests PKCE configuration and validation.
   */
  public function testPkceConfiguration(): void {
    // Test 1: Module installation and configuration defaults
    $config = $this->config('simple_oauth_pkce.settings');
    $this->assertNotNull($config);

    // Test 2: Configuration form access and permissions
    $admin_user = $this->drupalCreateUser([
      'administer simple_oauth entities',
    ]);

    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/pkce');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($admin_user);
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/pkce');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('PKCE enforcement');

    // Test 3: Configuration validation and saving
    $form_data = [
      'enforcement' => 'mandatory',
      's256_enabled' => TRUE,
      'plain_enabled' => FALSE,
    ];

    $this->submitForm($form_data, 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved');

    // Verify configuration was saved
    $saved_config = $this->config('simple_oauth_pkce.settings');
    $this->assertEquals('mandatory', $saved_config->get('enforcement'));
    $this->assertTrue($saved_config->get('s256_enabled'));
    $this->assertFalse($saved_config->get('plain_enabled'));

    // Test 4: Configuration schema validation
    $config_schema = $this->container->get('config.typed')->get('simple_oauth_pkce.settings');
    $this->assertNotNull($config_schema);

    // Test 5: Form help text and documentation
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/pkce');
    $this->assertSession()->pageTextContains('RFC 7636');
    $this->assertSession()->pageTextContains('OAuth 2.1');
    $this->assertSession()->pageTextContains('PKCE');

    // Test 6: Field validation
    $this->assertSession()->fieldExists('enforcement');
    $this->assertSession()->fieldExists('s256_enabled');
    $this->assertSession()->fieldExists('plain_enabled');
  }

}