<?php

namespace Drupal\Tests\simple_oauth_native_apps\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth_native_apps\EventSubscriber\AuthorizationRequestSubscriber;
use Drupal\simple_oauth_native_apps\EventSubscriber\PkceValidationSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests OAuth flow integration with native apps enhancements.
 */
#[Group('simple_oauth_native_apps')]
class OAuthFlowIntegrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'image',
    'consumers',
    'simple_oauth',
    'simple_oauth_native_apps',
    'serialization',
    'options',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('consumer');
    $this->installConfig(['simple_oauth', 'simple_oauth_native_apps']);
  }

  /**
   * Tests authorization request event subscriber integration.
   */
  public function testAuthorizationRequestSubscriberIntegration(): void {
    /** @var \Drupal\simple_oauth_native_apps\EventSubscriber\AuthorizationRequestSubscriber $subscriber */
    $subscriber = $this->container->get('simple_oauth_native_apps.authorization_request_subscriber');
    $this->assertInstanceOf(AuthorizationRequestSubscriber::class, $subscriber);

    // Test that subscriber is properly registered.
    $events = AuthorizationRequestSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey('kernel.request', $events);
  }

  /**
   * Tests PKCE validation event subscriber integration.
   */
  public function testPkceValidationSubscriberIntegration(): void {
    /** @var \Drupal\simple_oauth_native_apps\EventSubscriber\PkceValidationSubscriber $subscriber */
    $subscriber = $this->container->get('simple_oauth_native_apps.pkce_validation_subscriber');
    $this->assertInstanceOf(PkceValidationSubscriber::class, $subscriber);

    // Test that subscriber is properly registered.
    $events = PkceValidationSubscriber::getSubscribedEvents();
    $this->assertIsArray($events);
  }

  /**
   * Tests OAuth authorization request detection and processing.
   */
  public function testOauthAuthorizationRequestDetection(): void {
    $config = $this->container->get('config.factory')->getEditable('simple_oauth_native_apps.settings');
    $config->set('webview.detection', 'warn')->save();

    $subscriber = $this->container->get('simple_oauth_native_apps.authorization_request_subscriber');

    // Create OAuth authorization request.
    $request = Request::create('/oauth/authorize', Request::METHOD_GET, [
      'response_type' => 'code',
      'client_id' => 'test_client',
      'redirect_uri' => 'myapp://callback',
      'state' => 'random_state',
    ]);

    // Set webview user agent. cspell:ignore FBAN FBIOS
    $request->headers->set('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 [FBAN/FBIOS]');

    $event = new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST
    );

    // Process the request.
    $subscriber->onAuthorizationRequest($event);

    // Check that webview warning attributes are set.
    $this->assertTrue($request->attributes->has('oauth_webview_warning'));
    $this->assertEquals('social_media', $request->attributes->get('oauth_webview_type'));
  }

  /**
   * Tests native client detection in OAuth flow context.
   */
  public function testNativeClientDetectionInOauthFlow(): void {
    // Create a native app consumer.
    $consumer = Consumer::create([
      'label' => 'OAuth Flow Test Native App',
      'client_id' => 'oauth_flow_test_client',
      'confidential' => FALSE,
      'third_party' => TRUE,
      'redirect' => [
    // cspell:ignore oauthtest
        'com.example.oauthtest://callback',
        'http://127.0.0.1:8080/oauth',
      ],
    ]);
    $consumer->save();

    $native_detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    $pkce_service = $this->container->get('simple_oauth_native_apps.pkce_enhancement');

    // Test native detection.
    $is_native = $native_detector->isNativeClient($consumer);
    $this->assertTrue($is_native);

    // Test enhanced PKCE requirement.
    $requires_enhanced = $native_detector->requiresEnhancedPkce($consumer);
    $this->assertTrue($requires_enhanced);

    // Test S256 requirement.
    $requires_s256 = $pkce_service->requiresS256Method($consumer);
    $this->assertTrue($requires_s256);

    // Test classification reasons.
    $reasons = $native_detector->getClassificationReasons($consumer);
    $this->assertIsArray($reasons);
    $this->assertNotEmpty($reasons);
    $this->assertContains('Public client (typical for native apps)', $reasons);
  }

  /**
   * Tests PKCE validation in OAuth authorization flow.
   */
  public function testPkceValidationInAuthorizationFlow(): void {
    $consumer = Consumer::create([
      'label' => 'PKCE Test Native App',
      'client_id' => 'pkce_test_client',
      'confidential' => FALSE,
    // cspell:ignore nativeapp
      'redirect' => ['nativeapp://callback'],
    ]);
    $consumer->save();

    $pkce_service = $this->container->get('simple_oauth_native_apps.pkce_enhancement');

    // Test authorization request with PKCE parameters.
    $code_verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXkdBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    $code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, TRUE)), '+/', '-_'), '=');

    // Validate PKCE parameters for authorization request.
    $auth_validation = $pkce_service->validatePkceParameters(
      $consumer,
      $code_challenge,
      'S256'
    );

    $this->assertTrue($auth_validation['valid']);
    $this->assertTrue($auth_validation['enhanced_applied']);

    // Validate PKCE parameters for token request.
    $token_validation = $pkce_service->validatePkceParameters(
      $consumer,
      $code_challenge,
      'S256',
      $code_verifier
    );

    $this->assertTrue($token_validation['valid']);
    $this->assertTrue($token_validation['enhanced_applied']);
    $this->assertEmpty($token_validation['errors']);
  }

  /**
   * Tests redirect URI validation in OAuth flow.
   */
  public function testRedirectUriValidationInOauthFlow(): void {
    $redirect_validator = $this->container->get('simple_oauth_native_apps.redirect_uri_validator');

    // Test various redirect URI types that would be used in OAuth flows.
    $test_uris = [
      // Native app custom schemes.
      'com.example.myapp://oauth/callback' => TRUE,
      'myapp://auth' => TRUE,
    // cspell:ignore testscheme
      'testscheme://redirect' => TRUE,

      // Loopback interfaces.
      'http://127.0.0.1:8080/callback' => TRUE,
      'http://[::1]:3000/oauth' => TRUE,

      // Invalid/dangerous URIs.
      'javascript://malicious' => FALSE,
      'data://harmful' => FALSE,
    // Not loopback.
      'https://example.com/callback' => FALSE,
    ];

    foreach ($test_uris as $uri => $expected_valid) {
      $is_valid = $redirect_validator->validateRedirectUri($uri);
      $this->assertEquals($expected_valid, $is_valid, "URI validation failed for: {$uri}");

      if (!$expected_valid) {
        $error = $redirect_validator->getValidationError($uri);
        $this->assertNotNull($error, "Expected validation error for: {$uri}");
      }
    }
  }

  /**
   * Tests webview detection in OAuth authorization flow.
   */
  public function testWebviewDetectionInOauthFlow(): void {
    $config = $this->container->get('config.factory')->getEditable('simple_oauth_native_apps.settings');
    $config->set('webview.detection', 'block')->save();

    $user_agent_analyzer = $this->container->get('simple_oauth_native_apps.user_agent_analyzer');

    // Test various user agents in OAuth context.
    $test_cases = [
      // Webview user agents (should be detected).
    // cspell:ignore FBAN FBIOS
      'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 [FBAN/FBIOS]' => TRUE,
      'Mozilla/5.0 (Linux; Android 10; SM-G973F) Chrome/86.0.4240.198 Mobile Safari/537.36 Instagram' => TRUE,
      'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 WKWebView/1.0' => TRUE,

      // Safe browser user agents (should not be detected).
      'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 Version/16.0 Mobile Safari/604.1' => FALSE,
      'Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 Chrome/108.0.0.0 Mobile Safari/537.36' => FALSE,
    ];

    foreach ($test_cases as $user_agent => $is_webview) {
      $detected = $user_agent_analyzer->isEmbeddedWebview($user_agent);
      $this->assertEquals($is_webview, $detected, "Webview detection failed for: {$user_agent}");

      if ($is_webview) {
        $webview_type = $user_agent_analyzer->getWebviewType($user_agent);
        $this->assertNotNull($webview_type);
        $this->assertIsArray($webview_type);
        $this->assertArrayHasKey('category', $webview_type);
      }
    }
  }

  /**
   * Tests complete OAuth flow integration with all native app enhancements.
   */
  public function testCompleteOauthFlowIntegration(): void {
    // Set up configuration for full native app support.
    $config = $this->container->get('config.factory')->getEditable('simple_oauth_native_apps.settings');
    $config->setData([
      'allow' => [
        'custom_uri_schemes' => TRUE,
        'loopback_redirects' => TRUE,
      ],
      'enforce_native_security' => TRUE,
      'require_exact_redirect_match' => TRUE,
      'webview' => [
        'detection' => 'warn',
      ],
      'native' => [
        'enhanced_pkce' => TRUE,
        'enforce' => 'S256',
      ],
      'log' => [
        'pkce_validations' => FALSE,
        'detection_decisions' => FALSE,
      ],
    ])->save();

    // Create a comprehensive native app consumer.
    $consumer = Consumer::create([
      'label' => 'Complete Integration Test App',
      'client_id' => 'complete_integration_client',
      'confidential' => FALSE,
      'third_party' => TRUE,
      'redirect' => [
        'com.example.integration://oauth/callback',
        'http://127.0.0.1:8080/callback',
      ],
    ]);
    $consumer->save();

    // Get all necessary services.
    $native_detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    $redirect_validator = $this->container->get('simple_oauth_native_apps.redirect_uri_validator');
    $pkce_service = $this->container->get('simple_oauth_native_apps.pkce_enhancement');
    $user_agent_analyzer = $this->container->get('simple_oauth_native_apps.user_agent_analyzer');

    // Step 1: Native client detection.
    $is_native = $native_detector->isNativeClient($consumer);
    $confidence = $native_detector->getDetectionConfidence($consumer);
    $this->assertTrue($is_native);
    $this->assertGreaterThan(0.8, $confidence);

    // Step 2: Redirect URI validation.
    $custom_scheme_uri = 'com.example.integration://oauth/callback';
    $loopback_uri = 'http://127.0.0.1:8080/callback';

    $this->assertTrue($redirect_validator->validateRedirectUri($custom_scheme_uri));
    $this->assertTrue($redirect_validator->validateRedirectUri($loopback_uri));

    // Step 3: Enhanced PKCE validation.
    $requires_enhanced = $native_detector->requiresEnhancedPkce($consumer);
    $requires_s256 = $pkce_service->requiresS256Method($consumer);
    $this->assertTrue($requires_enhanced);
    $this->assertTrue($requires_s256);

    // Step 4: PKCE parameter validation.
    $code_verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXkdBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    $code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, TRUE)), '+/', '-_'), '=');

    $pkce_validation = $pkce_service->validatePkceParameters(
      $consumer,
      $code_challenge,
      'S256',
      $code_verifier
    );

    $this->assertTrue($pkce_validation['valid']);
    $this->assertTrue($pkce_validation['enhanced_applied']);
    $this->assertEmpty($pkce_validation['errors']);

    // Step 5: User agent analysis.
    $safe_ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 Version/16.0 Mobile Safari/604.1';
    // cspell:ignore FBAN FBIOS
    $webview_ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 [FBAN/FBIOS]';

    $this->assertFalse($user_agent_analyzer->isEmbeddedWebview($safe_ua));
    $this->assertTrue($user_agent_analyzer->isEmbeddedWebview($webview_ua));

    // Step 6: Verify service configuration.
    $service_config = $pkce_service->getConfiguration();
    $this->assertTrue($service_config['enhanced_pkce_enabled']);
    $this->assertEquals('S256', $service_config['enforce_method']);
    $this->assertEquals(128, $service_config['minimum_entropy_bits']);

    // Step 7: Test entropy validation.
    $entropy_result = $pkce_service->validateCodeVerifierEntropy($code_verifier);
    $this->assertTrue($entropy_result['valid']);
    $this->assertTrue($entropy_result['meets_minimum']);
    $this->assertGreaterThanOrEqual(128, $entropy_result['entropy_bits']);

    // Step 8: Test challenge method validation.
    $method_validation = $pkce_service->validateChallengeMethod('S256', TRUE);
    $this->assertTrue($method_validation['valid']);
    $this->assertEmpty($method_validation['errors']);

    // Step 9: Test plain method rejection for native clients.
    $plain_validation = $pkce_service->validateChallengeMethod('plain', TRUE);
    $this->assertFalse($plain_validation['valid']);
    $this->assertContains('Native clients must use S256 challenge method', $plain_validation['errors']);
  }

}
