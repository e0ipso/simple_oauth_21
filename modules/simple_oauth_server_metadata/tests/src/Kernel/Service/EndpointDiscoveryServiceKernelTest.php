<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Kernel\Service;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\simple_oauth_server_metadata\Controller\OpenIdConfigurationController;
use Drupal\simple_oauth_server_metadata\Controller\ServerMetadataController;
use Drupal\simple_oauth_server_metadata\Form\ServerMetadataSettingsForm;
use Symfony\Component\HttpFoundation\Request;
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

    $this->installConfig(['system', 'language', 'simple_oauth', 'simple_oauth_server_metadata']);
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

  /**
   * Tests automatic optional endpoints in both discovery responses.
   */
  public function testAutomaticOptionalEndpointResponses(): void {
    $endpoints = $this->container->get('simple_oauth_server_metadata.endpoint_discovery')->getCoreEndpoints();
    $this->assertEndpointResponses(array_intersect_key($endpoints, array_flip([
      'registration_endpoint', 'revocation_endpoint', 'introspection_endpoint',
      'device_authorization_endpoint',
    ])));
  }

  /**
   * Tests actual form saves keep automatic endpoints portable across moves.
   */
  public function testAutomaticEndpointFormPersistence(): void {
    $paths = [
      'registration_endpoint' => '/oauth/register',
      'revocation_endpoint' => '/oauth/revoke',
      'introspection_endpoint' => '/oauth/introspect',
      'device_authorization_endpoint' => '/oauth/device_authorization',
    ];
    $builder = $this->container->get('form_builder');
    foreach (['https://first.example', 'https://second.example/relocated'] as $site) {
      $request = Request::create($site . '/admin/config', 'GET', [], [], [], [
        'SCRIPT_NAME' => str_ends_with($site, '/relocated') ? '/relocated/index.php' : '/index.php',
        'SCRIPT_FILENAME' => '/index.php',
      ]);
      $stack = $this->container->get('request_stack');
      $request->setSession($stack->getCurrentRequest()->getSession());
      $stack->push($request);
      $this->container->get('router.request_context')->fromRequest($request);
      try {
        $form = $builder->getForm(ServerMetadataSettingsForm::class);
        $values = ['service_documentation' => $site . '/docs'];
        foreach ($paths as $field => $path) {
          $this->assertSame('', $form['endpoints'][$field]['#value'], $field);
          $this->assertSame($site . $path, $form['endpoints'][$field]['#placeholder'], $field);
          $this->assertStringContainsString('Leave blank', (string) $form['endpoints'][$field]['#description']);
          // Submit the values displayed by Form API, as an unchanged UI does.
          $values[$field] = $form['endpoints'][$field]['#value'];
        }
        $state = (new FormState())->setValues($values);
        $builder->submitForm(ServerMetadataSettingsForm::class, $state);
        $this->assertSame([], $state->getErrors());
        foreach ($paths as $field => $path) {
          $this->assertEmpty($this->config('simple_oauth_server_metadata.settings')->get($field), $field);
        }
        $this->assertEndpointResponses(array_map(static fn(string $path): string => $site . $path, $paths));
      }
      finally {
        $stack->pop();
      }
    }
  }

  /**
   * Tests route moves update automatic endpoint hints and response URLs.
   */
  public function testAutomaticEndpointRouteMoves(): void {
    $routes = [
      'registration_endpoint' => 'simple_oauth_client_registration.register',
      'revocation_endpoint' => 'simple_oauth_server_metadata.revoke',
      'introspection_endpoint' => 'simple_oauth_server_metadata.token_introspection',
      'device_authorization_endpoint' => 'simple_oauth_device_flow.device_authorization',
    ];
    $this->container->get('event_dispatcher')->addListener(RoutingEvents::ALTER, static function (RouteBuildEvent $event) use ($routes): void {
      foreach ($routes as $field => $route_name) {
        $event->getRouteCollection()->get($route_name)->setPath('/moved/' . $field);
      }
    });
    $this->container->get('router.builder')->rebuild();
    $endpoints = $this->container->get('simple_oauth_server_metadata.endpoint_discovery')->getCoreEndpoints();
    $form = $this->container->get('form_builder')->getForm(ServerMetadataSettingsForm::class);
    foreach ($routes as $field => $route_name) {
      $this->assertSame('/moved/' . $field, parse_url($endpoints[$field], PHP_URL_PATH));
      $this->assertSame($endpoints[$field], $form['endpoints'][$field]['#placeholder']);
      $this->assertSame('', $form['endpoints'][$field]['#value']);
    }
    $this->assertEndpointResponses(array_intersect_key($endpoints, $routes));
  }

  /**
   * Tests explicit URLs survive ordinary saves and can be explicitly cleared.
   */
  public function testEndpointOverrideFormPersistence(): void {
    $config = $this->config('simple_oauth_server_metadata.settings');
    $overrides = [
      'registration_endpoint' => 'https://old.example/oauth/register',
      'revocation_endpoint' => 'https://custom.example/Revoke?tenant=A%2Fb',
      'introspection_endpoint' => 'https://custom.example/Inspect?x=1&y=2',
      'device_authorization_endpoint' => 'https://custom.example/Device',
    ];
    foreach ($overrides as $field => $value) {
      $config->set($field, $value);
    }
    $config->save();
    $builder = $this->container->get('form_builder');
    $form = $builder->getForm(ServerMetadataSettingsForm::class);
    $values = ['service_documentation' => 'https://changed.example/docs'];
    foreach ($overrides as $field => $value) {
      $this->assertSame($value, $form['endpoints'][$field]['#value']);
      $values[$field] = $form['endpoints'][$field]['#value'];
    }
    $state = (new FormState())->setValues($values);
    $builder->submitForm(ServerMetadataSettingsForm::class, $state);
    $this->assertSame([], $state->getErrors());
    foreach ($overrides as $field => $value) {
      $this->assertSame($value, $this->config($config->getName())->get($field));
    }
    $this->assertEndpointResponses($overrides);

    // An external partial submission must retain omitted endpoint overrides.
    $state = (new FormState())->setValues(['service_documentation' => 'https://partial.example/docs']);
    $builder->submitForm(ServerMetadataSettingsForm::class, $state);
    $this->assertSame([], $state->getErrors());
    foreach ($overrides as $field => $value) {
      $this->assertSame($value, $this->config($config->getName())->get($field));
    }

    $state = (new FormState())->setValues(array_fill_keys(array_keys($overrides), ''));
    $builder->submitForm(ServerMetadataSettingsForm::class, $state);
    $this->assertSame([], $state->getErrors());
    foreach ($overrides as $field => $value) {
      $this->assertEmpty($this->config($config->getName())->get($field));
    }
    $discovered = $this->container->get('simple_oauth_server_metadata.endpoint_discovery')->getCoreEndpoints();
    $this->assertEndpointResponses(array_intersect_key($discovered, $overrides));
  }

  /**
   * Asserts optional endpoints in real server and OIDC controller responses.
   *
   * @param array<string, string> $expected
   *   The expected optional endpoint URLs.
   */
  private function assertEndpointResponses(array $expected): void {
    $responses = [
      ServerMetadataController::create($this->container)->__invoke(),
      OpenIdConfigurationController::create($this->container)->configuration(),
    ];
    foreach ($responses as $response) {
      $this->assertSame(200, $response->getStatusCode());
      $metadata = json_decode($response->getContent(), TRUE);
      foreach ($expected as $field => $value) {
        $this->assertArrayHasKey($field, $metadata);
        $this->assertSame($value, $metadata[$field], $field);
      }
      $this->assertContains('url.site', $response->getCacheableMetadata()->getCacheContexts());
    }
  }

}
