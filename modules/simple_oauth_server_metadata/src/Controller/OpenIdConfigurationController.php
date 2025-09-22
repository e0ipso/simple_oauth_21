<?php

namespace Drupal\simple_oauth_server_metadata\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_oauth_server_metadata\Service\OpenIdConfigurationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Controller for OpenID Connect Discovery endpoint.
 */
class OpenIdConfigurationController extends ControllerBase {

  /**
   * Constructs an OpenIdConfigurationController object.
   *
   * @param \Drupal\simple_oauth_server_metadata\Service\OpenIdConfigurationService $openIdConfigurationService
   *   The OpenID configuration service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected readonly OpenIdConfigurationService $openIdConfigurationService,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('simple_oauth_server_metadata.openid_configuration'),
      $container->get('config.factory')
    );
  }

  /**
   * Serves the OpenID Connect Discovery metadata.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The JSON response containing OpenID Connect Discovery metadata.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When OpenID Connect is disabled.
   */
  public function __invoke(): CacheableJsonResponse {
    // Check if OpenID Connect is enabled in simple_oauth module.
    $simple_oauth_config = $this->configFactory->get('simple_oauth.settings');
    if ($simple_oauth_config->get('disable_openid_connect')) {
      throw new NotFoundHttpException('OpenID Connect is not enabled');
    }

    try {
      // Get metadata from service.
      $metadata = $this->openIdConfigurationService->getOpenIdConfiguration();

      // Create cacheable JSON response with proper cache metadata.
      $response = new CacheableJsonResponse($metadata);

      // Add cache metadata from service.
      $response->addCacheableDependency($this->openIdConfigurationService);

      // Add CORS headers for cross-origin requests.
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', Request::METHOD_GET);

      return $response;
    }
    catch (\Exception $e) {
      // Log error for debugging.
      $this->getLogger('simple_oauth_server_metadata')->error(
        'Error generating OpenID Connect Discovery metadata: @message',
        ['@message' => $e->getMessage()]
      );

      // Return service unavailable for any errors.
      throw new ServiceUnavailableHttpException(300, 'OpenID Connect Discovery metadata temporarily unavailable');
    }
  }

}
