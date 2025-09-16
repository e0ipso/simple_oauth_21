<?php

namespace Drupal\simple_oauth_native_apps\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\simple_oauth_native_apps\Service\ConfigurationValidator;
use Drupal\simple_oauth_native_apps\Service\NativeClientDetector;

/**
 * Alters consumer forms to include native app settings.
 */
class ConsumerNativeAppsFormAlter {
  use StringTranslationTrait;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The configuration validator.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\ConfigurationValidator
   */
  protected ConfigurationValidator $configurationValidator;

  /**
   * The native client detector.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\NativeClientDetector
   */
  protected NativeClientDetector $clientDetector;

  /**
   * Constructs a new ConsumerNativeAppsFormAlter.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\simple_oauth_native_apps\Service\ConfigurationValidator $configuration_validator
   *   The configuration validator.
   * @param \Drupal\simple_oauth_native_apps\Service\NativeClientDetector $client_detector
   *   The native client detector.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigurationValidator $configuration_validator,
    NativeClientDetector $client_detector,
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->configurationValidator = $configuration_validator;
    $this->clientDetector = $client_detector;
  }

  /**
   * Alters the consumer form to include native app settings.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   */
  public function alterForm(array &$form, FormStateInterface $form_state, string $form_id): void {
    // Only alter consumer entity forms.
    if (!in_array($form_id, ['consumer_add_form', 'consumer_edit_form', 'consumer_form'], TRUE)) {
      return;
    }

    /** @var \Drupal\consumers\Entity\ConsumerInterface $consumer */
    $consumer = $form_state->getFormObject()->getEntity();

    // Get global settings for defaults.
    $global_config = $this->configFactory->get('simple_oauth_native_apps.settings');

    // Get current consumer-specific settings if they exist.
    $consumer_config = $this->getConsumerNativeAppsSettings($consumer->id());

    $form['native_apps'] = [
      '#type' => 'details',
      '#title' => $this->t('Native App Settings'),
      '#description' => $this->t('Configure RFC 8252 native app security settings for this specific consumer. These settings work alongside Simple OAuth PKCE module to provide comprehensive security. Leave fields empty to use global defaults.'),
      '#open' => !empty($consumer_config),
      '#tree' => TRUE,
      '#weight' => 10,
    ];

    // WebView detection override.
    $form['native_apps']['webview_detection_override'] = [
      '#type' => 'select',
      '#title' => $this->t('WebView detection policy override'),
      '#description' => $this->t('Override the global WebView detection policy for this consumer. <strong>Simple OAuth PKCE</strong> provides basic PKCE (RFC 7636) for all clients. <strong>Native Apps enhanced security</strong> adds RFC 8252 requirements including WebView detection. When disabled, native apps still get basic PKCE protection. Leave empty to use global setting (@global).', [
        '@global' => $this->getWebViewDetectionLabel($global_config->get('webview_detection')),
      ]),
      '#options' => [
        '' => $this->t('- Use global setting -'),
        'off' => $this->t('Off - Allow all requests'),
        'warn' => $this->t('Warn - Log warnings but allow requests'),
        'block' => $this->t('Block - Reject WebView requests'),
      ],
      '#default_value' => $consumer_config['webview_detection_override'] ?? '',
    ];

    // Custom redirect URI schemes override.
    $form['native_apps']['allow_custom_schemes_override'] = [
      '#type' => 'select',
      '#title' => $this->t('Allow custom URI schemes override'),
      '#description' => $this->t('Override whether to allow custom URI schemes (e.g., myapp://callback) for this consumer. Leave empty to use global setting (@global).', [
        '@global' => $global_config->get('allow_custom_uri_schemes') ? $this->t('Enabled') : $this->t('Disabled'),
      ]),
      '#options' => [
        '' => $this->t('- Use global setting -'),
        '1' => $this->t('Allow custom URI schemes'),
        '0' => $this->t('Disallow custom URI schemes'),
      ],
      '#default_value' => $consumer_config['allow_custom_schemes_override'] ?? '',
    ];

    // Loopback redirects override.
    $form['native_apps']['allow_loopback_override'] = [
      '#type' => 'select',
      '#title' => $this->t('Allow loopback redirects override'),
      '#description' => $this->t('Override whether to allow loopback IP redirects for this consumer. Leave empty to use global setting (@global).', [
        '@global' => $global_config->get('allow_loopback_redirects') ? $this->t('Enabled') : $this->t('Disabled'),
      ]),
      '#options' => [
        '' => $this->t('- Use global setting -'),
        '1' => $this->t('Allow loopback redirects'),
        '0' => $this->t('Disallow loopback redirects'),
      ],
      '#default_value' => $consumer_config['allow_loopback_override'] ?? '',
    ];

    // PKCE enforcement override.
    $form['native_apps']['enhanced_pkce_override'] = [
      '#type' => 'select',
      '#title' => $this->t('Enhanced PKCE override'),
      '#description' => $this->t('Override enhanced PKCE requirements for this consumer. Leave empty to use global setting (@global).', [
        '@global' => $global_config->get('enhanced_pkce_for_native') ? $this->t('Enabled') : $this->t('Disabled'),
      ]),
      '#options' => [
        '' => $this->t('- Use global setting -'),
        '1' => $this->t('Enable enhanced PKCE'),
        '0' => $this->t('Use standard PKCE'),
      ],
      '#default_value' => $consumer_config['enhanced_pkce_override'] ?? '',
    ];

    // Add PKCE relationship explanation.
    $form['native_apps']['pkce_relationship'] = [
      '#type' => 'details',
      '#title' => $this->t('Understanding PKCE vs Enhanced Security'),
      '#description' => $this->t('This module works alongside Simple OAuth PKCE to provide comprehensive native app security.'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $form['native_apps']['pkce_relationship']['explanation'] = [
      '#markup' => $this->t('<ul>
        <li><strong>Simple OAuth PKCE Module</strong>: Provides basic PKCE implementation (RFC 7636) for all OAuth clients</li>
        <li><strong>Native Apps Enhanced Security</strong>: Adds RFC 8252 specific requirements for native applications</li>
        <li><strong>When enhanced security is disabled</strong>: Native apps still receive basic PKCE protection but skip additional validations like WebView detection</li>
      </ul>'),
    ];

    // Add client detection section.
    $form['native_apps']['client_detection'] = [
      '#type' => 'details',
      '#title' => $this->t('Client Type Detection'),
      '#description' => $this->t('Automatic detection of client type based on redirect URIs to provide intelligent configuration defaults.'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      '#weight' => -5,
    ];

    $form['native_apps']['client_detection']['detect_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Detect Client Type'),
      '#ajax' => [
        'callback' => '::detectClientTypeAjax',
        'wrapper' => 'client-detection-results',
        'event' => 'click',
      ],
    ];

    $form['native_apps']['client_detection']['detection_results'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'client-detection-results'],
      '#markup' => $this->t('Click "Detect Client Type" to analyze redirect URIs and get configuration recommendations.'),
    ];

    // Add terminal application guidance.
    $form['native_apps']['terminal_guidance'] = [
      '#type' => 'details',
      '#title' => $this->t('Terminal Application Configuration'),
      '#description' => $this->t('Special considerations for command-line and terminal applications.'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $form['native_apps']['terminal_guidance']['info'] = [
      '#markup' => $this->t('<p>Terminal applications (command-line tools) have specific requirements:</p>
        <ul>
          <li><strong>Redirect URIs</strong>: Must use loopback addresses like "http://127.0.0.1:8080/callback"</li>
          <li><strong>Client Type</strong>: Always configure as "Public" (not confidential)</li>
          <li><strong>Custom URI Schemes</strong>: Cannot use custom schemes - loopback only</li>
          <li><strong>Browser</strong>: Should launch system browser, not embedded WebViews</li>
        </ul>'),
    ];

    // Add custom validation.
    $form['#validate'][] = [$this, 'validateConsumerNativeAppsSettings'];

    // Add custom submit handler.
    $form['actions']['submit']['#submit'][] = [$this, 'submitConsumerNativeAppsSettings'];
  }

  /**
   * Validates consumer native app settings.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateConsumerNativeAppsSettings(array $form, FormStateInterface $form_state): void {
    $values = $form_state->getValue('native_apps', []);

    // Validate the configuration.
    $config_to_validate = [];

    // Only validate non-empty overrides.
    if (!empty($values['webview_detection_override'])) {
      $config_to_validate['webview_detection'] = $values['webview_detection_override'];
    }

    if ($values['allow_custom_schemes_override'] !== '') {
      $config_to_validate['allow_custom_uri_schemes'] = (bool) $values['allow_custom_schemes_override'];
    }

    if ($values['allow_loopback_override'] !== '') {
      $config_to_validate['allow_loopback_redirects'] = (bool) $values['allow_loopback_override'];
    }

    if ($values['enhanced_pkce_override'] !== '') {
      $config_to_validate['enhanced_pkce_for_native'] = (bool) $values['enhanced_pkce_override'];
    }

    // Validate if we have any overrides.
    if (!empty($config_to_validate)) {
      $errors = $this->configurationValidator->validateConfiguration($config_to_validate);
      foreach ($errors as $error) {
        $form_state->setErrorByName('native_apps', $error);
      }
    }
  }

  /**
   * Submits consumer native app settings.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitConsumerNativeAppsSettings(array $form, FormStateInterface $form_state): void {
    /** @var \Drupal\consumers\Entity\ConsumerInterface $consumer */
    $consumer = $form_state->getFormObject()->getEntity();
    $values = $form_state->getValue('native_apps', []);

    // Save consumer-specific settings.
    $this->saveConsumerNativeAppsSettings($consumer->id(), $values);
  }

  /**
   * Gets consumer-specific native app settings.
   *
   * @param string $consumer_id
   *   The consumer ID.
   *
   * @return array
   *   The consumer-specific settings.
   */
  protected function getConsumerNativeAppsSettings(string $consumer_id): array {
    $config = $this->configFactory->get("simple_oauth_native_apps.consumer.$consumer_id");
    return $config->get() ?: [];
  }

  /**
   * Saves consumer-specific native app settings.
   *
   * @param string $consumer_id
   *   The consumer ID.
   * @param array $settings
   *   The settings to save.
   */
  protected function saveConsumerNativeAppsSettings(string $consumer_id, array $settings): void {
    // Filter out empty values to keep only actual overrides.
    $filtered_settings = array_filter($settings, function ($value) {
      return $value !== '';
    });

    $config = $this->configFactory->getEditable("simple_oauth_native_apps.consumer.$consumer_id");

    if (empty($filtered_settings)) {
      // Delete the config if no overrides are set.
      $config->delete();
    }
    else {
      // Save the filtered settings.
      $config->setData($filtered_settings)->save();
    }
  }

  /**
   * Gets a human-readable label for a WebView detection setting.
   *
   * @param string $setting
   *   The setting value.
   *
   * @return string
   *   The human-readable label.
   */
  protected function getWebViewDetectionLabel(string $setting): string {
    $labels = [
      'off' => $this->t('Off'),
      'warn' => $this->t('Warn'),
      'block' => $this->t('Block'),
    ];

    return $labels[$setting] ?? $this->t('Unknown');
  }

  /**
   * AJAX callback for client type detection.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function detectClientTypeAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    // Get redirect URIs from form.
    $redirect_uris = $this->getRedirectUrisFromForm($form_state);

    if (empty($redirect_uris)) {
      $markup = '<div class="messages messages--warning">' .
                $this->t('Please enter redirect URIs first in the main form to enable client type detection.') .
                '</div>';
    }
    else {
      $detection_result = $this->clientDetector->detectClientType($redirect_uris);
      $markup = $this->renderDetectionResult($detection_result, $redirect_uris);
    }

    $response->addCommand(new ReplaceCommand('#client-detection-results', $markup));

    return $response;
  }

  /**
   * Extracts redirect URIs from form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   Array of redirect URIs.
   */
  protected function getRedirectUrisFromForm(FormStateInterface $form_state): array {
    // Try to get redirect URIs from various possible field names.
    $redirect_field = $form_state->getValue('redirect')
      ?? $form_state->getValue('redirect_uri')
      ?? $form_state->getValue('redirect_uris');

    if (is_string($redirect_field)) {
      return array_filter(array_map('trim', explode("\n", $redirect_field)));
    }
    elseif (is_array($redirect_field)) {
      // Handle field collections or multiple values.
      $uris = [];
      foreach ($redirect_field as $item) {
        if (is_string($item)) {
          $uris[] = trim($item);
        }
        elseif (is_array($item) && isset($item['value'])) {
          $uris[] = trim($item['value']);
        }
      }
      return array_filter($uris);
    }

    return [];
  }

  /**
   * Renders the client detection result.
   *
   * @param array $result
   *   The detection result.
   * @param array $redirect_uris
   *   The redirect URIs that were analyzed.
   *
   * @return string
   *   The rendered HTML.
   */
  protected function renderDetectionResult(array $result, array $redirect_uris): string {
    $type = $result['type'];
    $confidence = $result['confidence'];
    $details = $result['details'] ?? [];

    $markup = '<div class="client-detection-result confidence-' . $confidence . '">';
    $markup .= '<h4>' . $this->t('Detection Result') . '</h4>';
    $markup .= '<p><strong>' . $this->t('Detected Client Type:') . '</strong> ' . ucfirst($type) . '</p>';
    $markup .= '<p><strong>' . $this->t('Detection Confidence:') . '</strong> ' . ucfirst($confidence) . '</p>';

    if (isset($details['description'])) {
      $markup .= '<p><strong>' . $this->t('Description:') . '</strong> ' . $details['description'] . '</p>';
    }

    // Show analyzed URIs.
    $markup .= '<div class="analyzed-uris">';
    $markup .= '<h5>' . $this->t('Analyzed Redirect URIs:') . '</h5>';
    $markup .= '<ul>';
    foreach ($redirect_uris as $uri) {
      $markup .= '<li><code>' . htmlspecialchars($uri) . '</code></li>';
    }
    $markup .= '</ul>';
    $markup .= '</div>';

    // Add recommendations.
    $recommendations = $this->getRecommendations($result);
    if (!empty($recommendations)) {
      $markup .= '<div class="recommendations">';
      $markup .= '<h5>' . $this->t('Configuration Recommendations:') . '</h5>';
      $markup .= '<ul>';
      foreach ($recommendations as $recommendation) {
        $markup .= '<li>' . $recommendation . '</li>';
      }
      $markup .= '</ul>';
      $markup .= '</div>';
    }

    // Add mixed types warning if applicable.
    if (isset($result['mixed_types']) && count($result['mixed_types']) > 1) {
      $markup .= '<div class="messages messages--warning">';
      $markup .= '<strong>' . $this->t('Mixed Client Types Detected:') . '</strong> ';
      $markup .= $this->t('Your redirect URIs suggest multiple client types (@types). Consider using consistent URI patterns for better security.', [
        '@types' => implode(', ', $result['mixed_types']),
      ]);
      $markup .= '</div>';
    }

    $markup .= '</div>';

    return $markup;
  }

  /**
   * Gets configuration recommendations based on detection result.
   *
   * @param array $result
   *   The detection result.
   *
   * @return array
   *   Array of recommendation strings.
   */
  protected function getRecommendations(array $result): array {
    $type = $result['type'];
    $recommendations = [];

    switch ($type) {
      case 'terminal':
        $recommendations[] = $this->t('Set "Is Confidential" to <strong>No</strong> (terminal apps cannot store secrets securely)');
        $recommendations[] = $this->t('Use loopback redirects like <code>http://127.0.0.1:8080/callback</code>');
        $recommendations[] = $this->t('Enable PKCE for enhanced security');
        $recommendations[] = $this->t('Disable custom URI schemes (not supported by terminal apps)');
        $recommendations[] = $this->t('Launch system browser for authentication, not embedded web views');
        break;

      case 'mobile':
        $recommendations[] = $this->t('Set "Is Confidential" to <strong>No</strong> (mobile apps are public clients)');
        $recommendations[] = $this->t('Use custom URI schemes like <code>myapp://callback</code>');
        $recommendations[] = $this->t('Enable enhanced PKCE for native app security');
        $recommendations[] = $this->t('Consider enabling WebView detection for additional security');
        $recommendations[] = $this->t('Register URI scheme with mobile OS for deep linking');
        break;

      case 'desktop':
        $recommendations[] = $this->t('Set "Is Confidential" to <strong>No</strong> (desktop apps are typically public clients)');
        $recommendations[] = $this->t('Use custom URI schemes or loopback redirects');
        $recommendations[] = $this->t('Enable enhanced PKCE for native app security');
        $recommendations[] = $this->t('Consider platform-specific URI scheme registration');
        break;

      case 'web':
        $recommendations[] = $this->t('Consider setting "Is Confidential" to <strong>Yes</strong> if server-side application');
        $recommendations[] = $this->t('Use HTTPS redirect URIs for security');
        $recommendations[] = $this->t('PKCE is optional but recommended for additional security');
        $recommendations[] = $this->t('Ensure redirect URIs match exactly with client configuration');
        break;
    }

    return $recommendations;
  }

}
