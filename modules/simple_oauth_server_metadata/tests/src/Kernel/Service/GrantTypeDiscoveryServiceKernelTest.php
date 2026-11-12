<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Kernel\Service;

use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth\Plugin\Oauth2Grant\AuthorizationCode;
use Drupal\simple_oauth\Plugin\Oauth2Grant\ClientCredentials;
use Drupal\simple_oauth\Plugin\Oauth2Grant\RefreshToken;
use Drupal\simple_oauth\Plugin\Oauth2GrantManager;
use Drupal\simple_oauth_server_metadata\Service\GrantTypeDiscoveryService;
use Drupal\simple_oauth_server_metadata\Service\OpenIdConfigurationService;
use Drupal\simple_oauth_server_metadata\Service\EndpointDiscoveryService;
use Drupal\simple_oauth_server_metadata\Service\ScopeDiscoveryService;
use Drupal\simple_oauth_server_metadata\Service\ClaimsAuthDiscoveryService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests discovery against real OAuth grant plugins in the service container.
 *
 * @coversDefaultClass \Drupal\simple_oauth_server_metadata\Service\GrantTypeDiscoveryService
 */
#[Group('simple_oauth_server_metadata')]
#[RunTestsInSeparateProcesses]
final class GrantTypeDiscoveryServiceKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'system',
    'file',
    'simple_oauth',
    'simple_oauth_server_metadata',
    'consumers',
    'serialization',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['simple_oauth']);
  }

  /**
   * Tests container discovery and instantiation of the real grant plugins.
   *
   * @covers ::__construct
   * @covers ::getGrantTypesSupported
   */
  public function testGetGrantTypesSupportedDefault(): void {
    $service = $this->container->get('simple_oauth_server_metadata.grant_type_discovery');
    $manager = $this->container->get('plugin.manager.oauth2_grant.processor');
    self::assertInstanceOf(AuthorizationCode::class, $manager->createInstance('authorization_code'));
    self::assertInstanceOf(ClientCredentials::class, $manager->createInstance('client_credentials'));
    self::assertInstanceOf(RefreshToken::class, $manager->createInstance('refresh_token'));
    self::assertEqualsCanonicalizing([
      'authorization_code',
      'client_credentials',
      'refresh_token',
    ], $service->getGrantTypesSupported());
    self::assertNotContains('implicit', $service->getGrantTypesSupported());
    self::assertNotContains('urn:ietf:params:oauth:grant-type:device_code', $service->getGrantTypesSupported());
  }

  /**
   * Tests that code flow has a positive response capability with OIDC enabled.
   *
   * @covers ::getResponseTypesSupported
   */
  public function testGetResponseTypesSupportedDefault(): void {
    $this->config('simple_oauth.settings')->set('disable_openid_connect', FALSE)->save();
    $service = $this->container->get('simple_oauth_server_metadata.grant_type_discovery');
    self::assertSame(['code'], $service->getResponseTypesSupported());
    self::assertEqualsCanonicalizing([
      'query',
      'fragment',
      'form_post',
    ], $service->getResponseModesSupported());
  }

  /**
   * Tests that disabling OIDC retains the supported OAuth authorization flow.
   *
   * @covers ::getResponseTypesSupported
   */
  public function testWithOpenIdConnectDisabled(): void {
    $this->config('simple_oauth.settings')->set('disable_openid_connect', TRUE)->save();
    $service = $this->container->get('simple_oauth_server_metadata.grant_type_discovery');
    self::assertSame(['code'], $service->getResponseTypesSupported());
  }

  /**
   * Tests that token-endpoint grants do not advertise authorization responses.
   *
   * @covers ::getGrantTypesSupported
   * @covers ::getResponseTypesSupported
   * @covers ::getResponseModesSupported
   */
  public function testWithoutAuthorizationGrant(): void {
    $service = $this->createDiscoveryWithGrants(['client_credentials', 'refresh_token']);
    self::assertEqualsCanonicalizing([
      'client_credentials',
      'refresh_token',
    ], $service->getGrantTypesSupported());
    self::assertSame([], $service->getResponseTypesSupported());
    self::assertSame([], $service->getResponseModesSupported());
  }

  /**
   * Tests the explicit absence of all grant capabilities.
   *
   * @covers ::getGrantTypesSupported
   * @covers ::getResponseTypesSupported
   * @covers ::getResponseModesSupported
   */
  public function testWithoutGrants(): void {
    $service = $this->createDiscoveryWithGrants([]);
    self::assertSame([], $service->getGrantTypesSupported());
    self::assertSame([], $service->getResponseTypesSupported());
    self::assertSame([], $service->getResponseModesSupported());
  }

  /**
   * Builds a real plugin manager with a subset of discovered plugins.
   *
   * @param string[] $grant_ids
   *   The real grant plugins available in this scenario.
   *
   * @return \Drupal\simple_oauth_server_metadata\Service\GrantTypeDiscoveryService
   *   Discovery backed by real plugins and the kernel configuration factory.
   */
  private function createDiscoveryWithGrants(array $grant_ids): GrantTypeDiscoveryService {
    $definitions = $this->container->get('plugin.manager.oauth2_grant.processor')->getDefinitions();
    $cache = $this->container->get('cache.discovery');
    $cache->set('test.oauth2_grant_plugins', array_intersect_key($definitions, array_flip($grant_ids)));
    $manager = new Oauth2GrantManager(
      $this->container->get('container.namespaces'),
      $cache,
      $this->container->get('module_handler'),
    );
    $manager->setCacheBackend($cache, 'test.oauth2_grant_plugins');
    return new GrantTypeDiscoveryService($manager, $this->container->get('config.factory'));
  }

  /**
   * Tests public OpenID metadata against the same real grant capabilities.
   */
  public function testOpenIdResponseCapabilities(): void {
    $endpoints = $this->createMock(EndpointDiscoveryService::class);
    foreach (['getIssuer', 'getAuthorizationEndpoint', 'getTokenEndpoint', 'getUserInfoEndpoint', 'getJwksUri'] as $method) {
      $endpoints->method($method)->willReturn('https://issuer.example/' . $method);
    }
    $scopes = $this->createMock(ScopeDiscoveryService::class);
    $scopes->method('getScopesSupported')->willReturn(['openid']);
    $claims = $this->createMock(ClaimsAuthDiscoveryService::class);
    $claims->method('getSubjectTypesSupported')->willReturn(['public']);
    $claims->method('getIdTokenSigningAlgValuesSupported')->willReturn(['RS256']);
    foreach ([FALSE, TRUE] as $disabled) {
      $this->config('simple_oauth.settings')->set('disable_openid_connect', $disabled)->save();
      $grants = $this->createDiscoveryWithGrants(['authorization_code']);
      $openid = new OpenIdConfigurationService($this->container->get('config.factory'), $endpoints, $scopes, $grants, $claims, ['sub']);
      $metadata = $openid->getOpenIdConfiguration();
      self::assertSame(['code'], $metadata['response_types_supported']);
      self::assertSame($grants->getResponseTypesSupported(), $metadata['response_types_supported']);
      self::assertFalse($metadata['implicit_flow_enabled']);
      self::assertTrue($metadata['authorization_code_flow_enabled']);
    }
    $grants = $this->createDiscoveryWithGrants(['client_credentials']);
    self::assertSame([], $grants->getResponseTypesSupported());
    $openid = new OpenIdConfigurationService($this->container->get('config.factory'), $endpoints, $scopes, $grants, $claims, ['sub']);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('response_types_supported');
    $openid->getOpenIdConfiguration();
  }

}
