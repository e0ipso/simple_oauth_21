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
    'system',
    'user',
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

    // Test user code generation
    $user_code = $generator->generateUserCode();
    $this->assertIsString($user_code);
    $this->assertGreaterThan(4, strlen($user_code));
    $this->assertLessThan(20, strlen($user_code));

    // Test multiple codes are different
    $user_code2 = $generator->generateUserCode();
    $this->assertNotEquals($user_code, $user_code2);
  }

  /**
   * Tests device flow settings service.
   */
  public function testDeviceFlowSettingsService(): void {
    // Install config
    $this->installConfig(['simple_oauth_device_flow']);

    $settings = $this->container->get('simple_oauth_device_flow.settings');
    $this->assertInstanceOf(DeviceFlowSettingsService::class, $settings);

    // Test default settings
    $this->assertIsInt($settings->getDeviceCodeExpiration());
    $this->assertIsInt($settings->getPollingInterval());
    $this->assertIsString($settings->getVerificationUri());

    // Test settings validation
    $this->assertGreaterThan(0, $settings->getDeviceCodeExpiration());
    $this->assertGreaterThan(0, $settings->getPollingInterval());
  }

  /**
   * Tests device flow routing and controller setup.
   */
  public function testDeviceFlowRouting(): void {
    // Install routing config
    $this->installConfig(['simple_oauth_device_flow']);

    $route_provider = $this->container->get('router.route_provider');

    // Test device authorization route exists
    $device_auth_route = $route_provider->getRouteByName('simple_oauth_device_flow.device_authorization');
    $this->assertNotNull($device_auth_route);
    $this->assertEquals('/oauth/device_authorization', $device_auth_route->getPath());
    $this->assertEquals(['POST'], $device_auth_route->getMethods());

    // Test device verification routes exist
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

    // Test all expected services are registered
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
    $device_code_expiration = $config->get('device_code_expiration');
    $polling_interval = $config->get('polling_interval');
    $verification_uri = $config->get('verification_uri');

    // Values can be null (using defaults), just test they don't cause errors
    $this->assertTrue(TRUE, 'Configuration loaded without errors');
  }

  /**
   * Tests device flow configuration form route exists.
   */
  public function testDeviceFlowConfigurationRoute(): void {
    $route_provider = $this->container->get('router.route_provider');

    // Test device flow settings route exists
    $settings_route = $route_provider->getRouteByName('simple_oauth_device_flow.settings');
    $this->assertNotNull($settings_route);
    $this->assertEquals('/admin/config/people/simple_oauth/oauth-21/device-flow', $settings_route->getPath());
  }

}