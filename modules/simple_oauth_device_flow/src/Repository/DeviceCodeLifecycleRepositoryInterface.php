<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Repository;

use Drupal\Core\Entity\EntityStorageInterface;

/**
 * Retains lifecycle operations and storage access for existing replacements.
 *
 * New replacements can implement DeviceCodeLifecycleOperationsInterface without
 * exposing entity storage. Existing implementations retain every obligation.
 */
interface DeviceCodeLifecycleRepositoryInterface extends DeviceCodeLifecycleOperationsInterface {

  /**
   * Gets entity storage for bounded cleanup and aggregate queries.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The device request storage.
   */
  public function getStorage(): EntityStorageInterface;

}
