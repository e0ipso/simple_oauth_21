<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Service;

use Defuse\Crypto\Core;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\simple_oauth\Entity\Oauth2TokenInterface;
use Drupal\simple_oauth\Server\ResourceServerFactoryInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves authenticated issued tokens to their stored token entities.
 *
 * Access tokens use the upstream resource server's signature, lifetime and
 * revocation validation. Refresh tokens use the same authenticated decryption
 * and salt derivation as the upstream authorization server. Raw identifiers
 * are never accepted as token representations.
 */
final class TokenLookupService {

  /**
   * Constructs a token lookup service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\simple_oauth\Server\ResourceServerFactoryInterface $resourceServerFactory
   *   The upstream resource server factory.
   * @param \Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface $httpMessageFactory
   *   The PSR-7 request factory.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings containing the upstream encryption salt.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ResourceServerFactoryInterface $resourceServerFactory,
    private readonly HttpMessageFactoryInterface $httpMessageFactory,
    private readonly Settings $settings,
  ) {}

  /**
   * Resolves a verified access JWT or decrypted refresh token.
   *
   * Refresh token records do not necessarily contain a client or scopes. Those
   * values come from the authenticated encrypted payload in that case.
   *
   * @param string $token_value
   *   The token representation issued to the client.
   *
   * @return array{token: \Drupal\simple_oauth\Entity\Oauth2TokenInterface, client_id: string, scope: ?string}|null
   *   The token and authenticated metadata, or NULL for an invalid token.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Defuse\Crypto\Exception\EnvironmentIsBrokenException
   */
  public function resolveToken(string $token_value): ?array {
    $scope = NULL;
    if (substr_count($token_value, '.') === 2) {
      $request = Request::create('/');
      $request->headers->set('Authorization', 'Bearer ' . $token_value);
      try {
        $validated = $this->resourceServerFactory->get()->validateAuthenticatedRequest(
          $this->httpMessageFactory->createRequest($request)
        );
      }
      catch (OAuthServerException) {
        return NULL;
      }
      $identifier = $validated->getAttribute('oauth_access_token_id');
      $client_id = $validated->getAttribute('oauth_client_id');
      $bundle = 'access_token';
    }
    else {
      try {
        $payload = json_decode(Crypto::decryptWithPassword(
          $token_value,
          Core::ourSubstr($this->settings::getHashSalt(), 0, 32)
        ), TRUE, 512, JSON_THROW_ON_ERROR);
      }
      catch (WrongKeyOrModifiedCiphertextException | \JsonException) {
        return NULL;
      }
      if (!is_array($payload)
        || !isset($payload['expire_time'], $payload['scopes'])
        || !is_int($payload['expire_time'])
        || $payload['expire_time'] <= time()
        || !is_array($payload['scopes'])
      ) {
        return NULL;
      }
      foreach ($payload['scopes'] as $name) {
        if (!is_string($name)) {
          return NULL;
        }
      }
      $identifier = $payload['refresh_token_id'] ?? NULL;
      $client_id = $payload['client_id'] ?? NULL;
      $scope = implode(' ', $payload['scopes']);
      $bundle = 'refresh_token';
    }

    if (!is_string($identifier) || $identifier === ''
      || !is_string($client_id) || $client_id === ''
    ) {
      return NULL;
    }

    $tokens = $this->entityTypeManager->getStorage('oauth2_token')->loadByProperties([
      'value' => $identifier,
      'bundle' => $bundle,
    ]);
    $token = reset($tokens);
    if (!$token instanceof Oauth2TokenInterface || $token->isRevoked()
      || (int) $token->get('expire')->value <= time()
    ) {
      return NULL;
    }

    // Cross-check stored ownership when upstream persisted a client reference.
    $client_reference = $token->get('client')->first();
    $client = $client_reference?->get('entity')->getValue();
    if (($client !== NULL && (!$client instanceof ConsumerInterface || $client->getClientId() !== $client_id))
      || ($client === NULL && $bundle === 'access_token')
    ) {
      return NULL;
    }

    return ['token' => $token, 'client_id' => $client_id, 'scope' => $scope];
  }

}
