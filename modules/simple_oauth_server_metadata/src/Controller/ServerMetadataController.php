<?php

namespace Drupal\simple_oauth_server_metadata\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_oauth_server_metadata\Service\ServerMetadataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Drupal\Core\Cache\CacheableJsonResponse;

/**
 * Controller for RFC 8414 Authorization Server Metadata endpoint.
 */
class ServerMetadataController extends ControllerBase {

  /**
   * Constructs a ServerMetadataController object.
   *
   * @param \Drupal\simple_oauth_server_metadata\Service\ServerMetadataService $serverMetadataService
   *   The server metadata service.
   */
  public function __construct(
    protected readonly ServerMetadataService $serverMetadataService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('simple_oauth_server_metadata.server_metadata')
    );
  }

  /**
   * Serves the authorization server metadata.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The JSON response containing RFC 8414 metadata.
   */
  public function metadata(): CacheableJsonResponse {
    try {
      // Get metadata from service.
      $metadata = $this->serverMetadataService->getServerMetadata();

      // Validate metadata for RFC 8414 compliance.
      if (!$this->serverMetadataService->validateMetadata($metadata)) {
        throw new ServiceUnavailableHttpException(300, 'Server metadata is incomplete or invalid');
      }

      // Create cacheable JSON response with proper cache metadata.
      $response = new CacheableJsonResponse($metadata);

      // Add cache metadata from service.
      $response->addCacheableDependency($this->serverMetadataService);

      // Add CORS headers for cross-origin requests.
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', Request::METHOD_GET);

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
