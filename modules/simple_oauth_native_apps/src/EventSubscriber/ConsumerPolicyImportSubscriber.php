<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_native_apps\EventSubscriber;

use Drupal\Component\Uuid\Uuid;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\simple_oauth_native_apps\Service\ConsumerPolicyResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Rejects unsafe new or changed consumer policies before config activation.
 *
 * This subscriber runs only when Native Apps is already enabled in the
 * validating container. Enable the module before importing its policies; an
 * import that first enables it cannot rely on this subscriber. Runtime UUID
 * matching remains independent of the import path and module activation.
 */
final class ConsumerPolicyImportSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs the import validator.
   *
   * @param \Drupal\simple_oauth_native_apps\Service\ConsumerPolicyResolver $policyResolver
   *   The shared policy resolver.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager used to verify existing UUID owners.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The schema validator for incoming policy values.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The translation manager.
   */
  public function __construct(
    private readonly ConsumerPolicyResolver $policyResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TypedConfigManagerInterface $typedConfigManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::IMPORT_VALIDATE => 'validateImport'];
  }

  /**
   * Validates changed records against the entire incoming policy snapshot.
   *
   * Unchanged active legacy policies survive unrelated imports. New or changed
   * unbound records must be reviewed and bound on the source, not guessed here.
   * Core validation remains responsible for the rest of the import.
   *
   * @param \Drupal\Core\Config\ConfigImporterEvent $event
   *   The core import validation event.
   */
  public function validateImport(ConfigImporterEvent $event): void {
    $importer = $event->getConfigImporter();
    $changed_names = array_merge($event->getChangelist('create'), $event->getChangelist('update'));
    $source = $importer->getStorageComparer()->getSourceStorage();
    $settings_name = 'simple_oauth_native_apps.settings';
    if (in_array($settings_name, $changed_names, TRUE)) {
      $settings = $source->read($settings_name);
      $typed = $this->typedConfigManager->createFromNameAndData($settings_name, $settings);
      foreach ($typed as $key => $value) {
        if (in_array($key, ['detection_sensitivity', 'cache_detection_results', 'allow_plain_pkce'], TRUE) && count($value->validate()) !== 0) {
          $importer->logError((string) $this->t('Native settings @key is invalid. Use a number from 0 to 1 for detection_sensitivity and boolean values for cache_detection_results and allow_plain_pkce.', ['@key' => $key]));
        }
      }
    }
    $changed = array_filter($changed_names, static fn ($name) => str_starts_with($name, ConsumerPolicyResolver::PREFIX));
    if ($changed === []) {
      return;
    }
    $records = $source->readMultiple($source->listAll(ConsumerPolicyResolver::PREFIX));
    $storage = $this->entityTypeManager->getStorage('consumer');
    foreach ($changed as $name) {
      $data = $records[$name] ?? [];
      $uuid = $data['consumer_uuid'] ?? NULL;
      if (!preg_match('/^' . preg_quote(ConsumerPolicyResolver::PREFIX, '/') . '[0-9]+$/D', $name) || !is_string($uuid) || !Uuid::isValid($uuid)) {
        $importer->logError((string) $this->t('Native policy @name requires a valid consumer UUID binding. On the source site, review the consumer Native App Settings, select "Assign this legacy policy to this consumer after review" for an unbound record, save, and export again. Enable Native Apps before importing its policies so this validation can run.', ['@name' => $name]));
        continue;
      }
      try {
        $valid = count($this->typedConfigManager->createFromNameAndData($name, $data)->validate()) === 0;
      }
      catch (UnexpectedTypeException) {
        $valid = FALSE;
      }
      if (!$valid) {
        $importer->logError((string) $this->t('Native policy @name contains invalid settings. Correct its Native App Settings on the source site and export again.', ['@name' => $name]));
        continue;
      }
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('uuid', strtolower($uuid))->range(0, 2)->execute();
      if (count($ids) !== 1) {
        $importer->logError((string) $this->t('Native policy @name must identify exactly one existing consumer by UUID. Create or restore its intended consumer before importing; do not replace the binding with a numeric ID.', ['@name' => $name]));
        continue;
      }
      $consumer = $storage->load(reset($ids));
      if (!$consumer instanceof ConsumerInterface) {
        $importer->logError((string) $this->t('Native policy @name has no existing consumer. Restore its UUID owner before importing.', ['@name' => $name]));
        continue;
      }
      try {
        $record = $this->policyResolver->resolve($consumer, $records);
        if ($record['name'] !== $name) {
          throw new \UnexpectedValueException('The incoming policy does not resolve to its owner.');
        }
      }
      catch (\UnexpectedValueException) {
        $importer->logError((string) $this->t('Native policy @name conflicts with another incoming policy for its consumer. Reconcile duplicate UUID bindings and competing unbound numeric records on the source site before exporting again.', ['@name' => $name]));
      }
    }
  }

}
