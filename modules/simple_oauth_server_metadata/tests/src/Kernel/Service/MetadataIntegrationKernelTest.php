<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Kernel\Service;

use Defuse\Crypto\Core;
use Defuse\Crypto\Crypto;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\simple_oauth\Entity\Oauth2Token;
use Drupal\simple_oauth_server_metadata\Controller\OpenIdConfigurationController;
use Drupal\simple_oauth_server_metadata\Controller\ResourceMetadataController;
use Drupal\simple_oauth_server_metadata\Controller\ServerMetadataController;
use Drupal\simple_oauth_server_metadata\Controller\TokenIntrospectionController;
use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent;
use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvents;
use Drupal\simple_oauth_server_metadata\Service\ClaimsAuthDiscoveryService;
use Drupal\simple_oauth_server_metadata\Service\ResourceMetadataService;
use Drupal\simple_oauth_server_metadata\Service\ScopeDiscoveryService;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests discovery invalidation and token management with real Drupal services.
 */
#[Group('simple_oauth_server_metadata')]
#[RunTestsInSeparateProcesses]
final class MetadataIntegrationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'image',
    'options',
    'serialization',
    'consumers',
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_pkce',
    'simple_oauth_server_metadata',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user', 'simple_oauth', 'simple_oauth_pkce', 'simple_oauth_server_metadata']);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Tests JSON responses and tag invalidation on scope add, edit and delete.
   */
  public function testScopeCollectionInvalidationAndJsonLists(): void {
    $cache = $this->container->get('cache.default');
    $scope = NULL;
    foreach (['empty', 'add', 'edit', 'delete'] as $operation) {
      $responses = [
        'server' => ServerMetadataController::create($this->container)->__invoke(),
        'openid' => OpenIdConfigurationController::create($this->container)->configuration(),
      ];
      foreach ($responses as $name => $response) {
        $metadata = json_decode($response->getContent());
        $this->assertIsArray($metadata->scopes_supported);
        $this->assertContains('oauth2_scope', $response->getCacheableMetadata()->getCacheTags());
        $cache->set($name, $metadata, Cache::PERMANENT, $response->getCacheableMetadata()->getCacheTags());
        if ($operation === 'add') {
          $this->assertContains('openid', $metadata->scopes_supported);
          $this->assertContains('config:simple_oauth.oauth2_scope.openid', $response->getCacheableMetadata()->getCacheTags());
        }
        if ($operation === 'delete') {
          $this->assertNotContains('custom', $metadata->scopes_supported);
          $this->assertNotContains('config:simple_oauth.oauth2_scope.custom', $response->getCacheableMetadata()->getCacheTags());
        }
      }
      if ($operation === 'empty') {
        $scope = $this->createScope('openid', 'access content');
      }
      elseif ($operation === 'add') {
        $scope->set('name', 'custom')->save();
      }
      elseif ($operation === 'edit') {
        $scope->delete();
      }
      else {
        break;
      }
      $this->assertFalse($cache->get('server'), $operation);
      $this->assertFalse($cache->get('openid'), $operation);
    }
  }

  /**
   * Tests an OIDC name duplicated by the scope provider encodes as a JSON list.
   */
  public function testDuplicateOpenIdScopeIsJsonList(): void {
    $this->createScope('openid', 'access content');
    $responses = [
      ServerMetadataController::create($this->container)->__invoke(),
      OpenIdConfigurationController::create($this->container)->configuration(),
    ];
    foreach ($responses as $response) {
      $this->assertSame(['openid', 'profile', 'email', 'phone', 'address'], json_decode($response->getContent())->scopes_supported);
    }
  }

  /**
   * Tests the supported static scope adapter without imposing dynamic storage.
   */
  public function testStaticScopeAdapter(): void {
    $this->enableModules(['simple_oauth_static_scope', 'simple_oauth_static_scope_test']);
    $this->config('simple_oauth.settings')->set('scope_provider', 'static')->save();
    $provider = $this->container->get('simple_oauth.oauth2_scope.provider.factory')->get();
    $service = new ScopeDiscoveryService($provider, $this->container->get('config.factory'), $this->container->get('logger.factory'));
    $this->assertContains('static_scope:child', $service->getScopesSupported());
    $this->assertContains('config:core.extension', $service->getCacheTags());
    $this->assertNotContains('oauth2_scope', $service->getCacheTags());
    $this->assertSame(Cache::PERMANENT, $service->getCacheMaxAge());
  }

  /**
   * Tests endpoint override precedence and relative URL conversion.
   */
  public function testEndpointOverrides(): void {
    $fields = [
      'registration_endpoint',
      'revocation_endpoint',
      'introspection_endpoint',
      'device_authorization_endpoint',
    ];
    $config = $this->config('simple_oauth_server_metadata.settings');
    foreach ($fields as $field) {
      $config->set($field, 'https://external.example/' . $field);
    }
    $config->save();
    $server = $this->container->get('simple_oauth_server_metadata.server_metadata');
    $openid = $this->container->get('simple_oauth_server_metadata.openid_configuration');
    foreach ([$server->getServerMetadata(), $openid->getOpenIdConfiguration()] as $metadata) {
      foreach ($fields as $field) {
        $this->assertSame('https://external.example/' . $field, $metadata[$field]);
      }
    }
    foreach ($fields as $field) {
      $metadata = $server->getServerMetadata([$field => 'https://preview.example/' . $field]);
      $this->assertSame('https://preview.example/' . $field, $metadata[$field]);
      $metadata = $server->getServerMetadata([$field => '']);
      $this->assertNotSame('https://external.example/' . $field, $metadata[$field] ?? NULL);
      $config->set($field, '/custom/' . $field);
    }
    $config->save();
    foreach ([$server->getServerMetadata(), $openid->getOpenIdConfiguration()] as $metadata) {
      foreach ($fields as $field) {
        $this->assertSame('/custom/' . $field, parse_url($metadata[$field], PHP_URL_PATH));
        $this->assertNotEmpty(parse_url($metadata[$field], PHP_URL_HOST));
      }
    }
    $this->assertSame(['code'], $server->getServerMetadata()['response_types_supported']);
  }

  /**
   * Tests optional and disabled PKCE policy and both configured method toggles.
   */
  public function testPkcePolicyCapabilities(): void {
    $without_policy = new ClaimsAuthDiscoveryService($this->container->get('config.factory'));
    $this->assertSame(['S256', 'plain'], $without_policy->getCodeChallengeMethodsSupported());
    $config = $this->config('simple_oauth_pkce.settings');
    foreach ([
      ['mandatory', TRUE, FALSE, ['S256']],
      ['optional', FALSE, TRUE, ['plain']],
      ['disabled', FALSE, FALSE, ['S256', 'plain']],
    ] as [$enforcement, $s256, $plain, $expected]) {
      $config->set('enforcement', $enforcement)->set('s256_enabled', $s256)->set('plain_enabled', $plain)->save();
      $responses = [
        ServerMetadataController::create($this->container)->__invoke(),
        OpenIdConfigurationController::create($this->container)->configuration(),
      ];
      foreach ($responses as $response) {
        $this->assertSame($expected, json_decode($response->getContent(), TRUE)['code_challenge_methods_supported']);
        $this->assertContains('config:simple_oauth_pkce.settings', $response->getCacheableMetadata()->getCacheTags());
      }
    }
  }

  /**
   * Tests event dependencies and subclass defaults across repeated generations.
   */
  public function testResourceEventCacheabilityIsolation(): void {
    $dispatcher = $this->container->get('event_dispatcher');
    $calls = 0;
    $dispatcher->addListener(ResourceMetadataEvents::BUILD, static function (ResourceMetadataEvent $event) use (&$calls): void {
      if ($calls++ === 0) {
        $event->addMetadataField('custom', 'first');
        $event->addCacheableDependency((new CacheableMetadata())->setCacheTags(['custom:first'])->setCacheContexts(['url.query_args'])->setCacheMaxAge(0));
      }
      elseif ($calls === 2) {
        $event->addCacheTags(['custom:second']);
        $event->mergeCacheMaxAge(600);
      }
    });
    $service = new class ($this->container->get('config.factory'), $this->container->get('simple_oauth_server_metadata.endpoint_discovery'), $dispatcher) extends ResourceMetadataService {

      /**
       * Adds extension defaults without changing the public constructor.
       */
      public function addDefaults(): void {
        $this->cacheTags[] = 'extension:baseline';
        $this->cacheContexts[] = 'url.site';
        $this->cacheMaxAge = 900;
      }

    };
    $service->addDefaults();
    $controller = new ResourceMetadataController($service);
    $first = $controller();
    $this->assertSame('first', json_decode($first->getContent(), TRUE)['custom']);
    $this->assertSame(0, $first->getCacheableMetadata()->getCacheMaxAge());
    $this->assertContains('custom:first', $first->getCacheableMetadata()->getCacheTags());
    $this->assertContains('url.query_args', $first->getCacheableMetadata()->getCacheContexts());
    $second = $controller();
    $this->assertArrayNotHasKey('custom', json_decode($second->getContent(), TRUE));
    $this->assertSame(600, $second->getCacheableMetadata()->getCacheMaxAge());
    $this->assertContains('custom:second', $second->getCacheableMetadata()->getCacheTags());
    $this->assertNotContains('custom:first', $second->getCacheableMetadata()->getCacheTags());
    $third = $controller();
    $this->assertSame(900, $third->getCacheableMetadata()->getCacheMaxAge());
    $this->assertNotContains('custom:second', $third->getCacheableMetadata()->getCacheTags());
    $this->assertNotContains('url.query_args', $third->getCacheableMetadata()->getCacheContexts());
    $this->assertContains('extension:baseline', $third->getCacheableMetadata()->getCacheTags());
    $this->assertContains('url.site', $third->getCacheableMetadata()->getCacheContexts());
    $this->assertSame(['header', 'body', 'query'], json_decode($third->getContent(), TRUE)['bearer_methods_supported']);
  }

  /**
   * Tests Basic encoding with the real repository and password hashing.
   */
  public function testBasicEncodedAndLegacyCredentials(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    $service = $this->container->get('simple_oauth_server_metadata.client_authentication');
    foreach (['plus+one', 'percent%one', 'colon:one', 'space one', 'ordinary'] as $id) {
      $secret = 'secret+with%: space';
      $consumer = Consumer::create(['label' => $id, 'client_id' => $id, 'secret' => $secret, 'confidential' => TRUE]);
      $consumer->save();
      $request = new ServerRequest('POST', '/oauth/revoke', ['Authorization' => 'Basic ' . base64_encode(urlencode($id) . ':' . urlencode($secret))]);
      $this->assertSame($consumer->id(), $service->authenticateClient($request)?->id(), $id);
      $post = (new ServerRequest('POST', '/oauth/revoke'))->withParsedBody([
        'client_id' => $id,
        'client_secret' => $secret,
      ]);
      $this->assertSame($consumer->id(), $service->authenticateClient($post)?->id());
      if (!str_contains($id, ':')) {
        $raw = $request->withHeader('Authorization', 'basic ' . base64_encode($id . ':' . $secret));
        $this->assertSame($consumer->id(), $service->authenticateClient($raw)?->id());
      }
    }
    $decoded = Consumer::create([
      'label' => 'decoded',
      'client_id' => 'collision+id',
      'secret' => 'decoded-secret',
      'confidential' => TRUE,
    ]);
    $decoded->save();
    $raw = Consumer::create([
      'label' => 'raw',
      'client_id' => 'collision%2Bid',
      'secret' => 'raw-secret',
      'confidential' => TRUE,
    ]);
    $raw->save();
    $request = new ServerRequest('POST', '/oauth/revoke', ['Authorization' => 'Basic ' . base64_encode('collision%2Bid:decoded-secret')]);
    $this->assertNull($service->authenticateClient($request));
    $this->assertSame($raw->id(), $service->authenticateClient($request->withHeader('Authorization', 'Basic ' . base64_encode('collision%2Bid:raw-secret')))?->id());
  }

  /**
   * Tests scoped bypass permission, owner fallback and inactive responses.
   */
  public function testIntrospectionUsesTokenScopedPermissions(): void {
    foreach (['user', 'consumer', 'oauth2_token'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    // Reserve UID 1 so the test account cannot use superuser permissions.
    User::create(['name' => 'reserved'])->save();
    $role = Role::create(['id' => 'introspector', 'label' => 'Introspector']);
    $role->grantPermission('bypass token introspection restrictions')->save();
    $user = User::create(['name' => 'introspector', 'status' => 1, 'roles' => [$role->id()]]);
    $user->save();
    $this->assertTrue($user->hasPermission('bypass token introspection restrictions'));
    $consumer = Consumer::create(['label' => 'introspection', 'client_id' => 'introspection', 'user_id' => $user->id()]);
    $consumer->save();
    $denied_scope = $this->createScope('read', 'access content');
    $allowed_scope = $this->createScope('introspect', 'bypass token introspection restrictions');
    $current_user = $this->container->get('current_user');
    $request_stack = $this->container->get('request_stack');
    $controller = TokenIntrospectionController::create($this->container);
    foreach ([$denied_scope, $allowed_scope] as $scope) {
      $auth_token = Oauth2Token::create([
        'bundle' => 'access_token',
        'auth_user_id' => $user->id(),
        'client' => $consumer->id(),
        'scopes' => [$scope->id()],
      ]);
      $account = new TokenAuthUser($this->container->get('permission_checker'), $auth_token, $this->container->get('psr7.http_message_factory'), $request_stack);
      $current_user->setAccount($account);
      $allowed = $scope === $allowed_scope;
      // Let the controller choose the account before warming permission caches.
      foreach ([NULL, 0, $user->id(), 999] as $owner) {
        $token = Oauth2Token::create([
          'bundle' => 'refresh_token',
          'auth_user_id' => $owner,
          'client' => $consumer->id(),
          'value' => bin2hex(random_bytes(20)),
          'expire' => time() + 3600,
        ]);
        $token->save();
        $payload = [
          'refresh_token_id' => $token->get('value')->value,
          'client_id' => $consumer->getClientId(),
          'expire_time' => time() + 3600,
          'scopes' => ['read'],
        ];
        $value = Crypto::encryptWithPassword(json_encode($payload), Core::ourSubstr(Settings::getHashSalt(), 0, 32));
        $response = $controller->introspect(Request::create('/oauth/introspect', 'POST', ['token' => $value]));
        $data = json_decode($response->getContent(), TRUE);
        $this->assertSame($allowed || $owner === $user->id(), $data['active']);
        if (!$data['active']) {
          $this->assertSame(['active' => FALSE], $data);
        }
        if ($allowed && $owner === $user->id()) {
          $token->set('expire', time() - 60)->save();
          $this->assertSame(['active' => FALSE], json_decode($controller->introspect(Request::create('/oauth/introspect', 'POST', ['token' => $value]))->getContent(), TRUE));
          $token->set('expire', time() + 3600);
          $token->revoke();
          $token->save();
          $this->assertSame(['active' => FALSE], json_decode($controller->introspect(Request::create('/oauth/introspect', 'POST', ['token' => $value]))->getContent(), TRUE));
        }
        $token->delete();
      }
      $this->assertSame($allowed, $account->hasPermission('bypass token introspection restrictions'));
    }
    $current_user->setAccount(new AnonymousUserSession());
    $this->assertSame(401, $controller->introspect(Request::create('/oauth/introspect', 'POST', ['token' => 'invalid']))->getStatusCode());
    // Keep the historical constructor callable without the optional account.
    $legacy = new TokenIntrospectionController($request_stack, $this->container->get('logger.channel.simple_oauth'), $this->container->get('simple_oauth_server_metadata.token_lookup'));
    $this->assertSame(401, $legacy->introspect(Request::create('/oauth/introspect', 'POST'))->getStatusCode());
  }

  /**
   * Tests registered route events and supported plural lifecycle hooks.
   */
  public function testLifecycleInvalidation(): void {
    $cache = $this->container->get('cache.default');
    $handler = $this->container->get('module_handler');
    foreach (['modules_installed', 'modules_uninstalled'] as $hook) {
      $cache->set('metadata', TRUE, Cache::PERMANENT, ['simple_oauth_server_metadata']);
      $handler->invoke('simple_oauth_server_metadata', $hook, [['example'], FALSE]);
      $this->assertFalse($cache->get('metadata'), $hook);
    }
    $cache->set('metadata', TRUE, Cache::PERMANENT, ['simple_oauth_server_metadata']);
    $this->container->get('router.builder')->rebuild();
    $this->assertFalse($cache->get('metadata'));
    $response = ResourceMetadataController::create($this->container)->__invoke();
    $cache->set('metadata', TRUE, Cache::PERMANENT, $response->getCacheableMetadata()->getCacheTags());
    $this->config('simple_oauth_server_metadata.settings')->set('resource_documentation', 'https://docs.example')->save();
    $this->assertFalse($cache->get('metadata'));
  }

  /**
   * Creates a real dynamic scope with a single permission.
   *
   * @param string $name
   *   The scope name.
   * @param string $permission
   *   The permission granted by the scope.
   *
   * @return \Drupal\simple_oauth\Entity\Oauth2Scope
   *   The saved scope.
   */
  private function createScope(string $name, string $permission): Oauth2Scope {
    $scope = Oauth2Scope::create([
      'name' => $name,
      'granularity_id' => 'permission',
      'granularity_configuration' => ['permission' => $permission],
    ]);
    $scope->save();
    return $scope;
  }

}
