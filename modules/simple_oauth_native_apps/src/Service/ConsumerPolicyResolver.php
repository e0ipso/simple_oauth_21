<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_native_apps\Service;

use Drupal\Component\Uuid\Uuid;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\ConfigRenameEvent;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Resolves and writes whole native policy records by consumer identity.
 *
 * Numeric names remain export-compatible. Unbound records retain their legacy
 * numeric meaning; their historical owner cannot be established from config.
 * A disposable UUID index narrows active reads to candidate records. Ownership
 * always uses raw configuration, independently of runtime policy overrides.
 */
final class ConsumerPolicyResolver implements EventSubscriberInterface {

  use DependencySerializationTrait;

  /**
   * The existing policy configuration prefix.
   */
  public const PREFIX = 'simple_oauth_native_apps.consumer.';

  /**
   * Cache identifier for the derived UUID to configuration name index.
   */
  private const INDEX_CID = 'simple_oauth_native_apps:consumer_policy_index';

  /**
   * Policy controls, separate from binding and presentation metadata.
   */
  private const POLICY_KEYS = [
    'webview_detection_override',
    'allow_custom_schemes_override',
    'allow_loopback_override',
    'enhanced_pkce_override',
  ];

  /**
   * Constructs the policy boundary.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   The derived index cache, or NULL for uncached original constructor calls.
   * @param \Drupal\Core\Cache\CacheTagsChecksumInterface|null $cacheTagsChecksum
   *   Invalidation checksums, or NULL to retain uncached compatibility.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly ?CacheBackendInterface $cache = NULL,
    protected readonly ?CacheTagsChecksumInterface $cacheTagsChecksum = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => 'invalidateIndex',
      ConfigEvents::DELETE => 'invalidateIndex',
      ConfigEvents::RENAME => 'invalidateIndex',
      ConfigEvents::IMPORT => 'onImport',
    ];
  }

  /**
   * Discards derived names after policy writes and completed imports.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent|null $event
   *   The saved or deleted configuration, or NULL for a completed import.
   */
  public function invalidateIndex(?ConfigCrudEvent $event = NULL): void {
    if ($event === NULL || str_starts_with($event->getConfig()->getName(), self::PREFIX) ||
      ($event instanceof ConfigRenameEvent && str_starts_with($event->getOldName(), self::PREFIX))) {
      $this->cache?->delete(self::INDEX_CID);
      Cache::invalidateTags([self::INDEX_CID]);
    }
  }

  /**
   * Invalidates the lookup after a completed configuration import.
   *
   * @param \Drupal\Core\Config\ConfigImporterEvent $event
   *   The completed import event.
   */
  public function onImport(ConfigImporterEvent $event): void {
    $this->invalidateIndex();
  }

  /**
   * Loads raw candidates without scanning unrelated policies on cache hits.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer whose local slot and UUID bindings must all be inspected.
   *
   * @return array<string, array>
   *   Candidate records, including malformed local and nonnumeric bound names.
   */
  private function getCandidates(ConsumerInterface $consumer): array {
    if ($this->cache === NULL || $this->cacheTagsChecksum === NULL) {
      return $this->getRecords();
    }
    $cached = $this->cache->get(self::INDEX_CID);
    if ($cached === FALSE || !$this->cacheTagsChecksum->isValid($cached->data['checksum'], [self::INDEX_CID])) {
      // Capture before reading: a concurrent write must invalidate this build,
      // even if it finishes before the resulting cache item is stored.
      $checksum = $this->cacheTagsChecksum->getCurrentChecksum([self::INDEX_CID]);
      $index = [];
      $records = $this->getRecords();
      foreach ($records as $name => $data) {
        $uuid = $data['consumer_uuid'] ?? NULL;
        if (is_string($uuid) && Uuid::isValid($uuid)) {
          $index[strtolower($uuid)][] = $name;
        }
      }
      $this->cache->set(self::INDEX_CID, [
        'checksum' => $checksum,
        'names' => $index,
      ], Cache::PERMANENT, [self::INDEX_CID]);
      // The initial read already contains every candidate.
      return $records;
    }
    $names = $cached->data['names'][strtolower((string) $consumer->uuid())] ?? [];
    if ($consumer->id() !== NULL) {
      $names[] = self::PREFIX . $consumer->id();
    }
    $records = [];
    foreach ($this->configFactory->loadMultiple(array_unique($names)) as $name => $config) {
      $records[$name] = $config->getRawData();
    }
    return $records;
  }

  /**
   * Reads active records without interpreting numeric suffixes as UUIDs.
   *
   * @return array<string, array>
   *   Whole records keyed by configuration name, without runtime overrides.
   */
  public function getRecords(): array {
    $records = [];
    foreach ($this->configFactory->loadMultiple($this->configFactory->listAll(self::PREFIX)) as $name => $config) {
      $records[$name] = $config->getRawData();
    }
    return $records;
  }

  /**
   * Resolves one complete owned record, including optional import snapshots.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer whose identity is known to the caller.
   * @param array<string, array>|null $records
   *   An incoming snapshot, or NULL to read active configuration.
   *
   * @return array{name: ?string, data: array}
   *   The selected record, or no record. Policy keys are never merged.
   *
   * @throws \UnexpectedValueException
   *   Multiple records compete for the consumer, or its slot is malformed.
   */
  public function resolve(ConsumerInterface $consumer, ?array $records = NULL): array {
    $records ??= $this->getCandidates($consumer);
    $consumer_id = $consumer->id();
    $local_name = $consumer_id !== NULL ? self::PREFIX . $consumer_id : NULL;
    $uuid = strtolower((string) $consumer->uuid());
    $matches = [];
    foreach ($records as $name => $data) {
      if (array_key_exists('consumer_uuid', $data)) {
        $binding = $data['consumer_uuid'];
        if (!is_string($binding) || !Uuid::isValid($binding)) {
          if ($name === $local_name) {
            throw new \UnexpectedValueException('The local native policy binding is malformed.');
          }
          continue;
        }
        if (strtolower($binding) === $uuid) {
          if (!preg_match('/^' . preg_quote(self::PREFIX, '/') . '[0-9]+$/D', $name)) {
            throw new \UnexpectedValueException('Native policy requires a numeric configuration name.');
          }
          $matches[$name] = $data;
        }
      }
      elseif ($name === $local_name && !$consumer->isNew()) {
        $matches[$name] = $data;
      }
    }
    if (count($matches) > 1) {
      throw new \UnexpectedValueException('Multiple native policy records require ownership reconciliation.');
    }
    $name = array_key_first($matches);
    return ['name' => $name, 'data' => $name !== NULL ? $matches[$name] : []];
  }

  /**
   * Reads policy values with configuration overrides after selecting ownership.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer.
   *
   * @return array
   *   One complete record with runtime overrides applied.
   *
   * @throws \UnexpectedValueException
   *   Ownership is ambiguous or malformed.
   */
  public function getPolicy(ConsumerInterface $consumer): array {
    $record = $this->resolve($consumer);
    return $record['name'] !== NULL ? $this->configFactory->get($record['name'])->get() : [];
  }

  /**
   * Checks identity and destination availability before entity or policy saves.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer to configure.
   * @param string|null $binding
   *   An explicitly supplied binding, if any.
   *
   * @return array{name: ?string, data: array}
   *   The existing owned record, if any.
   *
   * @throws \UnexpectedValueException
   *   Ownership conflicts, the UUID is invalid, or the slot is occupied.
   */
  public function assertWritable(ConsumerInterface $consumer, ?string $binding = NULL): array {
    $uuid = $consumer->uuid();
    if (!is_string($uuid) || !Uuid::isValid($uuid) || ($binding !== NULL && (!Uuid::isValid($binding) || strtolower($binding) !== strtolower($uuid)))) {
      throw new \UnexpectedValueException('Native policy binding must match the consumer UUID.');
    }
    $records = $this->getRecords();
    $record = $this->resolve($consumer, $records);
    if ($record['name'] === NULL && $consumer->id() !== NULL && isset($records[self::PREFIX . $consumer->id()])) {
      throw new \UnexpectedValueException('The numeric native policy slot belongs to another consumer.');
    }
    return $record;
  }

  /**
   * Persists an explicit edit, rechecking ownership without rekeying records.
   *
   * Existing bindings cannot be removed or changed by submitted settings.
   * Legacy programmatic calls stay unbound unless binding is explicit. An empty
   * edit retains the existing clear semantics and deletes only its owner.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The persisted consumer being configured.
   * @param array $settings
   *   Submitted settings, optionally including a matching consumer_uuid.
   * @param bool $bind
   *   Whether the caller explicitly requests binding to this consumer.
   * @param bool $confirmation_only
   *   Whether to bind a reviewed legacy record without changing its values.
   *
   * @throws \UnexpectedValueException
   *   The consumer is unsaved or ownership cannot be safely established.
   */
  public function save(ConsumerInterface $consumer, array $settings, bool $bind = FALSE, bool $confirmation_only = FALSE): void {
    $binding = $settings['consumer_uuid'] ?? NULL;
    if (array_key_exists('consumer_uuid', $settings) && !is_string($binding)) {
      throw new \UnexpectedValueException('Native policy binding must be a UUID string.');
    }
    $record = $this->assertWritable($consumer, $binding);
    if ($consumer->id() === NULL || $consumer->isNew()) {
      throw new \UnexpectedValueException('Native policy requires a saved consumer.');
    }
    unset($settings['consumer_uuid'], $settings['confirm_policy_owner']);
    $filtered = array_filter($settings, static fn ($value) => $value !== '');
    if (!$confirmation_only && $filtered === []) {
      if ($record['name'] !== NULL) {
        $this->configFactory->getEditable($record['name'])->delete();
      }
      return;
    }
    if ($confirmation_only) {
      if ($record['name'] === NULL || array_key_exists('consumer_uuid', $record['data']) || !$bind) {
        throw new \UnexpectedValueException('Ownership confirmation requires an unbound native policy.');
      }
      $data = $record['data'];
    }
    else {
      // Explicit edits replace policy controls, but retain other stored values.
      $data = array_diff_key($record['data'], array_fill_keys(self::POLICY_KEYS, TRUE));
      $data = array_replace($data, $filtered);
      foreach ($settings as $key => $value) {
        if ($value === '' && array_key_exists($key, $record['data']) && $record['data'][$key] === '') {
          $data[$key] = '';
        }
      }
    }
    if ($bind || $binding !== NULL) {
      $data['consumer_uuid'] = $consumer->uuid();
    }
    $name = $record['name'] ?? self::PREFIX . $consumer->id();
    $this->configFactory->getEditable($name)->setData($data)->save();
  }

  /**
   * Deletes only the resolved policy of a deleted consumer.
   *
   * Unbound legacy cleanup retains numeric compatibility. Its historical
   * ownership is unknowable until an administrator explicitly chooses an owner.
   * Conflicting records require reconciliation and are never deleted here.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer being deleted.
   *
   * @throws \UnexpectedValueException
   *   Ownership requires reconciliation.
   */
  public function delete(ConsumerInterface $consumer): void {
    $record = $this->resolve($consumer);
    if ($record['name'] !== NULL) {
      $this->configFactory->getEditable($record['name'])->delete();
    }
  }

}
