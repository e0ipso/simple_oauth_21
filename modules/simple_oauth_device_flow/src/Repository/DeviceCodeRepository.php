<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Repository;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\simple_oauth_device_flow\Entity\DeviceCode;
use Drupal\simple_oauth\Oauth2ScopeAdapterInterface;
use Drupal\simple_oauth_device_flow\Service\DeviceFlowSettingsService;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Repository for managing OAuth2 Device Codes.
 *
 * Implements the DeviceCodeRepositoryInterface required by league/oauth2-server
 * for the Device Authorization Grant (RFC 8628).
 */
final class DeviceCodeRepository implements DeviceVerificationRepositoryInterface, DeviceCodeLifecycleRepositoryInterface, DeviceCodeMaintenanceInterface {

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The client repository used to hydrate device codes.
   *
   * @var \League\OAuth2\Server\Repositories\ClientRepositoryInterface
   */
  private ClientRepositoryInterface $clientRepository;

  /**
   * Constructs a DeviceCodeRepository.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\simple_oauth_device_flow\Service\DeviceFlowSettingsService $settings
   *   The device flow settings service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   * @param \League\OAuth2\Server\Repositories\ClientRepositoryInterface $clientRepository
   *   The client repository.
   * @param \Drupal\Core\Lock\LockBackendInterface|null $lock
   *   The shared lock backend, or NULL to use the container.
   * @param \Drupal\Component\Datetime\TimeInterface|null $time
   *   The clock, or NULL to use the container.
   * @param \Drupal\simple_oauth\Oauth2ScopeAdapterInterface|null $scopeProvider
   *   The scope provider, or NULL for original constructor callers.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    protected DeviceFlowSettingsService $settings,
    LoggerChannelFactoryInterface $loggerFactory,
    ClientRepositoryInterface $clientRepository,
    private readonly ?LockBackendInterface $lock = NULL,
    private readonly ?TimeInterface $time = NULL,
    private readonly ?Oauth2ScopeAdapterInterface $scopeProvider = NULL,
  ) {
    $this->logger = $loggerFactory->get('simple_oauth_device_flow');
    $this->clientRepository = $clientRepository;
  }

  /**
   * {@inheritdoc}
   */
  public function getNewDeviceCode(): DeviceCode {
    try {
      $device_code = $this->entityTypeManager->getStorage('oauth2_device_code')->create();

      if (!$device_code instanceof DeviceCode) {
        throw new \RuntimeException('Failed to create device code entity: invalid entity type');
      }

      $device_code->set('created_at', $this->getCurrentTime());
      $this->attachScopeProvider($device_code);
      return $device_code;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create new device code entity: @exception_type', [
        '@exception_type' => get_class($e),
      ]);
      throw new \RuntimeException('Failed to create device code entity', 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function persistDeviceCode(DeviceCodeEntityInterface $deviceCodeEntity): void {
    if (!$deviceCodeEntity instanceof DeviceCode) {
      throw new \InvalidArgumentException('Device code entity must be an instance of DeviceCode');
    }

    try {
      // Before creating a new device code verify it's not a duplicate.
      if ($deviceCodeEntity->isNew()) {
        $this->attachScopeProvider($deviceCodeEntity);
        // Validate completed issuance against installed field constraints.
        if ($deviceCodeEntity->validate()->count() > 0) {
          throw new \InvalidArgumentException('Invalid device authorization data.');
        }
        $this->assertReferences($deviceCodeEntity);
        // Check for existing device code with same identifier.
        $existing = $this->getDeviceCodeEntityByDeviceCode($deviceCodeEntity->getIdentifier());
        if ($existing !== NULL) {
          throw UniqueTokenIdentifierConstraintViolationException::create();
        }

        // Check for existing user code.
        $existing_user_code = $this->getDeviceCodeEntityByUserCode($deviceCodeEntity->getUserCode());
        if ($existing_user_code !== NULL) {
          throw UniqueTokenIdentifierConstraintViolationException::create();
        }
      }

      if ($deviceCodeEntity->isNew()) {
        $deviceCodeEntity->save();
      }
      else {
        $this->withLockedCode($deviceCodeEntity->getIdentifier(), function (DeviceCode $current) use ($deviceCodeEntity): void {
          // League persists both polling snapshots and user decisions here.
          // Polling may update its timestamp, never the authorization fields.
          if ($deviceCodeEntity->getUserIdentifier() !== NULL) {
            $this->applyDecision($current, $deviceCodeEntity->getUserIdentifier(), $deviceCodeEntity->getUserApproved());
          }
          $polled_at = $deviceCodeEntity->getLastPolledAt();
          if ($polled_at !== NULL && ($current->getLastPolledAt() === NULL || $polled_at > $current->getLastPolledAt())) {
            $current->setLastPolledAt($polled_at);
          }
          $current->save();
        });
      }

      $this->logger->info('Device code persisted');
    }
    catch (UniqueTokenIdentifierConstraintViolationException $e) {
      // Re-throw constraint violations as-is.
      throw $e;
    }
    catch (EntityStorageException $e) {
      $this->logger->error('Failed to persist device code entity: @exception_type', [
        '@exception_type' => get_class($e),
      ]);
      throw new \RuntimeException('Failed to persist device code', 0, $e);
    }
    catch (\Exception $e) {
      $this->logger->error('Unexpected error persisting device code entity: @exception_type', [
        '@exception_type' => get_class($e),
      ]);
      throw new \RuntimeException('Unexpected error during device code persistence', 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDeviceCodeEntityByDeviceCode(string $deviceCode): ?DeviceCodeEntityInterface {
    try {
      $entities = $this->entityTypeManager->getStorage('oauth2_device_code')->loadByProperties([
        'device_code' => $deviceCode,
      ]);

      if (empty($entities)) {
        return NULL;
      }

      $entity = reset($entities);
      if (!$entity instanceof DeviceCode) {
        $this->logger->warning('Retrieved entity is not a DeviceCode instance during device code lookup');
        return NULL;
      }

      $this->attachScopeProvider($entity);
      $this->attachClientToDeviceCode($entity);
      return $entity;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to retrieve device code entity: @exception_type', [
        '@exception_type' => get_class($e),
      ]);
      return NULL;
    }
  }

  /**
   * Gets a device code entity by user code.
   *
   * @param string $userCode
   *   The user code to search for.
   *
   * @return \Drupal\simple_oauth_device_flow\Entity\DeviceCode|null
   *   The device code entity or NULL if not found.
   *
   * @throws \UnexpectedValueException
   *   When more than one stored record matches the exact candidate.
   */
  public function getDeviceCodeEntityByUserCode(string $userCode): ?DeviceCode {
    try {
      if (strlen($userCode) > 128 || mb_strlen($userCode, 'UTF-8') > 32 || $userCode === '') {
        return NULL;
      }
      $storage = $this->entityTypeManager->getStorage('oauth2_device_code');
      $ids = $storage->getQuery()->accessCheck(FALSE)
        ->condition('user_code', $userCode)->range(0, 2)->execute();
      if (count($ids) > 1) {
        throw new \UnexpectedValueException('Ambiguous device user code.');
      }
      if ($ids === []) {
        return NULL;
      }
      $entities = $storage->loadMultiple($ids);

      $entity = reset($entities);
      if (!$entity instanceof DeviceCode) {
        $this->logger->warning('Retrieved entity is not a DeviceCode instance during user code lookup');
        return NULL;
      }

      if ($entity->getUserCode() !== $userCode) {
        return NULL;
      }
      $this->attachScopeProvider($entity);
      $this->attachClientToDeviceCode($entity);
      return $entity;
    }
    catch (\UnexpectedValueException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to retrieve device code entity by user code: @exception_type', [
        '@exception_type' => get_class($e),
      ]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function revokeDeviceCode(string $codeId): void {
    try {
      $this->withLockedCode($codeId, function (DeviceCode $entity): void {
        // Revocation is idempotent and retains the first decision timestamp.
        if (((int) $entity->get('authorized_at')->value === 0)) {
          $entity->set('authorized_at', $this->getCurrentTime());
        }
        $entity->set('authorized', FALSE);
        $entity->set('user_id', NULL);
        $entity->save();
      }, TRUE);

      $this->logger->info('Device code revoked');
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to revoke device code: @exception_type', [
        '@exception_type' => get_class($e),
      ]);
      throw new \RuntimeException('Failed to revoke device code', 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isDeviceCodeRevoked(string $codeId): bool {
    try {
      $entity = $this->getDeviceCodeEntityByDeviceCode($codeId);

      if ($entity === NULL) {
        // Non-existent codes are considered revoked.
        return TRUE;
      }

      if (!$entity instanceof DeviceCode) {
        $this->logger->warning('Retrieved entity is not a DeviceCode instance when checking revocation');
        return TRUE;
      }

      // Check if expired.
      if ($entity->getExpiryDateTime() <= new \DateTimeImmutable()) {
        return TRUE;
      }

      // Revocation retains a decision timestamp and clears the user.
      $authorized = $entity->get('authorized')->value;
      $user_id = $entity->get('user_id')->target_id;
      $authorized_at = $entity->get('authorized_at')->value;

      return !$authorized && !empty($authorized_at) && empty($user_id);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to check device code revocation status: @exception_type', [
        '@exception_type' => get_class($e),
      ]);
      // Assume revoked on error for security.
      return TRUE;
    }
  }

  /**
   * Cleans up expired device codes.
   *
   * @return int
   *   Number of expired device codes deleted.
   */
  public function cleanupExpiredDeviceCodes(): int {
    try {
      $limit = max(1, $this->settings->getConfig()->get('max_cleanup_batch_size') ?? 1000);
      $count = $this->deleteExpired($this->getCurrentTime(), $limit);
      if ($count === 0) {
        return 0;
      }
      $this->logger->info('Cleaned up @count expired device codes', [
        '@count' => $count,
      ]);

      return $count;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to cleanup expired device codes: @exception_type', [
        '@exception_type' => get_class($e),
      ]);
      return 0;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function decide(string $identifier, string $user_id, bool $approved): void {
    $this->withLockedCode($identifier, function (DeviceCode $entity) use ($user_id, $approved): void {
      $this->applyDecision($entity, $user_id, $approved);
      $entity->save();
    });
  }

  /**
   * Updates the retained polling API from current persistent state.
   *
   * @param string $identifier
   *   The device identifier.
   * @param int $now
   *   The polling time.
   * @param int $interval
   *   The minimum interval.
   *
   * @return int
   *   Seconds until another poll is allowed, or zero for an accepted poll.
   *
   * @throws \RuntimeException
   *   If the code is missing or its lock cannot be acquired.
   * @throws \DomainException
   *   If the code is expired or revoked.
   */
  public function recordPolling(string $identifier, int $now, int $interval): int {
    return $this->withLockedCode($identifier, function (DeviceCode $entity) use ($now, $interval): int {
      if ($entity->getExpiryDateTime()->getTimestamp() <= $now
        || (!$entity->getUserApproved() && $entity->getUserIdentifier() === NULL && (int) $entity->get('authorized_at')->value !== 0)) {
        throw new \DomainException('Device authorization is no longer available.');
      }
      $last_polled = $entity->getLastPolledAt()?->getTimestamp();
      $remaining = $last_polled === NULL ? 0 : max(0, $interval - ($now - $last_polled));
      if ($remaining === 0) {
        $entity->setLastPolledAt(new \DateTimeImmutable('@' . $now));
        $entity->save();
      }
      return $remaining;
    });
  }

  /**
   * Applies a decision only to a fresh pending code while holding its lock.
   *
   * @param \Drupal\simple_oauth_device_flow\Entity\DeviceCode $entity
   *   The reloaded entity.
   * @param string $user_id
   *   The authenticated user's identifier.
   * @param bool $approved
   *   Whether the user approved the request.
   *
   * @throws \DomainException
   *   If expired, terminal, or a user, client or scope reference is invalid.
   */
  private function applyDecision(DeviceCode $entity, string $user_id, bool $approved): void {
    if (!ctype_digit($user_id) || (int) $user_id <= 0 || $entity->getExpiryDateTime()->getTimestamp() <= $this->getCurrentTime()
      || $entity->getUserApproved() || $entity->getUserIdentifier() !== NULL || (int) $entity->get('authorized_at')->value !== 0) {
      throw new \DomainException('Device authorization is no longer available.');
    }
    $user_storage = $this->entityTypeManager->getStorage('user');
    $user_storage->resetCache([$user_id]);
    if ($user_storage->load($user_id) === NULL) {
      throw new \DomainException('Device authorization is no longer available.');
    }
    $this->assertReferences($entity);
    $entity->setUserIdentifier($user_id);
    $entity->setUserApproved($approved);
    $entity->set('authorized_at', $this->getCurrentTime());
  }

  /**
   * Serializes mutations and reloads storage after acquiring the code lock.
   *
   * @param string $identifier
   *   The device identifier.
   * @param callable $operation
   *   The operation to run against the fresh entity.
   * @param bool $allow_missing
   *   Whether revocation of a missing code is allowed.
   *
   * @return mixed
   *   The operation's result.
   *
   * @throws \RuntimeException
   *   If locking fails or the entity no longer exists.
   */
  private function withLockedCode(string $identifier, callable $operation, bool $allow_missing = FALSE): mixed {
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal -- Preserve the original constructor for external callers.
    $lock = $this->lock ?? \Drupal::lock();
    $lock_name = 'simple_oauth_device_flow:' . hash('sha256', $identifier);
    if (!$lock->acquire($lock_name)) {
      throw new \RuntimeException('Device authorization is being updated.');
    }
    try {
      $storage = $this->getStorage();
      $storage->resetCache([$identifier]);
      $entity = $storage->load($identifier);
      if (!$entity instanceof DeviceCode) {
        if ($allow_missing) {
          return NULL;
        }
        throw new \RuntimeException('Device authorization is no longer available.');
      }
      $this->attachScopeProvider($entity);
      return $operation($entity);
    }
    finally {
      $lock->release($lock_name);
    }
  }

  /**
   * Gets the current time for expiry and terminal decision timestamps.
   *
   * @return int
   *   The current Unix timestamp.
   */
  private function getCurrentTime(): int {
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal -- Preserve the original constructor for external callers.
    return ($this->time ?? \Drupal::time())->getCurrentTime();
  }

  /**
   * Gets the device code storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The device code entity storage.
   */
  public function getStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('oauth2_device_code');
  }

  /**
   * {@inheritdoc}
   */
  public function deleteExpired(int $now, int $limit): int {
    return (new DeviceCodeStorageMaintenance($this->entityTypeManager))->deleteExpired($now, $limit);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteOldAuthorized(int $cutoff, int $limit): int {
    return (new DeviceCodeStorageMaintenance($this->entityTypeManager))->deleteOldAuthorized($cutoff, $limit);
  }

  /**
   * {@inheritdoc}
   */
  public function countDeviceCodes(int $now): array {
    return (new DeviceCodeStorageMaintenance($this->entityTypeManager))->countDeviceCodes($now);
  }

  /**
   * Supplies scope hydration for normal injected repository operations.
   *
   * Original constructors retain the entity's compatibility fallback.
   *
   * @param \Drupal\simple_oauth_device_flow\Entity\DeviceCode $entity
   *   The request to hydrate.
   */
  private function attachScopeProvider(DeviceCode $entity): void {
    if ($this->scopeProvider !== NULL) {
      $entity->setScopeProvider($this->scopeProvider);
    }
  }

  /**
   * Checks mutable references at issuance and fresh decision boundaries.
   *
   * @param \Drupal\simple_oauth_device_flow\Entity\DeviceCode $entity
   *   The completed issuance or freshly reloaded pending request.
   *
   * @throws \DomainException
   *   When a client, user or scope no longer resolves.
   */
  private function assertReferences(DeviceCode $entity): void {
    // client_id stores the public OAuth identifier, not the consumer entity ID.
    $client_id = (string) $entity->get('client_id')->value;
    if ($client_id === '' || !$this->clientRepository->getClientEntity($client_id) instanceof ClientEntityInterface) {
      throw new \DomainException('Device authorization is no longer available.');
    }
    $user_id = $entity->getUserIdentifier();
    if ($entity->getUserApproved() && $user_id === NULL) {
      throw new \DomainException('Device authorization is no longer available.');
    }
    if ($user_id !== NULL) {
      $storage = $this->entityTypeManager->getStorage('user');
      $storage->resetCache([$user_id]);
      if ($storage->load($user_id) === NULL) {
        throw new \DomainException('Device authorization is no longer available.');
      }
    }
    // Hydration accepts canonical IDs and historical names without mutation.
    if (count($entity->getScopes()) !== count($entity->get('scopes')->getValue())) {
      throw new \DomainException('Device authorization is no longer available.');
    }
  }

  /**
   * Ensures the device code entity has a hydrated client entity.
   */
  private function attachClientToDeviceCode(DeviceCode $deviceCode): void {
    $clientIdentifier = $deviceCode->get('client_id')->value;
    if ($clientIdentifier === NULL || $clientIdentifier === '') {
      return;
    }

    $client = $this->clientRepository->getClientEntity((string) $clientIdentifier);
    if ($client instanceof ClientEntityInterface) {
      $deviceCode->setClient($client);
    }
  }

}
