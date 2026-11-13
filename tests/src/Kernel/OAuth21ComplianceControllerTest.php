<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_21\Kernel;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\ElementInfoManager;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth_21\Controller\OAuth21ComplianceController;
use Drupal\simple_oauth_21\Service\OAuth21ComplianceService;
use Drupal\simple_oauth_server_metadata\Service\ServerMetadataService;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Tests rendering with and without the optional component render element.
 *
 * @group simple_oauth_21
 * @runTestsInSeparateProcesses
 */
#[Group('simple_oauth_21')]
final class OAuth21ComplianceControllerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'simple_oauth_21'];

  /**
   * Whether route discovery must throw a transient exception.
   */
  private bool $failDiscovery = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(FALSE);
    $routes = $this->createMock(RouteProviderInterface::class);
    $routes->method('getRouteByName')->willReturnCallback(function (): never {
      if ($this->failDiscovery) {
        throw new \RuntimeException('Temporary discovery failure');
      }
      throw new RouteNotFoundException();
    });
    $service = new OAuth21ComplianceService($modules, $this->container->get('config.factory'), $routes);
    $this->container->set('simple_oauth_21.compliance_service', $service);
  }

  /**
   * Tests the existing component path for success and transient errors.
   */
  public function testComponentRendering(): void {
    $controller = OAuth21ComplianceController::create($this->container);
    $build = $controller->display();
    self::assertSame('component', $build['#type']);
    self::assertSame('simple_oauth_21:rfc-matrix', $build['#component']);
    self::assertSame('PKCE (Proof Key for Code Exchange)', (string) $build['#props']['rfcs'][0]['title']);
    self::assertSame('Enable PKCE module for OAuth 2.1 compliance', $build['#props']['rfcs'][0]['recommendation']);
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    self::assertStringContainsString('PKCE (Proof Key for Code Exchange)', $html);
    $this->failDiscovery = TRUE;
    $build = $controller->display();
    self::assertSame('simple_oauth_21:compliance-tip', $build['#component']);
    self::assertSame(0, $build['#cache']['max-age']);
    self::assertStringContainsString('Unable to load compliance status.', (string) $this->container->get('renderer')->renderRoot($build));
  }

  /**
   * Removes the component element from Drupal's actual plugin definitions.
   */
  private function removeComponentRenderer(): void {
    // Remove the definition from the real manager used by the real renderer.
    $element_info = $this->container->get('plugin.manager.element_info');
    $definitions = $element_info->getDefinitions();
    unset($definitions['component']);
    (new \ReflectionProperty(ElementInfoManager::class, 'definitions'))->setValue($element_info, $definitions);
    self::assertFalse($element_info->hasDefinition('component'));
    self::assertSame(['#defaults_loaded' => TRUE], $element_info->getInfo('component'));
  }

  /**
   * Tests the transient-error fallback without a component element.
   */
  public function testFallbackErrorRendering(): void {
    $this->removeComponentRenderer();
    $this->failDiscovery = TRUE;
    $controller = OAuth21ComplianceController::create($this->container);
    $renderer = $this->container->get('renderer');
    $build = $controller->display();
    self::assertSame('container', $build['#type']);
    self::assertSame(0, $build['#cache']['max-age']);
    self::assertStringContainsString('Unable to load compliance status.', (string) $renderer->renderRoot($build));
  }

  /**
   * Tests standard rendering, cache metadata and escaped presentation text.
   */
  public function testFallbackSuccessRendering(): void {
    $this->removeComponentRenderer();
    $renderer = $this->container->get('renderer');
    $service = $this->container->get('simple_oauth_21.compliance_service');
    // Exercise explicit constructor injection alongside create().
    $controller = new OAuth21ComplianceController($service, $this->container->get('plugin.manager.element_info'));
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(static function (TranslatableMarkup $text): string {
      return $text->getUntranslatedString() === 'S256 challenge method, mandatory enforcement'
        ? '<script>alert("unsafe")</script>'
        : $text->getUntranslatedString();
    });
    $controller->setStringTranslation($translation);
    $build = $controller->display();
    self::assertSame('container', $build['#type']);
    self::assertSame(-1, $build['#cache']['max-age']);
    self::assertContains('config:core.extension', $build['#cache']['tags']);
    self::assertContains('routes', $build['#cache']['tags']);
    $html = (string) $renderer->renderRoot($build);
    self::assertStringContainsString('<table', $html);
    self::assertStringNotContainsString('<script>', $html);
    self::assertStringContainsString('&lt;script&gt;', $html);
    self::assertStringContainsString('PKCE (Proof Key for Code Exchange)', $html);
    self::assertStringContainsString('Enable PKCE module for OAuth 2.1 compliance', $html);
  }

  /**
   * Tests translated presentation without changing the service string contract.
   */
  public function testTranslationBoundary(): void {
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(static fn(string $name): bool => $name === 'simple_oauth_server_metadata');
    $routes = $this->createMock(RouteProviderInterface::class);
    $routes->method('getRouteByName')->willThrowException(new RouteNotFoundException());
    $metadata = $this->createMock(ServerMetadataService::class);
    $metadata->method('getServerMetadata')->willReturn([]);
    $metadata->method('getCacheTags')->willReturn([]);
    $metadata->method('getCacheContexts')->willReturn([]);
    $metadata->method('getCacheMaxAge')->willReturn(-1);
    $service = new OAuth21ComplianceService($modules, $this->container->get('config.factory'), $routes, $metadata);
    $controller = new OAuth21ComplianceController($service, $this->container->get('plugin.manager.element_info'));
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(static fn(TranslatableMarkup $text): string => 'translated: ' . $text->getUntranslatedString());
    $controller->setStringTranslation($translation);
    $build = $controller->display();
    $rfcs = $build['#props']['rfcs'];
    self::assertSame('translated: PKCE (Proof Key for Code Exchange)', (string) $rfcs[0]['title']);
    self::assertSame('translated: S256 challenge method, mandatory enforcement', (string) $rfcs[0]['description']);
    self::assertSame('translated: Enable PKCE module for OAuth 2.1 compliance', $rfcs[0]['recommendation']);
    self::assertSame('Enable PKCE module for OAuth 2.1 compliance', $service->getRfcComplianceStatus()['rfc_7636']['recommendation']);
    self::assertSame('translated: Discover a valid HTTPS URL for issuer in server metadata; translated: Discover a valid HTTPS URL for authorization_endpoint in server metadata; translated: Discover a valid HTTPS URL for token_endpoint in server metadata', $rfcs[1]['recommendation']);
    $translate = new \ReflectionMethod(OAuth21ComplianceController::class, 'translateRecommendation');
    $unknown = '<script>future message</script>';
    self::assertSame($unknown, $translate->invoke($controller, $unknown));
    $known = 'Server metadata discovery is unavailable; check the metadata module service registration';
    self::assertSame('translated: ' . $known, $translate->invoke($controller, $known));
  }

}
