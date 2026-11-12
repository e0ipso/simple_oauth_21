<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_pkce\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth_pkce\Service\PkceSettingsService;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Test PKCE configuration and service functionality.
 */
#[Group('simple_oauth_pkce')]
final class PkceConfigurationTest extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'file',
    'image',
    'options',
    'consumers',
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_pkce',
    'serialization',
  ];

  /**
   * The PKCE settings service.
   *
   * @var \Drupal\simple_oauth_pkce\Service\PkceSettingsService
   */
  protected PkceSettingsService $pkceSettings;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['simple_oauth_pkce']);
    $this->pkceSettings = $this->container->get('simple_oauth_pkce.settings');
  }

  /**
   * Test default PKCE configuration.
   */
  public function testDefaultConfiguration(): void {
    $config = $this->config('simple_oauth_pkce.settings');

    // Test default values match OAuth 2.1 recommendations.
    $this->assertEquals('mandatory', $config->get('enforcement'));
    $this->assertTrue($config->get('s256_enabled'));
    $this->assertTrue($config->get('plain_enabled'));
  }

  /**
   * Test PKCE settings service methods.
   */
  public function testPkceSettingsService(): void {
    // Test default methods.
    $this->assertEquals('mandatory', $this->pkceSettings->getEnforcementLevel());
    $this->assertTrue($this->pkceSettings->isS256Enabled());
    $this->assertTrue($this->pkceSettings->isPlainEnabled());
    $this->assertTrue($this->pkceSettings->isMandatory());
    $this->assertFalse($this->pkceSettings->isDisabled());

    $supported_methods = $this->pkceSettings->getSupportedMethods();
    $this->assertContains('S256', $supported_methods);
    $this->assertContains('plain', $supported_methods);
  }

  /**
   * Test configuration changes affect service.
   */
  public function testConfigurationChanges(): void {
    // Change to disabled enforcement.
    $this->config('simple_oauth_pkce.settings')
      ->set('enforcement', 'disabled')
      ->set('s256_enabled', FALSE)
      ->set('plain_enabled', FALSE)
      ->save();

    // Create new service instance to get updated config.
    $updated_settings = $this->container->get('simple_oauth_pkce.settings');

    $this->assertTrue($updated_settings->isDisabled());
    $this->assertFalse($updated_settings->isMandatory());
    $this->assertFalse($updated_settings->isS256Enabled());
    $this->assertFalse($updated_settings->isPlainEnabled());
    $this->assertEmpty($updated_settings->getSupportedMethods());
  }

  /**
   * Tests global authorization and token policy before controller processing.
   */
  public function testGlobalRequestPolicy(): void {
    /** @var array<int, array{string, bool, bool, string, array<string, string>, int|null}> $cases */
    $cases = [
      ['mandatory', TRUE, TRUE, 'authorize', [], 400],
      ['optional', TRUE, TRUE, 'authorize', [], NULL],
      ['disabled', FALSE, FALSE, 'authorize', [], NULL],
      [
        'mandatory', TRUE, FALSE, 'authorize',
        ['code_challenge' => 'challenge', 'code_challenge_method' => 'plain'],
        400,
      ],
      [
        'mandatory', FALSE, TRUE, 'authorize',
        ['code_challenge' => 'challenge', 'code_challenge_method' => 'S256'],
        400,
      ],
      [
        'mandatory', TRUE, FALSE, 'authorize',
        ['code_challenge' => 'challenge', 'code_challenge_method' => 'S256'],
        NULL,
      ],
      [
        'optional', TRUE, TRUE, 'authorize',
        ['code_challenge' => 'challenge', 'code_challenge_method' => 'unsupported'],
        400,
      ],
      ['mandatory', TRUE, TRUE, 'token', [], 400],
      ['mandatory', TRUE, TRUE, 'token', ['code_verifier' => 'valid-verifier'], NULL],
      ['optional', TRUE, TRUE, 'token', [], NULL],
      ['disabled', FALSE, FALSE, 'token', [], NULL],
    ];
    $subscriber = $this->container->get('simple_oauth_pkce.policy_subscriber');
    foreach ($cases as [$level, $s256, $plain, $endpoint, $parameters, $status]) {
      $this->config('simple_oauth_pkce.settings')
        ->set('enforcement', $level)
        ->set('s256_enabled', $s256)
        ->set('plain_enabled', $plain)
        ->save();
      $parameters += $endpoint === 'authorize'
        ? ['response_type' => 'code']
        : ['grant_type' => 'authorization_code'];
      $request = Request::create('/oauth/' . $endpoint, $endpoint === 'authorize' ? 'GET' : 'POST', $parameters);
      $request->attributes->set('_route', 'oauth2_token.' . $endpoint);
      $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
      $subscriber->onRequest($event);
      if ($status === NULL) {
        $this->assertFalse($event->hasResponse());
      }
      else {
        $this->assertSame($status, $event->getResponse()->getStatusCode());
        $this->assertSame('invalid_request', json_decode($event->getResponse()->getContent(), TRUE)['error']);
        $this->assertTrue($event->getResponse()->headers->hasCacheControlDirective('no-store'));
      }
    }
  }

  /**
   * Tests malformed PKCE values under each global policy with a web client.
   */
  public function testGlobalPolicyRejectsMalformedParameters(): void {
    $this->installEntitySchema('consumer');
    $consumer = Consumer::create([
      'label' => 'Global PKCE policy client',
      'client_id' => 'global-pkce-policy-client',
      'confidential' => TRUE,
      'redirect' => ['https://example.com/callback'],
    ]);
    $consumer->save();
    $challenge = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    /** @var array<int, array{string, array<string, mixed>, bool}> $cases */
    $cases = [
      ['authorize', ['code_challenge' => []], TRUE],
      ['authorize', ['code_challenge' => [$challenge]], TRUE],
      ['authorize', ['code_challenge' => ['nested' => [$challenge]]], TRUE],
      ['authorize', ['code_challenge' => $challenge, 'code_challenge_method' => []], TRUE],
      ['authorize', ['code_challenge' => $challenge, 'code_challenge_method' => ['S256']], TRUE],
      ['token', ['code_verifier' => []], TRUE],
      ['token', ['code_verifier' => [$challenge]], TRUE],
      ['token', ['code_verifier' => ['nested' => [$challenge]]], TRUE],
      ['authorize', ['code_challenge' => $challenge, 'code_challenge_method' => 'S256'], FALSE],
      ['authorize', ['code_challenge' => $challenge, 'code_challenge_method' => 'plain'], FALSE],
      ['token', ['code_verifier' => $challenge], FALSE],
    ];
    $subscriber = $this->container->get('simple_oauth_pkce.policy_subscriber');
    $route_provider = $this->container->get('router.route_provider');
    $router = $this->container->get('router.no_access_checks');

    foreach (['mandatory', 'optional', 'disabled'] as $level) {
      $this->config('simple_oauth_pkce.settings')
        ->set('enforcement', $level)
        ->set('s256_enabled', TRUE)
        ->set('plain_enabled', TRUE)
        ->save();
      foreach ($cases as $index => [$endpoint, $parameters, $malformed]) {
        $parameters += [
          'client_id' => $consumer->getClientId(),
          'redirect_uri' => 'https://example.com/callback',
        ];
        $parameters += $endpoint === 'authorize'
          ? ['response_type' => 'code']
          : ['grant_type' => 'authorization_code'];
        $route = $route_provider->getRouteByName('oauth2_token.' . $endpoint);
        $request = Request::create($route->getPath(), $endpoint === 'authorize' ? 'GET' : 'POST', $parameters);
        $router->getContext()->fromRequest($request);
        $request->attributes->add($router->matchRequest($request));
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onRequest($event);
        $context = "$level / $endpoint / $index";
        $this->assertSame($malformed && $level !== 'disabled', $event->hasResponse(), $context);
        if ($event->hasResponse()) {
          $response = $event->getResponse();
          $this->assertSame(400, $response->getStatusCode(), $context);
          $error = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
          $this->assertSame('invalid_request', $error['error'], $context);
          $this->assertTrue($response->headers->hasCacheControlDirective('no-store'), $context);
          $this->assertSame('no-cache', $response->headers->get('Pragma'), $context);
        }
      }
    }
  }

}
