<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Repository;

use Drupal\simple_oauth_device_flow\Entity\DeviceCode;

/**
 * Defines user-code lookup for uniqueness checks and verification.
 */
interface UserCodeLookupInterface {

  /**
   * Finds a request by its human-readable code.
   *
   * @param string $userCode
   *   An exact stored code candidate, formatted or raw.
   *
   * @return \Drupal\simple_oauth_device_flow\Entity\DeviceCode|null
   *   The request, or NULL if it cannot be found.
   */
  public function getDeviceCodeEntityByUserCode(string $userCode): ?DeviceCode;

}
