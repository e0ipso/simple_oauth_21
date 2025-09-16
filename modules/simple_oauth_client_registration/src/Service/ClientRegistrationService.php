<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
    private readonly RegistrationTokenService $tokenService,
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
    try {
      $this->logger->info('Client registration request received with data: @data', [
        '@data' => json_encode($registrationData),
      ]);

      // Validate required client metadata.
      $this->validateClientMetadata($registrationData);

      // Create the Consumer entity.
      $consumer_storage = $this->entityTypeManager->getStorage('consumer');
      $consumer = $consumer_storage->create([
        'label' => $registrationData['client_name'] ?? 'Dynamically Registered Client',
        'description' => 'Client registered via RFC 7591 Dynamic Client Registration',
        'roles' => ['authenticated'],
        'is_default' => FALSE,
        'confidential' => $registrationData['token_endpoint_auth_method'] !== 'none',
        'redirect' => $registrationData['redirect_uris'] ?? [],
        // RFC 7591 metadata fields.
        'client_name' => $registrationData['client_name'] ?? '',
        'client_uri' => $registrationData['client_uri'] ?? '',
        'logo_uri' => $registrationData['logo_uri'] ?? '',
        'contacts' => $registrationData['contacts'] ?? [],
        'tos_uri' => $registrationData['tos_uri'] ?? '',
        'policy_uri' => $registrationData['policy_uri'] ?? '',
        'jwks_uri' => $registrationData['jwks_uri'] ?? '',
        'software_id' => $registrationData['software_id'] ?? '',
        'software_version' => $registrationData['software_version'] ?? '',
      ]);

      $consumer->save();
      $client_id = $consumer->getClientId();

      // Generate registration access token.
      $registration_access_token = $this->tokenService->generateRegistrationAccessToken($client_id);

      // Build registration client URI.
      $registration_client_uri = Url::fromRoute('simple_oauth_client_registration.manage', [
        'client_id' => $client_id,
      ], ['absolute' => TRUE])->toString();

      // Build response according to RFC 7591.
      $response = [
        'client_id' => $client_id,
        'registration_access_token' => $registration_access_token,
        'registration_client_uri' => $registration_client_uri,
        'client_id_issued_at' => time(),
      ];

      // Include client secret for confidential clients.
      if ($consumer->get('confidential')->value) {
        $response['client_secret'] = $consumer->getSecret();
        // Never expires.
        $response['client_secret_expires_at'] = 0;
      }

      // Include all client metadata in response.
      $response = array_merge($response, $this->getClientMetadataArray($consumer));

      $this->logger->info('Client registration successful for client_id: @client_id', [
        '@client_id' => $client_id,
      ]);

      return $response;
    }
    catch (\Exception $e) {
      $this->logger->error('Client registration failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Retrieves client metadata.
   *
   * @param string $client_id
   *   The client identifier.
   *
   * @return array
   *   The client metadata.
   */
  public function getClientMetadata(string $client_id): array {
    $consumer = $this->loadConsumer($client_id);
    return $this->getClientMetadataArray($consumer);
  }

  /**
   * Updates client metadata.
   *
   * @param string $client_id
   *   The client identifier.
   * @param array $metadata
   *   The updated metadata.
   *
   * @return array
   *   The updated client metadata.
   */
  public function updateClientMetadata(string $client_id, array $metadata): array {
    $consumer = $this->loadConsumer($client_id);

    // Validate metadata.
    $this->validateClientMetadata($metadata);

    // Update RFC 7591 fields.
    $rfc_fields = [
      'client_name', 'client_uri', 'logo_uri', 'contacts',
      'tos_uri', 'policy_uri', 'jwks_uri', 'software_id', 'software_version',
    ];

    foreach ($rfc_fields as $field) {
      if (isset($metadata[$field])) {
        $consumer->set($field, $metadata[$field]);
      }
    }

    // Update redirect URIs if provided.
    if (isset($metadata['redirect_uris'])) {
      $consumer->set('redirect', $metadata['redirect_uris']);
    }

    $consumer->save();

    return $this->getClientMetadataArray($consumer);
  }

  /**
   * Deletes a client registration.
   *
   * @param string $client_id
   *   The client identifier.
   */
  public function deleteClient(string $client_id): void {
    $consumer = $this->loadConsumer($client_id);

    // Remove registration token.
    $this->tokenService->revokeRegistrationAccessToken($client_id);

    // Delete the consumer.
    $consumer->delete();

    $this->logger->info('Client registration deleted for client_id: @client_id', [
      '@client_id' => $client_id,
    ]);
  }

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
  public function validateRegistrationToken(string $client_id, string $token): bool {
    return $this->tokenService->validateRegistrationAccessToken($client_id, $token);
  }

  /**
   * Validates client metadata according to RFC 7591.
   *
   * @param array $metadata
   *   The client metadata to validate.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   If validation fails.
   */
  private function validateClientMetadata(array $metadata): void {
    // Validate redirect URIs if provided.
    if (isset($metadata['redirect_uris'])) {
      if (!is_array($metadata['redirect_uris']) || empty($metadata['redirect_uris'])) {
        throw new BadRequestHttpException('redirect_uris must be a non-empty array');
      }

      foreach ($metadata['redirect_uris'] as $uri) {
        if (!filter_var($uri, FILTER_VALIDATE_URL)) {
          throw new BadRequestHttpException('Invalid redirect URI: ' . $uri);
        }
      }
    }

    // Validate URI fields.
    $uri_fields = ['client_uri', 'logo_uri', 'tos_uri', 'policy_uri', 'jwks_uri'];
    foreach ($uri_fields as $field) {
      if (isset($metadata[$field]) && !empty($metadata[$field])) {
        if (!filter_var($metadata[$field], FILTER_VALIDATE_URL)) {
          throw new BadRequestHttpException('Invalid URI for ' . $field . ': ' . $metadata[$field]);
        }
      }
    }

    // Validate contacts array.
    if (isset($metadata['contacts'])) {
      if (!is_array($metadata['contacts'])) {
        throw new BadRequestHttpException('contacts must be an array');
      }

      foreach ($metadata['contacts'] as $contact) {
        if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) {
          throw new BadRequestHttpException('Invalid email in contacts: ' . $contact);
        }
      }
    }

    // Validate string fields.
    $string_fields = ['client_name', 'software_id', 'software_version'];
    foreach ($string_fields as $field) {
      if (isset($metadata[$field]) && !is_string($metadata[$field])) {
        throw new BadRequestHttpException($field . ' must be a string');
      }
    }
  }

  /**
   * Loads a consumer entity by client ID.
   *
   * @param string $client_id
   *   The client identifier.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The consumer entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If the client is not found.
   */
  private function loadConsumer(string $client_id) {
    $consumer_storage = $this->entityTypeManager->getStorage('consumer');

    $consumers = $consumer_storage->loadByProperties([
      'uuid' => $client_id,
    ]);

    if (empty($consumers)) {
      throw new NotFoundHttpException('Client not found');
    }

    return reset($consumers);
  }

  /**
   * Converts a consumer entity to RFC 7591 metadata array.
   *
   * @param \Drupal\Core\Entity\EntityInterface $consumer
   *   The consumer entity.
   *
   * @return array
   *   The client metadata array.
   */
  private function getClientMetadataArray($consumer): array {
    $metadata = [
      'client_id' => $consumer->getClientId(),
      'client_name' => $consumer->get('client_name')->value ?? '',
      'client_uri' => $consumer->get('client_uri')->uri ?? '',
      'logo_uri' => $consumer->get('logo_uri')->uri ?? '',
      'tos_uri' => $consumer->get('tos_uri')->uri ?? '',
      'policy_uri' => $consumer->get('policy_uri')->uri ?? '',
      'jwks_uri' => $consumer->get('jwks_uri')->uri ?? '',
      'software_id' => $consumer->get('software_id')->value ?? '',
      'software_version' => $consumer->get('software_version')->value ?? '',
      'redirect_uris' => [],
      'contacts' => [],
    ];

    // Get redirect URIs.
    if (!$consumer->get('redirect')->isEmpty()) {
      foreach ($consumer->get('redirect') as $redirect) {
        $metadata['redirect_uris'][] = $redirect->value;
      }
    }

    // Get contacts.
    if (!$consumer->get('contacts')->isEmpty()) {
      foreach ($consumer->get('contacts') as $contact) {
        $metadata['contacts'][] = $contact->value;
      }
    }

    // Remove empty fields.
    return array_filter($metadata, function ($value) {
      return $value !== '' && $value !== [];
    });
  }

}
