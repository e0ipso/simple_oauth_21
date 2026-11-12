<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Kernel\Service;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for the EndpointDiscoveryService on a multilingual site.
 *
 * @coversDefaultClass \Drupal\simple_oauth_server_metadata\Service\EndpointDiscoveryService
 */
#[Group('simple_oauth_server_metadata')]
#[RunTestsInSeparateProcesses]
final class EndpointDiscoveryServiceKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'system',
    'language',
    'file',
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_server_metadata',
    'simple_oauth_client_registration',
    'simple_oauth_device_flow',
    'consumers',
    'serialization',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['language', 'simple_oauth']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    // Every language has a path prefix, the default one included.
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => 'en', 'fr' => 'fr'])
      ->save();
    // The language path processor is only registered on multilingual sites.
    $this->container->get('kernel')->rebuildContainer();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Tests that the endpoint URLs carry no language prefix.
   *
   * @covers ::getCoreEndpoints
   */
  public function testEndpointsHaveNoLanguagePrefix(): void {
    $endpoints = $this->container
      ->get('simple_oauth_server_metadata.endpoint_discovery')
      ->getCoreEndpoints();

    $expected_paths = [
      'authorization_endpoint' => '/oauth/authorize',
      'token_endpoint' => '/oauth/token',
      'jwks_uri' => '/oauth/jwks',
      'userinfo_endpoint' => '/oauth/userinfo',
      'registration_endpoint' => '/oauth/register',
      'device_authorization_endpoint' => '/oauth/device_authorization',
      'revocation_endpoint' => '/oauth/revoke',
      'introspection_endpoint' => '/oauth/introspect',
      'oauth_authorization_server_metadata_endpoint' => '/.well-known/oauth-authorization-server',
    ];
    foreach ($expected_paths as $key => $path) {
      $this->assertSame($path, parse_url($endpoints[$key], PHP_URL_PATH), $key);
    }
  }

  /**
   * Tests that the endpoint routes opt out of the Redirect route normalizer.
   */
  public function testEndpointRoutesDisableRouteNormalizer(): void {
    $route_provider = $this->container->get('router.route_provider');
    $route_names = [
      'simple_oauth_server_metadata.well_known',
      'simple_oauth_server_metadata.resource_metadata',
      'simple_oauth_server_metadata.openid_configuration',
      'simple_oauth_server_metadata.revoke',
      'simple_oauth_server_metadata.token_introspection',
      'simple_oauth_client_registration.register',
      'simple_oauth_client_registration.manage',
      'simple_oauth_device_flow.device_authorization',
      'simple_oauth_device_flow.device_verification_form',
      'simple_oauth_device_flow.device_verification_submit',
    ];
    foreach ($route_names as $route_name) {
      $route = $route_provider->getRouteByName($route_name);
      $this->assertTrue($route->getDefault('_disable_route_normalizer'), $route_name);
    }
  }

}
