<?php

namespace Drupal\Tests\simple_oauth_pkce\Kernel;

use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;

/**
 * Test PKCE configuration and service functionality.
 *
 * @group simple_oauth_pkce
 */
class PkceConfigurationTest extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'consumers',
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_pkce',
    'serialization',
  ];

  /**
   * The PKCE settings service.
   *
   * @var \Drupal\simple_oauth_pkce\Service\PkceSettingsService
   */
  protected $pkceSettings;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['simple_oauth_pkce']);
    $this->pkceSettings = $this->container->get('simple_oauth_pkce.settings');
  }

  /**
   * Test default PKCE configuration.
   */
  public function testDefaultConfiguration(): void {
    $config = $this->config('simple_oauth_pkce.settings');

    // Test default values match OAuth 2.1 recommendations.
    $this->assertEquals('mandatory', $config->get('enforcement'));
    $this->assertTrue($config->get('s256_enabled'));
    $this->assertTrue($config->get('plain_enabled'));
  }

  /**
   * Test PKCE settings service methods.
   */
  public function testPkceSettingsService(): void {
    // Test default methods.
    $this->assertEquals('mandatory', $this->pkceSettings->getEnforcementLevel());
    $this->assertTrue($this->pkceSettings->isS256Enabled());
    $this->assertTrue($this->pkceSettings->isPlainEnabled());
    $this->assertTrue($this->pkceSettings->isMandatory());
    $this->assertFalse($this->pkceSettings->isDisabled());

    $supported_methods = $this->pkceSettings->getSupportedMethods();
    $this->assertContains('S256', $supported_methods);
    $this->assertContains('plain', $supported_methods);
  }

  /**
   * Test configuration changes affect service.
   */
  public function testConfigurationChanges(): void {
    // Change to disabled enforcement.
    $this->config('simple_oauth_pkce.settings')
      ->set('enforcement', 'disabled')
      ->set('s256_enabled', FALSE)
      ->set('plain_enabled', FALSE)
      ->save();

    // Create new service instance to get updated config.
    $updated_settings = $this->container->get('simple_oauth_pkce.settings');

    $this->assertTrue($updated_settings->isDisabled());
    $this->assertFalse($updated_settings->isMandatory());
    $this->assertFalse($updated_settings->isS256Enabled());
    $this->assertFalse($updated_settings->isPlainEnabled());
    $this->assertEmpty($updated_settings->getSupportedMethods());
  }

}
