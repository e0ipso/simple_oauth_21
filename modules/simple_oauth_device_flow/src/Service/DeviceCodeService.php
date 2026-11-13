<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\simple_oauth_device_flow\Repository\DeviceCodeMaintenanceInterface;
use Drupal\simple_oauth_device_flow\Repository\DeviceCodeStorageMaintenance;
use Drupal\simple_oauth_device_flow\Entity\DeviceCode;
use Drupal\simple_oauth\Oauth2ScopeAdapterInterface;
use Drupal\simple_oauth_device_flow\Repository\DeviceCodeLifecycleRepositoryInterface;
use Drupal\simple_oauth_device_flow\Repository\DeviceCodeLifecycleOperationsInterface;
use Psr\Log\LoggerInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * Service for managing Device Code lifecycle and cleanup operations.
 *
 * Provides comprehensive device code lifecycle management including:
 * - Automated cleanup of expired and old authorized codes
 * - Polling interval validation and enforcement
 * - Usage statistics collection
 * - Performance-optimized database operations.
 */
final class DeviceCodeService {

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Whether either operation in the current cleanup failed.
   */
  private bool $cleanupFailed = FALSE;

  /**
   * Constructs a DeviceCodeService.
   *
   * @param \Drupal\simple_oauth_device_flow\Repository\DeviceCodeLifecycleOperationsInterface $deviceCodeRepository
   *   The device code repository.
   * @param \Drupal\simple_oauth_device_flow\Service\UserCodeGenerator $userCodeGenerator
   *   The user code generator service.
   * @param \Drupal\simple_oauth_device_flow\Service\DeviceFlowSettingsService $settings
   *   The device flow settings service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   * @param \League\OAuth2\Server\Repositories\ScopeRepositoryInterface|null $scopeRepository
   *   The scope repository, or NULL to use the container.
   * @param \Drupal\simple_oauth_device_flow\Repository\DeviceCodeMaintenanceInterface|null $maintenance
   *   Maintenance operations, or NULL to use repository maintenance or legacy
   *   storage. Without either, cleanup logs failure and returns zero, scheduled
   *   cleanup remains due, and statistics include an error.
   * @param \Drupal\Core\State\StateInterface|null $state
   *   Scheduling state, or NULL for original constructor callers.
   * @param \Drupal\Core\Lock\LockBackendInterface|null $lock
   *   The scheduling lock, or NULL for original constructor callers.
   * @param \Drupal\simple_oauth\Oauth2ScopeAdapterInterface|null $scopeProvider
   *   The provider for issuance through replacement lifecycle repositories.
   */
  public function __construct(
    protected DeviceCodeLifecycleOperationsInterface $deviceCodeRepository,
    protected UserCodeGenerator $userCodeGenerator,
    protected DeviceFlowSettingsService $settings,
    protected TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
    private readonly ?ScopeRepositoryInterface $scopeRepository = NULL,
    private readonly ?DeviceCodeMaintenanceInterface $maintenance = NULL,
    private readonly ?StateInterface $state = NULL,
    private readonly ?LockBackendInterface $lock = NULL,
    private readonly ?Oauth2ScopeAdapterInterface $scopeProvider = NULL,
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

      $count = $this->getMaintenance()->deleteExpired($current_time, $batch_size);
      if ($count === 0) {
        return 0;
      }

      if ($this->isStatisticsLoggingEnabled()) {
        $this->logger->info('Cleaned up @count expired device codes (batch size: @batch_size)', [
          '@count' => $count,
          '@batch_size' => $batch_size,
        ]);
      }

      return $count;
    }
    catch (\Exception $e) {
      $this->cleanupFailed = TRUE;
      $this->logger->error('Failed to cleanup expired device codes: @exception_type', [
        '@exception_type' => get_class($e),
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

      $count = $this->getMaintenance()->deleteOldAuthorized($cutoff_time, $batch_size);
      if ($count === 0) {
        return 0;
      }

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
      $this->cleanupFailed = TRUE;
      $this->logger->error('Failed to cleanup old authorized device codes: @exception_type', [
        '@exception_type' => get_class($e),
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
   * This legacy entry point is deprecated. Use the League grant for routed
   * token polling. It retains its existing interval response policy.
   *
   * @param \Drupal\simple_oauth_device_flow\Entity\DeviceCode $deviceCode
   *   The device code entity to validate polling for.
   *
   * @return array
   *   Array with 'valid' boolean and optional 'error' and 'error_description'.
   *
   * @see \League\OAuth2\Server\Grant\DeviceCodeGrant::respondToAccessTokenRequest()
   */
  public function validatePollingInterval(DeviceCode $deviceCode): array {
    try {
      $current_time = $this->time->getCurrentTime();
      $polling_interval = $this->settings->getPollingInterval();
      $remaining_time = $this->deviceCodeRepository->recordPolling(
        $deviceCode->getIdentifier(), $current_time, $polling_interval,
      );
      if ($remaining_time > 0) {
        return [
          'valid' => FALSE,
          'error' => 'slow_down',
          'error_description' => sprintf(
            'Polling too frequently. Wait %d seconds before next request.',
            $remaining_time
          ),
        ];
      }

      // Retain the public helper's timestamp update on the supplied instance.
      $deviceCode->setLastPolledAt(new \DateTimeImmutable('@' . $current_time));
      return ['valid' => TRUE];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to validate device polling interval: @exception_type', [
        '@exception_type' => get_class($e),
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
      $statistics = $this->getMaintenance()->countDeviceCodes($current_time) + [
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
      $this->logger->error('Failed to generate device code statistics: @exception_type', [
        '@exception_type' => get_class($e),
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
   * @throws \League\OAuth2\Server\Exception\OAuthServerException
   *   When a requested public scope name is unknown.
   * @throws \RuntimeException
   *   When device authorization generation fails.
   */
  public function generateDeviceAuthorization($client_entity, string $scope = ''): array {
    try {
      // Validate independently of generator extensions before creating codes.
      $generation = $this->settings->getGenerationSettings();
      // Resolve all public names before issuing or persisting credentials.
      $scopes = [];
      foreach (array_filter(explode(' ', trim($scope)), static fn(string $name): bool => $name !== '') as $name) {
        // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal -- Preserve the original constructor for external callers.
        $resolved = ($this->scopeRepository ?? \Drupal::service('simple_oauth.repositories.scope'))->getScopeEntityByIdentifier($name);
        if (!$resolved instanceof ScopeEntityInterface) {
          throw OAuthServerException::invalidScope($name);
        }
        $scopes[] = $resolved;
      }
      // Generate a unique device code.
      $device_code = $this->generateUniqueDeviceCode();

      // Generate a unique user code.
      $user_code = $this->userCodeGenerator->generateUserCode();

      // Get configuration values.
      $expires_in = $generation['lifetime'];
      $polling_interval = $this->settings->getPollingInterval();
      $current_time = $this->time->getCurrentTime();
      $expires_at = $current_time + $expires_in;

      // Create device code entity.
      $device_code_entity = $this->deviceCodeRepository->getNewDeviceCode();
      if ($this->scopeProvider !== NULL) {
        $device_code_entity->setScopeProvider($this->scopeProvider);
      }
      $device_code_entity->setIdentifier($device_code);
      $device_code_entity->setUserCode($user_code);
      $device_code_entity->setClient($client_entity);
      $device_code_entity->setExpiryDateTime(new \DateTimeImmutable('@' . $expires_at));

      // Set additional properties including scopes.
      $device_code_entity->set('created_at', $current_time);
      $device_code_entity->set('expires_at', $expires_at);
      $device_code_entity->set('authorized', FALSE);

      foreach ($scopes as $resolved) {
        $device_code_entity->addScope($resolved);
      }
      $device_code_entity->setInterval($polling_interval);

      // Persist the device code with all fields set.
      $this->deviceCodeRepository->persistDeviceCode($device_code_entity);

      $this->logger->info('Device authorization generated for client @client_id', [
        '@client_id' => $client_entity->getIdentifier(),
      ]);

      return [
        'device_code' => $device_code,
        'user_code' => $user_code,
        'expires_in' => $expires_in,
        'interval' => $polling_interval,
      ];
    }
    catch (OAuthServerException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate device authorization: @exception_type', [
        '@exception_type' => get_class($e),
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
    $this->cleanupFailed = FALSE;
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
   * Runs due scheduled maintenance, recording only fully successful runs.
   *
   * Manual cleanup methods do not consult or update scheduling state. Failed
   * batches retain the last success time and retry on the next cron invocation.
   * An empty successful cleanup advances the schedule normally.
   *
   * @return array|null
   *   Existing cleanup result keys, or NULL when skipped or a batch failed.
   */
  public function runScheduledCleanup(): ?array {
    if (!$this->settings->isCleanupEnabled()) {
      return NULL;
    }
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal -- Original constructors did not supply scheduler dependencies.
    $lock = $this->lock ?? \Drupal::lock();
    $lock_name = 'simple_oauth_device_flow.cleanup';
    if (!$lock->acquire($lock_name)) {
      return NULL;
    }
    try {
      // Read fresh state under the lock, after any concurrent run finishes.
      // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal -- Preserve original constructor calls.
      $state = $this->state ?? \Drupal::state();
      $state->resetCache();
      $last_success = $state->get('simple_oauth_device_flow.cleanup_last_success');
      $now = $this->time->getCurrentTime();
      if ($last_success !== NULL && $now < $last_success + $this->settings->getCleanupInterval()) {
        return NULL;
      }
      $result = $this->performCleanup();
      if ($this->cleanupFailed) {
        return NULL;
      }
      $state->set('simple_oauth_device_flow.cleanup_last_success', $this->time->getCurrentTime());
      return $result;
    }
    finally {
      $lock->release($lock_name);
    }
  }

  /**
   * Gets focused operations, adapting only legacy storage-based replacements.
   *
   * @return \Drupal\simple_oauth_device_flow\Repository\DeviceCodeMaintenanceInterface
   *   The supplied operations or bounded compatibility adapter.
   *
   * @throws \RuntimeException
   *   If neither maintenance operations nor legacy storage are available.
   */
  private function getMaintenance(): DeviceCodeMaintenanceInterface {
    if ($this->maintenance !== NULL) {
      return $this->maintenance;
    }
    if ($this->deviceCodeRepository instanceof DeviceCodeMaintenanceInterface) {
      return $this->deviceCodeRepository;
    }
    if ($this->deviceCodeRepository instanceof DeviceCodeLifecycleRepositoryInterface) {
      return new DeviceCodeStorageMaintenance($this->deviceCodeRepository);
    }
    throw new \RuntimeException('Device code maintenance operations are not available.');
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
    return max(1, $this->settings->getConfig()->get('max_cleanup_batch_size') ?? 1000);
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
        $this->logger->error('Error generating device code on attempt @attempt: @exception_type', [
          '@attempt' => $attempts,
          '@exception_type' => get_class($e),
        ]);
      }
    }

    $this->logger->error('Failed to generate unique device code after @attempts attempts', [
      '@attempts' => $max_attempts,
    ]);

    throw new \RuntimeException('Unable to generate unique device code after maximum attempts');
  }

}
