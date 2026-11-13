<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Repository;

use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;

/**
 * Defines the repository operations required by device verification forms.
 */
interface DeviceVerificationOperationsInterface extends UserCodeLookupInterface {

  /**
   * Reloads a bound request for the consent display.
   *
   * @param string $deviceCode
   *   The device identifier stored in server-side form state.
   *
   * @return \League\OAuth2\Server\Entities\DeviceCodeEntityInterface|null
   *   The request with its client hydrated, or NULL when unavailable.
   */
  public function getDeviceCodeEntityByDeviceCode(string $deviceCode): ?DeviceCodeEntityInterface;

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

  /**
   * Checks whether the persisted request is revoked or unavailable.
   *
   * @param string $codeId
   *   The device identifier.
   *
   * @return bool
   *   TRUE for revoked, missing or expired codes, or on lookup failure.
   */
  public function isDeviceCodeRevoked(string $codeId): bool;

}
