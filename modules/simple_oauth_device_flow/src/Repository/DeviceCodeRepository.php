<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Repository;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\simple_oauth_device_flow\Entity\DeviceCode;
use Drupal\simple_oauth_device_flow\Service\DeviceFlowSettingsService;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Repository for managing OAuth2 Device Codes.
 *
 * Implements the DeviceCodeRepositoryInterface required by league/oauth2-server
 * for the Device Authorization Grant (RFC 8628).
 */
final class DeviceCodeRepository implements DeviceCodeRepositoryInterface {

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
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    protected DeviceFlowSettingsService $settings,
    LoggerChannelFactoryInterface $loggerFactory,
    ClientRepositoryInterface $clientRepository,
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

      $deviceCodeEntity->save();

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
   */
  public function getDeviceCodeEntityByUserCode(string $userCode): ?DeviceCode {
    try {
      $entities = $this->entityTypeManager->getStorage('oauth2_device_code')->loadByProperties([
        'user_code' => $userCode,
      ]);

      if (empty($entities)) {
        return NULL;
      }

      $entity = reset($entities);
      if (!$entity instanceof DeviceCode) {
        $this->logger->warning('Retrieved entity is not a DeviceCode instance during user code lookup');
        return NULL;
      }

      $this->attachClientToDeviceCode($entity);
      return $entity;
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
      $entity = $this->getDeviceCodeEntityByDeviceCode($codeId);

      if ($entity === NULL) {
        $this->logger->warning('Attempted to revoke non-existent device code');
        return;
      }

      if (!$entity instanceof DeviceCode) {
        throw new \RuntimeException('Entity is not a DeviceCode instance');
      }

      // Retain a decision timestamp even when revoking a pending code.
      // This distinguishes terminal codes from unused pending codes.
      if (empty($entity->get('authorized_at')->value)) {
        $entity->set('authorized_at', time());
      }
      $entity->set('authorized', FALSE);
      $entity->set('user_id', NULL);
      $entity->save();

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
      $current_time = time();

      $query = $this->entityTypeManager->getStorage('oauth2_device_code')->getQuery()
        ->accessCheck(FALSE);
      $query->condition('expires_at', $current_time, '<');

      $expired_ids = $query->execute();

      if (empty($expired_ids)) {
        return 0;
      }

      $expired_entities = $this->entityTypeManager->getStorage('oauth2_device_code')->loadMultiple($expired_ids);
      $this->entityTypeManager->getStorage('oauth2_device_code')->delete($expired_entities);

      $count = count($expired_entities);
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
   * Validates device code using time-constant comparison.
   *
   * @param string $providedCode
   *   The device code provided by the client.
   * @param string $storedCode
   *   The device code stored in the database.
   *
   * @return bool
   *   TRUE if the codes match using time-constant comparison.
   */
  protected function validateDeviceCode(string $providedCode, string $storedCode): bool {
    return hash_equals($storedCode, $providedCode);
  }

  /**
   * Validates user code using time-constant comparison.
   *
   * @param string $providedCode
   *   The user code provided by the user.
   * @param string $storedCode
   *   The user code stored in the database.
   *
   * @return bool
   *   TRUE if the codes match using time-constant comparison.
   */
  protected function validateUserCode(string $providedCode, string $storedCode): bool {
    return hash_equals($storedCode, $providedCode);
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
