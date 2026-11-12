<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_device_flow\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Flood\DatabaseBackend;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\UserSession;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth_device_flow\Controller\DeviceAuthorizationController;
use Drupal\simple_oauth_device_flow\Controller\DeviceVerificationController;
use Drupal\simple_oauth_device_flow\Form\DeviceVerificationForm;
use Drupal\simple_oauth_device_flow\Entity\DeviceCode;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Drupal\simple_oauth\Entities\ScopeEntity;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\simple_oauth_device_flow\Service\DeviceCodeService;
use Drupal\simple_oauth_device_flow\Service\UserCodeGenerator;
use Drupal\simple_oauth_device_flow\Service\DeviceFlowSettingsService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for device flow services integration.
 *
 * Tests core device flow services that don't require full entity setup.
 */
#[Group('simple_oauth_device_flow')]
#[Group('kernel')]
final class DeviceFlowIntegrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'simple_oauth_21',
    'simple_oauth_device_flow',
    'simple_oauth',
    'consumers',
    'serialization',
    'system',
    'user',
    'image',
    'file',
    'options',
  ];

  /**
   * Tests device code service can be instantiated.
   */
  public function testDeviceCodeServiceInstantiation(): void {
    $service = $this->container->get('simple_oauth_device_flow.device_code_service');
    $this->assertInstanceOf(DeviceCodeService::class, $service);
  }

  /**
   * Tests user code generator service.
   */
  public function testUserCodeGenerator(): void {
    $generator = $this->container->get('simple_oauth_device_flow.user_code_generator');
    $this->assertInstanceOf(UserCodeGenerator::class, $generator);

    // Test user code generation.
    $user_code = $generator->generateUserCode();
    $this->assertTrue($generator->validateCodeFormat($user_code, FALSE));
    $this->assertGreaterThan(4, strlen($user_code));
    $this->assertLessThan(20, strlen($user_code));

    // Test multiple codes are different.
    $user_code2 = $generator->generateUserCode();
    $this->assertNotEquals($user_code, $user_code2);
  }

  /**
   * Tests device flow settings service.
   */
  public function testDeviceFlowSettingsService(): void {
    // Install config.
    $this->installConfig(['simple_oauth_device_flow']);

    $settings = $this->container->get('simple_oauth_device_flow.settings');
    $this->assertInstanceOf(DeviceFlowSettingsService::class, $settings);

    // Test default settings.
    $this->assertSame(1800, $settings->getDeviceCodeExpiration());
    $this->assertSame(5, $settings->getPollingInterval());
    $this->assertSame('/oauth/device', $settings->getVerificationUri());

    // Test settings validation.
    $this->assertGreaterThan(0, $settings->getDeviceCodeExpiration());
    $this->assertGreaterThan(0, $settings->getPollingInterval());
  }

  /**
   * Tests device flow routing and controller setup.
   */
  public function testDeviceFlowRouting(): void {
    // Install routing config.
    $this->installConfig(['simple_oauth_device_flow']);

    $route_provider = $this->container->get('router.route_provider');

    // Test device authorization route exists.
    $device_auth_route = $route_provider->getRouteByName('simple_oauth_device_flow.device_authorization');
    $this->assertSame('\Drupal\simple_oauth_device_flow\Controller\DeviceAuthorizationController::authorize', $device_auth_route->getDefault('_controller'));
    $this->assertEquals('/oauth/device_authorization', $device_auth_route->getPath());
    $this->assertEquals(['POST'], $device_auth_route->getMethods());

    // Test device verification routes exist.
    $device_verify_route = $route_provider->getRouteByName('simple_oauth_device_flow.device_verification_form');
    $this->assertSame('\Drupal\simple_oauth_device_flow\Controller\DeviceVerificationController::form', $device_verify_route->getDefault('_controller'));
    $this->assertEquals('/oauth/device', $device_verify_route->getPath());
    $this->assertEquals(['GET'], $device_verify_route->getMethods());

    $device_submit_route = $route_provider->getRouteByName('simple_oauth_device_flow.device_verification_submit');
    $this->assertSame('\Drupal\simple_oauth_device_flow\Controller\DeviceVerificationController::verify', $device_submit_route->getDefault('_controller'));
    $this->assertEquals('/oauth/device', $device_submit_route->getPath());
    $this->assertEquals(['POST'], $device_submit_route->getMethods());
  }

  /**
   * Tests device flow service definitions.
   */
  public function testDeviceFlowServices(): void {
    $container = $this->container;

    // Test all expected services are registered.
    $expected_services = [
      'simple_oauth_device_flow.device_code_service' => DeviceCodeService::class,
      'simple_oauth_device_flow.user_code_generator' => UserCodeGenerator::class,
      'simple_oauth_device_flow.settings' => DeviceFlowSettingsService::class,
    ];

    foreach ($expected_services as $service_id => $expected_class) {
      $this->assertTrue($container->has($service_id), "Service {$service_id} should be registered");
      $service = $container->get($service_id);
      $this->assertSame($expected_class, $service::class, "Service {$service_id} should use its registered class");
    }
  }

  /**
   * Tests device flow configuration schema.
   */
  public function testDeviceFlowConfiguration(): void {
    $this->installConfig(['simple_oauth_device_flow']);

    $config = $this->config('simple_oauth_device_flow.settings');
    $this->assertFalse($config->isNew());

    // Installed configuration must provide the documented issuance defaults.
    $this->assertSame(1800, $config->get('device_code_lifetime'));
    $this->assertSame(5, $config->get('polling_interval'));
    $this->assertSame('/oauth/device', $config->get('verification_uri'));
  }

  /**
   * Tests device flow configuration form route exists.
   */
  public function testDeviceFlowConfigurationRoute(): void {
    $route_provider = $this->container->get('router.route_provider');

    // Test device flow settings route exists.
    $settings_route = $route_provider->getRouteByName('simple_oauth_device_flow.settings');
    $this->assertSame('administer simple_oauth entities', $settings_route->getRequirement('_permission'));
    $this->assertTrue($settings_route->getOption('_admin_route'));
    $this->assertEquals('/admin/config/people/simple_oauth/oauth-21/device-flow', $settings_route->getPath());
  }

  /**
   * Tests device code scope field integration.
   *
   * This tests our custom business logic for multi-value scope storage
   * using the oauth2_scope_reference field type.
   */
  public function testDeviceCodeScopeFieldIntegration(): void {
    // Install required schemas.
    $this->installEntitySchema('oauth2_device_code');
    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    $this->installEntitySchema('oauth2_scope');
    $this->installConfig(['simple_oauth_device_flow', 'simple_oauth']);

    // Create test scopes.
    // Note: Oauth2Scope entities auto-generate IDs from names using
    // scopeToMachineName(). So 'name' => 'read' becomes 'id' => 'read',
    // not 'id' => 'read_access'.
    $scope_storage = $this->container->get('entity_type.manager')->getStorage('oauth2_scope');
    $scope1 = $scope_storage->create([
      'name' => 'read',
      'description' => 'Read access to resources',
    ]);
    $scope1->save();

    $scope2 = $scope_storage->create([
      'name' => 'write',
      'description' => 'Write access to resources',
    ]);
    $scope2->save();

    // Create a device code entity and test scope assignment.
    $device_code_storage = $this->container->get('entity_type.manager')->getStorage('oauth2_device_code');
    $device_code = $device_code_storage->create([
      'device_code' => 'test_device_code_123',
      'user_code' => 'ABC-DEF',
      'client_id' => 'test_client',
      'created_at' => time(),
      'expires_at' => time() + 600,
      'authorized' => FALSE,
      'interval' => 5,
    ]);

    $this->assertInstanceOf(DeviceCode::class, $device_code);

    // Test adding multiple scopes via field API.
    $device_code->get('scopes')->appendItem(['scope_id' => 'read']);
    $device_code->get('scopes')->appendItem(['scope_id' => 'write']);
    $device_code->save();

    // Reload and verify scopes are persisted correctly.
    $device_code_storage->resetCache([$device_code->id()]);
    $loaded_device_code = $device_code_storage->load($device_code->id());
    $this->assertInstanceOf(DeviceCode::class, $loaded_device_code);

    // Test getScopes() returns proper scope entities.
    $scopes = $loaded_device_code->getScopes();
    $this->assertCount(2, $scopes, 'Device code should have 2 scopes');

    // Verify scope identifiers.
    $scope_ids = array_map(fn($scope) => $scope->getIdentifier(), $scopes);
    $this->assertContains('read', $scope_ids, 'Scope list should contain "read"');
    $this->assertContains('write', $scope_ids, 'Scope list should contain "write"');

    // Test adding scope via entity method (addScope).
    $scope3 = $scope_storage->create([
      'name' => 'admin',
      'description' => 'Administrative access',
    ]);
    $this->assertInstanceOf(Oauth2ScopeInterface::class, $scope3);
    $scope3->save();

    // Wrap the Drupal Oauth2Scope entity in a ScopeEntity (League interface).
    $loaded_device_code->addScope(new ScopeEntity($scope3));
    $loaded_device_code->save();

    // Reload and verify third scope is added.
    $device_code_storage->resetCache([$device_code->id()]);
    $reloaded_device_code = $device_code_storage->load($device_code->id());
    $this->assertInstanceOf(DeviceCode::class, $reloaded_device_code);
    $scopes_after = $reloaded_device_code->getScopes();
    $this->assertCount(3, $scopes_after, 'Device code should now have 3 scopes');

    $scope_ids_after = array_map(fn($scope) => $scope->getIdentifier(), $scopes_after);
    $this->assertContains('admin', $scope_ids_after, 'Scope list should contain "admin"');
  }

  /**
   * Tests canonical lifetime, persisted expiry and safe creation/approval logs.
   */
  public function testDeviceIssuanceLifetimeAndSafeLogging(): void {
    $this->enableModules(['dblog']);
    $this->installSchema('dblog', ['watchdog']);
    foreach (['oauth2_device_code', 'user', 'consumer', 'oauth2_scope'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installConfig(['simple_oauth_device_flow', 'simple_oauth']);
    $settings = $this->container->get('simple_oauth_device_flow.settings');
    $this->assertSame(1800, $settings->getDeviceCodeExpiration());
    $this->config('simple_oauth_device_flow.settings')
      ->clear('device_code_lifetime')->save();
    // A fresh service reads the fallback from the changed configuration.
    $settings = new DeviceFlowSettingsService($this->container->get('config.factory'));
    $this->assertNull($settings->getConfig()->get('device_code_lifetime'));
    $this->assertSame(1800, $settings->getDeviceCodeExpiration());
    $this->config('simple_oauth_device_flow.settings')
      ->set('device_code_lifetime', 143)->save();

    $records = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('log')->willReturnCallback(static function ($level, $message, array $context = []) use (&$records): void {
      $records[] = ['message' => (string) $message, 'context' => $context];
    });
    $this->container->get('simple_oauth_device_flow.logger_factory')->addLogger($logger);
    $consumer = Consumer::create([
      'label' => 'Device coverage client',
      'client_id' => 'device-coverage-client',
      'confidential' => FALSE,
      'grant_types' => ['urn:ietf:params:oauth:grant-type:device_code'],
      'redirect' => ['https://example.org/callback'],
    ]);
    $consumer->save();
    $now = time();
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn($now);
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $this->container->set('datetime.time', $time);
    $request = Request::create('https://example.org/oauth/device_authorization', 'POST', [
      'client_id' => $consumer->getClientId(),
      'client_secret' => 'device-secret-sentinel',
      'registration_access_token' => 'device-token-sentinel',
    ]);
    $this->container->get('request_stack')->push($request);
    $controller = DeviceAuthorizationController::create($this->container);
    $response = $controller->authorize($request);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(143, $data['expires_in']);
    $device = $repository->getDeviceCodeEntityByDeviceCode($data['device_code']);
    $this->assertInstanceOf(DeviceCode::class, $device);
    $this->assertSame($now + 143, $device->getExpiryDateTime()->getTimestamp());
    $this->assertSame($now, (int) $device->get('created_at')->value);
    $this->assertFalse($repository->isDeviceCodeRevoked($data['device_code']));

    $anonymous_request = Request::create('https://example.org/oauth/device', 'GET', [
      'user_code' => $data['user_code'],
    ]);
    $anonymous_uri = $anonymous_request->getUri();
    $anonymous_request->headers->set('Referer', $anonymous_uri);
    $this->container->get('request_stack')->push($anonymous_request);
    $login_response = DeviceVerificationController::create($this->container)
      ->form($anonymous_request);
    $this->assertSame(302, $login_response->getStatusCode());
    $this->assertStringContainsString('/user/login?', $login_response->getTargetUrl());
    $login_query = [];
    parse_str((string) parse_url($login_response->getTargetUrl(), PHP_URL_QUERY), $login_query);
    $this->assertSame($anonymous_request->getRequestUri(), $login_query['destination']);
    $this->assertSame($anonymous_request, $this->container->get('request_stack')->getCurrentRequest());
    $this->assertSame($anonymous_uri, $anonymous_request->getUri());
    $this->assertSame($anonymous_uri, $anonymous_request->headers->get('Referer'));
    $this->container->get('request_stack')->pop();

    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 7]));
    $verification_request = Request::create('https://example.org/oauth/device', 'GET', [
      'user_code' => $data['user_code'],
    ]);
    $verification_request->headers->set('Referer', $verification_request->getUri());
    $verification_uri = $verification_request->getUri();
    $verification_request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($verification_request);
    $verification_form = DeviceVerificationController::create($this->container)
      ->form($verification_request);
    $this->assertSame($data['user_code'], $verification_form['user_code']['#default_value']);
    $this->assertSame($verification_request, $this->container->get('request_stack')->getCurrentRequest());
    $this->assertSame($verification_uri, $verification_request->getUri());
    $this->assertSame($verification_uri, $verification_request->headers->get('Referer'));
    $unrelated_records = [];
    $unrelated_logger = $this->createMock(LoggerInterface::class);
    $unrelated_logger->method('log')->willReturnCallback(static function ($level, $message, array $context = []) use (&$unrelated_records): void {
      $unrelated_records[] = $context;
    });
    $this->container->get('logger.factory')->addLogger($unrelated_logger);
    $this->container->get('logger.factory')->get('unrelated')->info('Unrelated logging remains unchanged.');
    $this->assertSame($verification_uri, $unrelated_records[0]['request_uri']);
    $this->assertSame($verification_uri, $unrelated_records[0]['referer']);
    $this->container->get('request_stack')->pop();
    $form_object = DeviceVerificationForm::create($this->container);
    $form = [];
    $state = (new FormState())->setValue('user_code', $data['user_code']);
    $form_object->validateForm($form, $state);
    $this->assertSame([], $state->getErrors());
    $form_object->submitForm($form, $state);
    $this->container->get('entity_type.manager')->getStorage('oauth2_device_code')->resetCache();
    $approved = $repository->getDeviceCodeEntityByDeviceCode($data['device_code']);
    $this->assertInstanceOf(DeviceCode::class, $approved);
    $this->assertTrue($approved->getUserApproved());
    $this->assertSame('7', $approved->getUserIdentifier());

    $approved->setExpiryDateTime(new \DateTimeImmutable());
    $approved->save();
    $this->assertTrue($repository->isDeviceCodeRevoked($data['device_code']));

    $denial_response = $controller->authorize($request);
    $denial_data = json_decode($denial_response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $denial_state = (new FormState())->setValue('user_code', $denial_data['user_code']);
    $form_object->validateForm($form, $denial_state);
    $this->assertSame([], $denial_state->getErrors());
    $form_object->submitDeny($form, $denial_state);
    $this->container->get('entity_type.manager')->getStorage('oauth2_device_code')->resetCache();
    $denied = $repository->getDeviceCodeEntityByDeviceCode($denial_data['device_code']);
    $this->assertFalse($denied->getUserApproved());
    $this->assertSame('7', $denied->getUserIdentifier());

    $backend_records = $this->container->get('database')->select('watchdog', 'w')
      ->fields('w', ['message', 'variables', 'location', 'referer'])
      ->condition('type', 'simple_oauth_device_flow')->execute()->fetchAll();
    $this->assertNotEmpty($backend_records, 'Tagged backends receive device events.');
    foreach ($backend_records as $record) {
      $this->assertSame('', $record->location);
      $this->assertSame('', $record->referer);
    }
    foreach ($records as $record) {
      $this->assertSame('simple_oauth_device_flow', $record['context']['channel']);
      $this->assertArrayHasKey('timestamp', $record['context']);
      $this->assertSame('', $record['context']['request_uri']);
      $this->assertSame('', $record['context']['referer']);
    }
    $serialized = json_encode([$records, $backend_records], JSON_THROW_ON_ERROR);
    $credentials = [
      'device_code' => $data['device_code'],
      'user_code' => $data['user_code'],
      'denied_device_code' => $denial_data['device_code'],
      'denied_user_code' => $denial_data['user_code'],
      'client_secret' => 'device-secret-sentinel',
      'registration_access_token' => 'device-token-sentinel',
    ];
    $leaked = [];
    foreach ($credentials as $name => $credential) {
      if (str_contains($serialized, $credential)) {
        $leaked[] = $name;
      }
    }
    $this->assertSame([], $leaked, 'Credential values must stay out of logger messages and contexts.');
    foreach ([
      'Device code persisted',
      'Device authorization generated',
      'Device authorization successful',
      'Device authorized successfully',
      'Device verification form accessed',
      'Device authorization denied',
    ] as $event) {
      $this->assertStringContainsString($event, $serialized);
    }
    $this->assertStringContainsString('device-coverage-client', $serialized);

    $this->container->get('request_stack')->pop();
  }

  /**
   * Tests persistent per-user limiting and exact expiry with controlled time.
   */
  public function testVerificationLimiterPersistsAcrossRequestsAndExpires(): void {
    // User-code fixtures intentionally use the valid consonant-only alphabet.
    // cspell:ignore BCDF GHJK
    $this->installEntitySchema('oauth2_device_code');
    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    $this->installConfig(['simple_oauth_device_flow']);
    $now = 1700000000;
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(static fn(): int => $now);
    $request = Request::create('https://example.org/oauth/device');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $stack = $this->container->get('request_stack');
    $stack->push($request);
    $account = $this->container->get('current_user');
    $account->setAccount(new UserSession(['uid' => 11]));
    $database = $this->container->get('database');

    for ($attempt = 0; $attempt < 3; $attempt++) {
      $this->container->set('flood', new DatabaseBackend($database, $stack, $time));
      $form_object = DeviceVerificationForm::create($this->container);
      $form = [];
      $state = (new FormState())->setValue('user_code', 'BCDF-GHJK');
      $form_object->validateForm($form, $state);
      $this->assertStringContainsString('Invalid user code', (string) $state->getErrors()['user_code']);
    }
    $this->container->set('flood', new DatabaseBackend($database, $stack, $time));
    $blocked_form = DeviceVerificationForm::create($this->container);
    $form = [];
    $blocked_state = (new FormState())->setValue('user_code', 'BCDF-GHJK');
    $blocked_form->validateForm($form, $blocked_state);
    $this->assertStringContainsString('Too many failed verification attempts', (string) $blocked_state->getErrors()['user_code']);
    $this->assertArrayHasKey('rate_limit', $blocked_form->buildForm([], new FormState()));
    $controller = DeviceVerificationController::create($this->container);
    $this->assertArrayHasKey('rate_limit', $controller->form($request));
    $this->assertArrayHasKey('rate_limit', $controller->verify($request));

    $storage = $this->container->get('entity_type.manager')->getStorage('oauth2_device_code');
    $device = $storage->create([
      'device_code' => 'opaque-limiter-device-code',
      'user_code' => 'BCDF-GHJK',
      'client_id' => 'limiter-client',
      'created_at' => time(),
      'expires_at' => time() + 600,
      'authorized' => FALSE,
    ]);
    $device->save();
    $account->setAccount(new UserSession(['uid' => 12]));
    $this->assertArrayHasKey('user_code', DeviceVerificationForm::create($this->container)->buildForm([], new FormState()));
    $other_state = (new FormState())->setValue('user_code', 'BCDF-GHJK');
    DeviceVerificationForm::create($this->container)->validateForm($form, $other_state);
    $this->assertSame([], $other_state->getErrors());
    $account->setAccount(new UserSession(['uid' => 11]));
    $now += 3600;
    $expired_time = $this->createMock(TimeInterface::class);
    $expired_time->method('getRequestTime')->willReturn($now);
    $this->container->set('flood', new DatabaseBackend($database, $stack, $expired_time));
    $this->assertArrayHasKey('user_code', DeviceVerificationForm::create($this->container)->buildForm([], new FormState()));
    $after_window = DeviceVerificationForm::create($this->container);
    $valid_state = (new FormState())->setValue('user_code', 'BCDF-GHJK');
    $after_window->validateForm($form, $valid_state);
    $this->assertSame([], $valid_state->getErrors());
    $after_window->submitForm($form, $valid_state);
    $storage->resetCache([$device->id()]);
    $approved = $storage->load($device->id());
    $this->assertInstanceOf(DeviceCode::class, $approved);
    $this->assertTrue($approved->getUserApproved());
    $this->assertSame('11', $approved->getUserIdentifier());
    $this->assertSame(3, (int) $database->select('flood', 'f')->countQuery()->execute()->fetchField());
    $stack->pop();
  }

  /**
   * Tests protected logging remains available to disposable lifecycle hooks.
   */
  public function testProtectedLoggingAcrossLifecycleHooks(): void {
    $this->installEntitySchema('oauth2_device_code');
    $this->installConfig(['simple_oauth_device_flow']);
    $records = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('log')->willReturnCallback(static function ($level, $message, array $context = []) use (&$records): void {
      $records[] = ['message' => (string) $message, 'context' => $context];
    });
    $factory = $this->container->get('simple_oauth_device_flow.logger_factory');
    $factory->addLogger($logger);
    $module_handler = $this->container->get('module_handler');
    $module_handler->loadInclude('simple_oauth_device_flow', 'install');
    $module_handler->loadInclude('simple_oauth_device_flow', 'module');
    $request = Request::create('https://example.org/oauth/device', 'GET', ['user_code' => 'BCDF-GHJK']);
    $request->headers->set('Referer', $request->getUri());
    $stack = $this->container->get('request_stack');
    $stack->push($request);

    \simple_oauth_device_flow_install(FALSE);
    $storage = $this->container->get('entity_type.manager')->getStorage('oauth2_device_code');
    $storage->create([
      'device_code' => 'expired-log-context-fixture',
      'user_code' => 'BCDF-GHJK',
      'client_id' => 'lifecycle-client',
      'created_at' => time() - 600,
      'expires_at' => time() - 5,
      'authorized' => FALSE,
    ])->save();
    \simple_oauth_device_flow_cron();
    $this->assertNull($storage->load('expired-log-context-fixture'));
    \simple_oauth_device_flow_uninstall(FALSE);
    $serialized = json_encode($records, JSON_THROW_ON_ERROR);
    foreach (['installed successfully', 'Cron cleanup:', 'uninstalled successfully'] as $event) {
      $this->assertStringContainsString($event, $serialized);
    }
    $this->assertStringNotContainsString('BCDF-GHJK', $serialized);
    foreach ($records as $record) {
      $this->assertSame('', $record['context']['request_uri']);
      $this->assertSame('', $record['context']['referer']);
    }
    $this->assertSame($request, $stack->getCurrentRequest());
    $stack->pop();
  }

  /**
   * Tests verification URIs with an empty stack and ordinary HTTP requests.
   */
  public function testVerificationUriRequestContexts(): void {
    $this->installConfig(['simple_oauth_device_flow']);
    $device = $this->container->get('entity_type.manager')->getStorage('oauth2_device_code')->create();
    $this->assertInstanceOf(DeviceCode::class, $device);
    $stack = $this->container->get('request_stack');
    $requests = [];
    while ($request = $stack->pop()) {
      $requests[] = $request;
    }
    try {
      $expected = Url::fromRoute('simple_oauth_device_flow.device_verification_form', [], ['absolute' => TRUE])->toString();
      $this->assertSame($expected, $device->getVerificationUri());
      foreach (['https://example.org/unrelated/path', 'http://example.org:8081/base/other'] as $uri) {
        $request = Request::create($uri);
        $stack->push($request);
        try {
          $this->assertSame($request->getSchemeAndHttpHost() . '/oauth/device/verify', $device->getVerificationUri());
        }
        finally {
          $this->assertSame($request, $stack->pop());
        }
      }
      $this->assertNull($stack->getCurrentRequest());
      $this->assertSame($expected, $device->getVerificationUri());
    }
    finally {
      foreach (array_reverse($requests) as $request) {
        $stack->push($request);
      }
    }
  }

}
