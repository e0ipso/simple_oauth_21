<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Service;

use Drupal\consumers\Entity\Consumer;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Url;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Service for managing OAuth 2.0 client registration.
 *
 * Implements RFC 7591 Dynamic Client Registration Protocol logic.
 */
final class ClientRegistrationService {

  /**
   * Temporarily stores the generated secret for return in response.
   *
   * @var string|null
   */
  private ?string $generatedSecret = NULL;

  /**
   * Constructor.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly UuidInterface $uuid,
    private readonly RegistrationTokenService $tokenService,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Creates a Consumer entity from client registration DTO.
   *
   * @param \Drupal\simple_oauth_client_registration\Dto\ClientRegistration $clientData
   *   The client registration DTO.
   *
   * @return \Drupal\consumers\Entity\ConsumerInterface
   *   The created Consumer entity.
   */
  public function createConsumer(ClientRegistration $clientData): ConsumerInterface {
    // Generate unique client ID.
    $client_id = $this->generateClientId();

    // Determine if client should be confidential.
    $is_confidential = $clientData->isConfidential();

    // Map RFC 7591 fields to Consumer entity fields using Consumer::create.
    $values = [
      'client_id' => $client_id,
      'label' => $clientData->getClientName() ?? 'Dynamically Registered Client',
      'description' => 'Client registered via RFC 7591 Dynamic Client Registration',
      'grant_types' => $clientData->getGrantTypes() ?: ['authorization_code'],
      'redirect' => $clientData->getRedirectUris(),
      'confidential' => $is_confidential,
      'roles' => ['authenticated'],
      'is_default' => FALSE,
      'third_party' => TRUE,
      // Set token expiration defaults from ConsumerEntityTest.php patterns.
      'access_token_expiration' => 300,
      'refresh_token_expiration' => 1209600,
      // RFC 7591 metadata fields.
      'client_uri' => $clientData->getClientUri() ?? '',
      'logo_uri' => $clientData->getLogoUri() ?? '',
      'tos_uri' => $clientData->getTosUri() ?? '',
      'policy_uri' => $clientData->getPolicyUri() ?? '',
      'jwks_uri' => $clientData->getJwksUri() ?? '',
      'software_id' => $clientData->getSoftwareId() ?? '',
      'software_version' => $clientData->getSoftwareVersion() ?? '',
    ];

    // Generate client secret for confidential clients.
    if ($is_confidential) {
      $this->generatedSecret = $this->generateClientSecret();
      $values['secret'] = $this->generatedSecret;
    }
    else {
      $this->generatedSecret = NULL;
    }

    // Handle contacts field (multiple cardinality)
    $contacts = $clientData->getContacts();
    if (!empty($contacts)) {
      $values['contacts'] = [];
      foreach ($contacts as $contact) {
        $values['contacts'][] = ['value' => $contact];
      }
    }

    // Create Consumer using the proven Consumer::create() pattern.
    $consumer = Consumer::create($values);
    $consumer->save();

    return $consumer;
  }

  /**
   * Registers a new OAuth 2.0 client.
   *
   * @param \Drupal\simple_oauth_client_registration\Dto\ClientRegistration $registrationData
   *   The client registration DTO.
   *
   * @return array
   *   The registration response data.
   */
  public function registerClient(ClientRegistration $registrationData): array {
    try {
      $this->logger->info('Client registration request received with data: @data', [
        '@data' => json_encode($registrationData->toArray()),
      ]);

      // Create the Consumer entity using the proven creation pattern.
      $consumer = $this->createConsumer($registrationData);
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
      if ($consumer->get('confidential')->value && !empty($this->generatedSecret)) {
        // Return the original secret value, not the hashed version stored in
        // DB.
        $response['client_secret'] = $this->generatedSecret;
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
   * @param \Drupal\simple_oauth_client_registration\Dto\ClientRegistration $metadata
   *   The updated metadata DTO.
   *
   * @return array
   *   The updated client metadata.
   */
  public function updateClientMetadata(string $client_id, ClientRegistration $metadata): array {
    $consumer = $this->loadConsumer($client_id);

    // Handle client_name specially - it maps to the label field.
    if ($metadata->getClientName() !== NULL) {
      $consumer->set('label', $metadata->getClientName());
    }

    // Update RFC 7591 fields.
    if ($metadata->getClientUri() !== NULL) {
      $consumer->set('client_uri', $metadata->getClientUri());
    }
    if ($metadata->getLogoUri() !== NULL) {
      $consumer->set('logo_uri', $metadata->getLogoUri());
    }
    if ($metadata->getTosUri() !== NULL) {
      $consumer->set('tos_uri', $metadata->getTosUri());
    }
    if ($metadata->getPolicyUri() !== NULL) {
      $consumer->set('policy_uri', $metadata->getPolicyUri());
    }
    if ($metadata->getJwksUri() !== NULL) {
      $consumer->set('jwks_uri', $metadata->getJwksUri());
    }
    if ($metadata->getSoftwareId() !== NULL) {
      $consumer->set('software_id', $metadata->getSoftwareId());
    }
    if ($metadata->getSoftwareVersion() !== NULL) {
      $consumer->set('software_version', $metadata->getSoftwareVersion());
    }

    // Update contacts if provided.
    $contacts = $metadata->getContacts();
    if (!empty($contacts)) {
      $consumer->set('contacts', $contacts);
    }

    // Update redirect URIs if provided.
    $redirectUris = $metadata->getRedirectUris();
    if (!empty($redirectUris)) {
      $consumer->set('redirect', $redirectUris);
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
   * Generates a unique client ID.
   *
   * @return string
   *   A unique client identifier.
   */
  private function generateClientId(): string {
    $consumer_storage = $this->entityTypeManager->getStorage('consumer');

    do {
      // Generate a random client ID using the same method as Simple OAuth.
      $client_id = Crypt::randomBytesBase64(32);
      // Ensure uniqueness by checking existing Consumer entities.
      $existing = $consumer_storage->loadByProperties(['client_id' => $client_id]);
    } while (!empty($existing));

    return $client_id;
  }

  /**
   * Generates a client secret for confidential clients.
   *
   * @return string
   *   A client secret.
   */
  private function generateClientSecret(): string {
    // Generate a cryptographically secure secret using the same method as
    // Simple OAuth.
    return Crypt::randomBytesBase64(32);
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
      'client_id' => $client_id,
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
      'client_name' => $consumer->label(),
      'client_uri' => $consumer->get('client_uri')->value ?? '',
      'logo_uri' => $this->getLogoUri($consumer),
      'tos_uri' => $consumer->get('tos_uri')->value ?? '',
      'policy_uri' => $consumer->get('policy_uri')->value ?? '',
      'jwks_uri' => $consumer->get('jwks_uri')->value ?? '',
      'software_id' => $consumer->get('software_id')->value ?? '',
      'software_version' => $consumer->get('software_version')->value ?? '',
      'redirect_uris' => [],
      'contacts' => [],
      'grant_types' => [],
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

    // Get grant types.
    if (!$consumer->get('grant_types')->isEmpty()) {
      foreach ($consumer->get('grant_types') as $grant_type) {
        $metadata['grant_types'][] = $grant_type->value;
      }
    }

    // Remove empty fields.
    return array_filter($metadata, function ($value) {
      return $value !== '' && $value !== [];
    });
  }

  /**
   * Gets the logo URI for a consumer, prioritizing image field over logo_uri.
   *
   * @param \Drupal\Core\Entity\EntityInterface $consumer
   *   The consumer entity.
   *
   * @return string
   *   The logo URI or empty string if none available.
   */
  private function getLogoUri($consumer): string {
    // First, check if there's an uploaded image file.
    if (!$consumer->get('image')->isEmpty()) {
      $image_field = $consumer->get('image')->first();
      if ($image_field && $image_field->entity) {
        $file = $image_field->entity;
        // Generate absolute URL for the image file.
        $file_uri = $file->getFileUri();
        return $this->fileUrlGenerator->generateAbsoluteString($file_uri);
      }
    }

    // Fallback to logo_uri field if no image uploaded.
    return $consumer->get('logo_uri')->value ?? '';
  }

}
