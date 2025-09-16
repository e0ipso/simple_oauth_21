<?php

namespace Drupal\Tests\simple_oauth_server_metadata\Kernel\Service;

use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth_server_metadata\Service\GrantTypeDiscoveryService;

/**
 * Kernel tests for the GrantTypeDiscoveryService.
 *
 * @coversDefaultClass \Drupal\simple_oauth_server_metadata\Service\GrantTypeDiscoveryService
 * @group simple_oauth_server_metadata
 */
class GrantTypeDiscoveryServiceKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'system',
    'simple_oauth',
    'simple_oauth_server_metadata',
    'consumers',
    'serialization',
  ];

  /**
   * Tests that the service can be instantiated.
   *
   * @covers ::__construct
   */
  public function testServiceInstantiation() {
    $service = $this->container->get('simple_oauth_server_metadata.grant_type_discovery');
    $this->assertInstanceOf(GrantTypeDiscoveryService::class, $service);
  }

  /**
   * Tests getGrantTypesSupported() with default configuration.
   *
   * @covers ::getGrantTypesSupported
   */
  public function testGetGrantTypesSupportedDefault() {
    // Install default config.
    $this->installConfig(['simple_oauth']);

    $service = $this->container->get('simple_oauth_server_metadata.grant_type_discovery');
    $grant_types = $service->getGrantTypesSupported();

    // Default config has implicit disabled, so should not include it.
    $this->assertContains('authorization_code', $grant_types);
    $this->assertContains('client_credentials', $grant_types);
    $this->assertContains('refresh_token', $grant_types);
    $this->assertNotContains('implicit', $grant_types);
  }

  /**
   * Tests getResponseTypesSupported() with default configuration.
   *
   * @covers ::getResponseTypesSupported
   * @covers ::isOpenIdConnectDisabled
   */
  public function testGetResponseTypesSupportedDefault() {
    // Install default config.
    $this->installConfig(['simple_oauth']);

    $service = $this->container->get('simple_oauth_server_metadata.grant_type_discovery');
    $response_types = $service->getResponseTypesSupported();

    // With OIDC enabled (default) and only authorization_code grant.
    $this->assertContains('code', $response_types);
    $this->assertContains('id_token', $response_types);
    $this->assertNotContains('token', $response_types);
  }

  /**
   * Tests response types without implicit grant.
   *
   * @covers ::getGrantTypesSupported
   * @covers ::getResponseTypesSupported
   */
  public function testWithImplicitGrantEnabled() {
    // Install default config.
    $this->installConfig(['simple_oauth']);

    // Note: The implicit grant has been removed in Simple OAuth 6.x
    // as it's considered insecure per OAuth 2.0 Security Best Current Practice.
    // The use_implicit setting is deprecated and has no effect.
    $config = $this->config('simple_oauth.settings');
    $config->set('use_implicit', TRUE);
    $config->save();

    $service = $this->container->get('simple_oauth_server_metadata.grant_type_discovery');

    $grant_types = $service->getGrantTypesSupported();
    // Implicit grant should NOT be available even when use_implicit is TRUE.
    $this->assertNotContains('implicit', $grant_types);

    $response_types = $service->getResponseTypesSupported();
    $this->assertContains('code', $response_types);
    // Token response type is only for implicit grant, which is not available.
    $this->assertNotContains('token', $response_types);
    $this->assertContains('id_token', $response_types);
    // id_token token combination requires token response type.
    $this->assertNotContains('id_token token', $response_types);
  }

  /**
   * Tests with OpenID Connect disabled.
   *
   * @covers ::getResponseTypesSupported
   * @covers ::isOpenIdConnectDisabled
   */
  public function testWithOpenIdConnectDisabled() {
    // Install default config.
    $this->installConfig(['simple_oauth']);

    // Disable OIDC.
    $config = $this->config('simple_oauth.settings');
    $config->set('disable_openid_connect', TRUE);
    $config->save();

    $service = $this->container->get('simple_oauth_server_metadata.grant_type_discovery');
    $response_types = $service->getResponseTypesSupported();

    $this->assertContains('code', $response_types);
    $this->assertNotContains('id_token', $response_types);
    $this->assertNotContains('id_token token', $response_types);
  }

}
