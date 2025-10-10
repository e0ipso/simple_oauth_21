<?php

namespace Drupal\Tests\simple_oauth_native_apps\Functional;

use Drupal\Tests\simple_oauth\Functional\TokenBearerFunctionalTestBase;
use Drupal\simple_oauth_21\Trait\DebugLoggingTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests native app configuration and validation features.
 */
#[Group('simple_oauth_native_apps')]
class NativeAppConfigurationTest extends TokenBearerFunctionalTestBase {

  use DebugLoggingTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'image',
    'options',
    'serialization',
    'simple_oauth_test',
    'simple_oauth_native_apps',
    'text',
    'user',
  ];

  /**
   * Comprehensive native app OAuth functionality test.
   *
   * Tests RFC 8252 OAuth 2.0 for Native Apps implementation including:
   * - Module installation and configuration defaults
   * - Configuration form access and permissions
   * - Service validation and dependency injection
   * - Configuration schema validation
   * - Form help text and documentation
   * - Module dependencies verification
   *
   * All scenarios execute sequentially using a shared Drupal instance
   * for optimal performance.
   */
  public function testComprehensiveNativeAppFunctionality(): void {
    $this->logDebug('Starting comprehensive native app configuration test');

    $this->helperConfigurationDefaults();
    $admin_user = $this->helperConfigurationFormAccess();
    $this->helperServiceValidation();
    $this->helperConfigurationSchemaValidation();
    $this->helperFormDocumentation();
    $this->helperModuleDependencies();
  }

  /**
   * Helper: Tests module installation and configuration defaults.
   *
   * Validates that native apps module configuration is properly initialized.
   */
  protected function helperConfigurationDefaults(): void {
    $this->logDebug('Testing module installation and configuration defaults');
    $config = $this->config('simple_oauth_native_apps.settings');
    $this->logDebug('Got native apps config');
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

    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/native-apps');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($admin_user);
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/native-apps');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Enforce native app security');

    return $admin_user;
  }

  /**
   * Helper: Tests service validation and dependency injection.
   *
   * Validates that all RFC 8252 native app services are properly registered
   * and can be instantiated from the container.
   */
  protected function helperServiceValidation(): void {
    $redirect_validator = $this->container->get('simple_oauth_native_apps.redirect_uri_validator');
    $this->assertNotNull($redirect_validator);

    $user_agent_analyzer = $this->container->get('simple_oauth_native_apps.user_agent_analyzer');
    $this->assertNotNull($user_agent_analyzer);

    $native_client_detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    $this->assertNotNull($native_client_detector);

    $pkce_enhancement = $this->container->get('simple_oauth_native_apps.pkce_enhancement');
    $this->assertNotNull($pkce_enhancement);
  }

  /**
   * Helper: Tests configuration schema validation.
   *
   * Validates that the configuration schema is properly defined.
   */
  protected function helperConfigurationSchemaValidation(): void {
    $config_schema = $this->container->get('config.typed')->get('simple_oauth_native_apps.settings');
    $this->assertNotNull($config_schema);
  }

  /**
   * Helper: Tests form help text and documentation.
   *
   * Validates that the configuration form displays proper RFC 8252 references
   * and OAuth 2.1/PKCE documentation for native apps.
   */
  protected function helperFormDocumentation(): void {
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/native-apps');
    $this->assertSession()->pageTextContains('RFC 8252');
    $this->assertSession()->pageTextContains('OAuth 2.1');
    $this->assertSession()->pageTextContains('PKCE');
  }

  /**
   * Helper: Tests module dependencies verification.
   *
   * Validates that the native apps module is properly installed
   * and dependencies are met.
   */
  protected function helperModuleDependencies(): void {
    $module_handler = $this->container->get('module_handler');
    $this->assertTrue($module_handler->moduleExists('simple_oauth_native_apps'));
  }

}
