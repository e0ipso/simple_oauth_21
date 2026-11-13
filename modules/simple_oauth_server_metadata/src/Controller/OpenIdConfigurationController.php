<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\simple_oauth_server_metadata\Service\OpenIdConfigurationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Controller for OpenID Connect Discovery endpoint.
 */
final class OpenIdConfigurationController extends ControllerBase {

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
    // Constructor debugging will be handled in configuration() method.
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Static method debugging will be handled in configuration() method.
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
   * @throws \Drupal\Core\Http\Exception\CacheableNotFoundHttpException
   *   When OpenID Connect is disabled.
   */
  public function configuration(): CacheableJsonResponse {
    // Log that controller is being invoked.
    $this->getLogger('simple_oauth_server_metadata')->debug('🚀 OpenIdConfigurationController::configuration() STARTED - Controller instantiated and method invoked');

    // Check if OpenID Connect is enabled in simple_oauth module.
    $simple_oauth_config = $this->configFactory->get('simple_oauth.settings');
    $is_disabled = $simple_oauth_config->get('disable_openid_connect');
    $this->getLogger('simple_oauth_server_metadata')->debug('🔧 OpenID Connect disabled config value: @disabled', ['@disabled' => $is_disabled ? 'true' : 'false']);

    if ($is_disabled) {
      $this->getLogger('simple_oauth_server_metadata')->debug('❌ OpenID Connect is disabled - throwing NotFoundHttpException');
      // Share site variation with the enabled JSON response on this route.
      $cacheability = CacheableMetadata::createFromObject($simple_oauth_config)
        ->addCacheContexts(['url.site']);
      throw new CacheableNotFoundHttpException($cacheability, 'OpenID Connect is not enabled');
    }

    $this->getLogger('simple_oauth_server_metadata')->debug('✅ OpenID Connect is enabled - proceeding to get metadata');

    try {
      $this->getLogger('simple_oauth_server_metadata')->debug('📊 Calling openIdConfigurationService->getOpenIdConfiguration()');
      // Get metadata from service.
      $metadata = $this->openIdConfigurationService->getOpenIdConfiguration();
      $this->getLogger('simple_oauth_server_metadata')->debug('📊 Service call successful - got @count metadata fields', ['@count' => count($metadata)]);

      $this->getLogger('simple_oauth_server_metadata')->debug('📝 Creating CacheableJsonResponse');
      // Create cacheable JSON response with proper cache metadata.
      $response = new CacheableJsonResponse($metadata);

      // Add cache metadata from service.
      $response->addCacheableDependency($this->openIdConfigurationService);
      $response->addCacheableDependency($simple_oauth_config);

      // Add CORS headers for cross-origin requests.
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', Request::METHOD_GET);

      $this->getLogger('simple_oauth_server_metadata')->debug('✅ OpenIdConfigurationController::configuration() SUCCESS - returning response with status @status', ['@status' => $response->getStatusCode()]);
      return $response;
    }
    catch (\Exception $e) {
      // Log error for debugging.
      $this->getLogger('simple_oauth_server_metadata')->error(
        '💥 Exception in OpenIdConfigurationController: @message, @trace',
        ['@message' => $e->getMessage(), '@trace' => $e->getTraceAsString()]
      );

      $this->getLogger('simple_oauth_server_metadata')->debug('❌ Throwing ServiceUnavailableHttpException');
      // Return service unavailable for any errors.
      throw new ServiceUnavailableHttpException(300, 'OpenID Connect Discovery metadata temporarily unavailable');
    }
  }

}
