<?php

namespace Drupal\simple_oauth_native_apps\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth_native_apps\Service\PKCEEnhancementService;
use Drupal\simple_oauth_native_apps\Service\NativeClientDetector;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Event subscriber for enhanced PKCE validation in OAuth flows.
 *
 * Implements RFC 8252 enhanced PKCE requirements including mandatory S256
 * method enforcement, entropy validation, and native client specific security
 * checks for OAuth authorization and token exchange flows.
 */
class PkceValidationSubscriber implements EventSubscriberInterface {

  /**
   * The PKCE enhancement service.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\PKCEEnhancementService
   */
  protected PKCEEnhancementService $pkceEnhancementService;

  /**
   * The native client detector service.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\NativeClientDetector
   */
  protected NativeClientDetector $nativeClientDetector;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a PkceValidationSubscriber.
   *
   * @param \Drupal\simple_oauth_native_apps\Service\PKCEEnhancementService $pkce_enhancement_service
   *   The PKCE enhancement service.
   * @param \Drupal\simple_oauth_native_apps\Service\NativeClientDetector $native_client_detector
   *   The native client detector service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    PKCEEnhancementService $pkce_enhancement_service,
    NativeClientDetector $native_client_detector,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->pkceEnhancementService = $pkce_enhancement_service;
    $this->nativeClientDetector = $native_client_detector;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('simple_oauth_native_apps');
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    // Priority 50 to run after route resolution but before OAuth processing.
    $events[KernelEvents::REQUEST][] = ['onOauthRequest', 50];
    return $events;
  }

  /**
   * Handles OAuth requests for PKCE validation.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onOauthRequest(RequestEvent $event): void {
    $request = $event->getRequest();

    // Only process OAuth authorization and token requests.
    if (!$this->isOauthFlowRequest($request)) {
      return;
    }

    // Skip if enhanced PKCE validation is disabled.
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');
    if (!$config->get('native.enhanced_pkce')) {
      return;
    }

    $client_id = $this->extractClientId($request);
    if (empty($client_id)) {
      return;
    }

    // Load the client entity.
    $client = $this->loadClient($client_id);
    if (!$client) {
      return;
    }

    // Only process native clients that require enhanced PKCE.
    if (!$this->nativeClientDetector->requiresEnhancedPkce($client)) {
      return;
    }

    // Determine request type and validate accordingly.
    $path = $request->getPathInfo();
    if (strpos($path, '/oauth/authorize') !== FALSE) {
      $this->validateAuthorizationRequest($event, $client);
    }
    elseif (strpos($path, '/oauth/token') !== FALSE) {
      $this->validateTokenRequest($event, $client);
    }
  }

  /**
   * Validates OAuth authorization request PKCE parameters.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The OAuth client.
   */
  protected function validateAuthorizationRequest(RequestEvent $event, Consumer $client): void {
    $request = $event->getRequest();

    $code_challenge = $request->query->get('code_challenge') ?? $request->request->get('code_challenge');
    $code_challenge_method = $request->query->get('code_challenge_method') ?? $request->request->get('code_challenge_method', 'S256');

    // Perform enhanced PKCE validation for authorization requests.
    $validation_result = $this->pkceEnhancementService->validatePkceParameters(
      $client,
      $code_challenge,
      $code_challenge_method
    );

    if (!$validation_result['valid']) {
      $this->handleValidationFailure($event, $validation_result, 'authorization', $client);
      return;
    }

    // Add validation context to request for downstream processing.
    $request->attributes->set('oauth_pkce_validation', $validation_result);
    $request->attributes->set('oauth_enhanced_pkce_applied', TRUE);

    // Log successful validation if configured.
    if ($this->configFactory->get('simple_oauth_native_apps.settings')->get('log.pkce_validations')) {
      $this->logger->info('Enhanced PKCE validation passed for authorization request from client @client_id', [
        '@client_id' => $client->getClientId(),
        'challenge_method' => $code_challenge_method,
        'validation_context' => $validation_result,
      ]);
    }
  }

  /**
   * Validates OAuth token request PKCE parameters.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The OAuth client.
   */
  protected function validateTokenRequest(RequestEvent $event, Consumer $client): void {
    $request = $event->getRequest();

    // Only validate authorization code grants that include code_verifier.
    $grant_type = $request->request->get('grant_type');
    if ($grant_type !== 'authorization_code') {
      return;
    }

    $code_verifier = $request->request->get('code_verifier');

    // For native clients, code_verifier is mandatory.
    if (empty($code_verifier)) {
      $validation_result = [
        'valid' => FALSE,
        'errors' => ['Code verifier is mandatory for native clients'],
        'enhanced_applied' => TRUE,
      ];
      $this->handleValidationFailure($event, $validation_result, 'token', $client);
      return;
    }

    // Perform enhanced PKCE validation for token requests.
    $validation_result = $this->pkceEnhancementService->validatePkceParameters(
      $client,
      NULL,
      'S256',
      $code_verifier
    );

    if (!$validation_result['valid']) {
      $this->handleValidationFailure($event, $validation_result, 'token', $client);
      return;
    }

    // Add validation context to request for downstream processing.
    $request->attributes->set('oauth_pkce_validation', $validation_result);
    $request->attributes->set('oauth_enhanced_pkce_applied', TRUE);

    // Log successful validation if configured.
    if ($this->configFactory->get('simple_oauth_native_apps.settings')->get('log.pkce_validations')) {
      $this->logger->info('Enhanced PKCE validation passed for token request from client @client_id', [
        '@client_id' => $client->getClientId(),
        'verifier_entropy' => $validation_result['entropy_bits'] ?? 'unknown',
        'validation_context' => $validation_result,
      ]);
    }
  }

  /**
   * Handles PKCE validation failures.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   * @param array $validation_result
   *   The validation result from PKCEEnhancementService.
   * @param string $flow_type
   *   The flow type ('authorization' or 'token').
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The OAuth client.
   */
  protected function handleValidationFailure(RequestEvent $event, array $validation_result, string $flow_type, Consumer $client): void {
    $request = $event->getRequest();

    // Create comprehensive error response.
    $error_code = $flow_type === 'authorization' ? 'invalid_request' : 'invalid_grant';
    $error_description = 'PKCE validation failed: ' . implode(', ', $validation_result['errors']);

    $error_data = [
      'error' => $error_code,
      'error_description' => $error_description,
      'error_context' => [
        'flow_type' => $flow_type,
        'client_type' => 'native',
        'enhanced_pkce_applied' => TRUE,
        'validation_errors' => $validation_result['errors'],
        'security_reference' => 'https://tools.ietf.org/html/rfc7636',
      ],
    ];

    // Add developer guidance.
    $error_data['developer_guidance'] = $this->getDeveloperGuidance($validation_result['errors'], $flow_type);

    // Include warnings if present.
    if (!empty($validation_result['warnings'])) {
      $error_data['warnings'] = $validation_result['warnings'];
    }

    $headers = [
      'Content-Type' => 'application/json',
      'Cache-Control' => 'no-store',
      'Pragma' => 'no-cache',
      'X-OAuth-Error-Type' => 'pkce_validation_failure',
      'X-OAuth-Flow-Type' => $flow_type,
    ];

    $status_code = $flow_type === 'authorization' ? 400 : 400;
    $response = new JsonResponse($error_data, $status_code, $headers);
    $event->setResponse($response);

    // Log the validation failure with enhanced context.
    $this->logger->warning('Enhanced PKCE validation failed for @flow_type request from client @client_id: @errors', [
      '@flow_type' => $flow_type,
      '@client_id' => $client->getClientId(),
      '@errors' => implode(', ', $validation_result['errors']),
      'client_ip' => $request->getClientIp(),
      'request_uri' => $request->getRequestUri(),
      'user_agent' => $request->headers->get('User-Agent', 'unknown'),
      'validation_result' => $validation_result,
    ]);
  }

  /**
   * Checks if the request is an OAuth flow request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return bool
   *   TRUE if this is an OAuth authorization or token request.
   */
  protected function isOauthFlowRequest($request): bool {
    $path = $request->getPathInfo();

    // Check OAuth endpoints.
    if (strpos($path, '/oauth/authorize') !== FALSE || strpos($path, '/oauth/token') !== FALSE) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Extracts client ID from the request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return string|null
   *   The client ID if found.
   */
  protected function extractClientId($request): ?string {
    // Try query parameters first (authorization requests).
    $client_id = $request->query->get('client_id');
    if ($client_id) {
      return $client_id;
    }

    // Try POST parameters (token requests).
    $client_id = $request->request->get('client_id');
    if ($client_id) {
      return $client_id;
    }

    // Try HTTP Basic authentication.
    $auth_header = $request->headers->get('Authorization');
    if ($auth_header && strpos($auth_header, 'Basic ') === 0) {
      $credentials = base64_decode(substr($auth_header, 6));
      if ($credentials && strpos($credentials, ':') !== FALSE) {
        [$client_id] = explode(':', $credentials, 2);
        return $client_id;
      }
    }

    return NULL;
  }

  /**
   * Loads a client entity by client ID.
   *
   * @param string $client_id
   *   The client ID.
   *
   * @return \Drupal\consumers\Entity\Consumer|null
   *   The client entity or NULL if not found.
   */
  protected function loadClient(string $client_id): ?Consumer {
    $storage = $this->entityTypeManager->getStorage('consumer');
    $clients = $storage->loadByProperties(['client_id' => $client_id]);

    return $clients ? reset($clients) : NULL;
  }

  /**
   * Provides developer guidance based on validation errors.
   *
   * @param array $errors
   *   The validation errors.
   * @param string $flow_type
   *   The flow type.
   *
   * @return array
   *   Developer guidance information.
   */
  protected function getDeveloperGuidance(array $errors, string $flow_type): array {
    $guidance = [
      'general' => 'Native clients must implement RFC 7636 PKCE with enhanced security requirements.',
      'specific_issues' => [],
    ];

    foreach ($errors as $error) {
      if (strpos($error, 'S256') !== FALSE) {
        $guidance['specific_issues'][] = 'Use SHA256 challenge method (S256) exclusively for native clients. Plain method is not allowed.';
      }
      elseif (strpos($error, 'entropy') !== FALSE) {
        $guidance['specific_issues'][] = 'Ensure code verifier has sufficient entropy (minimum 128 bits). Use cryptographically secure random generation.';
      }
      elseif (strpos($error, 'format') !== FALSE) {
        $guidance['specific_issues'][] = 'Verify PKCE parameter format follows RFC 7636 requirements (base64url encoding, proper length).';
      }
      elseif (strpos($error, 'mandatory') !== FALSE) {
        $guidance['specific_issues'][] = 'PKCE parameters are mandatory for native clients. Include code_challenge in authorization requests and code_verifier in token requests.';
      }
      elseif (strpos($error, 'match') !== FALSE) {
        $guidance['specific_issues'][] = 'Code challenge must match the verifier using SHA256 method: base64url(sha256(code_verifier)).';
      }
    }

    $guidance['resources'] = [
      'rfc_7636' => 'https://tools.ietf.org/html/rfc7636',
      'rfc_8252' => 'https://tools.ietf.org/html/rfc8252',
      'oauth_security_best_practices' => 'https://tools.ietf.org/html/draft-ietf-oauth-security-topics',
    ];

    if ($flow_type === 'authorization') {
      $guidance['next_steps'] = [
        '1. Generate cryptographically secure code_verifier (43-128 characters)',
        '2. Calculate code_challenge = base64url(sha256(code_verifier))',
        '3. Include code_challenge and code_challenge_method=S256 in authorization request',
      ];
    }
    else {
      $guidance['next_steps'] = [
        '1. Include the same code_verifier used to generate the challenge',
        '2. Ensure the verifier matches the original challenge',
        '3. Verify the verifier has sufficient entropy',
      ];
    }

    return $guidance;
  }

}
