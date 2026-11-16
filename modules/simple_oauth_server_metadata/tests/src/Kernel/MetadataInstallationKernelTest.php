<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Kernel;

use Drupal\Core\Config\MemoryStorage;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests metadata installation through core's module and config installers.
 */
#[Group('simple_oauth_server_metadata')]
#[RunTestsInSeparateProcesses]
final class MetadataInstallationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'file', 'image', 'options', 'serialization', 'consumers',
    'simple_oauth', 'simple_oauth_21', 'simple_oauth_client_registration',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user', 'simple_oauth']);
    $this->installEntitySchema('user');
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Tests a fresh install keeps URLs automatic and initializes locales.
   */
  public function testFreshInstallation(): void {
    $this->assertTrue($this->container->get('module_installer')->install(['simple_oauth_server_metadata'], FALSE));
    $config = $this->config('simple_oauth_server_metadata.settings');
    foreach (['registration_endpoint', 'revocation_endpoint', 'introspection_endpoint', 'device_authorization_endpoint'] as $field) {
      $this->assertSame('', $config->get($field), $field);
    }
    $this->assertSame(['en-US'], $config->get('ui_locales_supported'));
    $this->assertSame([], $config->get('additional_claims_supported'));
  }

  /**
   * Tests the registration endpoint is advertised only for open registration.
   */
  public function testRegistrationEndpointFollowsAccessSetting(): void {
    $this->container->get('module_installer')->install(['simple_oauth_server_metadata']);
    $discovery = $this->container->get('simple_oauth_server_metadata.endpoint_discovery');
    $this->assertNotNull($discovery->getRegistrationEndpoint());
    $this->config('simple_oauth_server_metadata.settings')->set('registration_endpoint', 'https://example.org/oauth/register')->save();
    foreach (['permission', 'disabled'] as $access) {
      $this->config('simple_oauth_client_registration.settings')->set('registration_access', $access)->save();
      $this->assertNull($discovery->getRegistrationEndpoint(), $access);
      $metadata = $this->container->get('simple_oauth_server_metadata.server_metadata')->getServerMetadata();
      $this->assertArrayNotHasKey('registration_endpoint', $metadata, $access);
      $openid = $this->container->get('simple_oauth_server_metadata.openid_configuration')->getOpenIdConfiguration();
      $this->assertArrayNotHasKey('registration_endpoint', $openid, $access);
    }
    $this->config('simple_oauth_client_registration.settings')->set('registration_access', 'open')->save();
    $metadata = $this->container->get('simple_oauth_server_metadata.server_metadata')->getServerMetadata();
    $this->assertSame('https://example.org/oauth/register', $metadata['registration_endpoint']);
  }

  /**
   * Tests a syncing module install does not change imported settings.
   *
   * @param bool $explicit_endpoints
   *   Whether imported settings contain explicit endpoint overrides.
   *
   * @dataProvider importedEndpointProvider
   */
  #[DataProvider('importedEndpointProvider')]
  public function testSynchronizedInstallation(bool $explicit_endpoints): void {
    $name = 'simple_oauth_server_metadata.settings';
    $data = [
      'registration_endpoint' => $explicit_endpoints ? 'https://imported.example/Register' : '',
      'revocation_endpoint' => $explicit_endpoints ? 'https://imported.example/Revoke' : '',
      'introspection_endpoint' => $explicit_endpoints ? 'https://imported.example/Inspect' : '',
      'device_authorization_endpoint' => $explicit_endpoints ? 'https://imported.example/Device' : '',
      'ui_locales_supported' => ['fr-CA', 'de-DE'],
      'additional_claims_supported' => ['zeta', 'alpha'],
    ];
    // Imported configuration can exist before the module's install hook runs.
    $this->container->get('config.storage')->write($name, $data);
    $this->container->get('config.factory')->reset($name);
    $installer = $this->container->get('config.installer');
    $installer->setSourceStorage(new MemoryStorage());
    $installer->setSyncing(TRUE);
    try {
      $this->assertTrue($this->container->get('module_installer')->install(['simple_oauth_server_metadata'], FALSE));
      $this->assertSame($data, $this->container->get('config.storage')->read($name));
    }
    finally {
      $this->container->get('config.installer')->setSyncing(FALSE);
    }
  }

  /**
   * Provides imported automatic and explicit endpoint configurations.
   *
   * @return array<string, array{bool}>
   *   Imported endpoint variants.
   */
  public static function importedEndpointProvider(): array {
    return [
      'automatic' => [FALSE],
      'explicit' => [TRUE],
    ];
  }

}
