<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_client_registration\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\simple_oauth_client_registration\Controller\ClientRegistrationController;
use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;
use Drupal\simple_oauth_client_registration\Form\ClientRegistrationSettingsForm;
use Drupal\simple_oauth_client_registration\Normalizer\ClientRegistrationNormalizer;
use Drupal\simple_oauth_client_registration\Service\ClientRegistrationService;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests registration validation, response policy and atomic lifecycle writes.
 */
#[Group('simple_oauth_client_registration')]
#[RunTestsInSeparateProcesses]
final class ClientRegistrationLifecycleTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'file', 'image', 'options', 'serialization', 'consumers',
    'simple_oauth', 'simple_oauth_21', 'simple_oauth_client_registration',
    'simple_oauth_native_apps', 'simple_oauth_registration_test',
    'simple_oauth_server_metadata',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('consumer');
    $this->installEntitySchema('oauth2_token');
    $this->installSchema('simple_oauth_client_registration', ['simple_oauth_client_registration_tokens']);
    $this->installConfig(['system', 'simple_oauth', 'simple_oauth_client_registration', 'simple_oauth_native_apps']);
    Role::create(['id' => 'authenticated', 'label' => 'Authenticated'])->save();
    $generator = $this->createMock(UrlGeneratorInterface::class);
    $generator->method('generateFromRoute')->willReturn('https://example.org/oauth/register');
    $this->container->set('url_generator', $generator);
  }

  /**
   * Tests final response headers with the real core response subscribers.
   */
  public function testResponsePolicy(): void {
    $controller = new ClientRegistrationController($this->registration(), $this->container->get('serializer'));
    $request = Request::create('/oauth/register', 'POST', content: '{"redirect_uris":["https://example.org/callback"]}');
    $request->attributes->set('_route', 'simple_oauth_client_registration.register');
    $response = $controller->register($request);
    $this->assertInstanceOf(CacheableJsonResponse::class, $response);
    $this->assertSame(201, $response->getStatusCode(), $response->getContent());
    $this->assertArrayHasKey('registration_access_token', json_decode($response->getContent(), TRUE));
    foreach (['simple_oauth_client_registration.register', 'simple_oauth_client_registration.manage'] as $route) {
      foreach ([200, 201, 400, 500] as $status) {
        $request->attributes->set('_route', $route);
        $response->setStatusCode($status);
        $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::RESPONSE);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
      }
    }
    $request->attributes->set('_route', 'unrelated.route');
    $response = new CacheableJsonResponse([]);
    $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::RESPONSE);
    $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
  }

  /**
   * Tests the form's actual config target preserves subset and empty lists.
   */
  public function testGrantDefaultPersistence(): void {
    $form = ClientRegistrationSettingsForm::create($this->container);
    $state = new FormState();
    $built = $form->buildForm([], $state);
    $target = $built['grant_defaults']['default_grant_types']['#config_target'];
    if (is_string($target)) {
      $target = ConfigTarget::fromString($target);
    }
    $config = $this->config('simple_oauth_client_registration.settings');
    foreach ([
      [['authorization_code' => 'authorization_code', 'refresh_token' => 0], ['authorization_code']],
      [['authorization_code' => 0, 'refresh_token' => 0], []],
    ] as [$input, $expected]) {
      $target->setValue($config, $input, $state);
      $config->save();
      $this->container->get('config.factory')->reset($config->getName());
      $this->assertSame($expected, $this->container->get('config.factory')->get($config->getName())->get('default_grant_types'));
    }
  }

  /**
   * Tests missing, empty and direct-constructor contacts retain their meaning.
   */
  public function testContactsAndProjectionCompatibility(): void {
    $service = $this->registration();
    $data = $service->registerClient(ClientRegistration::fromArray([
      'redirect_uris' => ['https://example.org/callback'],
      'contacts' => ['owner@example.org'],
      'logo_uri' => 'https://example.org/logo.png',
    ]));
    $client_id = $data['client_id'];
    $this->assertSame(['owner@example.org'], $service->updateClientMetadata($client_id, ClientRegistration::fromArray([]))['contacts']);
    $this->assertSame(['owner@example.org'], $service->updateClientMetadata($client_id, new ClientRegistration())['contacts']);
    $this->assertArrayNotHasKey('contacts', $service->updateClientMetadata($client_id, ClientRegistration::fromArray(['contacts' => []])));
    $service->updateClientMetadata($client_id, new ClientRegistration(contacts: ['new@example.org']));
    $consumer = $this->consumer($client_id);
    $normalizer = $this->container->get('serializer');
    $normalized = $normalizer->normalize($consumer, 'json');
    $metadata = $service->getClientMetadata($client_id);
    $this->assertArrayHasKey('scope', $metadata);
    $this->assertArrayNotHasKey('scope', $normalized);
    unset($metadata['scope']);
    $this->assertSame($metadata, $normalized);
    $legacy_normalizer = new ClientRegistrationNormalizer(
      $this->container->get('simple_oauth_client_registration.service.token'),
      $this->container->get('file_url_generator'),
    );
    $this->assertSame($normalized, $legacy_normalizer->normalize($consumer, 'json'));
    $this->assertSame('https://example.org/logo.png', $normalized['logo_uri']);
    $this->container->get('simple_oauth_client_registration.service.token')->revokeRegistrationAccessToken($client_id);
    $context = $normalizer->normalize($consumer, 'json', [
      'include_registration_response' => TRUE,
      'client_secret' => 'response-only',
    ]);
    $this->assertSame('response-only', $context['client_secret']);
    $this->assertSame(0, $context['client_secret_expires_at']);
    $this->assertTrue($service->validateRegistrationToken($client_id, $context['registration_access_token']));
  }

  /**
   * Tests private URI syntax without suppressing the parent field constraint.
   */
  public function testPrivateUriLimitation(): void {
    $service = $this->registration();
    $normalizer = $this->container->get('serializer');
    $private = $normalizer->denormalize([
      'redirect_uris' => ['com.example.app:/oauth2redirect/example-provider'],
      'application_type' => 'native',
    ], ClientRegistration::class);
    // DTO syntax permits RFC 8252; the installed parent still rejects it.
    $private->validateRedirectUris();
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Invalid client metadata');
    $service->registerClient($private);
  }

  /**
   * Tests Drupal validates completed fields before creation and partial update.
   */
  public function testEntityValidation(): void {
    $service = $this->registration();
    foreach ([
      new ClientRegistration(softwareId: str_repeat('a', 256)),
      new ClientRegistration(softwareVersion: str_repeat('b', 256)),
      new ClientRegistration(contacts: ['not an email']),
      new ClientRegistration(redirectUris: ['https://example.org/' . str_repeat('a', 256)]),
    ] as $input) {
      try {
        $service->registerClient($input);
        $this->fail('Invalid completed consumer must be rejected.');
      }
      catch (BadRequestHttpException $e) {
        $this->assertSame('Invalid client metadata', $e->getMessage());
      }
    }
    $this->assertCount(0, $this->container->get('entity_type.manager')->getStorage('consumer')->loadMultiple());
    $data = $service->registerClient(new ClientRegistration(redirectUris: ['https://example.org/callback'], softwareId: 'original'));
    $cached = $this->consumer($data['client_id']);
    try {
      $service->updateClientMetadata($data['client_id'], new ClientRegistration(softwareId: str_repeat('x', 256)));
      $this->fail('Invalid partial update must be rejected.');
    }
    catch (BadRequestHttpException) {
      $this->assertSame('original', $cached->get('software_id')->value);
      $this->assertSame('original', $service->getClientMetadata($data['client_id'])['software_id']);
    }
  }

  /**
   * Tests registration rollback after response preparation fails.
   */
  public function testRegistrationRollback(): void {
    $service = $this->registration();
    $generator = $this->createMock(UrlGeneratorInterface::class);
    $generator->method('generateFromRoute')->willThrowException(new \RuntimeException('Response preparation failure'));
    $this->container->set('url_generator', $generator);
    try {
      $service->registerClient(new ClientRegistration(redirectUris: ['https://example.org/callback']));
      $this->fail('Response preparation must fail.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Response preparation failure', $e->getMessage());
    }
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $this->assertCount(0, $storage->loadMultiple());
    $database = $this->container->get('database');
    $this->assertSame(0, (int) $database->select('simple_oauth_client_registration_tokens')->countQuery()->execute()->fetchField());
  }

  /**
   * Tests a token storage failure cannot leave an orphaned consumer.
   */
  public function testTokenFailureRollback(): void {
    $service = $this->registration();
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $this->container->get('database')->schema()->dropTable('simple_oauth_client_registration_tokens');
    $failed = FALSE;
    try {
      $service->registerClient(new ClientRegistration(redirectUris: ['https://example.org/callback']));
    }
    catch (\Exception) {
      $failed = TRUE;
    }
    $this->assertTrue($failed, 'Token persistence must fail.');
    $this->assertCount(0, $storage->loadMultiple());
  }

  /**
   * Tests failed deletion preserves credentials and native policy per item.
   */
  public function testDeletionRollbackAndBulkOutcomes(): void {
    $service = $this->registration();
    $first = $service->registerClient(new ClientRegistration(redirectUris: ['https://example.org/first']));
    $second = $service->registerClient(new ClientRegistration(redirectUris: ['https://example.org/second']));
    $consumer = $this->consumer($first['client_id']);
    $name = 'simple_oauth_native_apps.consumer.' . $consumer->id();
    $this->config($name)->set('allow_custom_schemes_override', 'native')->save();
    $this->container->get('state')->set('simple_oauth_registration_test.fail_delete_id', $consumer->id());
    $account = new UserSession(['uid' => 1]);
    $outcomes = $service->manageClients([
      ['client_id' => $first['client_id'], 'operation' => 'delete'],
      ['client_id' => $second['client_id'], 'operation' => 'delete'],
    ], $account, $this->container->get('serializer'));
    $this->assertSame([['status' => 'server_error'], ['status' => 'success']], $outcomes);
    $this->assertTrue($service->validateRegistrationToken($first['client_id'], $first['registration_access_token']));
    $this->assertSame($consumer->id(), $this->consumer($first['client_id'])->id());
    $this->assertSame('native', $this->container->get('config.factory')->get($name)->get('allow_custom_schemes_override'));
    $this->assertSame('native', $this->container->get('config.storage')->read($name)['allow_custom_schemes_override']);
    $this->assertFalse($service->validateRegistrationToken($second['client_id'], $second['registration_access_token']));
  }

  /**
   * Tests installed grant capabilities extend the accepted metadata values.
   */
  public function testInstalledGrant(): void {
    $normalizer = $this->container->get('serializer');
    $dto = $normalizer->denormalize([
      'redirect_uris' => ['com.example.app://oauth/callback'],
      'grant_types' => ['native_app_authorization_code'],
      'token_endpoint_auth_method' => 'none',
    ], ClientRegistration::class);
    $service = $this->registration();
    $data = $service->registerClient($dto);
    $this->assertSame(['native_app_authorization_code'], $data['grant_types']);
  }

  /**
   * Tests stored native policy is applied to completed consumer values.
   */
  public function testNativePolicy(): void {
    $service = $this->registration();
    $data = $service->registerClient(new ClientRegistration(
      redirectUris: ['com.example.app://oauth/callback'],
      tokenEndpointAuthMethod: 'none',
    ));
    $consumer = $this->consumer($data['client_id']);
    $this->config('simple_oauth_native_apps.consumer.' . $consumer->id())->set('allow_custom_schemes_override', 'web')->save();
    try {
      $service->updateClientMetadata($data['client_id'], new ClientRegistration(redirectUris: ['com.example.app://oauth/changed']));
      $this->fail('Stored consumer policy must reject custom redirects.');
    }
    catch (BadRequestHttpException) {
      $this->assertSame(['com.example.app://oauth/callback'], $service->getClientMetadata($data['client_id'])['redirect_uris']);
    }
    $web = $service->registerClient(new ClientRegistration(redirectUris: ['https://example.org/web'], applicationType: 'web'));
    $this->assertSame(['https://example.org/web'], $web['redirect_uris']);
  }

  /**
   * Tests failed updates preserve storage, cache and safe per-item errors.
   */
  public function testUpdateRollbackAndMetadataErrors(): void {
    $service = $this->registration();
    $data = $service->registerClient(new ClientRegistration(clientName: 'Original', redirectUris: ['https://example.org/callback']));
    $cached = $this->consumer($data['client_id']);
    $state = $this->container->get('state');
    $state->set('simple_oauth_registration_test.fail_update', TRUE);
    $failed = FALSE;
    try {
      $service->updateClientMetadata($data['client_id'], new ClientRegistration(clientName: 'Changed'));
    }
    catch (EntityStorageException) {
      $failed = TRUE;
    }
    $state->delete('simple_oauth_registration_test.fail_update');
    $this->assertTrue($failed);
    $this->assertSame('Original', $cached->label());
    $this->assertSame('Original', $service->getClientMetadata($data['client_id'])['client_name']);
    $controller = new ClientRegistrationController($service, $this->container->get('serializer'));
    $request = Request::create('/oauth/register/' . $data['client_id'], 'PUT', content: json_encode(['software_id' => str_repeat('x', 256)]));
    $request->headers->set('Authorization', 'Bearer ' . $data['registration_access_token']);
    $response = $controller->manage($request, $data['client_id']);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame([
      'error' => 'invalid_client_metadata',
      'error_description' => 'Invalid client metadata',
    ], json_decode($response->getContent(), TRUE));
    $results = $service->manageClients([
      [
        'client_id' => $data['client_id'],
        'operation' => 'update',
        'metadata' => ['software_id' => str_repeat('x', 256)],
      ],
      [
        'client_id' => $data['client_id'],
        'operation' => 'update',
        'metadata' => ['client_name' => 'Updated'],
      ],
    ], new UserSession(['uid' => 1]), $this->container->get('serializer'));
    $this->assertSame([['status' => 'invalid_client_metadata'], ['status' => 'success']], $results);
  }

  /**
   * Tests grant scope filtering and the administrative client allowlist.
   */
  public function testScopeRestrictionsAndLegacyFields(): void {
    foreach (['read' => ['authorization_code'], 'machine' => ['client_credentials']] as $name => $grants) {
      Oauth2Scope::create([
        'name' => 'test:' . $name,
        'description' => 'Registration scope fixture',
        'grant_types' => array_fill_keys($grants, ['status' => TRUE]),
        'umbrella' => FALSE,
        'granularity_id' => Oauth2ScopeInterface::GRANULARITY_PERMISSION,
        'granularity_configuration' => ['permission' => 'access content'],
      ])->save();
    }
    $service = $this->registration();
    foreach ([FALSE, TRUE] as $legacy) {
      if ($legacy) {
        $this->container->get('state')->set('simple_oauth_registration_test.legacy_scope_field', TRUE);
        $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
        $this->container->get('entity_type.manager')->clearCachedDefinitions();
      }
      $data = $service->registerClient(new ClientRegistration(
        redirectUris: ['https://example.org/callback'],
        grantTypes: ['authorization_code', 'client_credentials'],
        scope: 'test:read test:machine unknown:scope',
      ));
      $consumer = $this->consumer($data['client_id']);
      $this->assertSame(!$legacy, $consumer->hasField('authorization_code_scopes'));
      $expected = $legacy ? ['test:machine'] : ['test:read', 'test:machine'];
      $this->assertEqualsCanonicalizing($expected, explode(' ', $data['scope']));
      $allowlist = $consumer->get('scopes')->getValue();
      $this->assertCount(1, $allowlist);
      foreach (['test:read', '', 'unknown:scope'] as $scope) {
        $updated = $service->updateClientMetadata($data['client_id'], new ClientRegistration(scope: $scope));
        $this->assertSame($allowlist, $this->consumer($data['client_id'])->get('scopes')->getValue());
        $expected = !$legacy && $scope === 'test:read' ? ['test:read', 'test:machine'] : ['test:machine'];
        $this->assertEqualsCanonicalizing($expected, explode(' ', $updated['scope']));
      }
    }
  }

  /**
   * Tests uninstall only clears the module's exact endpoint when resolvable.
   */
  public function testUninstallOwnership(): void {
    $this->container->get('module_handler')->loadInclude('simple_oauth_client_registration', 'install');
    $name = 'simple_oauth_server_metadata.settings';
    $this->assertTrue($this->config($name)->isNew());
    simple_oauth_client_registration_uninstall(FALSE);
    $this->assertTrue($this->config($name)->isNew());
    foreach (['https://external.example.org/oauth/register', 'https://example.org/oauth/register/extra'] as $endpoint) {
      $this->config($name)->set('registration_endpoint', $endpoint)->save();
      simple_oauth_client_registration_uninstall(FALSE);
      $this->assertSame($endpoint, $this->config($name)->get('registration_endpoint'));
    }
    $this->config($name)->set('registration_endpoint', 'https://example.org/oauth/register')->save();
    simple_oauth_client_registration_uninstall(TRUE);
    $this->assertSame('https://example.org/oauth/register', $this->config($name)->get('registration_endpoint'));
    simple_oauth_client_registration_uninstall(FALSE);
    $this->assertSame('', $this->config($name)->get('registration_endpoint'));
  }

  /**
   * Gets the registration service.
   *
   * @return \Drupal\simple_oauth_client_registration\Service\ClientRegistrationService
   *   The registration service.
   */
  private function registration(): ClientRegistrationService {
    return $this->container->get('simple_oauth_client_registration.service.registration');
  }

  /**
   * Loads a registered consumer.
   *
   * @param string $client_id
   *   The registered client identifier.
   *
   * @return \Drupal\consumers\Entity\Consumer
   *   The stored consumer.
   */
  private function consumer(string $client_id): Consumer {
    $consumers = $this->container->get('entity_type.manager')->getStorage('consumer')->loadByProperties(['client_id' => $client_id]);
    return reset($consumers);
  }

}
