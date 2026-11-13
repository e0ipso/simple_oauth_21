<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\simple_oauth_server_metadata\Service\ClientAuthenticationService;
use Drupal\simple_oauth_server_metadata\Service\TokenRevocationService;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for OAuth 2.0 token revocation endpoint (RFC 7009).
 *
 * Handles POST requests to revoke OAuth access tokens and refresh tokens.
 * Implements RFC 7009 privacy considerations by returning success responses
 * even for non-existent tokens to prevent token enumeration attacks.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7009
 */
final class TokenRevocationController extends ControllerBase {

  /**
   * Constructs a TokenRevocationController object.
   *
   * @param \Drupal\simple_oauth_server_metadata\Service\ClientAuthenticationService $clientAuthentication
   *   The client authentication service.
   * @param \Drupal\simple_oauth_server_metadata\Service\TokenRevocationService $tokenRevocation
   *   The token revocation service.
   * @param \Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface $httpMessageFactory
   *   The HTTP message factory for PSR-7 conversion.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The authenticated request account. Without the optional collaborators,
   *   legacy constructors enforce client ownership and cannot grant bypass.
   * @param \Drupal\Core\Access\CsrfRequestHeaderAccessCheck|null $csrfRequestHeaderAccess
   *   The core session-aware CSRF header checker.
   */
  public function __construct(
    private readonly ClientAuthenticationService $clientAuthentication,
    private readonly TokenRevocationService $tokenRevocation,
    private readonly HttpMessageFactoryInterface $httpMessageFactory,
    private readonly LoggerInterface $logger,
    private readonly ?AccountInterface $account = NULL,
    private readonly ?CsrfRequestHeaderAccessCheck $csrfRequestHeaderAccess = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('simple_oauth_server_metadata.client_authentication'),
      $container->get('simple_oauth_server_metadata.token_revocation'),
      $container->get('psr7.http_message_factory'),
      $container->get('logger.channel.simple_oauth'),
      $container->get('current_user'),
      $container->get('access_check.header.csrf'),
    );
  }

  /**
   * Handles token revocation requests.
   *
   * Processes POST requests to revoke OAuth tokens. The endpoint requires
   * client authentication and a token parameter. Per RFC 7009, the endpoint
   * always returns HTTP 200 for privacy reasons, regardless of whether the
   * token existed or was successfully revoked.
   *
   * Session-derived ownership bypass also requires core CSRF header access.
   * Send the value retrieved from /session/token as X-CSRF-Token with the same
   * session cookie. Core owns token-key compatibility across supported core
   * versions. Denied bypass falls back to ownership with the privacy response.
   * Core exempts stateless requests from the session CSRF requirement; account
   * permissions remain those supplied by the active authentication provider.
   *
   * Request parameters (POST body):
   * - token (required): The token value to revoke
   * - token_type_hint (optional): "access_token" or "refresh_token"
   *
   * Response codes:
   * - 200 OK: Token revoked or doesn't exist (RFC 7009 privacy)
   * - 400 Bad Request: Missing required token parameter
   * - 401 Unauthorized: Client authentication failed
   * - 500 Internal Server Error: Unexpected server error
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The HTTP response.
   */
  public function revoke(Request $request): Response {
    try {
      // Convert Symfony request to PSR-7 for authentication service.
      $psrRequest = $this->httpMessageFactory->createRequest($request);

      // Authenticate the client using credentials from request.
      $client = $this->clientAuthentication->authenticateClient($psrRequest);
      if (!$client) {
        $this->logger->warning('Token revocation failed: client authentication failed');
        return new JsonResponse(
          ['error' => 'invalid_client'],
          Response::HTTP_UNAUTHORIZED
        );
      }

      // Extract required token parameter from POST body.
      $token = $request->request->get('token');
      if (empty($token)) {
        return new JsonResponse(
          [
            'error' => 'invalid_request',
            'error_description' => 'Missing token parameter',
          ],
          Response::HTTP_BAD_REQUEST
        );
      }

      // Extract optional token_type_hint parameter.
      // Per RFC 7009 Section 2.1, this parameter is optional and provides a
      // hint about the token type. Our implementation handles both access and
      // refresh tokens automatically, so this hint is not needed for
      // revocation logic.
      $tokenTypeHint = $request->request->get('token_type_hint');
      // Prevent unused variable warning - accepted per RFC 7009.
      unset($tokenTypeHint);

      // Core's session CSRF check gates permission-based ownership bypass.
      $bypassOwnership = $this->account !== NULL
        && $this->csrfRequestHeaderAccess !== NULL
        && $this->account->hasPermission('bypass token revocation restrictions')
        && $this->csrfRequestHeaderAccess->access($request, $this->account)->isAllowed();

      // Get the client ID for ownership validation.
      $clientId = $client->getClientId();

      // Revoke the token with ownership validation unless bypassed.
      $success = $this->tokenRevocation->revokeToken($token, $clientId, $bypassOwnership);

      // Log the revocation attempt for audit purposes.
      $this->logger->info('Token revocation request by client @client, bypass: @bypass, success: @success', [
        '@client' => $clientId,
        '@bypass' => $bypassOwnership ? 'yes' : 'no',
        '@success' => $success ? 'yes' : 'no',
      ]);

      // Per RFC 7009 Section 2.2, always return 200 for privacy.
      // This prevents token enumeration attacks by not revealing whether the
      // token existed or was successfully revoked.
      return new Response('', Response::HTTP_OK);
    }
    catch (\Exception $e) {
      // Log unexpected errors for debugging.
      $this->logger->error('Token revocation error: @message', [
        '@message' => $e->getMessage(),
      ]);

      // Return generic server error without revealing internal details.
      return new JsonResponse(
        ['error' => 'server_error'],
        Response::HTTP_INTERNAL_SERVER_ERROR
      );
    }
  }

}
