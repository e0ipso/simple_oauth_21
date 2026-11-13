<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;
use Drupal\simple_oauth_client_registration\Service\ClientRegistrationOperationsInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Controller for RFC 7591 Dynamic Client Registration endpoint.
 */
final class ClientRegistrationController extends ControllerBase {

  /**
   * Constructs a ClientRegistrationController object.
   *
   * @param \Drupal\simple_oauth_client_registration\Service\ClientRegistrationOperationsInterface $registrationService
   *   The client registration service.
   * @param \Symfony\Component\Serializer\SerializerInterface $serializer
   *   The serializer.
   */
  public function __construct(
    protected readonly ClientRegistrationOperationsInterface $registrationService,
    protected readonly SerializerInterface $serializer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('simple_oauth_client_registration.service.registration'),
      $container->get('serializer')
    );
  }

  /**
   * Handles dynamic client registration.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The JSON response containing RFC 7591 client registration response.
   */
  public function register(Request $request): CacheableJsonResponse {
    try {
      // Parse JSON request body.
      $content = $request->getContent();
      if (empty($content)) {
        throw new BadRequestHttpException('Request body cannot be empty');
      }

      // Deserialize the request to a ClientRegistration DTO.
      try {
        $clientRegistration = $this->serializer->deserialize(
          $content,
          ClientRegistration::class,
          'json',
          ['json_decode_associative' => FALSE]
        );
      }
      catch (\Exception $e) {
        throw new BadRequestHttpException('Invalid client metadata: ' . $e->getMessage());
      }

      // Register the client using the DTO.
      $registration_response = $this->registrationService->registerClient($clientRegistration);

      // Create JSON response with proper headers.
      $response = new CacheableJsonResponse($registration_response, 201);

      // Set appropriate caching headers (no caching for registration
      // responses).
      $response->setMaxAge(0);
      $response->setPrivate();
      $response->headers->set('Cache-Control', 'no-store, private');
      $response->headers->set('Pragma', 'no-cache');

      // Add CORS headers for cross-origin requests.
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', Request::METHOD_POST);

      return $response;
    }
    catch (BadRequestHttpException $e) {
      // Return RFC 7591 compliant error response.
      $error_response = [
        'error' => 'invalid_client_metadata',
        'error_description' => $e->getMessage(),
      ];

      $response = new CacheableJsonResponse($error_response, 400);
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', Request::METHOD_POST);

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

      $response = new CacheableJsonResponse($error_response, 500);
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', Request::METHOD_POST);

      return $response;
    }
  }

  /**
   * Handles client management operations (GET, POST, PUT, DELETE).
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

      return match ($method) {
        Request::METHOD_GET => $this->getClient($request, $client_id),
        Request::METHOD_POST => $this->rotateSecret($request, $client_id),
        Request::METHOD_PUT => $this->updateClient($request, $client_id),
        Request::METHOD_DELETE => $this->deleteClient($request, $client_id),
        default => throw new BadRequestHttpException('Method not allowed'),
      };
    }
    catch (BadRequestHttpException $e) {
      $error_response = [
        'error' => 'invalid_request',
        'error_description' => $e->getMessage(),
      ];

      $response = new CacheableJsonResponse($error_response, 400);
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', Request::METHOD_POST);

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

      $response = new CacheableJsonResponse($error_response, 500);
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', Request::METHOD_POST);

      return $response;
    }
  }

  /**
   * Replaces a client secret using its registration-management credentials.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string $client_id
   *   The client identifier.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The replacement credentials or a controlled error response.
   */
  public function rotateSecret(Request $request, string $client_id): JsonResponse {
    try {
      $this->validateRegistrationToken($request, $client_id);
      $response = new JsonResponse($this->registrationService->rotateClientSecret($client_id));
    }
    catch (BadRequestHttpException $e) {
      $response = new JsonResponse([
        'error' => 'invalid_request',
        'error_description' => $e->getMessage(),
      ], 400);
    }
    catch (\Exception $e) {
      $this->getLogger('simple_oauth_client_registration')->error(
        'Client secret rotation failed: @exception_type',
        ['@exception_type' => get_class($e)]
      );
      $response = new JsonResponse([
        'error' => 'server_error',
        'error_description' => 'The client secret could not be replaced',
      ], 500);
    }

    $response->setMaxAge(0);
    $response->setPrivate();
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

  /**
   * Retrieves client metadata.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   * @param string $client_id
   *   The client identifier.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The JSON response containing client metadata.
   */
  protected function getClient(Request $request, $client_id): CacheableJsonResponse {
    // Validate registration access token.
    $this->validateRegistrationToken($request, $client_id);

    // Get client metadata.
    $client_metadata = $this->registrationService->getClientMetadata($client_id);

    $response = new CacheableJsonResponse($client_metadata);
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', Request::METHOD_GET);

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
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The JSON response containing updated client metadata.
   */
  protected function updateClient(Request $request, $client_id): CacheableJsonResponse {
    // Validate registration access token.
    $this->validateRegistrationToken($request, $client_id);

    // Parse request body.
    $content = $request->getContent();
    if (empty($content)) {
      throw new BadRequestHttpException('Request body cannot be empty');
    }

    // Deserialize the request to a ClientRegistration DTO.
    try {
      $clientRegistration = $this->serializer->deserialize(
        $content,
        ClientRegistration::class,
        'json',
        ['json_decode_associative' => FALSE]
      );
    }
    catch (\Exception $e) {
      $response = new CacheableJsonResponse([
        'error' => 'invalid_client_metadata',
        'error_description' => 'Invalid client metadata: ' . $e->getMessage(),
      ], 400);
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', Request::METHOD_PUT);
      return $response;
    }

    // Update client metadata using the DTO.
    try {
      $updated_metadata = $this->registrationService->updateClientMetadata($client_id, $clientRegistration);
    }
    catch (BadRequestHttpException) {
      return new CacheableJsonResponse([
        'error' => 'invalid_client_metadata',
        'error_description' => 'Invalid client metadata',
      ], 400, [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => Request::METHOD_PUT,
      ]);
    }

    $response = new CacheableJsonResponse($updated_metadata);
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', Request::METHOD_PUT);

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
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The JSON response confirming deletion.
   */
  protected function deleteClient(Request $request, $client_id): CacheableJsonResponse {
    // Validate registration access token.
    $this->validateRegistrationToken($request, $client_id);

    // Delete client registration.
    $this->registrationService->deleteClient($client_id);

    // Return success response.
    $response = new CacheableJsonResponse(NULL, 204);
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', Request::METHOD_DELETE);

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
