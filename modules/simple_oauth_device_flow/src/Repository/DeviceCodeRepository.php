<?php

namespace Drupal\simple_oauth_device_flow\Repository;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\simple_oauth_device_flow\Entity\DeviceCode;
use Drupal\simple_oauth_device_flow\Service\DeviceFlowSettingsService;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Repository for managing OAuth2 Device Codes.
 *
 * Implements the DeviceCodeRepositoryInterface required by league/oauth2-server
 * for the Device Authorization Grant (RFC 8628).
 */
class DeviceCodeRepository implements DeviceCodeRepositoryInterface {

  /**
   * The entity storage for device codes.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $deviceCodeStorage;

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a DeviceCodeRepository.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\simple_oauth_device_flow\Service\DeviceFlowSettingsService $settings
   *   The device flow settings service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    protected DeviceFlowSettingsService $settings,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    try {
      $this->deviceCodeStorage = $entityTypeManager->getStorage('oauth2_device_code');
    }
    catch (\Exception $e) {
      throw new \RuntimeException('Failed to initialize device code storage: ' . $e->getMessage(), 0, $e);
    }
    $this->logger = $loggerFactory->get('simple_oauth_device_flow');
  }

  /**
   * {@inheritdoc}
   */
  public function getNewDeviceCode(): DeviceCodeEntityInterface {
    try {
      $device_code = $this->deviceCodeStorage->create();

      if (!$device_code instanceof DeviceCode) {
        throw new \RuntimeException('Failed to create device code entity: invalid entity type');
      }

      return $device_code;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create new device code entity: @message', [
        '@message' => $e->getMessage(),
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

      $deviceCodeEntity->save();

      $this->logger->info('Device code persisted: @device_code with user code @user_code', [
        '@device_code' => $deviceCodeEntity->getIdentifier(),
        '@user_code' => $deviceCodeEntity->getUserCode(),
      ]);
    }
    catch (UniqueTokenIdentifierConstraintViolationException $e) {
      // Re-throw constraint violations as-is.
      throw $e;
    }
    catch (EntityStorageException $e) {
      $this->logger->error('Failed to persist device code @device_code: @message', [
        '@device_code' => $deviceCodeEntity->getIdentifier(),
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Failed to persist device code', 0, $e);
    }
    catch (\Exception $e) {
      $this->logger->error('Unexpected error persisting device code @device_code: @message', [
        '@device_code' => $deviceCodeEntity->getIdentifier(),
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Unexpected error during device code persistence', 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getDeviceCodeEntityByDeviceCode(string $deviceCode): ?DeviceCodeEntityInterface {
    try {
      $entities = $this->deviceCodeStorage->loadByProperties([
        'device_code' => $deviceCode,
      ]);

      if (empty($entities)) {
        return NULL;
      }

      $entity = reset($entities);
      if (!$entity instanceof DeviceCode) {
        $this->logger->warning('Retrieved entity is not a DeviceCode instance for device code: @device_code', [
          '@device_code' => $deviceCode,
        ]);
        return NULL;
      }

      return $entity;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to retrieve device code entity @device_code: @message', [
        '@device_code' => $deviceCode,
        '@message' => $e->getMessage(),
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
   * @return \League\OAuth2\Server\Entities\DeviceCodeEntityInterface|null
   *   The device code entity or NULL if not found.
   */
  public function getDeviceCodeEntityByUserCode(string $userCode): ?DeviceCodeEntityInterface {
    try {
      $entities = $this->deviceCodeStorage->loadByProperties([
        'user_code' => $userCode,
      ]);

      if (empty($entities)) {
        return NULL;
      }

      $entity = reset($entities);
      if (!$entity instanceof DeviceCode) {
        $this->logger->warning('Retrieved entity is not a DeviceCode instance for user code: @user_code', [
          '@user_code' => $userCode,
        ]);
        return NULL;
      }

      return $entity;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to retrieve device code entity by user code @user_code: @message', [
        '@user_code' => $userCode,
        '@message' => $e->getMessage(),
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
        $this->logger->warning('Attempted to revoke non-existent device code: @code_id', [
          '@code_id' => $codeId,
        ]);
        return;
      }

      if (!$entity instanceof DeviceCode) {
        throw new \RuntimeException('Entity is not a DeviceCode instance');
      }

      // Mark as revoked by setting authorized to FALSE and clearing user_id.
      $entity->set('authorized', FALSE);
      $entity->set('user_id', NULL);
      $entity->save();

      $this->logger->info('Device code revoked: @code_id', [
        '@code_id' => $codeId,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to revoke device code @code_id: @message', [
        '@code_id' => $codeId,
        '@message' => $e->getMessage(),
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
        $this->logger->warning('Retrieved entity is not a DeviceCode instance when checking revocation for: @code_id', [
          '@code_id' => $codeId,
        ]);
        return TRUE;
      }

      // Check if expired.
      if ($entity->getExpiryDateTime() <= new \DateTimeImmutable()) {
        return TRUE;
      }

      // Check if explicitly revoked (authorized = FALSE and no user_id).
      $authorized = $entity->get('authorized')->value;
      $user_id = $entity->get('user_id')->target_id;

      // Device code is revoked if not authorized and has no user.
      // Note: authorized can be FALSE during pending authorization, but having
      // a user_id while not authorized might indicate revocation.
      return !$authorized && empty($user_id);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to check device code revocation status for @code_id: @message', [
        '@code_id' => $codeId,
        '@message' => $e->getMessage(),
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

      $query = $this->deviceCodeStorage->getQuery()
        ->accessCheck(FALSE);
      $query->condition('expires_at', $current_time, '<');

      $expired_ids = $query->execute();

      if (empty($expired_ids)) {
        return 0;
      }

      $expired_entities = $this->deviceCodeStorage->loadMultiple($expired_ids);
      $this->deviceCodeStorage->delete($expired_entities);

      $count = count($expired_entities);
      $this->logger->info('Cleaned up @count expired device codes', [
        '@count' => $count,
      ]);

      return $count;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to cleanup expired device codes: @message', [
        '@message' => $e->getMessage(),
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
    return $this->deviceCodeStorage;
  }

}
