<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Uuid\UuidInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing OAuth 2.0 client registration.
 *
 * Implements RFC 7591 Dynamic Client Registration Protocol logic.
 */
final class ClientRegistrationService {

  /**
   * Constructor.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly UuidInterface $uuid,
  ) {}

  /**
   * Registers a new OAuth 2.0 client.
   *
   * @param array $registrationData
   *   The client registration data.
   *
   * @return array
   *   The registration response data.
   */
  public function registerClient(array $registrationData): array {
    // Placeholder implementation - will be implemented in subsequent tasks.
    $this->logger->info('Client registration request received');

    return [
      'error' => 'not_implemented',
      'error_description' => 'Client registration service not yet implemented',
    ];
  }

}
