<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_native_apps\Kernel;

use Drupal\Core\Validation\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\Core\StringTranslation\Translator\TranslatorInterface;
use Drupal\consumers\Entity\Consumer;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_oauth_native_apps\Form\ConsumerNativeAppsFormAlter;
use Drupal\simple_oauth_native_apps\Service\ConsumerPolicyResolver;
use Drupal\simple_oauth_native_apps\Service\ConfigurationValidator;
use Drupal\simple_oauth_native_apps\Service\MetadataProvider;
use Drupal\simple_oauth_native_apps\Service\NativeClientDetector;
use Drupal\simple_oauth_native_apps\Service\PKCEEnhancementService;
use Drupal\simple_oauth_native_apps\Service\RedirectUriValidator;
use Drupal\simple_oauth_native_apps\Service\UserAgentAnalyzer;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Exception\UnexpectedValueException as ValidationUnexpectedValueException;
use Drupal\simple_oauth_native_apps\Plugin\Validation\Constraint\NativeAppRedirectUri;

/**
 * Tests service integration for native apps module.
 */
#[Group('simple_oauth_native_apps')]
final class ServiceIntegrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'image',
    'consumers',
    'simple_oauth',
    'simple_oauth_native_apps',
    'serialization',
    'options',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('consumer');
    $this->installConfig(['simple_oauth', 'simple_oauth_native_apps']);
  }

  /**
   * Tests real configuration override cacheability and recovery per generation.
   */
  public function testMetadataOverrideCacheability(): void {
    $factory = $this->container->get('config.factory');
    $values = [];
    $cacheability = new CacheableMetadata();
    $override = $this->createMock(ConfigFactoryOverrideInterface::class);
    $override->method('getCacheSuffix')->willReturn('native_metadata_test');
    $override->method('loadOverrides')->willReturnCallback(static function (array $names) use (&$values): array {
      return in_array('simple_oauth_native_apps.settings', $names, TRUE)
        ? ['simple_oauth_native_apps.settings' => $values] : [];
    });
    $override->method('getCacheableMetadata')->willReturnCallback(static function (string $name) use (&$cacheability): CacheableMetadata {
      return $cacheability;
    });
    $factory->addOverride($override);
    $provider = $this->container->get('simple_oauth_native_apps.metadata_provider');
    foreach ([
      [FALSE, ['user.permissions'], ['native_override:first'], 0],
      [TRUE, ['languages:language_interface'], ['native_override:second'], 120],
      [TRUE, [], [], -1],
    ] as [$enabled, $contexts, $tags, $max_age]) {
      $values = ['enforce_native_security' => $enabled];
      $cacheability = (new CacheableMetadata())
        ->setCacheContexts($contexts)->setCacheTags($tags)->setCacheMaxAge($max_age);
      $factory->reset();
      for ($iteration = 0; $iteration < 2; $iteration++) {
        $metadata = $provider->getMetadata();
        $this->assertSame($enabled, $metadata['enhanced_native_security']);
        $this->assertSame($enabled, $metadata['native_apps_pkce_required']);
        $response = new CacheableJsonResponse($metadata);
        $response->addCacheableDependency($provider);
        $actual = $response->getCacheableMetadata();
        $this->assertEqualsCanonicalizing($contexts, $actual->getCacheContexts());
        $this->assertEqualsCanonicalizing(array_merge($tags, [
          'config:simple_oauth_native_apps.settings', 'config:simple_oauth.settings',
        ]), $actual->getCacheTags());
        $this->assertSame($max_age, $actual->getCacheMaxAge());
      }
    }
  }

  /**
   * Tests bounded candidate loads across resolvers and live config mutations.
   */
  public function testIndexedPolicyLookupWorkAndFreshness(): void {
    [$owner, $unrelated, $name, $data] = $this->createBoundPolicyCollision();
    for ($index = 1000; $index < 1050; $index++) {
      $this->config(ConsumerPolicyResolver::PREFIX . $index)->setData([
        'consumer_uuid' => \Drupal::service('uuid')->generate(),
        'enhanced_pkce_override' => 'enhanced',
      ])->save();
    }
    $real_factory = $this->container->get('config.factory');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $lists = 0;
    $loads = [];
    $factory->method('listAll')->willReturnCallback(static function (string $prefix) use ($real_factory, &$lists): array {
      $lists++;
      return $real_factory->listAll($prefix);
    });
    $factory->method('loadMultiple')->willReturnCallback(static function (array $names) use ($real_factory, &$loads): array {
      $loads[] = count($names);
      return $real_factory->loadMultiple($names);
    });
    $factory->method('get')->willReturnCallback($real_factory->get(...));
    $cache = $this->container->get('cache.default');
    $resolver = new ConsumerPolicyResolver($factory, $cache, $this->container->get('cache_tags.invalidator.checksum'));
    $this->assertSame($name, $resolver->resolve($owner)['name']);
    $this->assertSame(1, $lists);
    $this->assertSame(51, $loads[0]);
    for ($iteration = 0; $iteration < 5; $iteration++) {
      // A fresh service also reuses the derived index.
      $fresh = new ConsumerPolicyResolver($factory, $cache, $this->container->get('cache_tags.invalidator.checksum'));
      $this->assertSame($data, $fresh->getPolicy($owner));
      $this->assertSame(2, $loads[array_key_last($loads)]);
      $this->assertSame([], $fresh->getPolicy($unrelated));
      $this->assertSame(1, $loads[array_key_last($loads)]);
    }
    $this->assertSame(1, $lists);
    $this->config($name)->set('consumer_uuid', $unrelated->uuid())->save();
    $this->assertNull($resolver->resolve($owner)['name']);
    $this->assertSame($name, $resolver->resolve($unrelated)['name']);
    $this->assertSame(2, $lists);
    $this->config($name)->delete();
    $this->assertNull($resolver->resolve($unrelated)['name']);
    $this->assertSame(3, $lists);
    $this->config($name)->setData($data)->save();
    $this->assertSame($name, $resolver->resolve($owner)['name']);
    $this->assertSame(4, $lists);
    $snapshot = [ConsumerPolicyResolver::PREFIX . '9000' => $data];
    $this->assertSame(array_key_first($snapshot), $resolver->resolve($owner, $snapshot)['name']);
    $this->assertSame(4, $lists);
    $this->config(ConsumerPolicyResolver::PREFIX . $owner->id())->set('consumer_uuid', 'broken')->save();
    try {
      $resolver->resolve($owner);
      $this->fail('Malformed local slots must reject even with a UUID match.');
    }
    catch (\UnexpectedValueException $exception) {
      $this->assertStringContainsString('malformed', $exception->getMessage());
    }
    $this->config(ConsumerPolicyResolver::PREFIX . $owner->id())->delete();
    $invalid_name = ConsumerPolicyResolver::PREFIX . 'invalid';
    $this->config($invalid_name)->setData($data)->save();
    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('numeric');
    $resolver->resolve($owner);
  }

  /**
   * Tests a write during index construction cannot publish a valid stale index.
   */
  public function testPolicyIndexConcurrentInvalidation(): void {
    [$owner, , $name, $data] = $this->createBoundPolicyCollision();
    $real_factory = $this->container->get('config.factory');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('listAll')->willReturnCallback($real_factory->listAll(...));
    $first_load = TRUE;
    $factory->method('loadMultiple')->willReturnCallback(function (array $names) use ($real_factory, $data, &$first_load): array {
      $loaded = $real_factory->loadMultiple($names);
      if ($first_load) {
        $first_load = FALSE;
        $this->config(ConsumerPolicyResolver::PREFIX . '9000')->setData($data)->save();
      }
      return $loaded;
    });
    $resolver = new ConsumerPolicyResolver($factory, $this->container->get('cache.default'), $this->container->get('cache_tags.invalidator.checksum'));
    // This read started before the competing policy was saved.
    $this->assertSame($name, $resolver->resolve($owner)['name']);
    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('Multiple');
    $resolver->resolve($owner);
  }

  /**
   * Tests runtime configuration overrides never select a different UUID owner.
   */
  public function testIndexedPolicyUsesRawOwnership(): void {
    [$owner, $unrelated, $name] = $this->createBoundPolicyCollision();
    $factory = $this->container->get('config.factory');
    $override = $this->createMock(ConfigFactoryOverrideInterface::class);
    $override->method('getCacheSuffix')->willReturn('native_policy_test');
    $override->method('loadOverrides')->willReturn([
      $name => ['consumer_uuid' => $unrelated->uuid(), 'enhanced_pkce_override' => 'enhanced'],
    ]);
    $override->method('getCacheableMetadata')->willReturn(new CacheableMetadata());
    $factory->addOverride($override);
    $factory->reset();
    $resolver = $this->container->get('simple_oauth_native_apps.consumer_policy_resolver');
    for ($iteration = 0; $iteration < 2; $iteration++) {
      $this->assertSame($name, $resolver->resolve($owner)['name']);
      $this->assertSame($owner->uuid(), $resolver->resolve($owner)['data']['consumer_uuid']);
      $this->assertSame('enhanced', $resolver->getPolicy($owner)['enhanced_pkce_override']);
      $this->assertNull($resolver->resolve($unrelated)['name']);
    }
    $restored = unserialize(serialize($resolver), ['allowed_classes' => [ConsumerPolicyResolver::class]]);
    $this->assertSame($name, $restored->resolve($owner)['name']);
    $this->assertSame('enhanced', $restored->getPolicy($owner)['enhanced_pkce_override']);
  }

  /**
   * Tests URI rules agree across direct, constraint and consumer entry points.
   */
  public function testSharedRedirectUriRules(): void {
    $validator = $this->container->get('simple_oauth_native_apps.redirect_uri_validator');
    $legacy = new RedirectUriValidator($this->container->get('config.factory'), $this->container->get('logger.channel.simple_oauth_native_apps'));
    $consumer = Consumer::create([
      'label' => 'URI rules',
      'client_id' => 'uri-rules',
      'native_app_override' => 'native',
    ]);
    $consumer->save();
    $constraint = new NativeAppRedirectUri();
    $standalone = Validation::createValidatorBuilder()
      ->setConstraintValidatorFactory(new ConstraintValidatorFactory($this->container->get('class_resolver')))
      ->getValidator();
    foreach ([
      'https://example.org/callback' => TRUE,
      'https://127.0.0.1:8443/callback' => TRUE,
      'https://[::1]:8443/callback' => TRUE,
      'http://127.0.0.1:49152/callback' => TRUE,
      'http://[::1]:49153/callback' => TRUE,
      'http://example.org/callback' => FALSE,
      'http://127.0.0.2:49152/callback' => FALSE,
      'nl.example.app:/callback' => TRUE,
      'technology.example.app:/callback' => TRUE,
      'nl.123example.123:/callback' => TRUE,
      'x.example.app:/callback' => TRUE,
      'xn--p1ai.example.app:/callback' => TRUE,
      'com.example.app://callback' => TRUE,
      'com..app:/callback' => FALSE,
      'com.-example.app:/callback' => FALSE,
      'com.example-.app:/callback' => FALSE,
      'com.example:/callback' => FALSE,
      'javascript:/callback' => FALSE,
      'data:/callback' => FALSE,
      'file:///callback' => FALSE,
      'ftp://example.org/callback' => FALSE,
      'https://user:pass@example.org/callback' => FALSE,
      'nl.example.app://user@example.org/callback' => FALSE,
      'https://example.org/callback#fragment' => FALSE,
      'nl.example.app:/callback#fragment' => FALSE,
      "nl.example.app:/call\nback" => FALSE,
      'nl.example.app:/callback%0a' => FALSE,
      'nl.example.app:/callback%zz' => FALSE,
      'https:///callback' => FALSE,
      'missing-scheme' => FALSE,
    ] as $uri => $expected) {
      $this->assertSame($expected, $validator->validateRedirectUri($uri), $uri);
      $this->assertSame($expected, $legacy->validateRedirectUri($uri), $uri);
      $this->assertSame($expected, $validator->validateConsumerRedirectUri($uri, $consumer), $uri);
      $violations = $standalone->validate($uri, $constraint);
      $this->assertSame($expected, count($violations) === 0, $uri);
    }
    $this->assertFalse($validator->validateLoopbackInterface('https://127.0.0.1/callback'));
    $this->config('simple_oauth_native_apps.settings')->set('allow.custom_uri_schemes', 'web')->set('allow.loopback_redirects', 'web')->save();
    $this->assertFalse($validator->validateRedirectUri('nl.example.app:/callback'));
    $this->assertFalse($validator->validateConsumerRedirectUri('http://127.0.0.1/callback', $consumer));
    $this->assertTrue($validator->validateConsumerRedirectUri('https://example.org/callback', $consumer));
    $this->config(ConsumerPolicyResolver::PREFIX . $consumer->id())
      ->set('allow_custom_schemes_override', 'native')->set('allow_loopback_override', 'native')->save();
    $this->assertTrue($validator->validateConsumerRedirectUri('nl.example.app:/callback', $consumer));
    $this->assertTrue($validator->validateConsumerRedirectUri('http://127.0.0.1/callback', $consumer));
    $this->config('simple_oauth_native_apps.settings')->set('enforce_native_security', FALSE)->save();
    $this->config(ConsumerPolicyResolver::PREFIX . $consumer->id())
      ->set('allow_custom_schemes_override', 'web')->set('allow_loopback_override', 'web')->save();
    $this->assertTrue($validator->validateConsumerRedirectUri('nl.example.app:/callback', $consumer));
    $this->assertTrue($validator->validateConsumerRedirectUri('http://127.0.0.1/callback', $consumer));
    $this->assertFalse($validator->validateRedirectUri('nl.example.app:/callback'));
    $this->assertTrue($validator->validateConsumerRedirectUri('https://example.org/callback', $consumer));
  }

  /**
   * Tests capability outputs describe enum policies and consumer overrides.
   */
  public function testCapabilityPolicyEnumsAndConsumerOverrides(): void {
    $native = Consumer::create([
      'label' => 'Native capability client',
      'client_id' => 'native-capability-client',
      'confidential' => FALSE,
      'redirect' => ['com.example.native://callback'],
    ]);
    $native->save();
    $ordinary = Consumer::create([
      'label' => 'Web capability client',
      'client_id' => 'web-capability-client',
      'confidential' => TRUE,
      'redirect' => ['https://example.com/callback'],
    ]);
    $ordinary->save();
    $config = $this->config('simple_oauth_native_apps.settings');
    $provider = $this->container->get('simple_oauth_native_apps.metadata_provider');
    $pkce = $this->container->get('simple_oauth_native_apps.pkce_enhancement');
    $cases = [
      'disabled' => ['web', 'web', 'not-enhanced', 'off', FALSE, FALSE, FALSE, FALSE],
      'native' => ['native', 'native', 'enhanced', 'S256', TRUE, TRUE, TRUE, TRUE],
      'automatic' => [
        'auto-detect', 'auto-detect', 'auto-detect', 'S256',
        TRUE, TRUE, TRUE, TRUE,
      ],
      'plain' => ['native', 'web', 'enhanced', 'plain', TRUE, FALSE, TRUE, FALSE],
    ];
    foreach ($cases as $name => $case) {
      [
        $custom, $loopback, $enhanced, $method,
        $custom_expected, $loopback_expected, $pkce_expected, $s256_expected,
      ] = $case;
      $config->set('enforce_native_security', TRUE)
        ->set('allow.custom_uri_schemes', $custom)
        ->set('allow.loopback_redirects', $loopback)
        ->set('native.enhanced_pkce', $enhanced)
        ->set('native.enforce', $method)
        ->save();
      $metadata = $provider->getMetadata();
      $this->assertSame($custom_expected, $metadata['custom_uri_schemes_supported'], $name);
      $this->assertSame($custom_expected, $metadata['private_use_uri_schemes_allowed'], $name);
      $this->assertSame($loopback_expected, $metadata['loopback_redirects_supported'], $name);
      $this->assertSame($loopback_expected, $metadata['terminal_applications_supported'], $name);
      $this->assertSame($pkce_expected, $metadata['pkce_required'], $name);
      $this->assertSame($pkce_expected, $metadata['native_apps_pkce_required'], $name);
      $this->assertSame($s256_expected, $metadata['pkce_s256_enforced'], $name);
      $this->assertSame($pkce_expected, $pkce->getConfiguration()['enhanced_pkce_enabled'], $name);
      $this->assertSame($method, $pkce->getConfiguration()['enforce_method'], $name);
      $this->assertSame($pkce_expected, $pkce->getConfiguration($native)['enhanced_pkce_enabled'], $name);
      $this->assertSame($s256_expected, $pkce->requiresS256Method($native), $name);
      $ordinary_expected = $enhanced === 'enhanced';
      $this->assertSame($ordinary_expected, $pkce->getConfiguration($ordinary)['enhanced_pkce_enabled'], $name);
      $this->assertFalse($pkce->requiresS256Method($ordinary), $name);
    }

    foreach ([
      ['not-enhanced', 'enhanced', TRUE],
      ['enhanced', 'not-enhanced', FALSE],
      ['auto-detect', 'not-enhanced', FALSE],
      ['not-enhanced', 'auto-detect', TRUE],
      ['enhanced', 'auto-detect', TRUE],
    ] as [$global, $override, $expected]) {
      $config->set('native.enhanced_pkce', $global)->set('native.enforce', 'S256')->save();
      $this->config('simple_oauth_native_apps.consumer.' . $native->id())
        ->set('enhanced_pkce_override', $override)->save();
      $this->assertSame($expected, $pkce->getConfiguration($native)['enhanced_pkce_enabled']);
      $this->assertSame($expected, $pkce->requiresS256Method($native));
    }
  }

  /**
   * Tests isolated overrides validate, persist and retain inherited settings.
   */
  public function testPartialConsumerOverridesPersistIndependently(): void {
    $consumer = Consumer::create([
      'label' => 'Partial override client',
      'client_id' => 'partial-override-client',
      'confidential' => FALSE,
      'redirect' => ['com.example.override://callback'],
    ]);
    $consumer->save();
    $form_object = $this->createMock(EntityFormInterface::class);
    $form_object->method('getEntity')->willReturn($consumer);
    $handler = $this->container->get('simple_oauth_native_apps.consumer_form_alter');
    $validator = $this->container->get('simple_oauth_native_apps.configuration_validator');
    $global = $this->config('simple_oauth_native_apps.settings')->get();
    $this->assertEmpty($validator->validateConfiguration($global));

    foreach ([
      ['allow_custom_schemes_override' => 'web'],
      ['allow_loopback_override' => 'web'],
      ['enhanced_pkce_override' => 'not-enhanced'],
    ] as $override) {
      $state = (new FormState())->setFormObject($form_object);
      $state->setValue('native_apps', $override);
      $handler->validateConsumerNativeAppsSettings([], $state);
      $this->assertFalse($state->hasAnyErrors());
      $handler->submitConsumerNativeAppsSettings([], $state);
      $name = 'simple_oauth_native_apps.consumer.' . $consumer->id();
      $this->container->get('config.factory')->reset($name);
      $stored = $this->container->get('config.factory')->get($name)->get();
      $this->assertSame($override, $stored);
      $this->assertArrayNotHasKey('webview_detection_override', $stored);
      $form = [];
      $handler->alterForm($form, $state, 'consumer_edit_form');
      foreach ($override as $field => $value) {
        $this->assertSame($value, $form['native_apps'][$field]['#default_value']);
      }
      $this->assertSame('', $form['native_apps']['webview_detection_override']['#default_value']);
      $this->assertSame($global, $this->config('simple_oauth_native_apps.settings')->get());
    }

    $state = (new FormState())->setFormObject($form_object);
    $state->setValue('native_apps', ['allow_custom_schemes_override' => 'invalid-policy']);
    $handler->validateConsumerNativeAppsSettings([], $state);
    $this->assertTrue($state->hasAnyErrors());
    $this->assertArrayHasKey('native_apps', $state->getErrors());
    $invalid_global = $global;
    $invalid_global['webview']['detection'] = 'invalid-policy';
    $this->assertNotEmpty($validator->validateConfiguration($invalid_global));
    $state->clearErrors();
  }

  /**
   * Tests entity PKCE controls, explicit config inheritance and master policy.
   */
  public function testEntityPolicyPrecedenceAndMasterSwitch(): void {
    $client = Consumer::create([
      'label' => 'Entity policy client',
      'client_id' => 'entity-policy-client',
      'confidential' => TRUE,
      'redirect' => ['https://example.com/callback'],
    ]);
    $client->save();
    $detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    $config = $this->config('simple_oauth_native_apps.settings');
    $override = $this->config('simple_oauth_native_apps.consumer.' . $client->id());
    foreach (['enhanced', 'not-enhanced', 'auto-detect'] as $global) {
      $config->set('native.enhanced_pkce', $global)->save();
      foreach (['native', 'web', 'auto-detect', ''] as $field) {
        $client->set('native_app_enhanced_pkce', $field);
        foreach (['', 'enhanced', 'not-enhanced', 'auto-detect'] as $value) {
          $override->set('enhanced_pkce_override', $value)->save();
          $effective = $value !== '' ? $value : match ($field) {
            'native' => 'enhanced',
            'web' => 'not-enhanced',
            default => $global,
          };
          $this->assertSame($effective === 'enhanced', $detector->requiresEnhancedPkce($client), "$global / $field / $value");
        }
      }
    }
    $config->set('enforce_native_security', FALSE)->save();
    $override->set('enhanced_pkce_override', 'enhanced')->save();
    $this->assertFalse($detector->requiresEnhancedPkce($client));
    $pkce = $this->container->get('simple_oauth_native_apps.pkce_enhancement');
    $this->assertFalse($pkce->getConfiguration()['enhanced_pkce_enabled']);
    $this->assertFalse($pkce->requiresS256Method($client));
    $config->clear('enforce_native_security')->save();
    $this->assertFalse($detector->getEffectivePolicy($client)['enabled']);
    $this->assertFalse($detector->requiresEnhancedPkce($client));
  }

  /**
   * Tests redirect constraint attachment and consumer policy context.
   */
  public function testConsumerRedirectValidation(): void {
    $client = Consumer::create([
      'label' => 'Entity redirect client',
      'client_id' => 'entity-redirect-client',
      'confidential' => FALSE,
      'redirect' => ['com.example.policy://callback'],
      'native_app_override' => 'native',
    ]);
    $client->save();
    $validator = $this->container->get('simple_oauth_native_apps.redirect_uri_validator');
    $override = $this->config('simple_oauth_native_apps.consumer.' . $client->id());
    $this->config('simple_oauth_native_apps.settings')->set('allow.custom_uri_schemes', 'web')->set('allow.loopback_redirects', 'web')->save();
    foreach (['', 'native', 'web'] as $permission) {
      $override->set('allow_custom_schemes_override', $permission)->set('allow_loopback_override', $permission)->save();
      $uris = [
        'com.example.policy://callback',
        'com.example.policy:/callback',
        'http://127.0.0.1:43210/callback',
        'http://[::1]:43210/callback',
        'https://example.com/callback',
      ];
      foreach ($uris as $uri) {
        $allowed = $permission === 'native' || $uri === 'https://example.com/callback';
        $this->assertSame($allowed, $validator->validateConsumerRedirectUri($uri, $client), "$permission / $uri");
        $client->set('redirect', [$uri]);
        // Validate just this field to exclude unrelated consumer requirements.
        $violations = $client->get('redirect')->validate();
        $native_violations = array_filter(iterator_to_array($violations), static fn ($violation) => $violation->getConstraint() instanceof NativeAppRedirectUri);
        $this->assertSame(!$allowed, $native_violations !== [], "$permission / $uri");
      }
    }
    $this->assertFalse($validator->validateConsumerRedirectUri('http://localhost:43210/callback', $client));
  }

  /**
   * Tests pending form overrides validate without overwriting stored policy.
   */
  public function testConsumerFormUsesSubmittedRedirectPolicy(): void {
    $consumer = Consumer::create([
      'label' => 'Submitted policy client',
      'client_id' => 'submitted-policy-client',
      'confidential' => FALSE,
      'redirect' => ['com.example.submitted://callback'],
      'native_app_override' => 'native',
    ]);
    $consumer->save();
    $this->config('simple_oauth_native_apps.settings')->set('allow.custom_uri_schemes', 'web')->save();
    $form_object = $this->createMock(EntityFormInterface::class);
    $form_object->method('getEntity')->willReturn($consumer);
    $state = (new FormState())->setFormObject($form_object);
    $state->setValue('native_apps', ['allow_custom_schemes_override' => 'native']);
    $handler = $this->container->get('simple_oauth_native_apps.consumer_form_alter');
    $form = [];
    $handler->alterForm($form, $state, 'consumer_edit_form');
    $this->assertCount(1, $consumer->get('redirect')->validate());
    // EntityForm builds a clone, so pending overrides must stay on that clone.
    $built = clone $consumer;
    foreach ($form['#entity_builders'] as $builder) {
      $builder('consumer', $built, $form, $state);
    }
    $this->assertCount(0, $built->get('redirect')->validate());
    $this->assertCount(1, $consumer->get('redirect')->validate());
    $name = 'simple_oauth_native_apps.consumer.' . $consumer->id();
    $this->assertTrue($this->container->get('config.factory')->get($name)->isNew());
    $handler->submitConsumerNativeAppsSettings($form, $state);
    $this->assertSame('native', $this->container->get('config.factory')->get($name)->get('allow_custom_schemes_override'));
    $this->assertCount(0, $consumer->get('redirect')->validate());
    $state->setValue('native_apps', ['allow_custom_schemes_override' => 'web']);
    $denied = clone $consumer;
    foreach ($form['#entity_builders'] as $builder) {
      $builder('consumer', $denied, $form, $state);
    }
    $this->assertCount(1, $denied->get('redirect')->validate());
    $this->assertCount(0, $consumer->get('redirect')->validate());
    $this->assertSame('native', $this->container->get('config.factory')->get($name)->get('allow_custom_schemes_override'));
  }

  /**
   * Tests deleting one consumer removes only its own numeric override config.
   */
  public function testConsumerOverrideDeletion(): void {
    $this->installEntitySchema('oauth2_token');
    $client = Consumer::create(['label' => 'Deleted policy', 'client_id' => 'deleted-policy']);
    $client->save();
    $override = $this->config('simple_oauth_native_apps.consumer.' . $client->id());
    $override->set('enhanced_pkce_override', 'enhanced')->save();
    $other = Consumer::create(['label' => 'Retained policy', 'client_id' => 'retained-policy']);
    $other->save();
    $other_name = 'simple_oauth_native_apps.consumer.' . $other->id();
    $this->config($other_name)->set('enhanced_pkce_override', 'enhanced')->save();
    $name = $override->getName();
    $client->delete();
    $this->assertTrue($this->container->get('config.factory')->get($name)->isNew());
    $this->assertSame('enhanced', $this->container->get('config.factory')->get($other_name)->get('enhanced_pkce_override'));
  }

  /**
   * Tests validation logging honors its flag and failures remain logged.
   */
  public function testPkceValidationLoggingPolicy(): void {
    $client = Consumer::create([
      'label' => 'Logging policy client',
      'client_id' => 'logging-policy-client',
      'native_app_override' => 'native',
    ]);
    $detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    foreach ([FALSE, TRUE] as $enabled) {
      $this->config('simple_oauth_native_apps.settings')->set('log.pkce_validations', $enabled)->save();
      $logger = $this->createMock(LoggerInterface::class);
      $logger->expects($this->exactly($enabled ? 2 : 1))->method('log');
      $factory = $this->createMock(LoggerChannelFactoryInterface::class);
      $factory->method('get')->willReturn($logger);
      $service = new PKCEEnhancementService($this->container->get('config.factory'), $detector, $factory);
      $this->assertTrue($service->validatePkceParameters($client, 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'S256')['valid']);
      $this->assertFalse($service->validatePkceParameters($client)['valid']);
      $this->assertSame($enabled, $service->getConfiguration()['log_validations']);
    }
  }

  /**
   * Tests client advice uses web schemes and announces AJAX results.
   */
  public function testClientAdviceAndAccessibleAjax(): void {
    $detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    foreach ([
      'https://example.com/callback' => 'web',
      'http://example.com/callback' => 'web',
      'com.example.policy:/callback' => 'mobile',
      'http://127.0.0.1:43210/callback' => 'terminal',
    ] as $uri => $expected) {
      $this->assertSame($expected, $detector->detectClientType([$uri])['type']);
      $this->assertSame($expected !== 'web', $detector->detectNativeFromRedirectUris([$uri]));
    }
    $handler = $this->container->get('simple_oauth_native_apps.consumer_form_alter');
    $form = [];
    foreach ([[], ['redirect' => [['value' => 'https://example.com/callback']]]] as $values) {
      $state = (new FormState())->setValues($values);
      $commands = $handler->detectClientTypeAjax($form, $state)->getCommands();
      $this->assertSame('announce', $commands[1]['command']);
      $this->assertNotSame('', $commands[1]['text']);
    }
  }

  /**
   * Tests translation and escaping of detector presentation.
   */
  public function testDetectionPresentationTranslationAndEscaping(): void {
    $translations = [
      'Terminal' => 'Terminal traduit',
      'Mobile' => 'Mobile traduit',
      'Desktop' => 'Bureau',
      'Web' => 'Toile',
      'Low' => 'Faible',
      'Medium' => 'Moyenne',
      'High' => 'Haute',
      'Terminal/CLI application using loopback redirects' => 'Application terminal traduite',
      'Mobile native application' => 'Application mobile traduite',
      'Desktop native application' => 'Application bureau traduite',
      'Web application' => 'Application web traduite',
    ];
    $translator = $this->createMock(TranslatorInterface::class);
    $translator->method('getStringTranslation')->willReturnCallback(
      static fn(string $langcode, string $string, string $context): string|false => $translations[$string] ?? FALSE
    );
    $translation = new TranslationManager($this->container->get('language.default'));
    $translation->addTranslator($translator);
    $handler = $this->container->get('simple_oauth_native_apps.consumer_form_alter');
    $handler->setStringTranslation($translation);
    $render = new \ReflectionMethod($handler, 'renderDetectionResult');
    $detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    foreach ([
      'http://127.0.0.1:43210/callback' => ['terminal', 'Terminal/CLI application using loopback redirects'],
      'com.example.policy:/callback' => ['mobile', 'Mobile native application'],
      'http://127.0.0.1/callback' => ['desktop', 'Desktop native application'],
      'https://example.com/callback' => ['web', 'Web application'],
    ] as $uri => [$type, $description]) {
      $result = $detector->detectClientType([$uri]);
      $this->assertSame($type, $result['type']);
      $this->assertSame($description, $result['details']['description']);
      $markup = $render->invoke($handler, $result, [$uri]);
      $this->assertStringContainsString($translations[ucfirst($type)], $markup);
      $this->assertStringContainsString('Haute', $markup);
      $this->assertStringContainsString($translations[$description], $markup);
      // Rendering must preserve the public detector result and English values.
      $this->assertSame($result, $detector->detectClientType([$uri]));
    }
    $mixed = $detector->detectClientType([
      'http://127.0.0.1:43210/callback',
      'https://example.com/callback',
    ]);
    $markup = $render->invoke($handler, $mixed, []);
    $this->assertStringContainsString('Moyenne', $markup);
    $this->assertStringContainsString('Terminal traduit, Toile', $markup);
    $markup = $render->invoke($handler, ['type' => 'web', 'confidence' => 'low'], []);
    $this->assertStringContainsString('Faible', $markup);

    $form = [];
    $state = (new FormState())->setValues(['redirect' => [['value' => 'com.example.policy:/callback']]]);
    $commands = $handler->detectClientTypeAjax($form, $state)->getCommands();
    $this->assertSame('announce', $commands[1]['command']);
    $this->assertStringContainsString('Mobile traduit', $commands[1]['text']);
    $this->assertStringContainsString('Application mobile traduite', $commands[1]['text']);
    $this->assertStringContainsString('Enable enhanced PKCE for native app security', $commands[0]['data']);
  }

  /**
   * Tests unknown detector text and confidence cannot inject markup.
   */
  public function testDetectionPresentationEscapesUnknownValues(): void {
    $handler = $this->container->get('simple_oauth_native_apps.consumer_form_alter');
    $render = new \ReflectionMethod($handler, 'renderDetectionResult');
    $untrusted = '<img src=x onerror=alert(1)>';
    $markup = $render->invoke($handler, [
      'type' => $untrusted,
      'confidence' => 'high" onclick="alert(1)',
      'details' => ['description' => $untrusted],
      'mixed_types' => ['web', $untrusted],
    ], [$untrusted]);
    $this->assertStringContainsString('confidence-unknown', $markup);
    $this->assertStringContainsString('&lt;img', $markup);
    $this->assertStringContainsString('Web, &lt;img', $markup);
    $this->assertStringNotContainsString('<img', $markup);
    $this->assertStringNotContainsString(' onclick="', $markup);
    $this->assertStringNotContainsString('&amp;lt;', $markup);

  }

  /**
   * Tests that all services are properly registered and injectable.
   *
   * @covers \Drupal\simple_oauth_native_apps\Service\RedirectUriValidator
   * @covers \Drupal\simple_oauth_native_apps\Service\UserAgentAnalyzer
   * @covers \Drupal\simple_oauth_native_apps\Service\NativeClientDetector
   * @covers \Drupal\simple_oauth_native_apps\Service\PKCEEnhancementService
   * @covers \Drupal\simple_oauth_native_apps\Service\ConfigurationValidator
   * @covers \Drupal\simple_oauth_native_apps\Service\MetadataProvider
   */
  public function testServiceRegistration(): void {
    $container = $this->container;

    // Test redirect URI validator service.
    $redirect_validator = $container->get('simple_oauth_native_apps.redirect_uri_validator');
    $this->assertInstanceOf(RedirectUriValidator::class, $redirect_validator);

    // Test user agent analyzer service.
    $user_agent_analyzer = $container->get('simple_oauth_native_apps.user_agent_analyzer');
    $this->assertInstanceOf(UserAgentAnalyzer::class, $user_agent_analyzer);

    // Test native client detector service.
    $native_detector = $container->get('simple_oauth_native_apps.native_client_detector');
    $this->assertInstanceOf(NativeClientDetector::class, $native_detector);

    // Test PKCE enhancement service.
    $pkce_service = $container->get('simple_oauth_native_apps.pkce_enhancement');
    $this->assertInstanceOf(PKCEEnhancementService::class, $pkce_service);

    // Test configuration validator service.
    $config_validator = $container->get('simple_oauth_native_apps.configuration_validator');
    $this->assertInstanceOf(ConfigurationValidator::class, $config_validator);

    // Test metadata provider service.
    $metadata_provider = $container->get('simple_oauth_native_apps.metadata_provider');
    $this->assertInstanceOf(MetadataProvider::class, $metadata_provider);
  }

  /**
   * Tests service integration with configuration.
   */
  public function testServiceConfigurationIntegration(): void {
    $config_factory = $this->container->get('config.factory');
    $config = $config_factory->getEditable('simple_oauth_native_apps.settings');

    // Update configuration with nested structure.
    $config->set('allow.custom_uri_schemes', 'native');
    $config->set('allow.loopback_redirects', 'native');
    $config->set('enforce_native_security', TRUE);
    $config->set('webview.detection', 'warn');
    $config->save();

    $redirect_validator = $this->container->get('simple_oauth_native_apps.redirect_uri_validator');
    $user_agent_analyzer = $this->container->get('simple_oauth_native_apps.user_agent_analyzer');

    // Test that services respond to configuration changes.
    $this->assertTrue($redirect_validator->validateCustomScheme('myapp://callback'));
    $this->assertEquals('warn', $user_agent_analyzer->getDetectionMode());
  }

  /**
   * Tests integration between native client detector and PKCE enhancement.
   */
  public function testNativeDetectorPkceIntegration(): void {
    /** @var \Drupal\simple_oauth_native_apps\Service\NativeClientDetector $native_detector */
    $native_detector = $this->container->get('simple_oauth_native_apps.native_client_detector');

    /** @var \Drupal\simple_oauth_native_apps\Service\PKCEEnhancementService $pkce_service */
    $pkce_service = $this->container->get('simple_oauth_native_apps.pkce_enhancement');

    // Create a test consumer.
    $consumer = Consumer::create([
      'label' => 'Test Native App',
      'client_id' => 'test_native_client',
      'client_secret' => 'secret',
      'confidential' => FALSE,
      'third_party' => TRUE,
      'redirect' => [
        'myapp://callback',
        'com.example.app://auth',
      ],
    ]);
    $consumer->save();

    // Test that native detection affects PKCE requirements.
    $is_native = $native_detector->isNativeClient($consumer);
    $requires_enhanced = $native_detector->requiresEnhancedPkce($consumer);
    $requires_s256 = $pkce_service->requiresS256Method($consumer);

    $this->assertTrue($is_native);
    $this->assertTrue($requires_enhanced);
    $this->assertTrue($requires_s256);

    // Test PKCE validation with native client.
    $validation_result = $pkce_service->validatePkceParameters(
      $consumer,
      'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
      'S256'
    );

    $this->assertTrue($validation_result['valid']);
    $this->assertTrue($validation_result['enhanced_applied']);
  }

  /**
   * Tests user agent analyzer integration.
   */
  public function testUserAgentAnalyzerIntegration(): void {
    /** @var \Drupal\simple_oauth_native_apps\Service\UserAgentAnalyzer $analyzer */
    $analyzer = $this->container->get('simple_oauth_native_apps.user_agent_analyzer');

    // Test webview detection.
    // cspell:ignore FBAN FBIOS FBDV
    $facebook_ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBDV/iPhone14,5]';
    $safari_ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1';

    $this->assertTrue($analyzer->isEmbeddedWebview($facebook_ua));
    $this->assertFalse($analyzer->isEmbeddedWebview($safari_ua));

    $webview_type = $analyzer->getWebviewType($facebook_ua);
    $this->assertNotNull($webview_type);
    $this->assertEquals('social_media', $webview_type['category']);
  }

  /**
   * Tests redirect URI validator with configuration changes.
   */
  public function testRedirectUriValidatorConfigIntegration(): void {
    /** @var \Drupal\simple_oauth_native_apps\Service\RedirectUriValidator $validator */
    $validator = $this->container->get('simple_oauth_native_apps.redirect_uri_validator');

    $config = $this->container->get('config.factory')->getEditable('simple_oauth_native_apps.settings');

    // Test with custom schemes enabled (using enum value).
    $config->set('allow.custom_uri_schemes', 'native')->save();
    $this->assertTrue($validator->validateCustomScheme('myapp://callback'));

    // Test with custom schemes disabled (using enum value).
    $config->set('allow.custom_uri_schemes', 'web')->save();
    $this->assertFalse($validator->validateCustomScheme('myapp://callback'));

    // Test with loopback enabled (using enum value).
    $config->set('allow.loopback_redirects', 'native')->save();
    $this->assertTrue($validator->validateLoopbackInterface('http://127.0.0.1:8080/callback'));

    // Test with loopback disabled (using enum value).
    $config->set('allow.loopback_redirects', 'web')->save();
    $this->assertFalse($validator->validateLoopbackInterface('http://127.0.0.1:8080/callback'));
  }

  /**
   * Tests configuration validator integration.
   */
  public function testConfigurationValidatorIntegration(): void {
    /** @var \Drupal\simple_oauth_native_apps\Service\ConfigurationValidator $validator */
    $validator = $this->container->get('simple_oauth_native_apps.configuration_validator');

    // Test valid configuration with nested structure.
    $valid_config = [
      'webview' => [
        'detection' => 'warn',
        'whitelist' => ['TrustedApp'],
        'patterns' => [],
      ],
      'allow' => [
        'custom_uri_schemes' => 'native',
        'loopback_redirects' => 'native',
      ],
      'native' => [
        'enhanced_pkce' => 'enhanced',
        'enforce' => 'S256',
      ],
    ];

    $errors = $validator->validateConfiguration($valid_config);
    $this->assertEmpty($errors);

    // Test invalid configuration with nested structure.
    $invalid_config = [
      'webview' => ['detection' => 'invalid_policy'],
      'allow' => [
        'custom_uri_schemes' => 'invalid_value',
      ],
    ];

    $errors = $validator->validateConfiguration($invalid_config);
    $this->assertNotEmpty($errors);
    $this->assertGreaterThanOrEqual(1, count($errors));
  }

  /**
   * Tests metadata provider integration.
   */
  public function testMetadataProviderIntegration(): void {
    // Set up configuration for native app support.
    $config = $this->container->get('config.factory')->getEditable('simple_oauth_native_apps.settings');
    $config->set('enforce_native_security', TRUE)->save();

    /** @var \Drupal\simple_oauth_native_apps\Service\MetadataProvider $provider */
    $provider = $this->container->get('simple_oauth_native_apps.metadata_provider');

    $metadata = $provider->getMetadata();

    $this->assertIsArray($metadata);
    $this->assertArrayHasKey('native_apps_supported', $metadata);
    $this->assertArrayHasKey('pkce_required', $metadata);
    $this->assertTrue($metadata['native_apps_supported']);
    $this->assertTrue($metadata['pkce_required']);
  }

  /**
   * Tests service dependencies are correctly injected.
   */
  public function testServiceDependencyInjection(): void {
    // Test that native client detector has correct dependencies.
    $native_detector = $this->container->get('simple_oauth_native_apps.native_client_detector');

    // Verify it can use injected services by testing functionality.
    $consumer = Consumer::create([
      'label' => 'Test App',
      'client_id' => 'test_client',
      'confidential' => FALSE,
      'redirect' => ['myapp://callback'],
    ]);

    // This would fail if dependencies weren't correctly injected.
    $confidence = $native_detector->getDetectionConfidence($consumer);
    $this->assertIsFloat($confidence);
    $this->assertGreaterThanOrEqual(0.0, $confidence);
    $this->assertLessThanOrEqual(1.0, $confidence);
  }

  /**
   * Tests service logging integration.
   */
  public function testServiceLoggingIntegration(): void {
    $config = $this->container->get('config.factory')->getEditable('simple_oauth_native_apps.settings');
    $config->set('log.pkce_validations', TRUE)->save();

    /** @var \Drupal\simple_oauth_native_apps\Service\PKCEEnhancementService $pkce_service */
    $pkce_service = $this->container->get('simple_oauth_native_apps.pkce_enhancement');

    $consumer = Consumer::create([
      'label' => 'Test Native App',
      'client_id' => 'test_native_client',
      'confidential' => FALSE,
      'redirect' => ['myapp://callback'],
    ]);

    // Test that logging doesn't cause errors.
    $result = $pkce_service->validatePkceParameters($consumer);
    $this->assertIsArray($result);
    $this->assertArrayHasKey('valid', $result);
  }

  /**
   * Tests complete service chain integration.
   */
  public function testCompleteServiceChainIntegration(): void {
    // Create a native app consumer.
    $consumer = Consumer::create([
      'label' => 'Complete Test Native App',
      'client_id' => 'complete_test_client',
      'confidential' => FALSE,
      'third_party' => TRUE,
      'redirect' => [
    // cspell:ignore testapp
        'com.example.testapp://oauth/callback',
        'http://127.0.0.1:8080/callback',
      ],
    ]);
    $consumer->save();

    // Test the complete chain: Detection -> Validation -> PKCE Enhancement.
    // 1. Native client detection.
    $native_detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    $is_native = $native_detector->isNativeClient($consumer);
    $this->assertTrue($is_native);

    // 2. Redirect URI validation.
    $redirect_validator = $this->container->get('simple_oauth_native_apps.redirect_uri_validator');
    // cspell:ignore testapp
    $custom_valid = $redirect_validator->validateCustomScheme('com.example.testapp://oauth/callback');
    $loopback_valid = $redirect_validator->validateLoopbackInterface('http://127.0.0.1:8080/callback');
    $this->assertTrue($custom_valid);
    $this->assertTrue($loopback_valid);

    // 3. PKCE enhancement for native client.
    $pkce_service = $this->container->get('simple_oauth_native_apps.pkce_enhancement');
    $requires_enhanced = $native_detector->requiresEnhancedPkce($consumer);
    $this->assertTrue($requires_enhanced);

    // 4. Enhanced PKCE validation.
    $code_verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXkdBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    $code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, TRUE)), '+/', '-_'), '=');

    $validation_result = $pkce_service->validatePkceParameters(
      $consumer,
      $code_challenge,
      'S256',
      $code_verifier
    );

    $this->assertTrue($validation_result['valid']);
    $this->assertTrue($validation_result['enhanced_applied']);
    $this->assertEmpty($validation_result['errors']);

    // 5. User agent analysis for webview detection.
    $user_agent_analyzer = $this->container->get('simple_oauth_native_apps.user_agent_analyzer');
    // cspell:ignore FBAN FBIOS
    $webview_ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS]';
    $is_webview = $user_agent_analyzer->isEmbeddedWebview($webview_ua);
    $this->assertTrue($is_webview);

    // 6. Configuration validation with nested structure.
    $config_validator = $this->container->get('simple_oauth_native_apps.configuration_validator');
    $config_errors = $config_validator->validateConfiguration([
      'webview' => ['detection' => 'warn'],
      'allow' => [
        'custom_uri_schemes' => 'native',
        'loopback_redirects' => 'native',
      ],
      'native' => [
        'enhanced_pkce' => 'enhanced',
        'enforce' => 'S256',
      ],
    ]);
    $this->assertEmpty($config_errors);

    // 7. Metadata provision.
    $metadata_provider = $this->container->get('simple_oauth_native_apps.metadata_provider');
    $metadata = $metadata_provider->getMetadata();
    $this->assertTrue($metadata['native_apps_supported']);
    $this->assertTrue($metadata['pkce_required']);
  }

  /**
   * Creates the explicit UUID collision used to verify policy portability.
   *
   * @return array
   *   Intended owner, unrelated local consumer, config name and whole record.
   */
  private function createBoundPolicyCollision(): array {
    $this->config('simple_oauth_native_apps.settings')
      ->set('enforce_native_security', TRUE)
      ->set('native.enhanced_pkce', 'enhanced')
      ->set('allow.custom_uri_schemes', 'native')
      ->set('allow.loopback_redirects', 'native')
      ->set('webview.detection', 'block')->save();
    $unrelated = Consumer::create([
      'label' => 'Unrelated local consumer',
      'uuid' => '8d9a15eb-d594-45af-b550-cc49768d16ad',
      'client_id' => 'unrelated-local-client',
    ]);
    $unrelated->save();
    $owner = Consumer::create([
      'label' => 'Portable policy consumer',
      'uuid' => 'd1d85c86-b3e8-4fca-aa39-bc38f9a7569c',
      'client_id' => 'stable-original-client',
    ]);
    $owner->save();
    $this->assertNotSame($owner->id(), $unrelated->id());
    $this->assertNotSame($owner->uuid(), $unrelated->uuid());
    $data = [
      'consumer_uuid' => $owner->uuid(),
      'webview_detection_override' => 'off',
      'allow_custom_schemes_override' => 'web',
      'allow_loopback_override' => 'web',
      'enhanced_pkce_override' => 'not-enhanced',
    ];
    $name = 'simple_oauth_native_apps.consumer.' . $unrelated->id();
    $this->config($name)->setData($data)->save();
    return [$owner, $unrelated, $name, $data];
  }

  /**
   * Tests ambiguous policy ownership becomes an entity validation violation.
   */
  public function testPolicyConflictIsEntityViolation(): void {
    [$owner, , , $data] = $this->createBoundPolicyCollision();
    $this->config('simple_oauth_native_apps.consumer.999')->setData($data)->save();
    $owner->set('redirect', ['https://example.org/callback']);
    $violations = $owner->get('redirect')->validate();
    $this->assertCount(1, $violations);
    $this->assertSame('Native policy ownership requires reconciliation before this redirect URI can be validated.', (string) $violations[0]->getMessage());
  }

  /**
   * Tests all four controls follow the UUID, never a foreign numeric slot.
   */
  public function testBoundPolicyFollowsUuid(): void {
    [$owner, $unrelated, $name, $data] = $this->createBoundPolicyCollision();
    $detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    $this->assertSame([
      'enabled' => TRUE,
      'enhanced_pkce' => FALSE,
      'custom_schemes' => FALSE,
      'loopback' => FALSE,
      'webview' => 'off',
    ], $detector->getEffectivePolicy($owner));
    $this->assertSame([
      'enabled' => TRUE,
      'enhanced_pkce' => TRUE,
      'custom_schemes' => TRUE,
      'loopback' => TRUE,
      'webview' => 'block',
    ], $detector->getEffectivePolicy($unrelated));
    $handler = $this->container->get('simple_oauth_native_apps.consumer_form_alter');
    foreach ([$owner, $unrelated] as $consumer) {
      $state = $this->policyFormState($consumer, []);
      $form = [];
      $handler->alterForm($form, $state, 'consumer_edit_form');
      foreach (array_diff_key($data, ['consumer_uuid' => TRUE]) as $key => $value) {
        $this->assertSame($consumer === $owner ? $value : '', $form['native_apps'][$key]['#default_value']);
      }
      $this->assertArrayNotHasKey('confirm_policy_owner', $form['native_apps']);
    }
    $this->assertSame($data, $this->config($name)->get());
  }

  /**
   * Tests unrelated deletion preserves policy and owner deletion follows UUID.
   */
  public function testBoundForeignDeletionAndOwnerCleanup(): void {
    [$owner, $unrelated, $name, $data] = $this->createBoundPolicyCollision();
    $this->installEntitySchema('oauth2_token');
    $unrelated->delete();
    $this->assertSame($data, $this->config($name)->get());
    $owner->delete();
    $this->assertTrue($this->container->get('config.factory')->get($name)->isNew());
  }

  /**
   * Tests orphan records never transfer to a recreated numeric consumer.
   */
  public function testBoundOrphanAndRecreatedConsumers(): void {
    [$owner, $unrelated, $name, $data] = $this->createBoundPolicyCollision();
    $this->installEntitySchema('oauth2_token');
    $id = $owner->id();
    $owner->delete();
    // Model an orphan arriving outside the supported config import boundary.
    $name = 'simple_oauth_native_apps.consumer.' . $id;
    $this->config($name)->setData($data)->save();
    $replacement = Consumer::create([
      'id' => $id,
      'uuid' => 'ecb78c78-8b91-45e0-98d0-0d79168ff167',
      'label' => 'Recreated numeric client',
      'client_id' => 'replacement-client',
    ]);
    $replacement->save();
    $detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    $this->assertTrue($detector->requiresEnhancedPkce($replacement));
    $replacement->delete();
    $this->assertSame($data, $this->config($name)->get());
    $restored = Consumer::create([
      'uuid' => $data['consumer_uuid'],
      'label' => 'Restored UUID client',
      'client_id' => 'restored-client',
    ]);
    $restored->save();
    $this->assertNotSame($id, $restored->id());
    $this->assertFalse($detector->requiresEnhancedPkce($restored));
    $this->assertTrue($detector->requiresEnhancedPkce($unrelated));
  }

  /**
   * Tests bound explicit-empty values and submitted whole-record precedence.
   */
  public function testBoundExplicitEmptyPolicyPrecedence(): void {
    [$owner, , $name, $data] = $this->createBoundPolicyCollision();
    $this->installEntitySchema('oauth2_token');
    $owner->set('native_app_enhanced_pkce', 'native')->save();
    $detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    $this->assertFalse($detector->requiresEnhancedPkce($owner));
    foreach (array_diff(array_keys($data), ['consumer_uuid']) as $key) {
      $data[$key] = '';
    }
    $this->config($name)->setData($data)->save();
    $expected = [
      'enabled' => TRUE,
      'enhanced_pkce' => TRUE,
      'custom_schemes' => TRUE,
      'loopback' => TRUE,
      'webview' => 'block',
    ];
    $this->assertSame($expected, $detector->getEffectivePolicy($owner));
    $data['enhanced_pkce_override'] = 'not-enhanced';
    $data['webview_detection_override'] = 'off';
    $data['allow_custom_schemes_override'] = 'web';
    $this->config($name)->setData($data)->save();
    $detector->setSubmittedOverrides($owner, ['enhanced_pkce_override' => '']);
    $this->assertSame($expected, $detector->getEffectivePolicy($owner));
    $this->assertSame($data, $this->config($name)->get());
  }

  /**
   * Tests duplicates and competing legacy records require reconciliation.
   */
  public function testPolicyConflictsNeverSelectOrMerge(): void {
    [$owner, , $name, $data] = $this->createBoundPolicyCollision();
    $other_name = 'simple_oauth_native_apps.consumer.' . $owner->id();
    $resolver = $this->container->get('simple_oauth_native_apps.consumer_policy_resolver');
    $detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    $handler = $this->container->get('simple_oauth_native_apps.consumer_form_alter');
    foreach ([$data, ['enhanced_pkce_override' => 'enhanced']] as $competing) {
      $this->config($other_name)->setData($competing)->save();
      try {
        $detector->getEffectivePolicy($owner);
        $this->fail('Ambiguous policy must not resolve.');
      }
      catch (\UnexpectedValueException $exception) {
        $this->assertStringContainsString('reconciliation', $exception->getMessage());
      }
      $state = $this->policyFormState($owner, ['enhanced_pkce_override' => 'not-enhanced', 'confirm_policy_owner' => 1]);
      $handler->validateConsumerNativeAppsSettings([], $state);
      $this->assertTrue($state->hasAnyErrors());
      $state->clearErrors();
      foreach (['save', 'delete'] as $operation) {
        try {
          $operation === 'save' ? $resolver->save($owner, ['enhanced_pkce_override' => 'not-enhanced']) : $resolver->delete($owner);
          $this->fail('Ambiguous records must not be written or deleted.');
        }
        catch (\UnexpectedValueException) {
          $this->assertSame($data, $this->config($name)->get());
          $this->assertSame($competing, $this->config($other_name)->get());
        }
      }
    }
  }

  /**
   * Tests foreign slots fail before entity save, including forged confirmation.
   */
  public function testCollisionFormRefusesForeignOwner(): void {
    [$owner, $unrelated, $name, $data] = $this->createBoundPolicyCollision();
    $handler = $this->container->get('simple_oauth_native_apps.consumer_form_alter');
    $state = $this->policyFormState($unrelated, [
      'enhanced_pkce_override' => 'not-enhanced',
      'confirm_policy_owner' => 1,
    ]);
    $handler->validateConsumerNativeAppsSettings([], $state);
    $this->assertTrue($state->hasAnyErrors());
    $state->clearErrors();
    $label = $unrelated->label();
    $unrelated->set('label', 'Must not be saved');
    $form = [
      'actions' => [
        'submit' => [
          '#submit' => [
            static function () use ($unrelated): void {
              $unrelated->save();
            },
          ],
        ],
      ],
    ];
    $handler->alterForm($form, $state, 'consumer_edit_form');
    try {
      foreach ($form['actions']['submit']['#submit'] as $callback) {
        $callback($form, $state);
      }
      $this->fail('Foreign ownership must stop callbacks before entity save.');
    }
    catch (\UnexpectedValueException) {
      $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
      $storage->resetCache([$unrelated->id()]);
      $this->assertSame($label, $storage->load($unrelated->id())->label());
      $this->assertSame($owner->uuid(), $storage->load($owner->id())->uuid());
      $this->assertSame($data, $this->config($name)->get());
    }
    try {
      $handler->submitConsumerNativeAppsSettings([], $state);
      $this->fail('Direct persistence must recheck foreign ownership.');
    }
    catch (\UnexpectedValueException) {
      $this->assertSame($data, $this->config($name)->get());
    }
  }

  /**
   * Tests explicit binding validation rejects malformed or foreign identity.
   */
  public function testPolicyFormRejectsInvalidBinding(): void {
    [$owner, $unrelated, $name, $data] = $this->createBoundPolicyCollision();
    $handler = $this->container->get('simple_oauth_native_apps.consumer_form_alter');
    foreach ([$unrelated->uuid(), 'invalid', '', NULL, []] as $binding) {
      $state = $this->policyFormState($owner, [
        'enhanced_pkce_override' => 'enhanced',
        'consumer_uuid' => $binding,
      ]);
      $handler->validateConsumerNativeAppsSettings([], $state);
      $this->assertTrue($state->hasAnyErrors());
      $state->clearErrors();
      try {
        $handler->submitConsumerNativeAppsSettings([], $state);
        $this->fail('An invalid or foreign explicit binding must not persist.');
      }
      catch (\UnexpectedValueException) {
        $this->assertSame($data, $this->config($name)->get());
      }
    }
  }

  /**
   * Tests an assigned-ID collision rolls back the new consumer and policy.
   */
  public function testCollisionOnAssignedIdRollsBackConsumer(): void {
    [$owner, $unrelated, , $data] = $this->createBoundPolicyCollision();
    // This fixture controls its next numeric ID. Production never predicts IDs.
    $next_id = (int) $owner->id() + 1;
    $name = 'simple_oauth_native_apps.consumer.' . $next_id;
    $this->config($name)->setData($data)->save();
    $consumer = Consumer::create(['label' => 'Late collision', 'client_id' => 'late-collision']);
    $uuid = $consumer->uuid();
    $state = $this->policyFormState($consumer, ['enhanced_pkce_override' => 'not-enhanced']);
    $handler = $this->container->get('simple_oauth_native_apps.consumer_form_alter');
    $form = [
      'actions' => [
        'submit' => [
          '#submit' => [
            static function () use ($consumer): void {
              $consumer->save();
            },
          ],
        ],
      ],
    ];
    $handler->alterForm($form, $state, 'consumer_add_form');
    $handler->validateConsumerNativeAppsSettings($form, $state);
    $this->assertFalse($state->hasAnyErrors());
    try {
      foreach ($form['actions']['submit']['#submit'] as $callback) {
        $callback($form, $state);
      }
      $this->fail('Late numeric collision must not leave a new consumer saved.');
    }
    catch (\UnexpectedValueException) {
      $this->assertSame($next_id, (int) $consumer->id());
      $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
      $this->assertSame([], $storage->loadByProperties(['uuid' => $uuid]));
      $this->assertNull($storage->load($next_id));
      $this->assertSame($owner->uuid(), $storage->load($owner->id())->uuid());
      $this->assertSame($unrelated->uuid(), $storage->load($unrelated->id())->uuid());
      $this->assertSame($data, $this->config($name)->get());
    }
  }

  /**
   * Tests new policies bind, keep imported names and clear only owned records.
   */
  public function testPolicyFormBindingAndStableWrites(): void {
    $consumer = Consumer::create(['label' => 'New policy', 'client_id' => 'new-policy']);
    $handler = $this->container->get('simple_oauth_native_apps.consumer_form_alter');
    $state = $this->policyFormState($consumer, ['enhanced_pkce_override' => 'enhanced']);
    $form = [
      'actions' => [
        'submit' => [
          '#submit' => [
            static function () use ($consumer): void {
              $consumer->save();
            },
          ],
        ],
      ],
    ];
    $handler->alterForm($form, $state, 'consumer_add_form');
    $handler->validateConsumerNativeAppsSettings($form, $state);
    $this->assertFalse($state->hasAnyErrors());
    foreach ($form['actions']['submit']['#submit'] as $callback) {
      $callback($form, $state);
    }
    $this->assertSame($consumer->uuid(), $this->container->get('entity_type.manager')->getStorage('consumer')->load($consumer->id())->uuid());
    $name = 'simple_oauth_native_apps.consumer.' . $consumer->id();
    $this->assertSame($consumer->uuid(), $this->config($name)->get('consumer_uuid'));
    $this->config($name)->delete();
    [$owner, , $imported_name] = $this->createBoundPolicyCollision();
    $state = $this->policyFormState($owner, ['enhanced_pkce_override' => 'enhanced']);
    $handler->submitConsumerNativeAppsSettings([], $state);
    $this->assertSame($owner->uuid(), $this->config($imported_name)->get('consumer_uuid'));
    $this->assertSame('enhanced', $this->config($imported_name)->get('enhanced_pkce_override'));
    $this->assertTrue($this->container->get('config.factory')->get('simple_oauth_native_apps.consumer.' . $owner->id())->isNew());
    $state->setValue('native_apps', ['enhanced_pkce_override' => '']);
    $handler->submitConsumerNativeAppsSettings([], $state);
    $this->assertTrue($this->container->get('config.factory')->get($imported_name)->isNew());
  }

  /**
   * Tests legacy reads and saves never bind without explicit operator choice.
   */
  public function testLegacyOwnershipConfirmationPreservesValues(): void {
    $this->installEntitySchema('oauth2_token');
    [$owner, $unrelated, $name, $data] = $this->createBoundPolicyCollision();
    unset($data['consumer_uuid']);
    $data['allow_loopback_override'] = '';
    $data['client_detection'] = [];
    $this->config($name)->setData($data)->save();
    $data = $this->config($name)->get();
    $handler = $this->container->get('simple_oauth_native_apps.consumer_form_alter');
    $detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    $this->assertFalse($detector->requiresEnhancedPkce($unrelated));
    $this->assertTrue($detector->requiresEnhancedPkce($owner));
    $unrelated->save();
    $state = $this->policyFormState($unrelated, $data);
    $form = [];
    $handler->alterForm($form, $state, 'consumer_edit_form');
    $this->assertFalse($form['native_apps']['confirm_policy_owner']['#default_value']);
    $this->assertStringContainsString('historical owner', (string) $form['native_apps']['confirm_policy_owner']['#description']);
    $handler->validateConsumerNativeAppsSettings($form, $state);
    $this->assertFalse($state->hasAnyErrors());
    $handler->submitConsumerNativeAppsSettings($form, $state);
    $this->assertSame($data, $this->config($name)->get());
    $state->setValue(['native_apps', 'confirm_policy_owner'], 1);
    $handler->submitConsumerNativeAppsSettings($form, $state);
    $bound = $this->config($name)->get();
    $this->assertSame($unrelated->uuid(), $bound['consumer_uuid']);
    unset($bound['consumer_uuid']);
    $this->assertSame($data, $bound);
    $this->assertTrue($detector->getEffectivePolicy($unrelated)['loopback']);
  }

  /**
   * Tests fresh decisions after config saves, deletes and consumer updates.
   */
  public function testPolicyMutationFreshnessAndStaleWriteGuard(): void {
    $this->installEntitySchema('oauth2_token');
    [$owner, $unrelated, $name, $data] = $this->createBoundPolicyCollision();
    $detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    $handler = $this->container->get('simple_oauth_native_apps.consumer_form_alter');
    $this->assertFalse($detector->requiresEnhancedPkce($owner));
    $this->config($name)->set('consumer_uuid', $unrelated->uuid())->set('enhanced_pkce_override', 'enhanced')->save();
    $this->assertTrue($detector->requiresEnhancedPkce($owner));
    $this->assertTrue($detector->requiresEnhancedPkce($unrelated));
    $this->config($name)->set('enhanced_pkce_override', 'not-enhanced')->save();
    $this->assertFalse($detector->requiresEnhancedPkce($unrelated));
    $this->config($name)->delete();
    $this->assertTrue($detector->requiresEnhancedPkce($unrelated));
    $state = $this->policyFormState($unrelated, ['enhanced_pkce_override' => 'enhanced']);
    $handler->validateConsumerNativeAppsSettings([], $state);
    $this->assertFalse($state->hasAnyErrors());
    $this->config($name)->setData($data)->save();
    try {
      $handler->submitConsumerNativeAppsSettings([], $state);
      $this->fail('A destination taken after validation must not be overwritten.');
    }
    catch (\UnexpectedValueException) {
      $this->assertSame($data, $this->config($name)->get());
    }
    $owner->set('native_app_enhanced_pkce', 'native')->save();
    $this->assertFalse($detector->requiresEnhancedPkce($owner));
    $this->config($name)->delete();
    $this->assertTrue($detector->requiresEnhancedPkce($owner));
    $built = clone $owner;
    $detector->setSubmittedOverrides($built, ['enhanced_pkce_override' => 'not-enhanced']);
    $this->assertFalse($detector->requiresEnhancedPkce($built));
    $built->save();
    $this->assertTrue($detector->requiresEnhancedPkce($built));
    $new = Consumer::create(['label' => 'New pending policy', 'client_id' => 'new-pending-policy']);
    $detector->setSubmittedOverrides($new, ['enhanced_pkce_override' => 'not-enhanced']);
    $this->assertFalse($detector->requiresEnhancedPkce($new));
    $new->save();
    $this->assertTrue($detector->requiresEnhancedPkce($new));
  }

  /**
   * Tests original constructors, programmatic saves and service serialization.
   */
  public function testOriginalPolicyConstructorsAndSerialization(): void {
    [$owner, $unrelated, $name] = $this->createBoundPolicyCollision();
    $factory = $this->container->get('config.factory');
    $detector = new NativeClientDetector($factory, $this->container->get('logger.factory'));
    $this->assertFalse($detector->requiresEnhancedPkce($owner));
    $handler = new ConsumerNativeAppsFormAlter($factory, $this->container->get('entity_type.manager'), $this->container->get('simple_oauth_native_apps.configuration_validator'), $detector);
    $this->config($name)->delete();
    $state = $this->policyFormState($unrelated, ['enhanced_pkce_override' => 'enhanced']);
    $manual_form = [];
    $handler->alterForm($manual_form, $state, 'consumer_edit_form');
    $handler->submitConsumerNativeAppsSettings($manual_form, $state);
    $this->assertSame(['enhanced_pkce_override' => 'enhanced'], $this->config($name)->get());
    $handler->submitConsumerNativeAppsSettings([], $state);
    $this->assertSame(['enhanced_pkce_override' => 'enhanced'], $this->config($name)->get());
    $state->setValue(['native_apps', 'consumer_uuid'], $unrelated->uuid());
    $handler->submitConsumerNativeAppsSettings([], $state);
    $this->assertSame($unrelated->uuid(), $this->config($name)->get('consumer_uuid'));
    $service = unserialize(serialize($this->container->get('simple_oauth_native_apps.consumer_form_alter')), ['allowed_classes' => [ConsumerNativeAppsFormAlter::class]]);
    $form = [];
    $service->alterForm($form, $state, 'consumer_edit_form');
    $this->assertSame('enhanced', $form['native_apps']['enhanced_pkce_override']['#default_value']);
    $this->assertSame($unrelated->uuid(), $this->config($name)->get('consumer_uuid'));
  }

  /**
   * Tests one lookup supplies the entire effective policy.
   */
  public function testPolicyLookupCost(): void {
    [$owner] = $this->createBoundPolicyCollision();
    $real_factory = $this->container->get('config.factory');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->expects($this->once())->method('listAll')->willReturnCallback($real_factory->listAll(...));
    $factory->expects($this->once())->method('loadMultiple')->willReturnCallback($real_factory->loadMultiple(...));
    $factory->method('get')->willReturnCallback($real_factory->get(...));
    $detector = new NativeClientDetector($factory, $this->container->get('logger.factory'), new ConsumerPolicyResolver($factory));
    $this->assertFalse($detector->getEffectivePolicy($owner)['enhanced_pkce']);
  }

  /**
   * Tests Drupal UUID schema validation while binding remains optional.
   */
  public function testPolicyUuidSchemaValidation(): void {
    [, , $name, $data] = $this->createBoundPolicyCollision();
    $manager = $this->container->get('config.typed');
    $typed = $manager->createFromNameAndData($name, $data);
    $this->assertSame('uuid', $typed->get('consumer_uuid')->getDataDefinition()->getDataType());
    $this->assertCount(0, $typed->validate());
    foreach (['', 'invalid', NULL] as $invalid) {
      $data['consumer_uuid'] = $invalid;
      $this->assertGreaterThan(0, count($manager->createFromNameAndData($name, $data)->validate()));
    }
    $data['consumer_uuid'] = [];
    try {
      $manager->createFromNameAndData($name, $data)->validate();
      $this->fail('Core UUID validation must reject non-string bindings.');
    }
    catch (ValidationUnexpectedValueException) {
      $this->assertIsArray($data['consumer_uuid']);
    }
    unset($data['consumer_uuid']);
    $this->assertCount(0, $manager->createFromNameAndData($name, $data)->validate());
  }

  /**
   * Tests actual importer validation and activation preserve portable identity.
   */
  public function testImportBoundPolicyAndMutationFreshness(): void {
    [$owner, $unrelated, $name, $data] = $this->createBoundPolicyCollision();
    $detector = $this->container->get('simple_oauth_native_apps.native_client_detector');
    $this->assertFalse($detector->requiresEnhancedPkce($owner));
    $source = $this->policyImportSource();
    $changed = $data;
    $changed['enhanced_pkce_override'] = 'enhanced';
    $source->write($name, $changed);
    $importer = $this->policyImporter($source);
    $importer->validate();
    $this->assertSame([], $importer->getErrors());
    $importer->import();
    $this->assertSame($changed, $this->config($name)->get());
    $this->assertTrue($detector->requiresEnhancedPkce($owner));
    $this->assertTrue($detector->requiresEnhancedPkce($unrelated));
    $source->delete($name);
    $this->policyImporter($source)->import();
    $this->assertTrue($this->container->get('config.factory')->get($name)->isNew());
    $this->assertTrue($detector->requiresEnhancedPkce($owner));
    $source->write($name, $data);
    $this->policyImporter($source)->import();
    $this->assertSame($data, $this->config($name)->get());
    $this->assertFalse($detector->requiresEnhancedPkce($owner));
  }

  /**
   * Tests unchanged legacy survives imports but changed or new unbound cannot.
   */
  public function testImportLegacyRejectionAndUnchangedPreservation(): void {
    [, , $name, $data] = $this->createBoundPolicyCollision();
    unset($data['consumer_uuid']);
    $this->config($name)->setData($data)->save();
    $source = $this->policyImportSource();
    $settings = $source->read('simple_oauth_native_apps.settings');
    $settings['webview']['detection'] = 'warn';
    $source->write('simple_oauth_native_apps.settings', $settings);
    $this->policyImporter($source)->import();
    $this->assertSame($data, $this->config($name)->get());
    $changed = $data;
    $changed['webview_detection_override'] = 'warn';
    $source->write($name, $changed);
    $this->assertPolicyImportRejected($source, 'Assign this legacy policy');
    $this->assertSame($data, $this->config($name)->get());
    $source->write($name, $data);
    $source->write('simple_oauth_native_apps.consumer.987654', $data);
    $this->assertPolicyImportRejected($source, 'Assign this legacy policy');
    $this->assertTrue($this->container->get('config.factory')->get('simple_oauth_native_apps.consumer.987654')->isNew());
  }

  /**
   * Tests malformed, orphan, duplicate and bound/unbound incoming conflicts.
   */
  public function testImportRejectsInvalidOwnership(): void {
    [$owner, , $name, $data] = $this->createBoundPolicyCollision();
    foreach (['invalid', '', NULL, [], 'ecb78c78-8b91-45e0-98d0-0d79168ff167'] as $uuid) {
      $source = $this->policyImportSource();
      $changed = $data;
      $changed['consumer_uuid'] = $uuid;
      $source->write($name, $changed);
      $this->assertPolicyImportRejected($source, is_string($uuid) && strlen($uuid) === 36 ? 'existing consumer' : 'valid consumer UUID');
      $this->assertSame($data, $this->config($name)->get());
    }
    foreach ([$data, ['enhanced_pkce_override' => 'enhanced']] as $competing) {
      $source = $this->policyImportSource();
      $source->write('simple_oauth_native_apps.consumer.' . $owner->id(), $competing);
      $changed = $data;
      $changed['webview_detection_override'] = 'warn';
      $source->write($name, $changed);
      $this->assertPolicyImportRejected($source, 'conflicts with another incoming policy');
    }
    $source = $this->policyImportSource();
    $source->delete($name);
    $source->write('simple_oauth_native_apps.consumer.invalid', $data);
    $this->assertPolicyImportRejected($source, 'valid consumer UUID');
    foreach (['invalid', []] as $invalid) {
      $source = $this->policyImportSource();
      $changed = $data;
      $changed['webview_detection_override'] = $invalid;
      $source->write($name, $changed);
      $this->assertPolicyImportRejected($source, 'contains invalid settings');
    }
    // The native subscriber must leave core validation enabled.
    $source = $this->policyImportSource();
    $source->delete('core.extension');
    $this->assertPolicyImportRejected($source, 'core.extension');
  }

  /**
   * Creates form state with a real consumer and a form boundary double.
   *
   * @param \Drupal\consumers\Entity\Consumer $consumer
   *   The real persisted or new consumer.
   * @param array $settings
   *   Submitted native settings.
   *
   * @return \Drupal\Core\Form\FormState
   *   The form state.
   */
  private function policyFormState(Consumer $consumer, array $settings): FormState {
    $form_object = $this->createMock(EntityFormInterface::class);
    $form_object->method('getEntity')->willReturn($consumer);
    return (new FormState())->setFormObject($form_object)->setValue('native_apps', $settings);
  }

  /**
   * Copies active config into an incoming snapshot with core validators.
   *
   * @return \Drupal\Core\Config\MemoryStorage
   *   The incoming configuration storage.
   */
  private function policyImportSource(): MemoryStorage {
    $this->config('system.site')->set('uuid', 'a18d7807-e012-47a8-9517-72763a457666')->save();
    $this->config('core.extension')->set('module', array_fill_keys(array_keys($this->container->get('module_handler')->getModuleList()), 0))->set('theme', [])->save();
    $source = new MemoryStorage();
    $active = $this->container->get('config.storage');
    foreach ($active->readMultiple($active->listAll()) as $name => $data) {
      $source->write($name, $data);
    }
    return $source;
  }

  /**
   * Constructs a real importer with the container's event dispatcher.
   *
   * @param \Drupal\Core\Config\MemoryStorage $source
   *   The incoming storage.
   *
   * @return \Drupal\Core\Config\ConfigImporter
   *   The core importer with a calculated changelist.
   */
  private function policyImporter(MemoryStorage $source): ConfigImporter {
    $subscriber = $this->container->get('simple_oauth_native_apps.policy_import_subscriber');
    $this->assertContains([$subscriber, 'validateImport'], $this->container->get('event_dispatcher')->getListeners(ConfigEvents::IMPORT_VALIDATE));
    $comparer = new StorageComparer($source, $this->container->get('config.storage'));
    return new ConfigImporter(
      $comparer->createChangelist(),
      $this->container->get('event_dispatcher'),
      $this->container->get('config.manager'),
      $this->container->get('lock.persistent'),
      $this->container->get('config.typed'),
      $this->container->get('module_handler'),
      $this->container->get('module_installer'),
      $this->container->get('theme_handler'),
      $this->container->get('string_translation'),
      $this->container->get('extension.list.module'),
      $this->container->get('extension.list.theme'),
    );
  }

  /**
   * Asserts core rejects a snapshot without activating any of its policies.
   *
   * @param \Drupal\Core\Config\MemoryStorage $source
   *   The incoming storage.
   * @param string $message
   *   An actionable fragment expected in validation errors.
   */
  private function assertPolicyImportRejected(MemoryStorage $source, string $message): void {
    $importer = $this->policyImporter($source);
    $before = $this->container->get('config.storage')->readMultiple($this->container->get('config.storage')->listAll('simple_oauth_native_apps.consumer.'));
    try {
      $importer->import();
      $this->fail('Unsafe policy import must be rejected before activation.');
    }
    catch (ConfigImporterException $exception) {
      $this->assertStringContainsString($message, $exception->getMessage());
      $this->assertNotEmpty($importer->getErrors());
      $this->assertSame($before, $this->container->get('config.storage')->readMultiple($this->container->get('config.storage')->listAll('simple_oauth_native_apps.consumer.')));
    }
  }

}
