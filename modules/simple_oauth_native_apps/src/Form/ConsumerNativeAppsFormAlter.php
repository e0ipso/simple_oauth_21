<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_native_apps\Form;

use Drupal\Component\Utility\Html;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AnnounceCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\simple_oauth_native_apps\Service\ConfigurationValidator;
use Drupal\simple_oauth_native_apps\Service\ConsumerPolicyResolver;
use Drupal\simple_oauth_native_apps\Service\NativeClientDetector;
use Drupal\simple_oauth_native_apps\Service\RedirectPolicyValue;

/**
 * Alters consumer forms to include native app settings.
 */
final class ConsumerNativeAppsFormAlter {
  use DependencySerializationTrait;
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
   * The shared policy resolver and write boundary.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\ConsumerPolicyResolver
   */
  protected ConsumerPolicyResolver $policyResolver;

  /**
   * Whether service wiring opts new forms into automatic stable binding.
   *
   * @var bool
   */
  protected bool $bindNewPolicies;

  /**
   * The database for atomic entity and policy form submission, when available.
   *
   * @var \Drupal\Core\Database\Connection|null
   */
  protected ?Connection $database;

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
   * @param \Drupal\simple_oauth_native_apps\Service\ConsumerPolicyResolver|null $policy_resolver
   *   The policy boundary; NULL keeps original unbound constructor behavior.
   * @param \Drupal\Core\Database\Connection|null $database
   *   The database, or NULL for original programmatic constructor calls.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigurationValidator $configuration_validator,
    NativeClientDetector $client_detector,
    ?ConsumerPolicyResolver $policy_resolver = NULL,
    ?Connection $database = NULL,
  ) {
    $this->database = $database;
    $this->bindNewPolicies = $policy_resolver !== NULL;
    $this->policyResolver = $policy_resolver ?? new ConsumerPolicyResolver($config_factory);
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

    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }
    $consumer = $form_object->getEntity();
    if (!$consumer instanceof ConsumerInterface) {
      return;
    }

    // Modify the confidential field description to warn about auto-detection.
    if (isset($form['confidential'])) {
      $original_description = $form['confidential']['widget']['value']['#description'] ?? '';
      $additional_info = $this->t('<strong>Native Apps:</strong> Confidential consumers will be auto-detected as Web applications. Native apps (mobile/desktop/terminal) should typically be Public clients.');

      if (empty($original_description)) {
        $form['confidential']['widget']['value']['#description'] = $additional_info;
      }
      else {
        $form['confidential']['widget']['value']['#description'] = $original_description . '<br><br>' . $additional_info;
      }
    }

    // Get global settings for defaults.
    $global_config = $this->configFactory->get('simple_oauth_native_apps.settings');

    // Get current consumer-specific settings if they exist.
    $conflict = FALSE;
    try {
      $record = $this->policyResolver->resolve($consumer);
      $consumer_config = $record['name'] !== NULL ? $this->configFactory->get($record['name'])->get() : [];
    }
    catch (\UnexpectedValueException) {
      $record = ['name' => NULL, 'data' => []];
      $consumer_config = [];
      $conflict = TRUE;
    }
    // Only forms built here opt into automatic binding of new policy records.
    // Original programmatic submit calls remain unbound unless explicit.
    $form['#native_apps_bind_new_policy'] = $this->bindNewPolicies;

    $form['native_apps'] = [
      '#type' => 'details',
      '#title' => $this->t('Native App Settings'),
      '#description' => $this->t('Configure RFC 8252 native app security settings for this specific consumer. These settings work alongside Simple OAuth PKCE module to provide comprehensive security. Leave fields empty to use global defaults.'),
      '#open' => !empty($consumer_config),
      '#tree' => TRUE,
      '#weight' => 10,
    ];

    if ($conflict) {
      $form['native_apps']['policy_conflict'] = [
        '#markup' => $this->t('Native policy ownership is ambiguous. Reconcile duplicate bindings or competing numeric records before saving this consumer.'),
      ];
    }
    elseif ($record['name'] !== NULL && !array_key_exists('consumer_uuid', $record['data'])) {
      $form['native_apps']['confirm_policy_owner'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Assign this legacy policy to this consumer after review'),
        '#description' => $this->t('This record uses a numeric consumer ID. Its historical owner cannot be established from configuration. Review the policy before choosing this consumer as its owner. Saving without this confirmation preserves legacy numeric behavior. Bound policies follow the consumer UUID across environments. Enable Native Apps before importing policies; its import validation cannot run while the module is disabled.'),
        '#default_value' => FALSE,
      ];
    }

    // WebView detection override.
    $form['native_apps']['webview_detection_override'] = [
      '#type' => 'select',
      '#title' => $this->t('WebView detection policy override'),
      '#description' => $this->t('Override the global WebView detection policy for this consumer. <strong>Simple OAuth PKCE</strong> provides basic PKCE (RFC 7636) for all clients. <strong>Native Apps enhanced security</strong> adds RFC 8252 requirements including WebView detection. When disabled, native apps still get basic PKCE protection. Leave empty to use global setting (@global).', [
        '@global' => $this->getWebViewDetectionLabel($global_config->get('webview.detection')),
      ]),
      '#options' => [
        '' => $this->t('- Use global setting -'),
        'off' => $this->t('Off - Allow all requests'),
        'warn' => $this->t('Warn - Send warning headers but allow requests'),
        'block' => $this->t('Block - Reject WebView requests'),
      ],
      '#default_value' => $consumer_config['webview_detection_override'] ?? '',
    ];

    // Custom redirect URI schemes override.
    $form['native_apps']['allow_custom_schemes_override'] = [
      '#type' => 'select',
      '#title' => $this->t('Allow custom URI schemes override'),
      '#description' => $this->t('Override whether to allow custom URI schemes (e.g., myapp://callback) for this consumer. Leave empty to use global setting (@global).', [
        '@global' => $this->getReadableValue(RedirectPolicyValue::normalize($global_config->get('allow.custom_uri_schemes'))),
      ]),
      '#options' => [
        '' => $this->t('- Use global setting -'),
        'auto-detect' => $this->t('Auto-detect - Based on client type detection'),
        'native' => $this->t('Native - Allow custom URI schemes'),
        'web' => $this->t('Web - Disallow custom URI schemes'),
      ],
      '#default_value' => $consumer_config['allow_custom_schemes_override'] ?? '',
    ];

    // Loopback redirects override.
    $form['native_apps']['allow_loopback_override'] = [
      '#type' => 'select',
      '#title' => $this->t('Allow loopback redirects override'),
      '#description' => $this->t('Override whether to allow loopback IP redirects for this consumer. Leave empty to use global setting (@global).', [
        '@global' => $this->getReadableValue(RedirectPolicyValue::normalize($global_config->get('allow.loopback_redirects'))),
      ]),
      '#options' => [
        '' => $this->t('- Use global setting -'),
        'auto-detect' => $this->t('Auto-detect - Based on client type detection'),
        'native' => $this->t('Native - Allow loopback redirects'),
        'web' => $this->t('Web - Disallow loopback redirects'),
      ],
      '#default_value' => $consumer_config['allow_loopback_override'] ?? '',
    ];

    // PKCE enforcement override.
    $form['native_apps']['enhanced_pkce_override'] = [
      '#type' => 'select',
      '#title' => $this->t('Enhanced PKCE override'),
      '#description' => $this->t('Override enhanced PKCE requirements for this consumer. Leave empty to use global setting (@global).', [
        '@global' => $this->getReadableValue($global_config->get('native.enhanced_pkce')),
      ]),
      '#options' => [
        '' => $this->t('- Use global setting -'),
        'auto-detect' => $this->t('Auto-detect - Based on client type detection'),
        'enhanced' => $this->t('Enhanced - Apply enhanced PKCE'),
        'not-enhanced' => $this->t('Not Enhanced - Use standard PKCE'),
      ],
      '#default_value' => $consumer_config['enhanced_pkce_override'] ?? '',
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
        'callback' => [$this, 'detectClientTypeAjax'],
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

    // Apply submitted policy before Drupal validates the built consumer entity.
    $form['#entity_builders'][] = 'simple_oauth_native_apps_build_consumer_policy';

    // Add custom validation.
    $form['#validate'][] = 'simple_oauth_native_apps_validate_consumer_settings';

    // Add custom submit handler.
    $form['#native_apps_entity_submit'] = $form['actions']['submit']['#submit'] ?? [];
    $form['actions']['submit']['#submit'] = [[$this, 'submitWithPolicy']];
  }

  /**
   * Supplies pending config overrides for entity constraint validation.
   *
   * @param \Drupal\consumers\Entity\Consumer $consumer
   *   The consumer built by the entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state containing submitted policy controls.
   */
  public function buildConsumerPolicy(Consumer $consumer, FormStateInterface $form_state): void {
    $values = $form_state->getValue('native_apps');
    if (is_array($values)) {
      $this->clientDetector->setSubmittedOverrides($consumer, $values);
    }
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
    $values += array_fill_keys([
      'webview_detection_override',
      'allow_custom_schemes_override',
      'allow_loopback_override',
      'enhanced_pkce_override',
    ], '');

    $form_object = $form_state->getFormObject();
    if ($form_object instanceof EntityFormInterface && $form_object->getEntity() instanceof ConsumerInterface) {
      try {
        $binding = $values['consumer_uuid'] ?? NULL;
        if (array_key_exists('consumer_uuid', $values) && !is_string($binding)) {
          throw new \UnexpectedValueException('Invalid binding type.');
        }
        $this->policyResolver->assertWritable($form_object->getEntity(), $binding);
      }
      catch (\UnexpectedValueException) {
        $form_state->setErrorByName('native_apps', $this->t('Native policy ownership conflicts with existing configuration or the binding is invalid. Reconcile the records before saving; ownership confirmation cannot replace a foreign binding.'));
      }
    }

    // Consumer enums do not declare the legacy global Boolean strings.
    foreach (['allow_custom_schemes_override', 'allow_loopback_override'] as $key) {
      if (!in_array($values[$key], ['', 'auto-detect', 'native', 'web'], TRUE)) {
        $form_state->setErrorByName('native_apps][' . $key, $this->t('Invalid consumer redirect policy. Choose inheritance, auto-detect, native, or web.'));
      }
    }

    // Build configuration in nested structure directly for validation.
    $validator_config = [];

    // Only include non-empty overrides in nested structure.
    if ($values['webview_detection_override'] !== '') {
      $validator_config['webview']['detection'] = $values['webview_detection_override'];
    }

    if ($values['allow_custom_schemes_override'] !== '') {
      $validator_config['allow']['custom_uri_schemes'] = $values['allow_custom_schemes_override'];
    }

    if ($values['allow_loopback_override'] !== '') {
      $validator_config['allow']['loopback_redirects'] = $values['allow_loopback_override'];
    }

    if ($values['enhanced_pkce_override'] !== '') {
      $validator_config['native']['enhanced_pkce'] = $values['enhanced_pkce_override'];
    }

    // Validate overrides in their inherited global configuration context.
    if (!empty($validator_config)) {
      $global_config = $this->configFactory->get('simple_oauth_native_apps.settings');
      $effective_config = array_replace_recursive($global_config->get(), $validator_config);
      $errors = $this->configurationValidator->validateConfiguration($effective_config);
      foreach ($errors as $error) {
        $form_state->setErrorByName('native_apps', $error);
      }
    }
  }

  /**
   * Submits the entity and policy together, rolling back late ID collisions.
   *
   * New consumers have no numeric destination until the entity save assigns an
   * ID. Validation checks known IDs; the final write rechecks the assigned ID.
   * A transaction prevents a rejected policy from leaving a new consumer saved
   * without the security settings its administrator submitted.
   *
   * @param array $form
   *   The form, including its original entity submit callbacks.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The submitted form state.
   *
   * @throws \Throwable
   *   Entity or policy submission failed; database writes roll back.
   */
  public function submitWithPolicy(array &$form, FormStateInterface $form_state): void {
    $this->checkConsumerPolicy($form, $form_state);
    $transaction = $this->database?->startTransaction();
    try {
      foreach ($form['#native_apps_entity_submit'] ?? [] as $callback) {
        $callback = $form_state->prepareCallback($callback);
        $callback($form, $form_state);
      }
      $this->submitConsumerNativeAppsSettings($form, $form_state);
    }
    catch (\Throwable $exception) {
      $transaction?->rollBack();
      // A rolled-back consumer or config save may still be in static caches.
      $this->entityTypeManager->getStorage('consumer')->resetCache();
      $this->configFactory->reset();
      throw $exception;
    }
  }

  /**
   * Rechecks ownership before the entity form submit and save callbacks.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The submitted form state.
   *
   * @throws \UnexpectedValueException
   *   Ownership changed after validation.
   */
  public function checkConsumerPolicy(array $form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof EntityFormInterface && $form_object->getEntity() instanceof ConsumerInterface) {
      $values = $form_state->getValue('native_apps', []);
      $binding = $values['consumer_uuid'] ?? NULL;
      if (array_key_exists('consumer_uuid', $values) && !is_string($binding)) {
        throw new \UnexpectedValueException('Invalid binding type.');
      }
      $this->policyResolver->assertWritable($form_object->getEntity(), $binding);
    }
  }

  /**
   * Submits consumer native app settings.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @throws \UnexpectedValueException
   *   The consumer is missing or ownership requires reconciliation.
   */
  public function submitConsumerNativeAppsSettings(array $form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }
    $consumer = $form_object->getEntity();
    if (!$consumer instanceof ConsumerInterface) {
      return;
    }
    $values = $form_state->getValue('native_apps', []);

    // Save consumer-specific settings only if the consumer has an ID.
    $consumer_id = $consumer->id();
    if ($consumer_id) {
      $consumer = $this->entityTypeManager->getStorage('consumer')->load($consumer_id);
      if (!$consumer instanceof ConsumerInterface) {
        throw new \UnexpectedValueException('Native policy requires an existing consumer.');
      }
      $record = $this->policyResolver->assertWritable($consumer);
      $confirm = (bool) ($values['confirm_policy_owner'] ?? FALSE);
      unset($values['confirm_policy_owner']);
      $bind = $confirm || (($form['#native_apps_bind_new_policy'] ?? FALSE) === TRUE && $record['name'] === NULL);
      // A metadata-only confirmation must preserve all legacy values, including
      // explicit empty values. Submitted edits retain normal clear semantics.
      $confirmation_only = $confirm;
      foreach ($values as $key => $value) {
        if (!array_key_exists($key, $record['data']) || $record['data'][$key] !== $value) {
          $confirmation_only = FALSE;
        }
      }
      $this->policyResolver->save($consumer, $values, $bind, $confirmation_only);
    }
  }

  /**
   * Gets a human-readable label for a WebView detection setting.
   *
   * @param string|null $setting
   *   The setting value.
   *
   * @return string
   *   The human-readable label.
   */
  protected function getWebViewDetectionLabel(?string $setting): string {
    if ($setting === NULL) {
      return (string) $this->t('Not configured');
    }

    $labels = [
      'off' => $this->t('Off'),
      'warn' => $this->t('Warn'),
      'block' => $this->t('Block'),
    ];

    return (string) ($labels[$setting] ?? $this->t('Unknown'));
  }

  /**
   * Gets a human-readable label for any setting value.
   *
   * @param mixed $value
   *   The setting value.
   *
   * @return string
   *   The human-readable label.
   */
  protected function getReadableValue($value): string {
    if ($value === NULL || $value === '') {
      return (string) $this->t('Not configured');
    }

    // Handle boolean values (legacy support)
    if (is_bool($value)) {
      return (string) ($value ? $this->t('Enabled') : $this->t('Disabled'));
    }

    // Handle string enum values.
    $labels = [
      'auto-detect' => $this->t('Auto-detect'),
      'native' => $this->t('Native'),
      'web' => $this->t('Web'),
      'enhanced' => $this->t('Enhanced'),
      'not-enhanced' => $this->t('Not Enhanced'),
      'off' => $this->t('Off'),
      'warn' => $this->t('Warn'),
      'block' => $this->t('Block'),
    ];

    return (string) ($labels[$value] ?? $this->t('Unknown (@value)', ['@value' => $value]));
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

    $response->addCommand(new HtmlCommand('#client-detection-results', $markup));
    $response->addCommand(new AnnounceCommand(trim(strip_tags($markup))));

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

    $confidence_labels = [
      'low' => $this->t('Low'),
      'medium' => $this->t('Medium'),
      'high' => $this->t('High'),
    ];
    $confidence_class = isset($confidence_labels[$confidence]) ? $confidence : 'unknown';
    $confidence_label = $confidence_labels[$confidence] ?? ucfirst($confidence);

    $markup = '<div class="client-detection-result confidence-' . $confidence_class . '">';
    $markup .= '<h4>' . $this->t('Detection Result') . '</h4>';
    $markup .= '<p><strong>' . $this->t('Detected Client Type:') . '</strong> ' . Html::escape($this->getClientTypeLabel($type)) . '</p>';
    $markup .= '<p><strong>' . $this->t('Detection Confidence:') . '</strong> ' . Html::escape((string) $confidence_label) . '</p>';

    if (isset($details['description'])) {
      $description = match ($details['description']) {
        'Terminal/CLI application using loopback redirects' => $this->t('Terminal/CLI application using loopback redirects'),
        'Mobile native application' => $this->t('Mobile native application'),
        'Desktop native application' => $this->t('Desktop native application'),
        'Web application' => $this->t('Web application'),
        default => $details['description'],
      };
      $markup .= '<p><strong>' . $this->t('Description:') . '</strong> ' . Html::escape((string) $description) . '</p>';
    }

    // Show analyzed URIs.
    $markup .= '<div class="analyzed-uris">';
    $markup .= '<h5>' . $this->t('Analyzed Redirect URIs:') . '</h5>';
    $markup .= '<ul>';
    foreach ($redirect_uris as $uri) {
      $markup .= '<li><code>' . Html::escape($uri) . '</code></li>';
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
        '@types' => implode(', ', array_map($this->getClientTypeLabel(...), $result['mixed_types'])),
      ]);
      $markup .= '</div>';
    }

    $markup .= '</div>';

    return $markup;
  }

  /**
   * Presents stable detector type values without changing detector results.
   *
   * @param string $type
   *   The detector type.
   *
   * @return string
   *   A translated label or unescaped fallback for the caller to escape.
   */
  private function getClientTypeLabel(string $type): string {
    return (string) match ($type) {
      'terminal' => $this->t('Terminal'),
      'mobile' => $this->t('Mobile'),
      'desktop' => $this->t('Desktop'),
      'web' => $this->t('Web'),
      default => ucfirst($type),
    };
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
