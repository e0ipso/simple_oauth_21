<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_device_flow\Kernel;

use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\simple_oauth_device_flow\Form\DeviceFlowSettingsForm;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Component\Datetime\TimeInterface;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Server\Grant\DeviceCodeGrant as LeagueDeviceCodeGrant;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Flood\DatabaseBackend;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\UserSession;
use Drupal\Core\Url;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\simple_oauth_device_flow\Repository\DeviceCodeMaintenanceInterface;
use Drupal\simple_oauth_device_flow\Repository\DeviceCodeStorageMaintenance;
use Drupal\simple_oauth\Oauth2ScopeProviderInterface;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\simple_oauth_device_flow\Repository\DeviceCodeRepository;
use Drupal\simple_oauth_device_flow\Repository\DeviceCodeLifecycleRepositoryInterface;
use Drupal\simple_oauth_device_flow\Repository\DeviceCodeLifecycleOperationsInterface;
use Drupal\simple_oauth_device_flow\Repository\DeviceVerificationOperationsInterface;
use Drupal\simple_oauth_device_flow\Repository\DeviceVerificationRepositoryInterface;
use Drupal\simple_oauth_device_flow\Repository\UserCodeLookupInterface;
use Drupal\simple_oauth_device_flow\DeviceGrantType;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth_device_flow\Controller\DeviceAuthorizationController;
use Drupal\simple_oauth_device_flow\Controller\DeviceVerificationController;
use Drupal\simple_oauth_device_flow\Form\DeviceVerificationForm;
use Drupal\simple_oauth_device_flow\Entity\DeviceCode;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Drupal\simple_oauth\Entities\ScopeEntity;
use Drupal\simple_oauth\Entities\ClientEntity;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\simple_oauth_device_flow\Service\DeviceCodeService;
use Drupal\simple_oauth_device_flow\Service\UserCodeGenerator;
use Drupal\simple_oauth_device_flow\Service\DeviceFlowSettingsService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

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
    // Verification retains its existing max(1, configured integer) semantics.
    foreach ([0, -1, 3] as $attempts) {
      $this->config('simple_oauth_device_flow.settings')->set('max_authorization_attempts', $attempts)->save();
      $this->assertSame($attempts, $settings->getMaxAuthorizationAttempts());
    }
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
    $this->assertSame(8, $config->get('user_code_length'));
    $this->assertSame('BCDFGHJKLMNPQRSTVWXZ', $config->get('user_code_charset'));
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
    $this->createDecisionUsers();

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
    $this->createDecisionUsers();
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
    $this->assertSame(0, $verification_form['#cache']['max-age']);
    $this->assertContains('user', $verification_form['#cache']['contexts']);
    $this->assertContains('url.query_args:user_code', $verification_form['#cache']['contexts']);
    $rendered = (string) $this->container->get('renderer')->renderRoot($verification_form);
    $this->assertStringContainsString('Review request', $rendered);
    $this->assertStringContainsString('form_token', $rendered);
    $assets = AttachedAssets::createFromRenderArray($verification_form);
    $libraries = $this->container->get('library.dependency_resolver')->getLibrariesWithDependencies($assets->getLibraries());
    foreach ($libraries as $library) {
      [$extension, $name] = explode('/', $library, 2);
      $this->assertNotFalse($this->container->get('library.discovery')->getLibraryByName($extension, $name));
    }
    $this->assertNotContains('simple_oauth_device_flow/device_verification', $libraries);
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
    $this->assertTrue($state->isRebuilding());
    $state->setRebuild(FALSE);
    $form_object->validateForm($form, $state);
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
    $form_object->submitForm($form, $denial_state);
    $form_object->validateForm($form, $denial_state);
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
    $this->installDeviceFixtures();
    $this->createDeviceClient('limiter-client');
    $user_storage = $this->container->get('entity_type.manager')->getStorage('user');
    foreach ([11, 12] as $id) {
      $user_storage->create(['uid' => $id, 'name' => 'limiter-user-' . $id, 'status' => 1])->save();
    }
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
    $after_window->validateForm($form, $valid_state);
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
          $this->assertSame(Url::fromRoute('simple_oauth_device_flow.device_verification_form', [], ['absolute' => TRUE])->toString(), $device->getVerificationUri());
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

  /**
   * Tests confidential credentials before any device code is issued.
   */
  public function testClientAuthenticationBeforeIssuance(): void {
    $this->installDeviceFixtures();
    $consumer = Consumer::create([
      'label' => 'Confidential device',
      'client_id' => 'confidential-device',
      'confidential' => TRUE,
      'secret' => 'correct-device-secret',
      'grant_types' => ['urn:ietf:params:oauth:grant-type:device_code'],
    ]);
    $consumer->save();
    $controller = DeviceAuthorizationController::create($this->container);
    $storage = $this->container->get('entity_type.manager')->getStorage('oauth2_device_code');
    foreach (['client_id', 'client_secret'] as $parameter) {
      $parameters = [
        'client_id' => $consumer->getClientId(),
        'client_secret' => 'correct-device-secret',
      ];
      $parameters[$parameter] = ['invalid'];
      $request = Request::create('/oauth/device_authorization', 'POST', $parameters);
      $response = $controller->authorize($request);
      $this->assertSame(400, $response->getStatusCode());
      $this->assertSame('invalid_request', json_decode($response->getContent(), TRUE)['error']);
      $this->assertSame(0, (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute());
    }
    $request = Request::create('/oauth/device_authorization', 'POST', [
      'client_id' => $consumer->getClientId(),
      'client_secret' => 'correct-device-secret',
    ]);
    $request->query->set('client_id', ['invalid']);
    $response = $controller->authorize($request);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('invalid_request', json_decode($response->getContent(), TRUE)['error']);
    $this->assertSame(0, (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute());
    foreach ([NULL, 'wrong-device-secret'] as $secret) {
      $parameters = ['client_id' => $consumer->getClientId()];
      if ($secret !== NULL) {
        $parameters['client_secret'] = $secret;
      }
      $response = $controller->authorize(Request::create('/oauth/device_authorization', 'POST', $parameters));
      $this->assertSame('invalid_client', json_decode($response->getContent(), TRUE)['error']);
      $this->assertSame(0, (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute());
    }
    $response = $controller->authorize(Request::create('/oauth/device_authorization', 'POST', [
      'client_id' => $consumer->getClientId(),
      'client_secret' => 'correct-device-secret',
    ]));
    $this->assertSame(200, $response->getStatusCode());
    $request = Request::create('/oauth/device_authorization', 'POST');
    $request->headers->set('Authorization', 'Basic ' . base64_encode('confidential-device:correct-device-secret'));
    $this->assertSame(200, $controller->authorize($request)->getStatusCode());
    $request->headers->set('Authorization', 'Basic ' . base64_encode('confidential-device:wrong-device-secret'));
    $this->assertSame('invalid_client', json_decode($controller->authorize($request)->getContent(), TRUE)['error']);

    $this->installEntitySchema('oauth2_token');
    $consumer->set('confidential', FALSE)->set('secret', NULL)->save();
    $this->assertSame(200, $controller->authorize(Request::create('/oauth/device_authorization', 'POST', [
      'client_id' => $consumer->getClientId(),
    ]))->getStatusCode());
  }

  /**
   * Tests public scope names resolve to IDs while legacy values still load.
   */
  public function testScopeNamesResolveBeforePersistence(): void {
    $this->installDeviceFixtures();
    $scope = $this->container->get('entity_type.manager')->getStorage('oauth2_scope')->create([
      'name' => 'user:auth:login',
      'description' => 'Sign in',
    ]);
    $scope->save();
    $consumer = Consumer::create([
      'label' => 'Scope client',
      'client_id' => 'scope-device',
      'confidential' => FALSE,
      'grant_types' => ['urn:ietf:params:oauth:grant-type:device_code'],
    ]);
    $consumer->save();
    $controller = DeviceAuthorizationController::create($this->container);
    $request = Request::create('/oauth/device_authorization', 'POST', [
      'client_id' => $consumer->getClientId(),
      'scope' => 'user:auth:login',
    ]);
    $response = $controller->authorize($request);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $device = $repository->getDeviceCodeEntityByDeviceCode($data['device_code']);
    $this->assertSame('user_auth_login', $device->get('scopes')->first()->scope_id);
    $this->assertSame('user:auth:login', $device->getScopes()[0]->getIdentifier());
    $device->set('scopes', [['scope_id' => 'user:auth:login']]);
    $this->assertSame('user:auth:login', $device->getScopes()[0]->getIdentifier());
    $device->addScope(new ScopeEntity($scope));
    $this->assertSame([
      ['scope_id' => 'user:auth:login'],
      ['scope_id' => 'user_auth_login'],
    ], $device->get('scopes')->getValue());
    foreach (['unknown-scope', 'user_auth_login'] as $invalid_scope) {
      $request->request->set('scope', $invalid_scope);
      $response = $controller->authorize($request);
      $this->assertSame(400, $response->getStatusCode());
      $this->assertSame('invalid_scope', json_decode($response->getContent(), TRUE)['error']);
      $this->assertSame(1, (int) $repository->getStorage()->getQuery()->accessCheck(FALSE)->count()->execute());
    }
  }

  /**
   * Tests a retained polling snapshot cannot undo a terminal revocation.
   */
  public function testStalePollingCannotUndoRevocation(): void {
    $this->installDeviceFixtures();
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $device = $repository->getStorage()->create([
      'device_code' => 'stale-poll-device',
      'user_code' => 'BCDF-GHJK',
      'client_id' => 'poll-client',
      'expires_at' => time() + 600,
    ]);
    $device->save();
    $snapshot = clone $device;
    $repository->revokeDeviceCode($device->getIdentifier());
    $snapshot->setLastPolledAt(new \DateTimeImmutable());
    $repository->persistDeviceCode($snapshot);
    $repository->getStorage()->resetCache();
    $this->assertTrue($repository->isDeviceCodeRevoked($device->getIdentifier()));
    $this->container->get('simple_oauth_device_flow.device_code_service')->validatePollingInterval(clone $device);
    $repository->getStorage()->resetCache();
    $this->assertTrue($repository->isDeviceCodeRevoked($device->getIdentifier()));
  }

  /**
   * Tests install hooks preserve shipped defaults and explicit legacy values.
   */
  public function testInstallPreservesConfiguration(): void {
    $this->installConfig(['simple_oauth_device_flow']);
    $this->container->get('module_handler')->loadInclude('simple_oauth_device_flow', 'install');
    $config = $this->config('simple_oauth_device_flow.settings');
    $defaults = $config->getRawData();
    \simple_oauth_device_flow_install(FALSE);
    $this->assertSame($defaults, $config->getRawData());
    $config->set('device_code_lifetime', 600)
      ->clear('verification_uri_complete')
      ->set('verification_uri_complete_enabled', FALSE)->save();
    $existing = $config->getRawData();
    \simple_oauth_device_flow_install(FALSE);
    $this->assertSame($existing, $config->getRawData());
    $this->assertSame($existing, $this->container->get('config.factory')->get('simple_oauth_device_flow.settings')->getRawData());
  }

  /**
   * Tests explicit verification URIs survive the League entity contract.
   */
  public function testSuppliedVerificationUriIsRetained(): void {
    $device = $this->container->get('entity_type.manager')->getStorage('oauth2_device_code')->create();
    $device->setUserCode('BCDF-GHJK');
    $device->setVerificationUri('https://example.org/base/device?language=en');
    $this->assertSame('https://example.org/base/device?language=en', $device->getVerificationUri());
    $this->assertSame('https://example.org/base/device?language=en&user_code=BCDF-GHJK', $device->getVerificationUriComplete());
  }

  /**
   * Tests confirmation identifies escaped client and scope data before consent.
   */
  public function testConfirmationAndStaleFormDecisions(): void {
    $this->installDeviceFixtures();
    $consumer = Consumer::create([
      'label' => '<script>client</script>',
      'client_id' => 'consent-client',
      'confidential' => FALSE,
      'grant_types' => ['urn:ietf:params:oauth:grant-type:device_code'],
    ]);
    $consumer->save();
    $scope = $this->container->get('entity_type.manager')->getStorage('oauth2_scope')->create([
      'name' => 'user:auth:login',
      'description' => '<script>scope</script>',
    ]);
    $scope->save();
    $client = $this->container->get('simple_oauth.repositories.client')->getClientEntity('consent-client');
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $service = $this->container->get('simple_oauth_device_flow.device_code_service');
    $account = $this->container->get('current_user');
    $account->setAccount(new UserSession(['uid' => 7]));
    $request = Request::create('https://example.org/oauth/device');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    foreach ([FALSE, TRUE] as $approved) {
      $data = $service->generateDeviceAuthorization($client, 'user:auth:login');
      $form_object = DeviceVerificationForm::create($this->container);
      $form = [];
      $first = (new FormState())->setValue('user_code', $data['user_code']);
      $second = (new FormState())->setValue('user_code', $data['user_code']);
      foreach ([$first, $second] as $state) {
        $form_object->validateForm($form, $state);
        $this->assertSame([], $state->getErrors());
        $form_object->submitForm($form, $state);
        $this->assertTrue($state->isRebuilding());
      }
      $pending = $repository->getDeviceCodeEntityByDeviceCode($data['device_code']);
      $this->assertFalse($pending->getUserApproved());
      $this->assertNull($pending->getUserIdentifier());
      $confirmation = $form_object->buildForm([], $first);
      $rendered = (string) $this->container->get('renderer')->renderRoot($confirmation);
      $this->assertStringContainsString('&lt;script&gt;client&lt;/script&gt;', $rendered);
      $this->assertStringContainsString('consent-client', $rendered);
      $this->assertStringContainsString('user:auth:login', $rendered);
      $this->assertStringContainsString('&lt;script&gt;scope&lt;/script&gt;', $rendered);
      $this->assertStringNotContainsString('<script>', $rendered);
      $this->assertSame(0, $confirmation['#cache']['max-age']);
      $form_object->validateForm($form, $first);
      $form_object->validateForm($form, $second);
      // Both requests validate pending state before saving a decision.
      if ($approved) {
        $form_object->submitForm($form, $first);
      }
      else {
        $form_object->submitDeny($form, $first);
      }
      $account->setAccount(new UserSession(['uid' => 8]));
      if ($approved) {
        $form_object->submitDeny($form, $second);
      }
      else {
        $form_object->submitForm($form, $second);
      }
      $repository->getStorage()->resetCache();
      $decided = $repository->getDeviceCodeEntityByDeviceCode($data['device_code']);
      $this->assertSame($approved, $decided->getUserApproved());
      $this->assertSame('7', $decided->getUserIdentifier());
      $account->setAccount(new UserSession(['uid' => 7]));
    }
    $this->container->get('request_stack')->pop();
  }

  /**
   * Tests reloading after lock acquisition and merging stale polls safely.
   */
  public function testTransitionReloadsAfterLockAndPreservesDecisions(): void {
    $this->installDeviceFixtures();
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $storage = $repository->getStorage();
    $this->createDeviceClient('locked-client');
    $device = $storage->create([
      'device_code' => 'locked-device',
      'user_code' => 'BCDF-GHJK',
      'client_id' => 'locked-client',
      'expires_at' => time() + 600,
    ]);
    $device->save();
    $snapshot = clone $device;
    $repository->decide($device->getIdentifier(), '7', TRUE);
    $snapshot->setLastPolledAt(new \DateTimeImmutable());
    $repository->persistDeviceCode($snapshot);
    $this->container->get('simple_oauth_device_flow.device_code_service')->validatePollingInterval($snapshot);
    $storage->resetCache();
    $approved = $storage->load($device->id());
    $this->assertTrue($approved->getUserApproved());
    $this->assertSame('7', $approved->getUserIdentifier());
    $this->assertNotNull($approved->getLastPolledAt());
    $poll_time = time() + 10;
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentTime')->willReturn($poll_time);
    $legacy_service = new DeviceCodeService(
      $repository,
      $this->container->get('simple_oauth_device_flow.user_code_generator'),
      $this->container->get('simple_oauth_device_flow.settings'),
      $clock,
      $this->container->get('simple_oauth_device_flow.logger_factory'),
    );
    $poll_snapshot = clone $device;
    $this->assertSame(['valid' => TRUE], $legacy_service->validatePollingInterval($poll_snapshot));
    $this->assertSame($poll_time, $poll_snapshot->getLastPolledAt()->getTimestamp());
    $storage->resetCache();
    $this->assertSame('7', $storage->load($device->id())->getUserIdentifier());
    // A stale decision snapshot must not replace an approved user.
    $snapshot->setUserIdentifier('8');
    $snapshot->setUserApproved(FALSE);
    try {
      $repository->persistDeviceCode($snapshot);
      $this->fail('A stale repository decision must be rejected.');
    }
    catch (\RuntimeException) {
      $storage->resetCache();
      $this->assertSame('7', $storage->load($device->id())->getUserIdentifier());
    }
    // Simulate another request committing just before this lock is acquired.
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('acquire')->willReturnCallback(function (string $name) use ($device): bool {
      $this->assertSame('simple_oauth_device_flow:' . hash('sha256', $device->getIdentifier()), $name);
      $this->container->get('database')->update('oauth2_device_code')
        ->fields(['authorized' => 0, 'user_id' => NULL, 'authorized_at' => time()])
        ->condition('device_code', $device->id())->execute();
      return TRUE;
    });
    $lock->expects($this->once())->method('release');
    $reloading = new DeviceCodeRepository(
      $this->container->get('entity_type.manager'),
      $this->container->get('simple_oauth_device_flow.settings'),
      $this->container->get('simple_oauth_device_flow.logger_factory'),
      $this->container->get('simple_oauth.repositories.client'),
      $lock,
    );
    $this->expectException(\DomainException::class);
    $reloading->decide($device->getIdentifier(), '8', TRUE);
  }

  /**
   * Tests unsupported League-only repositories fail verification safely.
   */
  public function testLeagueOnlyRepositoryFailsClosed(): void {
    $this->installConfig(['simple_oauth_device_flow']);
    $account = new UserSession(['uid' => 7]);
    $form_object = new DeviceVerificationForm(
      $this->createMock(DeviceCodeRepositoryInterface::class),
      $this->container->get('simple_oauth_device_flow.device_code_service'),
      $this->container->get('simple_oauth_device_flow.user_code_generator'),
      $account,
      $this->createMock(LoggerInterface::class),
      $this->container->get('flood'),
      $this->container->get('simple_oauth_device_flow.settings'),
    );
    $form = [];
    $state = (new FormState())->setValue('user_code', 'BCDF-GHJK');
    $form_object->validateForm($form, $state);
    $this->assertSame('Device verification is unavailable.', (string) $state->getErrors()['user_code']);
  }

  /**
   * Tests schema covers supported settings and rejects nonpositive limits.
   */
  public function testSupportedSettingsSchema(): void {
    $this->installConfig(['simple_oauth_device_flow']);
    $config = $this->config('simple_oauth_device_flow.settings');
    $values = [
      'max_authorization_attempts' => 3,
      'user_code_format' => 'XXXX-XXXX',
      'user_code_uppercase' => TRUE,
      'user_code_excluded_chars' => '01OIL',
      'cleanup_enabled' => TRUE,
      'cleanup_interval' => 3600,
    ];
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $manager = $this->container->get('config.typed');
    $typed = $manager->createFromNameAndData('simple_oauth_device_flow.settings', $config->getRawData());
    foreach ($values as $key => $value) {
      $this->assertSame($value, $typed->get($key)->getValue());
      $this->assertSame(is_int($value) ? 'integer' : (is_bool($value) ? 'boolean' : 'string'), $typed->get($key)->getDataDefinition()->getDataType());
      $this->assertSame(0, $typed->get($key)->validate()->count());
    }
    $typed->get('max_authorization_attempts')->setValue(0);
    $this->assertSame(1, $typed->get('max_authorization_attempts')->validate()->count());
  }

  /**
   * Tests aliases and application base paths in both issuance contracts.
   */
  public function testVerificationAliasAndBasePath(): void {
    $this->enableModules(['path_alias']);
    $this->installEntitySchema('path_alias');
    $this->installDeviceFixtures();
    PathAlias::create([
      'path' => '/oauth/device',
      'alias' => '/verify-device',
    ])->save();
    $this->config('simple_oauth_device_flow.settings')->set('verification_uri', '/verify-device')->save();
    $request = Request::create('https://example.org/base/other', 'GET', [], [], [], [
      'SCRIPT_NAME' => '/base/index.php',
      'PHP_SELF' => '/base/index.php',
      'SCRIPT_FILENAME' => '/index.php',
    ]);
    $this->assertSame('/base', $request->getBaseUrl());
    $this->container->get('request_stack')->push($request);
    $this->container->get('router.request_context')->fromRequest($request);
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $device = $repository->getNewDeviceCode();
    $this->assertSame('https://example.org/base/verify-device', $device->getVerificationUri());
    $consumer = Consumer::create([
      'label' => 'URI client',
      'client_id' => 'uri-client',
      'confidential' => FALSE,
      'grant_types' => ['urn:ietf:params:oauth:grant-type:device_code'],
    ]);
    $consumer->save();
    $plugin = $this->container->get('plugin.manager.oauth2_grant.processor')->createInstance('urn:ietf:params:oauth:grant-type:device_code');
    $grant = $plugin->getGrantType($consumer);
    $this->assertInstanceOf(LeagueDeviceCodeGrant::class, $grant);
    $grant->setClientRepository($this->container->get('simple_oauth.repositories.client'));
    $grant->setScopeRepository($this->container->get('simple_oauth.repositories.scope'));
    $grant->setDefaultScope('');
    $response = $grant->respondToDeviceAuthorizationRequest(
      (new ServerRequest('POST', '/oauth/device_authorization'))->withParsedBody(['client_id' => 'uri-client']),
    )->generateHttpResponse(new Response());
    $data = json_decode((string) $response->getBody(), TRUE);
    $this->assertSame('https://example.org/base/verify-device', $data['verification_uri']);
    $controller = DeviceAuthorizationController::create($this->container);
    $response = $controller->authorize(Request::create('/oauth/device_authorization', 'POST', ['client_id' => 'uri-client']));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('https://example.org/base/verify-device', json_decode($response->getContent(), TRUE)['verification_uri']);
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame($data['verification_uri'] . '?user_code=' . urlencode($data['user_code']), $data['verification_uri_complete']);
    $this->config('simple_oauth_device_flow.settings')->set('verification_uri_complete', FALSE)->save();
    $legacy = new DeviceAuthorizationController(
      $this->container->get('psr7.http_message_factory'),
      $this->container->get('simple_oauth.repositories.client'),
      $this->container->get('simple_oauth_device_flow.device_code_service'),
      $this->container->get('logger.channel.simple_oauth_device_flow'),
      $this->container->get('config.factory'), $this->container->get('path.validator'),
    );
    $legacy_data = json_decode($legacy->authorize(Request::create('/oauth/device_authorization', 'POST', ['client_id' => 'uri-client']))->getContent(), TRUE);
    $this->assertSame($data['verification_uri'], $legacy_data['verification_uri']);
    $this->assertArrayNotHasKey('verification_uri_complete', $legacy_data);
    $before = $repository->countDeviceCodes(time())['total_codes'];
    foreach ([
      '//example.org', 'https://example.org', '/user/login', '/missing',
      '/oauth/device?other=1', '/oauth/device#fragment', 123, ['invalid'],
    ] as $invalid) {
      // Model malformed imported storage without relaxing entity constraints.
      $raw = $this->config('simple_oauth_device_flow.settings')->getRawData();
      $raw['verification_uri'] = $invalid;
      $this->container->get('config.storage')->write('simple_oauth_device_flow.settings', $raw);
      $this->container->get('config.factory')->reset('simple_oauth_device_flow.settings');
      $response = $controller->authorize(Request::create('/oauth/device_authorization', 'POST', ['client_id' => 'uri-client']));
      $this->assertSame(500, $response->getStatusCode());
      $this->assertSame('server_error', json_decode($response->getContent(), TRUE)['error']);
      try {
        $plugin->getGrantType($consumer);
        $this->fail('Invalid imported paths must fail before grant issuance.');
      }
      catch (\UnexpectedValueException) {
        $this->assertSame($before, $repository->countDeviceCodes(time())['total_codes']);
      }
    }
    // The controller factory must use the configured shared settings boundary.
    $this->config('simple_oauth_device_flow.settings')->set('verification_uri', '/verify-device')->save();
    $validator = $this->createMock(PathValidatorInterface::class);
    $validator->expects($this->once())->method('getUrlIfValid')->willReturn(FALSE);
    $this->container->set('simple_oauth_device_flow.settings', new DeviceFlowSettingsService($this->container->get('config.factory'), $validator));
    $this->assertSame(500, DeviceAuthorizationController::create($this->container)->authorize(Request::create('/oauth/device_authorization', 'POST', ['client_id' => 'uri-client']))->getStatusCode());
    $this->assertSame($before, $repository->countDeviceCodes(time())['total_codes']);
    $this->container->get('request_stack')->pop();
  }

  /**
   * Tests expiry, repeated revocation and unavailable locks reject decisions.
   */
  public function testExpiredAndLockedDecisionsFailClosed(): void {
    $this->installDeviceFixtures();
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $storage = $repository->getStorage();
    $device = $storage->create([
      'device_code' => 'unavailable-device',
      'user_code' => 'BCDF-GHJK',
      'client_id' => 'unavailable-client',
      'expires_at' => time() - 1,
    ]);
    $device->save();
    try {
      $repository->decide($device->getIdentifier(), '7', TRUE);
      $this->fail('Expired codes must not be approved.');
    }
    catch (\DomainException) {
      $this->assertNull($storage->load($device->id())->getUserIdentifier());
    }
    $repository->revokeDeviceCode($device->getIdentifier());
    $revoked = $storage->load($device->id());
    $decision_time = (int) $revoked->get('authorized_at')->value - 60;
    $revoked->set('authorized_at', $decision_time)->save();
    $repository->revokeDeviceCode($device->getIdentifier());
    $this->assertSame($decision_time, (int) $storage->load($device->id())->get('authorized_at')->value);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('acquire')->willReturn(FALSE);
    $lock->expects($this->never())->method('release');
    $locked = new DeviceCodeRepository(
      $this->container->get('entity_type.manager'),
      $this->container->get('simple_oauth_device_flow.settings'),
      $this->container->get('simple_oauth_device_flow.logger_factory'),
      $this->container->get('simple_oauth.repositories.client'),
      $lock,
    );
    $this->expectException(\RuntimeException::class);
    $locked->decide($device->getIdentifier(), '7', TRUE);
  }

  /**
   * Tests real Form API submissions require review, CSRF and the bound request.
   */
  public function testFormApiReviewBindingAndCsrf(): void {
    $this->installDeviceFixtures();
    $consumer = Consumer::create([
      'label' => 'Form API client',
      'client_id' => 'form-api-client',
      'confidential' => FALSE,
      'grant_types' => ['urn:ietf:params:oauth:grant-type:device_code'],
    ]);
    $consumer->save();
    $client = $this->container->get('simple_oauth.repositories.client')->getClientEntity('form-api-client');
    $service = $this->container->get('simple_oauth_device_flow.device_code_service');
    $first = $service->generateDeviceAuthorization($client);
    $second = $service->generateDeviceAuthorization($client);
    // Issued records survive unsafe replacement generation settings.
    $this->config('simple_oauth_device_flow.settings')->set('user_code_length', 6)
      ->set('user_code_charset', 'AB')->set('user_code_format', 'XXXXXX')
      ->set('device_code_lifetime', 1)->set('max_authorization_attempts', 1)->save();
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 7]));
    $stack = $this->container->get('request_stack');
    $request = Request::create('https://example.org/oauth/device');
    $session = new Session(new MockArraySessionStorage());
    $request->setSession($session);
    $stack->push($request);
    $builder = $this->container->get('form_builder');
    $state = (new FormState())->disableRedirect();
    $form = $builder->buildForm(DeviceVerificationForm::class, $state);
    $input = [
      'form_id' => $form['#form_id'],
      'form_build_id' => $form['form_build_id']['#value'],
      'form_token' => $this->container->get('csrf_token')->get($form['#token']),
      'user_code' => $first['user_code'],
      'op' => 'Review request',
    ];
    $post = Request::create('https://example.org/oauth/device', 'POST', $input);
    $post->setSession($session);
    $stack->push($post);
    $review_state = (new FormState())->disableRedirect();
    $confirmation = $builder->buildForm(DeviceVerificationForm::class, $review_state);
    $this->assertSame([], $review_state->getErrors());
    $this->assertArrayHasKey('client', $confirmation);
    $this->assertNull($repository->getDeviceCodeEntityByDeviceCode($first['device_code'])->getUserIdentifier());
    $stack->pop();
    $input['form_build_id'] = $confirmation['form_build_id']['#value'];
    $input['user_code'] = $second['user_code'];
    $input['op'] = 'Authorize Device';
    $valid_token = $input['form_token'];
    $input['form_token'] = 'invalid-token';
    $invalid = Request::create('https://example.org/oauth/device', 'POST', $input);
    $invalid->setSession($session);
    $stack->push($invalid);
    $invalid_state = (new FormState())->disableRedirect();
    $builder->buildForm(DeviceVerificationForm::class, $invalid_state);
    $this->assertNotEmpty($invalid_state->getErrors());
    $this->assertNull($repository->getDeviceCodeEntityByDeviceCode($first['device_code'])->getUserIdentifier());
    $invalid_state->clearErrors();
    $stack->pop();
    $input['form_token'] = $valid_token;
    $approve = Request::create('https://example.org/oauth/device', 'POST', $input);
    $approve->setSession($session);
    $stack->push($approve);
    $approve_state = (new FormState())->disableRedirect();
    $builder->buildForm(DeviceVerificationForm::class, $approve_state);
    $this->assertSame([], $approve_state->getErrors());
    $repository->getStorage()->resetCache();
    $this->assertSame('7', $repository->getDeviceCodeEntityByDeviceCode($first['device_code'])->getUserIdentifier());
    $this->assertNull($repository->getDeviceCodeEntityByDeviceCode($second['device_code'])->getUserIdentifier());
    $stack->pop();
    $stack->pop();
  }

  /**
   * Tests user-code generation retries a collision through the lookup contract.
   */
  public function testFocusedUserCodeLookup(): void {
    $this->installDeviceFixtures();
    $storage = $this->container->get('entity_type.manager')->getStorage('oauth2_device_code');
    $logger_factory = $this->container->get('simple_oauth_device_flow.logger_factory');
    $lookup = $this->createMock(UserCodeLookupInterface::class);
    $lookup->expects($this->exactly(2))->method('getDeviceCodeEntityByUserCode')
      ->willReturnOnConsecutiveCalls($storage->create(), NULL);
    $generator = new UserCodeGenerator($this->container->get('config.factory'), $lookup, $logger_factory);
    $this->assertTrue($generator->validateCodeFormat($generator->generateUserCode()));
  }

  /**
   * Tests charset overrides keep their hook and satisfy the issuance policy.
   */
  public function testGeneratorCharsetExtensionPolicy(): void {
    $this->installDeviceFixtures();
    $this->config('simple_oauth_device_flow.settings')->set('user_code_length', 11)->save();
    $generator = new class($this->container->get('config.factory'), $this->container->get('simple_oauth_device_flow.user_code_lookup'), $this->container->get('simple_oauth_device_flow.logger_factory')) extends UserCodeGenerator {

      /**
       * {@inheritdoc}
       */
      protected function getCharset(): string {
        return '0123456789';
      }

    };
    $this->assertMatchesRegularExpression('/^[0-9]{11}$/D', $generator->generateUserCode());
    $weak = new class($this->container->get('config.factory'), $this->container->get('simple_oauth_device_flow.user_code_lookup'), $this->container->get('simple_oauth_device_flow.logger_factory')) extends UserCodeGenerator {

      /**
       * {@inheritdoc}
       */
      protected function getCharset(): string {
        return 'AB';
      }

    };
    $this->expectException(\RuntimeException::class);
    $weak->generateUserCode();
  }

  /**
   * Tests custom formatter codes retain bounded exact pending lookup.
   *
   * @param string $separator
   *   The formatter's separator, including multibyte output.
   * @param int $group_size
   *   The number of significant characters in each displayed group.
   *
   * @dataProvider customFormatterProvider
   */
  #[DataProvider('customFormatterProvider')]
  public function testCustomFormatterPendingCompatibility(string $separator, int $group_size): void {
    $this->installDeviceFixtures();
    $client = $this->createDeviceClient('custom-format-client');
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $settings = $this->container->get('simple_oauth_device_flow.settings');
    $logger = $this->container->get('simple_oauth_device_flow.logger_factory');
    $generator = new class($this->container->get('config.factory'), $repository, $logger) extends UserCodeGenerator {

      /**
       * The configured display separator.
       */
      public string $separator = ' ';

      /**
       * The number of significant characters per display group.
       */
      public int $groupSize = 4;

      /**
       * {@inheritdoc}
       */
      public function formatUserCode(string $rawCode): string {
        return implode($this->separator, str_split($rawCode, $this->groupSize));
      }

    };
    $generator->separator = $separator;
    $generator->groupSize = $group_size;
    $service = new DeviceCodeService($repository, $generator, $settings, $this->container->get('datetime.time'), $logger);
    $issued = $service->generateDeviceAuthorization($client);
    $this->assertStringContainsString($separator, $issued['user_code']);
    $this->assertTrue($generator->validateCodeFormat($issued['user_code']));
    $this->assertLessThanOrEqual(32, mb_strlen($issued['user_code'], 'UTF-8'));
    if ($group_size === 1) {
      $this->assertGreaterThan(32, strlen($issued['user_code']));
    }
    $form_object = new DeviceVerificationForm(
      $repository, $service, $generator, new UserSession(['uid' => 7]),
      $logger->get('simple_oauth_device_flow'), $this->container->get('flood'), $settings,
    );
    $stack = $this->container->get('request_stack');
    $stack->push(Request::create('https://example.org/oauth/device', 'GET', ['user_code' => $issued['user_code']]));
    try {
      $built = $form_object->buildForm([], new FormState());
      $this->assertSame($issued['user_code'], $built['user_code']['#default_value']);
    }
    finally {
      $stack->pop();
    }
    $form = [];
    foreach ([$issued['user_code'], str_replace($separator, '', $issued['user_code'])] as $input) {
      $state = (new FormState())->setValue('user_code', strtolower($input));
      $form_object->validateForm($form, $state);
      $this->assertSame([], $state->getErrors(), 'Issued custom formatting remains verifiable.');
      $this->assertSame($issued['device_code'], $state->getTemporaryValue('device_code_entity')->id());
    }
    // Exact historical output also survives replacing the formatter itself.
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 7]));
    $this->config('simple_oauth_device_flow.settings')->set('user_code_length', 6)->set('user_code_charset', 'AB')->save();
    $state = (new FormState())->setValue('user_code', $issued['user_code']);
    DeviceVerificationForm::create($this->container)->validateForm($form, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame($issued['device_code'], $state->getTemporaryValue('device_code_entity')->id());
  }

  /**
   * Provides formatter layouts accepted by the existing normalization API.
   *
   * @return array<string, array{string, int}>
   *   Separators and significant display group sizes.
   */
  public static function customFormatterProvider(): array {
    return [
      'space separator' => [' ', 4],
      'four-byte separator' => ["\u{1F539}", 1],
    ];
  }

  /**
   * Tests raw-generation extensions cannot bypass unsafe effective settings.
   */
  public function testGeneratorRawExtensionPolicy(): void {
    $this->installDeviceFixtures();
    $this->config('simple_oauth_device_flow.settings')->set('user_code_length', 6)->set('user_code_charset', 'AB')->save();
    $generator = new class($this->container->get('config.factory'), $this->container->get('simple_oauth_device_flow.user_code_lookup'), $this->container->get('simple_oauth_device_flow.logger_factory')) extends UserCodeGenerator {

      /**
       * {@inheritdoc}
       */
      protected function generateRawUserCode(): string {
        throw new \LogicException('Unsafe settings must be rejected before this hook.');
      }

    };
    $this->expectException(\UnexpectedValueException::class);
    $generator->generateUserCode();
  }

  /**
   * Tests service issuance validates before calling generator replacements.
   */
  public function testLifecycleRejectsUnsafeGeneratorSettings(): void {
    $this->installDeviceFixtures();
    $client = $this->createDeviceClient('extension-policy-client');
    $this->config('simple_oauth_device_flow.settings')->set('user_code_length', 6)->set('user_code_charset', 'AB')->save();
    $generator = $this->createMock(UserCodeGenerator::class);
    $generator->expects($this->never())->method('generateUserCode');
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $service = new DeviceCodeService(
      $repository, $generator, $this->container->get('simple_oauth_device_flow.settings'),
      $this->container->get('datetime.time'), $this->container->get('simple_oauth_device_flow.logger_factory'),
    );
    try {
      $service->generateDeviceAuthorization($client);
      $this->fail('Unsafe settings must stop issuance before any generator replacement.');
    }
    catch (\RuntimeException) {
      $this->assertSame(0, (int) $repository->getStorage()->getQuery()->accessCheck(FALSE)->count()->execute());
    }
  }

  /**
   * Tests malformed subclass output cannot issue a shorter pending code.
   */
  public function testGeneratorRejectsMalformedOutput(): void {
    $this->installDeviceFixtures();
    $client = $this->createDeviceClient('malformed-output-client');
    $generator = new class($this->container->get('config.factory'), $this->container->get('simple_oauth_device_flow.user_code_lookup'), $this->container->get('simple_oauth_device_flow.logger_factory')) extends UserCodeGenerator {

      /**
       * The number of raw generation attempts.
       */
      public int $calls = 0;

      /**
       * {@inheritdoc}
       */
      protected function generateRawUserCode(): string {
        $this->calls++;
        return 'BCDF';
      }

    };
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $service = new DeviceCodeService(
      $repository, $generator, $this->container->get('simple_oauth_device_flow.settings'),
      $this->container->get('datetime.time'), $this->container->get('simple_oauth_device_flow.logger_factory'),
    );
    try {
      $service->generateDeviceAuthorization($client);
      $this->fail('Malformed generator output must not create a pending code.');
    }
    catch (\RuntimeException) {
      $this->assertSame(UserCodeGenerator::MAX_GENERATION_ATTEMPTS, $generator->calls);
      $this->assertSame(0, (int) $repository->getStorage()->getQuery()->accessCheck(FALSE)->count()->execute());
    }
  }

  /**
   * Tests storage-free issuance, lookup, polling and bounded maintenance.
   *
   * @param bool $combined
   *   Whether the lifecycle replacement also provides maintenance.
   *
   * @dataProvider lifecycleMaintenanceProvider
   */
  #[DataProvider('lifecycleMaintenanceProvider')]
  public function testStorageFreeLifecycleRepository(bool $combined): void {
    // Assert the contract before construction to identify incompatible types.
    $constructor = new \ReflectionMethod(DeviceCodeService::class, '__construct');
    $this->assertSame(DeviceCodeLifecycleOperationsInterface::class, $constructor->getParameters()[0]->getType()->getName());
    $this->installDeviceFixtures();
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $this->assertInstanceOf(DeviceCodeLifecycleOperationsInterface::class, $repository);
    $this->assertInstanceOf(DeviceCodeLifecycleRepositoryInterface::class, $repository);
    $lifecycle = $combined
      ? $this->createMockForIntersectionOfInterfaces([
        DeviceCodeLifecycleOperationsInterface::class,
        DeviceCodeMaintenanceInterface::class,
      ])
      : $this->createMock(DeviceCodeLifecycleOperationsInterface::class);
    $this->assertFalse(method_exists($lifecycle, 'getStorage'));
    $this->assertNotInstanceOf(DeviceCodeLifecycleRepositoryInterface::class, $lifecycle);
    foreach (['getNewDeviceCode', 'persistDeviceCode', 'getDeviceCodeEntityByDeviceCode', 'recordPolling'] as $method) {
      $lifecycle->expects($this->atLeastOnce())->method($method)->willReturnCallback([$repository, $method]);
    }
    $maintenance = $combined ? $lifecycle : $this->createMock(DeviceCodeMaintenanceInterface::class);
    $now = $this->container->get('datetime.time')->getCurrentTime();
    $maintenance->expects($this->exactly(2))->method('countDeviceCodes')->with($now)
      ->willReturnCallback([$repository, 'countDeviceCodes']);
    $maintenance->expects($this->once())->method('deleteExpired')->with($now, 2)
      ->willReturnCallback([$repository, 'deleteExpired']);
    $maintenance->expects($this->once())->method('deleteOldAuthorized')->with($now - 7 * 86400, 2)
      ->willReturnCallback([$repository, 'deleteOldAuthorized']);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn($now);
    $this->config('simple_oauth_device_flow.settings')->set('max_cleanup_batch_size', 2)->save();
    $generator = $this->container->get('simple_oauth_device_flow.user_code_generator');
    if ($combined) {
      // Resolve the lifecycle dependency through the configured default alias.
      $this->container->set('simple_oauth_device_flow.device_code_repository', $lifecycle);
      $this->assertSame($lifecycle, $this->container->get('simple_oauth_device_flow.lifecycle_repository'));
    }
    $this->container->set('datetime.time', $time);
    $service = $combined
      ? $this->container->get('simple_oauth_device_flow.device_code_service')
      : new DeviceCodeService(
        $lifecycle, $generator,
        $this->container->get('simple_oauth_device_flow.settings'), $time,
        $this->container->get('simple_oauth_device_flow.logger_factory'), NULL, $maintenance,
      );
    $client = $this->createDeviceClient('focused-client');
    $scope = $this->container->get('entity_type.manager')->getStorage('oauth2_scope')->create([
      'name' => 'focused:read',
      'description' => 'Read',
    ]);
    $scope->save();
    $data = $service->generateDeviceAuthorization($client, 'focused:read');
    $entity = $lifecycle->getDeviceCodeEntityByDeviceCode($data['device_code']);
    $this->assertCount(0, $entity->validate());
    $this->assertSame($data['user_code'], $entity->getUserCode());
    $this->assertSame('focused-client', $entity->getClient()->getIdentifier());
    $this->assertSame('focused:read', $entity->getScopes()[0]->getIdentifier());
    $this->assertSame(['valid' => TRUE], $service->validatePollingInterval($entity));
    $this->assertSame('slow_down', $service->validatePollingInterval($entity)['error']);
    for ($number = 0; $number < 3; $number++) {
      $expired = $service->generateDeviceAuthorization($client);
      $expired_entity = $lifecycle->getDeviceCodeEntityByDeviceCode($expired['device_code']);
      $expired_entity->setExpiryDateTime(new \DateTimeImmutable('@' . ($now - 1)));
      $this->assertCount(0, $expired_entity->validate());
      $expired_entity->save();
      $authorized = $service->generateDeviceAuthorization($client);
      $repository->decide($authorized['device_code'], '7', TRUE);
      $authorized_entity = $lifecycle->getDeviceCodeEntityByDeviceCode($authorized['device_code']);
      $authorized_entity->set('created_at', $now - 8 * 86400);
      $this->assertCount(0, $authorized_entity->validate());
      $authorized_entity->save();
    }
    $statistics = $service->getStatistics();
    $this->assertSame($repository->countDeviceCodes($now) + [
      'timestamp' => $now,
      'retention_days' => 7,
      'cleanup_batch_size' => 2,
    ], $statistics);
    $this->assertSame(7, $statistics['total_codes']);
    $this->assertSame(3, $statistics['expired_codes']);
    $this->assertSame(3, $statistics['authorized_codes']);
    $this->assertIsInt($statistics['active_codes']);
    $result = $service->performCleanup();
    $this->assertSame(2, $result['expired_codes_deleted']);
    $this->assertSame(2, $result['old_authorized_codes_deleted']);
    $this->assertSame(4, $result['total_deleted']);
    $this->assertArrayHasKey('execution_time_ms', $result);
    $this->assertSame(3, $service->getStatistics()['total_codes']);
  }

  /**
   * Provides independent and repository-provided maintenance replacements.
   *
   * @return array<string, array{bool}>
   *   Whether maintenance is provided by the lifecycle replacement.
   */
  public static function lifecycleMaintenanceProvider(): array {
    return ['independent maintenance' => [FALSE], 'combined maintenance' => [TRUE]];
  }

  /**
   * Tests unavailable maintenance preserves public failure and retry behavior.
   */
  public function testStorageFreeLifecycleWithoutMaintenance(): void {
    $constructor = new \ReflectionMethod(DeviceCodeService::class, '__construct');
    $this->assertSame(DeviceCodeLifecycleOperationsInterface::class, $constructor->getParameters()[0]->getType()->getName());
    $this->installDeviceFixtures();
    $lifecycle = $this->createMock(DeviceCodeLifecycleOperationsInterface::class);
    $this->assertFalse(method_exists($lifecycle, 'getStorage'));
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->exactly(7))->method('error')->with(
      $this->anything(), ['@exception_type' => \RuntimeException::class],
    );
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->with('simple_oauth_device_flow')->willReturn($logger);
    $state = $this->container->get('state');
    $last_success = time() - 86400;
    $state->set('simple_oauth_device_flow.cleanup_last_success', $last_success);
    $service = new DeviceCodeService(
      $lifecycle, $this->container->get('simple_oauth_device_flow.user_code_generator'),
      $this->container->get('simple_oauth_device_flow.settings'),
      $this->container->get('datetime.time'), $factory, NULL, NULL,
      $state, $this->container->get('lock'),
    );
    $this->assertSame(0, $service->cleanupExpiredCodes());
    $this->assertSame(0, $service->cleanupOldAuthorizedCodes());
    $this->assertSame(0, $service->performCleanup()['total_deleted']);
    $this->assertNull($service->runScheduledCleanup());
    $this->assertSame($last_success, $state->get('simple_oauth_device_flow.cleanup_last_success'));
    $this->assertTrue($this->container->get('lock')->acquire('simple_oauth_device_flow.cleanup'));
    $this->container->get('lock')->release('simple_oauth_device_flow.cleanup');
    $statistics = $service->getStatistics();
    $this->assertSame('Device code maintenance operations are not available.', $statistics['error']);
    foreach (['active_codes', 'authorized_codes', 'expired_codes', 'total_codes'] as $key) {
      $this->assertSame(0, $statistics[$key]);
    }
    $this->assertIsInt($statistics['timestamp']);
  }

  /**
   * Tests the retained lifecycle storage contract uses bounded maintenance.
   */
  public function testLegacyLifecycleStorageFallback(): void {
    $this->installDeviceFixtures();
    $storage = $this->container->get('entity_type.manager')->getStorage('oauth2_device_code');
    $legacy = $this->createMock(DeviceCodeLifecycleRepositoryInterface::class);
    $legacy->expects($this->atLeastOnce())->method('getStorage')->willReturn($storage);
    $client = $this->createDeviceClient('legacy-storage-client');
    $this->container->get('simple_oauth_device_flow.device_code_service')->generateDeviceAuthorization($client);
    $legacy_service = new DeviceCodeService(
      $legacy, $this->container->get('simple_oauth_device_flow.user_code_generator'),
      $this->container->get('simple_oauth_device_flow.settings'),
      $this->container->get('datetime.time'), $this->container->get('simple_oauth_device_flow.logger_factory'),
    );
    $this->assertInstanceOf(DeviceCodeLifecycleOperationsInterface::class, $legacy);
    $this->assertSame(1, $legacy_service->getStatistics()['total_codes']);
    $this->assertSame(0, $legacy_service->performCleanup()['total_deleted']);
  }

  /**
   * Tests the ordinary form factory with only focused verification operations.
   */
  public function testFocusedVerificationFactory(): void {
    $this->installDeviceFixtures();
    $client = $this->createDeviceClient('focused-client', 'Focused application');
    $service = $this->container->get('simple_oauth_device_flow.device_code_service');
    $data = $service->generateDeviceAuthorization($client);
    $entity = $this->container->get('entity_type.manager')->getStorage('oauth2_device_code')->load($data['device_code']);
    $entity->setClient($client);
    $this->container->get('request_stack')->push(Request::create('https://example.org/oauth/device'));
    $verification = $this->createMock(DeviceVerificationOperationsInterface::class);
    // Each validation checks both exact layouts to detect ambiguity.
    $candidates = [];
    $verification->expects($this->exactly(4))->method('getDeviceCodeEntityByUserCode')
      ->willReturnCallback(static function (string $candidate) use ($entity, $data, &$candidates): ?DeviceCode {
        $candidates[] = $candidate;
        return $candidate === $data['user_code'] ? $entity : NULL;
      });
    $verification->expects($this->exactly(2))->method('isDeviceCodeRevoked')
      ->with($data['device_code'])->willReturn(FALSE);
    $verification->expects($this->once())->method('getDeviceCodeEntityByDeviceCode')
      ->with($data['device_code'])->willReturn($entity);
    $verification->expects($this->once())->method('decide')->with($data['device_code'], '7', TRUE);
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 7]));
    $form_container = new ContainerBuilder();
    foreach ([
      'simple_oauth_device_flow.device_code_service',
      'simple_oauth_device_flow.user_code_generator',
      'current_user', 'logger.channel.simple_oauth_device_flow', 'flood',
      'simple_oauth_device_flow.settings',
    ] as $id) {
      $form_container->set($id, $this->container->get($id));
    }
    $form_container->set('simple_oauth_device_flow.verification_repository', $verification);
    $form_container->set('simple_oauth_device_flow.device_code_repository', $verification);
    $form_object = DeviceVerificationForm::create($form_container);
    $form = [];
    $state = (new FormState())->setValue('user_code', $data['user_code']);
    $form_object->validateForm($form, $state);
    $this->assertSame([], $state->getErrors());
    $form_object->submitForm($form, $state);
    $this->assertTrue($state->isRebuilding());
    $review = $form_object->buildForm([], $state);
    $this->assertSame('Focused application (focused-client)', $review['client']['#plain_text']);
    $this->assertSame(0, $review['#cache']['max-age']);
    $state->setRebuild(FALSE);
    $form_object->validateForm($form, $state);
    $this->assertSame([], $state->getErrors());
    $raw = str_replace('-', '', $data['user_code']);
    $this->assertSame([$raw, $data['user_code'], $raw, $data['user_code']], $candidates);
    $form_object->submitForm($form, $state);
    $this->assertSame('simple_oauth_device_flow.device_verification_form', $state->getRedirect()->getRouteName());
    $this->container->get('request_stack')->pop();
  }

  /**
   * Tests the original repository, generator and lifecycle constructor calls.
   */
  public function testLegacyRepositoryConstructors(): void {
    $this->installDeviceFixtures();
    $factory = $this->container->get('simple_oauth_device_flow.logger_factory');
    $settings = $this->container->get('simple_oauth_device_flow.settings');
    $repository = new DeviceCodeRepository(
      $this->container->get('entity_type.manager'), $settings, $factory,
      $this->container->get('simple_oauth.repositories.client'),
    );
    $generator = new UserCodeGenerator($this->container->get('config.factory'), $repository, $factory);
    $service = new DeviceCodeService($repository, $generator, $settings, $this->container->get('datetime.time'), $factory);
    $client = $this->createDeviceClient('legacy-client');
    $data = $service->generateDeviceAuthorization($client);
    $entity = $repository->getDeviceCodeEntityByUserCode($data['user_code']);
    $this->assertSame($data['device_code'], $entity->getIdentifier());
    $this->assertSame(['valid' => TRUE], $service->validatePollingInterval($entity));
    $repository->decide($data['device_code'], '7', FALSE);
    $this->assertSame('7', $repository->getDeviceCodeEntityByUserCode($data['user_code'])->getUserIdentifier());
    $this->assertSame(1, $service->getStatistics()['total_codes']);
    $this->assertSame(0, $service->performCleanup()['total_deleted']);
    $this->assertSame(0, $service->runScheduledCleanup()['total_deleted']);
    $this->assertNotNull($this->container->get('state')->get('simple_oauth_device_flow.cleanup_last_success'));
  }

  /**
   * Tests the combined public interface remains a League-compatible decorator.
   */
  public function testLegacyCombinedRepositoryConstructor(): void {
    $this->installConfig(['simple_oauth_device_flow']);
    $repository = $this->createMock(DeviceVerificationRepositoryInterface::class);
    $this->assertInstanceOf(DeviceCodeRepositoryInterface::class, $repository);
    $repository->expects($this->exactly(2))->method('getDeviceCodeEntityByUserCode')->willReturn(NULL);
    $form_object = new DeviceVerificationForm(
      $repository, $this->container->get('simple_oauth_device_flow.device_code_service'),
      $this->container->get('simple_oauth_device_flow.user_code_generator'),
      new UserSession(['uid' => 7]), $this->createMock(LoggerInterface::class),
      $this->container->get('flood'), $this->container->get('simple_oauth_device_flow.settings'),
    );
    $form = [];
    $state = (new FormState())->setValue('user_code', 'BCDF-GHJK');
    $form_object->validateForm($form, $state);
    $this->assertStringContainsString('Invalid user code.', (string) $state->getErrors()['user_code']);
  }

  /**
   * Tests controller/plugin eligibility and authentication for saved aliases.
   */
  public function testDeviceGrantEligibility(): void {
    $this->installDeviceFixtures();
    $this->installEntitySchema('oauth2_token');
    $consumer = Consumer::create([
      'label' => 'Grant eligibility',
      'client_id' => 'eligibility-client',
      'confidential' => TRUE,
      'secret' => 'eligibility-secret',
      'grant_types' => [DeviceGrantType::CANONICAL],
    ]);
    $consumer->save();
    $controller = DeviceAuthorizationController::create($this->container);
    $plugin = $this->container->get('plugin.manager.oauth2_grant.processor')->createInstance(DeviceGrantType::CANONICAL);
    $this->assertSame(DeviceGrantType::CANONICAL, $plugin->getGrantType($consumer)->getIdentifier());
    foreach ([
      [DeviceGrantType::CANONICAL],
      [DeviceGrantType::LEGACY],
      [DeviceGrantType::LEGACY, DeviceGrantType::CANONICAL],
      ['authorization_code'],
    ] as $grants) {
      $consumer->set('grant_types', $grants)->save();
      $eligible = $grants !== ['authorization_code'];
      $request = Request::create('/oauth/device_authorization', 'POST', [
        'client_id' => 'eligibility-client',
        'client_secret' => 'wrong-secret',
      ]);
      $this->assertSame('invalid_client', json_decode($controller->authorize($request)->getContent(), TRUE)['error']);
      $request->request->set('client_secret', 'eligibility-secret');
      $response = $controller->authorize($request);
      $this->assertSame($eligible ? 200 : 400, $response->getStatusCode());
      if (!$eligible) {
        $this->assertSame('unauthorized_client', json_decode($response->getContent(), TRUE)['error']);
      }
      $this->assertSame($grants, array_column($consumer->get('grant_types')->getValue(), 'value'));
    }
  }

  /**
   * Tests the cron bridge honors disabled cleanup and the configured interval.
   */
  public function testCronRespectsCleanupSettings(): void {
    $this->installDeviceFixtures();
    $this->createDeviceClient('cron-client');
    $storage = $this->container->get('entity_type.manager')->getStorage('oauth2_device_code');
    $create_expired = static function (string $id) use ($storage): void {
      $storage->create([
        'device_code' => $id,
        'user_code' => $id,
        'client_id' => 'cron-client',
        'created_at' => time() - 100,
        'expires_at' => time() - 1,
      ])->save();
    };
    $settings = $this->config('simple_oauth_device_flow.settings');
    $settings->set('cleanup_enabled', FALSE)->save();
    $this->container->get('module_handler')->loadInclude('simple_oauth_device_flow', 'module');
    $create_expired('disabled');
    simple_oauth_device_flow_cron();
    $this->assertNotNull($storage->load('disabled'));
    $settings->set('cleanup_enabled', TRUE)->set('cleanup_interval', 3600)->save();
    simple_oauth_device_flow_cron();
    $this->assertNull($storage->load('disabled'));
    $create_expired('too-soon');
    simple_oauth_device_flow_cron();
    $this->assertNotNull($storage->load('too-soon'));
    $this->container->get('state')->set('simple_oauth_device_flow.cleanup_last_success', time() - 3601);
    simple_oauth_device_flow_cron();
    $this->assertNull($storage->load('too-soon'));
  }

  /**
   * Tests scheduler controls, locking, retry and manual cleanup independence.
   */
  public function testScheduledCleanupAndFailureRetry(): void {
    $this->installDeviceFixtures();
    $records = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('log')->willReturnCallback(static function ($level, $message, array $context = []) use (&$records): void {
      $records[] = ['message' => (string) $message, 'context' => $context];
    });
    $this->container->get('simple_oauth_device_flow.logger_factory')->addLogger($logger);
    $now = 10000;
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(static function () use (&$now): int {
      return $now;
    });
    $state = $this->container->get('state');
    $lock = new DatabaseLockBackend($this->container->get('database'));
    $calls = 0;
    $fail = FALSE;
    $fail_authorized = FALSE;
    $maintenance = $this->createMock(DeviceCodeMaintenanceInterface::class);
    $maintenance->method('deleteExpired')->willReturnCallback(static function (int $timestamp, int $limit) use (&$calls, &$fail, &$fail_authorized): int {
      $calls++;
      if ($fail) {
        throw new \RuntimeException('credential-sentinel');
      }
      return $fail_authorized ? 1 : 0;
    });
    $maintenance->method('deleteOldAuthorized')->willReturnCallback(static function () use (&$fail_authorized): int {
      if ($fail_authorized) {
        throw new \RuntimeException('credential-sentinel');
      }
      return 0;
    });
    $service = new DeviceCodeService(
      $this->container->get('simple_oauth_device_flow.lifecycle_repository'),
      $this->container->get('simple_oauth_device_flow.user_code_generator'),
      $this->container->get('simple_oauth_device_flow.settings'), $time,
      $this->container->get('simple_oauth_device_flow.logger_factory'), NULL,
      $maintenance, $state, $lock,
    );
    $key = 'simple_oauth_device_flow.cleanup_last_success';
    $settings = $this->config('simple_oauth_device_flow.settings');
    $settings->set('cleanup_enabled', FALSE)->save();
    $this->assertNull($service->runScheduledCleanup());
    $this->assertSame(0, $calls);
    $this->assertNull($state->get($key));
    // Both absent settings use their existing defaults.
    $settings->clear('cleanup_enabled')->clear('cleanup_interval')->save();
    $result = $service->runScheduledCleanup();
    $this->assertSame(['expired_codes_deleted', 'old_authorized_codes_deleted', 'total_deleted', 'execution_time_ms'], array_keys($result));
    $this->assertSame(0, $result['total_deleted']);
    $this->assertSame($now, $state->get($key));
    $this->assertSame(1, $calls);
    $now += 3599;
    $this->assertNull($service->runScheduledCleanup());
    $this->assertSame(1, $calls);
    $now++;
    $this->assertSame(0, $service->runScheduledCleanup()['total_deleted']);
    $this->assertSame(2, $calls);
    $settings->set('cleanup_interval', 10)->save();
    $now += 10;
    // A distinct backend simulates another worker holding the scheduling lock.
    $other_lock = new DatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other_lock->acquire('simple_oauth_device_flow.cleanup'));
    $this->assertNull($service->runScheduledCleanup());
    $this->assertSame(2, $calls);
    $other_lock->release('simple_oauth_device_flow.cleanup');
    $last_success = $state->get($key);
    $fail = TRUE;
    $this->assertNull($service->runScheduledCleanup());
    $this->assertSame($last_success, $state->get($key));
    $this->assertTrue($other_lock->acquire('simple_oauth_device_flow.cleanup'));
    $other_lock->release('simple_oauth_device_flow.cleanup');
    $fail = FALSE;
    $this->assertSame(0, $service->runScheduledCleanup()['total_deleted']);
    $this->assertSame($now, $state->get($key));
    $settings->set('cleanup_enabled', FALSE)->save();
    $manual = $service->performCleanup();
    $this->assertSame(0, $manual['total_deleted']);
    $this->assertSame(5, $calls);
    $this->assertSame($now, $state->get($key));
    $settings->set('cleanup_enabled', TRUE)->save();
    $now += 10;
    $fail_authorized = TRUE;
    $this->assertNull($service->runScheduledCleanup());
    $this->assertSame($now - 10, $state->get($key));
    $fail_authorized = FALSE;
    // State write failures also release the lock and do not mark success.
    $broken_state = $this->createMock(StateInterface::class);
    $broken_state->method('set')->willThrowException(new \RuntimeException('state failure'));
    $broken = new DeviceCodeService(
      $this->container->get('simple_oauth_device_flow.lifecycle_repository'),
      $this->container->get('simple_oauth_device_flow.user_code_generator'),
      $this->container->get('simple_oauth_device_flow.settings'), $time,
      $this->container->get('simple_oauth_device_flow.logger_factory'), NULL,
      $maintenance, $broken_state, $lock,
    );
    try {
      $broken->runScheduledCleanup();
      $this->fail('A failed state write must propagate to the hook bridge.');
    }
    catch (\RuntimeException) {
      $this->assertSame($now - 10, $state->get($key));
      $this->assertTrue($other_lock->acquire('simple_oauth_device_flow.cleanup'));
      $other_lock->release('simple_oauth_device_flow.cleanup');
    }
    $this->container->get('module_handler')->loadInclude('simple_oauth_device_flow', 'install');
    simple_oauth_device_flow_uninstall(TRUE);
    $this->assertNull($state->get($key));
    $serialized = json_encode($records, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('credential-sentinel', $serialized);
    $this->assertStringContainsString('Failed to cleanup expired device codes', $serialized);
    $this->assertStringContainsString('Failed to cleanup old authorized device codes', $serialized);
  }

  /**
   * Tests bounded deletion, retention in days and aggregate query contracts.
   */
  public function testBoundedMaintenanceOperations(): void {
    $this->installDeviceFixtures();
    $this->createDeviceClient('maintenance-client');
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $storage = $repository->getStorage();
    $now = time();
    foreach (range(1, 5) as $number) {
      $storage->create([
        'device_code' => 'expired-' . $number,
        'user_code' => 'expired-' . $number,
        'client_id' => 'maintenance-client',
        'created_at' => $now - 100,
        'expires_at' => $now - 1,
      ])->save();
      $storage->create([
        'device_code' => 'authorized-' . $number,
        'user_code' => 'authorized-' . $number,
        'client_id' => 'maintenance-client',
        'created_at' => $now - 8 * 86400,
        'expires_at' => $now + 100,
        'authorized' => TRUE,
        'user_id' => 7,
      ])->save();
    }
    $storage->create([
      'device_code' => 'retained',
      'user_code' => 'retained',
      'client_id' => 'maintenance-client',
      'created_at' => $now - 6 * 86400,
      'expires_at' => $now + 100,
      'authorized' => TRUE,
      'user_id' => 7,
    ])->save();
    $this->config('simple_oauth_device_flow.settings')->set('max_cleanup_batch_size', 2)->set('cleanup_retention_days', 7)->save();
    $service = $this->container->get('simple_oauth_device_flow.device_code_service');
    $this->assertSame(11, $service->getStatistics()['total_codes']);
    $this->assertSame(4, $service->performCleanup()['total_deleted']);
    $this->assertSame(7, $service->getStatistics()['total_codes']);
    $this->assertSame(2, $repository->cleanupExpiredDeviceCodes());
    $adapter = new DeviceCodeStorageMaintenance($this->container->get('entity_type.manager'));
    $this->assertSame(1, $adapter->deleteExpired($now, 2));
    $this->assertSame(2, $adapter->deleteOldAuthorized($now - 7 * 86400, 2));
    $this->assertSame(1, $adapter->deleteOldAuthorized($now - 7 * 86400, 2));
    $this->assertSame(['active_codes' => 0, 'authorized_codes' => 1, 'expired_codes' => 0, 'total_codes' => 1], $adapter->countDeviceCodes($now));
    $this->assertNotNull($storage->load('retained'));
  }

  /**
   * Tests validation of completed issuance and mutable reference integrity.
   */
  public function testCompletedIssuanceValidation(): void {
    $this->installDeviceFixtures();
    $this->createDeviceClient('integrity-client');
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $scope = $this->container->get('entity_type.manager')->getStorage('oauth2_scope')->create([
      'name' => 'integrity:read',
      'description' => 'Read',
    ]);
    $scope->save();
    $valid = [
      'device_code' => 'valid-integrity',
      'user_code' => 'BCDF-GHJK',
      'client_id' => 'integrity-client',
      'created_at' => time(),
      'expires_at' => time() + 600,
      'scopes' => [['scope_id' => $scope->id()]],
    ];
    $invalid = [];
    foreach (['device_code' => 129, 'user_code' => 33, 'client_id' => 129] as $field => $length) {
      $invalid[] = [$field => str_repeat('x', $length)];
    }
    foreach (['device_code', 'user_code', 'client_id', 'created_at', 'expires_at'] as $field) {
      $invalid[] = [$field => NULL];
    }
    foreach (['device_code', 'user_code', 'client_id'] as $field) {
      $invalid[] = [$field => ''];
    }
    $invalid[] = ['client_id' => 'missing-client'];
    $invalid[] = ['authorized' => TRUE];
    $invalid[] = ['user_id' => 999];
    $invalid[] = ['scopes' => [['scope_id' => 'missing-scope']]];
    foreach ($invalid as $changes) {
      $entity = $repository->getNewDeviceCode();
      foreach ($changes + $valid as $field => $value) {
        $entity->set($field, $value);
      }
      $rejected = FALSE;
      try {
        $repository->persistDeviceCode($entity);
      }
      catch (\RuntimeException $exception) {
        $rejected = TRUE;
        $this->assertSame('Unexpected error during device code persistence', $exception->getMessage());
      }
      $this->assertTrue($rejected, 'Invalid completed issuance must not persist: ' . implode(',', array_keys($changes)));
      $this->assertSame(0, (int) $repository->getStorage()->getQuery()->accessCheck(FALSE)->count()->execute());
    }
    $entity = $repository->getNewDeviceCode();
    foreach ($valid as $field => $value) {
      $entity->set($field, $value);
    }
    $this->assertCount(0, $entity->validate());
    $repository->persistDeviceCode($entity);
    $repository->decide($entity->getIdentifier(), '7', TRUE);
    $this->assertSame('7', $repository->getDeviceCodeEntityByDeviceCode($entity->getIdentifier())->getUserIdentifier());
  }

  /**
   * Tests deleted users, scopes and public clients leave decisions unchanged.
   */
  public function testDeletedDecisionReferences(): void {
    $this->installDeviceFixtures();
    $this->installEntitySchema('oauth2_token');
    $this->installSchema('user', ['users_data']);
    $manager = $this->container->get('entity_type.manager');
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $service = $this->container->get('simple_oauth_device_flow.device_code_service');
    foreach (['user', 'scope', 'client'] as $deleted) {
      $id = 'deleted-' . $deleted;
      $client = $this->createDeviceClient($id);
      $scope = $manager->getStorage('oauth2_scope')->create(['name' => 'read:' . $deleted, 'description' => 'Read']);
      $scope->save();
      $user = $manager->getStorage('user')->create(['name' => $id, 'status' => 1]);
      $user->save();
      $data = $service->generateDeviceAuthorization($client, 'read:' . $deleted);
      $entity = $repository->getDeviceCodeEntityByDeviceCode($data['device_code']);
      // Historical names remain eligible without rewriting the field values.
      $entity->set('scopes', [['scope_id' => 'read:' . $deleted]])->save();
      $before = $manager->getStorage('oauth2_device_code')->load($entity->id())->toArray();
      match ($deleted) {
        'user' => $user->delete(),
        'scope' => $scope->delete(),
        'client' => $client->getDrupalEntity()->delete(),
      };
      try {
        $repository->decide($entity->getIdentifier(), (string) $user->id(), TRUE);
        $this->fail('A deleted reference must reject the decision.');
      }
      catch (\DomainException) {
        $repository->getStorage()->resetCache();
        $this->assertSame($before, $repository->getStorage()->load($entity->id())->toArray());
      }
      if ($deleted !== 'user') {
        $stale = $repository->getStorage()->load($entity->id());
        $stale->setUserIdentifier((string) $user->id());
        $stale->setUserApproved(TRUE);
        try {
          $repository->persistDeviceCode($stale);
          $this->fail('Legacy decision persistence must check fresh references.');
        }
        catch (\RuntimeException) {
          $repository->getStorage()->resetCache();
          $this->assertSame($before, $repository->getStorage()->load($entity->id())->toArray());
        }
      }
    }
    $client = $this->createDeviceClient('legacy-scope-client');
    $scope = $manager->getStorage('oauth2_scope')->create(['name' => 'legacy:read', 'description' => 'Read']);
    $scope->save();
    $data = $service->generateDeviceAuthorization($client, 'legacy:read');
    $entity = $repository->getDeviceCodeEntityByDeviceCode($data['device_code']);
    $entity->set('scopes', [['scope_id' => 'legacy:read']])->save();
    $repository->decide($entity->getIdentifier(), '7', FALSE);
    $loaded = $repository->getDeviceCodeEntityByDeviceCode($entity->getIdentifier());
    $this->assertSame([['scope_id' => 'legacy:read']], $loaded->get('scopes')->getValue());
    $this->assertSame('legacy:read', $loaded->getScopes()[0]->getIdentifier());
  }

  /**
   * Tests supplied scope providers on issuance, reload and serialization.
   */
  public function testInjectedScopeProviderAndSerialization(): void {
    $this->installDeviceFixtures();
    $client = $this->createDeviceClient('provider-client');
    $scope = $this->container->get('entity_type.manager')->getStorage('oauth2_scope')->create([
      'name' => 'provider:read',
      'description' => 'Read',
    ]);
    $scope->save();
    $provider = $this->createMock(Oauth2ScopeProviderInterface::class);
    $provider->method('load')->willReturnCallback(static fn(string $id) => $id === 'provider_read' ? $scope : NULL);
    $provider->method('loadByName')->willReturnCallback(static fn(string $name) => $name === 'provider:read' ? $scope : NULL);
    $repository = new DeviceCodeRepository(
      $this->container->get('entity_type.manager'),
      $this->container->get('simple_oauth_device_flow.settings'),
      $this->container->get('simple_oauth_device_flow.logger_factory'),
      $this->container->get('simple_oauth.repositories.client'),
      $this->container->get('lock'), $this->container->get('datetime.time'), $provider,
    );
    $entity = $repository->getNewDeviceCode();
    // An unavailable global provider detects selection by field helpers.
    $global = $this->container->get('simple_oauth.oauth2_scope.provider');
    $unavailable = $this->createMock(Oauth2ScopeProviderInterface::class);
    $unavailable->method('load')->willReturn(NULL);
    $unavailable->method('loadByName')->willReturn(NULL);
    $this->container->set('simple_oauth.oauth2_scope.provider', $unavailable);
    try {
      $entity->set('scopes', [['scope_id' => 'provider_read']]);
      $this->assertCount(1, $entity->getScopes());
      $entity->set('scopes', []);
      $entity->addScope(new ScopeEntity($scope));
      $this->assertSame([['scope_id' => 'provider_read']], $entity->get('scopes')->getValue());
      $this->assertSame('provider:read', $entity->getScopes()[0]->getIdentifier());
      $entity->set('scopes', [['scope_id' => 'provider:read']]);
      $this->assertSame('provider:read', $entity->getScopes()[0]->getIdentifier());
    }
    finally {
      $this->container->set('simple_oauth.oauth2_scope.provider', $global);
    }
    $entity->set('scopes', [['scope_id' => 'provider_read']]);
    $entity->setIdentifier('provider-device');
    $entity->setUserCode('BCDF-GHJK');
    $entity->setClient($client);
    $entity->setExpiryDateTime(new \DateTimeImmutable('+10 minutes'));
    $repository->persistDeviceCode($entity);
    $repository->getStorage()->resetCache();
    $this->container->set('simple_oauth.oauth2_scope.provider', $unavailable);
    try {
      $loaded = $repository->getDeviceCodeEntityByDeviceCode('provider-device');
      $this->assertSame('provider:read', $loaded->getScopes()[0]->getIdentifier());
      $repository->decide('provider-device', '7', TRUE);
      $this->assertSame('provider:read', $repository->getDeviceCodeEntityByUserCode('BCDF-GHJK')->getScopes()[0]->getIdentifier());
    }
    finally {
      $this->container->set('simple_oauth.oauth2_scope.provider', $global);
    }
    // Normal entities serialize service references through Drupal's trait.
    $normal = $this->container->get('simple_oauth_device_flow.device_code_repository')->getDeviceCodeEntityByDeviceCode('provider-device');
    $restored = unserialize(serialize($normal), [
      'allowed_classes' => [DeviceCode::class, ClientEntity::class, Consumer::class],
    ]);
    $this->assertSame('provider:read', $restored->getScopes()[0]->getIdentifier());
    $this->assertSame('provider-client', $restored->getClient()->getIdentifier());
  }

  /**
   * Tests optional static scopes through issuance, reload and authorization.
   */
  public function testStaticScopeRoundtrip(): void {
    $this->enableModules(['simple_oauth_static_scope', 'simple_oauth_static_scope_example']);
    $this->installDeviceFixtures();
    $this->installConfig(['user']);
    $this->config('simple_oauth.settings')->set('scope_provider', 'static')->save();
    $client = $this->createDeviceClient('static-client');
    $service = $this->container->get('simple_oauth_device_flow.device_code_service');
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $data = $service->generateDeviceAuthorization($client, 'static_scope:child');
    $repository->getStorage()->resetCache();
    $entity = $repository->getDeviceCodeEntityByDeviceCode($data['device_code']);
    $provider = $this->container->get('simple_oauth.oauth2_scope.provider');
    $this->assertSame([['scope_id' => $provider->loadByName('static_scope:child')->id()]], $entity->get('scopes')->getValue());
    $this->assertSame('static_scope:child', $entity->getScopes()[0]->getIdentifier());
    $repository->decide($entity->getIdentifier(), '7', TRUE);
    $this->assertTrue($repository->getDeviceCodeEntityByUserCode($entity->getUserCode())->getUserApproved());
    $restored = unserialize(serialize($entity), [
      'allowed_classes' => [DeviceCode::class, ClientEntity::class, Consumer::class],
    ]);
    $this->assertSame('static_scope:child', $restored->getScopes()[0]->getIdentifier());
  }

  /**
   * Tests scope reads skip empty field items without provider lookups.
   */
  public function testEmptyScopeItemsDoNotResolve(): void {
    $this->installDeviceFixtures();
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $entity = $repository->getNewDeviceCode();
    $provider = $this->createMock(Oauth2ScopeProviderInterface::class);
    $provider->expects($this->never())->method('load');
    $provider->expects($this->never())->method('loadByName');
    $entity->setScopeProvider($provider);
    foreach ([[], [['scope_id' => NULL]], [['scope_id' => '']], [[]]] as $values) {
      $entity->set('scopes', $values);
      $this->assertSame([], $entity->getScopes());
    }
  }

  /**
   * Creates real users used by existing authorization and stale-write tests.
   */
  private function createDecisionUsers(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    foreach ([7, 8] as $id) {
      $storage->create(['uid' => $id, 'name' => 'device-user-' . $id, 'status' => 1])->save();
    }
  }

  /**
   * Tests the same issuance policy through schema, raw storage and overrides.
   *
   * @param array $changes
   *   Complete changes to packaged settings.
   * @param bool $valid
   *   Whether issuance is permitted.
   *
   * @dataProvider generationPolicyProvider
   */
  #[DataProvider('generationPolicyProvider')]
  public function testGenerationPolicyBoundaries(array $changes, bool $valid): void {
    $this->installDeviceFixtures();
    $client = $this->createDeviceClient('policy-client');
    $name = 'simple_oauth_device_flow.settings';
    $defaults = $this->config($name)->getRawData();
    $data = $changes + $defaults;
    $typed = $this->container->get('config.typed')->createFromNameAndData($name, $data);
    $this->assertSame($valid, $typed->validate()->count() === 0, 'Typed schema enforces the issuance policy.');
    $storage = $this->container->get('config.storage');
    $storage->write($name, $data);
    $factory = $this->container->get('config.factory');
    $factory->reset($name);
    $this->assertIssuancePolicy($client, $valid);
    $this->assertSame($data, $storage->read($name), 'Runtime validation never rewrites configuration.');
    // Actual settings.php-style overrides must pass the same runtime boundary.
    $storage->write($name, $defaults);
    $overrides = $GLOBALS['config'] ?? [];
    $GLOBALS['config'][$name] = $changes;
    try {
      $factory->reset($name);
      $this->assertIssuancePolicy($client, $valid);
      $this->assertSame($defaults, $storage->read($name));
    }
    finally {
      $GLOBALS['config'] = $overrides;
      $factory->reset($name);
    }
  }

  /**
   * Provides exact policy boundaries and malformed effective settings.
   *
   * @return array<string, array{array, bool}>
   *   Settings changes and expected issuance decision.
   */
  public static function generationPolicyProvider(): array {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    return [
      'packaged default strength' => [[], TRUE],
      'tiny space' => [['user_code_length' => 6, 'user_code_charset' => 'AB'], FALSE],
      'tiny budget still needs floor' => [
        [
          'user_code_length' => 6,
          'user_code_charset' => 'AB',
          'device_code_lifetime' => 1,
          'max_authorization_attempts' => 1,
        ],
        FALSE,
      ],
      'sufficient nondefault' => [['user_code_length' => 11, 'user_code_charset' => '0123456789'], TRUE],
      'exact budget' => [
        [
          'user_code_charset' => $alphabet,
          'max_authorization_attempts' => 256,
          'device_code_lifetime' => 3600,
        ],
        TRUE,
      ],
      'one attempt beyond budget' => [['user_code_charset' => $alphabet, 'max_authorization_attempts' => 257], FALSE],
      'second window' => [
        [
          'user_code_charset' => $alphabet,
          'max_authorization_attempts' => 128,
          'device_code_lifetime' => 3601,
        ],
        TRUE,
      ],
      'window rounding' => [
        [
          'user_code_charset' => $alphabet,
          'max_authorization_attempts' => 256,
          'device_code_lifetime' => 3601,
        ],
        FALSE,
      ],
      'default five attempts' => [['max_authorization_attempts' => 5], TRUE],
      'default six attempts' => [['max_authorization_attempts' => 6], FALSE],
      'large product' => [['max_authorization_attempts' => PHP_INT_MAX, 'device_code_lifetime' => PHP_INT_MAX], FALSE],
      'long lifetime' => [['device_code_lifetime' => 86400], FALSE],
      'zero lifetime' => [['device_code_lifetime' => 0], FALSE],
      'negative attempts' => [['max_authorization_attempts' => -1], FALSE],
      'zero attempts' => [['max_authorization_attempts' => 0], FALSE],
      'string lifetime' => [['device_code_lifetime' => '1800'], FALSE],
      'null lifetime' => [['device_code_lifetime' => NULL], FALSE],
      'array lifetime' => [['device_code_lifetime' => []], FALSE],
      'boolean attempts' => [['max_authorization_attempts' => TRUE], FALSE],
      'fractional length' => [['user_code_length' => 8.5], FALSE],
      'length bounds' => [['user_code_length' => 13], FALSE],
      'duplicate alphabet' => [['user_code_charset' => 'BCDFGHJKLMNPQRSTVWXZB'], FALSE],
      'lowercase alphabet' => [['user_code_charset' => 'abcdefghijklmnopqrstuvwxyz'], FALSE],
      'array alphabet' => [['user_code_charset' => []], FALSE],
      'oversized alphabet' => [['user_code_charset' => str_repeat('A', 10000)], FALSE],
      'empty charset legacy fallback' => [['user_code_charset' => ''], TRUE],
      'legacy excludes all fallback' => [
        [
          'user_code_charset' => '',
          'user_code_excluded_chars' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        ],
        TRUE,
      ],
      'legacy weak exclusion' => [
        [
          'user_code_charset' => '',
          'user_code_excluded_chars' => 'CDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        ],
        FALSE,
      ],
      'malformed exclusion' => [['user_code_charset' => '', 'user_code_excluded_chars' => []], FALSE],
    ];
  }

  /**
   * Asserts generator and service enforcement without partial persistence.
   *
   * @param \League\OAuth2\Server\Entities\ClientEntityInterface $client
   *   The registered client.
   * @param bool $valid
   *   Whether generation is allowed.
   */
  private function assertIssuancePolicy(ClientEntityInterface $client, bool $valid): void {
    $factory = $this->container->get('config.factory');
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $logger = $this->container->get('simple_oauth_device_flow.logger_factory');
    // Exercise the original three-argument constructor and normal DI.
    $generators = [
      $this->container->get('simple_oauth_device_flow.user_code_generator'),
      new UserCodeGenerator($factory, $repository, $logger),
    ];
    foreach ($generators as $generator) {
      $rejected = FALSE;
      try {
        $code = $generator->generateUserCode();
        $this->assertMatchesRegularExpression('/^[A-Z0-9]+(?:-[A-Z0-9]+)?$/D', $code);
      }
      catch (\UnexpectedValueException) {
        $rejected = TRUE;
      }
      $this->assertSame(!$valid, $rejected);
    }
    $services = [
      $this->container->get('simple_oauth_device_flow.device_code_service'),
      new DeviceCodeService($repository, $generators[1], new DeviceFlowSettingsService($factory), $this->container->get('datetime.time'), $logger),
    ];
    foreach ($services as $service) {
      $before = (int) $repository->getStorage()->getQuery()->accessCheck(FALSE)->count()->execute();
      $rejected = FALSE;
      try {
        $service->generateDeviceAuthorization($client);
      }
      catch (\RuntimeException) {
        $rejected = TRUE;
      }
      $this->assertSame(!$valid, $rejected);
      $after = (int) $repository->getStorage()->getQuery()->accessCheck(FALSE)->count()->execute();
      $this->assertSame($before + ($valid ? 1 : 0), $after);
    }
  }

  /**
   * Tests invalid raw settings stop the actual endpoint before persistence.
   */
  public function testUnsafeIssuanceNeverPersists(): void {
    $this->installDeviceFixtures();
    $this->createDeviceClient('unsafe-policy-client');
    $name = 'simple_oauth_device_flow.settings';
    $active = $this->container->get('config.storage');
    $defaults = $active->read($name);
    $weak = ['user_code_length' => 6, 'user_code_charset' => 'AB'];
    $active->write($name, $weak + $defaults);
    $factory = $this->container->get('config.factory');
    $factory->reset($name);
    $generator = $this->container->get('simple_oauth_device_flow.user_code_generator');
    $this->assertTrue($generator->validateCodeFormat('AAAAAA'), 'Context-free format validation retains its original contract.');
    $request = Request::create('https://example.org/oauth/device_authorization', 'POST', ['client_id' => 'unsafe-policy-client']);
    $response = DeviceAuthorizationController::create($this->container)->authorize($request);
    $this->assertSame(500, $response->getStatusCode());
    $this->assertSame('server_error', json_decode($response->getContent(), TRUE)['error']);
    $storage = $this->container->get('entity_type.manager')->getStorage('oauth2_device_code');
    $this->assertSame(0, (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute());
    // Overrides have the same endpoint behavior, with valid active defaults.
    $active->write($name, $defaults);
    $overrides = $GLOBALS['config'] ?? [];
    $GLOBALS['config'][$name] = $weak;
    try {
      $factory->reset($name);
      $response = DeviceAuthorizationController::create($this->container)->authorize($request);
      $this->assertSame(500, $response->getStatusCode());
      $this->assertSame(0, (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute());
    }
    finally {
      $GLOBALS['config'] = $overrides;
      $factory->reset($name);
    }
  }

  /**
   * Tests the real settings form rejects cross-field weak issuance settings.
   */
  public function testGenerationSettingsFormPolicy(): void {
    $this->installConfig(['simple_oauth_device_flow']);
    $initial = $this->config('simple_oauth_device_flow.settings')->getRawData();
    $values = $initial + ['verification_uri_complete_enabled' => TRUE];
    $values['user_code_charset'] = 'AB';
    $values['user_code_length'] = 6;
    $state = (new FormState())->setValues($values);
    $this->container->get('form_builder')->submitForm(DeviceFlowSettingsForm::class, $state);
    $this->assertArrayHasKey('user_code_charset', $state->getErrors());
    $this->assertSame($initial, $this->config('simple_oauth_device_flow.settings')->getRawData());
    $state->clearErrors();
    $values['user_code_charset'] = '0123456789';
    $values['user_code_length'] = 11;
    $state = (new FormState())->setValues($values);
    $this->container->get('form_builder')->submitForm(DeviceFlowSettingsForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame(11, $this->config('simple_oauth_device_flow.settings')->get('user_code_length'));
  }

  /**
   * Tests full config imports validate the incoming snapshot, not old settings.
   */
  public function testGenerationPolicyFullImport(): void {
    $this->installConfig(['simple_oauth_device_flow']);
    $this->config('system.site')->set('uuid', 'fa72695c-c46d-4bee-99b6-2593ab36d6de')->save();
    $this->config('core.extension')->set('module', array_fill_keys(array_keys($this->container->get('module_handler')->getModuleList()), 0))->set('theme', [])->save();
    $name = 'simple_oauth_device_flow.settings';
    $active = $this->container->get('config.storage');
    $before = $active->read($name);
    foreach ([
      ['user_code_length' => 6, 'user_code_charset' => 'AB'],
      ['device_code_lifetime' => PHP_INT_MAX, 'max_authorization_attempts' => PHP_INT_MAX],
      ['user_code_charset' => 'ABAB'],
      ['device_code_lifetime' => 0],
      ['device_code_lifetime' => []],
      ['max_authorization_attempts' => TRUE],
      ['user_code_charset' => []],
      [
        'user_code_length' => 11,
        'user_code_charset' => '0123456789',
        'device_code_lifetime' => 7200,
        'max_authorization_attempts' => 10,
      ],
      [],
    ] as $changes) {
      if ($changes === []) {
        // Incoming absence defaults must not inherit unsafe old active values.
        $active->write($name, ['max_authorization_attempts' => PHP_INT_MAX, 'user_code_charset' => 'AB'] + $before);
      }
      $source = new MemoryStorage();
      foreach ($active->readMultiple($active->listAll()) as $key => $data) {
        $source->write($key, $data);
      }
      $source->write($name, $changes + $before);
      $comparer = new StorageComparer($source, $active);
      $importer = new ConfigImporter(
        $comparer->createChangelist(), $this->container->get('event_dispatcher'),
        $this->container->get('config.manager'), $this->container->get('lock.persistent'),
        $this->container->get('config.typed'), $this->container->get('module_handler'),
        $this->container->get('module_installer'), $this->container->get('theme_handler'),
        $this->container->get('string_translation'), $this->container->get('extension.list.module'),
        $this->container->get('extension.list.theme'),
      );
      $valid = $changes === [] || ($changes['user_code_length'] ?? 0) === 11;
      try {
        $importer->import();
        $this->assertTrue($valid, 'Unsafe incoming settings must stop the full import.');
      }
      catch (ConfigImporterException $exception) {
        $this->assertFalse($valid);
        $this->assertStringContainsString('Invalid device code generation settings', $exception->getMessage());
        $this->assertSame($before, $active->read($name));
      }
      if ($valid) {
        $this->assertEquals($changes + $before, $active->read($name));
      }
    }
  }

  /**
   * Tests raw scope shape validation and precedence on both constructors.
   */
  public function testScopeParameterShapesAndPrecedence(): void {
    $this->installDeviceFixtures();
    $this->createDeviceClient('scope-shape-client');
    $scope = $this->container->get('entity_type.manager')->getStorage('oauth2_scope')->create([
      'name' => 'shape:read',
      'description' => 'Read',
    ]);
    $scope->save();
    $controllers = [
      DeviceAuthorizationController::create($this->container),
      new DeviceAuthorizationController(
        $this->container->get('psr7.http_message_factory'),
        $this->container->get('simple_oauth.repositories.client'),
        $this->container->get('simple_oauth_device_flow.device_code_service'),
        $this->container->get('logger.channel.simple_oauth_device_flow'),
        $this->container->get('config.factory'), $this->container->get('path.validator'),
      ),
    ];
    $storage = $this->container->get('entity_type.manager')->getStorage('oauth2_device_code');
    foreach ($controllers as $controller) {
      foreach (['query', 'request', 'attributes'] as $bag) {
        foreach ([[], ['shape:read'], new \stdClass(), NULL, 1, 1.5, FALSE] as $invalid) {
          $request = Request::create('https://example.org/oauth/device_authorization', 'POST', ['client_id' => 'scope-shape-client']);
          if ($bag === 'attributes') {
            $request->attributes->set('scope', $invalid);
          }
          else {
            // Internal callers can construct raw bags containing object values.
            $request->{$bag} = new InputBag(['scope' => $invalid] + $request->{$bag}->all());
          }
          $before = (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute();
          $response = $controller->authorize($request);
          $this->assertSame(400, $response->getStatusCode(), $bag . ' must reject nonstring scope.');
          $this->assertSame('invalid_request', json_decode($response->getContent(), TRUE)['error']);
          $this->assertSame($before, (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute());
        }
      }
      foreach ([['scope' => 'shape:read'], ['scope' => ''], []] as $highest) {
        $request = Request::create('https://example.org/oauth/device_authorization', 'POST', ['client_id' => 'scope-shape-client']);
        $request->attributes->add($highest);
        if ($highest !== []) {
          $request->query->set('scope', ['bad']);
          $request->request->set('scope', ['bad']);
        }
        $response = $controller->authorize($request);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), TRUE);
        $entity = $storage->load($data['device_code']);
        $this->assertCount(($highest['scope'] ?? '') === '' ? 0 : 1, $entity->getScopes());
      }
      $request = Request::create('https://example.org/oauth/device_authorization', 'POST', [
        'client_id' => 'scope-shape-client',
        'scope' => ['bad'],
      ]);
      $request->query->set('scope', 'shape:read');
      $this->assertSame(200, $controller->authorize($request)->getStatusCode());
    }
  }

  /**
   * Tests bounded pending lookup across policy changes and ambiguous layouts.
   */
  public function testPendingLookupIgnoresGenerationPolicy(): void {
    $this->installDeviceFixtures();
    $client = $this->createDeviceClient('continuity-client');
    $service = $this->container->get('simple_oauth_device_flow.device_code_service');
    $issued = $service->generateDeviceAuthorization($client);
    $repository = $this->container->get('simple_oauth_device_flow.device_code_repository');
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 7]));
    $form = [];
    $config = $this->config('simple_oauth_device_flow.settings');
    foreach ([
      [
        'user_code_length' => 11,
        'user_code_charset' => '0123456789',
        'user_code_format' => 'XXXXXXXXXXX',
        'device_code_lifetime' => 7200,
        'max_authorization_attempts' => 10,
      ],
      [
        'user_code_length' => 6,
        'user_code_charset' => 'AB',
        'device_code_lifetime' => -1,
        'max_authorization_attempts' => [],
      ],
    ] as $changes) {
      foreach ($changes as $key => $value) {
        $config->set($key, $value);
      }
      // Raw storage avoids schema coercion of the deliberately malformed limit.
      $this->container->get('config.storage')->write('simple_oauth_device_flow.settings', $config->getRawData());
      $this->container->get('config.factory')->reset('simple_oauth_device_flow.settings');
      foreach ([
        $issued['user_code'],
        strtolower(str_replace('-', '', $issued['user_code'])),
        str_replace('-', '/', $issued['user_code']),
      ] as $input) {
        $state = (new FormState())->setValue('user_code', $input);
        DeviceVerificationForm::create($this->container)->validateForm($form, $state);
        $this->assertSame([], $state->getErrors());
        $this->assertSame($issued['device_code'], $state->getTemporaryValue('device_code_entity')->id());
      }
    }
    $storage = $repository->getStorage();
    $original = $storage->load($issued['device_code']);
    $raw = str_replace('-', '', $original->getUserCode());
    $duplicate = $original->createDuplicate();
    $duplicate->setIdentifier('ambiguous-layout');
    $duplicate->setUserCode($raw);
    $duplicate->save();
    $state = (new FormState())->setValue('user_code', $raw);
    DeviceVerificationForm::create($this->container)->validateForm($form, $state);
    $this->assertArrayHasKey('user_code', $state->getErrors());
    $state->clearErrors();
    $duplicate->delete();
    // A historic raw code remains authoritative, with a bounded dash variant.
    $original->setUserCode($raw);
    $original->save();
    $state = (new FormState())->setValue('user_code', strtolower($issued['user_code']));
    DeviceVerificationForm::create($this->container)->validateForm($form, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame($issued['device_code'], $state->getTemporaryValue('device_code_entity')->id());
    foreach (['ABABAB', str_repeat('Z', 32)] as $historic_code) {
      $historic = $original->createDuplicate();
      $historic->setIdentifier('historic-' . strlen($historic_code));
      $historic->setUserCode($historic_code);
      $historic->save();
      $state = (new FormState())->setValue('user_code', strtolower($historic_code));
      DeviceVerificationForm::create($this->container)->validateForm($form, $state);
      $this->assertSame([], $state->getErrors());
      $this->assertSame($historic->id(), $state->getTemporaryValue('device_code_entity')->id());
    }
  }

  /**
   * Tests malformed or huge raw verification input never reaches storage.
   */
  public function testVerificationInputBoundsBeforeLookup(): void {
    $this->installDeviceFixtures();
    $lookup = $this->createMock(DeviceVerificationOperationsInterface::class);
    $lookup->expects($this->never())->method('getDeviceCodeEntityByUserCode');
    $form_object = new DeviceVerificationForm(
      $lookup, $this->container->get('simple_oauth_device_flow.device_code_service'),
      $this->container->get('simple_oauth_device_flow.user_code_generator'),
      new UserSession(['uid' => 7]), $this->createMock(LoggerInterface::class),
      $this->container->get('flood'), $this->container->get('simple_oauth_device_flow.settings'),
    );
    foreach ([
      str_repeat(' ', 100000) . 'BCDF-GHJK',
      str_repeat('A', 33),
      str_repeat("\u{1F539}", 33),
      '',
      '---',
      '   ',
      [],
      NULL,
    ] as $input) {
      $this->container->get('flood')->clear('simple_oauth_device_flow.verification', '7');
      $state = (new FormState())->setValue('user_code', $input);
      $form = [];
      $form_object->validateForm($form, $state);
      $this->assertArrayHasKey('user_code', $state->getErrors());
      $state->clearErrors();
    }
  }

  /**
   * Creates a registered public client with an identifier distinct from its ID.
   *
   * @param string $identifier
   *   The public identifier.
   * @param string $label
   *   The display label.
   *
   * @return \League\OAuth2\Server\Entities\ClientEntityInterface
   *   The registered client.
   */
  private function createDeviceClient(string $identifier, string $label = 'Device client'): ClientEntityInterface {
    Consumer::create([
      'label' => $label,
      'client_id' => $identifier,
      'confidential' => FALSE,
      'grant_types' => [DeviceGrantType::CANONICAL],
    ])->save();
    return $this->container->get('simple_oauth.repositories.client')->getClientEntity($identifier);
  }

  /**
   * Installs storage and configuration used by device lifecycle tests.
   */
  private function installDeviceFixtures(): void {
    foreach (['oauth2_device_code', 'user', 'consumer', 'oauth2_scope'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installConfig(['simple_oauth_device_flow', 'simple_oauth']);
    $this->createDecisionUsers();
  }

}
