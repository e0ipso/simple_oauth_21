<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_registration_test;

use Drupal\Core\Session\AccountInterface;
use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;
use Drupal\simple_oauth_client_registration\Service\ClientRegistrationOperationsInterface;
use Drupal\simple_oauth_client_registration\Service\ClientRegistrationAdministrationInterface;
use Drupal\simple_oauth_client_registration\Service\ClientRegistrationService;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Records calls while preserving the composed registration implementation.
 */
final class RegistrationOperationsDecorator implements ClientRegistrationOperationsInterface, ClientRegistrationAdministrationInterface {

  /**
   * Operations invoked through the decorator.
   *
   * @var string[]
   */
  public array $calls = [];

  /**
   * Constructs the recording decorator.
   *
   * @param \Drupal\simple_oauth_client_registration\Service\ClientRegistrationService $inner
   *   The composed implementation, which remains final.
   */
  public function __construct(private readonly ClientRegistrationService $inner) {}

  /**
   * {@inheritdoc}
   */
  public function registerClient(ClientRegistration $registrationData): array {
    $this->calls[] = __FUNCTION__;
    return $this->inner->registerClient($registrationData);
  }

  /**
   * {@inheritdoc}
   */
  public function getClientMetadata(string $client_id): array {
    $this->calls[] = __FUNCTION__;
    return $this->inner->getClientMetadata($client_id);
  }

  /**
   * {@inheritdoc}
   */
  public function rotateClientSecret(string $client_id): array {
    $this->calls[] = __FUNCTION__;
    return $this->inner->rotateClientSecret($client_id);
  }

  /**
   * {@inheritdoc}
   */
  public function updateClientMetadata(string $client_id, ClientRegistration $metadata): array {
    $this->calls[] = __FUNCTION__;
    return $this->inner->updateClientMetadata($client_id, $metadata);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteClient(string $client_id): void {
    $this->calls[] = __FUNCTION__;
    $this->inner->deleteClient($client_id);
  }

  /**
   * {@inheritdoc}
   */
  public function validateRegistrationToken(string $client_id, string $token): bool {
    $this->calls[] = __FUNCTION__;
    return $this->inner->validateRegistrationToken($client_id, $token);
  }

  /**
   * {@inheritdoc}
   */
  public function manageClients(array $items, AccountInterface $account, DenormalizerInterface $normalizer): array {
    $this->calls[] = __FUNCTION__;
    return $this->inner->manageClients($items, $account, $normalizer);
  }

}
