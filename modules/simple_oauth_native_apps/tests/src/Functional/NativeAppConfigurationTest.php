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
   * Tests native app configuration and services.
   */
  public function testNativeAppConfiguration(): void {
    $this->logDebug('Starting native app configuration test');

    // Test 1: Module installation and configuration defaults.
    $this->logDebug('Testing module installation and configuration defaults');
    $config = $this->config('simple_oauth_native_apps.settings');
    $this->logDebug('Got native apps config');
    $this->assertNotNull($config);

    // Test 2: Configuration form access and permissions.
    $admin_user = $this->drupalCreateUser([
      'administer simple_oauth entities',
    ]);

    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/native-apps');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($admin_user);
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/native-apps');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Enforce native app security');

    // Test 3: Service validation and dependency injection.
    $redirect_validator = $this->container->get('simple_oauth_native_apps.redirect_uri_validator');
    $this->assertNotNull($redirect_validator);

    $user_agent_analyzer = $this->container->get('simple_oauth_native_apps.user_agent_analyzer');
    $this->assertNotNull($user_agent_analyzer);

    $native_client_detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    $this->assertNotNull($native_client_detector);

    $pkce_enhancement = $this->container->get('simple_oauth_native_apps.pkce_enhancement');
    $this->assertNotNull($pkce_enhancement);

    // Test 4: Configuration schema validation.
    $config_schema = $this->container->get('config.typed')->get('simple_oauth_native_apps.settings');
    $this->assertNotNull($config_schema);

    // Test 5: Form help text and documentation.
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21/native-apps');
    $this->assertSession()->pageTextContains('RFC 8252');
    $this->assertSession()->pageTextContains('OAuth 2.1');
    $this->assertSession()->pageTextContains('PKCE');

    // Test 6: Verify module info and dependencies.
    $module_handler = $this->container->get('module_handler');
    $this->assertTrue($module_handler->moduleExists('simple_oauth_native_apps'));
  }

}
