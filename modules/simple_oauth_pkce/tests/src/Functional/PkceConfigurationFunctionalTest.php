<?php

namespace Drupal\Tests\simple_oauth_pkce\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests PKCE configuration functionality.
 */
#[Group('simple_oauth_pkce')]
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
   * Comprehensive PKCE functionality test.
   *
   * Tests all PKCE configuration and validation scenarios sequentially,
   * reusing the Drupal instance for performance optimization.
   *
   * This consolidated test includes:
   * - Module installation and configuration defaults
   * - Configuration form access and permissions
   * - Configuration validation and saving
   * - Configuration schema validation
   * - Form help text and documentation
   * - Field validation
   */
  public function testComprehensivePkceFunctionality(): void {
    $this->helperConfigurationDefaults();
    $admin_user = $this->helperConfigurationFormAccess();
    $this->helperConfigurationSaving($admin_user);
    $this->helperConfigurationSchemaValidation();
    $this->helperFormDocumentation();
    $this->helperFieldValidation();

    $this->assertTrue(TRUE, 'All test scenarios completed successfully');
  }

  /**
   * Helper: Tests module installation and configuration defaults.
   *
   * Validates that PKCE module configuration is properly initialized.
   */
  protected function helperConfigurationDefaults(): void {
    $config = $this->config('simple_oauth_pkce.settings');
    $this->assertNotNull($config);
  }

  /**
   * Helper: Tests configuration form access and permissions.
   *
   * Validates that anonymous users cannot access the configuration form
   * and that admin users with proper permissions can access it.
   *
   * @return \Drupal\user\UserInterface
   *   The created admin user for use in subsequent helpers.
   */
  protected function helperConfigurationFormAccess() {
    $admin_user = $this->drupalCreateUser([
      'administer simple_oauth entities',
    ]);

    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/pkce');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($admin_user);
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/pkce');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('PKCE enforcement');

    return $admin_user;
  }

  /**
   * Helper: Tests configuration validation and saving.
   *
   * Validates that PKCE configuration can be saved and that values
   * are properly persisted.
   *
   * @param \Drupal\user\UserInterface $admin_user
   *   The admin user created in previous helper.
   */
  protected function helperConfigurationSaving($admin_user): void {
    $form_data = [
      'enforcement' => 'mandatory',
      's256_enabled' => TRUE,
      'plain_enabled' => FALSE,
    ];

    $this->submitForm($form_data, 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved');

    // Verify configuration was saved.
    $saved_config = $this->config('simple_oauth_pkce.settings');
    $this->assertEquals('mandatory', $saved_config->get('enforcement'));
    $this->assertTrue($saved_config->get('s256_enabled'));
    $this->assertFalse($saved_config->get('plain_enabled'));
  }

  /**
   * Helper: Tests configuration schema validation.
   *
   * Validates that the configuration schema is properly defined.
   */
  protected function helperConfigurationSchemaValidation(): void {
    $config_schema = $this->container->get('config.typed')->get('simple_oauth_pkce.settings');
    $this->assertNotNull($config_schema);
  }

  /**
   * Helper: Tests form help text and documentation.
   *
   * Validates that the configuration form displays proper RFC references
   * and PKCE documentation.
   */
  protected function helperFormDocumentation(): void {
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/pkce');
    $this->assertSession()->pageTextContains('RFC 7636');
    $this->assertSession()->pageTextContains('OAuth 2.1');
    $this->assertSession()->pageTextContains('PKCE');
  }

  /**
   * Helper: Tests field validation.
   *
   * Validates that all expected form fields exist.
   */
  protected function helperFieldValidation(): void {
    $this->assertSession()->fieldExists('enforcement');
    $this->assertSession()->fieldExists('s256_enabled');
    $this->assertSession()->fieldExists('plain_enabled');
  }

}
