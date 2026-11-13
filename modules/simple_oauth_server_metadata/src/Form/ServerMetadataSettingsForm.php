<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for RFC 8414 Server Metadata settings.
 */
final class ServerMetadataSettingsForm extends ConfigFormBase {

  /**
   * Constructs a ServerMetadataSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed configuration manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Routing\RouteProviderInterface $routeProvider
   *   The route provider.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected ModuleHandlerInterface $moduleHandler,
    protected RouteProviderInterface $routeProvider,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('module_handler'),
      $container->get('router.route_provider')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['simple_oauth_server_metadata.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'simple_oauth_server_metadata_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('simple_oauth_server_metadata.settings');

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Configure additional RFC 8414 Authorization Server Metadata fields. Leave endpoint overrides blank to use automatic discovery. Other empty fields will not be included in the metadata response.') . '</p>',
    ];

    $form['info_link'] = [
      '#markup' => '<p>' . $this->t('Learn more about <a href="@rfc_url" target="_blank" rel="noopener">RFC 8414 OAuth 2.0 Authorization Server Metadata</a>.', [
        '@rfc_url' => 'https://tools.ietf.org/html/rfc8414',
      ]) . '</p>',
    ];

    // Module status section removed per request.
    // Optional endpoints section.
    $form['endpoints'] = [
      '#type' => 'details',
      '#title' => $this->t('Optional Endpoints'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Server metadata should advertise all supported endpoints for better OAuth 2.1 compliance. Endpoints are auto-detected based on enabled modules. You can override the auto-detected values if needed. (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-2" target="_blank">RFC 8414 Section 2</a>)'),
      '#open' => TRUE,
    ];

    // Auto-detect available endpoints.
    $auto_detected_endpoints = $this->getAutoDetectedEndpoints();

    $form['endpoints']['registration_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Client Registration Endpoint'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> RFC 8414 Authorization Server Metadata recommends including client registration endpoint information for OAuth 2.1 compliance. @auto_detected (<a href="https://datatracker.ietf.org/doc/html/rfc7591#section-3" target="_blank">RFC 7591 Section 3</a>)', [
        '@auto_detected' => isset($auto_detected_endpoints['registration']) ? $this->t('Auto-detected from Dynamic Client Registration module.') : $this->t('Module not detected. Enter manually if available.'),
      ]),
      '#default_value' => $config->get('registration_endpoint') ?? '',
      '#placeholder' => $auto_detected_endpoints['registration'] ?? '',
    ];

    $form['endpoints']['revocation_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Token Revocation Endpoint'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Token revocation endpoint allows clients to invalidate tokens when no longer needed. This enhances security by preventing token misuse after app uninstall or logout. @auto_detected (<a href="https://datatracker.ietf.org/doc/html/rfc7009#section-2" target="_blank">RFC 7009 Section 2</a>)', [
        '@auto_detected' => isset($auto_detected_endpoints['revocation']) ? $this->t('Auto-detected from Server Metadata module.') : $this->t('Enter manually if available.'),
      ]),
      '#default_value' => $config->get('revocation_endpoint') ?? '',
      '#placeholder' => $auto_detected_endpoints['revocation'] ?? '',
    ];

    $form['endpoints']['introspection_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Token Introspection Endpoint'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Token introspection endpoint enables resource servers to validate tokens and check their status. This supports OAuth 2.1 security best practices for token validation. @auto_detected (<a href="https://datatracker.ietf.org/doc/html/rfc7662#section-2" target="_blank">RFC 7662 Section 2</a>)', [
        '@auto_detected' => isset($auto_detected_endpoints['introspection']) ? $this->t('Auto-detected from Server Metadata module.') : $this->t('Enter manually if available.'),
      ]),
      '#default_value' => $config->get('introspection_endpoint') ?? '',
      '#placeholder' => $auto_detected_endpoints['introspection'] ?? '',
    ];

    // Device Authorization endpoint (RFC 8628).
    if (isset($auto_detected_endpoints['device_authorization'])) {
      $form['endpoints']['device_authorization_endpoint'] = [
        '#type' => 'url',
        '#title' => $this->t('Device Authorization Endpoint'),
        '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Device authorization endpoint enables OAuth flows for devices with limited input capabilities like smart TVs and IoT devices. Auto-detected from Device Flow module. (<a href="https://datatracker.ietf.org/doc/html/rfc8628#section-3.1" target="_blank">RFC 8628 Section 3.1</a>)'),
        '#default_value' => $config->get('device_authorization_endpoint') ?? '',
        '#placeholder' => $auto_detected_endpoints['device_authorization'],
      ];
    }

    foreach (['registration', 'revocation', 'introspection', 'device_authorization'] as $endpoint) {
      $field = $endpoint . '_endpoint';
      if (isset($form['endpoints'][$field])) {
        $form['endpoints'][$field]['#description'] .= ' ' . (isset($auto_detected_endpoints[$endpoint])
          ? $this->t('Leave blank to use automatic discovery. Current endpoint: @url', ['@url' => $auto_detected_endpoints[$endpoint]])
          : $this->t('Leave blank to use automatic discovery when the endpoint becomes available.'));
      }
    }

    // OpenID Connect Discovery section.
    $form['openid_discovery'] = [
      '#type' => 'details',
      '#title' => $this->t('OpenID Connect Discovery'),
      '#description' => $this->t('Capabilities of the OpenID Connect Discovery endpoint at /.well-known/openid-configuration. This endpoint is available when OpenID Connect is enabled in Simple OAuth.'),
      '#open' => TRUE,
    ];

    $form['openid_discovery']['response_capabilities'] = [
      '#type' => 'item',
      '#title' => $this->t('Response capabilities'),
      '#markup' => $this->t('Response types are discovered from available grant plugins. OpenID Connect adds ID tokens at the token endpoint for code flow; it does not enable implicit or hybrid authorization responses. Discovery retains the advertised query, fragment, and form_post modes for compatibility. Advertising a mode does not enable it at the authorization endpoint. Stored response type and mode settings are retained for configuration compatibility and do not control discovery.'),
    ];

    // Policy URLs section.
    $form['policy'] = [
      '#type' => 'details',
      '#title' => $this->t('Policy and Documentation URLs'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Policy and documentation URLs help clients understand server capabilities and requirements. OAuth 2.1 encourages transparent documentation for better client integration. (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-2" target="_blank">RFC 8414 Section 2</a>)'),
      '#open' => FALSE,
    ];

    $form['policy']['service_documentation'] = [
      '#type' => 'url',
      '#title' => $this->t('Service Documentation'),
      '#description' => $this->t('URL for human-readable documentation of the authorization server. This should explain how to use your OAuth server.'),
      '#default_value' => $config->get('service_documentation'),
    ];

    $form['policy']['op_policy_uri'] = [
      '#type' => 'url',
      '#title' => $this->t('Operator Policy URI'),
      '#description' => $this->t('URL for the authorization server policy document. This typically covers privacy and data handling policies.'),
      '#default_value' => $config->get('op_policy_uri'),
    ];

    $form['policy']['op_tos_uri'] = [
      '#type' => 'url',
      '#title' => $this->t('Operator Terms of Service URI'),
      '#description' => $this->t('URL for the authorization server terms of service document.'),
      '#default_value' => $config->get('op_tos_uri'),
    ];

    // Capabilities section.
    $form['capabilities'] = [
      '#type' => 'details',
      '#title' => $this->t('Additional Capabilities'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Advertising server capabilities enables OAuth 2.1 clients to automatically detect and use appropriate security features and protocols. (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-2" target="_blank">RFC 8414 Section 2</a>)'),
      '#open' => FALSE,
    ];

    $form['capabilities']['ui_locales_supported'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Supported UI Locales'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Advertise supported UI locales to help clients provide localized authorization flows. Enter BCP47 language tags (en-US, es-ES, etc.) for OAuth 2.1 metadata completeness. (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-2" target="_blank">RFC 8414 Section 2</a>)'),
      '#default_value' => implode("\n", $config->get('ui_locales_supported') ?: []),
      '#rows' => 4,
    ];

    $form['capabilities']['additional_claims_supported'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional Claims Supported'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Advertise custom claims available in access tokens to help clients understand available user data. This improves OAuth 2.1 server metadata completeness and client integration. (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-2" target="_blank">RFC 8414 Section 2</a>)'),
      '#default_value' => implode("\n", $config->get('additional_claims_supported') ?: []),
      '#rows' => 4,
    ];

    $form['capabilities']['additional_signing_algorithms'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional Signing Algorithms'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Advertise additional JWT signing algorithms beyond RS256 to support diverse client security requirements. OAuth 2.1 encourages algorithm flexibility for different deployment scenarios. (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-2" target="_blank">RFC 8414 Section 2</a>)'),
      '#default_value' => implode("\n", $config->get('additional_signing_algorithms') ?: []),
      '#rows' => 4,
    ];

    // Resource metadata section.
    $form['resource_metadata'] = [
      '#type' => 'details',
      '#title' => $this->t('Protected Resource Metadata (RFC 9728)'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> RFC 9728 Protected Resource Metadata helps clients discover resource server capabilities and policies. This enhances OAuth 2.1 compliance by providing standardized resource server information. (<a href="https://datatracker.ietf.org/doc/html/rfc9728" target="_blank">RFC 9728</a>)'),
      '#open' => FALSE,
    ];

    $form['resource_metadata']['resource_documentation'] = [
      '#type' => 'url',
      '#title' => $this->t('Resource Documentation'),
      '#description' => $this->t('URL for human-readable documentation of the protected resource server. This should explain available resources and how to access them.'),
      '#default_value' => $config->get('resource_documentation'),
    ];

    $form['resource_metadata']['resource_policy_uri'] = [
      '#type' => 'url',
      '#title' => $this->t('Resource Policy URI'),
      '#description' => $this->t('URL for the protected resource server policy document. This typically covers resource access policies and data handling practices.'),
      '#default_value' => $config->get('resource_policy_uri'),
    ];

    $form['resource_metadata']['resource_tos_uri'] = [
      '#type' => 'url',
      '#title' => $this->t('Resource Terms of Service URI'),
      '#description' => $this->t('URL for the protected resource server terms of service document.'),
      '#default_value' => $config->get('resource_tos_uri'),
    ];

    // Link to live metadata endpoints.
    $form['metadata_links'] = [
      '#markup' => '<p>' . $this->t('View live metadata:') . '</p>' .
      '<ul>' .
      '<li>' . $this->t('Authorization Server: <a href="@url" target="_blank" rel="noopener">@url</a>', [
        '@url' => Url::fromRoute('simple_oauth_server_metadata.well_known', [], ['absolute' => TRUE])->toString(),
      ]) . '</li>' .
      '<li>' . $this->t('Protected Resource: <a href="@url" target="_blank" rel="noopener">@url</a>', [
        '@url' => Url::fromRoute('simple_oauth_server_metadata.resource_metadata', [], ['absolute' => TRUE])->toString(),
      ]) . '</li>' .
      '</ul>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // FormBuilder discards values without input elements. Accept explicit
    // legacy submissions while retaining the former checkbox value choices.
    $legacy_choices = [
      'response_types_supported' => ['code', 'token', 'id_token', 'code id_token'],
      'response_modes_supported' => ['query', 'fragment'],
    ];
    foreach ($legacy_choices as $field => $choices) {
      $input = $form_state->getValue($field, $form_state->getUserInput()[$field] ?? NULL);
      if ($input === NULL) {
        continue;
      }
      if (!is_array($input) || array_filter($input, static fn($value): bool => (bool) $value && !in_array($value, $choices, TRUE)) !== []) {
        $form_state->setErrorByName($field, $this->t('Invalid response capability selection.'));
        $input = [];
      }
      $form_state->setValue($field, $input);
    }

    // Convert textarea arrays to proper arrays and validate.
    $array_fields = ['ui_locales_supported', 'additional_claims_supported', 'additional_signing_algorithms'];
    foreach ($array_fields as $field) {
      if (!array_key_exists($field, $form_state->getValues())) {
        continue;
      }
      $value = $form_state->getValue($field);
      if ($value !== NULL && $value !== '') {
        $array_value = array_values(array_filter(
          array_map('trim', explode("\n", $value)),
          static fn(string $line): bool => $line !== '',
        ));
        $form_state->setValue($field, $array_value);
      }
      else {
        $form_state->setValue($field, []);
      }
    }

    // Validate locale format for ui_locales_supported.
    $locales = $form_state->getValue('ui_locales_supported');
    if (!empty($locales)) {
      foreach ($locales as $locale) {
        // Basic BCP47 validation - should be letters, numbers, and hyphens.
        if (!preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $locale)) {
          $form_state->setErrorByName('ui_locales_supported',
            $this->t('Invalid locale format: @locale. Please use BCP47 format (e.g., en-US, fr-FR).', ['@locale' => $locale]));
        }
      }
    }

    // Validate signing algorithms.
    $algorithms = $form_state->getValue('additional_signing_algorithms');
    if (!empty($algorithms)) {
      $valid_algorithms = [
        'HS256', 'HS384', 'HS512', 'RS256', 'RS384', 'RS512',
        'PS256', 'PS384', 'PS512', 'ES256', 'ES384', 'ES512',
      ];
      foreach ($algorithms as $algorithm) {
        if (!in_array($algorithm, $valid_algorithms, TRUE)) {
          $form_state->setErrorByName('additional_signing_algorithms',
            $this->t('Invalid signing algorithm: @algorithm. Valid algorithms include: @valid', [
              '@algorithm' => $algorithm,
              '@valid' => implode(', ', $valid_algorithms),
            ]));
        }
      }
    }

    // Validate OpenID Connect Discovery fields.
    // Validate at least one response type is selected.
    $response_types = array_filter($form_state->getValue('response_types_supported') ?: []);
    if ($form_state->hasValue('response_types_supported') && empty($response_types)) {
      $form_state->setErrorByName('response_types_supported', $this->t('At least one response type must be selected for OpenID Connect Discovery.'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('simple_oauth_server_metadata.settings');

    $fields = [
      'registration_endpoint',
      'revocation_endpoint',
      'introspection_endpoint',
      'device_authorization_endpoint',
      'service_documentation',
      'op_policy_uri',
      'op_tos_uri',
      'ui_locales_supported',
      'additional_claims_supported',
      'additional_signing_algorithms',
      'resource_documentation',
      'resource_policy_uri',
      'resource_tos_uri',
      'response_types_supported',
      'response_modes_supported',
    ];

    foreach ($fields as $field) {
      // Absent controls and partial external submissions retain stored values.
      // Present empty controls still clear an explicit override.
      if (!array_key_exists($field, $form_state->getValues())) {
        continue;
      }
      $value = $form_state->getValue($field);

      // Handle checkbox arrays (response types and modes).
      if (in_array($field, ['response_types_supported', 'response_modes_supported'])) {
        // Retain the legacy behavior for explicit NULL capability values.
        if ($value === NULL) {
          continue;
        }
        // Filter out unchecked values (FALSE) and get only the checked ones.
        $value = array_values(array_filter($value));
      }

      // Don't save empty strings or empty arrays.
      if (empty($value)) {
        $config->clear($field);
      }
      else {
        $config->set($field, $value);
      }
    }

    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Auto-detects available OAuth endpoints based on enabled modules.
   *
   * @return array
   *   An array of auto-detected endpoint URLs keyed by endpoint type.
   */
  protected function getAutoDetectedEndpoints(): array {
    $endpoints = [];
    // Match the language-neutral URLs advertised by runtime discovery.
    $options = ['language' => new Language(['id' => LanguageInterface::LANGCODE_NOT_APPLICABLE])];

    // Check for Dynamic Client Registration module.
    if ($this->moduleHandler->moduleExists('simple_oauth_client_registration')) {
      try {
        $url = Url::fromRoute('simple_oauth_client_registration.register', [], $options);
        $endpoints['registration'] = $url->setAbsolute()->toString();
      }
      catch (\Exception $e) {
        // Route doesn't exist or error generating URL.
      }
    }

    // Check for Device Flow module.
    if ($this->moduleHandler->moduleExists('simple_oauth_device_flow')) {
      try {
        $url = Url::fromRoute('simple_oauth_device_flow.device_authorization', [], $options);
        $endpoints['device_authorization'] = $url->setAbsolute()->toString();
      }
      catch (\Exception $e) {
        // Route doesn't exist or error generating URL.
      }
    }

    // Check for this module's token management endpoints.
    if ($this->moduleHandler->moduleExists('simple_oauth')) {
      try {
        $this->routeProvider->getRouteByName('simple_oauth_server_metadata.revoke');
        $url = Url::fromRoute('simple_oauth_server_metadata.revoke', [], $options);
        $endpoints['revocation'] = $url->setAbsolute()->toString();
      }
      catch (\Exception $e) {
        // Route doesn't exist.
      }

      try {
        $this->routeProvider->getRouteByName('simple_oauth_server_metadata.token_introspection');
        $url = Url::fromRoute('simple_oauth_server_metadata.token_introspection', [], $options);
        $endpoints['introspection'] = $url->setAbsolute()->toString();
      }
      catch (\Exception $e) {
        // Route doesn't exist.
      }
    }

    return $endpoints;
  }

}
