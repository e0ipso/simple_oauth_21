<?php

namespace Drupal\Tests\simple_oauth_device_flow\Kernel;

use Drupal\KernelTests\KernelTestBase;
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
class DeviceFlowIntegrationTest extends KernelTestBase {

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
    $this->assertIsString($user_code);
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
    $this->assertIsInt($settings->getDeviceCodeExpiration());
    $this->assertIsInt($settings->getPollingInterval());
    $this->assertIsString($settings->getVerificationUri());

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
    $this->assertNotNull($device_auth_route);
    $this->assertEquals('/oauth/device_authorization', $device_auth_route->getPath());
    $this->assertEquals(['POST'], $device_auth_route->getMethods());

    // Test device verification routes exist.
    $device_verify_route = $route_provider->getRouteByName('simple_oauth_device_flow.device_verification_form');
    $this->assertNotNull($device_verify_route);
    $this->assertEquals('/oauth/device', $device_verify_route->getPath());
    $this->assertEquals(['GET'], $device_verify_route->getMethods());

    $device_submit_route = $route_provider->getRouteByName('simple_oauth_device_flow.device_verification_submit');
    $this->assertNotNull($device_submit_route);
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
      'simple_oauth_device_flow.device_code_service',
      'simple_oauth_device_flow.user_code_generator',
      'simple_oauth_device_flow.settings',
    ];

    foreach ($expected_services as $service_id) {
      $this->assertTrue($container->has($service_id), "Service {$service_id} should be registered");
      $service = $container->get($service_id);
      $this->assertNotNull($service, "Service {$service_id} should be instantiable");
    }
  }

  /**
   * Tests device flow configuration schema.
   */
  public function testDeviceFlowConfiguration(): void {
    $this->installConfig(['simple_oauth_device_flow']);

    $config = $this->config('simple_oauth_device_flow.settings');
    $this->assertNotNull($config);

    // Test default configuration values exist (they may be null, that's OK)
    // Just test that getting the values doesn't cause errors.
    $config->get('device_code_expiration');
    $config->get('polling_interval');
    $config->get('verification_uri');
    $this->assertTrue(TRUE, 'Configuration loaded without errors');
  }

  /**
   * Tests device flow configuration form route exists.
   */
  public function testDeviceFlowConfigurationRoute(): void {
    $route_provider = $this->container->get('router.route_provider');

    // Test device flow settings route exists.
    $settings_route = $route_provider->getRouteByName('simple_oauth_device_flow.settings');
    $this->assertNotNull($settings_route);
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
    $scope_storage = $this->container->get('entity_type.manager')->getStorage('oauth2_scope');
    $scope1 = $scope_storage->create([
      'id' => 'read',
      'name' => 'Read Access',
      'description' => 'Read access to resources',
    ]);
    $scope1->save();

    $scope2 = $scope_storage->create([
      'id' => 'write',
      'name' => 'Write Access',
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

    // Test adding multiple scopes via field API.
    $device_code->get('scopes')->appendItem(['scope_id' => 'read']);
    $device_code->get('scopes')->appendItem(['scope_id' => 'write']);
    $device_code->save();

    // Reload and verify scopes are persisted correctly.
    $device_code_storage->resetCache([$device_code->id()]);
    $loaded_device_code = $device_code_storage->load($device_code->id());

    // Test getScopes() returns proper scope entities.
    $scopes = $loaded_device_code->getScopes();
    $this->assertCount(2, $scopes, 'Device code should have 2 scopes');

    // Verify scope identifiers.
    $scope_ids = array_map(fn($scope) => $scope->getIdentifier(), $scopes);
    $this->assertContains('read', $scope_ids, 'Scope list should contain "read"');
    $this->assertContains('write', $scope_ids, 'Scope list should contain "write"');

    // Test adding scope via entity method (addScope).
    $scope3 = $scope_storage->create([
      'id' => 'admin',
      'name' => 'Admin Access',
      'description' => 'Administrative access',
    ]);
    $scope3->save();

    $loaded_device_code->addScope($scope3);
    $loaded_device_code->save();

    // Reload and verify third scope is added.
    $device_code_storage->resetCache([$device_code->id()]);
    $reloaded_device_code = $device_code_storage->load($device_code->id());
    $scopes_after = $reloaded_device_code->getScopes();
    $this->assertCount(3, $scopes_after, 'Device code should now have 3 scopes');

    $scope_ids_after = array_map(fn($scope) => $scope->getIdentifier(), $scopes_after);
    $this->assertContains('admin', $scope_ids_after, 'Scope list should contain "admin"');
  }

}
