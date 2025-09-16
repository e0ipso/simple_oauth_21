<?php

namespace Drupal\simple_oauth_client_registration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_oauth_client_registration\Service\ClientRegistrationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Controller for RFC 7591 Dynamic Client Registration endpoint.
 */
class ClientRegistrationController extends ControllerBase {

  /**
   * The client registration service.
   *
   * @var \Drupal\simple_oauth_client_registration\Service\ClientRegistrationService
   */
  protected $registrationService;

  /**
   * Constructs a ClientRegistrationController object.
   *
   * @param \Drupal\simple_oauth_client_registration\Service\ClientRegistrationService $registration_service
   *   The client registration service.
   */
  public function __construct(ClientRegistrationService $registration_service) {
    $this->registrationService = $registration_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('simple_oauth_client_registration.service.registration')
    );
  }

  /**
   * Handles dynamic client registration.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response containing RFC 7591 client registration response.
   */
  public function register(Request $request): JsonResponse {
    try {
      // Parse JSON request body.
      $content = $request->getContent();
      if (empty($content)) {
        throw new BadRequestHttpException('Request body cannot be empty');
      }

      $client_metadata = json_decode($content, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new BadRequestHttpException('Invalid JSON in request body');
      }

      // Validate and register the client.
      $registration_response = $this->registrationService->registerClient($client_metadata);

      // Create JSON response with proper headers.
      $response = new JsonResponse($registration_response);

      // Set appropriate caching headers (no caching for registration responses).
      $response->setMaxAge(0);
      $response->setPrivate();

      // Set additional headers for API compliance.
      $response->headers->set('Content-Type', 'application/json; charset=utf-8');

      // Add CORS headers for cross-origin requests.
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', 'POST');

      return $response;
    }
    catch (BadRequestHttpException $e) {
      // Return RFC 7591 compliant error response.
      $error_response = [
        'error' => 'invalid_client_metadata',
        'error_description' => $e->getMessage(),
      ];

      $response = new JsonResponse($error_response, 400);
      $response->headers->set('Content-Type', 'application/json; charset=utf-8');
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', 'POST');

      return $response;
    }
    catch (\Exception $e) {
      // Log error for debugging.
      $this->getLogger('simple_oauth_client_registration')->error(
        'Error during client registration: @message',
        ['@message' => $e->getMessage()]
      );

      // Return RFC 7591 compliant error response for server errors.
      $error_response = [
        'error' => 'server_error',
        'error_description' => 'The authorization server encountered an unexpected condition',
      ];

      $response = new JsonResponse($error_response, 500);
      $response->headers->set('Content-Type', 'application/json; charset=utf-8');
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', 'POST');

      return $response;
    }
  }

  /**
   * Handles client management operations (GET, PUT, DELETE).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   * @param string $client_id
   *   The client identifier.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response containing client data or operation result.
   */
  public function manage(Request $request, $client_id): JsonResponse {
    try {
      $method = $request->getMethod();

      switch ($method) {
        case 'GET':
          return $this->getClient($request, $client_id);

        case 'PUT':
          return $this->updateClient($request, $client_id);

        case 'DELETE':
          return $this->deleteClient($request, $client_id);

        default:
          throw new BadRequestHttpException('Method not allowed');
      }
    }
    catch (BadRequestHttpException $e) {
      $error_response = [
        'error' => 'invalid_request',
        'error_description' => $e->getMessage(),
      ];

      $response = new JsonResponse($error_response, 400);
      $response->headers->set('Content-Type', 'application/json; charset=utf-8');

      return $response;
    }
    catch (\Exception $e) {
      $this->getLogger('simple_oauth_client_registration')->error(
        'Error during client management: @message',
        ['@message' => $e->getMessage()]
      );

      $error_response = [
        'error' => 'server_error',
        'error_description' => 'The authorization server encountered an unexpected condition',
      ];

      $response = new JsonResponse($error_response, 500);
      $response->headers->set('Content-Type', 'application/json; charset=utf-8');

      return $response;
    }
  }

  /**
   * Retrieves client metadata.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   * @param string $client_id
   *   The client identifier.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response containing client metadata.
   */
  protected function getClient(Request $request, $client_id): JsonResponse {
    // Validate registration access token.
    $this->validateRegistrationToken($request, $client_id);

    // Get client metadata.
    $client_metadata = $this->registrationService->getClientMetadata($client_id);

    $response = new JsonResponse($client_metadata);
    $response->headers->set('Content-Type', 'application/json; charset=utf-8');

    return $response;
  }

  /**
   * Updates client metadata.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   * @param string $client_id
   *   The client identifier.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response containing updated client metadata.
   */
  protected function updateClient(Request $request, $client_id): JsonResponse {
    // Validate registration access token.
    $this->validateRegistrationToken($request, $client_id);

    // Parse request body.
    $content = $request->getContent();
    if (empty($content)) {
      throw new BadRequestHttpException('Request body cannot be empty');
    }

    $client_metadata = json_decode($content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new BadRequestHttpException('Invalid JSON in request body');
    }

    // Update client metadata.
    $updated_metadata = $this->registrationService->updateClientMetadata($client_id, $client_metadata);

    $response = new JsonResponse($updated_metadata);
    $response->headers->set('Content-Type', 'application/json; charset=utf-8');

    return $response;
  }

  /**
   * Deletes a client registration.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   * @param string $client_id
   *   The client identifier.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response confirming deletion.
   */
  protected function deleteClient(Request $request, $client_id): JsonResponse {
    // Validate registration access token.
    $this->validateRegistrationToken($request, $client_id);

    // Delete client registration.
    $this->registrationService->deleteClient($client_id);

    // Return success response.
    $response = new JsonResponse(NULL, 204);
    $response->headers->set('Content-Type', 'application/json; charset=utf-8');

    return $response;
  }

  /**
   * Validates the registration access token.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   * @param string $client_id
   *   The client identifier.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   If the token is invalid or missing.
   */
  protected function validateRegistrationToken(Request $request, $client_id): void {
    $authorization_header = $request->headers->get('Authorization');

    if (!$authorization_header || !preg_match('/^Bearer (.+)$/', $authorization_header, $matches)) {
      throw new BadRequestHttpException('Missing or invalid authorization header');
    }

    $token = $matches[1];
    if (!$this->registrationService->validateRegistrationToken($client_id, $token)) {
      throw new BadRequestHttpException('Invalid registration access token');
    }
  }

}
