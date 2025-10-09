<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\simple_oauth_device_flow\Service\DeviceCodeService;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for RFC 8628 Device Authorization endpoint.
 *
 * Handles device authorization requests as part of the OAuth 2.0 Device
 * Authorization Grant flow. Generates device codes and user codes for
 * devices with limited input capabilities.
 */
final class DeviceAuthorizationController extends ControllerBase {

  /**
   * The HTTP message factory for PSR-7 conversion.
   */
  private HttpMessageFactoryInterface $httpMessageFactory;

  /**
   * The client repository for OAuth client validation.
   */
  private ClientRepositoryInterface $clientRepository;

  /**
   * The device code service for generating codes.
   */
  private DeviceCodeService $deviceCodeService;

  /**
   * The logger for device authorization operations.
   */
  private LoggerInterface $logger;

  /**
   * Constructs a DeviceAuthorizationController object.
   *
   * @param \Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface $http_message_factory
   *   The PSR-7 HTTP message factory.
   * @param \League\OAuth2\Server\Repositories\ClientRepositoryInterface $client_repository
   *   The OAuth client repository.
   * @param \Drupal\simple_oauth_device_flow\Service\DeviceCodeService $device_code_service
   *   The device code service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger for device flow operations.
   */
  public function __construct(
    HttpMessageFactoryInterface $http_message_factory,
    ClientRepositoryInterface $client_repository,
    DeviceCodeService $device_code_service,
    LoggerInterface $logger,
  ) {
    $this->httpMessageFactory = $http_message_factory;
    $this->clientRepository = $client_repository;
    $this->deviceCodeService = $device_code_service;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('psr7.http_message_factory'),
      $container->get('simple_oauth.repositories.client'),
      $container->get('simple_oauth_device_flow.device_code_service'),
      $container->get('logger.channel.simple_oauth')
    );
  }

  /**
   * Handles device authorization requests.
   *
   * Processes POST requests to /oauth/device_authorization as specified
   * in RFC 8628. Validates the client and generates device/user codes
   * for the device authorization flow.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request containing client_id and optional scope.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with device_code, user_code, verification_uri, etc.
   *
   * @throws \Exception
   *   If an unexpected error occurs during processing.
   */
  public function authorize(Request $request): JsonResponse {
    $server_request = $this->httpMessageFactory->createRequest($request);
    $server_response = new Response();

    try {
      // Extract and validate client_id parameter.
      $client_id = $request->get('client_id');
      if (empty($client_id)) {
        $this->logger->notice('Device authorization request missing client_id parameter');
        throw OAuthServerException::invalidRequest('client_id');
      }

      // Validate client exists and is authorized for device flow.
      $client_entity = $this->clientRepository->getClientEntity($client_id);
      if (empty($client_entity)) {
        $this->logger->notice('Device authorization request with invalid client_id: @client_id', [
          '@client_id' => $client_id,
        ]);
        // For device flow, invalid client_id is a bad request (400), not auth
        // failure (401). Use code 4 like invalidClient() but with 400 status
        // code per RFC 8628.
        $exception = new OAuthServerException('Client identifier is invalid', 4, 'invalid_client', 400);
        $exception->setServerRequest($server_request);
        throw $exception;
      }

      $client_drupal_entity = $client_entity->getDrupalEntity();

      // Verify client is configured for device code grant.
      $grant_types = array_column($client_drupal_entity->get('grant_types')->getValue(), 'value');
      if (!in_array('urn:ietf:params:oauth:grant-type:device_code', $grant_types, TRUE)) {
        $this->logger->notice('Client @client_id not configured for device_code grant', [
          '@client_id' => $client_id,
        ]);
        throw OAuthServerException::unauthorizedClient('Client not authorized for device code grant');
      }

      // Extract optional scope parameter.
      $scope = $request->get('scope', '');

      // Generate device and user codes using the service.
      $authorization_data = $this->deviceCodeService->generateDeviceAuthorization(
        $client_entity,
        $scope
      );

      // Build verification URIs using request to ensure proper base URL.
      // This ensures proper URL generation in both regular and test
      // environments.
      $base_url = $request->getSchemeAndHttpHost();
      $verification_path = Url::fromRoute('simple_oauth_device_flow.device_verification_form')->toString();
      $verification_uri = $base_url . $verification_path;

      $verification_uri_complete = $base_url . $verification_path . '?user_code=' . urlencode($authorization_data['user_code']);

      // Build RFC 8628 compliant response.
      $response_data = [
        'device_code' => $authorization_data['device_code'],
        'user_code' => $authorization_data['user_code'],
        'verification_uri' => $verification_uri,
        'verification_uri_complete' => $verification_uri_complete,
        'expires_in' => $authorization_data['expires_in'],
        'interval' => $authorization_data['interval'],
      ];

      $this->logger->info('Device authorization successful for client @client_id, user_code: @user_code', [
        '@client_id' => $client_id,
        '@user_code' => $authorization_data['user_code'],
      ]);

      // Create JSON response with proper headers.
      $response = new JsonResponse($response_data);
      $response->headers->set('Cache-Control', 'no-store');
      $response->headers->set('Pragma', 'no-cache');

      // Add CORS headers for cross-origin requests.
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', Request::METHOD_POST);
      $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');

      return $response;
    }
    catch (OAuthServerException $exception) {
      // Log OAuth server exceptions appropriately.
      $log_level = $exception->getCode() < 500 ? 'notice' : 'error';
      $this->logger->log($log_level, 'Device authorization error: @message. Hint: @hint', [
        '@message' => $exception->getMessage(),
        '@hint' => $exception->getHint(),
      ]);

      // Convert OAuth exception to JSON response.
      return $this->createErrorResponse($exception, $server_response);
    }
    catch (\Exception $exception) {
      // Log unexpected exceptions.
      $this->logger->error('Unexpected error in device authorization: @message', [
        '@message' => $exception->getMessage(),
      ]);

      // Return generic server error for unexpected exceptions.
      $oauth_exception = OAuthServerException::serverError('The authorization server encountered an unexpected condition');
      return $this->createErrorResponse($oauth_exception, $server_response);
    }
  }

  /**
   * Creates an error response from an OAuth server exception.
   *
   * @param \League\OAuth2\Server\Exception\OAuthServerException $exception
   *   The OAuth server exception.
   * @param \Psr\Http\Message\ResponseInterface $server_response
   *   The PSR-7 response object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON error response.
   */
  private function createErrorResponse(OAuthServerException $exception, ResponseInterface $server_response): JsonResponse {
    // Generate the OAuth error response.
    $psr_response = $exception->generateHttpResponse($server_response);

    // Extract response data.
    $status_code = $psr_response->getStatusCode();
    // Rewind the response body stream before reading.
    $psr_response->getBody()->rewind();
    $response_body = $psr_response->getBody()->getContents();
    $error_data = json_decode($response_body, TRUE) ?: ['error' => 'server_error'];

    // Create Symfony JSON response.
    $response = new JsonResponse($error_data, $status_code);

    // Copy headers from PSR-7 response.
    foreach ($psr_response->getHeaders() as $name => $values) {
      $response->headers->set($name, implode(', ', $values));
    }

    // Add CORS headers for error responses.
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', Request::METHOD_POST);
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');

    return $response;
  }

}
