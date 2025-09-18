<?php

namespace Drupal\simple_oauth_server_metadata\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_oauth_server_metadata\Service\ServerMetadataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\DrupalKernelInterface;

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
   * The kernel service.
   *
   * @var \Drupal\Core\DrupalKernelInterface
   */
  protected $kernel;

  /**
   * Constructs a ServerMetadataController object.
   *
   * @param \Drupal\simple_oauth_server_metadata\Service\ServerMetadataService $server_metadata_service
   *   The server metadata service.
   * @param \Drupal\Core\DrupalKernelInterface $kernel
   *   The kernel service.
   */
  public function __construct(ServerMetadataService $server_metadata_service, DrupalKernelInterface $kernel) {
    $this->serverMetadataService = $server_metadata_service;
    $this->kernel = $kernel;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('simple_oauth_server_metadata.server_metadata'),
      $container->get('kernel')
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
      // Force cache invalidation in test environments to ensure fresh data.
      if (defined('DRUPAL_TEST_IN_CHILD_SITE') || $this->kernel->getEnvironment() === 'testing') {
        $this->serverMetadataService->invalidateCache();
      }

      // Get metadata from service.
      $metadata = $this->serverMetadataService->getServerMetadata();

      // Validate metadata for RFC 8414 compliance.
      if (!$this->serverMetadataService->validateMetadata($metadata)) {
        throw new ServiceUnavailableHttpException(300, 'Server metadata is incomplete or invalid');
      }

      // Create cacheable JSON response with proper cache metadata.
      $response = new CacheableJsonResponse($metadata);

      // Add cache metadata from service.
      $cache_metadata = $this->serverMetadataService->getCacheableMetadata();
      $response->addCacheableDependency($cache_metadata);

      // Set appropriate caching headers based on environment.
      // Disable caching in test environments to ensure fresh metadata.
      if (defined('DRUPAL_TEST_IN_CHILD_SITE') || $this->kernel->getEnvironment() === 'testing') {
        $response->setMaxAge(0);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
      }
      else {
        $response->setMaxAge($this->serverMetadataService->getCacheMaxAge());
        $response->setPublic();
      }

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
