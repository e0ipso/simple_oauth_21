<?php

namespace Drupal\simple_oauth_server_metadata\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_oauth_server_metadata\Service\ResourceMetadataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Controller for RFC 9728 Protected Resource Metadata endpoint.
 */
class ResourceMetadataController extends ControllerBase {

  /**
   * Constructs a ResourceMetadataController object.
   *
   * @param \Drupal\simple_oauth_server_metadata\Service\ResourceMetadataService $resourceMetadataService
   *   The resource metadata service.
   */
  public function __construct(
    protected readonly ResourceMetadataService $resourceMetadataService,
  ) {}

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
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The JSON response containing RFC 9728 metadata.
   */
  public function __invoke(): CacheableJsonResponse {
    try {
      // Get metadata from service.
      $metadata = $this->resourceMetadataService->getResourceMetadata();

      // Validate metadata for RFC 9728 compliance.
      if (!$this->resourceMetadataService->validateMetadata($metadata)) {
        throw new ServiceUnavailableHttpException(300, 'Resource metadata is incomplete or invalid');
      }

      // Create cacheable JSON response with proper cache metadata.
      $response = new CacheableJsonResponse($metadata);

      // Add cache metadata from service.
      $response->addCacheableDependency($this->resourceMetadataService);

      // Add CORS headers for cross-origin requests.
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', Request::METHOD_GET);

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
