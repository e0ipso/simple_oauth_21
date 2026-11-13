<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Service;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Utility\Crypt;
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
final class ClientRegistrationService implements ClientRegistrationOperationsInterface, ClientRegistrationAdministrationInterface {

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
   * @param \Drupal\simple_oauth_client_registration\Service\ClientMetadataMapper|null $metadataMapper
   *   The shared response mapper, or the built-in profile for legacy callers.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly RegistrationTokenService $tokenService,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly Oauth2ScopeProviderInterface $scopeProvider,
    private readonly ?ClientMetadataMapper $metadataMapper = NULL,
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

    // Map RFC 7591 fields to Consumer entity fields.
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

    /** @var \Drupal\consumers\Entity\ConsumerInterface $consumer */
    $consumer = $this->entityTypeManager->getStorage('consumer')->create($values);
    $this->applyApplicationType($consumer, $clientData->applicationType);
    $this->validateConsumer($consumer);
    try {
      $consumer->save();
    }
    catch (\Throwable $e) {
      $this->resetAfterRollback();
      throw $e;
    }

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
    $transaction = $this->tokenService->startTransaction();
    try {
      $this->logger->info('Client registration request received with @redirect_count redirect URIs', [
        '@redirect_count' => count($registrationData->redirectUris),
      ]);

      // Create the Consumer entity using the proven creation pattern.
      $consumer = $this->createConsumer($registrationData);
      $client_id = $consumer->getClientId();

      $secret = (bool) $consumer->get('confidential')->value ? $this->generatedSecret : NULL;
      $prepared = $this->tokenService->prepareRegistrationResponse($client_id, $secret);
      $response = $this->getClientMetadataArray($consumer, $prepared);

      $this->logger->info('Client registration successful for client_id: @client_id', [
        '@client_id' => $client_id,
      ]);

      return $response;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->resetAfterRollback();
      $this->logger->error('Client registration failed: @exception_type', [
        '@exception_type' => get_class($e),
      ]);
      throw $e;
    }
    finally {
      $this->generatedSecret = NULL;
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
   *   If the client does not use a secret or completed entity validation fails.
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
    $this->validateConsumer($consumer);
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
    $consumer = clone $this->loadConsumer($client_id);

    $this->applyApplicationType($consumer, $metadata->applicationType);

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
    if ($metadata->contactsProvided || !empty($contacts)) {
      $consumer->set('contacts', $contacts);
    }

    // Update redirect URIs if provided.
    $redirectUris = $metadata->redirectUris;
    if (!empty($redirectUris)) {
      $consumer->set('redirect', $redirectUris);
    }

    // Omission preserves authorization defaults; an empty value clears them.
    if ($metadata->scope !== NULL) {
      $grant_types = array_column($consumer->get('grant_types')->getValue(), 'value');
      foreach ($this->buildScopeFieldValues($metadata->scope, $grant_types) as $field_name => $value) {
        // Client credentials use an administrative allowlist. Clearing it also
        // removes its restriction, so clients must not rewrite this field.
        // Supported schemas before 6.0.6 lack authorization-code defaults.
        if (!$consumer->hasField($field_name) || $field_name === self::SCOPE_FIELDS['client_credentials']) {
          continue;
        }
        $consumer->set($field_name, $value);
      }
    }

    $this->validateConsumer($consumer);
    $transaction = $this->tokenService->startTransaction();
    try {
      $consumer->save();
      return $this->getClientMetadataArray($consumer);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->resetAfterRollback();
      throw $e;
    }
  }

  /**
   * Deletes a client registration.
   *
   * @param string $client_id
   *   The client identifier.
   */
  public function deleteClient(string $client_id): void {
    $consumer = $this->loadConsumer($client_id);

    $transaction = $this->tokenService->startTransaction();
    try {
      $this->tokenService->revokeLifecycleCredentials($client_id);
      $consumer->delete();
      $this->logger->info('Client registration deleted for client_id: @client_id', [
        '@client_id' => $client_id,
      ]);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->resetAfterRollback();
      throw $e;
    }
  }

  /**
   * Validates the completed consumer through every installed constraint.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The completed consumer, including preserved fields on partial updates.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   If any entity or field constraint rejects the completed values.
   */
  private function validateConsumer(ConsumerInterface $consumer): void {
    if ($consumer->validate()->count() > 0) {
      throw new BadRequestHttpException('Invalid client metadata');
    }
  }

  /**
   * Retains a native declaration using an installed classification control.
   *
   * Registration may opt into native policy, but cannot replace an explicit
   * administrator classification or disable an existing native requirement.
   * Omitted and web declarations leave classification and PKCE controls intact.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer being created or updated.
   * @param string|null $application_type
   *   The declared application type, or NULL when omitted.
   */
  private function applyApplicationType(ConsumerInterface $consumer, ?string $application_type): void {
    if ($application_type !== 'native' || !$consumer->hasField('native_app_override')) {
      return;
    }
    $override = $consumer->get('native_app_override')->value;
    if ($override === NULL || $override === '' || $override === 'auto-detect') {
      $consumer->set('native_app_override', 'native');
    }
  }

  /**
   * Discards cached entities and configuration after lifecycle rollback.
   *
   * Entity hooks may have loaded or deleted consumer-specific configuration.
   * A database rollback alone does not restore those in-memory objects.
   */
  private function resetAfterRollback(): void {
    $this->entityTypeManager->getStorage('consumer')->resetCache();
    $this->configFactory->reset();
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
   * @param \Drupal\simple_oauth_client_registration\Dto\ClientRegistration|null $prepared
   *   Credentials explicitly prepared during registration, when supplied.
   *
   * @return array
   *   The client metadata array.
   */
  private function getClientMetadataArray(ConsumerInterface $consumer, ?ClientRegistration $prepared = NULL): array {
    $metadata = ($this->metadataMapper ?? new ClientMetadataMapper($this->fileUrlGenerator))->map($consumer, $prepared);
    // Management responses preserve even an empty registered scope selection.
    $metadata['scope'] = $this->getRegisteredScope($consumer);
    return $metadata;
  }

  /**
   * Builds the registered scope selection for each supported grant type.
   *
   * Unknown scopes and scopes disabled for the client's grants are omitted.
   * RFC 7591 section 2 permits overriding requested scopes when the response
   * reports the registered selection. Empty selections return empty field
   * lists; callers decide which fields are writable.
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

}
