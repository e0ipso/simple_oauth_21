<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Repository;

/**
 * Provides bounded maintenance and aggregate queries without exposing storage.
 *
 * Operations throw on failure so scheduling can distinguish an empty batch from
 * a failed batch. Existing lifecycle replacements need not add these methods.
 */
interface DeviceCodeMaintenanceInterface {

  /**
   * Deletes at most the limit of requests expired before the supplied time.
   *
   * @param int $now
   *   The current Unix timestamp.
   * @param int $limit
   *   The positive batch limit.
   *
   * @return int
   *   The deleted count, including zero for a successful empty batch.
   *
   * @throws \Exception
   *   If query or deletion fails.
   */
  public function deleteExpired(int $now, int $limit): int;

  /**
   * Deletes at most the limit of authorized requests created before the cutoff.
   *
   * @param int $cutoff
   *   The retention cutoff as a Unix timestamp.
   * @param int $limit
   *   The positive batch limit.
   *
   * @return int
   *   The deleted count.
   *
   * @throws \Exception
   *   If query or deletion fails.
   */
  public function deleteOldAuthorized(int $cutoff, int $limit): int;

  /**
   * Counts active, authorized, expired and total requests.
   *
   * @param int $now
   *   The current Unix timestamp.
   *
   * @return array<string, int>
   *   Counts keyed by active_codes, authorized_codes, expired_codes and
   *   total_codes.
   *
   * @throws \Exception
   *   If a count query fails.
   */
  public function countDeviceCodes(int $now): array;

}
