<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\simple_oauth\Entities\ScopeEntity;
use Drupal\simple_oauth\Oauth2ScopeAdapterInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Defines the Device Code entity for OAuth 2.0 Device Authorization Grant.
 *
 * @ingroup simple_oauth_device_flow
 *
 * @ContentEntityType(
 *   id = "oauth2_device_code",
 *   label = @Translation("OAuth2 Device Code"),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "storage_schema" = "Drupal\simple_oauth_device_flow\Entity\DeviceCodeStorageSchema",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *   },
 *   base_table = "oauth2_device_code",
 *   admin_permission = "administer simple_oauth entities",
 *   entity_keys = {
 *     "id" = "device_code",
 *     "langcode" = "langcode",
 *   },
 *   list_cache_tags = {"oauth2_device_code"},
 * )
 */
class DeviceCode extends ContentEntityBase implements DeviceCodeEntityInterface {

  /**
   * The client entity.
   *
   * @var \League\OAuth2\Server\Entities\ClientEntityInterface|null
   */
  protected ?ClientEntityInterface $clientEntity = NULL;

  /**
   * The scope provider supplied by the repository for this instance.
   */
  protected ?Oauth2ScopeAdapterInterface $scopeProvider = NULL;

  /**
   * Supplies the provider used to map public names and stored scope IDs.
   *
   * @param \Drupal\simple_oauth\Oauth2ScopeAdapterInterface $scope_provider
   *   The provider, including optional static or dynamic implementations.
   */
  public function setScopeProvider(Oauth2ScopeAdapterInterface $scope_provider): void {
    $this->scopeProvider = $scope_provider;
  }

  /**
   * Gets the supplied provider or the direct-entity compatibility fallback.
   *
   * @return \Drupal\simple_oauth\Oauth2ScopeAdapterInterface
   *   The scope provider.
   */
  protected function getScopeProvider(): Oauth2ScopeAdapterInterface {
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal -- Direct entity callers predate provider injection.
    return $this->scopeProvider ?? \Drupal::service('simple_oauth.oauth2_scope.provider');
  }

  /**
   * The verification destination supplied for this transient instance.
   *
   * @var string|null
   */
  protected ?string $verificationUri = NULL;

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

    $fields['scopes'] = BaseFieldDefinition::create('oauth2_scope_reference')
      ->setLabel(t('Scopes'))
      ->setDescription(t('The scopes for this device code.'))
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'oauth2_scope_reference_label',
        'weight' => 4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'oauth2_scope_reference',
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

    $fields['authorized_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Authorized at'))
      ->setDescription(t('Authorization timestamp.'))
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 7,
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
    $oauth2scopes = [];
    $provider = $this->getScopeProvider();
    foreach ($this->get('scopes')->getValue() as $scope) {
      $id = $scope['scope_id'] ?? NULL;
      if ($id === NULL || $id === '') {
        continue;
      }
      $id = (string) $id;
      // Read historical public names without rewriting their stored values.
      $scope_entity = $provider->load($id) ?? $provider->loadByName($id);
      if ($scope_entity !== NULL) {
        $oauth2scopes[] = new ScopeEntity($scope_entity);
      }
    }
    return $oauth2scopes;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \League\OAuth2\Server\Exception\OAuthServerException
   *   If the public scope name does not resolve through the scope provider.
   */
  public function addScope(ScopeEntityInterface $scope): void {
    $resolved = $this->getScopeProvider()->loadByName($scope->getIdentifier());
    if ($resolved === NULL) {
      throw OAuthServerException::invalidScope($scope->getIdentifier());
    }
    $this->get('scopes')->appendItem(['scope_id' => $resolved->id()]);
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
    if ($this->verificationUri !== NULL) {
      return $this->verificationUri;
    }
    return \Drupal::service('simple_oauth_device_flow.settings')->getAbsoluteVerificationUri();
  }

  /**
   * {@inheritdoc}
   */
  public function setVerificationUri(string $verificationUri): void {
    $this->verificationUri = $verificationUri;
  }

  /**
   * {@inheritdoc}
   */
  public function getVerificationUriComplete(): string {
    $uri = $this->getVerificationUri();
    return $uri . (str_contains($uri, '?') ? '&' : '?') . 'user_code=' . rawurlencode($this->getUserCode());
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
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

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
