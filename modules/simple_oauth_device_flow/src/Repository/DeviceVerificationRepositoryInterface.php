<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Repository;

use Drupal\simple_oauth_device_flow\Entity\DeviceCode;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;

/**
 * Defines the repository operations required by device verification forms.
 */
interface DeviceVerificationRepositoryInterface extends DeviceCodeRepositoryInterface {

  /**
   * Finds a request by its human-readable code.
   *
   * @param string $userCode
   *   The formatted user code.
   *
   * @return \Drupal\simple_oauth_device_flow\Entity\DeviceCode|null
   *   The request, or NULL if it cannot be found.
   */
  public function getDeviceCodeEntityByUserCode(string $userCode): ?DeviceCode;

  /**
   * Records a decision against fresh persistent state under a shared lock.
   *
   * @param string $identifier
   *   The device request identifier.
   * @param string $user_id
   *   The authenticated user's identifier.
   * @param bool $approved
   *   Whether the request was approved.
   *
   * @throws \DomainException
   *   If the code has expired, has a decision, or the user is invalid.
   * @throws \RuntimeException
   *   If storage or locking fails.
   */
  public function decide(string $identifier, string $user_id, bool $approved): void;

}
