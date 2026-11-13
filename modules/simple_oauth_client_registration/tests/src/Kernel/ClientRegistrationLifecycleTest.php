<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_client_registration\Kernel;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\RouteCollection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Psr\Log\LoggerInterface;
use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth_registration_test\RegistrationOperationsDecorator;
use Drupal\simple_oauth_client_registration\Service\RegistrationTokenService;
use Drupal\simple_oauth_client_registration\Service\ClientRegistrationOperationsInterface;
use Drupal\simple_oauth_client_registration\Service\ClientRegistrationAdministrationInterface;
use Symfony\Component\DependencyInjection\Reference;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Routing\UrlGenerator;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\simple_oauth_client_registration\Controller\ClientRegistrationController;
use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;
use Drupal\simple_oauth_client_registration\Form\ClientRegistrationSettingsForm;
use Drupal\simple_oauth_client_registration\Normalizer\ClientRegistrationNormalizer;
use Drupal\simple_oauth_client_registration\Service\ClientRegistrationService;
use Drupal\simple_oauth_client_registration\Service\TokenEndpointAuthMethodResolverInterface;
use GuzzleHttp\Psr7\ServerRequest;
use League\OAuth2\Server\Exception\OAuthServerException;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    if ($this->name() === 'testDecoratedRegistrationOperations') {
      $container->register('registration_test.decorator', RegistrationOperationsDecorator::class)
        ->setDecoratedService('simple_oauth_client_registration.service.registration')
        ->addArgument(new Reference('registration_test.decorator.inner'));
    }
  }

  /**
   * Tests direct entity deletion, identifier reuse and metadata-only updates.
   */
  public function testConsumerCredentialOwnership(): void {
    $controller = ClientRegistrationController::create($this->container);
    $response = $controller->register(Request::create('/oauth/register', 'POST', content: '{"redirect_uris":["https://example.org/callback"]}'));
    $this->assertSame(201, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $id = $data['client_id'];
    $token = $data['registration_access_token'];
    $consumer = $this->consumer($id);
    $consumer->set('label', 'Administrative edit')->save();
    $this->assertSame('Administrative edit', json_decode($this->managementRequest($controller, $id, $token)->getContent(), TRUE)['client_name']);
    $this->assertSame(1, $this->tokenCount());
    $consumer->delete();
    $this->assertSame(0, $this->tokenCount());
    $this->assertSame(400, $this->managementRequest($controller, $id, $token)->getStatusCode());
    $this->directConsumer($id);
    $this->assertSame(400, $this->managementRequest($controller, $id, $token)->getStatusCode());
    $this->assertSame(0, $this->tokenCount());
  }

  /**
   * Tests insertion clears preexisting orphan credentials without issuing any.
   */
  public function testOrphanIdentifierInsertion(): void {
    $controller = ClientRegistrationController::create($this->container);
    // A new entity also clears tokens orphaned before these safeguards existed.
    $tokens = $this->container->get('simple_oauth_client_registration.service.token');
    $orphan = $tokens->generateRegistrationAccessToken('orphan-id');
    $this->directConsumer('orphan-id');
    $this->assertSame(400, $this->managementRequest($controller, 'orphan-id', $orphan)->getStatusCode());
    $this->directConsumer('no-token')->set('client_id', 'no-token-changed')->save();
    $this->consumer('no-token-changed')->delete();
    $this->assertSame(0, $this->tokenCount());
  }

  /**
   * Tests deletion uses stored ownership when the entity has unsaved ID edits.
   */
  public function testUnsavedIdentityDeletion(): void {
    $data = $this->registration()->registerClient(new ClientRegistration(redirectUris: ['https://example.org/callback']));
    $tokens = $this->container->get('simple_oauth_client_registration.service.token');
    $unrelated = $tokens->generateRegistrationAccessToken('unsaved-id');
    $consumer = $this->consumer($data['client_id']);
    $consumer->set('client_id', 'unsaved-id')->delete();
    $this->assertSame(1, $this->tokenCount());
    $this->assertTrue($tokens->validateRegistrationAccessToken('unsaved-id', $unrelated));
    $controller = ClientRegistrationController::create($this->container);
    $this->assertSame(400, $this->managementRequest($controller, $data['client_id'], $data['registration_access_token'])->getStatusCode());
  }

  /**
   * Tests identifier changes revoke both previous and destination credentials.
   */
  public function testConsumerIdentityChange(): void {
    $service = $this->registration();
    $data = $service->registerClient(new ClientRegistration(redirectUris: ['https://example.org/callback']));
    $old = $data['client_id'];
    $tokens = $this->container->get('simple_oauth_client_registration.service.token');
    $orphan = $tokens->generateRegistrationAccessToken('new-id');
    $consumer = $this->consumer($old);
    $consumer->set('client_id', 'new-id')->save();
    $this->assertSame(0, $this->tokenCount());
    $controller = ClientRegistrationController::create($this->container);
    $invalid_credentials = [
      [$old, $data['registration_access_token']],
      ['new-id', $orphan],
      ['new-id', $data['registration_access_token']],
    ];
    foreach ($invalid_credentials as [$id, $token]) {
      $this->assertSame(400, $this->managementRequest($controller, $id, $token)->getStatusCode());
    }
    $this->directConsumer($old);
    $this->assertSame(400, $this->managementRequest($controller, $old, $data['registration_access_token'])->getStatusCode());
    $this->assertSame($consumer->id(), $this->consumer('new-id')->id());
  }

  /**
   * Tests failed direct lifecycle writes restore consumer credentials.
   */
  public function testConsumerLifecycleRollback(): void {
    $service = $this->registration();
    $data = $service->registerClient(new ClientRegistration(redirectUris: ['https://example.org/callback']));
    $tokens = $this->container->get('simple_oauth_client_registration.service.token');
    $orphan = $tokens->generateRegistrationAccessToken('rollback-id');
    $state = $this->container->get('state');
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    foreach (['insert', 'update', 'delete'] as $operation) {
      $consumer = $this->consumer($data['client_id']);
      $key = 'simple_oauth_registration_test.fail_' . ($operation === 'delete' ? 'delete_id' : $operation);
      $state->set($key, $operation === 'delete' ? $consumer->id() : TRUE);
      try {
        match ($operation) {
          'insert' => $this->directConsumer('rollback-id'),
          'update' => $consumer->set('client_id', 'rollback-id')->save(),
          'delete' => $consumer->delete(),
        };
        $this->fail('The lifecycle write must fail.');
      }
      catch (EntityStorageException) {
        $state->delete($key);
        $storage->resetCache();
        $this->assertSame($data['client_id'], $this->consumer($data['client_id'])->getClientId());
        $this->assertSame([], $storage->loadByProperties(['client_id' => 'rollback-id']));
        $this->assertTrue($tokens->validateRegistrationAccessToken('rollback-id', $orphan));
        $controller = ClientRegistrationController::create($this->container);
        $this->assertSame(200, $this->managementRequest($controller, $data['client_id'], $data['registration_access_token'])->getStatusCode());
        $this->assertSame(2, $this->tokenCount());
      }
    }
  }

  /**
   * Tests absent token storage and rollback after real revocation failures.
   */
  public function testLifecycleStorageBoundaries(): void {
    $consumer = $this->directConsumer('unregistered');
    $database = $this->container->get('database');
    $table = 'simple_oauth_client_registration_tokens';
    $tokens = $this->container->get('simple_oauth_client_registration.service.token');
    $token = $tokens->generateRegistrationAccessToken('unregistered');
    $broken = $this->createMock(Connection::class);
    $broken->method('schema')->willReturn($database->schema());
    $broken->method('delete')->willThrowException(new \RuntimeException('Credential storage failure'));
    $this->container->set('simple_oauth_client_registration.service.token', new RegistrationTokenService($broken, $this->container->get('logger.channel.simple_oauth_client_registration')));
    try {
      $consumer->delete();
      $this->fail('A real credential deletion failure must abort entity deletion.');
    }
    catch (EntityStorageException) {
      $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
      $storage->resetCache();
      $this->assertNotNull($storage->load($consumer->id()));
      $this->assertTrue($tokens->validateRegistrationAccessToken('unregistered', $token));
    }
    $this->container->set('simple_oauth_client_registration.service.token', $tokens);
    $database->schema()->dropTable($table);
    $consumer->delete();
    $consumer = $this->directConsumer('without-table');
    $consumer->set('client_id', 'still-without-table')->save();
    $consumer->delete();
    simple_oauth_client_registration_cron();
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('consumer')->loadMultiple());
  }

  /**
   * Tests actual cron bounds cleanup and retains active and boundary tokens.
   */
  public function testBoundedTokenCron(): void {
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentTime')->willReturn(2000000000);
    $this->container->set('datetime.time', $clock);
    $tokens = $this->container->get('simple_oauth_client_registration.service.token');
    $database = $this->container->get('database');
    $table = 'simple_oauth_client_registration_tokens';
    $insert = $database->insert($table)->fields(['client_id', 'token_hash', 'created', 'expires']);
    for ($i = 0; $i < 205; $i++) {
      $insert->values(['expired-' . $i, 'stable-hash-' . $i, 1, 1999999999]);
    }
    $insert->execute();
    $active = $tokens->generateRegistrationAccessToken('active');
    $boundary = $tokens->generateRegistrationAccessToken('boundary');
    $database->update($table)->fields(['expires' => 2000000000])->condition('client_id', 'boundary')->execute();
    $before = $database->select($table, 't')->fields('t')->condition('client_id', ['active', 'boundary'], 'IN')->execute()->fetchAllAssoc('client_id');
    $this->assertSame(2000000000, (int) $before['active']->created);
    $this->assertSame(2031536000, (int) $before['active']->expires);
    $this->assertTrue($tokens->validateRegistrationAccessToken('boundary', $boundary));
    foreach ([107, 7, 2, 2] as $remaining) {
      $this->assertTrue($this->container->get('cron')->run());
      $this->assertSame($remaining, $this->tokenCount());
    }
    $after = $database->select($table, 't')->fields('t')->condition('client_id', ['active', 'boundary'], 'IN')->execute()->fetchAllAssoc('client_id');
    $this->assertEquals($before, $after);
    $this->assertTrue($tokens->validateRegistrationAccessToken('active', $active));
    $this->assertTrue($tokens->validateRegistrationAccessToken('boundary', $boundary));
    // The manual no-argument call still drains more than one cron batch.
    for ($i = 0; $i < 101; $i++) {
      $insert->values(['manual-' . $i, 'stable-hash-' . $i, 1, 1999999999]);
    }
    $insert->execute();
    $database->update($table)->fields(['expires' => 1999999999])->execute();
    $tokens->cleanupExpiredTokens();
    $this->assertSame(0, $this->tokenCount());
    $legacy = new RegistrationTokenService($database, $this->container->get('logger.channel.simple_oauth_client_registration'));
    $legacy->cleanupExpiredTokens();
    $tokens->generateRegistrationAccessToken('survives-failure');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('log')->with(3, 'Registration token cleanup failed: @exception_type', $this->callback(static fn(array $context): bool => isset($context['@exception_type']) && !isset($context['exception']) && !isset($context['@message'])));
    $this->container->get('logger.factory')->get('simple_oauth_client_registration')->addLogger($logger);
    $database->schema()->dropField($table, 'expires');
    $this->assertTrue($this->container->get('cron')->run());
    $this->assertSame(1, $this->tokenCount());
  }

  /**
   * Tests cleanup cannot delete a renewed or replaced candidate snapshot.
   */
  public function testCleanupCandidateReplacement(): void {
    $database = $this->container->get('database');
    $table = 'simple_oauth_client_registration_tokens';
    $tokens = $this->container->get('simple_oauth_client_registration.service.token');
    foreach (['renewed', 'replaced', 'expired'] as $id) {
      $tokens->generateRegistrationAccessToken($id);
    }
    $database->update($table)->fields(['expires' => 1])->execute();
    // Capture actual candidates, then change storage before deletion starts.
    $candidates = $database->select($table, 't')->fields('t', ['id', 'token_hash', 'expires'])->execute()->fetchAll();
    $database->update($table)->fields(['expires' => time() + 31536000])->condition('client_id', 'renewed')->execute();
    $database->update($table)->fields(['token_hash' => 'replacement-hash'])->condition('client_id', 'replaced')->execute();
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn($candidates);
    $query = $this->createMock(SelectInterface::class);
    foreach (['fields', 'condition', 'orderBy', 'range'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('execute')->willReturn($statement);
    $connection = $this->createMock(Connection::class);
    $connection->method('schema')->willReturn($database->schema());
    $connection->method('select')->willReturn($query);
    $connection->method('delete')->willReturnCallback($database->delete(...));
    $service = new RegistrationTokenService($connection, $this->container->get('logger.channel.simple_oauth_client_registration'));
    $service->cleanupExpiredTokens(100);
    $this->assertSame(2, $this->tokenCount());
    $this->assertTrue($tokens->hasRegistrationAccessToken('renewed'));
    $this->assertTrue($tokens->hasRegistrationAccessToken('replaced'));
    $this->assertFalse($tokens->hasRegistrationAccessToken('expired'));
  }

  /**
   * Tests a compiled composition decorator through every consuming factory.
   */
  public function testDecoratedRegistrationOperations(): void {
    $controller_parameter = (new \ReflectionMethod(ClientRegistrationController::class, '__construct'))->getParameters()[0];
    $form_parameter = (new \ReflectionMethod(ClientRegistrationSettingsForm::class, '__construct'))->getParameters()[3];
    $this->assertSame(ClientRegistrationOperationsInterface::class, $controller_parameter->getType()->getName());
    $this->assertSame(ClientRegistrationAdministrationInterface::class, $form_parameter->getType()->getName());
    $decorator = $this->container->get('simple_oauth_client_registration.service.registration');
    $this->assertInstanceOf(RegistrationOperationsDecorator::class, $decorator);
    $controller = $this->container->get('simple_oauth_client_registration.controller.registration');
    $response = $controller->register(Request::create('/oauth/register', 'POST', content: '{"redirect_uris":["https://example.org/callback"]}'));
    $this->assertSame(201, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $controller = ClientRegistrationController::create($this->container);
    $id = $data['client_id'];
    $token = $data['registration_access_token'];
    $this->assertSame(400, $this->managementRequest($controller, $id, 'wrong-token')->getStatusCode());
    $read = $this->managementRequest($controller, $id, $token);
    $this->assertSame(200, $read->getStatusCode());
    $this->assertSame('*', $read->headers->get('Access-Control-Allow-Origin'));
    $this->assertTrue($read->headers->hasCacheControlDirective('no-store'));
    $this->assertSame(0, $read->getCacheableMetadata()->getCacheMaxAge());
    $this->assertArrayNotHasKey('client_secret', json_decode($read->getContent(), TRUE));
    $this->assertSame(200, $this->managementRequest($controller, $id, $token, 'POST')->getStatusCode());
    $this->assertSame(200, $this->managementRequest($controller, $id, $token, 'PUT', '{"client_name":"Decorated edit"}')->getStatusCode());
    $state = $this->container->get('state');
    $state->set('simple_oauth_registration_test.fail_update', TRUE);
    $this->assertSame(500, $this->managementRequest($controller, $id, $token, 'PUT', '{"client_name":"Must roll back"}')->getStatusCode());
    $state->delete('simple_oauth_registration_test.fail_update');
    $this->assertSame('Decorated edit', json_decode($this->managementRequest($controller, $id, $token)->getContent(), TRUE)['client_name']);
    $other = $controller->register(Request::create('/oauth/register', 'POST', content: '{"redirect_uris":["https://example.org/other"]}'));
    $this->assertSame(201, $other->getStatusCode(), $other->getContent());
    $other_data = json_decode($other->getContent(), TRUE);
    $this->assertSame(400, $this->managementRequest($controller, $other_data['client_id'], $token)->getStatusCode());
    $form = ClientRegistrationSettingsForm::create($this->container);
    $form_state = new FormState();
    $items = [['client_id' => $id, 'operation' => 'update', 'metadata' => ['client_name' => 'Bulk edit']]];
    $form_state->set('bulk_items', $items);
    $build = [];
    // The decorator preserves the original administrative permission check.
    try {
      $form->submitBulk($build, $form_state);
      $this->fail('An anonymous account must not manage clients.');
    }
    catch (AccessDeniedHttpException) {
      $this->container->get('current_user')->setAccount(new UserSession(['uid' => 1]));
    }
    $form->submitBulk($build, $form_state);
    $this->assertSame('success', $form_state->get('bulk_results')[0][1]['data']['#plain_text']);
    $state->set('simple_oauth_registration_test.fail_delete_id', $this->consumer($id)->id());
    $this->assertSame(500, $this->managementRequest($controller, $id, $token, 'DELETE')->getStatusCode());
    $state->delete('simple_oauth_registration_test.fail_delete_id');
    $this->assertSame(200, $this->managementRequest($controller, $id, $token)->getStatusCode());
    $this->assertSame(204, $this->managementRequest($controller, $id, $token, 'DELETE')->getStatusCode());
    $this->assertSame(400, $this->managementRequest($controller, $id, $token)->getStatusCode());
    $operations = [
      'registerClient', 'getClientMetadata', 'validateRegistrationToken',
      'rotateClientSecret', 'updateClientMetadata', 'deleteClient', 'manageClients',
    ];
    foreach ($operations as $operation) {
      $this->assertContains($operation, $decorator->calls);
    }
  }

  /**
   * Sends an authenticated management request through the real controller.
   *
   * @param \Drupal\simple_oauth_client_registration\Controller\ClientRegistrationController $controller
   *   The controller under test.
   * @param string $id
   *   The public client identifier.
   * @param string $token
   *   The management credential.
   * @param string $method
   *   The HTTP method.
   * @param string $content
   *   The request body.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The management response.
   */
  private function managementRequest(ClientRegistrationController $controller, string $id, string $token, string $method = 'GET', string $content = ''): JsonResponse {
    $request = Request::create('/oauth/register/' . $id, $method, content: $content);
    $request->headers->set('Authorization', 'Bearer ' . $token);
    $request->attributes->set('_route', 'simple_oauth_client_registration.manage');
    $response = $controller->manage($request, $id);
    $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::RESPONSE);
    return $response;
  }

  /**
   * Creates an administrative consumer with an explicit public identifier.
   *
   * @param string $id
   *   The public identifier.
   *
   * @return \Drupal\consumers\Entity\Consumer
   *   The saved consumer.
   */
  private function directConsumer(string $id): Consumer {
    $consumer = Consumer::create(['client_id' => $id, 'label' => 'Administrative client']);
    $consumer->save();
    return $consumer;
  }

  /**
   * Counts persisted registration credentials.
   *
   * @return int
   *   The number of credential records.
   */
  private function tokenCount(): int {
    return (int) $this->container->get('database')->select('simple_oauth_client_registration_tokens')->countQuery()->execute()->fetchField();
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
   * Tests prepared projection repeats without token writes or scope changes.
   */
  public function testPreparedRegistrationProjection(): void {
    $service = $this->registration();
    $response = $service->registerClient(new ClientRegistration(redirectUris: ['https://example.org/callback']));
    $consumer = $this->consumer($response['client_id']);
    $serializer = $this->container->get('serializer');
    $prepared = ClientRegistration::fromArray($response);
    $database = $this->container->get('database');
    $rows = $database->select('simple_oauth_client_registration_tokens')->fields('simple_oauth_client_registration_tokens')->execute()->fetchAll();
    $context = [
      'include_registration_response' => TRUE,
      'prepared_registration_response' => $prepared,
    ];
    $expected = $response;
    // The serializer preserves its established omission of registered scopes.
    unset($expected['scope']);
    for ($i = 0; $i < 3; $i++) {
      $this->assertEquals($expected, $serializer->normalize($consumer, 'json', $context));
      $this->assertEquals($expected, $serializer->normalize($consumer, 'json', ['prepared_registration_response' => $prepared]));
      $this->assertArrayNotHasKey('client_secret', $serializer->normalize($consumer, 'json'));
      $this->assertEquals($rows, $database->select('simple_oauth_client_registration_tokens')->fields('simple_oauth_client_registration_tokens')->execute()->fetchAll());
      $this->assertTrue($service->validateRegistrationToken($response['client_id'], $response['registration_access_token']));
    }
    // DTO projection itself remains an immutable response snapshot.
    $this->assertEquals($response, $serializer->normalize($prepared, 'json'));
  }

  /**
   * Tests incompatible prepared credentials fail without altering token rows.
   */
  public function testInvalidPreparedRegistration(): void {
    $service = $this->registration();
    $response = $service->registerClient(new ClientRegistration(redirectUris: ['https://example.org/callback']));
    $consumer = $this->consumer($response['client_id']);
    foreach ([
      ClientRegistration::fromArray(array_replace($response, ['client_id' => 'other-client'])),
      new ClientRegistration(clientId: $response['client_id']),
      $response,
      NULL,
    ] as $prepared) {
      try {
        $this->container->get('serializer')->normalize($consumer, 'json', [
          'include_registration_response' => TRUE,
          'prepared_registration_response' => $prepared,
        ]);
        $this->fail('Invalid prepared context must be rejected.');
      }
      catch (\InvalidArgumentException) {
        $this->assertTrue($service->validateRegistrationToken($response['client_id'], $response['registration_access_token']));
      }
    }
  }

  /**
   * Tests legacy first issuance and safe handling of all existing token rows.
   */
  public function testLegacyRegistrationIssuance(): void {
    $service = $this->registration();
    $consumer = $service->createConsumer(new ClientRegistration(redirectUris: ['https://example.org/callback']));
    $normalizer = new ClientRegistrationNormalizer(
      $this->container->get('simple_oauth_client_registration.service.token'),
      $this->container->get('file_url_generator'),
    );
    $database = $this->container->get('database');
    $normalizer->normalize($consumer);
    $this->assertSame(0, (int) $database->select('simple_oauth_client_registration_tokens')->countQuery()->execute()->fetchField());
    $response = $normalizer->normalize($consumer, 'json', [
      'include_registration_response' => TRUE,
      'client_secret' => 'newly-issued-secret',
    ]);
    $this->assertSame('newly-issued-secret', $response['client_secret']);
    $this->assertTrue($service->validateRegistrationToken($consumer->getClientId(), $response['registration_access_token']));
    foreach ([time() + 12345, time() - 12345] as $expiry) {
      $database->update('simple_oauth_client_registration_tokens')->fields(['expires' => $expiry])->execute();
      $before = $database->select('simple_oauth_client_registration_tokens')->fields('simple_oauth_client_registration_tokens')->execute()->fetchAll();
      try {
        $normalizer->normalize($consumer, 'json', ['include_registration_response' => TRUE]);
        $this->fail('Legacy normalization must not recover or replace a token.');
      }
      catch (\LogicException $e) {
        $this->assertSame('Registration credentials already exist; supply the original prepared response', $e->getMessage());
      }
      $this->assertEquals($before, $database->select('simple_oauth_client_registration_tokens')->fields('simple_oauth_client_registration_tokens')->execute()->fetchAll());
      // Even an expired prepared response can be projected without cleanup.
      $normalizer->normalize($consumer, 'json', ['prepared_registration_response' => ClientRegistration::fromArray($response)]);
      $this->assertEquals($before, $database->select('simple_oauth_client_registration_tokens')->fields('simple_oauth_client_registration_tokens')->execute()->fetchAll());
    }
  }

  /**
   * Tests legacy issuance also rolls back if the pure mapper fails afterward.
   */
  public function testLegacyProjectionRollback(): void {
    $resolver = $this->createMock(TokenEndpointAuthMethodResolverInterface::class);
    $resolver->method('resolve')->willThrowException(new \RuntimeException('Projection failed'));
    $this->container->set('simple_oauth_client_registration.auth_method_resolver', $resolver);
    $consumer = $this->registration()->createConsumer(new ClientRegistration(redirectUris: ['https://example.org/callback']));
    try {
      $this->container->get('serializer')->normalize($consumer, 'json', ['include_registration_response' => TRUE]);
      $this->fail('Projection must fail.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Projection failed', $e->getMessage());
    }
    $this->assertSame(0, (int) $this->container->get('database')->select('simple_oauth_client_registration_tokens')->countQuery()->execute()->fetchField());
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('consumer')->loadMultiple());
  }

  /**
   * Tests effective methods through the installed client authentication flow.
   */
  public function testEffectiveAuthenticationMethods(): void {
    $service = $this->registration();
    $serializer = $this->container->get('serializer');
    foreach ([NULL, 'none', 'client_secret_basic', 'client_secret_post', 'client_secret_jwt', 'private_key_jwt'] as $requested) {
      $input = ['redirect_uris' => ['https://example.org/callback']];
      if ($requested !== NULL) {
        $input['token_endpoint_auth_method'] = $requested;
      }
      $response = $service->registerClient($serializer->denormalize($input, ClientRegistration::class));
      $client_id = $response['client_id'];
      $consumer = $this->consumer($client_id);
      $effective = $requested === 'none' ? 'none' : 'client_secret_basic';
      $this->assertSame($effective, $response['token_endpoint_auth_method']);
      $this->assertSame($effective, $service->getClientMetadata($client_id)['token_endpoint_auth_method']);
      $updated = $service->updateClientMetadata($client_id, new ClientRegistration(clientName: 'Updated', tokenEndpointAuthMethod: 'private_key_jwt'));
      $this->assertSame($effective, $updated['token_endpoint_auth_method']);
      $this->assertArrayNotHasKey('client_secret', $updated);
      $this->assertArrayNotHasKey('client_secret', $service->getClientMetadata($client_id));
      $this->assertSame($effective, $serializer->normalize($this->consumer($client_id), 'json')['token_endpoint_auth_method']);

      // Run the installed grant's client-auth stage against its real repository
      // and password checker. No token/key fixture obscures authentication.
      $grant = $this->container->get('plugin.manager.oauth2_grant.processor')->createInstance('refresh_token')->getGrantType($consumer);
      $grant->setClientRepository($this->container->get('simple_oauth.repositories.client'));
      $authenticate = new \ReflectionMethod($grant, 'validateClient');
      if ($effective === 'none') {
        $this->assertArrayNotHasKey('client_secret', $response);
        $request = (new ServerRequest('POST', 'https://example.org/oauth/token'))->withParsedBody(['client_id' => $client_id]);
        $this->assertSame($client_id, $authenticate->invoke($grant, $request)->getIdentifier());
        continue;
      }
      $secret = $response['client_secret'];
      $basic = new ServerRequest('POST', 'https://example.org/oauth/token', ['Authorization' => 'Basic ' . base64_encode($client_id . ':' . $secret)]);
      $this->assertSame($client_id, $authenticate->invoke($grant, $basic)->getIdentifier());
      $post = (new ServerRequest('POST', 'https://example.org/oauth/token'))->withParsedBody([
        'client_id' => $client_id,
        'client_secret' => $secret,
      ]);
      $this->assertSame($client_id, $authenticate->invoke($grant, $post)->getIdentifier());
      try {
        $authenticate->invoke($grant, $basic->withHeader('Authorization', 'Basic ' . base64_encode($client_id . ':wrong-secret')));
        $this->fail('An incorrect shared secret must fail authentication.');
      }
      catch (OAuthServerException $e) {
        $this->assertSame('invalid_client', $e->getErrorType());
      }
    }
  }

  /**
   * Tests a custom resolver controls projections without storing a method.
   */
  public function testCustomAuthenticationResolver(): void {
    $resolver = $this->createMock(TokenEndpointAuthMethodResolverInterface::class);
    // A post-capable integration can select post instead of built-in Basic.
    $resolver->method('resolve')->willReturn('client_secret_post');
    $this->container->set('simple_oauth_client_registration.auth_method_resolver', $resolver);
    $service = $this->registration();
    $response = $service->registerClient(new ClientRegistration(redirectUris: ['https://example.org/callback']));
    $this->assertSame('client_secret_post', $response['token_endpoint_auth_method']);
    $this->assertSame('client_secret_post', $service->getClientMetadata($response['client_id'])['token_endpoint_auth_method']);
    $this->assertSame('client_secret_post', $service->updateClientMetadata($response['client_id'], new ClientRegistration(clientName: 'Custom'))['token_endpoint_auth_method']);
    $this->assertSame('client_secret_post', $this->container->get('serializer')->normalize($this->consumer($response['client_id']))['token_endpoint_auth_method']);
    // The old service constructor still selects the built-in supported profile.
    $legacy = new ClientRegistrationService(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->container->get('logger.channel.simple_oauth_client_registration'),
      $this->container->get('simple_oauth_client_registration.service.token'),
      $this->container->get('file_url_generator'),
      $this->container->get('simple_oauth.oauth2_scope.provider'),
    );
    $this->assertSame('client_secret_basic', $legacy->getClientMetadata($response['client_id'])['token_endpoint_auth_method']);
  }

  /**
   * Tests a mapper failure rolls back entity and token writes during creation.
   */
  public function testResponseMapperRollback(): void {
    $resolver = $this->createMock(TokenEndpointAuthMethodResolverInterface::class);
    $resolver->method('resolve')->willThrowException(new \RuntimeException('Method resolution failed'));
    $this->container->set('simple_oauth_client_registration.auth_method_resolver', $resolver);
    try {
      $this->registration()->registerClient(new ClientRegistration(redirectUris: ['https://example.org/callback']));
      $this->fail('Method resolution must fail.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Method resolution failed', $e->getMessage());
    }
    $this->assertCount(0, $this->container->get('entity_type.manager')->getStorage('consumer')->loadMultiple());
    $this->assertSame(0, (int) $this->container->get('database')->select('simple_oauth_client_registration_tokens')->countQuery()->execute()->fetchField());
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
   * Tests rotation validates the replacement without changing rejected secrets.
   */
  public function testSecretRotationValidation(): void {
    $service = $this->registration();
    $data = $service->registerClient(new ClientRegistration(redirectUris: ['https://example.org/callback']));
    $cached = $this->consumer($data['client_id']);
    $hash = $cached->get('secret')->value;
    $metadata = $service->getClientMetadata($data['client_id']);
    $definition = $cached->getFieldDefinition('secret');
    $this->assertInstanceOf(BaseFieldDefinition::class, $definition);
    $constraints = $definition->getItemDefinition()->getConstraints();
    // Model an installed field constraint that rejects a new plaintext value.
    $definition->addPropertyConstraints('value', ['Regex' => ['pattern' => '/^\$/']]);
    $this->assertCount(0, $cached->validate());
    try {
      $service->rotateClientSecret($data['client_id']);
      $this->fail('An installed field constraint must reject the replacement.');
    }
    catch (BadRequestHttpException $exception) {
      $this->assertSame('Invalid client metadata', $exception->getMessage());
    }
    finally {
      $definition->getItemDefinition()->setConstraints($constraints);
    }
    $this->assertSame($hash, $cached->get('secret')->value);
    $this->container->get('entity_type.manager')->getStorage('consumer')->resetCache();
    $this->assertSame($hash, $this->consumer($data['client_id'])->get('secret')->value);
    $this->assertSame($metadata, $service->getClientMetadata($data['client_id']));
    $this->assertTrue($service->validateRegistrationToken($data['client_id'], $data['registration_access_token']));

    $rotated = $service->rotateClientSecret($data['client_id']);
    $stored = $this->consumer($data['client_id'])->get('secret')->value;
    $password = $this->container->get('password');
    $this->assertTrue($password->check($rotated['client_secret'], $stored));
    $this->assertFalse($password->check($data['client_secret'], $stored));
    $this->assertSame($hash, $cached->get('secret')->value);
    $this->assertSame($metadata, $service->getClientMetadata($data['client_id']));
    $this->assertArrayNotHasKey('client_secret', $service->getClientMetadata($data['client_id']));
    $this->assertTrue($service->validateRegistrationToken($data['client_id'], $data['registration_access_token']));
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
   * Tests declared native HTTPS clients retain routed PKCE policy and metadata.
   */
  public function testDeclaredApplicationTypePolicy(): void {
    $service = $this->registration();
    $detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    foreach (['native', 'web', NULL] as $type) {
      $data = $service->registerClient(new ClientRegistration(
        redirectUris: ['https://example.org/callback'],
        tokenEndpointAuthMethod: 'none', applicationType: $type,
      ));
      $id = $data['client_id'];
      $consumer = $this->consumer($id);
      $expected = $type === 'native';
      $this->assertSame($expected, $detector->isNativeClient($consumer));
      $this->assertSame($expected, $detector->requiresEnhancedPkce($consumer));
      $this->assertSame($expected ? 'native' : 'auto-detect', $consumer->get('native_app_override')->value);
      $this->assertSame('auto-detect', $consumer->get('native_app_enhanced_pkce')->value);
      $responses = [
        $data,
        $service->getClientMetadata($id),
        $service->updateClientMetadata($id, new ClientRegistration(clientName: 'Renamed')),
      ];
      foreach ($responses as $metadata) {
        $this->assertSame($expected ? 'native' : NULL, $metadata['application_type'] ?? NULL);
        $this->assertSame('none', $metadata['token_endpoint_auth_method']);
        $this->assertArrayNotHasKey('client_secret', $metadata);
        $this->assertSame(['https://example.org/callback'], $metadata['redirect_uris']);
      }
      $this->assertRoutedNativePkce($id, $expected);
      // A web declaration cannot weaken a retained native classification.
      $service->updateClientMetadata($id, new ClientRegistration(applicationType: 'web'));
      $this->assertRoutedNativePkce($id, $expected);
      $service->updateClientMetadata($id, new ClientRegistration(applicationType: 'native'));
      $this->assertRoutedNativePkce($id, TRUE);
      $consumer = $this->consumer($id);
      $this->config('simple_oauth_native_apps.settings')->set('native.enhanced_pkce', 'not-enhanced')->save();
      $this->assertRoutedNativePkce($id, FALSE);
      $policy = $this->config('simple_oauth_native_apps.consumer.' . $consumer->id());
      $policy->set('enhanced_pkce_override', 'enhanced')->save();
      $this->assertRoutedNativePkce($id, TRUE);
      $this->config('simple_oauth_native_apps.settings')->set('enforce_native_security', FALSE)->save();
      $this->assertRoutedNativePkce($id, FALSE);
      $this->config('simple_oauth_native_apps.settings')->set('enforce_native_security', TRUE)->set('native.enhanced_pkce', 'enhanced')->save();
      $policy->set('enhanced_pkce_override', 'not-enhanced')->save();
      $this->assertRoutedNativePkce($id, FALSE);
      $policy->delete();
      $this->assertRoutedNativePkce($id, TRUE);
      // Explicit administrative classification and PKCE controls survive input.
      $consumer->set('native_app_override', 'web')->set('native_app_enhanced_pkce', 'native')->save();
      $updated = $service->updateClientMetadata($id, new ClientRegistration(applicationType: 'native'));
      $this->assertSame('web', $updated['application_type']);
      $this->assertSame('native', $this->consumer($id)->get('native_app_enhanced_pkce')->value);
      $this->assertRoutedNativePkce($id, TRUE);
      $this->config('simple_oauth_native_apps.settings')->set('native.enhanced_pkce', 'auto-detect')->save();
    }
  }

  /**
   * Tests registration remains usable without native fields or dependencies.
   */
  public function testDeclaredTypeWithoutNativeApps(): void {
    $this->disableModules(['simple_oauth_native_apps']);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $service = $this->registration();
    $data = $service->registerClient(new ClientRegistration(
      redirectUris: ['https://example.org/callback'],
      tokenEndpointAuthMethod: 'none', applicationType: 'native',
    ));
    $this->assertFalse($this->consumer($data['client_id'])->hasField('native_app_override'));
    $this->assertArrayNotHasKey('application_type', $service->getClientMetadata($data['client_id']));
    $this->assertSame('none', $service->updateClientMetadata($data['client_id'], new ClientRegistration(clientName: 'Renamed'))['token_endpoint_auth_method']);
  }

  /**
   * Asserts actual route matching selects authorization and token PKCE policy.
   *
   * @param string $client_id
   *   The registered client's public identifier.
   * @param bool $required
   *   Whether the native subscriber must reject a missing challenge/verifier.
   */
  private function assertRoutedNativePkce(string $client_id, bool $required): void {
    $routes = new RouteCollection();
    $provider = $this->container->get('router.route_provider');
    foreach (['authorize' => '/identity/consent', 'token' => '/identity/exchange'] as $endpoint => $path) {
      $route = clone $provider->getRouteByName('oauth2_token.' . $endpoint);
      $route->setPath($path);
      $routes->add('oauth2_token.' . $endpoint, $route);
    }
    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new RouterListener(
      new UrlMatcher($routes, new RequestContext()),
      new RequestStack(),
    ));
    $dispatcher->addSubscriber($this->container->get('simple_oauth_native_apps.pkce_validation_subscriber'));
    $requests = [
      'authorize' => ['GET', '/identity/consent'],
      'token' => ['POST', '/identity/exchange'],
    ];
    foreach ($requests as $endpoint => [$method, $path]) {
      $request = Request::create($path, $method, ['client_id' => $client_id, 'grant_type' => 'authorization_code']);
      $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
      $dispatcher->dispatch($event, KernelEvents::REQUEST);
      $this->assertSame('oauth2_token.' . $endpoint, $request->attributes->get('_route'));
      $this->assertSame($required, $event->hasResponse(), $endpoint);
      if ($required) {
        $this->assertSame(400, $event->getResponse()->getStatusCode());
      }
    }
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
    Role::create(['id' => 'scope_manager', 'label' => 'Scope manager'])
      ->grantPermission('administer consumer access settings')->save();
    $manager = User::create([
      'uid' => 2,
      'name' => 'Scope manager',
      'status' => 1,
      'roles' => ['scope_manager'],
    ]);
    $manager->save();
    $this->container->get('current_user')->setAccount($manager);
    $this->assertTrue($manager->hasPermission('administer consumer access settings'));
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
   * Tests the install hook preserves absent, blank and explicit settings.
   */
  public function testInstallEndpointPortability(): void {
    $this->container->get('module_handler')->loadInclude('simple_oauth_client_registration', 'install');
    $name = 'simple_oauth_server_metadata.settings';
    $this->assertTrue($this->config($name)->isNew());
    simple_oauth_client_registration_install(FALSE);
    $this->assertTrue($this->config($name)->isNew());
    foreach (['', 'https://external.example.org/oauth/register', 'https://example.org/oauth/register'] as $endpoint) {
      $this->config($name)->set('registration_endpoint', $endpoint)->save();
      $before = $this->container->get('config.storage')->read($name);
      simple_oauth_client_registration_install(FALSE);
      $this->assertSame($before, $this->container->get('config.storage')->read($name));
    }

    $this->config($name)->set('registration_endpoint', '')->save();
    $before = $this->container->get('config.storage')->read($name);
    $this->container->get('router.builder')->rebuild();
    $generator = new UrlGenerator(
      $this->container->get('router.route_provider'),
      $this->container->get('path_processor_manager'),
      $this->container->get('route_processor_manager'),
      $this->container->get('request_stack'),
    );
    $context = $this->container->get('router.request_context');
    $generator->setContext($context);
    $this->container->set('url_generator', $generator);
    $discovery = $this->container->get('simple_oauth_server_metadata.endpoint_discovery');
    foreach ([['first.example.org', '/old'], ['moved.example.org', '/new/base']] as [$host, $base_path]) {
      $context->setScheme('https');
      $context->setHost($host);
      $context->setBaseUrl($base_path);
      $this->assertSame('https://' . $host . $base_path . '/oauth/register', $discovery->getRegistrationEndpoint());
      $this->assertSame($before, $this->container->get('config.storage')->read($name));
    }
  }

  /**
   * Tests uninstall preserves settings whose ownership cannot be proved.
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
    $this->assertSame('https://example.org/oauth/register', $this->config($name)->get('registration_endpoint'));
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
