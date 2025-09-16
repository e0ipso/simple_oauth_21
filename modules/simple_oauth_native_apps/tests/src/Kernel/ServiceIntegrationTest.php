<?php

namespace Drupal\Tests\simple_oauth_native_apps\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth_native_apps\Service\ConfigurationValidator;
use Drupal\simple_oauth_native_apps\Service\MetadataProvider;
use Drupal\simple_oauth_native_apps\Service\NativeClientDetector;
use Drupal\simple_oauth_native_apps\Service\PKCEEnhancementService;
use Drupal\simple_oauth_native_apps\Service\RedirectUriValidator;
use Drupal\simple_oauth_native_apps\Service\UserAgentAnalyzer;

/**
 * Tests service integration for native apps module.
 *
 * @group simple_oauth_native_apps
 */
class ServiceIntegrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'consumers',
    'simple_oauth',
    'simple_oauth_native_apps',
    'serialization',
    'options',
    'image',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    $this->installConfig(['simple_oauth', 'simple_oauth_native_apps']);
  }

  /**
   * Tests that all services are properly registered and injectable.
   *
   * @covers \Drupal\simple_oauth_native_apps\Service\RedirectUriValidator
   * @covers \Drupal\simple_oauth_native_apps\Service\UserAgentAnalyzer
   * @covers \Drupal\simple_oauth_native_apps\Service\NativeClientDetector
   * @covers \Drupal\simple_oauth_native_apps\Service\PKCEEnhancementService
   * @covers \Drupal\simple_oauth_native_apps\Service\ConfigurationValidator
   * @covers \Drupal\simple_oauth_native_apps\Service\MetadataProvider
   */
  public function testServiceRegistration(): void {
    $container = $this->container;

    // Test redirect URI validator service.
    $redirect_validator = $container->get('simple_oauth_native_apps.redirect_uri_validator');
    $this->assertInstanceOf(RedirectUriValidator::class, $redirect_validator);

    // Test user agent analyzer service.
    $user_agent_analyzer = $container->get('simple_oauth_native_apps.user_agent_analyzer');
    $this->assertInstanceOf(UserAgentAnalyzer::class, $user_agent_analyzer);

    // Test native client detector service.
    $native_detector = $container->get('simple_oauth_native_apps.native_client_detector');
    $this->assertInstanceOf(NativeClientDetector::class, $native_detector);

    // Test PKCE enhancement service.
    $pkce_service = $container->get('simple_oauth_native_apps.pkce_enhancement');
    $this->assertInstanceOf(PKCEEnhancementService::class, $pkce_service);

    // Test configuration validator service.
    $config_validator = $container->get('simple_oauth_native_apps.configuration_validator');
    $this->assertInstanceOf(ConfigurationValidator::class, $config_validator);

    // Test metadata provider service.
    $metadata_provider = $container->get('simple_oauth_native_apps.metadata_provider');
    $this->assertInstanceOf(MetadataProvider::class, $metadata_provider);
  }

  /**
   * Tests service integration with configuration.
   */
  public function testServiceConfigurationIntegration(): void {
    $config_factory = $this->container->get('config.factory');
    $config = $config_factory->getEditable('simple_oauth_native_apps.settings');

    // Update configuration.
    $config->set('allow_custom_uri_schemes', TRUE);
    $config->set('allow_loopback_redirects', TRUE);
    $config->set('enforce_native_security', TRUE);
    $config->set('webview_detection', 'warn');
    $config->save();

    $redirect_validator = $this->container->get('simple_oauth_native_apps.redirect_uri_validator');
    $user_agent_analyzer = $this->container->get('simple_oauth_native_apps.user_agent_analyzer');

    // Test that services respond to configuration changes.
    $this->assertTrue($redirect_validator->validateCustomScheme('myapp://callback'));
    $this->assertEquals('warn', $user_agent_analyzer->getDetectionMode());
  }

  /**
   * Tests integration between native client detector and PKCE enhancement.
   */
  public function testNativeDetectorPkceIntegration(): void {
    /** @var \Drupal\simple_oauth_native_apps\Service\NativeClientDetector $native_detector */
    $native_detector = $this->container->get('simple_oauth_native_apps.native_client_detector');

    /** @var \Drupal\simple_oauth_native_apps\Service\PKCEEnhancementService $pkce_service */
    $pkce_service = $this->container->get('simple_oauth_native_apps.pkce_enhancement');

    // Create a test consumer.
    $consumer = Consumer::create([
      'label' => 'Test Native App',
      'client_id' => 'test_native_client',
      'client_secret' => 'secret',
      'confidential' => FALSE,
      'third_party' => TRUE,
      'redirect' => [
        'myapp://callback',
        'com.example.app://auth',
      ],
    ]);
    $consumer->save();

    // Test that native detection affects PKCE requirements.
    $is_native = $native_detector->isNativeClient($consumer);
    $requires_enhanced = $native_detector->requiresEnhancedPkce($consumer);
    $requires_s256 = $pkce_service->requiresS256Method($consumer);

    $this->assertTrue($is_native);
    $this->assertTrue($requires_enhanced);
    $this->assertTrue($requires_s256);

    // Test PKCE validation with native client.
    $validation_result = $pkce_service->validatePkceParameters(
      $consumer,
      'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
      'S256'
    );

    $this->assertTrue($validation_result['valid']);
    $this->assertTrue($validation_result['enhanced_applied']);
  }

  /**
   * Tests user agent analyzer integration.
   */
  public function testUserAgentAnalyzerIntegration(): void {
    /** @var \Drupal\simple_oauth_native_apps\Service\UserAgentAnalyzer $analyzer */
    $analyzer = $this->container->get('simple_oauth_native_apps.user_agent_analyzer');

    // Test webview detection.
    $facebook_ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBDV/iPhone14,5]';
    $safari_ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1';

    $this->assertTrue($analyzer->isEmbeddedWebview($facebook_ua));
    $this->assertFalse($analyzer->isEmbeddedWebview($safari_ua));

    $webview_type = $analyzer->getWebviewType($facebook_ua);
    $this->assertNotNull($webview_type);
    $this->assertEquals('social_media', $webview_type['category']);
  }

  /**
   * Tests redirect URI validator with configuration changes.
   */
  public function testRedirectUriValidatorConfigIntegration(): void {
    /** @var \Drupal\simple_oauth_native_apps\Service\RedirectUriValidator $validator */
    $validator = $this->container->get('simple_oauth_native_apps.redirect_uri_validator');

    $config = $this->container->get('config.factory')->getEditable('simple_oauth_native_apps.settings');

    // Test with custom schemes enabled.
    $config->set('allow_custom_uri_schemes', TRUE)->save();
    $this->assertTrue($validator->validateCustomScheme('myapp://callback'));

    // Test with custom schemes disabled.
    $config->set('allow_custom_uri_schemes', FALSE)->save();
    $this->assertFalse($validator->validateCustomScheme('myapp://callback'));

    // Test with loopback enabled.
    $config->set('allow_loopback_redirects', TRUE)->save();
    $this->assertTrue($validator->validateLoopbackInterface('http://127.0.0.1:8080/callback'));

    // Test with loopback disabled.
    $config->set('allow_loopback_redirects', FALSE)->save();
    $this->assertFalse($validator->validateLoopbackInterface('http://127.0.0.1:8080/callback'));
  }

  /**
   * Tests configuration validator integration.
   */
  public function testConfigurationValidatorIntegration(): void {
    /** @var \Drupal\simple_oauth_native_apps\Service\ConfigurationValidator $validator */
    $validator = $this->container->get('simple_oauth_native_apps.configuration_validator');

    // Test valid configuration.
    $valid_config = [
      'webview_detection' => 'warn',
      'webview_whitelist' => ['TrustedApp'],
      'webview_patterns' => [],
      'allow_custom_uri_schemes' => TRUE,
      'allow_loopback_redirects' => TRUE,
      'logging_level' => 'info',
    ];

    $errors = $validator->validateConfiguration($valid_config);
    $this->assertEmpty($errors);

    // Test invalid configuration.
    $invalid_config = [
      'webview_detection' => 'invalid_policy',
      'logging_level' => 'invalid_level',
    ];

    $errors = $validator->validateConfiguration($invalid_config);
    $this->assertNotEmpty($errors);
    $this->assertGreaterThanOrEqual(2, count($errors));
  }

  /**
   * Tests metadata provider integration.
   */
  public function testMetadataProviderIntegration(): void {
    /** @var \Drupal\simple_oauth_native_apps\Service\MetadataProvider $provider */
    $provider = $this->container->get('simple_oauth_native_apps.metadata_provider');

    $metadata = $provider->getMetadata();

    $this->assertIsArray($metadata);
    $this->assertArrayHasKey('native_apps_supported', $metadata);
    $this->assertArrayHasKey('pkce_required', $metadata);
    $this->assertTrue($metadata['native_apps_supported']);
    $this->assertTrue($metadata['pkce_required']);
  }

  /**
   * Tests service dependencies are correctly injected.
   */
  public function testServiceDependencyInjection(): void {
    // Test that native client detector has correct dependencies.
    $native_detector = $this->container->get('simple_oauth_native_apps.native_client_detector');

    // Verify it can use injected services by testing functionality.
    $consumer = Consumer::create([
      'label' => 'Test App',
      'client_id' => 'test_client',
      'confidential' => FALSE,
      'redirect' => ['myapp://callback'],
    ]);

    // This would fail if dependencies weren't correctly injected.
    $confidence = $native_detector->getDetectionConfidence($consumer);
    $this->assertIsFloat($confidence);
    $this->assertGreaterThanOrEqual(0.0, $confidence);
    $this->assertLessThanOrEqual(1.0, $confidence);
  }

  /**
   * Tests service logging integration.
   */
  public function testServiceLoggingIntegration(): void {
    $config = $this->container->get('config.factory')->getEditable('simple_oauth_native_apps.settings');
    $config->set('log_pkce_validations', TRUE)->save();

    /** @var \Drupal\simple_oauth_native_apps\Service\PKCEEnhancementService $pkce_service */
    $pkce_service = $this->container->get('simple_oauth_native_apps.pkce_enhancement');

    $consumer = Consumer::create([
      'label' => 'Test Native App',
      'client_id' => 'test_native_client',
      'confidential' => FALSE,
      'redirect' => ['myapp://callback'],
    ]);

    // Test that logging doesn't cause errors.
    $result = $pkce_service->validatePkceParameters($consumer);
    $this->assertIsArray($result);
    $this->assertArrayHasKey('valid', $result);
  }

  /**
   * Tests complete service chain integration.
   */
  public function testCompleteServiceChainIntegration(): void {
    // Create a native app consumer.
    $consumer = Consumer::create([
      'label' => 'Complete Test Native App',
      'client_id' => 'complete_test_client',
      'confidential' => FALSE,
      'third_party' => TRUE,
      'redirect' => [
        'com.example.testapp://oauth/callback',
        'http://127.0.0.1:8080/callback',
      ],
    ]);
    $consumer->save();

    // Test the complete chain: Detection -> Validation -> PKCE Enhancement.
    // 1. Native client detection.
    $native_detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    $is_native = $native_detector->isNativeClient($consumer);
    $this->assertTrue($is_native);

    // 2. Redirect URI validation.
    $redirect_validator = $this->container->get('simple_oauth_native_apps.redirect_uri_validator');
    $custom_valid = $redirect_validator->validateCustomScheme('com.example.testapp://oauth/callback');
    $loopback_valid = $redirect_validator->validateLoopbackInterface('http://127.0.0.1:8080/callback');
    $this->assertTrue($custom_valid);
    $this->assertTrue($loopback_valid);

    // 3. PKCE enhancement for native client.
    $pkce_service = $this->container->get('simple_oauth_native_apps.pkce_enhancement');
    $requires_enhanced = $native_detector->requiresEnhancedPkce($consumer);
    $this->assertTrue($requires_enhanced);

    // 4. Enhanced PKCE validation.
    $code_verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXkdBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    $code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, TRUE)), '+/', '-_'), '=');

    $validation_result = $pkce_service->validatePkceParameters(
      $consumer,
      $code_challenge,
      'S256',
      $code_verifier
    );

    $this->assertTrue($validation_result['valid']);
    $this->assertTrue($validation_result['enhanced_applied']);
    $this->assertEmpty($validation_result['errors']);

    // 5. User agent analysis for webview detection.
    $user_agent_analyzer = $this->container->get('simple_oauth_native_apps.user_agent_analyzer');
    $webview_ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS]';
    $is_webview = $user_agent_analyzer->isEmbeddedWebview($webview_ua);
    $this->assertTrue($is_webview);

    // 6. Configuration validation.
    $config_validator = $this->container->get('simple_oauth_native_apps.configuration_validator');
    $config_errors = $config_validator->validateConfiguration([
      'webview_detection' => 'warn',
      'allow_custom_uri_schemes' => TRUE,
      'allow_loopback_redirects' => TRUE,
      'logging_level' => 'info',
    ]);
    $this->assertEmpty($config_errors);

    // 7. Metadata provision.
    $metadata_provider = $this->container->get('simple_oauth_native_apps.metadata_provider');
    $metadata = $metadata_provider->getMetadata();
    $this->assertTrue($metadata['native_apps_supported']);
    $this->assertTrue($metadata['pkce_required']);
  }

}
