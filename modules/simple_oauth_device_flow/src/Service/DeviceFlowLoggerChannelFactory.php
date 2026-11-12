<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Service;

use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates device channels without request metadata that contains credentials.
 *
 * Device verification links contain a user code in the query string. Omitting
 * request-stack enrichment prevents those codes from reaching logger backends
 * through automatic URI and Referer fields. Explicit safe event context and
 * the standard channel, severity, timestamp and backend behavior are preserved.
 */
final class DeviceFlowLoggerChannelFactory implements LoggerChannelFactoryInterface {

  /**
   * Instantiated channels keyed by channel name.
   *
   * @var array<string, \Drupal\Core\Logger\LoggerChannelInterface>
   */
  private array $channels = [];

  /**
   * Logger backends grouped by priority.
   *
   * @var array<int, array<\Psr\Log\LoggerInterface>>
   */
  private array $loggers = [];

  /**
   * Creates or retrieves a channel without request-stack enrichment.
   *
   * @param string $channel
   *   The channel name.
   *
   * @return \Drupal\Core\Logger\LoggerChannelInterface
   *   The channel using the registered backends and standard default context.
   */
  public function get($channel): LoggerChannelInterface {
    if (!isset($this->channels[$channel])) {
      $instance = new LoggerChannel($channel);
      $instance->setLoggers($this->loggers);
      $this->channels[$channel] = $instance;
    }
    return $this->channels[$channel];
  }

  /**
   * Registers a backend for existing and future device channels.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger backend.
   * @param int $priority
   *   The backend priority.
   */
  public function addLogger(LoggerInterface $logger, $priority = 0): void {
    $this->loggers[$priority][] = $logger;
    foreach ($this->channels as $channel) {
      $channel->addLogger($logger, $priority);
    }
  }

}
