<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_native_apps\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth_native_apps\EventSubscriber\AuthorizationRequestSubscriber;
use Drupal\simple_oauth_native_apps\EventSubscriber\PkceValidationSubscriber;
use GuzzleHttp\Psr7\ServerRequest;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests OAuth flow integration with native apps enhancements.
 */
#[Group('simple_oauth_native_apps')]
final class OAuthFlowIntegrationTest extends KernelTestBase {

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
   * Tests token validation follows credentials instead of query hints.
   */
  public function testTokenPkceUsesAuthenticatedClientIdentity(): void {
    $native = Consumer::create([
      'label' => 'Native credential client',
      'client_id' => 'native-credential-client',
      'confidential' => FALSE,
      'redirect' => ['com.example.native://callback'],
    ]);
    $native->save();
    $ordinary = Consumer::create([
      'label' => 'Ordinary credential client',
      'client_id' => 'ordinary-credential-client',
      'confidential' => TRUE,
      'redirect' => ['https://example.com/callback'],
    ]);
    $ordinary->save();
    $subscriber = $this->container->get('simple_oauth_native_apps.pkce_validation_subscriber');
    $verifiers = [
      'low entropy' => str_repeat('a', 43),
      'high entropy' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
    ];
    $credentials = [
      'body' => ['native-credential-client', NULL],
      'Basic' => [NULL, 'native-credential-client'],
      'body before Basic' => ['native-credential-client', 'ordinary-credential-client'],
    ];

    foreach ($credentials as $credential_name => [$body_client, $basic_client]) {
      foreach (['ordinary-credential-client', 'missing-client', ''] as $query_client) {
        foreach ($verifiers as $verifier_name => $verifier) {
          $request = Request::create('/oauth/token', Request::METHOD_POST, [
            'grant_type' => 'authorization_code',
            'code_verifier' => $verifier,
          ]);
          $request->query->set('client_id', $query_client);
          if ($body_client !== NULL) {
            $request->request->set('client_id', $body_client);
          }
          if ($basic_client !== NULL) {
            $request->headers->set('Authorization', 'Basic ' . base64_encode($basic_client . ':secret'));
          }
          $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
          $subscriber->onOauthRequest($event);
          $context = "$credential_name / $query_client / $verifier_name";
          if ($verifier_name === 'low entropy') {
            $this->assertTrue($event->hasResponse(), $context);
            $this->assertSame(400, $event->getResponse()->getStatusCode(), $context);
            $error = json_decode($event->getResponse()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('invalid_grant', $error['error'], $context);
            $this->assertStringContainsString('entropy', $error['error_description'], $context);
          }
          else {
            $this->assertFalse($event->hasResponse(), $context);
            $this->assertTrue($request->attributes->get('oauth_enhanced_pkce_applied'), $context);
          }
        }
      }
    }

    // An unrelated native query hint must not impose native policy on a web
    // client, even when a conflicting Basic username names the native client.
    $request = Request::create('/oauth/token', Request::METHOD_POST, [
      'client_id' => 'ordinary-credential-client',
      'grant_type' => 'authorization_code',
      'code_verifier' => str_repeat('a', 43),
    ]);
    $request->query->set('client_id', 'native-credential-client');
    $request->headers->set('Authorization', 'Basic ' . base64_encode('native-credential-client:secret'));
    $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    $subscriber->onOauthRequest($event);
    $this->assertFalse($event->hasResponse());
    $this->assertFalse($request->attributes->has('oauth_enhanced_pkce_applied'));
  }

  /**
   * Preserves native string validation and precedence over global PKCE policy.
   */
  public function testNativePkceStringsAndPolicyPrecedence(): void {
    $this->enableModules(['simple_oauth_pkce']);
    $this->installConfig(['simple_oauth_pkce']);
    $client = Consumer::create([
      'label' => 'Native PKCE policy client',
      'client_id' => 'native-policy-client',
      'confidential' => TRUE,
      'redirect' => ['https://example.com/callback'],
      'native_app_override' => 'native',
    ]);
    $client->save();
    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber($this->container->get('simple_oauth_native_apps.pkce_validation_subscriber'));
    $dispatcher->addSubscriber($this->container->get('simple_oauth_pkce.policy_subscriber'));
    $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    /** @var array<string, array{string, array<string, string>, ?string, ?string}> $cases */
    $cases = [
      'valid challenge' => [
        'authorize',
        ['code_challenge' => $challenge, 'code_challenge_method' => 'S256'],
        NULL,
        NULL,
      ],
      'omitted method' => ['authorize', ['code_challenge' => $challenge], NULL, NULL],
      'missing challenge' => ['authorize', [], 'invalid_request', 'mandatory'],
      'empty challenge' => ['authorize', ['code_challenge' => ''], 'invalid_request', 'mandatory'],
      'malformed challenge string' => [
        'authorize',
        ['code_challenge' => 'invalid!'],
        'invalid_request',
        'Invalid code challenge format',
      ],
      'unsupported method string' => [
        'authorize',
        ['code_challenge' => $challenge, 'code_challenge_method' => 'unknown'],
        'invalid_request',
        'Unsupported challenge method',
      ],
      'valid verifier' => ['token', ['code_verifier' => $verifier], NULL, NULL],
      'missing verifier' => ['token', [], 'invalid_grant', 'mandatory'],
      'empty verifier' => ['token', ['code_verifier' => ''], 'invalid_grant', 'mandatory'],
      'malformed verifier string' => [
        'token',
        ['code_verifier' => 'invalid!'],
        'invalid_grant',
        'Invalid code verifier format',
      ],
    ];
    foreach (['enhanced', 'auto-detect'] as $native_policy) {
      $this->config('simple_oauth_native_apps.settings')
        ->set('native.enhanced_pkce', $native_policy)->save();
      foreach (['mandatory', 'optional', 'disabled'] as $global_policy) {
        $this->config('simple_oauth_pkce.settings')
          ->set('enforcement', $global_policy)->save();
        foreach ($cases as $case => [$endpoint, $parameters, $error, $description]) {
          $context = "$native_policy / $global_policy / $case";
          $parameters += ['client_id' => 'native-policy-client'];
          $parameters += $endpoint === 'authorize' ? ['response_type' => 'code'] : ['grant_type' => 'authorization_code'];
          $request = Request::create('/oauth/' . $endpoint, $endpoint === 'authorize' ? 'GET' : 'POST', $parameters);
          $request->attributes->set('_route', 'oauth2_token.' . $endpoint);
          $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
          $dispatcher->dispatch($event, KernelEvents::REQUEST);
          if ($error === NULL) {
            $this->assertFalse($event->hasResponse(), $context);
            $this->assertTrue($request->attributes->get('oauth_enhanced_pkce_applied'), $context);
            continue;
          }
          $response = $event->getResponse();
          $this->assertNotNull($response, $context);
          $this->assertSame(400, $response->getStatusCode(), $context);
          $data = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
          $this->assertSame($error, $data['error'], $context);
          $this->assertStringContainsString($description, $data['error_description'], $context);
          $this->assertSame('pkce_validation_failure', $response->headers->get('X-OAuth-Error-Type'), $context);
          $this->assertTrue($response->headers->hasCacheControlDirective('no-store'), $context);
          $this->assertSame('no-cache', $response->headers->get('Pragma'), $context);
        }
      }
    }

    // A native exemption delegates to global policy, so optional and disabled
    // allow missing parameters while mandatory still rejects them.
    $this->config('simple_oauth_native_apps.consumer.' . $client->id())
      ->set('enhanced_pkce_override', 'not-enhanced')->save();
    foreach (['mandatory', 'optional', 'disabled'] as $global_policy) {
      $this->config('simple_oauth_pkce.settings')
        ->set('enforcement', $global_policy)->save();
      foreach (['authorize', 'token'] as $endpoint) {
        $parameters = ['client_id' => 'native-policy-client'];
        $parameters += $endpoint === 'authorize' ? ['response_type' => 'code'] : ['grant_type' => 'authorization_code'];
        $request = Request::create('/oauth/' . $endpoint, $endpoint === 'authorize' ? 'GET' : 'POST', $parameters);
        $request->attributes->set('_route', 'oauth2_token.' . $endpoint);
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $dispatcher->dispatch($event, KernelEvents::REQUEST);
        $this->assertFalse($request->attributes->has('oauth_enhanced_pkce_applied'));
        $this->assertSame($global_policy === 'mandatory', $event->hasResponse());
        if ($global_policy === 'mandatory') {
          $response = $event->getResponse();
          $this->assertNotNull($response);
          $this->assertSame(400, $response->getStatusCode());
          $data = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
          $this->assertSame('invalid_request', $data['error']);
          $this->assertStringContainsString('required', $data['error_description']);
        }
      }
    }
  }

  /**
   * Tests the master switch controls every WebView enforcement mode.
   */
  public function testWebviewPoliciesRespectMasterSwitch(): void {
    $config = $this->config('simple_oauth_native_apps.settings');
    $analyzer = $this->container->get('simple_oauth_native_apps.user_agent_analyzer');
    $subscriber = $this->container->get('simple_oauth_native_apps.authorization_request_subscriber');
    foreach ([TRUE, FALSE] as $enabled) {
      foreach (['block', 'warn', 'off'] as $policy) {
        $config->set('enforce_native_security', $enabled)->set('webview.detection', $policy)->save();
        $request = Request::create('/oauth/authorize', Request::METHOD_GET, ['response_type' => 'code']);
        $request->attributes->set('_route', 'oauth2_token.authorize');
        $request->headers->set('User-Agent', 'ExampleApp WKWebView/1.0');
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onAuthorizationRequest($event);
        $this->assertSame($enabled ? $policy : 'off', $analyzer->getDetectionMode());
        $this->assertSame($enabled && $policy !== 'off', $analyzer->isDetectionEnabled());
        $this->assertSame($enabled && $policy === 'block', $event->hasResponse());
        $this->assertSame($enabled && $policy === 'warn', $request->attributes->has('oauth_webview_warning'));
      }
    }
  }

  /**
   * Tests WebView blocking follows the matched route rather than query hints.
   */
  public function testWebviewBlockingUsesMatchedAuthorizationRoute(): void {
    $config = $this->config('simple_oauth_native_apps.settings');
    $subscriber = $this->container->get('simple_oauth_native_apps.authorization_request_subscriber');
    $route_provider = $this->container->get('router.route_provider');
    $router = $this->container->get('router.no_access_checks');
    $routes = [
      'oauth2_token.authorize',
      'user.login',
      'oauth2_token.user_debug',
    ];
    $parameters = [
      'client_id' => 'webview-context-client',
      'redirect_uri' => 'https://example.com/callback',
      'scope' => 'profile',
      'response_type' => 'code',
    ];

    foreach ([TRUE, FALSE] as $enabled) {
      $config->set('enforce_native_security', $enabled)
        ->set('webview.detection', 'block')
        ->save();
      foreach ($routes as $route_name) {
        $route = $route_provider->getRouteByName($route_name);
        foreach ($parameters as $parameter => $value) {
          $request = Request::create($route->getPath(), 'GET', [$parameter => $value]);
          $router->getContext()->fromRequest($request);
          $request->attributes->add($router->matchRequest($request));
          $request->headers->set('User-Agent', 'ExampleApp WKWebView/1.0');
          $this->assertSame($route_name, $request->attributes->get('_route'));
          $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
          $subscriber->onAuthorizationRequest($event);
          $context = "$route_name / $parameter / " . ($enabled ? 'enabled' : 'disabled');
          $this->assertSame($enabled && $route_name === 'oauth2_token.authorize', $event->hasResponse(), $context);
          $this->assertFalse($request->attributes->has('oauth_webview_warning'), $context);
          if ($event->hasResponse()) {
            $response = $event->getResponse();
            $this->assertSame(400, $response->getStatusCode(), $context);
            $error = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('embedded_webview_blocked', $error['error'], $context);
          }
        }
      }
    }
  }

  /**
   * Tests custom detection and whitelist regexes through the real subscribers.
   */
  public function testConfiguredWebviewRegexes(): void {
    $config = $this->config('simple_oauth_native_apps.settings');
    $config->set('enforce_native_security', TRUE)
      ->set('webview.detection', 'block')
      ->set('webview.patterns', ['^ExampleView/[0-9]+\.[0-9]+$', 'LiteralNativeView', '['])
      ->set('webview.whitelist', ['^ExampleView/2\..*$', '['])
      ->save();
    $analyzer = $this->container->get('simple_oauth_native_apps.user_agent_analyzer');
    $subscriber = $this->container->get('simple_oauth_native_apps.authorization_request_subscriber');
    foreach ([
      'ExampleView/1.2' => [TRUE, TRUE],
      'ExampleView/2.7' => [TRUE, FALSE],
      'LiteralNativeView' => [TRUE, TRUE],
      'OtherBrowser/1.2' => [FALSE, FALSE],
    ] as $user_agent => [$detected, $blocked]) {
      $this->assertSame($detected, $analyzer->isEmbeddedWebview($user_agent), $user_agent);
      $this->assertSame($detected, $analyzer->getWebviewType($user_agent) !== NULL, $user_agent);
      $request = Request::create('/oauth/authorize', Request::METHOD_GET, ['response_type' => 'code']);
      $request->attributes->set('_route', 'oauth2_token.authorize');
      $request->headers->set('User-Agent', $user_agent);
      $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
      $subscriber->onAuthorizationRequest($event);
      $this->assertSame($blocked, $event->hasResponse(), $user_agent);
    }
    $validator = $this->container->get('simple_oauth_native_apps.configuration_validator');
    $this->assertEmpty($validator->validateRegexPatterns(['^ExampleView/[0-9]+\.[0-9]+$', 'LiteralNativeView']));
    $this->assertCount(1, $validator->validateRegexPatterns(['[']));
  }

  /**
   * Tests the native plugin constructs a server retaining public-client PKCE.
   */
  public function testNativeGrantServerRequiresPublicChallenge(): void {
    $consumer = Consumer::create([
      'label' => 'Native grant client',
      'client_id' => 'native-grant-client',
      'confidential' => FALSE,
      'status' => TRUE,
      'redirect' => ['com.example.grant://callback'],
      'grant_types' => ['native_app_authorization_code'],
      'access_token_expiration' => 300,
    ]);
    $consumer->save();
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $this->assertNotFalse($key);
    $this->assertTrue(openssl_pkey_export($key, $private_key));
    $key_file = tempnam(sys_get_temp_dir(), 'oauth-native-key-');
    $this->assertNotFalse($key_file);
    try {
      file_put_contents($key_file, $private_key);
      $this->config('simple_oauth.settings')->set('private_key', $key_file)->save();
      $this->setSetting('hash_salt', str_repeat('native-test-salt', 4));
      $factory = $this->container->get('simple_oauth.server.authorization_server.factory');
      $server = $factory->get($consumer);
      $this->assertInstanceOf(AuthorizationServer::class, $server);
      $query = [
        'response_type' => 'code',
        'client_id' => 'native-grant-client',
        'redirect_uri' => 'com.example.grant://callback',
      ];
      $request = (new ServerRequest('GET', 'https://example.com/oauth/authorize'))->withQueryParams($query);
      try {
        $server->validateAuthorizationRequest($request);
        $this->fail('A public native client must supply a code challenge.');
      }
      catch (OAuthServerException $exception) {
        $this->assertSame('invalid_request', $exception->getErrorType());
        $this->assertStringContainsString('public clients', $exception->getHint());
      }
      $query['code_challenge'] = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
      $query['code_challenge_method'] = 'S256';
      $authorization = $server->validateAuthorizationRequest($request->withQueryParams($query));
      $this->assertSame('native-grant-client', $authorization->getClient()->getIdentifier());
      $this->assertSame($query['code_challenge'], $authorization->getCodeChallenge());
      $this->assertSame('S256', $authorization->getCodeChallengeMethod());
    }
    finally {
      unlink($key_file);
    }
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
    $this->assertArrayHasKey('kernel.request', $events);
    $listeners = $this->container->get('event_dispatcher')->getListeners('kernel.request');
    $this->assertContains([$subscriber, 'onOauthRequest'], $listeners);
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

    $request->attributes->set('_route', 'oauth2_token.authorize');

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
    $this->assertContains('Strong native URI patterns detected', $reasons);
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
    // Configure settings to allow native URIs.
    $config = $this->container->get('config.factory')->getEditable('simple_oauth_native_apps.settings');
    $config->set('allow.custom_uri_schemes', 'native');
    $config->set('allow.loopback_redirects', 'native');
    $config->save();

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
        $this->assertSame($user_agent, $webview_type['user_agent']);
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
        'custom_uri_schemes' => 'native',
        'loopback_redirects' => 'native',
      ],
      'enforce_native_security' => TRUE,
      'require_exact_redirect_match' => TRUE,
      'webview' => [
        'detection' => 'warn',
      ],
      'native' => [
        'enhanced_pkce' => 'enhanced',
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
