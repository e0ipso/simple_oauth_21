<?php

namespace Drupal\simple_oauth_native_apps\Form;

use Drupal\simple_oauth_native_apps\Service\UserAgentAnalyzer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\RedundantEditableConfigNamesTrait;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\simple_oauth_native_apps\Service\ConfigurationValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Simple OAuth Native Apps settings.
 */
class NativeAppsSettingsForm extends ConfigFormBase {

  use RedundantEditableConfigNamesTrait;

  /**
   * The configuration validator.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\ConfigurationValidator
   */
  protected ConfigurationValidator $configurationValidator;

  /**
   * The user agent analyzer service.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\UserAgentAnalyzer
   */
  protected $userAgentAnalyzer;

  /**
   * Constructs a new NativeAppsSettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\simple_oauth_native_apps\Service\ConfigurationValidator $configuration_validator
   *   The configuration validator service.
   * @param \Drupal\simple_oauth_native_apps\Service\UserAgentAnalyzer $user_agent_analyzer
   *   The user agent analyzer service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    MessengerInterface $messenger,
    ConfigurationValidator $configuration_validator,
    UserAgentAnalyzer $user_agent_analyzer,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->messenger = $messenger;
    $this->configurationValidator = $configuration_validator;
    $this->userAgentAnalyzer = $user_agent_analyzer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('messenger'),
      $container->get('simple_oauth_native_apps.configuration_validator'),
      $container->get('simple_oauth_native_apps.user_agent_analyzer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'simple_oauth_native_apps_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['simple_oauth_native_apps.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('simple_oauth_native_apps.settings');

    $form['#tree'] = TRUE;

    // Module status section removed per request.
    // Global security enforcement.
    $form['security'] = [
      '#type' => 'details',
      '#title' => $this->t('Security Settings'),
      '#open' => TRUE,
    ];

    $form['security']['enforce_native_security'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enforce native app security'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Enable RFC 8252 native app security requirements for comprehensive protection. This adds WebView detection, strict redirect URI validation, and enhanced PKCE requirements beyond basic OAuth 2.0. (<a href="https://datatracker.ietf.org/doc/html/rfc8252#section-8" target="_blank">RFC 8252 Section 8</a>)'),
      '#default_value' => $config->get('enforce_native_security'),
    ];

    // WebView Detection Settings.
    $form['webview'] = [
      '#type' => 'details',
      '#title' => $this->t('WebView Detection'),
      '#description' => $this->t('Configure how to handle authorization requests from embedded WebViews. RFC 8252 recommends against using embedded WebViews for OAuth flows.'),
      '#open' => TRUE,
    ];

    $form['webview']['detection'] = [
      '#type' => 'radios',
      '#title' => $this->t('WebView detection policy'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Set to "Block" to prevent WebView-based attacks in native applications. RFC 8252 explicitly recommends against embedded WebViews for OAuth flows due to security vulnerabilities. (<a href="https://datatracker.ietf.org/doc/html/rfc8252#section-8.12" target="_blank">RFC 8252 Section 8.12</a>)'),
      '#options' => [
        'off' => $this->t('Off - Allow all requests (not recommended)'),
        'warn' => $this->t('Warn - Add headers but allow requests'),
        'block' => $this->t('Block - Reject WebView requests (recommended)'),
      ],
      '#default_value' => $config->get('webview.detection'),
      '#required' => TRUE,
    ];

    $form['webview']['custom_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom WebView warning message'),
      '#description' => $this->t('Custom message to display when embedded WebView is detected. Leave empty to use the default message.'),
      '#default_value' => $config->get('webview.custom_message'),
      '#rows' => 3,
      '#states' => [
        'visible' => [
          ':input[name="webview[detection]"]' => ['!value' => 'off'],
        ],
      ],
    ];

    $form['webview']['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced WebView Detection'),
      '#open' => FALSE,
    ];

    // Display current built-in patterns.
    $form['webview']['advanced']['builtin_patterns'] = [
      '#type' => 'details',
      '#title' => $this->t('Built-in WebView patterns (automatically blocked)'),
      '#description' => $this->t('These patterns are built into the module and automatically detect embedded WebViews. The text areas below are for adding <strong>additional</strong> custom patterns.'),
      '#open' => FALSE,
    ];

    $form['webview']['advanced']['builtin_patterns']['pattern_list'] = [
      '#markup' => $this->getBuiltinPatternsMarkup(),
    ];

    $form['webview']['advanced']['whitelist'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional WebView whitelist patterns'),
      '#description' => $this->t('<strong>Additional</strong> user-agent patterns to whitelist (bypass WebView detection). These patterns will override the built-in detection above. Enter one pattern per line. Use regular expressions.'),
      '#default_value' => implode("\n", $config->get('webview.whitelist') ?? []),
      '#rows' => 4,
      '#placeholder' => "MyTrustedApp/.*\nCompanyApp/[0-9.]+",
    ];

    $form['webview']['advanced']['patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional WebView detection patterns'),
      '#description' => $this->t('<strong>Additional</strong> user-agent patterns to detect as embedded WebViews beyond the built-in patterns shown above. Enter one pattern per line. Use regular expressions.'),
      '#default_value' => implode("\n", $config->get('webview.patterns') ?? []),
      '#rows' => 4,
      '#placeholder' => "SuspiciousWebView/.*\nEmbeddedBrowser/.*",
    ];

    // Redirect URI Validation Settings.
    $form['redirect_uri'] = [
      '#type' => 'details',
      '#title' => $this->t('Redirect URI Validation'),
      '#description' => $this->t('Configure redirect URI validation methods and security levels for native applications.'),
      '#open' => TRUE,
    ];

    $form['require_exact_redirect_match'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require exact redirect URI match'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Exact redirect URI matching prevents redirect manipulation attacks. This is essential for native app security and recommended for maximum protection. (<a href="https://datatracker.ietf.org/doc/draft-ietf-oauth-v2-1/#section-4.1.3" target="_blank">OAuth 2.1 Draft Section 4.1.3</a>)'),
      '#default_value' => $config->get('require_exact_redirect_match'),
    ];

    $form['allow'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    $form['allow']['custom_uri_schemes'] = [
      '#type' => 'radios',
      '#title' => $this->t('Allow custom URI schemes'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Custom URI schemes (e.g., myapp://callback) are the preferred redirect method for native applications. This provides better security than web-based redirects for mobile and desktop apps. (<a href="https://datatracker.ietf.org/doc/html/rfc8252#section-7.1" target="_blank">RFC 8252 Section 7.1</a>)'),
      '#options' => [
        'auto-detect' => $this->t('Auto-detect - Based on client type detection'),
        'native' => $this->t('Native - Allow custom URI schemes'),
        'web' => $this->t('Web - Disallow custom URI schemes'),
      ],
      '#default_value' => $config->get('allow.custom_uri_schemes') ?? 'auto-detect',
      '#required' => TRUE,
    ];

    $form['allow']['loopback_redirects'] = [
      '#type' => 'radios',
      '#title' => $this->t('Allow loopback redirects'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Loopback redirects (e.g., http://127.0.0.1:8080/callback) are essential for command-line and desktop applications. RFC 8252 recommends this for native apps that cannot use custom URI schemes. (<a href="https://datatracker.ietf.org/doc/html/rfc8252#section-7.3" target="_blank">RFC 8252 Section 7.3</a>)'),
      '#options' => [
        'auto-detect' => $this->t('Auto-detect - Based on client type detection'),
        'native' => $this->t('Native - Allow loopback redirects'),
        'web' => $this->t('Web - Disallow loopback redirects'),
      ],
      '#default_value' => $config->get('allow.loopback_redirects') ?? 'auto-detect',
      '#required' => TRUE,
    ];

    // PKCE Settings.
    $form['pkce'] = [
      '#type' => 'details',
      '#title' => $this->t('PKCE (Proof Key for Code Exchange)'),
      '#description' => $this->t('Configure enhanced PKCE requirements that work alongside the Simple OAuth PKCE module.'),
      '#open' => TRUE,
    ];

    $form['pkce']['pkce_relationship'] = [
      '#type' => 'item',
      '#title' => $this->t('Understanding PKCE vs Enhanced Security'),
      '#description' => $this->t('<ul>
        <li><strong>Simple OAuth PKCE Module:</strong> Provides basic PKCE implementation (RFC 7636) for all OAuth clients</li>
        <li><strong>Native Apps Enhanced Security:</strong> Adds RFC 8252 specific requirements for native applications</li>
        <li><strong>When enhanced security is disabled:</strong> Native apps still receive basic PKCE protection but skip additional validations like WebView detection</li>
      </ul>'),
    ];

    $form['native'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    $form['native']['enhanced_pkce'] = [
      '#type' => 'radios',
      '#title' => $this->t('Enhanced PKCE for native apps'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Enhanced PKCE enforces mandatory S256 challenge method and stricter validation for native applications. This goes beyond basic PKCE to meet OAuth 2.1 security requirements for native apps. (<a href="https://datatracker.ietf.org/doc/html/rfc8252#section-8.1" target="_blank">RFC 8252 Section 8.1</a>)'),
      '#options' => [
        'auto-detect' => $this->t('Auto-detect - Based on client type detection'),
        'enhanced' => $this->t('Enhanced - Apply enhanced PKCE for all clients'),
        'not-enhanced' => $this->t('Not Enhanced - Use standard PKCE'),
      ],
      '#default_value' => $config->get('native.enhanced_pkce') ?? 'auto-detect',
      '#required' => TRUE,
    ];

    $form['native']['enforce'] = [
      '#type' => 'radios',
      '#title' => $this->t('Enforce challenge method for native apps'),
      '#description' => $this->t('🔒 <strong>Critical Security Setting:</strong> Specifies which PKCE challenge method native clients must use. S256 is strongly recommended for security. This setting prevents the configuration errors that can cause OAuth validation failures.'),
      '#options' => [
        'off' => $this->t('Off - Allow any method (not recommended)'),
        'S256' => $this->t('S256 - Require SHA256 method (recommended)'),
        'plain' => $this->t('Plain - Require plain method (not recommended)'),
      ],
      '#default_value' => $config->get('native.enforce') ?? 'S256',
      '#required' => TRUE,
    ];

    // Add help text.
    $form['help'] = [
      '#type' => 'details',
      '#title' => $this->t('Help & Information'),
      '#open' => FALSE,
    ];

    $form['help']['info'] = [
      '#markup' => $this->t('<p><strong>About RFC 8252 Native Apps</strong></p>
        <p>This module implements security recommendations from RFC 8252 "OAuth 2.0 for Native Apps". Key features include:</p>
        <ul>
          <li><strong>WebView Detection:</strong> Identifies and optionally blocks embedded WebView authorization attempts</li>
          <li><strong>Enhanced Redirect URI Validation:</strong> Supports custom URI schemes and loopback redirects</li>
          <li><strong>PKCE Enhancement:</strong> Provides additional PKCE validation for native applications</li>
          <li><strong>Flexible Configuration:</strong> Allows per-client overrides of global settings</li>
        </ul>
        <p>For more information, see <a href="https://tools.ietf.org/html/rfc8252" target="_blank">RFC 8252</a>.</p>'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $values = $form_state->getValues();

    // Convert form values to config format for validation.
    $config_values = $this->convertFormValuesToConfig($values);

    // Use the configuration validator service.
    $errors = $this->configurationValidator->validateConfiguration($config_values);

    // Set form errors for validation failures.
    foreach ($errors as $error) {
      $form_state->setErrorByName('', $error);
    }

    // Additional form-specific validations.
    $this->validateFormSpecificRules($values, $form_state);
  }

  /**
   * Converts form values to configuration format.
   *
   * Form structure now matches config structure, only textarea arrays need
   * conversion.
   *
   * @param array $values
   *   Form values.
   *
   * @return array
   *   Configuration array.
   */
  protected function convertFormValuesToConfig(array $values): array {
    $config = [
      'enforce_native_security' => $values['security']['enforce_native_security'] ?? FALSE,
      'webview' => [
        'detection' => $values['webview']['detection'] ?? 'warn',
        'custom_message' => $values['webview']['custom_message'] ?? '',
        'whitelist' => !empty($values['webview']['advanced']['whitelist'])
          ? array_filter(array_map('trim', explode("\n", $values['webview']['advanced']['whitelist'])))
          : [],
        'patterns' => !empty($values['webview']['advanced']['patterns'])
          ? array_filter(array_map('trim', explode("\n", $values['webview']['advanced']['patterns'])))
          : [],
      ],
      'require_exact_redirect_match' => $values['require_exact_redirect_match'] ?? TRUE,
      'allow' => $values['allow'] ?? [],
      'native' => $values['native'] ?? [],
    ];

    return $config;
  }

  /**
   * Validates form-specific rules and provides warnings.
   *
   * @param array $values
   *   Form values.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function validateFormSpecificRules(array $values, FormStateInterface $form_state): void {
    // Critical validation: Ensure enforce method is set.
    if (empty($values['native']['enforce'])) {
      $form_state->setErrorByName('native][enforce', $this->t('PKCE challenge method enforcement is required. This prevents configuration errors that cause OAuth validation failures.'));
    }

    // Logical validation: Enhanced PKCE with enforce method.
    if ($values['native']['enhanced_pkce'] === 'enhanced' && $values['native']['enforce'] === 'off') {
      $form_state->setErrorByName('native][enforce', $this->t('Enhanced PKCE is enabled but challenge method enforcement is off. Enhanced PKCE requires method enforcement to function properly.'));
    }

    // Security validation: Plain method warning.
    if ($values['native']['enforce'] === 'plain') {
      $this->messenger->addWarning($this->t('Plain PKCE method is not recommended for production use. S256 provides better security for native applications.'));
    }

    // Warning if security is not enforced.
    if (!$values['security']['enforce_native_security']) {
      $this->messenger->addWarning($this->t('Enhanced native security is disabled. Native apps will receive basic PKCE protection (RFC 7636) but skip WebView detection and strict redirect validation (RFC 8252).'));
    }

    // Warning if WebView detection is off.
    if ($values['webview']['detection'] === 'off') {
      $this->messenger->addWarning($this->t('WebView detection is disabled. This is not recommended as it may allow insecure embedded WebView authorization flows.'));
    }

    // Information message for enhanced PKCE.
    if ($values['native']['enhanced_pkce'] === 'enhanced') {
      $this->messenger->addMessage($this->t('Enhanced PKCE is enabled, providing additional security for native applications.'));
    }
    elseif ($values['native']['enhanced_pkce'] === 'auto-detect') {
      $this->messenger->addMessage($this->t('Enhanced PKCE is set to auto-detect mode, which will apply enhanced PKCE based on client type detection.'));
    }

    // Success message for secure configuration.
    if ($values['native']['enforce'] === 'S256' && $values['native']['enhanced_pkce'] !== 'not-enhanced') {
      $this->messenger->addMessage($this->t('Secure PKCE configuration detected: S256 method enforcement with enhanced validation.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $config = $this->config('simple_oauth_native_apps.settings');

    // Save security settings.
    $config->set('enforce_native_security', (bool) $values['security']['enforce_native_security']);

    // Save WebView settings.
    $config->set('webview.detection', $values['webview']['detection']);
    $config->set('webview.custom_message', $values['webview']['custom_message']);

    // Process and save WebView patterns.
    $webview_whitelist = !empty($values['webview']['advanced']['whitelist'])
      ? array_filter(array_map('trim', explode("\n", $values['webview']['advanced']['whitelist'])))
      : [];
    $config->set('webview.whitelist', $webview_whitelist);

    $webview_patterns = !empty($values['webview']['advanced']['patterns'])
      ? array_filter(array_map('trim', explode("\n", $values['webview']['advanced']['patterns'])))
      : [];
    $config->set('webview.patterns', $webview_patterns);

    // Save redirect URI settings.
    $config->set('require_exact_redirect_match', (bool) $values['require_exact_redirect_match']);
    $config->set('allow.custom_uri_schemes', $values['allow']['custom_uri_schemes']);
    $config->set('allow.loopback_redirects', $values['allow']['loopback_redirects']);

    // Save PKCE settings.
    $config->set('native.enhanced_pkce', $values['native']['enhanced_pkce']);
    $config->set('native.enforce', $values['native']['enforce']);

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Generates markup for displaying built-in WebView patterns.
   *
   * @return string
   *   HTML markup showing the patterns organized by category.
   */
  protected function getBuiltinPatternsMarkup(): string {
    // Get the pattern statistics from the UserAgentAnalyzer service.
    $stats = $this->userAgentAnalyzer->getPatternStatistics();

    // Create organized display of pattern categories.
    $categories = [
      'ios_native' => $this->t('iOS Native WebViews'),
      'android_native' => $this->t('Android Native WebViews'),
      'social_media' => $this->t('Social Media Apps'),
      'messaging_browsers' => $this->t('Messaging & Browser Apps'),
      'other_apps' => $this->t('Other Mobile Apps'),
      'cross_platform_frameworks' => $this->t('Cross-Platform Frameworks'),
    ];

    $output = '<div class="webview-patterns-summary">';
    $output .= '<p><strong>' . $this->t('Pattern Summary:') . '</strong></p>';
    $output .= '<ul>';

    foreach ($categories as $category => $label) {
      $count = $stats[$category] ?? 0;
      if ($count > 0) {
        $output .= '<li>' . $label . ': <strong>' . $count . '</strong> ' . $this->formatPlural($count, 'pattern', 'patterns') . '</li>';
      }
    }

    $safe_count = $stats['safe_browsers'] ?? 0;
    $output .= '<li>' . $this->t('Safe Browser Patterns: <strong>@count</strong> @plural', [
      '@count' => $safe_count,
      '@plural' => $this->formatPlural($safe_count, 'pattern', 'patterns'),
    ]) . '</li>';
    $output .= '</ul>';

    $output .= '<p><em>' . $this->t('Total: @total detection patterns built-in', ['@total' => $stats['total'] - $safe_count]) . '</em></p>';

    // Add examples of what gets detected.
    $output .= '<details class="webview-examples"><summary>' . $this->t('Examples of detected apps') . '</summary>';
    $output .= '<ul>';
    $output .= '<li>' . $this->t('<strong>Social Media:</strong> Facebook, Instagram, Twitter, LinkedIn, WhatsApp, TikTok') . '</li>';
    $output .= '<li>' . $this->t('<strong>Messaging:</strong> WeChat, Line, Telegram, Baidu Browser') . '</li>';
    $output .= '<li>' . $this->t('<strong>Mobile Frameworks:</strong> Cordova, PhoneGap, Ionic, React Native, Electron, Capacitor') . '</li>';
    $output .= '<li>' . $this->t('<strong>Native WebViews:</strong> Android WebView, iOS WKWebView/UIWebView') . '</li>';
    $output .= '</ul></details>';

    $output .= '</div>';

    return $output;
  }

}
