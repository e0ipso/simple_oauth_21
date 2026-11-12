<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\simple_oauth\Entities\ClientEntityInterface;
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
   * The device flow response settings.
   */
  private ImmutableConfig $settings;

  /**
   * The path validator.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  private PathValidatorInterface $pathValidator;

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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   *   The path validator.
   */
  public function __construct(
    HttpMessageFactoryInterface $http_message_factory,
    ClientRepositoryInterface $client_repository,
    DeviceCodeService $device_code_service,
    LoggerInterface $logger,
    ConfigFactoryInterface $config_factory,
    PathValidatorInterface $path_validator,
  ) {
    $this->httpMessageFactory = $http_message_factory;
    $this->clientRepository = $client_repository;
    $this->deviceCodeService = $device_code_service;
    $this->logger = $logger;
    $this->settings = $config_factory->get('simple_oauth_device_flow.settings');
    $this->pathValidator = $path_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('psr7.http_message_factory'),
      $container->get('simple_oauth.repositories.client'),
      $container->get('simple_oauth_device_flow.device_code_service'),
      $container->get('logger.channel.simple_oauth_device_flow'),
      $container->get('config.factory'),
      $container->get('path.validator'),
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
      $client_id = $request->attributes->get('client_id', $request->query->get('client_id', $request->request->get('client_id', NULL)));
      if (empty($client_id)) {
        $this->logger->notice('Device authorization request missing client_id parameter');
        throw OAuthServerException::invalidRequest('client_id');
      }

      // Validate client exists and is authorized for device flow.
      $client_entity = $this->clientRepository->getClientEntity($client_id);
      if (!$client_entity instanceof ClientEntityInterface) {
        $this->logger->notice('Device authorization request with invalid client_id');
        // For device flow, invalid client_id is a bad request (400), not auth
        // failure (401). Use code 4 like invalidClient() but with 400 status
        // code per RFC 8628.
        $exception = new OAuthServerException('Client identifier is invalid', 4, 'invalid_client', 400);
        $exception->setServerRequest($server_request);
        throw $exception;
      }

      $client_drupal_entity = $client_entity->getDrupalEntity();

      // Verify client is configured for device code grant.
      // RFC 8628 requires the full URN for the grant type identifier.
      $grant_types = array_column($client_drupal_entity->get('grant_types')->getValue(), 'value');
      if (!in_array('urn:ietf:params:oauth:grant-type:device_code', $grant_types, TRUE)) {
        $this->logger->notice('Client @client_id not configured for device_code grant', [
          '@client_id' => $client_entity->getIdentifier(),
        ]);
        throw OAuthServerException::unauthorizedClient('Client not authorized for device code grant');
      }

      // Extract optional scope parameter.
      $scope = $request->attributes->get('scope', $request->query->get('scope', $request->request->get('scope', '')));

      // Reject invalid imported settings before issuing device credentials.
      $verification_path = $this->settings->get('verification_uri') ?? '/oauth/device';
      if (!is_string($verification_path) || !preg_match('@^/(?!/)[A-Za-z0-9/_-]*$@D', $verification_path)) {
        throw new \UnexpectedValueException('Invalid local device verification path.');
      }
      $verification_url = $this->pathValidator->getUrlIfValid($verification_path);
      if (!$verification_url || !$verification_url->isRouted() || $verification_url->getRouteName() !== 'simple_oauth_device_flow.device_verification_form') {
        throw new \UnexpectedValueException('Device verification path does not resolve to the verification form.');
      }
      $verification_uri = $request->getSchemeAndHttpHost() . $verification_url->toString();

      // Generate device and user codes using the service.
      $authorization_data = $this->deviceCodeService->generateDeviceAuthorization(
        $client_entity,
        $scope
      );

      // Build RFC 8628 compliant response.
      $response_data = [
        'device_code' => $authorization_data['device_code'],
        'user_code' => $authorization_data['user_code'],
        'verification_uri' => $verification_uri,
        'expires_in' => $authorization_data['expires_in'],
        'interval' => $authorization_data['interval'],
      ];
      // Prefer the installed option; retain the earlier name as a fallback.
      $include_complete = $this->settings->get('verification_uri_complete') ?? $this->settings->get('verification_uri_complete_enabled') ?? TRUE;
      if ($include_complete) {
        $response_data['verification_uri_complete'] = $verification_uri . '?user_code=' . urlencode($authorization_data['user_code']);
      }

      $this->logger->info('Device authorization successful for client @client_id', [
        '@client_id' => $client_entity->getIdentifier(),
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
      $this->logger->log($log_level, 'Device authorization error: @exception_type', [
        '@exception_type' => get_class($exception),
      ]);

      // Convert OAuth exception to JSON response.
      return $this->createErrorResponse($exception, $server_response);
    }
    catch (\Exception $exception) {
      // Log unexpected exceptions.
      $this->logger->error('Unexpected error in device authorization: @exception_type', [
        '@exception_type' => get_class($exception),
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
