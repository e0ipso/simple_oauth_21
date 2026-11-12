<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_pkce\EventSubscriber;

use Drupal\simple_oauth_pkce\Service\PkceSettingsService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies global PKCE policy before authorization-code request processing.
 *
 * League retains responsibility for challenge syntax, verifier matching and
 * per-client requirements, including when global enforcement is disabled.
 */
final class PkcePolicySubscriber implements EventSubscriberInterface {

  /**
   * The global PKCE settings.
   *
   * @var \Drupal\simple_oauth_pkce\Service\PkceSettingsService
   */
  private readonly PkceSettingsService $settings;

  /**
   * Constructs the policy subscriber.
   *
   * @param \Drupal\simple_oauth_pkce\Service\PkceSettingsService $settings
   *   The global PKCE settings.
   */
  public function __construct(PkceSettingsService $settings) {
    $this->settings = $settings;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after routing, before the authorization and token controllers.
    return [KernelEvents::REQUEST => ['onRequest', 28]];
  }

  /**
   * Enforces policy on the authorization and token routes for all clients.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    if ($this->settings->isDisabled()) {
      return;
    }

    $request = $event->getRequest();
    $route = $request->attributes->get('_route');
    $error = NULL;

    if ($route === 'oauth2_token.authorize' && $request->query->get('response_type') === 'code') {
      $parameters = $request->query->all();
      $challenge = $parameters['code_challenge'] ?? NULL;
      if ($challenge !== NULL && !is_string($challenge)) {
        $error = 'The code_challenge parameter must be a string.';
      }
      elseif ($challenge === NULL || trim($challenge) === '') {
        if ($this->settings->isMandatory()) {
          $error = 'The code_challenge parameter is required.';
        }
      }
      else {
        // RFC 7636 and League default an omitted method to plain.
        $method = $parameters['code_challenge_method'] ?? 'plain';
        if (!in_array($method, $this->settings->getSupportedMethods(), TRUE)) {
          $error = 'The code_challenge_method is not enabled.';
        }
      }
    }
    elseif ($route === 'oauth2_token.token' && $request->request->get('grant_type') === 'authorization_code') {
      $parameters = $request->request->all();
      $verifier = $parameters['code_verifier'] ?? NULL;
      if ($verifier !== NULL && !is_string($verifier)) {
        $error = 'The code_verifier parameter must be a string.';
      }
      elseif (($verifier === NULL || trim($verifier) === '') && $this->settings->isMandatory()) {
        $error = 'The code_verifier parameter is required.';
      }
    }

    if ($error !== NULL) {
      $event->setResponse(new JsonResponse([
        'error' => 'invalid_request',
        'error_description' => $error,
      ], 400, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']));
    }
  }

}
