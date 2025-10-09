<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\simple_oauth\Entity\Oauth2TokenInterface;

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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
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

    $token = $this->findToken($tokenValue);

    if (!$token) {
      return TRUE;
    }

    if (!$bypassOwnership && !$this->validateOwnership($token, $clientId)) {
      return FALSE;
    }

    if ($token->isRevoked()) {
      return TRUE;
    }

    $token->revoke();
    $token->save();

    return TRUE;
  }

  /**
   * Finds a token by its value.
   *
   * Searches both access tokens and refresh tokens (same entity type,
   * different bundles).
   *
   * @param string $tokenValue
   *   The token value to search for.
   *
   * @return \Drupal\simple_oauth\Entity\Oauth2TokenInterface|null
   *   The token entity if found, NULL otherwise.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function findToken(string $tokenValue): ?Oauth2TokenInterface {
    $storage = $this->entityTypeManager->getStorage('oauth2_token');

    $tokens = $storage->loadByProperties(['value' => $tokenValue]);

    if (empty($tokens)) {
      return NULL;
    }

    return reset($tokens);
  }

  /**
   * Validates that the token belongs to the specified client.
   *
   * @param \Drupal\simple_oauth\Entity\Oauth2TokenInterface $token
   *   The token entity.
   * @param string $clientId
   *   The client ID to validate against.
   *
   * @return bool
   *   TRUE if the token belongs to the client, FALSE otherwise.
   */
  private function validateOwnership(Oauth2TokenInterface $token, string $clientId): bool {
    $client = $token->get('client')->entity;

    if (!$client) {
      return FALSE;
    }

    return $client->getClientId() === $clientId;
  }

}
