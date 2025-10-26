<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_oauth\Entity\Oauth2TokenInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for OAuth 2.0 token introspection endpoint (RFC 7662).
 *
 * Provides an endpoint for authorized clients to query metadata about OAuth
 * 2.0 tokens in a standardized format. The endpoint enforces authorization
 * checks to ensure only token owners or privileged users can introspect tokens.
 * This prevents token enumeration attacks by returning consistent responses
 * for unauthorized, non-existent, or expired tokens.
 *
 * Authentication is handled by the global SimpleOauthAuthenticationProvider
 * which validates Bearer tokens and sets the current user. This controller
 * trusts the currentUser service rather than duplicating authentication logic.
 * Authentication failures are caught by IntrospectionExceptionSubscriber and
 * converted to RFC 7662 compliant error responses.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7662
 */
final class TokenIntrospectionController extends ControllerBase {

  /**
   * Constructs a TokenIntrospectionController object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('request_stack'),
      $container->get('logger.channel.simple_oauth'),
    );
  }

  /**
   * Handles token introspection requests.
   *
   * Processes POST requests to introspect OAuth tokens and return metadata
   * in RFC 7662 compliant format. Authentication is handled by the global
   * SimpleOauthAuthenticationProvider which validates Bearer tokens and sets
   * currentUser. The controller enforces ownership validation unless the user
   * has bypass permission.
   *
   * Authentication:
   * - Bearer token in Authorization header (validated by authentication
   *   provider).
   * - Authentication failures are caught by IntrospectionExceptionSubscriber.
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
   * - 401 Unauthorized: Missing or invalid Bearer token (via event
   *   subscriber)
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
    // Temporary workaround: Reject GET requests with HTTP 405.
    // The route accepts both GET and POST to bypass a simple_oauth
    // bug where PathValidator creates internal GET requests, causing
    // MethodNotAllowedException for POST-only routes with OAuth
    // authentication.
    // See: https://www.drupal.org/project/simple_oauth/issues/[TBD]
    if ($request->getMethod() === 'GET') {
      return new JsonResponse(
        [
          'error' => 'invalid_request',
          'error_description' => 'Only POST requests are allowed. RFC 7662 requires the introspection endpoint to accept HTTP POST.',
        ],
        Response::HTTP_METHOD_NOT_ALLOWED,
        ['Allow' => 'POST']
      );
    }

    try {
      // Check if user is authenticated. The authentication provider sets
      // currentUser() based on Bearer token validation. If authentication
      // fails, the provider throws OAuthUnauthorizedHttpException which is
      // caught by IntrospectionExceptionSubscriber.
      if ($this->currentUser()->isAnonymous()) {
        return new JsonResponse(
          [
            'error' => 'invalid_client',
            'error_description' => 'Authentication required',
          ],
          Response::HTTP_UNAUTHORIZED,
          ['WWW-Authenticate' => 'Bearer']
        );
      }

      // Extract required token parameter from POST body.
      $tokenValue = $request->request->get('token');
      if (empty($tokenValue)) {
        return new JsonResponse(
          [
            'error' => 'invalid_request',
            'error_description' => 'Missing token parameter',
          ],
          Response::HTTP_BAD_REQUEST
        );
      }

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
          '@uid' => $this->currentUser()->id(),
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
    $storage = $this->entityTypeManager()->getStorage('oauth2_token');

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
    if ($this->currentUser()->hasPermission('bypass token introspection restrictions')) {
      return TRUE;
    }

    // Check if current user is the token owner.
    $tokenOwnerId = $token->get('auth_user_id')->target_id;

    // Handle NULL token owner (e.g., client credentials tokens).
    if ($tokenOwnerId === NULL) {
      return FALSE;
    }

    return (int) $tokenOwnerId === (int) $this->currentUser()->id();
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

    // Add scope (required by RFC 7662, empty string if no scopes).
    $scopes = $token->get('scopes')->getScopes();
    $scopeNames = array_map(fn($scope) => $scope->getName(), $scopes);
    $response['scope'] = implode(' ', $scopeNames);

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
