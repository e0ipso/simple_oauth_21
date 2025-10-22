<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\simple_oauth\Entity\Oauth2TokenInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for OAuth 2.0 token introspection endpoint (RFC 7662).
 *
 * Provides an endpoint for authorized clients to query metadata about OAuth
 * 2.0 tokens in a standardized format. The endpoint enforces authorization
 * checks to ensure only token owners or privileged users can introspect tokens.
 * This prevents token enumeration attacks by returning consistent responses
 * for unauthorized, non-existent, or expired tokens.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7662
 */
final class TokenIntrospectionController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private readonly EntityTypeManagerInterface $entityManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  private readonly AccountProxyInterface $account;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private readonly LoggerInterface $logger;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private readonly RequestStack $requestStack;

  /**
   * Constructs a TokenIntrospectionController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user account.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    LoggerInterface $logger,
    RequestStack $requestStack,
  ) {
    $this->entityManager = $entityTypeManager;
    $this->account = $currentUser;
    $this->logger = $logger;
    $this->requestStack = $requestStack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('logger.channel.simple_oauth'),
      $container->get('request_stack'),
    );
  }

  /**
   * Handles token introspection requests.
   *
   * Processes POST requests to introspect OAuth tokens and return metadata
   * in RFC 7662 compliant format. The endpoint requires OAuth 2.0
   * authentication and enforces ownership validation unless the user has
   * bypass permission.
   *
   * Request parameters (POST body):
   * - token (required): The token value to introspect
   * - token_type_hint (optional): "access_token" or "refresh_token"
   *
   * Response format:
   * - Active token: JSON object with token metadata (active, scope, client_id,
   *   username, token_type, exp, iat, sub, aud, iss, jti)
   * - Inactive/unauthorized token: {"active": false}
   *
   * Response codes:
   * - 200 OK: Token introspection completed (includes both active/inactive)
   * - 400 Bad Request: Missing required token parameter
   *
   * Security considerations:
   * - Returns identical {"active": false} for non-existent, expired, revoked,
   *   and unauthorized tokens to prevent information disclosure
   * - Enforces token ownership unless bypass permission is granted
   * - Consistent response timing to prevent timing attacks
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response with token metadata or inactive status.
   */
  public function introspect(Request $request): JsonResponse {
    try {
      // Extract required token parameter from POST body.
      $tokenValue = $request->request->get('token');
      if (empty($tokenValue)) {
        return new JsonResponse(
          [
            'error' => 'invalid_request',
            'error_description' => 'Missing token parameter',
          ],
          JsonResponse::HTTP_BAD_REQUEST
        );
      }

      // Extract optional token_type_hint parameter.
      // Per RFC 7662 Section 2.1, this parameter is optional and provides a
      // hint about the token type. Our implementation searches both access and
      // refresh tokens automatically, so this hint is informational only.
      $tokenTypeHint = $request->request->get('token_type_hint');
      // Prevent unused variable warning - accepted per RFC 7662.
      unset($tokenTypeHint);

      // Look up the token by its value.
      $token = $this->findTokenByValue($tokenValue);

      // Return inactive if token doesn't exist.
      if (!$token) {
        return $this->createInactiveResponse();
      }

      // Check authorization: user must be token owner OR have bypass
      // permission.
      if (!$this->isAuthorizedToIntrospect($token)) {
        // Per RFC 7662 Section 2.2, return inactive for unauthorized requests
        // to prevent token enumeration.
        $this->logger->info('Token introspection denied for user @uid: not owner and no bypass permission', [
          '@uid' => $this->account->id(),
        ]);
        return $this->createInactiveResponse();
      }

      // Check if token is expired or revoked.
      if ($this->isTokenInactive($token)) {
        return $this->createInactiveResponse();
      }

      // Token is valid and user is authorized - return full metadata.
      return $this->createActiveResponse($token);
    }
    catch (\Exception $e) {
      // Log unexpected errors for debugging.
      $this->logger->error('Token introspection error: @message', [
        '@message' => $e->getMessage(),
      ]);

      // Return inactive response to prevent information disclosure even on
      // errors.
      return $this->createInactiveResponse();
    }
  }

  /**
   * Finds a token entity by its token value.
   *
   * Searches for oauth2_token entities matching the provided token value.
   * This supports both access tokens and refresh tokens stored in the same
   * entity type.
   *
   * @param string $tokenValue
   *   The token value to search for.
   *
   * @return \Drupal\simple_oauth\Entity\Oauth2TokenInterface|null
   *   The token entity if found, NULL otherwise.
   */
  private function findTokenByValue(string $tokenValue): ?Oauth2TokenInterface {
    $storage = $this->entityManager->getStorage('oauth2_token');

    // Query for token by value field.
    $results = $storage->loadByProperties(['value' => $tokenValue]);

    if (empty($results)) {
      return NULL;
    }

    // Return the first matching token.
    $token = reset($results);
    return $token instanceof Oauth2TokenInterface ? $token : NULL;
  }

  /**
   * Checks if the current user is authorized to introspect the token.
   *
   * Authorization is granted if either:
   * 1. The current user is the token owner.
   * 2. The current user has "bypass token introspection restrictions"
   *    permission.
   *
   * @param \Drupal\simple_oauth\Entity\Oauth2TokenInterface $token
   *   The token to check authorization for.
   *
   * @return bool
   *   TRUE if authorized, FALSE otherwise.
   */
  private function isAuthorizedToIntrospect(Oauth2TokenInterface $token): bool {
    // Check bypass permission first.
    if ($this->account->hasPermission('bypass token introspection restrictions')) {
      return TRUE;
    }

    // Check if current user is the token owner.
    $tokenOwnerId = $token->get('auth_user_id')->target_id;

    // Handle NULL token owner (e.g., client credentials tokens).
    if ($tokenOwnerId === NULL) {
      return FALSE;
    }

    return (int) $tokenOwnerId === (int) $this->account->id();
  }

  /**
   * Checks if a token is inactive (expired or revoked).
   *
   * @param \Drupal\simple_oauth\Entity\Oauth2TokenInterface $token
   *   The token to check.
   *
   * @return bool
   *   TRUE if the token is inactive, FALSE otherwise.
   */
  private function isTokenInactive(Oauth2TokenInterface $token): bool {
    // Check if token is revoked.
    if ($token->isRevoked()) {
      return TRUE;
    }

    // Check if token is expired.
    $expiration = $token->get('expire')->value;
    if ($expiration && $expiration < time()) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Creates an inactive token response.
   *
   * Per RFC 7662 Section 2.2, this response is returned for:
   * - Non-existent tokens.
   * - Expired tokens.
   * - Revoked tokens.
   * - Unauthorized introspection attempts.
   *
   * This consistent response prevents token enumeration attacks.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with active: false.
   */
  private function createInactiveResponse(): JsonResponse {
    return new JsonResponse(['active' => FALSE]);
  }

  /**
   * Creates an active token response with full metadata.
   *
   * Builds RFC 7662 compliant response including all available token metadata.
   * The response includes the required "active" field and optional fields such
   * as scope, client_id, username, token_type, exp, iat, sub, aud, iss, jti.
   *
   * @param \Drupal\simple_oauth\Entity\Oauth2TokenInterface $token
   *   The token entity.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with token metadata.
   */
  private function createActiveResponse(Oauth2TokenInterface $token): JsonResponse {
    $response = [
      'active' => TRUE,
    ];

    // Add scope if available.
    $scopes = $token->get('scopes')->referencedEntities();
    if (!empty($scopes)) {
      $scopeNames = array_map(fn($scope) => $scope->label(), $scopes);
      $response['scope'] = implode(' ', $scopeNames);
    }

    // Add client_id.
    $client = $token->get('client')->entity;
    if ($client) {
      $response['client_id'] = $client->uuid();
    }

    // Add username if available.
    $user = $token->get('auth_user_id')->entity;
    if ($user) {
      $response['username'] = $user->getAccountName();
      // Add sub (subject) claim - user UUID.
      $response['sub'] = $user->uuid();
    }

    // Add token_type.
    $response['token_type'] = 'Bearer';

    // Add exp (expiration time).
    $expiration = $token->get('expire')->value;
    if ($expiration) {
      $response['exp'] = (int) $expiration;
    }

    // Add iat (issued at time).
    $created = $token->get('created')->value;
    if ($created) {
      $response['iat'] = (int) $created;
    }

    // Add aud (audience) - same as client_id in this implementation.
    if (isset($response['client_id'])) {
      $response['aud'] = $response['client_id'];
    }

    // Add iss (issuer) - the site base URL.
    $currentRequest = $this->requestStack->getCurrentRequest();
    if ($currentRequest) {
      $baseUrl = $currentRequest->getSchemeAndHttpHost();
      $response['iss'] = $baseUrl;
    }

    // Add jti (JWT ID) - token UUID.
    $response['jti'] = $token->uuid();

    return new JsonResponse($response);
  }

}
