<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\simple_oauth\Oauth2ScopeProviderInterface;
use Drupal\simple_oauth\Plugin\Oauth2GrantInterface;
use Drupal\simple_oauth\Plugin\Oauth2GrantManager;
use Drupal\simple_oauth_server_metadata\Service\ClientAuthenticationService;
use Drupal\simple_oauth_server_metadata\Service\ClaimsAuthDiscoveryService;
use Drupal\simple_oauth_server_metadata\Service\EndpointDiscoveryService;
use Drupal\simple_oauth_server_metadata\Service\ServerMetadataService;
use Drupal\simple_oauth_server_metadata\Service\OpenIdConfigurationService;
use Drupal\simple_oauth_server_metadata\Service\ResourceMetadataService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\simple_oauth_server_metadata\Service\ScopeDiscoveryService;
use Drupal\simple_oauth_server_metadata\Service\GrantTypeDiscoveryService;
use Drupal\Tests\simple_oauth_server_metadata\Unit\Fixture\CacheableScopeInterface;
use Drupal\Tests\simple_oauth_server_metadata\Unit\Fixture\CacheableScopeProviderInterface;
use Drupal\Tests\simple_oauth_server_metadata\Unit\Fixture\DiscoveryGrantInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\ServerRequest;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests external scope cache policies and malformed credential boundaries.
 */
#[Group('simple_oauth_server_metadata')]
final class DiscoveryPolicyTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', new CacheContextsManager($container, ['url.query_args', 'url.site']));
    \Drupal::setContainer($container);
  }

  /**
   * Tests extension mappings, legacy fallback, and unavailable plugins.
   */
  public function testGrantWireIdentifierDiscovery(): void {
    $mapped = $this->createMock(DiscoveryGrantInterface::class);
    $mapped->method('getGrantTypeIdentifier')->willReturn('authorization_code');
    $mapped->expects($this->never())->method('getGrantType');
    $extension = $this->createMock(Oauth2GrantInterface::class);
    $extension->expects($this->never())->method('getGrantType');
    $manager = $this->createMock(Oauth2GrantManager::class);
    $manager->method('getDefinitions')->willReturn(array_fill_keys([
      'replacement', 'authorization_code', 'urn:example:custom-grant', 'unavailable',
    ], []));
    $manager->method('createInstance')->willReturnCallback(static function (string $id) use ($mapped, $extension): Oauth2GrantInterface {
      if ($id === 'unavailable') {
        throw new \RuntimeException('Optional dependency unavailable.');
      }
      return $id === 'replacement' ? $mapped : $extension;
    });
    $service = new GrantTypeDiscoveryService($manager, $this->getConfigFactoryStub());
    $this->assertSame(['authorization_code', 'urn:example:custom-grant'], $service->getGrantTypesSupported());
    $this->assertSame(['code'], $service->getResponseTypesSupported());
  }

  /**
   * Tests capability extensions, absent code grants and legacy construction.
   */
  public function testCapabilityHelpers(): void {
    foreach ([FALSE, TRUE] as $disabled) {
      $factory = $this->getConfigFactoryStub([
        'simple_oauth.settings' => ['disable_openid_connect' => $disabled],
        'simple_oauth_server_metadata.settings' => [],
      ]);
      $endpoints = $this->createMock(EndpointDiscoveryService::class);
      $endpoints->method('getIssuer')->willReturn('https://issuer.example/');
      $endpoints->method('getCoreEndpoints')->willReturn([]);
      foreach ([
        'getAuthorizationEndpoint',
        'getTokenEndpoint',
        'getJwksUri',
        'getUserInfoEndpoint',
        'getOauthServerMetadataEndpoint',
      ] as $method) {
        $endpoints->method($method)->willReturn('https://issuer.example/endpoint');
      }
      $scopes = $this->createMock(ScopeDiscoveryService::class);
      $scopes->method('getScopesSupported')->willReturn([]);
      foreach ([$scopes, $factory->get('simple_oauth.settings'), $factory->get('simple_oauth_server_metadata.settings')] as $dependency) {
        $dependency->method('getCacheContexts')->willReturn([]);
        $dependency->method('getCacheTags')->willReturn([]);
        $dependency->method('getCacheMaxAge')->willReturn(-1);
      }
      foreach (['client_credentials', 'code', 'authorization_code', 'replacement'] as $id) {
        $plugin = $id === 'replacement'
          ? $this->createMock(DiscoveryGrantInterface::class)
          : $this->createMock(Oauth2GrantInterface::class);
        if ($id === 'replacement') {
          $plugin->method('getGrantTypeIdentifier')->willReturn('authorization_code');
        }
        $plugin->expects($this->never())->method('getGrantType');
        $manager = $this->createMock(Oauth2GrantManager::class);
        $manager->method('getDefinitions')->willReturn([$id => []]);
        $manager->method('createInstance')->willReturn($plugin);
        $grants = new GrantTypeDiscoveryService($manager, $factory);
        $claims = new ClaimsAuthDiscoveryService($factory, NULL, $grants);
        $server = new ServerMetadataService($factory, $endpoints, $grants, $scopes, $claims);
        $metadata = $server->getServerMetadata();
        $enabled = $id !== 'client_credentials';
        $this->assertSame($enabled, $claims->getAuthorizationCodeFlowEnabled());
        $this->assertSame(in_array('code', $metadata['response_types_supported'], TRUE), $claims->getAuthorizationCodeFlowEnabled());
        $this->assertFalse($claims->getImplicitFlowEnabled());
        $openid = new OpenIdConfigurationService($factory, $endpoints, $scopes, $grants, $claims, ['sub']);
        if ($enabled) {
          $oidc = $openid->getOpenIdConfiguration();
          $this->assertSame($claims->getAuthorizationCodeFlowEnabled(), $oidc['authorization_code_flow_enabled']);
          $this->assertSame($claims->getImplicitFlowEnabled(), $oidc['implicit_flow_enabled']);
        }
        else {
          try {
            $openid->getOpenIdConfiguration();
            $this->fail('OIDC discovery requires at least one response type.');
          }
          catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('response_types_supported', $exception->getMessage());
          }
        }
      }
      // Respect the response capability override rather than reconstructing it.
      $grants = $this->createMock(GrantTypeDiscoveryService::class);
      $grants->method('getGrantTypesSupported')->willReturn(['urn:example:custom']);
      $response_types = [];
      $grants->method('getResponseTypesSupported')->willReturnCallback(static function () use (&$response_types): array {
        return $response_types;
      });
      $claims = new ClaimsAuthDiscoveryService($factory, NULL, $grants);
      $server = new ServerMetadataService($factory, $endpoints, $grants, $scopes, $claims);
      $openid = new OpenIdConfigurationService($factory, $endpoints, $scopes, $grants, $claims, ['sub']);
      foreach ([[], ['code'], []] as $response_types) {
        $metadata = $server->getServerMetadata();
        $this->assertSame($response_types !== [], $claims->getAuthorizationCodeFlowEnabled());
        $this->assertSame(in_array('code', $metadata['response_types_supported'], TRUE), $claims->getAuthorizationCodeFlowEnabled());
        if ($response_types !== []) {
          $this->assertSame($openid->getOpenIdConfiguration()['authorization_code_flow_enabled'], $claims->getAuthorizationCodeFlowEnabled());
        }
      }
      $legacy = new ClaimsAuthDiscoveryService($factory);
      $this->assertTrue($legacy->getAuthorizationCodeFlowEnabled());
      $this->assertFalse($legacy->getImplicitFlowEnabled());
    }
  }

  /**
   * Tests implicit flow stays disabled for original constructor callers.
   */
  public function testImplicitCapabilityDisabled(): void {
    $claims = new ClaimsAuthDiscoveryService($this->getConfigFactoryStub());
    $this->assertFalse($claims->getImplicitFlowEnabled());
  }

  /**
   * Tests shared URL fallback and each service's protected override dispatch.
   */
  public function testOptionalUrlHelperExtensions(): void {
    $factory = $this->getConfigFactoryStub([
      'simple_oauth_server_metadata.settings' => [
        'service_documentation' => '/docs',
        'op_policy_uri' => '/policy',
        'op_tos_uri' => '/terms',
        'resource_documentation' => '/docs',
      ],
      'simple_oauth.settings' => [],
    ]);
    $endpoints = $this->createMock(EndpointDiscoveryService::class);
    $endpoints->method('getIssuer')->willReturn('https://issuer.example/');
    foreach ([
      'getAuthorizationEndpoint',
      'getTokenEndpoint',
      'getJwksUri',
      'getUserInfoEndpoint',
      'getOauthServerMetadataEndpoint',
    ] as $method) {
      $endpoints->method($method)->willReturn('https://issuer.example/endpoint');
    }
    $endpoints->method('getCoreEndpoints')->willReturn([]);
    $grants = $this->createMock(GrantTypeDiscoveryService::class);
    $grants->method('getResponseTypesSupported')->willReturn(['code']);
    $grants->method('getGrantTypesSupported')->willReturn(['authorization_code']);
    $scopes = $this->createMock(ScopeDiscoveryService::class);
    $scopes->method('getScopesSupported')->willReturn([]);
    foreach ([$scopes, $factory->get('simple_oauth.settings'), $factory->get('simple_oauth_server_metadata.settings')] as $dependency) {
      $dependency->method('getCacheContexts')->willReturn([]);
      $dependency->method('getCacheTags')->willReturn([]);
      $dependency->method('getCacheMaxAge')->willReturn(-1);
    }
    $claims = new ClaimsAuthDiscoveryService($factory, NULL, $grants);
    $server = new class ($factory, $endpoints, $grants, $scopes, $claims) extends ServerMetadataService {

      /**
       * {@inheritdoc}
       */
      protected function ensureAbsoluteUrl(string $url): string {
        return 'https://override.example' . parent::ensureAbsoluteUrl($url);
      }

    };
    $openid = new class ($factory, $endpoints, $scopes, $grants, $claims, ['sub']) extends OpenIdConfigurationService {

      /**
       * {@inheritdoc}
       */
      protected function ensureAbsoluteUrl(string $url): string {
        return 'https://override.example' . parent::ensureAbsoluteUrl($url);
      }

    };
    $resource = new class ($factory, $endpoints, $this->createMock(EventDispatcherInterface::class)) extends ResourceMetadataService {

      /**
       * {@inheritdoc}
       */
      protected function ensureAbsoluteUrl(string $url): string {
        return 'https://override.example' . parent::ensureAbsoluteUrl($url);
      }

    };
    // Fail URL conversion deliberately; the configured value must survive.
    $validator = $this->createMock(PathValidatorInterface::class);
    $validator->method('getUrlIfValidWithoutAccessCheck')->willThrowException(new \RuntimeException('URL context unavailable.'));
    \Drupal::getContainer()->set('path.validator', $validator);
    foreach ([$server->getServerMetadata(), $openid->getOpenIdConfiguration()] as $metadata) {
      foreach (['service_documentation' => '/docs', 'op_policy_uri' => '/policy', 'op_tos_uri' => '/terms'] as $field => $path) {
        $this->assertSame('https://override.example' . $path, $metadata[$field]);
      }
    }
    $this->assertSame('https://override.example/docs', $resource->getResourceMetadata()['resource_documentation']);
  }

  /**
   * Tests unknown collections expire while item dependencies refresh each call.
   */
  public function testExternalCollectionCacheability(): void {
    $scope = $this->createMock(CacheableScopeInterface::class);
    $scope->method('getName')->willReturn('openid');
    $scope->method('getCacheTags')->willReturn(['external:item']);
    $scope->method('getCacheContexts')->willReturn(['url.query_args']);
    $scope->method('getCacheMaxAge')->willReturn(120);
    $provider = $this->createMock(Oauth2ScopeProviderInterface::class);
    $provider->method('loadMultiple')->willReturnOnConsecutiveCalls([$scope], []);
    $service = $this->createDiscovery($provider);
    $this->assertIsArray(json_decode(json_encode($service->getScopesSupported())));
    $this->assertSame(120, $service->getCacheMaxAge());
    $this->assertContains('external:item', $service->getCacheTags());
    $this->assertContains('url.query_args', $service->getCacheContexts());
    $service->getScopesSupported();
    $this->assertSame(300, $service->getCacheMaxAge());
    $this->assertNotContains('external:item', $service->getCacheTags());
    $this->assertNotContains('url.query_args', $service->getCacheContexts());
  }

  /**
   * Tests collection dependencies supplied by custom providers.
   */
  public function testCacheableCollectionProvider(): void {
    $provider = $this->createMock(CacheableScopeProviderInterface::class);
    $provider->method('loadMultiple')->willReturn([]);
    $provider->method('getCacheTags')->willReturn(['external:collection']);
    $provider->method('getCacheContexts')->willReturn(['url.site']);
    $provider->method('getCacheMaxAge')->willReturn(1800);
    $service = $this->createDiscovery($provider);
    $service->getScopesSupported();
    $this->assertSame(1800, $service->getCacheMaxAge());
    $this->assertContains('external:collection', $service->getCacheTags());
    $this->assertContains('url.site', $service->getCacheContexts());
  }

  /**
   * Tests the fallback collection lifetime cannot extend an override's limit.
   */
  public function testExternalProviderHonorsConfigurationLimit(): void {
    $factory = $this->getConfigFactoryStub(['simple_oauth.settings' => ['scope_provider' => 'external']]);
    $config = $factory->get('simple_oauth.settings');
    $config->method('getCacheTags')->willReturn(['override:scope']);
    $config->method('getCacheContexts')->willReturn(['url.query_args']);
    $config->method('getCacheMaxAge')->willReturn(30);
    $provider = $this->createMock(Oauth2ScopeProviderInterface::class);
    $provider->method('loadMultiple')->willReturn([]);
    $service = new ScopeDiscoveryService($provider, $factory, $this->createMock(LoggerChannelFactoryInterface::class));
    $service->getScopesSupported();
    $this->assertSame(30, $service->getCacheMaxAge());
    $this->assertContains('override:scope', $service->getCacheTags());
    $this->assertContains('url.query_args', $service->getCacheContexts());
  }

  /**
   * Tests malformed POST credentials never reach the repository.
   */
  public function testMalformedCredentials(): void {
    $repository = $this->createMock(ClientRepositoryInterface::class);
    $repository->expects($this->never())->method('getClientEntity');
    $service = new ClientAuthenticationService($repository);
    $bodies = [
      [],
      ['client_id' => []],
      ['client_id' => 123],
      ['client_id' => new \stdClass()],
      ['client_id' => 'client', 'client_secret' => []],
      ['client_id' => 'client', 'client_secret' => FALSE],
      ['client_id' => 'client', 'client_secret' => 1],
    ];
    foreach ($bodies as $body) {
      $request = (new ServerRequest('POST', '/oauth/revoke'))->withParsedBody($body);
      $this->assertNull($service->authenticateClient($request));
    }
    foreach (['Basic !!!', 'Basic ' . base64_encode('no-colon')] as $header) {
      $request = new ServerRequest('POST', '/oauth/revoke', ['Authorization' => $header]);
      $this->assertNull($service->authenticateClient($request));
    }
  }

  /**
   * Creates discovery with cacheability on the configuration test double.
   *
   * @param \Drupal\simple_oauth\Oauth2ScopeProviderInterface $provider
   *   The external provider.
   *
   * @return \Drupal\simple_oauth_server_metadata\Service\ScopeDiscoveryService
   *   The discovery service.
   */
  private function createDiscovery(Oauth2ScopeProviderInterface $provider): ScopeDiscoveryService {
    $factory = $this->getConfigFactoryStub(['simple_oauth.settings' => ['scope_provider' => 'external']]);
    $config = $factory->get('simple_oauth.settings');
    $config->method('getCacheTags')->willReturn([]);
    $config->method('getCacheContexts')->willReturn([]);
    $config->method('getCacheMaxAge')->willReturn(-1);
    return new ScopeDiscoveryService($provider, $factory, $this->createMock(LoggerChannelFactoryInterface::class));
  }

}
