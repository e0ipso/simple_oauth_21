<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_client_registration\Kernel;

use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth_client_registration\Controller\ClientRegistrationController;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests actual registration installation without optional metadata integration.
 */
#[Group('simple_oauth_client_registration')]
#[RunTestsInSeparateProcesses]
final class ClientRegistrationStandaloneTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'file', 'image', 'options', 'serialization', 'consumers',
    'simple_oauth', 'simple_oauth_21',
  ];

  /**
   * Tests installation and management do not install or configure metadata.
   */
  public function testStandaloneInstallationAndManagement(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('consumer');
    $this->installEntitySchema('oauth2_token');
    $this->installConfig(['system', 'simple_oauth']);
    Role::create(['id' => 'authenticated', 'label' => 'Authenticated'])->save();
    $this->container->get('module_installer')->install(['simple_oauth_client_registration']);
    $this->assertFalse($this->container->get('module_handler')->moduleExists('simple_oauth_server_metadata'));
    $this->assertFalse($this->container->has('simple_oauth_server_metadata.endpoint_discovery'));
    $storage = $this->container->get('config.storage');
    $this->assertFalse($storage->exists('simple_oauth_server_metadata.settings'));
    $generator = $this->createMock(UrlGeneratorInterface::class);
    $generator->method('generateFromRoute')->willReturn('https://example.org/oauth/register');
    $this->container->set('url_generator', $generator);
    $controller = ClientRegistrationController::create($this->container);
    $response = $controller->register(Request::create('/oauth/register', 'POST', content: '{"redirect_uris":["https://example.org/callback"]}'));
    $this->assertSame(201, $response->getStatusCode(), $response->getContent());
    $data = json_decode($response->getContent(), TRUE);
    foreach (['GET' => 200, 'PUT' => 200, 'DELETE' => 204] as $method => $status) {
      $request = Request::create('/oauth/register/' . $data['client_id'], $method, content: '{"client_name":"Standalone edit"}');
      $request->headers->set('Authorization', 'Bearer ' . $data['registration_access_token']);
      $request->attributes->set('_route', 'simple_oauth_client_registration.manage');
      $response = $controller->manage($request, $data['client_id']);
      $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
      $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::RESPONSE);
      $this->assertSame($status, $response->getStatusCode(), $response->getContent());
      $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }
    $this->assertFalse($storage->exists('simple_oauth_server_metadata.settings'));
    $this->assertFalse($this->container->get('module_handler')->moduleExists('simple_oauth_server_metadata'));
    $this->assertFalse($this->container->get('simple_oauth_client_registration.service.token')->validateRegistrationAccessToken($data['client_id'], $data['registration_access_token']));
  }

}
