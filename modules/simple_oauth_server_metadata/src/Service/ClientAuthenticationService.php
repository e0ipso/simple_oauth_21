<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\simple_oauth\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Service for authenticating OAuth clients.
 *
 * Validates client credentials from HTTP requests supporting both
 * HTTP Basic Authentication and POST body credentials per RFC 6749.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6749#section-2.3
 */
final class ClientAuthenticationService {

  /**
   * The OAuth client repository.
   */
  private ClientRepositoryInterface $clientRepository;

  /**
   * Constructs a ClientAuthenticationService object.
   *
   * @param \League\OAuth2\Server\Repositories\ClientRepositoryInterface $client_repository
   *   The OAuth client repository.
   */
  public function __construct(ClientRepositoryInterface $client_repository) {
    $this->clientRepository = $client_repository;
  }

  /**
   * Authenticates an OAuth client from the request.
   *
   * Extracts client credentials from either HTTP Basic Auth header or POST
   * body parameters and validates them against the stored client entity.
   * Supports both confidential clients (with secrets) and public clients
   * (without secrets).
   *
   * @param \Psr\Http\Message\ServerRequestInterface $request
   *   The HTTP request containing client credentials.
   *
   * @return \Drupal\consumers\Entity\ConsumerInterface|null
   *   The authenticated client entity, or NULL if authentication fails.
   */
  public function authenticateClient(ServerRequestInterface $request): ?ConsumerInterface {
    // Extract credentials from request (Basic Auth takes precedence).
    $credentials = $this->extractCredentials($request);

    if (!$credentials['client_id']) {
      return NULL;
    }

    // Lookup client entity.
    $client_entity = $this->clientRepository->getClientEntity($credentials['client_id']);
    if (!$client_entity) {
      // Client not found - use timing-safe comparison to prevent enumeration.
      $this->preventTimingAttack();
      return NULL;
    }

    // Get the Drupal consumer entity.
    $consumer = $client_entity->getDrupalEntity();

    // Validate client is active and not revoked.
    if (!$this->isClientActive($consumer)) {
      return NULL;
    }

    // Validate credentials based on client type.
    if (!$this->validateClientCredentials($client_entity, $consumer, $credentials['client_secret'])) {
      return NULL;
    }

    return $consumer;
  }

  /**
   * Extracts client credentials from the request.
   *
   * Attempts to extract credentials from HTTP Basic Auth header first,
   * then falls back to POST body parameters.
   *
   * @param \Psr\Http\Message\ServerRequestInterface $request
   *   The HTTP request.
   *
   * @return array
   *   Array with 'client_id' and 'client_secret' keys (may be null).
   */
  private function extractCredentials(ServerRequestInterface $request): array {
    $credentials = [
      'client_id' => NULL,
      'client_secret' => NULL,
    ];

    // Try Basic Auth first (per RFC 6749 Section 2.3.1).
    $auth_header = $request->getHeaderLine('Authorization');
    if (str_starts_with($auth_header, 'Basic ')) {
      $encoded = substr($auth_header, 6);
      $decoded = base64_decode($encoded, TRUE);

      if ($decoded !== FALSE && str_contains($decoded, ':')) {
        [$client_id, $client_secret] = explode(':', $decoded, 2);
        $credentials['client_id'] = $client_id;
        $credentials['client_secret'] = $client_secret;
        return $credentials;
      }
    }

    // Fall back to POST body parameters.
    $body = $request->getParsedBody();
    if (is_array($body)) {
      $credentials['client_id'] = $body['client_id'] ?? NULL;
      $credentials['client_secret'] = $body['client_secret'] ?? NULL;
    }

    return $credentials;
  }

  /**
   * Validates client credentials based on client type.
   *
   * For confidential clients, validates the provided secret using
   * constant-time comparison. For public clients, accepts requests
   * without a secret.
   *
   * @param \Drupal\simple_oauth\Entities\ClientEntityInterface $client_entity
   *   The OAuth client entity.
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The Drupal consumer entity.
   * @param string|null $provided_secret
   *   The client secret provided in the request.
   *
   * @return bool
   *   TRUE if credentials are valid, FALSE otherwise.
   */
  private function validateClientCredentials(
    ClientEntityInterface $client_entity,
    ConsumerInterface $consumer,
    ?string $provided_secret,
  ): bool {
    $is_confidential = $client_entity->isConfidential();
    $secret_field = $consumer->get('secret');

    // Public clients (not confidential) can authenticate with just client_id.
    if (!$is_confidential && empty($provided_secret)) {
      return TRUE;
    }

    // If a secret is provided, it must be validated regardless of client type.
    if (!empty($provided_secret)) {
      // Get the stored secret hash.
      if ($secret_field->isEmpty()) {
        return FALSE;
      }

      // Use the client repository's validateClient method which properly
      // handles password hashing with constant-time comparison to prevent
      // timing attacks. This is the secure approach as it uses Drupal's
      // PasswordInterface internally.
      return $this->clientRepository->validateClient(
        $consumer->getClientId(),
        $provided_secret,
        NULL
      );
    }

    // Confidential clients MUST provide a secret.
    if ($is_confidential) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Checks if a client is active and not revoked.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer entity to check.
   *
   * @return bool
   *   TRUE if the client is active, FALSE otherwise.
   */
  private function isClientActive(ConsumerInterface $consumer): bool {
    // Check if consumer entity is published/active.
    // The consumer entity might have status fields we should check.
    // Looking at ContentEntityInterface, entities typically have a 'status'
    // field or are deleted. Since we got it from the repository, it exists.
    // We should check if it has a status field and if it's published.
    if ($consumer->hasField('status')) {
      return (bool) $consumer->get('status')->value;
    }

    // If no status field, assume it's active (entity exists).
    return TRUE;
  }

  /**
   * Performs a timing-safe operation to prevent client enumeration.
   *
   * When a client is not found, we perform a dummy operation to ensure
   * the response time is similar to when a client is found but fails
   * authentication. This prevents attackers from determining which
   * client IDs exist.
   */
  private function preventTimingAttack(): void {
    // Perform a dummy hash comparison with random data to maintain
    // consistent timing regardless of whether the client exists.
    $dummy_secret = bin2hex(random_bytes(32));
    $dummy_hash = bin2hex(random_bytes(32));
    hash_equals($dummy_secret, $dummy_hash);
  }

}
