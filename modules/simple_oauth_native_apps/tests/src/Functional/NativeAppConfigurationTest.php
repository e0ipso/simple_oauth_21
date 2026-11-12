<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_native_apps\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\Tests\simple_oauth\Functional\TokenBearerFunctionalTestBase;
use Drupal\simple_oauth_21\Trait\DebugLoggingTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests native app configuration and validation features.
 */
#[Group('simple_oauth_native_apps')]
final class NativeAppConfigurationTest extends TokenBearerFunctionalTestBase {

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
   * - Module dependencies verification.
   *
   * All scenarios execute sequentially using a shared Drupal instance
   * for optimal performance.
   */
  public function testComprehensiveNativeAppFunctionality(): void {
    $this->logDebug('Starting comprehensive native app configuration test');

    $this->helperConfigurationDefaults();
    $this->helperConfigurationFormAccess();
    $this->helperServiceValidation();
    $this->helperConfigurationSchemaValidation();
    $this->helperFormDocumentation();
    $this->helperModuleDependencies();
  }

  /**
   * Tests malformed PKCE parameters through the authorization and token routes.
   *
   * Both native policies must reject array parameters before grant processing,
   * with an OAuth JSON response that cannot be cached after kernel handling.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   If an HTTP request fails.
   */
  public function testMalformedNativePkceParameters(): void {
    $this->client->set('native_app_override', 'native')->save();
    $authorization_url = Url::fromRoute('oauth2_token.authorize');
    $authorization_parameters = [
      'client_id' => $this->client->getClientId(),
      'response_type' => 'code',
      'redirect_uri' => $this->redirectUri,
      'code_challenge' => str_repeat('a', 43),
      'code_challenge_method' => 'S256',
    ];
    $token_parameters = [
      'client_id' => $this->client->getClientId(),
      'grant_type' => 'authorization_code',
      'redirect_uri' => $this->redirectUri,
      'code' => 'unused-malformed-pkce-code',
    ];

    foreach (['enhanced', 'auto-detect'] as $policy) {
      $this->config('simple_oauth_native_apps.settings')
        ->set('native.enhanced_pkce', $policy)
        ->save();
      foreach (['code_challenge', 'code_challenge_method', 'code_verifier'] as $parameter) {
        foreach (['list' => ['S256'], 'nested' => ['value' => ['S256']]] as $shape => $value) {
          $message = "$policy: $parameter ($shape)";
          if ($parameter === 'code_verifier') {
            $response = $this->post($this->url, [$parameter => $value] + $token_parameters);
          }
          else {
            $response = $this->get($authorization_url, [
              'query' => [$parameter => $value] + $authorization_parameters,
              'allow_redirects' => FALSE,
            ]);
          }

          $this->assertSame(400, $response->getStatusCode(), $message);
          $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), $message);
          $error = Json::decode((string) $response->getBody());
          $this->assertSame('invalid_request', $error['error'], $message);
          $this->assertStringContainsString("$parameter parameter must be a string", $error['error_description'], $message);
          $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'), $message);
          $this->assertSame('no-cache', $response->getHeaderLine('Pragma'), $message);
        }
      }
    }
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
