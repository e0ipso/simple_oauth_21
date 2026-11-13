<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Repository;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\simple_oauth_device_flow\Entity\DeviceCode;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;

/**
 * Defines issuance, polling and storage operations used by lifecycle services.
 *
 * Implementations must serialize polling against fresh authorization state.
 */
interface DeviceCodeLifecycleRepositoryInterface {

  /**
   * Creates an unsaved Drupal device request for issuance.
   *
   * @return \Drupal\simple_oauth_device_flow\Entity\DeviceCode
   *   The unsaved request.
   */
  public function getNewDeviceCode(): DeviceCode;

  /**
   * Persists a request without overwriting a newer authorization decision.
   *
   * @param \League\OAuth2\Server\Entities\DeviceCodeEntityInterface $deviceCodeEntity
   *   The request to persist.
   */
  public function persistDeviceCode(DeviceCodeEntityInterface $deviceCodeEntity): void;

  /**
   * Looks up a device identifier.
   *
   * @param string $deviceCode
   *   The device identifier.
   *
   * @return \League\OAuth2\Server\Entities\DeviceCodeEntityInterface|null
   *   The request, or NULL when unavailable.
   */
  public function getDeviceCodeEntityByDeviceCode(string $deviceCode): ?DeviceCodeEntityInterface;

  /**
   * Gets entity storage for bounded cleanup and aggregate queries.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The device request storage.
   */
  public function getStorage(): EntityStorageInterface;

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
  public function recordPolling(string $identifier, int $now, int $interval): int;

}
