<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\EventSubscriber;

use Drupal\simple_oauth\Exception\OAuthUnauthorizedHttpException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber for handling authentication exceptions in introspection.
 *
 * Intercepts OAuthUnauthorizedHttpException thrown by the global
 * SimpleOauthAuthenticationProvider when Bearer token validation fails on
 * the token introspection endpoint. Transforms these exceptions into RFC
 * 7662 compliant error responses with appropriate HTTP 401 status codes and
 * WWW-Authenticate headers.
 *
 * Per RFC 7662 Section 2.3, authentication failures must return:
 * - HTTP 401 Unauthorized status.
 * - WWW-Authenticate header indicating Bearer authentication scheme.
 * - Optional JSON error response with error and error_description fields.
 *
 * This subscriber only handles exceptions for the /oauth/introspect path to
 * ensure endpoint-specific error formatting without affecting other OAuth
 * endpoints.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7662#section-2.3
 */
final class IntrospectionExceptionSubscriber implements EventSubscriberInterface {

  /**
   * Constructs an IntrospectionExceptionSubscriber.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service for recording authentication failures.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority 50 ensures this runs after authentication but before generic
    // error handlers. This allows us to catch OAuth exceptions specifically
    // for the introspection endpoint while letting other exceptions pass
    // through to appropriate handlers.
    return [
      KernelEvents::EXCEPTION => ['onException', 50],
    ];
  }

  /**
   * Handles exceptions during token introspection requests.
   *
   * Intercepts OAuthUnauthorizedHttpException on the introspection endpoint
   * and converts them to RFC 7662 compliant 401 responses with proper
   * WWW-Authenticate headers. All other exceptions and paths are ignored,
   * allowing normal exception handling to proceed.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The exception event containing the thrown exception and request context.
   */
  public function onException(ExceptionEvent $event): void {
    $exception = $event->getThrowable();
    $request = $event->getRequest();

    // Only handle OAuthUnauthorizedHttpException for introspection endpoint.
    if (!$exception instanceof OAuthUnauthorizedHttpException) {
      return;
    }

    // Check if this is the token introspection endpoint.
    $path = $request->getPathInfo();
    if ($path !== '/oauth/introspect') {
      return;
    }

    // Log the authentication failure for security monitoring.
    $this->logger->warning('Token introspection authentication failed: @message', [
      '@message' => $exception->getMessage(),
      'client_ip' => $request->getClientIp(),
      'request_uri' => $request->getRequestUri(),
    ]);

    // Create RFC 7662 compliant error response.
    $errorResponse = [
      'error' => 'invalid_client',
      'error_description' => 'Bearer token authentication failed',
    ];

    // Build response with proper headers per RFC 7662 Section 2.3.
    $response = new JsonResponse(
      $errorResponse,
      Response::HTTP_UNAUTHORIZED,
      [
        // WWW-Authenticate header required by RFC 7662 for 401 responses.
        'WWW-Authenticate' => 'Bearer error="invalid_token"',
        'Content-Type' => 'application/json',
        // Prevent caching of error responses per OAuth security best
        // practices.
        'Cache-Control' => 'no-store',
        'Pragma' => 'no-cache',
      ]
    );

    // Set response to stop exception propagation.
    $event->setResponse($response);
  }

}
