<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Service;

use Drupal\Core\Database\Connection;
use Drupal\Component\Utility\Crypt;
use Psr\Log\LoggerInterface;

/**
 * Service for managing registration access tokens.
 *
 * Handles generation, validation, and storage of RFC 7591 registration access
 * tokens.
 */
final class RegistrationTokenService {

  /**
   * Constructor.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
  ) {}

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
    $this->database->insert('simple_oauth_client_registration_tokens')
      ->fields([
        'client_id' => $client_id,
        'token_hash' => $token_hash,
        'created' => time(),
    // 1 year expiration
        'expires' => time() + (365 * 24 * 60 * 60),
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
      if ($stored_hash['expires'] < time()) {
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
   * Cleans up expired registration tokens.
   */
  public function cleanupExpiredTokens(): void {
    $deleted = $this->database->delete('simple_oauth_client_registration_tokens')
      ->condition('expires', time(), '<')
      ->execute();

    if ($deleted > 0) {
      $this->logger->info('Cleaned up @count expired registration access tokens', [
        '@count' => $deleted,
      ]);
    }
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
