<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_oauth_client_registration\Service\ClientRegistrationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Controller for OAuth 2.0 Client Registration endpoints.
 *
 * Implements RFC 7591 Dynamic Client Registration Protocol.
 */
final class ClientRegistrationController extends ControllerBase {

  /**
   * Constructor.
   */
  public function __construct(
    private readonly ClientRegistrationService $registrationService,
    private readonly LoggerInterface $logger,
    private readonly SerializerInterface $serializer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('simple_oauth_client_registration.service.registration'),
      $container->get('logger.channel.simple_oauth_client_registration'),
      $container->get('serializer'),
    );
  }

  /**
   * Handles OAuth 2.0 client registration requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The registration response.
   */
  public function register(Request $request): JsonResponse {
    // Placeholder implementation - will be implemented in subsequent tasks.
    return new JsonResponse([
      'error' => 'not_implemented',
      'error_description' => 'Client registration endpoint not yet implemented',
    ], 501);
  }

}
