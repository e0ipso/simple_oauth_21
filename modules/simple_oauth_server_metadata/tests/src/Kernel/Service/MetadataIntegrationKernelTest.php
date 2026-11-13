<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Kernel\Service;

use Defuse\Crypto\Core;
use Defuse\Crypto\Crypto;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Form\FormState;
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
use Drupal\simple_oauth_server_metadata\Form\ServerMetadataSettingsForm;
use Drupal\simple_oauth_server_metadata\Service\ClaimsAuthDiscoveryService;
use Drupal\simple_oauth_server_metadata\Service\ResourceMetadataService;
use Drupal\simple_oauth_server_metadata\Service\ScopeDiscoveryService;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

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
   * Tests real configuration overrides on repeated controller responses.
   *
   * @param string $config_name
   *   The configuration consumed by discovery.
   */
  #[DataProvider('discoveryConfigProvider')]
  public function testConfigOverrideCacheability(string $config_name): void {
    $factory = $this->container->get('config.factory');
    $stack = $this->container->get('request_stack');
    $override = new class ($stack) implements ConfigFactoryOverrideInterface {

      /**
       * The current override values, keyed by configuration name.
       */
      public array $values = [];

      /**
       * The current override dependencies, keyed by configuration name.
       *
       * @var \Drupal\Core\Cache\CacheableMetadata[]
       */
      public array $dependencies = [];

      /**
       * Constructs an override that varies by the current request.
       *
       * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
       *   The current request stack.
       */
      public function __construct(private readonly RequestStack $requestStack) {}

      /**
       * {@inheritdoc}
       */
      public function loadOverrides($names): array {
        $values = array_intersect_key($this->values, array_flip($names));
        $variant = $this->requestStack->getCurrentRequest()?->query->get('variant');
        if ($variant !== NULL && isset($values['simple_oauth_server_metadata.settings'])) {
          foreach (['service_documentation', 'resource_documentation'] as $field) {
            $values['simple_oauth_server_metadata.settings'][$field] .= $variant;
          }
        }
        return $values;
      }

      /**
       * {@inheritdoc}
       */
      public function getCacheSuffix(): string {
        return 'metadata-test:' . $this->requestStack->getCurrentRequest()?->query->get('variant');
      }

      /**
       * {@inheritdoc}
       */
      public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
        return NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function getCacheableMetadata($name): CacheableMetadata {
        return $this->dependencies[$name] ?? new CacheableMetadata();
      }

    };
    $factory->addOverride($override);
    $claims = new class ($factory, $this->container->get('simple_oauth_pkce.settings')) extends ClaimsAuthDiscoveryService {

      /**
       * Sets cacheability defaults supplied by an extending module.
       */
      public function addDefaults(): void {
        $this->cacheTags[] = 'extension:claims';
        $this->cacheContexts[] = 'url.query_args:claims';
      }

    };
    $claims->addDefaults();
    $this->container->set('simple_oauth_server_metadata.claims_auth_discovery', $claims);
    $this->container->get('event_dispatcher')->addListener(ResourceMetadataEvents::BUILD, static function (ResourceMetadataEvent $event): void {
      $event->addCacheTags(['extension:resource']);
    });
    $server = ServerMetadataController::create($this->container);
    $openid = OpenIdConfigurationController::create($this->container);
    $resource = ResourceMetadataController::create($this->container);
    foreach ([0, 120, Cache::PERMANENT] as $generation => $max_age) {
      $context = ['url.query_args:variant', 'languages:language_interface', NULL][$generation];
      $tag = 'override:generation' . $generation;
      $override->dependencies[$config_name] = (new CacheableMetadata())
        ->setCacheTags($generation < 2 ? [$tag] : [])
        ->setCacheContexts($context ? [$context] : [])
        ->setCacheMaxAge($max_age);
      $override->values[$config_name] = match ($config_name) {
        'simple_oauth_server_metadata.settings' => [
          'service_documentation' => 'https://docs.example/' . $generation,
          'resource_documentation' => 'https://docs.example/' . $generation,
        ],
        'simple_oauth.settings' => ['disable_openid_connect' => $generation === 1],
        'simple_oauth_pkce.settings' => [
          'enforcement' => 'optional',
          's256_enabled' => $generation !== 1,
          'plain_enabled' => $generation === 1,
        ],
      };
      $factory->reset();
      $claims->getClaimsSupported();
      $claims->getCodeChallengeMethodsSupported();
      if ($config_name !== 'simple_oauth_server_metadata.settings') {
        $this->assertSame($max_age, $claims->getCacheMaxAge());
        $this->assertSame($generation < 2, in_array($tag, $claims->getCacheTags(), TRUE));
      }
      $responses = ['server' => $server()];
      if ($config_name !== 'simple_oauth.settings' || $generation !== 1) {
        $responses['openid'] = $openid->configuration();
      }
      if ($config_name === 'simple_oauth_server_metadata.settings') {
        $responses['resource'] = $resource();
      }
      foreach ($responses as $name => $response) {
        $metadata = json_decode($response->getContent(), TRUE);
        $cacheability = $response->getCacheableMetadata();
        $this->assertContains('config:' . $config_name, $cacheability->getCacheTags());
        $this->assertSame($generation < 2 ? $max_age : ($name === 'openid' ? 3600 : Cache::PERMANENT), $cacheability->getCacheMaxAge());
        if ($generation < 2) {
          $this->assertContains($tag, $cacheability->getCacheTags());
          $this->assertContains($context, $cacheability->getCacheContexts());
        }
        if ($generation > 0) {
          $this->assertNotContains('override:generation0', $cacheability->getCacheTags());
          $this->assertNotContains('url.query_args:variant', $cacheability->getCacheContexts());
        }
        if ($generation === 2) {
          $this->assertNotContains('override:generation1', $cacheability->getCacheTags());
          $this->assertNotContains('languages:language_interface', $cacheability->getCacheContexts());
        }
        if ($config_name === 'simple_oauth_server_metadata.settings') {
          $this->assertSame('https://docs.example/' . $generation, $metadata[$name === 'resource' ? 'resource_documentation' : 'service_documentation']);
        }
        elseif ($config_name === 'simple_oauth_pkce.settings') {
          $this->assertSame($generation === 1 ? ['plain'] : ['S256'], $metadata['code_challenge_methods_supported']);
        }
        else {
          $this->assertSame($generation !== 1, in_array('openid', $metadata['scopes_supported'] ?? [], TRUE));
          $this->assertSame($generation !== 1, isset($metadata['claims_supported']));
        }
        if ($name !== 'resource') {
          $this->assertContains('oauth2_scope', $cacheability->getCacheTags());
          $this->assertContains('extension:claims', $cacheability->getCacheTags());
          $this->assertContains('url.query_args:claims', $cacheability->getCacheContexts());
        }
        else {
          $this->assertContains('extension:resource', $cacheability->getCacheTags());
        }
      }
    }
    if ($config_name === 'simple_oauth_server_metadata.settings') {
      $override->dependencies[$config_name] = (new CacheableMetadata())->setCacheContexts(['url.query_args:variant']);
      $override->values[$config_name] = [
        'service_documentation' => 'https://docs.example/context/',
        'resource_documentation' => 'https://docs.example/context/',
      ];
      $keys = [];
      foreach (['first', 'second'] as $variant) {
        $stack->push(Request::create('https://issuer.example/?variant=' . $variant));
        try {
          foreach ([$server(), $openid->configuration(), $resource()] as $response) {
            $metadata = json_decode($response->getContent(), TRUE);
            $this->assertSame('https://docs.example/context/' . $variant, $metadata['resource_documentation'] ?? $metadata['service_documentation']);
            $this->assertContains('url.query_args:variant', $response->getCacheableMetadata()->getCacheContexts());
          }
          $keys[] = $this->container->get('cache_contexts_manager')->convertTokensToKeys($response->getCacheableMetadata()->getCacheContexts())->getKeys();
        }
        finally {
          $stack->pop();
        }
      }
      $this->assertNotSame($keys[0], $keys[1]);
    }
  }

  /**
   * Lists configuration objects consumed by metadata and discovery services.
   *
   * @return array<string, array{string}>
   *   Configuration names for the real factory override scenarios.
   */
  public static function discoveryConfigProvider(): array {
    return [
      'metadata' => ['simple_oauth_server_metadata.settings'],
      'oauth' => ['simple_oauth.settings'],
      'pkce' => ['simple_oauth_pkce.settings'],
    ];
  }

  /**
   * Tests settings overrides take precedence without changing stored values.
   */
  public function testSettingsOverridePrecedence(): void {
    $original = $GLOBALS['config'] ?? [];
    $factory = $this->container->get('config.factory');
    try {
      $GLOBALS['config']['simple_oauth_server_metadata.settings'] = [
        'service_documentation' => 'https://settings.example/docs',
        'resource_documentation' => 'https://settings.example/resource',
      ];
      $factory->reset();
      $responses = [
        ServerMetadataController::create($this->container)->__invoke(),
        OpenIdConfigurationController::create($this->container)->configuration(),
        ResourceMetadataController::create($this->container)->__invoke(),
      ];
      foreach ($responses as $response) {
        $metadata = json_decode($response->getContent(), TRUE);
        $this->assertSame(isset($metadata['resource']) ? 'https://settings.example/resource' : 'https://settings.example/docs', $metadata['resource_documentation'] ?? $metadata['service_documentation']);
        $this->assertContains('config:simple_oauth_server_metadata.settings', $response->getCacheableMetadata()->getCacheTags());
      }
      $this->assertSame('', $factory->getEditable('simple_oauth_server_metadata.settings')->get('resource_documentation'));
    }
    finally {
      $GLOBALS['config'] = $original;
      $factory->reset();
    }
  }

  /**
   * Tests ordinary form saves retain ignored legacy response settings.
   */
  public function testResponseSettingsFormCompatibility(): void {
    $legacy_types = ['token', 'id_token', 'code id_token'];
    $legacy_modes = ['fragment'];
    $this->config('simple_oauth_server_metadata.settings')
      ->set('response_types_supported', $legacy_types)
      ->set('response_modes_supported', $legacy_modes)
      ->save();
    $builder = $this->container->get('form_builder');
    $form = $builder->getForm(ServerMetadataSettingsForm::class);
    $this->assertArrayNotHasKey('response_types_supported', $form['openid_discovery']);
    $this->assertArrayNotHasKey('response_modes_supported', $form['openid_discovery']);
    $state = (new FormState())->setValues(['service_documentation' => 'https://updated.example/docs']);
    $builder->submitForm(ServerMetadataSettingsForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $config = $this->config('simple_oauth_server_metadata.settings');
    $this->assertSame('https://updated.example/docs', $config->get('service_documentation'));
    $this->assertSame($legacy_types, $config->get('response_types_supported'));
    $this->assertSame($legacy_modes, $config->get('response_modes_supported'));
    $server = $this->container->get('simple_oauth_server_metadata.server_metadata')->getServerMetadata();
    $openid = $this->container->get('simple_oauth_server_metadata.openid_configuration')->getOpenIdConfiguration();
    foreach ([$server, $openid] as $metadata) {
      $this->assertSame(['code'], $metadata['response_types_supported']);
      $this->assertSame(['query', 'fragment', 'form_post'], $metadata['response_modes_supported']);
    }
    // Keep explicit external form-state submissions in their existing format.
    $state = (new FormState())->setValues([
      'response_types_supported' => ['code' => 'code', 'token' => 0],
      'response_modes_supported' => ['query' => 'query', 'fragment' => 0],
    ]);
    $builder->submitForm(ServerMetadataSettingsForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $config = $this->config('simple_oauth_server_metadata.settings');
    $this->assertSame(['code'], $config->get('response_types_supported'));
    $this->assertSame(['query'], $config->get('response_modes_supported'));
    foreach ([
      ['response_types_supported' => ['unsupported']],
      ['response_types_supported' => 'code'],
      ['response_modes_supported' => ['form_post']],
    ] as $invalid) {
      $state = (new FormState())->setValues($invalid);
      $builder->submitForm(ServerMetadataSettingsForm::class, $state);
      $this->assertArrayHasKey(array_key_first($invalid), $state->getErrors());
      $this->assertSame(['code'], $this->config('simple_oauth_server_metadata.settings')->get('response_types_supported'));
      $this->assertSame(['query'], $this->config('simple_oauth_server_metadata.settings')->get('response_modes_supported'));
    }
  }

  /**
   * Tests unavailable optional modules preserve explicit endpoint overrides.
   */
  public function testUnavailableEndpointFormPersistence(): void {
    $builder = $this->container->get('form_builder');
    $form = $builder->getForm(ServerMetadataSettingsForm::class);
    $this->assertArrayNotHasKey('device_authorization_endpoint', $form['endpoints']);
    $overrides = [
      'registration_endpoint' => 'https://external.example/Register',
      'device_authorization_endpoint' => 'https://external.example/Device',
    ];
    $state = (new FormState())->setValues(['service_documentation' => 'https://updated.example/docs']);
    $builder->submitForm(ServerMetadataSettingsForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $responses = [
      ServerMetadataController::create($this->container)->__invoke(),
      OpenIdConfigurationController::create($this->container)->configuration(),
    ];
    foreach ($responses as $response) {
      $metadata = json_decode($response->getContent(), TRUE);
      foreach ($overrides as $field => $value) {
        $this->assertArrayNotHasKey($field, $metadata);
      }
    }
    foreach ($overrides as $field => $value) {
      $this->config('simple_oauth_server_metadata.settings')->set($field, $value)->save();
    }
    // The device field is absent when its module is unavailable.
    $state = (new FormState())->setValues(['service_documentation' => 'https://partial.example/docs']);
    $builder->submitForm(ServerMetadataSettingsForm::class, $state);
    $this->assertSame([], $state->getErrors());
    foreach ($overrides as $field => $value) {
      $this->assertSame($value, $this->config('simple_oauth_server_metadata.settings')->get($field));
    }
    $responses = [
      ServerMetadataController::create($this->container)->__invoke(),
      OpenIdConfigurationController::create($this->container)->configuration(),
    ];
    foreach ($responses as $response) {
      $metadata = json_decode($response->getContent(), TRUE);
      foreach ($overrides as $field => $value) {
        $this->assertSame($value, $metadata[$field]);
      }
    }
  }

  /**
   * Tests direct form submissions distinguish omitted and NULL endpoints.
   */
  public function testPartialEndpointSubmissionCompatibility(): void {
    $config = $this->config('simple_oauth_server_metadata.settings');
    $config->set('registration_endpoint', 'https://saved.example/Register')
      ->set('device_authorization_endpoint', 'https://saved.example/Device')
      ->set('response_types_supported', ['id_token'])
      ->save();
    $object = ServerMetadataSettingsForm::create($this->container);
    $form = $this->container->get('form_builder')->getForm(ServerMetadataSettingsForm::class);
    $state = (new FormState())->setValues([
      'device_authorization_endpoint' => NULL,
      'response_types_supported' => NULL,
    ]);
    $object->submitForm($form, $state);
    $config = $this->config($config->getName());
    $this->assertSame('https://saved.example/Register', $config->get('registration_endpoint'));
    $this->assertNull($config->get('device_authorization_endpoint'));
    $this->assertSame(['id_token'], $config->get('response_types_supported'));
  }

  /**
   * Tests absolute discovery URLs vary by the current site origin in caches.
   */
  public function testDiscoveryVariesBySiteOrigin(): void {
    $stack = $this->container->get('request_stack');
    $context = $this->container->get('router.request_context');
    foreach (['first.example.org', 'second.example.org'] as $host) {
      $request = Request::create('https://' . $host . '/.well-known/oauth-authorization-server');
      $stack->push($request);
      $context->fromRequest($request);
      try {
        $responses = [
          ServerMetadataController::create($this->container)->__invoke(),
          OpenIdConfigurationController::create($this->container)->configuration(),
          ResourceMetadataController::create($this->container)->__invoke(),
        ];
        foreach ($responses as $response) {
          $metadata = json_decode($response->getContent(), TRUE);
          $this->assertSame($host, parse_url($metadata['issuer'] ?? $metadata['resource'], PHP_URL_HOST));
          $this->assertContains('url.site', $response->getCacheableMetadata()->getCacheContexts());
        }
      }
      finally {
        $stack->pop();
      }
    }
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
