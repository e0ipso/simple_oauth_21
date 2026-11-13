<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Service;

use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;

/**
 * Defines registration and credential-authenticated management operations.
 */
interface ClientRegistrationOperationsInterface {

  /**
   * Registers a new OAuth 2.0 client.
   *
   * @param \Drupal\simple_oauth_client_registration\Dto\ClientRegistration $registrationData
   *   The client registration DTO.
   *
   * @return array
   *   The registration response data.
   */
  public function registerClient(ClientRegistration $registrationData): array;

  /**
   * Retrieves client metadata.
   *
   * @param string $client_id
   *   The client identifier.
   *
   * @return array
   *   The client metadata.
   */
  public function getClientMetadata(string $client_id): array;

  /**
   * Replaces the secret of a confidential client.
   *
   * Callers must authenticate the client's registration access token first.
   * The password field hashes the replacement during transactional entity save.
   *
   * @param string $client_id
   *   The authenticated client identifier.
   *
   * @return array
   *   Client metadata and the replacement secret, for this response only.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   If the client does not use a secret.
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If saving the replacement fails.
   */
  public function rotateClientSecret(string $client_id): array;

  /**
   * Updates client metadata.
   *
   * @param string $client_id
   *   The client identifier.
   * @param \Drupal\simple_oauth_client_registration\Dto\ClientRegistration $metadata
   *   The updated metadata DTO.
   *
   * @return array
   *   The updated client metadata.
   */
  public function updateClientMetadata(string $client_id, ClientRegistration $metadata): array;

  /**
   * Deletes a client registration.
   *
   * @param string $client_id
   *   The client identifier.
   */
  public function deleteClient(string $client_id): void;

  /**
   * Validates a registration access token.
   *
   * @param string $client_id
   *   The client identifier.
   * @param string $token
   *   The registration access token.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  public function validateRegistrationToken(string $client_id, string $token): bool;

}
