<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Service;

/**
 * Service for revoking OAuth tokens.
 *
 * Provides token lookup, ownership validation, and revocation operations
 * for both access tokens and refresh tokens. Implements RFC 7009 privacy
 * considerations by returning success even for non-existent tokens.
 */
final class TokenRevocationService {

  /**
   * Constructs a TokenRevocationService.
   *
   * @param \Drupal\simple_oauth_server_metadata\Service\TokenLookupService $tokenLookup
   *   The validated token lookup service.
   */
  public function __construct(
    private readonly TokenLookupService $tokenLookup,
  ) {}

  /**
   * Revokes a token if valid and owned by the specified client.
   *
   * This method implements RFC 7009 token revocation. Per the specification,
   * the endpoint returns success even if the token doesn't exist or is
   * invalid to prevent token enumeration attacks.
   *
   * @param string $tokenValue
   *   The token value to revoke.
   * @param string $clientId
   *   The client ID that owns the token.
   * @param bool $bypassOwnership
   *   If TRUE, skip ownership validation (for admin bypass permission).
   *
   * @return bool
   *   TRUE if token was revoked or doesn't exist (RFC 7009 privacy),
   *   FALSE if ownership validation fails.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function revokeToken(string $tokenValue, string $clientId, bool $bypassOwnership = FALSE): bool {
    if (empty($tokenValue)) {
      return TRUE;
    }

    $resolved = $this->tokenLookup->resolveToken($tokenValue);
    $token = $resolved['token'] ?? NULL;

    if ($token === NULL) {
      return TRUE;
    }

    if (!$bypassOwnership && $resolved['client_id'] !== $clientId) {
      return FALSE;
    }

    if ($token->isRevoked()) {
      return TRUE;
    }

    $token->revoke();
    $token->save();

    return TRUE;
  }

}
