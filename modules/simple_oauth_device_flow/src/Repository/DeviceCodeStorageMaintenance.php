<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Repository;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Adapts entity storage to bounded device maintenance operations.
 *
 * Also supports installed lifecycle replacements exposing the original storage
 * API. New replacements can supply maintenance operations without that API.
 */
final class DeviceCodeStorageMaintenance implements DeviceCodeMaintenanceInterface {

  /**
   * Constructs the storage adapter.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface|\Drupal\simple_oauth_device_flow\Repository\DeviceCodeLifecycleRepositoryInterface $source
   *   The entity manager, or an installed storage-based lifecycle replacement.
   */
  public function __construct(private readonly EntityTypeManagerInterface|DeviceCodeLifecycleRepositoryInterface $source) {}

  /**
   * Gets storage from the manager or the original lifecycle storage contract.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The device storage.
   */
  private function getStorage(): EntityStorageInterface {
    return $this->source instanceof EntityTypeManagerInterface
      ? $this->source->getStorage('oauth2_device_code')
      : $this->source->getStorage();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteExpired(int $now, int $limit): int {
    return $this->deleteBatch(['expires_at' => [$now, '<']], $limit);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteOldAuthorized(int $cutoff, int $limit): int {
    return $this->deleteBatch(['authorized' => [TRUE, '='], 'created_at' => [$cutoff, '<']], $limit);
  }

  /**
   * Deletes a bounded, deterministic batch matching the conditions.
   *
   * @param array $conditions
   *   Field names mapped to value/operator pairs.
   * @param int $limit
   *   The positive batch limit.
   *
   * @return int
   *   The number of deleted entities.
   */
  private function deleteBatch(array $conditions, int $limit): int {
    $query = $this->getStorage()->getQuery()->accessCheck(FALSE);
    foreach ($conditions as $field => [$value, $operator]) {
      $query->condition($field, $value, $operator);
    }
    $ids = $query->sort('device_code')->range(0, max(1, $limit))->execute();
    if ($ids === []) {
      return 0;
    }
    $entities = $this->getStorage()->loadMultiple($ids);
    $this->getStorage()->delete($entities);
    return count($entities);
  }

  /**
   * {@inheritdoc}
   */
  public function countDeviceCodes(int $now): array {
    $query = $this->getStorage()->getQuery()->accessCheck(FALSE);
    $active = clone $query;
    $authorized = clone $query;
    $expired = clone $query;
    return [
      'active_codes' => $active->condition('authorized', FALSE)->condition('expires_at', $now, '>=')->count()->execute(),
      'authorized_codes' => $authorized->condition('authorized', TRUE)->count()->execute(),
      'expired_codes' => $expired->condition('expires_at', $now, '<')->count()->execute(),
      'total_codes' => $query->count()->execute(),
    ];
  }

}
