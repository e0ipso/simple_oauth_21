<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Url;
use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;
use Psr\Log\LoggerInterface;

/**
 * Service for managing registration access tokens.
 *
 * Handles generation, validation, and storage of RFC 7591 registration access
 * tokens.
 */
final class RegistrationTokenService {

  /**
   * Constructs the registration token service.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The credential storage connection.
   * @param \Psr\Log\LoggerInterface $logger
   *   The registration logger.
   * @param \Drupal\Component\Datetime\TimeInterface|null $time
   *   The clock, or system time for legacy two-argument callers.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
    private readonly ?TimeInterface $time = NULL,
  ) {}

  /**
   * Starts an atomic lifecycle operation on the token storage connection.
   *
   * @return \Drupal\Core\Database\Transaction
   *   The transaction, committed when the caller releases it.
   */
  public function startTransaction(): Transaction {
    return $this->database->startTransaction();
  }

  /**
   * Prepares first-issuance credentials for an explicit registration response.
   *
   * This is a lifecycle operation, including for the legacy normalizer context.
   * Repeated projection must reuse the returned DTO. Stored hashes cannot be
   * recovered, and existing records are never rotated or removed here.
   *
   * @param string $client_id
   *   The client identifier.
   * @param string|null $client_secret
   *   An optional newly issued secret, never read from storage.
   *
   * @return \Drupal\simple_oauth_client_registration\Dto\ClientRegistration
   *   The prepared credential envelope, suitable for repeatable projection.
   *
   * @throws \LogicException
   *   If a registration record already exists. Supply its original prepared
   *   credentials instead; its plaintext token cannot be recovered.
   * @throws \Throwable
   *   If preparation fails; any token insertion is rolled back.
   */
  public function prepareRegistrationResponse(string $client_id, ?string $client_secret = NULL): ClientRegistration {
    if ($this->hasRegistrationAccessToken($client_id)) {
      throw new \LogicException('Registration credentials already exist; supply the original prepared response');
    }

    $transaction = $this->startTransaction();
    try {
      $token = $this->generateRegistrationAccessToken($client_id);
      $uri = Url::fromRoute('simple_oauth_client_registration.manage', [
        'client_id' => $client_id,
      ], ['absolute' => TRUE])->toString();
      return (new ClientRegistration())->withResponseFields($client_id, $client_secret, $token, $uri, $this->currentTime());
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Generates a registration access token for a client.
   *
   * @param string $client_id
   *   The client identifier.
   *
   * @return string
   *   The registration access token.
   */
  public function generateRegistrationAccessToken(string $client_id): string {
    // Generate a cryptographically secure token.
    $token = $this->generateSecureToken();

    // Hash the token for storage (similar to password hashing)
    $token_hash = Crypt::hashBase64($token);

    // Store the token in the database.
    $now = $this->currentTime();
    $this->database->insert('simple_oauth_client_registration_tokens')
      ->fields([
        'client_id' => $client_id,
        'token_hash' => $token_hash,
        'created' => $now,
    // 1 year expiration
        'expires' => $now + (365 * 24 * 60 * 60),
      ])
      ->execute();

    $this->logger->info('Registration access token generated for client: @client_id', [
      '@client_id' => $client_id,
    ]);

    return $token;
  }

  /**
   * Validates a registration access token.
   *
   * @param string $client_id
   *   The client identifier.
   * @param string $token
   *   The registration access token to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  public function validateRegistrationAccessToken(string $client_id, string $token): bool {
    try {
      // Get stored token hash for the client.
      $stored_hash = $this->database->select('simple_oauth_client_registration_tokens', 't')
        ->fields('t', ['token_hash', 'expires'])
        ->condition('client_id', $client_id)
        ->execute()
        ->fetchAssoc();

      if (!$stored_hash) {
        return FALSE;
      }

      // Check if token has expired.
      if ($stored_hash['expires'] < $this->currentTime()) {
        $this->revokeRegistrationAccessToken($client_id);
        return FALSE;
      }

      // Verify token hash.
      $provided_hash = Crypt::hashBase64($token);
      $is_valid = hash_equals($stored_hash['token_hash'], $provided_hash);

      if (!$is_valid) {
        $this->logger->warning('Invalid registration access token attempt for client: @client_id', [
          '@client_id' => $client_id,
        ]);
      }

      return $is_valid;
    }
    catch (\Exception $e) {
      $this->logger->error('Error validating registration access token: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Checks whether a client has a registration-management record.
   *
   * @param string $client_id
   *   The client identifier.
   *
   * @return bool
   *   TRUE if the client was dynamically registered.
   */
  public function hasRegistrationAccessToken(string $client_id): bool {
    return (bool) $this->database->select('simple_oauth_client_registration_tokens', 't')
      ->condition('client_id', $client_id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Revokes a registration access token.
   *
   * @param string $client_id
   *   The client identifier.
   */
  public function revokeRegistrationAccessToken(string $client_id): void {
    $this->database->delete('simple_oauth_client_registration_tokens')
      ->condition('client_id', $client_id)
      ->execute();

    $this->logger->info('Registration access token revoked for client: @client_id', [
      '@client_id' => $client_id,
    ]);
  }

  /**
   * Revokes credentials when consumer ownership of an identifier changes.
   *
   * Missing storage is valid during installation, kernel setup and uninstall.
   * Other storage errors propagate to the enclosing entity transaction.
   *
   * @param string ...$client_ids
   *   Identifiers whose credentials must no longer authorize management.
   */
  public function revokeLifecycleCredentials(string ...$client_ids): void {
    if (!$this->database->schema()->tableExists('simple_oauth_client_registration_tokens')) {
      return;
    }
    foreach (array_unique($client_ids) as $client_id) {
      $this->revokeRegistrationAccessToken($client_id);
    }
  }

  /**
   * Cleans up expired registration tokens.
   *
   * @param int|null $limit
   *   The maximum candidates, or NULL for the existing full manual cleanup.
   *
   * @throws \InvalidArgumentException
   *   If the supplied limit is not positive.
   */
  public function cleanupExpiredTokens(?int $limit = NULL): void {
    if ($limit !== NULL && $limit < 1) {
      throw new \InvalidArgumentException('The cleanup limit must be positive');
    }
    if (!$this->database->schema()->tableExists('simple_oauth_client_registration_tokens')) {
      return;
    }
    $query = $this->database->select('simple_oauth_client_registration_tokens', 't')
      ->fields('t', ['id', 'token_hash', 'expires'])
      ->condition('expires', $this->currentTime(), '<')
      ->orderBy('expires')
      ->orderBy('id');
    if ($limit !== NULL) {
      $query->range(0, $limit);
    }
    $deleted = 0;
    foreach ($query->execute()->fetchAll() as $candidate) {
      // Match the snapshot so renewal or replacement after selection survives.
      $deleted += $this->database->delete('simple_oauth_client_registration_tokens')
        ->condition('id', $candidate->id)
        ->condition('token_hash', $candidate->token_hash)
        ->condition('expires', $candidate->expires)
        ->execute();
    }
    if ($deleted > 0) {
      $this->logger->info('Cleaned up @count expired registration access tokens', [
        '@count' => $deleted,
      ]);
    }
  }

  /**
   * Gets current time while preserving legacy constructor calls.
   *
   * @return int
   *   The current Unix timestamp.
   */
  private function currentTime(): int {
    return $this->time?->getCurrentTime() ?? time();
  }

  /**
   * Generates a cryptographically secure token.
   *
   * @return string
   *   The secure token.
   */
  private function generateSecureToken(): string {
    // Generate 32 bytes (256 bits) of random data.
    $random_bytes = random_bytes(32);

    // Encode as base64url (RFC 4648 Section 5) for URL safety.
    return rtrim(strtr(base64_encode($random_bytes), '+/', '-_'), '=');
  }

}
