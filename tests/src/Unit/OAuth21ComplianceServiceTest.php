<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_21\Unit;

use Drupal\Core\Config\Config;
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
   * Tests complete, incomplete, invalid and unavailable metadata discovery.
   */
  public function testDiscoveredMetadataCompliance(): void {
    /** @var string[] $cases */
    $cases = ['complete', 'incomplete', 'invalid', 'exception', 'absent'];
    foreach ($cases as $case) {
      $modules = $this->createMock(ModuleHandlerInterface::class);
      $modules->method('moduleExists')->willReturnCallback(
        static fn(string $name): bool => $case !== 'absent' && $name === 'simple_oauth_server_metadata'
      );
      $config = $this->createMock(Config::class);
      $config->method('isNew')->willReturn(FALSE);
      $factory = $this->createMock(ConfigFactoryInterface::class);
      $factory->method('get')->willReturn($config);
      $routes = $this->createMock(RouteProviderInterface::class);
      $routes->method('getRouteByName')->willThrowException(new RouteNotFoundException());
      $metadata = $this->createMock(ServerMetadataService::class);
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
      $service = new OAuth21ComplianceService($modules, $factory, $routes, $case === 'absent' ? NULL : $metadata);
      $result = $service->getRfcComplianceStatus()['rfc_8414'];
      $expected = match ($case) {
        'complete' => 'configured',
        'absent' => 'not_available',
        default => 'needs_attention',
      };
      $this->assertSame($expected, $result['status'], $case);
      if (in_array($case, ['incomplete', 'invalid', 'exception'], TRUE)) {
        $this->assertNotSame('Server metadata is available for discovery', $result['recommendation']);
        $this->assertNotEmpty($result['recommendation']);
      }
    }
  }

}
