<?php

namespace Drupal\simple_oauth_native_apps\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Handles exceptions from native app OAuth grant processing.
 *
 * Converts internal exceptions to generic OAuth error responses to avoid
 * disclosing configuration details to potential attackers.
 */
class NativeAppExceptionSubscriber implements EventSubscriberInterface {

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a NativeAppExceptionSubscriber.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger instance.
   */
  public function __construct(LoggerInterface $logger) {
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // High priority to catch exceptions before default handlers.
    return [
      KernelEvents::EXCEPTION => ['onException', 100],
    ];
  }

  /**
   * Handles exceptions from native app authorization code grant.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The exception event.
   */
  public function onException(ExceptionEvent $event): void {
    $exception = $event->getThrowable();
    $request = $event->getRequest();

    // Only handle exceptions from OAuth authorization requests.
    if (!$this->isOAuthAuthorizationRequest($request)) {
      return;
    }

    // Handle InvalidArgumentException from NativeAppAuthorizationCode plugin.
    if ($exception instanceof \InvalidArgumentException) {
      // Check if this is the native app PKCE error.
      if (strpos($exception->getMessage(), 'Native app authorization code grant') !== FALSE) {
        $this->handleNativeAppGrantException($event, $exception);
      }
    }
  }

  /**
   * Checks if the request is an OAuth authorization request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return bool
   *   TRUE if this is an OAuth authorization request.
   */
  protected function isOAuthAuthorizationRequest($request): bool {
    $path = $request->getPathInfo();

    // Check if this is the OAuth authorization endpoint.
    if (strpos($path, '/oauth/authorize') !== FALSE) {
      return TRUE;
    }

    // Also check token endpoint for grant type errors.
    if (strpos($path, '/oauth/token') !== FALSE) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Handles native app grant exceptions.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The exception event.
   * @param \InvalidArgumentException $exception
   *   The original exception.
   */
  protected function handleNativeAppGrantException(ExceptionEvent $event, \InvalidArgumentException $exception): void {
    $request = $event->getRequest();

    // Log the detailed error for administrators.
    $this->logger->error('Native app authorization grant error: @message', [
      '@message' => $exception->getMessage(),
      'client_id' => $request->get('client_id'),
      'grant_type' => $request->get('grant_type'),
      'response_type' => $request->get('response_type'),
      'request_uri' => $request->getRequestUri(),
      'exception' => $exception,
    ]);

    // Return generic OAuth error response without disclosing configuration.
    $errorResponse = [
      'error' => 'invalid_request',
      'error_description' => 'The request is invalid.',
    ];

    $response = new JsonResponse($errorResponse, 400, [
      'Content-Type' => 'application/json',
      'Cache-Control' => 'no-store',
      'Pragma' => 'no-cache',
    ]);

    $event->setResponse($response);
  }

}