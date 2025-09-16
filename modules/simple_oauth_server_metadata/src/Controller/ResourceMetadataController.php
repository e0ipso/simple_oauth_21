<?php

namespace Drupal\simple_oauth_server_metadata\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_oauth_server_metadata\Service\ResourceMetadataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Controller for RFC 9728 Protected Resource Metadata endpoint.
 */
class ResourceMetadataController extends ControllerBase {

  /**
   * The resource metadata service.
   *
   * @var \Drupal\simple_oauth_server_metadata\Service\ResourceMetadataService
   */
  protected $resourceMetadataService;

  /**
   * Constructs a ResourceMetadataController object.
   *
   * @param \Drupal\simple_oauth_server_metadata\Service\ResourceMetadataService $resource_metadata_service
   *   The resource metadata service.
   */
  public function __construct(ResourceMetadataService $resource_metadata_service) {
    $this->resourceMetadataService = $resource_metadata_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('simple_oauth_server_metadata.resource_metadata')
    );
  }

  /**
   * Serves the protected resource metadata.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response containing RFC 9728 metadata.
   */
  public function metadata(): JsonResponse {
    try {
      // Get metadata from service.
      $metadata = $this->resourceMetadataService->getResourceMetadata();

      // Validate metadata for RFC 9728 compliance.
      if (!$this->resourceMetadataService->validateMetadata($metadata)) {
        throw new ServiceUnavailableHttpException(300, 'Resource metadata is incomplete or invalid');
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
        'Error generating resource metadata: @message',
        ['@message' => $e->getMessage()]
      );

      // Return service unavailable for any errors.
      throw new ServiceUnavailableHttpException(300, 'Resource metadata temporarily unavailable');
    }
  }

}
