<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Unit;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\simple_oauth\Oauth2ScopeProviderInterface;
use Drupal\simple_oauth\Plugin\Oauth2GrantInterface;
use Drupal\simple_oauth\Plugin\Oauth2GrantManager;
use Drupal\simple_oauth_21\Plugin\Oauth2GrantIdentifierInterface;
use Drupal\simple_oauth_server_metadata\Service\ClientAuthenticationService;
use Drupal\simple_oauth_server_metadata\Service\ScopeDiscoveryService;
use Drupal\simple_oauth_server_metadata\Service\GrantTypeDiscoveryService;
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
    $mapped = $this->createMockForIntersectionOfInterfaces([
      Oauth2GrantInterface::class,
      Oauth2GrantIdentifierInterface::class,
    ]);
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
   * Tests unknown collections expire while item dependencies refresh each call.
   */
  public function testExternalCollectionCacheability(): void {
    $scope = $this->createMockForIntersectionOfInterfaces([
      Oauth2ScopeInterface::class,
      CacheableDependencyInterface::class,
    ]);
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
    $provider = $this->createMockForIntersectionOfInterfaces([
      Oauth2ScopeProviderInterface::class,
      CacheableDependencyInterface::class,
    ]);
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
