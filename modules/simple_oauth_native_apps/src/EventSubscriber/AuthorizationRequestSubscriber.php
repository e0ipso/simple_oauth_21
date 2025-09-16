<?php

namespace Drupal\simple_oauth_native_apps\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\simple_oauth_native_apps\Service\UserAgentAnalyzer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Event subscriber for native app authorization request security.
 *
 * Implements RFC 8252 security recommendations by detecting embedded
 * webviews in OAuth authorization flows and applying configurable
 * security responses (warn, block).
 */
class AuthorizationRequestSubscriber implements EventSubscriberInterface {

  /**
   * The user agent analyzer service.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\UserAgentAnalyzer
   */
  protected UserAgentAnalyzer $userAgentAnalyzer;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Constructs an AuthorizationRequestSubscriber.
   *
   * @param \Drupal\simple_oauth_native_apps\Service\UserAgentAnalyzer $user_agent_analyzer
   *   The user agent analyzer service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   */
  public function __construct(UserAgentAnalyzer $user_agent_analyzer, ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler) {
    $this->userAgentAnalyzer = $user_agent_analyzer;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    // Priority 100 to run before OAuth processing.
    $events[KernelEvents::REQUEST][] = ['onAuthorizationRequest', 100];
    return $events;
  }

  /**
   * Handles authorization requests for webview detection.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onAuthorizationRequest(RequestEvent $event): void {
    $request = $event->getRequest();

    // Only process OAuth authorization requests.
    if (!$this->isOauthAuthorizationRequest($request)) {
      return;
    }

    // Skip if webview detection is disabled.
    if (!$this->userAgentAnalyzer->isDetectionEnabled()) {
      return;
    }

    $userAgent = $request->headers->get('User-Agent', '');
    if (empty($userAgent)) {
      return;
    }

    // Check for embedded webview.
    if ($this->userAgentAnalyzer->isEmbeddedWebview($userAgent)) {
      $this->handleWebviewDetection($event, $userAgent);
    }
  }

  /**
   * Checks if the request is an OAuth authorization request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return bool
   *   TRUE if this is an OAuth authorization request.
   */
  private function isOauthAuthorizationRequest($request): bool {
    $path = $request->getPathInfo();

    // Check if this is the OAuth authorization endpoint.
    if (strpos($path, '/oauth/authorize') !== FALSE) {
      return TRUE;
    }

    // Check for OAuth parameters that indicate authorization request.
    $oauthParams = [
      'response_type',
      'client_id',
      'redirect_uri',
      'scope',
    ];

    $hasOAuthParams = FALSE;
    foreach ($oauthParams as $param) {
      if ($request->query->has($param) || $request->request->has($param)) {
        $hasOAuthParams = TRUE;
        break;
      }
    }

    return $hasOAuthParams;
  }

  /**
   * Handles webview detection based on configuration.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   * @param string $userAgent
   *   The user agent string.
   */
  private function handleWebviewDetection(RequestEvent $event, string $userAgent): void {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');
    $webviewDetection = $config->get('webview.detection', 'warn');

    $webviewInfo = $this->userAgentAnalyzer->getWebviewType($userAgent);
    $webviewType = $webviewInfo['category'] ?? 'generic';

    // Check whitelist first.
    if ($this->isUserAgentWhitelisted($userAgent)) {
      return;
    }

    switch ($webviewDetection) {
      case 'off':
        // Do nothing.
        break;

      case 'warn':
        $this->addSecurityWarningHeaders($event, $webviewType, $userAgent);
        break;

      case 'block':
        $this->blockWebviewRequest($event, $webviewType, $userAgent);
        break;
    }
  }

  /**
   * Checks if the user agent is whitelisted.
   *
   * @param string $userAgent
   *   The user agent string.
   *
   * @return bool
   *   TRUE if the user agent is whitelisted.
   */
  private function isUserAgentWhitelisted(string $userAgent): bool {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');
    $whitelist = $config->get('webview.whitelist') ?? [];

    foreach ($whitelist as $pattern) {
      if (preg_match('/' . preg_quote($pattern, '/') . '/i', $userAgent)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Adds security warning headers without blocking the request.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   * @param string $webviewType
   *   The detected webview type.
   * @param string $userAgent
   *   The user agent string.
   */
  private function addSecurityWarningHeaders(RequestEvent $event, string $webviewType, string $userAgent): void {
    $request = $event->getRequest();

    // Add security warning attributes to the request for later processing.
    $request->attributes->set('oauth_webview_warning', TRUE);
    $request->attributes->set('oauth_webview_type', $webviewType);
    $request->attributes->set('oauth_security_headers', $this->generateSecurityHeaders($webviewType, $userAgent));
  }

  /**
   * Blocks the webview request with a developer-friendly error.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   * @param string $webviewType
   *   The detected webview type.
   * @param string $userAgent
   *   The user agent string.
   */
  private function blockWebviewRequest(RequestEvent $event, string $webviewType, string $userAgent): void {
    $errorMessage = $this->createBlockingErrorMessage($webviewType);

    // Create comprehensive error response.
    $errorData = [
      'error' => 'embedded_webview_blocked',
      'error_description' => $errorMessage,
      'webview_type' => $webviewType,
      'user_agent_detected' => $userAgent,
      'recommendation' => $this->getDeveloperRecommendation($webviewType),
      'security_reference' => 'https://tools.ietf.org/html/rfc8252#section-8.12',
      'support_info' => [
        'contact' => 'Please contact the application developer for assistance.',
        'alternative_flow' => 'Try opening this link in your device\'s default browser.',
      ],
    ];

    // Add custom message if configured.
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');
    $customMessage = $config->get('webview.custom_message');
    if (!empty($customMessage)) {
      $errorData['custom_message'] = $customMessage;
    }

    $headers = array_merge(
      $this->generateSecurityHeaders($webviewType, $userAgent),
      ['Content-Type' => 'application/json']
    );

    $response = new JsonResponse($errorData, 400, $headers);
    $event->setResponse($response);
  }

  /**
   * Generates security headers for webview responses.
   *
   * @param string $webviewType
   *   The detected webview type.
   * @param string $userAgent
   *   The user agent string.
   *
   * @return array
   *   Array of security headers.
   */
  private function generateSecurityHeaders(string $webviewType, string $userAgent): array {
    return [
      'X-OAuth-Security-Warning' => 'embedded-webview-detected',
      'X-OAuth-Webview-Type' => $webviewType,
      'X-OAuth-Security-Recommendation' => 'use-external-browser',
      'X-OAuth-Developer-Message' => $this->createDeveloperWarningMessage($webviewType),
      'X-OAuth-RFC-Reference' => 'https://tools.ietf.org/html/rfc8252#section-8.12',
    ];
  }

  /**
   * Creates a developer-friendly warning message.
   *
   * @param string $webviewType
   *   The detected webview type.
   *
   * @return string
   *   The developer warning message.
   */
  private function createDeveloperWarningMessage(string $webviewType): string {
    $messages = [
      'social_media' => 'Social media in-app browser detected. Consider using SFSafariViewController (iOS) or Custom Tabs (Android) for better security.',
      'ios_native' => 'iOS WebView detected. Consider using ASWebAuthenticationSession or SFSafariViewController for OAuth flows.',
      'android_native' => 'Android WebView detected. Consider using Custom Tabs or Chrome Custom Tabs for better security.',
      'messaging_browsers' => 'Messaging app browser detected. Redirect to external browser for enhanced security.',
      'other_apps' => 'In-app browser detected. Consider implementing external browser OAuth flow.',
      'custom' => 'Custom webview pattern detected. Review OAuth implementation for security compliance.',
      'generic' => 'Embedded webview detected. RFC 8252 recommends using external user-agent for OAuth.',
    ];

    return $messages[$webviewType] ?? $messages['generic'];
  }

  /**
   * Creates a blocking error message based on webview type.
   *
   * @param string $webviewType
   *   The detected webview type.
   *
   * @return string
   *   The blocking error message.
   */
  private function createBlockingErrorMessage(string $webviewType): string {
    $messages = [
      'social_media' => 'OAuth authentication is not allowed in social media in-app browsers for security reasons.',
      'ios_native' => 'OAuth authentication in embedded iOS WebView is not allowed per RFC 8252 security requirements.',
      'android_native' => 'OAuth authentication in embedded Android WebView is not allowed per RFC 8252 security requirements.',
      'messaging_browsers' => 'OAuth authentication in messaging app browsers is not allowed for security reasons.',
      'other_apps' => 'OAuth authentication in embedded app browsers is not allowed per RFC 8252 security requirements.',
      'custom' => 'OAuth authentication in this webview is blocked by security policy.',
      'generic' => 'OAuth authentication in embedded webview is not allowed per RFC 8252 security requirements.',
    ];

    return $messages[$webviewType] ?? $messages['generic'];
  }

  /**
   * Gets developer recommendations for webview alternatives.
   *
   * @param string $webviewType
   *   The detected webview type.
   *
   * @return string
   *   The developer recommendation.
   */
  private function getDeveloperRecommendation(string $webviewType): string {
    $recommendations = [
      'social_media' => 'Implement deep linking to redirect users to their default browser, or use platform-specific secure web view components.',
      'ios_native' => 'Use ASWebAuthenticationSession or SFSafariViewController instead of WKWebView for OAuth flows.',
      'android_native' => 'Use Chrome Custom Tabs or the default browser instead of WebView for OAuth flows.',
      'messaging_browsers' => 'Implement external browser redirection for OAuth authentication.',
      'other_apps' => 'Use system browser or platform-specific secure authentication components.',
      'custom' => 'Review your OAuth implementation and consider using external browser or secure system components.',
      'generic' => 'Follow RFC 8252 guidelines and use external user-agent (system browser) for OAuth authentication.',
    ];

    return $recommendations[$webviewType] ?? $recommendations['generic'];
  }

}
