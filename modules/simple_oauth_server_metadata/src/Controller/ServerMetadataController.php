<?php

namespace Drupal\simple_oauth_server_metadata\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_oauth_server_metadata\Service\ServerMetadataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Controller for RFC 8414 Authorization Server Metadata endpoint.
 */
class ServerMetadataController extends ControllerBase {

  /**
   * The server metadata service.
   *
   * @var \Drupal\simple_oauth_server_metadata\Service\ServerMetadataService
   */
  protected $serverMetadataService;

  /**
   * Constructs a ServerMetadataController object.
   *
   * @param \Drupal\simple_oauth_server_metadata\Service\ServerMetadataService $server_metadata_service
   *   The server metadata service.
   */
  public function __construct(ServerMetadataService $server_metadata_service) {
    $this->serverMetadataService = $server_metadata_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('simple_oauth_server_metadata.server_metadata')
    );
  }

  /**
   * Serves the authorization server metadata.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response containing RFC 8414 metadata.
   */
  public function metadata(): JsonResponse {
    try {
      // Get metadata from service.
      $metadata = $this->serverMetadataService->getServerMetadata();

      // Validate metadata for RFC 8414 compliance.
      if (!$this->serverMetadataService->validateMetadata($metadata)) {
        throw new ServiceUnavailableHttpException(300, 'Server metadata is incomplete or invalid');
      }

      // Create JSON response with proper headers.
      $response = new JsonResponse($metadata);

      // Set appropriate caching headers.
      $response->setMaxAge(3600);
      $response->setPublic();

      // Set additional headers for API compliance.
      $response->headers->set('Content-Type', 'application/json; charset=utf-8');

      // Add CORS headers for cross-origin requests.
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', 'GET');

      return $response;
    }
    catch (\Exception $e) {
      // Log error for debugging.
      $this->getLogger('simple_oauth_server_metadata')->error(
        'Error generating server metadata: @message',
        ['@message' => $e->getMessage()]
      );

      // Return service unavailable for any errors.
      throw new ServiceUnavailableHttpException(300, 'Server metadata temporarily unavailable');
    }
  }

}
