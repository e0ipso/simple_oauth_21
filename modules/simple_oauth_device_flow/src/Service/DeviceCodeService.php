<?php

namespace Drupal\simple_oauth_device_flow\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\simple_oauth_device_flow\Entity\DeviceCode;
use Drupal\simple_oauth_device_flow\Repository\DeviceCodeRepository;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Device Code lifecycle and cleanup operations.
 *
 * Provides comprehensive device code lifecycle management including:
 * - Automated cleanup of expired and old authorized codes
 * - Polling interval validation and enforcement
 * - Usage statistics collection
 * - Performance-optimized database operations.
 */
class DeviceCodeService {

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a DeviceCodeService.
   *
   * @param \Drupal\simple_oauth_device_flow\Repository\DeviceCodeRepository $deviceCodeRepository
   *   The device code repository.
   * @param \Drupal\simple_oauth_device_flow\Service\UserCodeGenerator $userCodeGenerator
   *   The user code generator service.
   * @param \Drupal\simple_oauth_device_flow\Service\DeviceFlowSettingsService $settings
   *   The device flow settings service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    protected DeviceCodeRepository $deviceCodeRepository,
    protected UserCodeGenerator $userCodeGenerator,
    protected DeviceFlowSettingsService $settings,
    protected TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('simple_oauth_device_flow');
  }

  /**
   * Cleans up expired device codes.
   *
   * Removes device codes that have passed their expiration time.
   * Uses batch processing to handle large datasets efficiently.
   *
   * @return int
   *   Number of expired device codes deleted.
   */
  public function cleanupExpiredCodes(): int {
    try {
      $batch_size = $this->getCleanupBatchSize();
      $current_time = $this->time->getCurrentTime();

      $storage = $this->deviceCodeRepository->getStorage();
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('expires_at', $current_time, '<')
        ->range(0, $batch_size);

      $expired_ids = $query->execute();

      if (empty($expired_ids)) {
        return 0;
      }

      $expired_entities = $storage->loadMultiple($expired_ids);
      $storage->delete($expired_entities);

      $count = count($expired_entities);

      if ($this->isStatisticsLoggingEnabled()) {
        $this->logger->info('Cleaned up @count expired device codes (batch size: @batch_size)', [
          '@count' => $count,
          '@batch_size' => $batch_size,
        ]);
      }

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
   * Cleans up old authorized device codes based on retention period.
   *
   * Removes authorized device codes that exceed the configured retention
   * period.
   * This helps maintain database performance and comply with data retention
   * policies.
   *
   * @return int
   *   Number of old authorized device codes deleted.
   */
  public function cleanupOldAuthorizedCodes(): int {
    try {
      $retention_days = $this->getCleanupRetentionDays();
      $batch_size = $this->getCleanupBatchSize();
      $cutoff_time = $this->time->getCurrentTime() - ($retention_days * 86400);

      $storage = $this->deviceCodeRepository->getStorage();
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('authorized', TRUE)
        ->condition('created_at', $cutoff_time, '<')
        ->range(0, $batch_size);

      $old_ids = $query->execute();

      if (empty($old_ids)) {
        return 0;
      }

      $old_entities = $storage->loadMultiple($old_ids);
      $storage->delete($old_entities);

      $count = count($old_entities);

      if ($this->isStatisticsLoggingEnabled()) {
        $this->logger->info('Cleaned up @count old authorized device codes (retention: @days days, batch size: @batch_size)', [
          '@count' => $count,
          '@days' => $retention_days,
          '@batch_size' => $batch_size,
        ]);
      }

      return $count;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to cleanup old authorized device codes: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Validates and enforces polling intervals for device code requests.
   *
   * Checks if the client is respecting the polling interval and updates
   * the last polled timestamp. Returns appropriate response for slow_down
   * if polling too frequently.
   *
   * @param \Drupal\simple_oauth_device_flow\Entity\DeviceCode $deviceCode
   *   The device code entity to validate polling for.
   *
   * @return array
   *   Array with 'valid' boolean and optional 'error' and 'error_description'.
   */
  public function validatePollingInterval(DeviceCode $deviceCode): array {
    try {
      $current_time = $this->time->getCurrentTime();
      $polling_interval = $this->settings->getPollingInterval();
      $last_polled = $deviceCode->get('last_polled_at')->value ?? 0;

      // Calculate time since last poll.
      $time_since_last_poll = $current_time - $last_polled;

      // Check if polling too frequently.
      if ($time_since_last_poll < $polling_interval) {
        $remaining_time = $polling_interval - $time_since_last_poll;

        $this->logger->debug('Client polling too frequently for device code @device_code. Time since last poll: @time_since, Required interval: @interval', [
          '@device_code' => $deviceCode->getIdentifier(),
          '@time_since' => $time_since_last_poll,
          '@interval' => $polling_interval,
        ]);

        return [
          'valid' => FALSE,
          'error' => 'slow_down',
          'error_description' => sprintf(
            'Polling too frequently. Wait %d seconds before next request.',
            $remaining_time
          ),
        ];
      }

      // Update last polled timestamp.
      $deviceCode->set('last_polled_at', $current_time);
      $deviceCode->save();

      return ['valid' => TRUE];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to validate polling interval for device code @device_code: @message', [
        '@device_code' => $deviceCode->getIdentifier(),
        '@message' => $e->getMessage(),
      ]);

      return [
        'valid' => FALSE,
        'error' => 'server_error',
        'error_description' => 'Internal server error during polling validation.',
      ];
    }
  }

  /**
   * Gets device code usage statistics for monitoring and analytics.
   *
   * Provides comprehensive statistics about device code usage including
   * active, authorized, and expired code counts.
   *
   * @return array
   *   Array containing usage statistics.
   */
  public function getStatistics(): array {
    try {
      $current_time = $this->time->getCurrentTime();
      $storage = $this->deviceCodeRepository->getStorage();

      // Count active device codes (not expired, not authorized).
      $active_query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('authorized', FALSE)
        ->condition('expires_at', $current_time, '>=');
      $active_count = $active_query->count()->execute();

      // Count authorized device codes.
      $authorized_query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('authorized', TRUE);
      $authorized_count = $authorized_query->count()->execute();

      // Count expired device codes.
      $expired_query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('expires_at', $current_time, '<');
      $expired_count = $expired_query->count()->execute();

      // Count total device codes.
      $total_query = $storage->getQuery()
        ->accessCheck(FALSE);
      $total_count = $total_query->count()->execute();

      $statistics = [
        'active_codes' => (int) $active_count,
        'authorized_codes' => (int) $authorized_count,
        'expired_codes' => (int) $expired_count,
        'total_codes' => (int) $total_count,
        'timestamp' => $current_time,
        'retention_days' => $this->getCleanupRetentionDays(),
        'cleanup_batch_size' => $this->getCleanupBatchSize(),
      ];

      if ($this->isStatisticsLoggingEnabled()) {
        $this->logger->debug('Device code statistics: Active: @active, Authorized: @authorized, Expired: @expired, Total: @total', [
          '@active' => $statistics['active_codes'],
          '@authorized' => $statistics['authorized_codes'],
          '@expired' => $statistics['expired_codes'],
          '@total' => $statistics['total_codes'],
        ]);
      }

      return $statistics;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate device code statistics: @message', [
        '@message' => $e->getMessage(),
      ]);

      return [
        'active_codes' => 0,
        'authorized_codes' => 0,
        'expired_codes' => 0,
        'total_codes' => 0,
        'timestamp' => $this->time->getCurrentTime(),
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Generates device authorization data for OAuth 2.0 Device Flow.
   *
   * Creates a new device code entity with generated device code, user code,
   * and expiration information according to RFC 8628 specifications.
   *
   * @param object $client_entity
   *   The OAuth client entity requesting device authorization.
   * @param string $scope
   *   The requested scope for the authorization.
   *
   * @return array
   *   Array containing device_code, user_code, expires_in, and interval.
   *
   * @throws \RuntimeException
   *   When device authorization generation fails.
   */
  public function generateDeviceAuthorization($client_entity, string $scope = ''): array {
    try {
      // Generate a unique device code.
      $device_code = $this->generateUniqueDeviceCode();

      // Generate a unique user code.
      $user_code = $this->userCodeGenerator->generateUserCode();

      // Get configuration values.
      $expires_in = $this->settings->getDeviceCodeExpiration();
      $polling_interval = $this->settings->getPollingInterval();
      $current_time = $this->time->getCurrentTime();
      $expires_at = $current_time + $expires_in;

      // Create device code entity.
      $device_code_entity = $this->deviceCodeRepository->getNewDeviceCode();
      $device_code_entity->setIdentifier($device_code);
      $device_code_entity->setUserCode($user_code);
      $device_code_entity->setClient($client_entity);
      $device_code_entity->setExpiryDateTime(new \DateTimeImmutable('@' . $expires_at));

      // Set additional properties including scopes.
      $device_code_entity->set('created_at', $current_time);
      $device_code_entity->set('expires_at', $expires_at);
      $device_code_entity->set('authorized', FALSE);

      // Parse scope string and add each scope directly to the field.
      $scope_array = !empty($scope) ? explode(' ', trim($scope)) : [];
      foreach ($scope_array as $scope_id) {
        $device_code_entity->get('scopes')->appendItem(['scope_id' => trim($scope_id)]);
      }

      // Persist the device code with all fields set.
      $this->deviceCodeRepository->persistDeviceCode($device_code_entity);

      $this->logger->info('Device authorization generated for client @client_id: device_code=@device_code, user_code=@user_code', [
        '@client_id' => $client_entity->getIdentifier(),
        '@device_code' => $device_code,
        '@user_code' => $user_code,
      ]);

      return [
        'device_code' => $device_code,
        'user_code' => $user_code,
        'expires_in' => $expires_in,
        'interval' => $polling_interval,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate device authorization: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Device authorization generation failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Performs comprehensive cleanup of device codes.
   *
   * Runs both expired code cleanup and old authorized code cleanup.
   * This is the main method called by cron for maintenance.
   *
   * @return array
   *   Array with cleanup results including counts for both operations.
   */
  public function performCleanup(): array {
    $start_time = microtime(TRUE);

    $expired_count = $this->cleanupExpiredCodes();
    $authorized_count = $this->cleanupOldAuthorizedCodes();

    $end_time = microtime(TRUE);
    $execution_time = round(($end_time - $start_time) * 1000, 2);

    $result = [
      'expired_codes_deleted' => $expired_count,
      'old_authorized_codes_deleted' => $authorized_count,
      'total_deleted' => $expired_count + $authorized_count,
      'execution_time_ms' => $execution_time,
    ];

    if ($this->isStatisticsLoggingEnabled()) {
      $this->logger->info('Device code cleanup completed: @expired expired codes, @authorized old authorized codes deleted in @time ms', [
        '@expired' => $expired_count,
        '@authorized' => $authorized_count,
        '@time' => $execution_time,
      ]);
    }

    return $result;
  }

  /**
   * Gets the cleanup retention period in days.
   *
   * @return int
   *   Number of days to retain authorized codes.
   */
  protected function getCleanupRetentionDays(): int {
    return $this->settings->getConfig()->get('cleanup_retention_days') ?? 7;
  }

  /**
   * Gets the maximum cleanup batch size.
   *
   * @return int
   *   Maximum number of codes to delete per cleanup operation.
   */
  protected function getCleanupBatchSize(): int {
    return $this->settings->getConfig()->get('max_cleanup_batch_size') ?? 1000;
  }

  /**
   * Checks if statistics logging is enabled.
   *
   * @return bool
   *   TRUE if statistics logging is enabled.
   */
  protected function isStatisticsLoggingEnabled(): bool {
    return $this->settings->getConfig()->get('enable_statistics_logging') ?? TRUE;
  }

  /**
   * Generates a unique device code identifier.
   *
   * Creates a cryptographically secure, URL-safe device code that is
   * guaranteed to be unique in the system.
   *
   * @return string
   *   The unique device code identifier.
   *
   * @throws \RuntimeException
   *   When unable to generate a unique device code.
   */
  protected function generateUniqueDeviceCode(): string {
    $max_attempts = 10;
    $attempts = 0;

    while ($attempts < $max_attempts) {
      $attempts++;

      try {
        // Generate a cryptographically secure random device code.
        // Using 32 bytes (256 bits) for high entropy.
        $random_bytes = random_bytes(32);
        $device_code = rtrim(strtr(base64_encode($random_bytes), '+/', '-_'), '=');

        // Check if this device code already exists.
        $existing_entity = $this->deviceCodeRepository->getDeviceCodeEntityByDeviceCode($device_code);
        if ($existing_entity === NULL) {
          return $device_code;
        }

        $this->logger->debug('Device code collision on attempt @attempt', [
          '@attempt' => $attempts,
        ]);
      }
      catch (\Exception $e) {
        $this->logger->error('Error generating device code on attempt @attempt: @message', [
          '@attempt' => $attempts,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $this->logger->error('Failed to generate unique device code after @attempts attempts', [
      '@attempts' => $max_attempts,
    ]);

    throw new \RuntimeException('Unable to generate unique device code after maximum attempts');
  }

}
