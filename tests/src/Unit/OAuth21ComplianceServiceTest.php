<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_21\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Cache\Context\SiteCacheContext;
use Drupal\Core\Config\Config;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\simple_oauth_21\Service\OAuth21ComplianceService;
use Drupal\simple_oauth_server_metadata\Service\ServerMetadataService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Tests compliance reporting against optional discovered metadata capabilities.
 */
#[Group('simple_oauth_21')]
final class OAuth21ComplianceServiceTest extends UnitTestCase {

  /**
   * Whether the metadata fixture must throw a transient failure.
   */
  private bool $metadataFailure = FALSE;

  /**
   * Whether route discovery must fail after metadata generation.
   */
  private bool $routeFailure = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('cache_context.url.site', new SiteCacheContext(new RequestStack()));
    $container->set('cache_contexts_manager', new CacheContextsManager($container, ['url.site']));
    \Drupal::setContainer($container);
  }

  /**
   * Tests complete, incomplete, invalid and unavailable metadata discovery.
   */
  public function testDiscoveredMetadataCompliance(): void {
    /** @var string[] $cases */
    $cases = ['complete', 'incomplete', 'invalid', 'exception', 'missing_service', 'absent'];
    foreach ($cases as $case) {
      $modules = $this->createMock(ModuleHandlerInterface::class);
      $modules->method('moduleExists')->willReturnCallback(
        static fn(string $name): bool => $case !== 'absent' && $name === 'simple_oauth_server_metadata'
      );
      $config = $this->createMock(Config::class);
      $config->method('isNew')->willReturn(FALSE);
      $config->method('getCacheContexts')->willReturn([]);
      $config->method('getCacheTags')->willReturn([]);
      $config->method('getCacheMaxAge')->willReturn(-1);
      $factory = $this->createMock(ConfigFactoryInterface::class);
      $factory->method('get')->willReturn($config);
      $routes = $this->createMock(RouteProviderInterface::class);
      $routes->method('getRouteByName')->willThrowException(new RouteNotFoundException());
      $metadata = $this->createMock(ServerMetadataService::class);
      $metadata->method('getCacheContexts')->willReturn([]);
      $metadata->method('getCacheTags')->willReturn([]);
      $metadata->method('getCacheMaxAge')->willReturn(-1);
      $capabilities = [
        'issuer' => 'https://issuer.example',
        'authorization_endpoint' => 'https://issuer.example/oauth/authorize',
        'token_endpoint' => 'https://issuer.example/oauth/token',
      ];
      if ($case === 'incomplete') {
        unset($capabilities['token_endpoint']);
      }
      elseif ($case === 'invalid') {
        $capabilities['issuer'] = 'invalid';
      }
      if ($case === 'exception') {
        $metadata->method('getServerMetadata')->willThrowException(new \RuntimeException('Discovery unavailable'));
      }
      else {
        $metadata->method('getServerMetadata')->willReturn($capabilities);
      }
      $service = new OAuth21ComplianceService($modules, $factory, $routes, in_array($case, ['absent', 'missing_service'], TRUE) ? NULL : $metadata);
      $result = $service->getRfcComplianceStatus()['rfc_8414'];
      $expected = match ($case) {
        'complete' => 'configured',
        'absent' => 'not_available',
        default => 'needs_attention',
      };
      $this->assertSame($expected, $result['status'], $case);
      if (in_array($case, ['exception', 'missing_service'], TRUE)) {
        self::assertSame(0, $service->getCacheMaxAge());
      }
      if (in_array($case, ['incomplete', 'invalid', 'exception', 'missing_service'], TRUE)) {
        $this->assertNotSame('Server metadata is available for discovery', $result['recommendation']);
        $this->assertNotEmpty($result['recommendation']);
      }
    }
  }

  /**
   * Tests native compliance follows the master switch and legacy permissions.
   */
  public function testNativeComplianceMasterSwitch(): void {
    foreach ([
      [TRUE, 'auto-detect', 'native', TRUE],
      [FALSE, 'auto-detect', 'native', FALSE],
      [NULL, 'auto-detect', 'native', FALSE],
      [TRUE, '1', '1', TRUE],
      [TRUE, '0', '1', FALSE],
      [TRUE, '1', '0', FALSE],
      [TRUE, 'web', 'native', FALSE],
      [FALSE, '1', '1', FALSE],
    ] as [$enabled, $custom, $loopback, $expected]) {
      $modules = $this->createMock(ModuleHandlerInterface::class);
      $modules->method('moduleExists')->willReturnCallback(
        static fn(string $name): bool => $name === 'simple_oauth_native_apps'
      );
      $config = $this->createMock(Config::class);
      $config->method('isNew')->willReturn(FALSE);
      $config->method('getCacheContexts')->willReturn([]);
      $config->method('getCacheTags')->willReturn(['config:simple_oauth_native_apps.settings']);
      $config->method('getCacheMaxAge')->willReturn(-1);
      $config->method('get')->willReturnMap([
        ['enforce_native_security', $enabled],
        ['allow.custom_uri_schemes', $custom],
        ['allow.loopback_redirects', $loopback],
      ]);
      $factory = $this->createMock(ConfigFactoryInterface::class);
      $factory->method('get')->willReturn($config);
      $routes = $this->createMock(RouteProviderInterface::class);
      $routes->method('getRouteByName')->willThrowException(new RouteNotFoundException());
      $service = new OAuth21ComplianceService($modules, $factory, $routes);
      self::assertSame([
        'status' => $expected ? 'configured' : 'needs_attention',
        'module' => 'simple_oauth_native_apps',
        'enabled' => TRUE,
        'recommendation' => 'Native app security features are enabled',
      ], $service->getRfcComplianceStatus()['rfc_8252']);
      self::assertContains('config:simple_oauth_native_apps.settings', $service->getCacheTags());
    }
  }

  /**
   * Tests fallback config dependencies and per-generation metadata state.
   */
  public function testCacheabilityAndPublicContract(): void {
    $enabled = ['simple_oauth_21_pkce', 'simple_oauth_pkce', 'simple_oauth_server_metadata'];
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(static function (string $name) use (&$enabled): bool {
      return in_array($name, $enabled, TRUE);
    });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnCallback(function (string $name): Config {
      $config = $this->createMock(Config::class);
      $config->method('isNew')->willReturn($name === 'simple_oauth_21_pkce.settings');
      $config->method('getCacheTags')->willReturn(['config:' . $name]);
      $config->method('getCacheContexts')->willReturn([]);
      $config->method('getCacheMaxAge')->willReturn(-1);
      $config->method('get')->willReturnMap([['enforcement', 'mandatory'], ['s256_enabled', TRUE]]);
      return $config;
    });
    $routes = $this->createMock(RouteProviderInterface::class);
    $routes->method('getRouteByName')->willReturnCallback(function (): never {
      if ($this->routeFailure) {
        throw new \RuntimeException('Temporary route failure');
      }
      throw new RouteNotFoundException();
    });
    $metadata = $this->createMock(ServerMetadataService::class);
    $metadata->method('getCacheTags')->willReturn(['metadata:current']);
    $metadata->method('getCacheContexts')->willReturn(['url.site']);
    $metadata->method('getCacheMaxAge')->willReturn(120);
    $this->metadataFailure = FALSE;
    $metadata->method('getServerMetadata')->willReturnCallback(function (): array {
      if ($this->metadataFailure) {
        throw new \RuntimeException('Temporary failure');
      }
      return [
        'issuer' => 'https://issuer.example',
        'authorization_endpoint' => 'https://issuer.example/authorize',
        'token_endpoint' => 'https://issuer.example/token',
      ];
    });
    $service = new OAuth21ComplianceService($modules, $factory, $routes, $metadata);
    self::assertSame(0, $service->getCacheMaxAge());
    $result = $service->getRfcComplianceStatus();
    self::assertSame([
      'status' => 'configured',
      'module' => 'simple_oauth_pkce',
      'enabled' => TRUE,
      'recommendation' => 'PKCE is enabled for OAuth 2.1 compliance',
    ], $result['rfc_7636']);
    foreach ($result as $rfc) {
      self::assertIsString($rfc['status']);
      self::assertIsString($rfc['recommendation']);
      self::assertIsString($rfc['module']);
      self::assertIsBool($rfc['enabled']);
    }
    self::assertContains('config:simple_oauth_21_pkce.settings', $service->getCacheTags());
    self::assertContains('config:simple_oauth_pkce.settings', $service->getCacheTags());
    self::assertContains('config:core.extension', $service->getCacheTags());
    self::assertContains('routes', $service->getCacheTags());
    self::assertContains('metadata:current', $service->getCacheTags());
    self::assertSame(['url.site'], $service->getCacheContexts());
    self::assertSame(120, $service->getCacheMaxAge());
    $this->metadataFailure = TRUE;
    $service->getRfcComplianceStatus();
    self::assertSame(0, $service->getCacheMaxAge());
    self::assertNotContains('metadata:current', $service->getCacheTags());
    self::assertSame([], $service->getCacheContexts());
    $this->metadataFailure = FALSE;
    $service->getRfcComplianceStatus();
    self::assertSame(120, $service->getCacheMaxAge());
    $this->routeFailure = TRUE;
    try {
      $service->getRfcComplianceStatus();
      self::fail('Route discovery must throw the fixture exception.');
    }
    catch (\RuntimeException $e) {
      self::assertSame('Temporary route failure', $e->getMessage());
      self::assertSame(0, $service->getCacheMaxAge());
    }
    $this->routeFailure = FALSE;
    $enabled = [];
    $service->getRfcComplianceStatus();
    self::assertSame(['config:core.extension', 'routes'], $service->getCacheTags());
    self::assertSame([], $service->getCacheContexts());
    self::assertSame(-1, $service->getCacheMaxAge());
  }

}
