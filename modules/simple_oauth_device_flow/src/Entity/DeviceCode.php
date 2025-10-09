<?php

namespace Drupal\simple_oauth_device_flow\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\simple_oauth\Entities\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

/**
 * Defines the Device Code entity for OAuth 2.0 Device Authorization Grant.
 *
 * @ingroup simple_oauth_device_flow
 */
#[ContentEntityType(
  id: 'oauth2_device_code',
  label: new TranslatableMarkup('OAuth2 Device Code'),
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
  ],
  base_table: 'oauth2_device_code',
  admin_permission: 'administer simple_oauth entities',
  entity_keys: [
    'id' => 'device_code',
    'langcode' => 'langcode',
  ],
  list_cache_tags: ['oauth2_device_code'],
)]
class DeviceCode extends ContentEntityBase implements DeviceCodeEntityInterface {

  /**
   * The client entity.
   *
   * @var \League\OAuth2\Server\Entities\ClientEntityInterface|null
   */
  private ?ClientEntityInterface $clientEntity = NULL;

  /**
   * Array of scope entities.
   *
   * @var \League\OAuth2\Server\Entities\ScopeEntityInterface[]
   */
  private array $scopeEntities = [];

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Remove the default id field since we use device_code as primary key.
    unset($fields['id']);

    $fields['device_code'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Device Code'))
      ->setDescription(t('The device code identifier.'))
      ->setRequired(TRUE)
      ->setTranslatable(FALSE)
      ->setSettings([
        'max_length' => 128,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 1,
      ]);

    $fields['user_code'] = BaseFieldDefinition::create('string')
      ->setLabel(t('User Code'))
      ->setDescription(t('Human-readable user code.'))
      ->setRequired(TRUE)
      ->setTranslatable(FALSE)
      ->setSettings([
        'max_length' => 32,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 2,
      ]);

    $fields['client_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Client ID'))
      ->setDescription(t('OAuth client identifier.'))
      ->setRequired(TRUE)
      ->setTranslatable(FALSE)
      ->setSettings([
        'max_length' => 128,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 3,
      ]);

    $fields['scopes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Scopes'))
      ->setDescription(t('Serialized array of requested scopes.'))
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 4,
      ]);

    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User'))
      ->setDescription(t('User ID once authorized.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 5,
      ]);

    $fields['authorized'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Authorized'))
      ->setDescription(t('Authorization status.'))
      ->setDefaultValue(FALSE)
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => 6,
      ]);

    $fields['created_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Created'))
      ->setDescription(t('Creation timestamp.'))
      ->setRequired(TRUE)
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 7,
      ]);

    $fields['expires_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Expires'))
      ->setDescription(t('Expiration timestamp.'))
      ->setRequired(TRUE)
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 8,
      ]);

    $fields['last_polled_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Last Polled'))
      ->setDescription(t('Last polling timestamp.'))
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 9,
      ]);

    $fields['interval'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Interval'))
      ->setDescription(t('Polling interval in seconds.'))
      ->setDefaultValue(5)
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => 10,
      ]);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIdentifier(): string {
    return $this->get('device_code')->value ?: '';
  }

  /**
   * {@inheritdoc}
   */
  public function setIdentifier(string $identifier): void {
    $this->set('device_code', $identifier);
  }

  /**
   * {@inheritdoc}
   */
  public function getExpiryDateTime(): \DateTimeImmutable {
    $timestamp = $this->get('expires_at')->value;
    return new \DateTimeImmutable('@' . $timestamp);
  }

  /**
   * {@inheritdoc}
   */
  public function setExpiryDateTime(\DateTimeImmutable $dateTime): void {
    $this->set('expires_at', $dateTime->getTimestamp());
  }

  /**
   * {@inheritdoc}
   */
  public function getUserIdentifier(): ?string {
    $user_id = $this->get('user_id')->target_id;
    return $user_id ? (string) $user_id : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setUserIdentifier(string $identifier): void {
    $this->set('user_id', $identifier);
  }

  /**
   * {@inheritdoc}
   */
  public function getClient(): ClientEntityInterface {
    if ($this->clientEntity === NULL) {
      throw new \RuntimeException('Client entity has not been set.');
    }
    return $this->clientEntity;
  }

  /**
   * {@inheritdoc}
   */
  public function setClient(ClientEntityInterface $client): void {
    $this->clientEntity = $client;
    // Set the client_id field using the client's identifier.
    $this->set('client_id', $client->getIdentifier());
  }

  /**
   * {@inheritdoc}
   */
  public function getScopes(): array {
    if (empty($this->scopeEntities)) {
      $this->loadScopesFromDatabase();
    }
    return $this->scopeEntities;
  }

  /**
   * {@inheritdoc}
   */
  public function addScope(ScopeEntityInterface $scope): void {
    $this->scopeEntities[] = $scope;
    $this->saveScopesToDatabase();
  }

  /**
   * {@inheritdoc}
   */
  public function getUserCode(): string {
    return $this->get('user_code')->value ?: '';
  }

  /**
   * {@inheritdoc}
   */
  public function setUserCode(string $userCode): void {
    $this->set('user_code', $userCode);
  }

  /**
   * {@inheritdoc}
   */
  public function getVerificationUri(): string {
    $request = \Drupal::request();
    if ($request && $request->getSchemeAndHttpHost()) {
      $base_url = $request->getSchemeAndHttpHost();
      return $base_url . '/oauth/device/verify';
    }
    // Fallback for contexts where request is not available.
    return 'http://localhost/oauth/device/verify';
  }

  /**
   * {@inheritdoc}
   */
  public function setVerificationUri(string $verificationUri): void {
    // The verification URI is constructed dynamically, not stored.
    // This method is required by the interface but doesn't need implementation.
  }

  /**
   * {@inheritdoc}
   */
  public function getVerificationUriComplete(): string {
    return $this->getVerificationUri() . '?user_code=' . $this->getUserCode();
  }

  /**
   * {@inheritdoc}
   */
  public function getLastPolledAt(): ?\DateTimeImmutable {
    $timestamp = $this->get('last_polled_at')->value;
    return $timestamp ? new \DateTimeImmutable('@' . $timestamp) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setLastPolledAt(\DateTimeImmutable $lastPolledAt): void {
    $this->set('last_polled_at', $lastPolledAt->getTimestamp());
  }

  /**
   * {@inheritdoc}
   */
  public function getInterval(): int {
    return (int) $this->get('interval')->value ?: 5;
  }

  /**
   * {@inheritdoc}
   */
  public function setInterval(int $interval): void {
    $this->set('interval', $interval);
  }

  /**
   * {@inheritdoc}
   */
  public function getUserApproved(): bool {
    $value = $this->get('authorized')->first();
    return $value ? (bool) $value->getValue()['value'] : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function setUserApproved(bool $userApproved): void {
    $this->set('authorized', $userApproved);
  }

  /**
   * Load scopes from the database field.
   */
  private function loadScopesFromDatabase(): void {
    $scopes_data = $this->get('scopes')->value;
    if ($scopes_data) {
      $scope_identifiers = unserialize($scopes_data, ['allowed_classes' => FALSE]);
      if (is_array($scope_identifiers)) {
        foreach ($scope_identifiers as $scope_id) {
          // Load the actual scope entity from storage.
          $scope_storage = \Drupal::entityTypeManager()->getStorage('oauth2_scope');
          $scope_entities = $scope_storage->loadByProperties(['name' => $scope_id]);
          if (!empty($scope_entities)) {
            $scope_entity_obj = reset($scope_entities);
            $scope_entity = new ScopeEntity($scope_entity_obj);
            $this->scopeEntities[] = $scope_entity;
          }
        }
      }
    }
  }

  /**
   * Save scopes to the database field.
   */
  private function saveScopesToDatabase(): void {
    $scope_identifiers = [];
    foreach ($this->scopeEntities as $scope) {
      $scope_identifiers[] = $scope->getIdentifier();
    }
    $this->set('scopes', serialize($scope_identifiers));
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

    // Ensure scopes are serialized before saving.
    if (!empty($this->scopeEntities)) {
      $this->saveScopesToDatabase();
    }

    // Set created timestamp if not set.
    if ($this->isNew() && !$this->get('created_at')->value) {
      $this->set('created_at', \Drupal::time()->getRequestTime());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTagsToInvalidate() {
    // Similar to OAuth2 tokens, avoid creating unique cache tags for each
    // device code to prevent cache tag bloat.
    return ['oauth2_device_code'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return static::getCacheTagsToInvalidate();
  }

}
