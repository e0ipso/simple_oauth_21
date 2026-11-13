<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Unit;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\simple_oauth\Oauth2ScopeProviderInterface;
use Drupal\simple_oauth_server_metadata\Service\ClientAuthenticationService;
use Drupal\simple_oauth_server_metadata\Service\ScopeDiscoveryService;
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
