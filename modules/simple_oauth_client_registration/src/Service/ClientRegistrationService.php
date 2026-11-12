<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Service;

use Drupal\consumers\Entity\Consumer;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\simple_oauth\Oauth2ScopeProviderInterface;
use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Service for managing OAuth 2.0 client registration.
 *
 * Implements RFC 7591 Dynamic Client Registration Protocol logic.
 */
final class ClientRegistrationService {

  /**
   * Consumer scope fields keyed by the grant types that use them.
   *
   * @see \Drupal\simple_oauth\Repositories\ScopeRepository
   */
  private const SCOPE_FIELDS = [
    'authorization_code' => 'authorization_code_scopes',
    'client_credentials' => 'scopes',
  ];

  /**
   * Temporarily stores the generated secret for return in response.
   *
   * @var string|null
   */
  private ?string $generatedSecret = NULL;

  /**
   * Constructs the client registration service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The registration logger.
   * @param \Drupal\simple_oauth_client_registration\Service\RegistrationTokenService $tokenService
   *   The registration access token service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   * @param \Drupal\simple_oauth\Oauth2ScopeProviderInterface $scopeProvider
   *   The provider for configured or dynamically supplied scopes.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly RegistrationTokenService $tokenService,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly Oauth2ScopeProviderInterface $scopeProvider,
  ) {}

  /**
   * Creates a Consumer entity from client registration DTO.
   *
   * @param \Drupal\simple_oauth_client_registration\Dto\ClientRegistration $clientData
   *   The client registration DTO.
   *
   * @return \Drupal\consumers\Entity\ConsumerInterface
   *   The created Consumer entity.
   *
   * @throws \InvalidArgumentException
   *   When client data contains invalid values.
   */
  public function createConsumer(ClientRegistration $clientData): ConsumerInterface {
    $clientData->validateRedirectUris();

    // Generate unique client ID.
    $client_id = $this->generateClientId();

    // Determine if client should be confidential.
    $is_confidential = $clientData->isConfidential();

    // Determine default grant types based on configuration.
    // RFC 7591 Section 3.2.1 grants the authorization server the authority to
    // establish default values for omitted fields during registration.
    $config = $this->configFactory->get('simple_oauth_client_registration.settings');
    $default_grant_types = $config->get('default_grant_types') ?? ['authorization_code', 'refresh_token'];

    // Map RFC 7591 fields to Consumer entity fields using Consumer::create.
    $values = [
      'client_id' => $client_id,
      'label' => $clientData->clientName ?? 'Dynamically Registered Client',
      'description' => 'Client registered via RFC 7591 Dynamic Client Registration',
      'grant_types' => $clientData->grantTypes ?: $default_grant_types,
      'redirect' => $clientData->redirectUris,
      'confidential' => $is_confidential,
      'roles' => ['authenticated'],
      'is_default' => FALSE,
      'third_party' => TRUE,
      // Set token expiration defaults from ConsumerEntityTest.php patterns.
      'access_token_expiration' => 300,
      'refresh_token_expiration' => 1209600,
      // RFC 7591 metadata fields.
      'client_uri' => $clientData->clientUri ?? '',
      'logo_uri' => $clientData->logoUri ?? '',
      'tos_uri' => $clientData->tosUri ?? '',
      'policy_uri' => $clientData->policyUri ?? '',
      'jwks_uri' => $clientData->jwksUri ?? '',
      'software_id' => $clientData->softwareId ?? '',
      'software_version' => $clientData->softwareVersion ?? '',
    ];

    $values += $this->buildScopeFieldValues($clientData->scope, $values['grant_types']);

    // Generate client secret for confidential clients.
    if ($is_confidential) {
      $this->generatedSecret = $this->generateClientSecret();
      $values['secret'] = $this->generatedSecret;
    }
    else {
      $this->generatedSecret = NULL;
    }

    // Handle contacts field (multiple cardinality)
    $contacts = $clientData->contacts;
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
      $this->logger->info('Client registration request received with @redirect_count redirect URIs', [
        '@redirect_count' => count($registrationData->redirectUris),
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
      $this->logger->error('Client registration failed: @exception_type', [
        '@exception_type' => get_class($e),
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
  public function rotateClientSecret(string $client_id): array {
    // Keep cached metadata and credentials intact if persistence fails.
    $consumer = clone $this->loadConsumer($client_id);
    if (!(bool) $consumer->get('confidential')->value) {
      throw new BadRequestHttpException('Public clients do not have a client secret');
    }

    // Resolve metadata before saving so response preparation cannot fail later.
    $response = $this->getClientMetadataArray($consumer);
    $secret = $this->generateClientSecret();
    $consumer->set('secret', $secret);
    try {
      $consumer->save();
    }
    catch (\Throwable $e) {
      $this->entityTypeManager->getStorage('consumer')->resetCache([$consumer->id()]);
      throw $e;
    }

    return $response + [
      'client_secret' => $secret,
      'client_secret_expires_at' => 0,
    ];
  }

  /**
   * Applies administrative updates or deletions with an outcome for each item.
   *
   * @param array $items
   *   A list of items with client_id, operation (update/delete), and metadata
   *   for updates. Metadata follows the single-client management contract.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The administrator performing the operations.
   * @param \Symfony\Component\Serializer\Normalizer\DenormalizerInterface $normalizer
   *   The shared client metadata denormalizer.
   *
   * @return array
   *   Ordered per-item results containing only success or a safe error code.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the account lacks the existing OAuth administrative permission.
   * @throws \InvalidArgumentException
   *   If the batch is not a list of between one and 100 items.
   */
  public function manageClients(array $items, AccountInterface $account, DenormalizerInterface $normalizer): array {
    if (!$account->hasPermission('administer simple_oauth entities')) {
      throw new AccessDeniedHttpException();
    }
    if (!array_is_list($items) || count($items) < 1 || count($items) > 100) {
      throw new \InvalidArgumentException('Provide between one and 100 client operations');
    }

    $results = [];
    foreach ($items as $item) {
      try {
        if (!is_array($item) || !isset($item['client_id'], $item['operation']) ||
          !is_string($item['client_id']) || $item['client_id'] === '' ||
          !in_array($item['operation'], ['update', 'delete'], TRUE)) {
          throw new BadRequestHttpException('Invalid operation');
        }
        $client_id = $item['client_id'];
        $consumer = $this->loadConsumer($client_id);
        if (!$consumer->access($item['operation'], $account)) {
          throw new AccessDeniedHttpException();
        }
        if (!$this->tokenService->hasRegistrationAccessToken($client_id)) {
          throw new NotFoundHttpException();
        }
        if ($item['operation'] === 'update') {
          if (!isset($item['metadata']) || !is_array($item['metadata'])) {
            throw new BadRequestHttpException('Invalid metadata');
          }
          $metadata = $normalizer->denormalize($item['metadata'], ClientRegistration::class, 'json');
          $this->updateClientMetadata($client_id, $metadata);
        }
        else {
          $this->deleteClient($client_id);
        }
        $results[] = ['status' => 'success'];
      }
      catch (AccessDeniedHttpException) {
        $results[] = ['status' => 'access_denied'];
      }
      catch (NotFoundHttpException) {
        $results[] = ['status' => 'not_found'];
      }
      catch (BadRequestHttpException) {
        $results[] = ['status' => 'invalid_client_metadata'];
      }
      catch (\Exception $e) {
        $this->logger->error('Administrative client operation failed: @exception_type', [
          '@exception_type' => get_class($e),
        ]);
        $results[] = ['status' => 'server_error'];
      }
    }
    return $results;
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
    // Validate the complete list before mutating even the cached consumer.
    $metadata->validateRedirectUris();
    $consumer = $this->loadConsumer($client_id);

    // Handle client_name specially - it maps to the label field.
    if ($metadata->clientName !== NULL) {
      $consumer->set('label', $metadata->clientName);
    }

    // Update RFC 7591 fields.
    if ($metadata->clientUri !== NULL) {
      $consumer->set('client_uri', $metadata->clientUri);
    }
    if ($metadata->logoUri !== NULL) {
      $consumer->set('logo_uri', $metadata->logoUri);
    }
    if ($metadata->tosUri !== NULL) {
      $consumer->set('tos_uri', $metadata->tosUri);
    }
    if ($metadata->policyUri !== NULL) {
      $consumer->set('policy_uri', $metadata->policyUri);
    }
    if ($metadata->jwksUri !== NULL) {
      $consumer->set('jwks_uri', $metadata->jwksUri);
    }
    if ($metadata->softwareId !== NULL) {
      $consumer->set('software_id', $metadata->softwareId);
    }
    if ($metadata->softwareVersion !== NULL) {
      $consumer->set('software_version', $metadata->softwareVersion);
    }

    // Update contacts if provided.
    $contacts = $metadata->contacts;
    if (!empty($contacts)) {
      $consumer->set('contacts', $contacts);
    }

    // Update redirect URIs if provided.
    $redirectUris = $metadata->redirectUris;
    if (!empty($redirectUris)) {
      $consumer->set('redirect', $redirectUris);
    }

    // Omission preserves scopes; an explicit empty value clears them.
    if ($metadata->scope !== NULL) {
      $grant_types = array_column($consumer->get('grant_types')->getValue(), 'value');
      foreach ($this->buildScopeFieldValues($metadata->scope, $grant_types) as $field_name => $value) {
        // Client credentials use an administrative allowlist. Clearing it also
        // removes its restriction, so clients must not rewrite this field.
        if ($field_name === self::SCOPE_FIELDS['client_credentials']) {
          continue;
        }
        $consumer->set($field_name, $value);
      }
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
   * @return \Drupal\consumers\Entity\ConsumerInterface
   *   The consumer entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If the client is not found.
   */
  private function loadConsumer(string $client_id): ConsumerInterface {
    $consumer_storage = $this->entityTypeManager->getStorage('consumer');

    /** @var \Drupal\consumers\Entity\ConsumerInterface[] $consumers */
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
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer entity.
   *
   * @return array
   *   The client metadata array.
   */
  private function getClientMetadataArray(ConsumerInterface $consumer): array {
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
      'scope' => $this->getRegisteredScope($consumer),
    ];

    // Get redirect URIs.
    if (!$consumer->get('redirect')->isEmpty()) {
      foreach ($consumer->get('redirect') as $redirect) {
        $metadata['redirect_uris'][] = $redirect->get('value')->getValue();
      }
    }

    // Get contacts.
    if (!$consumer->get('contacts')->isEmpty()) {
      foreach ($consumer->get('contacts') as $contact) {
        $metadata['contacts'][] = $contact->get('value')->getValue();
      }
    }

    // Get grant types.
    if (!$consumer->get('grant_types')->isEmpty()) {
      foreach ($consumer->get('grant_types') as $grant_type) {
        $metadata['grant_types'][] = $grant_type->get('value')->getValue();
      }
    }

    // Report even an empty scope when the requested selection was filtered out.
    return array_filter($metadata, static function ($value, string $key): bool {
      return $key === 'scope' || ($value !== '' && $value !== []);
    }, ARRAY_FILTER_USE_BOTH);
  }

  /**
   * Builds the registered scope selection for each supported grant type.
   *
   * Unknown scopes and scopes disabled for the client's grants are omitted.
   * RFC 7591 section 2 permits overriding requested scopes when the response
   * reports the registered selection. Empty selections clear existing values.
   *
   * @param string|null $scope
   *   The requested space-delimited scope names, or NULL when omitted.
   * @param string[] $grant_types
   *   The grant types registered for the client.
   *
   * @return array<string, array<int, array{scope_id: string}>>
   *   Field values for each grant-specific consumer scope field.
   */
  private function buildScopeFieldValues(?string $scope, array $grant_types): array {
    $values = array_fill_keys(self::SCOPE_FIELDS, []);
    $names = preg_split('/\s+/', trim($scope ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    if (!$names) {
      return $values;
    }

    $scopes = $this->scopeProvider->loadMultipleByNames($names);
    foreach (self::SCOPE_FIELDS as $grant_type => $field_name) {
      if (!in_array($grant_type, $grant_types, TRUE)) {
        continue;
      }
      foreach ($scopes as $registered_scope) {
        if ($registered_scope->isGrantTypeEnabled($grant_type)) {
          $values[$field_name][] = ['scope_id' => $registered_scope->id()];
        }
      }
    }
    return $values;
  }

  /**
   * Returns the registered scope names across grant-specific consumer fields.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The registered consumer.
   *
   * @return string
   *   Unique space-delimited scope names, or an empty string for no scopes.
   */
  private function getRegisteredScope(ConsumerInterface $consumer): string {
    $names = [];
    foreach (self::SCOPE_FIELDS as $field_name) {
      if (!$consumer->hasField($field_name)) {
        continue;
      }
      $scope_ids = array_column($consumer->get($field_name)->getValue(), 'scope_id');
      // The static provider interprets an empty ID list as every known scope.
      if ($scope_ids === []) {
        continue;
      }
      foreach ($this->scopeProvider->loadMultiple($scope_ids) as $scope) {
        $names[$scope->getName()] = TRUE;
      }
    }
    return implode(' ', array_keys($names));
  }

  /**
   * Gets the logo URI for a consumer, prioritizing image field over logo_uri.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer entity.
   *
   * @return string
   *   The logo URI or empty string if none available.
   */
  private function getLogoUri(ConsumerInterface $consumer): string {
    // First, check if there's an uploaded image file.
    if (!$consumer->get('image')->isEmpty()) {
      $image_field = $consumer->get('image')->first();
      $file = $image_field?->get('entity')->getValue();
      if ($file instanceof FileInterface) {
        // Generate absolute URL for the image file.
        $file_uri = $file->getFileUri();
        return $this->fileUrlGenerator->generateAbsoluteString($file_uri);
      }
    }

    // Fallback to logo_uri field if no image uploaded.
    return $consumer->get('logo_uri')->value ?? '';
  }

}
