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
use Drupal\simple_oauth\Entities\AccessTokenEntity;
use Drupal\simple_oauth\Entities\ClientEntity;
use Drupal\Core\Extension\ModuleHandlerInterface;
use League\OAuth2\Server\CryptKey;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Drupal\simple_oauth\Entity\Oauth2Token;
use Drupal\simple_oauth_server_metadata\Controller\OpenIdConfigurationController;
use Drupal\simple_oauth_server_metadata\Controller\ResourceMetadataController;
use Drupal\simple_oauth_server_metadata\Controller\ServerMetadataController;
use Drupal\simple_oauth_server_metadata\Controller\TokenIntrospectionController;
use Drupal\simple_oauth_server_metadata\Controller\TokenRevocationController;
use Drupal\system\Controller\CsrfTokenController;
use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent;
use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvents;
use Drupal\simple_oauth_server_metadata\Form\ServerMetadataSettingsForm;
use Drupal\simple_oauth_server_metadata\Service\ClaimsAuthDiscoveryService;
use Drupal\simple_oauth_server_metadata\Service\GrantTypeDiscoveryService;
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
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

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
   * Tests discovery uses its own claims without replacing upstream claims.
   */
  public function testDiscoveryClaimsParameterIsolation(): void {
    $upstream = $this->container->getParameter('simple_oauth.openid.claims');
    foreach (['name', 'preferred_username', 'email', 'email_verified'] as $claim) {
      $this->assertContains($claim, $upstream);
    }
    $expected = ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'acr', 'amr', 'azp'];
    $this->assertSame($expected, $this->container->getParameter('simple_oauth_server_metadata.openid.claims'));
    $metadata = $this->container->get('simple_oauth_server_metadata.openid_configuration')->getOpenIdConfiguration();
    $this->assertSame([
      'sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'name', 'given_name',
      'family_name', 'preferred_username', 'email', 'email_verified',
      'nonce', 'acr', 'amr', 'azp',
    ], $metadata['claims_supported']);
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
          $values['simple_oauth_server_metadata.settings']['ui_locales_supported'] = [
            7 => $variant === 'first' ? 'fr' : 'de',
            3 => 'en',
          ];
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
          'ui_locales_supported' => [2 => 'fr', 5 => 'en'],
          'additional_claims_supported' => [2 => '0', 5 => 'custom'],
          'additional_signing_algorithms' => [2 => 'ES256', 5 => 'RS384'],
        ],
        'simple_oauth.settings' => ['disable_openid_connect' => $generation === 1],
        'simple_oauth_pkce.settings' => [
          'enforcement' => 'optional',
          's256_enabled' => $generation !== 1,
          'plain_enabled' => $generation === 1,
        ],
      };
      $factory->reset();
      $source = serialize($factory->get($config_name)->getRawData());
      $stored_source = serialize($this->container->get('config.storage')->read($config_name));
      $override_source = serialize($override->values);
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
          if ($name !== 'resource') {
            $decoded = json_decode($response->getContent());
            $this->assertSame(['fr', 'en'], $decoded->ui_locales_supported);
            $this->assertSame([2 => 'fr', 5 => 'en'], $factory->get($config_name)->get('ui_locales_supported'));
            if ($name === 'server') {
              $this->assertSame(['0', 'custom'], $decoded->additional_claims_supported);
              $this->assertSame(['ES256', 'RS384'], $decoded->additional_signing_algorithms);
            }
            else {
              $this->assertContains('0', $decoded->claims_supported);
              $this->assertSame(['RS256', 'ES256', 'RS384'], $decoded->id_token_signing_alg_values_supported);
            }
          }
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
      $this->assertSame($source, serialize($factory->get($config_name)->getRawData()));
      $this->assertSame($stored_source, serialize($this->container->get('config.storage')->read($config_name)));
      $this->assertSame($override_source, serialize($override->values));
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
            if (!isset($metadata['resource'])) {
              $this->assertSame([$variant === 'first' ? 'fr' : 'de', 'en'], json_decode($response->getContent())->ui_locales_supported);
            }
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
   * Tests textarea parsing through the real form build, validation and save.
   */
  public function testTextareaListSubmission(): void {
    $builder = $this->container->get('form_builder');
    $state = (new FormState())->setValues([
      'ui_locales_supported' => " en \n\n fr \n \n en ",
      'additional_claims_supported' => " custom \n\n 0 \n \n custom ",
      'additional_signing_algorithms' => " ES256 \n\n RS384 \n \n ES256 ",
    ]);
    $builder->submitForm(ServerMetadataSettingsForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $expected = [
      'ui_locales_supported' => ['en', 'fr', 'en'],
      'additional_claims_supported' => ['custom', '0', 'custom'],
      'additional_signing_algorithms' => ['ES256', 'RS384', 'ES256'],
    ];
    $config = $this->config('simple_oauth_server_metadata.settings');
    $form = $builder->getForm(ServerMetadataSettingsForm::class);
    foreach ($expected as $field => $values) {
      $this->assertSame($values, $state->getValue($field));
      $this->assertSame($values, $config->get($field));
      $this->assertSame(implode("\n", $values), $form['capabilities'][$field]['#value']);
    }
    $server = json_decode(ServerMetadataController::create($this->container)->__invoke()->getContent());
    $openid = json_decode(OpenIdConfigurationController::create($this->container)->configuration()->getContent());
    $this->assertSame($expected['ui_locales_supported'], $server->ui_locales_supported);
    $this->assertSame($expected['ui_locales_supported'], $openid->ui_locales_supported);
    $this->assertSame($expected['additional_claims_supported'], $server->additional_claims_supported);
    $this->assertSame(1, count(array_keys($openid->claims_supported, 'custom', TRUE)));
    $this->assertContains('0', $openid->claims_supported);
    $this->assertSame(['RS256', 'ES256', 'RS384', 'ES256'], $openid->id_token_signing_alg_values_supported);
  }

  /**
   * Tests a single zero claim survives actual form validation and submission.
   */
  public function testTextareaZeroClaimSubmission(): void {
    $state = (new FormState())->setValues(['additional_claims_supported' => '0']);
    $this->container->get('form_builder')->submitForm(ServerMetadataSettingsForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame(['0'], $state->getValue('additional_claims_supported'));
    $this->assertSame(['0'], $this->config('simple_oauth_server_metadata.settings')->get('additional_claims_supported'));
  }

  /**
   * Tests omitted external values and explicitly empty text areas separately.
   */
  public function testTextareaPartialAndEmptySubmissions(): void {
    $values = [
      'ui_locales_supported' => ['fr', 'en'],
      'additional_claims_supported' => ['custom', '0'],
      'additional_signing_algorithms' => ['ES256', 'RS384'],
    ];
    $config = $this->config('simple_oauth_server_metadata.settings');
    foreach ($values as $field => $value) {
      $config->set($field, $value);
    }
    $config->set('response_types_supported', ['id_token'])->save();
    $builder = $this->container->get('form_builder');
    $form = $builder->getForm(ServerMetadataSettingsForm::class);
    $object = ServerMetadataSettingsForm::create($this->container);
    // External callers may validate and submit only the provided values.
    $state = (new FormState())->setValues([
      'service_documentation' => 'https://updated.example/docs',
      'response_types_supported' => NULL,
    ]);
    $object->validateForm($form, $state);
    $this->assertSame([], $state->getErrors());
    foreach ($values as $field => $value) {
      $this->assertArrayNotHasKey($field, $state->getValues());
    }
    $object->submitForm($form, $state);
    $config = $this->config($config->getName());
    foreach ($values as $field => $value) {
      $this->assertSame($value, $config->get($field));
    }
    $this->assertSame(['id_token'], $config->get('response_types_supported'));
    $this->assertSame('https://updated.example/docs', $config->get('service_documentation'));

    $state = (new FormState())->setValues(['service_documentation' => 'https://partial.example/docs']);
    $builder->submitForm(ServerMetadataSettingsForm::class, $state);
    $this->assertSame([], $state->getErrors());
    foreach ($values as $field => $value) {
      $this->assertSame($value, $this->config($config->getName())->get($field));
    }
    $state = (new FormState())->setValues([
      'ui_locales_supported' => '',
      'additional_claims_supported' => " \n\n ",
      'additional_signing_algorithms' => '',
    ]);
    $builder->submitForm(ServerMetadataSettingsForm::class, $state);
    $this->assertSame([], $state->getErrors());
    foreach ($values as $field => $value) {
      $this->assertSame([], $state->getValue($field));
      $this->assertNull($this->config($config->getName())->get($field));
    }
  }

  /**
   * Tests locale and signing algorithm validation still rejects invalid input.
   *
   * @param string $field
   *   The textarea field to submit.
   * @param string $value
   *   The invalid textarea contents.
   */
  #[DataProvider('invalidTextareaProvider')]
  public function testTextareaValidation(string $field, string $value): void {
    $storage = $this->container->get('config.storage');
    $before = serialize($storage->read('simple_oauth_server_metadata.settings'));
    $state = (new FormState())->setValues([$field => $value]);
    $this->container->get('form_builder')->submitForm(ServerMetadataSettingsForm::class, $state);
    $this->assertArrayHasKey($field, $state->getErrors());
    $this->assertSame($before, serialize($storage->read('simple_oauth_server_metadata.settings')));
  }

  /**
   * Lists invalid locales and algorithms, including nonempty zero strings.
   *
   * @return array<string, array{string, string}>
   *   Invalid textarea submissions.
   */
  public static function invalidTextareaProvider(): array {
    return [
      'locale syntax' => ['ui_locales_supported', "en\n\nen_US"],
      'locale zero' => ['ui_locales_supported', '0'],
      'algorithm syntax' => ['additional_signing_algorithms', "RS256\n\nnone"],
      'algorithm zero' => ['additional_signing_algorithms', '0'],
    ];
  }

  /**
   * Tests saved sparse lists project as JSON arrays without changing storage.
   */
  public function testSparseConfiguredLists(): void {
    $values = [
      'ui_locales_supported' => [2 => 'fr', 5 => 'en', 8 => 'fr'],
      'additional_claims_supported' => [2 => 'custom', 5 => '0', 8 => 'custom'],
      'additional_signing_algorithms' => [2 => 'ES256', 5 => 'RS384', 8 => 'ES256'],
    ];
    $config = $this->config('simple_oauth_server_metadata.settings');
    foreach ($values as $field => $value) {
      $config->set($field, $value);
    }
    $config->save();
    $storage = $this->container->get('config.storage');
    $before = serialize($storage->read($config->getName()));
    $factory = $this->container->get('config.factory');
    $source = serialize($factory->get($config->getName())->getRawData());
    $server = ServerMetadataController::create($this->container);
    $openid = OpenIdConfigurationController::create($this->container);
    for ($generation = 0; $generation < 2; $generation++) {
      $response = $server();
      $metadata = json_decode($response->getContent());
      foreach ($values as $field => $value) {
        $this->assertSame(array_values($value), $metadata->$field);
      }
      $this->assertContains('config:' . $config->getName(), $response->getCacheableMetadata()->getCacheTags());
      $response = $openid->configuration();
      $metadata = json_decode($response->getContent());
      $this->assertSame(['fr', 'en', 'fr'], $metadata->ui_locales_supported);
      $this->assertSame(['RS256', 'ES256', 'RS384', 'ES256'], $metadata->id_token_signing_alg_values_supported);
      $this->assertSame(['custom', '0'], array_slice($metadata->claims_supported, -2));
      $this->assertContains('config:' . $config->getName(), $response->getCacheableMetadata()->getCacheTags());
      $preview = $this->container->get('simple_oauth_server_metadata.server_metadata')->getServerMetadata([
        'ui_locales_supported' => [8 => 'de', 3 => 'en'],
      ]);
      $this->assertSame(['de', 'en'], $preview['ui_locales_supported']);
      $this->assertSame($source, serialize($factory->get($config->getName())->getRawData()));
      $this->assertSame($before, serialize($storage->read($config->getName())));
    }
  }

  /**
   * Tests sparse discovery collaborator results retain list order and contents.
   */
  public function testSparseDiscoveryLists(): void {
    $grant_values = [
      'getResponseTypesSupported' => ['response_types_supported', [2 => 'code']],
      'getResponseModesSupported' => ['response_modes_supported', [2 => 'query', 5 => 'fragment', 8 => 'form_post']],
      'getGrantTypesSupported' => ['grant_types_supported', [2 => 'authorization_code', 5 => 'refresh_token']],
    ];
    $claim_values = [
      'getClaimsSupported' => ['claims_supported', [2 => 'sub', 5 => 'custom']],
      'getSubjectTypesSupported' => ['subject_types_supported', [2 => 'public']],
      'getIdTokenSigningAlgValuesSupported' => ['id_token_signing_alg_values_supported', [2 => 'RS256']],
      'getTokenEndpointAuthMethodsSupported' => [
        'token_endpoint_auth_methods_supported',
        [2 => 'client_secret_post', 5 => 'client_secret_basic'],
      ],
      'getTokenEndpointAuthSigningAlgValuesSupported' => [
        'token_endpoint_auth_signing_alg_values_supported',
        [2 => 'RS256'],
      ],
      'getCodeChallengeMethodsSupported' => ['code_challenge_methods_supported', [2 => 'S256', 5 => 'plain']],
      'getClaimsLocalesSupported' => ['claims_locales_supported', [2 => 'fr', 5 => 'en']],
    ];
    $grant = $this->createMock(GrantTypeDiscoveryService::class);
    foreach ($grant_values as $method => [$field, $value]) {
      $grant->method($method)->willReturn($value);
    }
    $factory = $this->container->get('config.factory');
    $claims = $this->getMockBuilder(ClaimsAuthDiscoveryService::class)
      ->setConstructorArgs([$factory])
      ->onlyMethods(array_keys($claim_values))
      ->getMock();
    foreach ($claim_values as $method => [$field, $value]) {
      $claims->method($method)->willReturn($value);
    }
    $scope = $this->createMock(ScopeDiscoveryService::class);
    $scope->method('getScopesSupported')->willReturn([2 => 'profile', 5 => 'openid', 8 => 'profile']);
    $scope->method('getCacheTags')->willReturn(['external:scope']);
    $scope->method('getCacheContexts')->willReturn(['url.query_args:scope']);
    $scope->method('getCacheMaxAge')->willReturn(120);
    $this->container->set('simple_oauth_server_metadata.grant_type_discovery', $grant);
    $this->container->set('simple_oauth_server_metadata.claims_auth_discovery', $claims);
    $this->container->set('simple_oauth_server_metadata.scope_discovery', $scope);
    $server = ServerMetadataController::create($this->container);
    $openid = OpenIdConfigurationController::create($this->container);
    for ($generation = 0; $generation < 2; $generation++) {
      foreach (['server' => $server(), 'openid' => $openid->configuration()] as $name => $response) {
        $metadata = json_decode($response->getContent());
        foreach ($grant_values + $claim_values as [$field, $value]) {
          if ($field === 'claims_locales_supported' && $name === 'server') {
            continue;
          }
          if ($field === 'claims_supported' && $name === 'openid') {
            $this->assertIsArray($metadata->$field);
            $this->assertSame(array_values($value), array_slice($metadata->$field, 0, 2));
          }
          else {
            $this->assertSame(array_values($value), $metadata->$field);
          }
        }
        $this->assertSame($name === 'server' ? ['profile', 'openid', 'profile'] : ['profile', 'openid'], $metadata->scopes_supported);
        $this->assertSame(120, $response->getCacheableMetadata()->getCacheMaxAge());
        $this->assertContains('external:scope', $response->getCacheableMetadata()->getCacheTags());
        $this->assertContains('url.query_args:scope', $response->getCacheableMetadata()->getCacheContexts());
      }
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
   * Tests event extension values survive filtering in cacheable JSON responses.
   */
  public function testResourceExtensionValues(): void {
    $values = [
      'custom_false' => FALSE,
      'custom_zero' => 0,
      'custom_float_zero' => 0.0,
      'custom_string_zero' => '0',
      'custom_true' => TRUE,
      'custom_number' => 42,
      'custom_object' => ['enabled' => FALSE, 'count' => 0, 'empty' => NULL, 'items' => [2 => 'a', 5 => 'b']],
      'custom_empty_object' => new \stdClass(),
    ];
    $dispatcher = $this->container->get('event_dispatcher');
    $calls = 0;
    $seen = [];
    $dispatcher->addListener(ResourceMetadataEvents::BUILD, static function (ResourceMetadataEvent $event) use ($values, &$calls): void {
      $calls++;
      $event->metadata['authorization_servers'] = [2 => 'https://second.example', 5 => 'https://first.example'];
      $event->metadata['bearer_methods_supported'] = [2 => 'query', 5 => 'header'];
      foreach ($values + ['absent_null' => NULL, 'absent_string' => '', 'absent_array' => []] as $field => $value) {
        $event->addMetadataField($field, $value);
      }
      $event->addCacheableDependency((new CacheableMetadata())->setCacheTags(['extension:' . $calls])->setCacheContexts(['url.query_args:extension'])->setCacheMaxAge($calls === 1 ? 0 : 120));
    }, 100);
    $dispatcher->addListener(ResourceMetadataEvents::BUILD, static function (ResourceMetadataEvent $event) use (&$seen): void {
      $seen = $event->metadata;
    }, -100);
    $service = new class ($this->container->get('config.factory'), $this->container->get('simple_oauth_server_metadata.endpoint_discovery'), $dispatcher) extends ResourceMetadataService {

      /**
       * The subscriber-provided metadata received by the protected filter.
       */
      public array $beforeFilter = [];

      /**
       * {@inheritdoc}
       */
      protected function filterEmptyFields(array $metadata): array {
        $this->beforeFilter = $metadata;
        $metadata = parent::filterEmptyFields($metadata);
        // Extending filters can also supply known list values.
        $metadata['bearer_methods_supported'] = [2 => 'query', 5 => 'header'];
        return $metadata;
      }

    };
    $controller = new ResourceMetadataController($service);
    foreach ([0, 120] as $generation => $max_age) {
      $response = $controller();
      $metadata = json_decode($response->getContent());
      $expected_keys = array_diff(array_keys($seen), ['absent_null', 'absent_string', 'absent_array']);
      $this->assertSame(array_values($expected_keys), array_keys(get_object_vars($metadata)));
      foreach ($values as $field => $value) {
        // JSON preserves numeric zero's meaning, but not its float precision.
        $this->assertSame(json_encode($value), json_encode($metadata->$field));
      }
      $this->assertSame(['https://second.example', 'https://first.example'], $metadata->authorization_servers);
      $this->assertSame(['query', 'header'], $metadata->bearer_methods_supported);
      $this->assertSame([2 => 'query', 5 => 'header'], $seen['bearer_methods_supported']);
      $this->assertSame($seen, $service->beforeFilter);
      $this->assertSame($max_age, $response->getCacheableMetadata()->getCacheMaxAge());
      $this->assertContains('extension:' . ($generation + 1), $response->getCacheableMetadata()->getCacheTags());
      $this->assertNotContains('extension:' . $generation, $response->getCacheableMetadata()->getCacheTags());
      $this->assertContains('url.query_args:extension', $response->getCacheableMetadata()->getCacheContexts());
      $this->assertContains('config:simple_oauth_server_metadata.settings', $response->getCacheableMetadata()->getCacheTags());
    }
    $this->assertSame(2, $calls);
  }

  /**
   * Tests required empty resource fields remain available for validation.
   */
  public function testResourceExtensionRequiredFields(): void {
    $this->container->get('event_dispatcher')->addListener(ResourceMetadataEvents::BUILD, static function (ResourceMetadataEvent $event): void {
      $event->metadata['resource'] = '';
      $event->metadata['authorization_servers'] = [];
    });
    $service = $this->container->get('simple_oauth_server_metadata.resource_metadata');
    $metadata = $service->getResourceMetadata();
    $this->assertSame('', $metadata['resource']);
    $this->assertSame([], $metadata['authorization_servers']);
    $this->assertFalse($service->validateMetadata($metadata));
    $this->expectException(ServiceUnavailableHttpException::class);
    ResourceMetadataController::create($this->container)->__invoke();
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
   * Tests issuer claims from upstream signing through real resource validation.
   */
  public function testAuthenticatedIssuerConsistency(): void {
    foreach (['user', 'consumer', 'oauth2_token'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $user = User::create(['name' => 'owner', 'status' => 1]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    $consumer = Consumer::create(['label' => 'issuer', 'client_id' => 'issuer']);
    $consumer->save();
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $private_key);
    $public_path = $this->siteDirectory . '/issuer-public.key';
    file_put_contents($public_path, openssl_pkey_get_details($key)['key']);
    $this->config('simple_oauth.settings')->set('public_key', $public_path)->save();
    $stack = $this->container->get('request_stack');
    $controller = TokenIntrospectionController::create($this->container);
    $lookup = $this->container->get('simple_oauth_server_metadata.token_lookup');
    $repository = $this->container->get('simple_oauth.repositories.access_token');
    foreach (['http://first.example/subdir', 'http://second.example/other'] as $site) {
      $request = Request::create($site . '/oauth/introspect', 'POST', [], [], [], [
        'SCRIPT_NAME' => parse_url($site, PHP_URL_PATH) . '/index.php',
        'SCRIPT_FILENAME' => '/index.php',
      ]);
      $stack->push($request);
      $this->container->get('router.request_context')->fromRequest($request);
      try {
        $default = $this->container->get('simple_oauth_server_metadata.endpoint_discovery')->getIssuer();
        $this->assertSame(str_replace('http:', 'https:', $site) . '/', $default);
        $this->assertSame($default, $this->container->get('simple_oauth_server_metadata.server_metadata')->getServerMetadata()['issuer']);
        $this->assertSame($default, $this->container->get('simple_oauth_server_metadata.openid_configuration')->getOpenIdConfiguration()['issuer']);
        $refresh = Oauth2Token::create([
          'bundle' => 'refresh_token',
          'auth_user_id' => $user->id(),
          'value' => bin2hex(random_bytes(20)),
          'expire' => time() + 3600,
        ]);
        $refresh->save();
        $payload = [
          'refresh_token_id' => $refresh->get('value')->value,
          'client_id' => $consumer->getClientId(),
          'expire_time' => time() + 3600,
          'scopes' => [],
        ];
        $opaque = Crypto::encryptWithPassword(json_encode($payload), Core::ourSubstr(Settings::getHashSalt(), 0, 32));
        $request->request->set('token', $opaque);
        $this->assertSame($default, json_decode($controller->introspect($request)->getContent(), TRUE)['iss']);
        $legacy = new TokenIntrospectionController($stack, $this->container->get('logger.channel.simple_oauth'), $lookup);
        $this->assertSame($default, json_decode($legacy->introspect($request)->getContent(), TRUE)['iss']);
        foreach ([NULL, 'https://custom.example/tenant'] as $custom) {
          if ($custom === NULL) {
            $issued = $repository->getNewToken(new ClientEntity($consumer), [], (string) $user->id());
          }
          else {
            $handler = $this->createMock(ModuleHandlerInterface::class);
            $handler->method('alter')->willReturnCallback(static function ($type, &$claims) use ($custom): void {
              $claims['iss'] = $custom;
            });
            $issued = new AccessTokenEntity($handler, $this->container->get('datetime.time'), $this->container->get('language_manager'));
            $issued->setClient(new ClientEntity($consumer));
            $issued->setUserIdentifier((string) $user->id());
          }
          $issued->setIdentifier(bin2hex(random_bytes(20)));
          $issued->setExpiryDateTime(new \DateTimeImmutable('+1 hour'));
          $issued->setPrivateKey(new CryptKey($private_key, NULL, FALSE));
          $repository->persistNewAccessToken($issued);
          $value = $issued->toString();
          $expected = $custom ?? $default;
          $this->assertSame($expected, (new Parser(new JoseEncoder()))->parse($value)->claims()->get('iss'));
          $this->assertSame($expected, $lookup->resolveToken($value)['issuer'] ?? NULL);
          $request->request->set('token', $value);
          $this->assertSame($expected, json_decode($controller->introspect($request)->getContent(), TRUE)['iss']);
          // A signed issuer remains usable without any request-stack context.
          $standalone = new TokenIntrospectionController(new RequestStack(), $this->container->get('logger.channel.simple_oauth'), $lookup, $this->container->get('current_user'));
          $this->assertSame($expected, json_decode($standalone->introspect($request)->getContent(), TRUE)['iss']);
          $parts = explode('.', $value);
          $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), TRUE);
          $claims['iss'] = 'https://forged.example';
          $parts[1] = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
          foreach ([implode('.', $parts), $issued->getIdentifier()] as $invalid) {
            $request->request->set('token', $invalid);
            $this->assertNull($lookup->resolveToken($invalid));
            $this->assertSame(['active' => FALSE], json_decode($controller->introspect($request)->getContent(), TRUE));
          }
          // A different authenticated caller cannot see the signed issuer.
          $other = User::create(['name' => 'other-' . $issued->getIdentifier(), 'status' => 1]);
          $other->save();
          $this->container->get('current_user')->setAccount($other);
          $request->request->set('token', $value);
          $this->assertSame(['active' => FALSE], json_decode($controller->introspect($request)->getContent(), TRUE));
          $this->container->get('current_user')->setAccount($user);
          // Reuse an authenticated record with a genuinely signed missing-iss
          // JWT. This fixture is independent of upstream token serialization.
          $jwt_config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($private_key));
          $missing_issuer = $jwt_config->builder()
            ->permittedFor($consumer->getClientId())
            ->identifiedBy($issued->getIdentifier())
            ->relatedTo((string) $user->id())
            ->issuedAt(new \DateTimeImmutable('-1 minute'))
            ->expiresAt(new \DateTimeImmutable('+1 hour'))
            ->getToken($jwt_config->signer(), $jwt_config->signingKey())->toString();
          $this->assertNull($lookup->resolveToken($missing_issuer)['issuer']);
          $request->request->set('token', $missing_issuer);
          $this->assertSame($default, json_decode($controller->introspect($request)->getContent(), TRUE)['iss']);
          $this->assertArrayNotHasKey('iss', json_decode($standalone->introspect($request)->getContent(), TRUE));
          $issued->setExpiryDateTime(new \DateTimeImmutable('-1 minute'));
          $this->assertNull($lookup->resolveToken($issued->toString()));
          // A valid signature cannot bypass stored client ownership checks.
          $foreign = Consumer::create(['label' => 'foreign', 'client_id' => 'foreign-' . $issued->getIdentifier()]);
          $foreign->save();
          $records = $this->container->get('entity_type.manager')->getStorage('oauth2_token')->loadByProperties(['value' => $issued->getIdentifier()]);
          $record = reset($records);
          $record->set('client', $foreign->id())->save();
          $this->assertNull($lookup->resolveToken($value));
          $record->set('client', $consumer->id())->save();
          $repository->revokeAccessToken($issued->getIdentifier());
          $this->assertNull($lookup->resolveToken($value));
        }
      }
      finally {
        $stack->pop();
      }
    }
  }

  /**
   * Tests optional URLs share normalization and preserve stored raw values.
   */
  public function testOptionalUrlConsistency(): void {
    $config = $this->config('simple_oauth_server_metadata.settings');
    $fields = ['service_documentation', 'op_policy_uri', 'op_tos_uri'];
    $stack = $this->container->get('request_stack');
    foreach (['https://first.example/subdir', 'https://second.example/other'] as $site) {
      $request = Request::create($site . '/discovery', 'GET', [], [], [], [
        'SCRIPT_NAME' => parse_url($site, PHP_URL_PATH) . '/index.php',
        'SCRIPT_FILENAME' => '/index.php',
      ]);
      $stack->push($request);
      $this->container->get('router.request_context')->fromRequest($request);
      try {
        foreach (['/docs', 'https://external.example/docs', ''] as $raw) {
          foreach ($fields as $field) {
            $config->set($field, $raw);
          }
          $config->save();
          $stored = $this->container->get('config.storage')->read($config->getName());
          foreach ([
            ServerMetadataController::create($this->container)(),
            OpenIdConfigurationController::create($this->container)->configuration(),
          ] as $response) {
            $metadata = json_decode($response->getContent(), TRUE);
            foreach ($fields as $field) {
              if ($raw === '') {
                $this->assertArrayNotHasKey($field, $metadata);
              }
              else {
                $this->assertSame($raw === '/docs' ? $site . '/docs' : $raw, $metadata[$field]);
              }
            }
            $this->assertContains('url.site', $response->getCacheableMetadata()->getCacheContexts());
            $this->assertContains('config:' . $config->getName(), $response->getCacheableMetadata()->getCacheTags());
          }
          $this->assertSame($stored, $this->container->get('config.storage')->read($config->getName()));
          foreach ($fields as $field) {
            $this->assertSame($raw, $this->container->get('config.factory')->get($config->getName())->get($field));
          }
          $preview = $this->container->get('simple_oauth_server_metadata.server_metadata')->getServerMetadata(['service_documentation' => '/preview']);
          $this->assertSame($site . '/preview', $preview['service_documentation']);
        }
      }
      finally {
        $stack->pop();
      }
    }
  }

  /**
   * Tests normally injected capability helpers track repeated discovery builds.
   */
  public function testCapabilityHelperConsistency(): void {
    $claims = $this->container->get('simple_oauth_server_metadata.claims_auth_discovery');
    foreach ([FALSE, TRUE, FALSE] as $disabled) {
      $this->config('simple_oauth.settings')->set('disable_openid_connect', $disabled)->save();
      $metadata = $this->container->get('simple_oauth_server_metadata.server_metadata')->getServerMetadata();
      $this->assertSame(in_array('code', $metadata['response_types_supported'], TRUE), $claims->getAuthorizationCodeFlowEnabled());
      $this->assertFalse($claims->getImplicitFlowEnabled());
      if (!$disabled) {
        $openid = $this->container->get('simple_oauth_server_metadata.openid_configuration')->getOpenIdConfiguration();
        $this->assertSame($openid['authorization_code_flow_enabled'], $claims->getAuthorizationCodeFlowEnabled());
        $this->assertSame($openid['implicit_flow_enabled'], $claims->getImplicitFlowEnabled());
      }
      $this->assertContains('config:simple_oauth.settings', $claims->getCacheTags());
      $this->assertContains('config:core.extension', $claims->getCacheTags());
    }
  }

  /**
   * Tests revocation privacy, session header checks and legacy constructors.
   */
  public function testRevocationAuthorityAndPrivacy(): void {
    foreach (['user', 'consumer', 'oauth2_token'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    User::create(['name' => 'reserved'])->save();
    $role = Role::create(['id' => 'revoker', 'label' => 'Revoker']);
    $role->grantPermission('bypass token revocation restrictions')->save();
    $user = User::create(['name' => 'revoker', 'status' => 1, 'roles' => [$role->id()]]);
    $user->save();
    $this->assertNotSame('1', (string) $user->id());
    $this->assertTrue($user->hasPermission('bypass token revocation restrictions'));
    $this->container->get('current_user')->setAccount($user);
    $owner = Consumer::create(['label' => 'Owner', 'client_id' => 'owner', 'confidential' => FALSE]);
    $owner->save();
    $foreign = Consumer::create(['label' => 'Foreign', 'client_id' => 'foreign', 'confidential' => FALSE]);
    $foreign->save();
    $controller = TokenRevocationController::create($this->container);
    $legacy = new TokenRevocationController(
      $this->container->get('simple_oauth_server_metadata.client_authentication'),
      $this->container->get('simple_oauth_server_metadata.token_revocation'),
      $this->container->get('psr7.http_message_factory'),
      $this->container->get('logger.channel.simple_oauth'),
    );
    $csrf = CsrfTokenController::create($this->container)->csrfToken()->getContent();
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $private_key);
    $public_path = $this->siteDirectory . '/revocation-public.key';
    file_put_contents($public_path, openssl_pkey_get_details($key)['key']);
    $this->config('simple_oauth.settings')->set('public_key', $public_path)->save();
    $jwt_config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($private_key));
    $storage = $this->container->get('entity_type.manager')->getStorage('oauth2_token');
    foreach (['access_token', 'refresh_token'] as $type) {
      $token = Oauth2Token::create([
        'bundle' => $type,
        'auth_user_id' => $user->id(),
        'client' => $owner->id(),
        'value' => bin2hex(random_bytes(20)),
        'expire' => time() + 3600,
      ]);
      $token->save();
      $value = $type === 'access_token'
        ? $jwt_config->builder()->issuedBy('https://issuer.example')
          ->permittedFor($owner->getClientId())->identifiedBy($token->get('value')->value)
          ->relatedTo((string) $user->id())->issuedAt(new \DateTimeImmutable('-1 minute'))
          ->expiresAt(new \DateTimeImmutable('+1 hour'))
          ->getToken($jwt_config->signer(), $jwt_config->signingKey())->toString()
        : Crypto::encryptWithPassword(json_encode([
          'refresh_token_id' => $token->get('value')->value,
          'client_id' => $owner->getClientId(),
          'expire_time' => time() + 3600,
          'scopes' => [],
        ]), Core::ourSubstr(Settings::getHashSalt(), 0, 32));
      $this->assertNotNull($this->container->get('simple_oauth_server_metadata.token_lookup')->resolveToken($value));
      $request = Request::create('https://issuer.example/oauth/revoke', 'POST', [
        'token' => $value,
        'client_id' => $foreign->getClientId(),
      ]);
      $cookie_name = $this->container->get('session_configuration')->getOptions($request)['name'];
      $request->cookies->set($cookie_name, 'session-fixture');
      foreach ([$controller, $legacy] as $entry) {
        foreach ([NULL, 'invalid', $csrf] as $header) {
          if ($header === NULL) {
            $request->headers->remove('X-CSRF-Token');
          }
          else {
            $request->headers->set('X-CSRF-Token', $header);
          }
          // The legacy constructor always enforces ownership, even with CSRF.
          if ($entry === $controller && $header === $csrf) {
            continue;
          }
          $response = $entry->revoke($request);
          $this->assertSame(200, $response->getStatusCode());
          $this->assertSame('', $response->getContent());
          $storage->resetCache([$token->id()]);
          $this->assertFalse($storage->load($token->id())->isRevoked());
        }
        $request->request->set('client_id', $owner->getClientId());
        $request->headers->remove('X-CSRF-Token');
        $this->assertSame(200, $entry->revoke($request)->getStatusCode());
        $storage->resetCache([$token->id()]);
        $record = $storage->load($token->id());
        $this->assertTrue($record->isRevoked());
        $record->set('status', TRUE)->save();
        $request->request->set('client_id', $foreign->getClientId());
      }
      // Expired, raw, forged and already revoked values stay privacy successes.
      $parts = explode('.', $value);
      $parts[count($parts) - 1] = 'forged';
      foreach (['invalid', $token->get('value')->value, implode('.', $parts)] as $invalid) {
        $request->request->set('token', $invalid);
        $request->headers->set('X-CSRF-Token', $csrf);
        $this->assertSame(200, $controller->revoke($request)->getStatusCode());
        $storage->resetCache([$token->id()]);
        $this->assertFalse($storage->load($token->id())->isRevoked());
      }
      $request->request->set('token', $value);
      $record = $storage->load($token->id());
      $record->set('expire', time() - 60)->save();
      $this->assertSame(200, $controller->revoke($request)->getStatusCode());
      $storage->resetCache([$token->id()]);
      $this->assertFalse($storage->load($token->id())->isRevoked());
      $record = $storage->load($token->id());
      $record->set('expire', time() + 3600)->save();
      $this->assertSame(200, $controller->revoke($request)->getStatusCode());
      $storage->resetCache([$token->id()]);
      $this->assertTrue($storage->load($token->id())->isRevoked());
      $this->assertSame(200, $controller->revoke($request)->getStatusCode());
      $request->request->remove('token');
      $this->assertSame(400, $controller->revoke($request)->getStatusCode());
      $request->request->remove('client_id');
      $this->assertSame(401, $controller->revoke($request)->getStatusCode());
    }
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
        if ($data['active']) {
          $expected = $this->container->get('simple_oauth_server_metadata.endpoint_discovery')->getIssuer();
          $this->assertSame($expected, $data['iss']);
          $legacy = new TokenIntrospectionController($request_stack, $this->container->get('logger.channel.simple_oauth'), $this->container->get('simple_oauth_server_metadata.token_lookup'));
          $post = Request::create('/oauth/introspect', 'POST', ['token' => $value]);
          $this->assertSame($expected, json_decode($legacy->introspect($post)->getContent(), TRUE)['iss']);
          $without_context = new TokenIntrospectionController(new RequestStack(), $this->container->get('logger.channel.simple_oauth'), $this->container->get('simple_oauth_server_metadata.token_lookup'), $current_user);
          $this->assertArrayNotHasKey('iss', json_decode($without_context->introspect($post)->getContent(), TRUE));
        }
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
