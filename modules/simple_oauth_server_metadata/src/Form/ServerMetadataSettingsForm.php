<?php

namespace Drupal\simple_oauth_server_metadata\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Drupal\simple_oauth_server_metadata\Service\ServerMetadataService;
use Drupal\simple_oauth_server_metadata\Service\ResourceMetadataService;
use Drupal\simple_oauth_server_metadata\Service\EndpointDiscoveryService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for RFC 8414 Server Metadata settings.
 */
class ServerMetadataSettingsForm extends ConfigFormBase {

  /**
   * Constructs a ServerMetadataSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed configuration manager.
   * @param \Drupal\simple_oauth_server_metadata\Service\ServerMetadataService $serverMetadataService
   *   The server metadata service.
   * @param \Drupal\simple_oauth_server_metadata\Service\ResourceMetadataService $resourceMetadataService
   *   The resource metadata service.
   * @param \Drupal\simple_oauth_server_metadata\Service\EndpointDiscoveryService $endpointDiscoveryService
   *   The endpoint discovery service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Routing\RouteProviderInterface $routeProvider
   *   The route provider.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly ServerMetadataService $serverMetadataService,
    private readonly ResourceMetadataService $resourceMetadataService,
    private readonly EndpointDiscoveryService $endpointDiscoveryService,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly RouteProviderInterface $routeProvider,
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
      $container->get('simple_oauth_server_metadata.server_metadata'),
      $container->get('simple_oauth_server_metadata.resource_metadata'),
      $container->get('simple_oauth_server_metadata.endpoint_discovery'),
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
      '#markup' => '<p>' . $this->t('Configure additional RFC 8414 Authorization Server Metadata fields. Fields left empty will not be included in the metadata response.') . '</p>',
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

    $registration_endpoint_value = $config->get('registration_endpoint') ?: ($auto_detected_endpoints['registration'] ?? '');
    $form['endpoints']['registration_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Client Registration Endpoint'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> RFC 8414 Authorization Server Metadata recommends including client registration endpoint information for OAuth 2.1 compliance. @auto_detected (<a href="https://datatracker.ietf.org/doc/html/rfc7591#section-3" target="_blank">RFC 7591 Section 3</a>)', [
        '@auto_detected' => isset($auto_detected_endpoints['registration']) ? $this->t('Auto-detected from Dynamic Client Registration module.') : $this->t('Module not detected. Enter manually if available.'),
      ]),
      '#default_value' => $registration_endpoint_value,
      '#placeholder' => $auto_detected_endpoints['registration'] ?? '',
    ];

    $revocation_endpoint_value = $config->get('revocation_endpoint') ?: ($auto_detected_endpoints['revocation'] ?? '');
    $form['endpoints']['revocation_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Token Revocation Endpoint'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Token revocation endpoint allows clients to invalidate tokens when no longer needed. This enhances security by preventing token misuse after app uninstall or logout. @auto_detected (<a href="https://datatracker.ietf.org/doc/html/rfc7009#section-2" target="_blank">RFC 7009 Section 2</a>)', [
        '@auto_detected' => isset($auto_detected_endpoints['revocation']) ? $this->t('Auto-detected from Simple OAuth module.') : $this->t('Enter manually if available.'),
      ]),
      '#default_value' => $revocation_endpoint_value,
      '#placeholder' => $auto_detected_endpoints['revocation'] ?? '',
    ];

    $introspection_endpoint_value = $config->get('introspection_endpoint') ?: ($auto_detected_endpoints['introspection'] ?? '');
    $form['endpoints']['introspection_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Token Introspection Endpoint'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Token introspection endpoint enables resource servers to validate tokens and check their status. This supports OAuth 2.1 security best practices for token validation. @auto_detected (<a href="https://datatracker.ietf.org/doc/html/rfc7662#section-2" target="_blank">RFC 7662 Section 2</a>)', [
        '@auto_detected' => isset($auto_detected_endpoints['introspection']) ? $this->t('Auto-detected from Simple OAuth module.') : $this->t('Enter manually if available.'),
      ]),
      '#default_value' => $introspection_endpoint_value,
      '#placeholder' => $auto_detected_endpoints['introspection'] ?? '',
    ];

    // Device Authorization endpoint (RFC 8628).
    if (isset($auto_detected_endpoints['device_authorization'])) {
      $device_authorization_value = $config->get('device_authorization_endpoint') ?: $auto_detected_endpoints['device_authorization'];
      $form['endpoints']['device_authorization_endpoint'] = [
        '#type' => 'url',
        '#title' => $this->t('Device Authorization Endpoint'),
        '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Device authorization endpoint enables OAuth flows for devices with limited input capabilities like smart TVs and IoT devices. Auto-detected from Device Flow module. (<a href="https://datatracker.ietf.org/doc/html/rfc8628#section-3.1" target="_blank">RFC 8628 Section 3.1</a>)'),
        '#default_value' => $device_authorization_value,
        '#placeholder' => $auto_detected_endpoints['device_authorization'],
      ];
    }

    // OpenID Connect Discovery section.
    $form['openid_discovery'] = [
      '#type' => 'details',
      '#title' => $this->t('OpenID Connect Discovery'),
      '#description' => $this->t('Configuration for the OpenID Connect Discovery endpoint at /.well-known/openid-configuration. This endpoint is always available when the module is enabled.'),
      '#open' => TRUE,
    ];

    $form['openid_discovery']['response_types_supported'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Supported Response Types'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Required:</strong> Select the OAuth 2.0 response types that are supported by this authorization server. These values will be included in the OpenID Connect Discovery metadata. (<a href="https://openid.net/specs/openid-connect-discovery-1_0.html#ProviderMetadata" target="_blank">OpenID Connect Discovery 1.0 Section 3</a>)'),
      '#options' => [
        'code' => $this->t('code'),
        'token' => $this->t('token'),
        'id_token' => $this->t('id_token'),
        'code id_token' => $this->t('code id_token'),
      ],
      '#default_value' => $config->get('response_types_supported') ?: ['code', 'id_token', 'code id_token'],
    ];

    $form['openid_discovery']['response_modes_supported'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Supported Response Modes'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Select the OAuth 2.0 response modes that are supported by this authorization server. These values will be included in the OpenID Connect Discovery metadata. (<a href="https://openid.net/specs/openid-connect-discovery-1_0.html#ProviderMetadata" target="_blank">OpenID Connect Discovery 1.0 Section 3</a>)'),
      '#options' => [
        'query' => $this->t('query'),
        'fragment' => $this->t('fragment'),
      ],
      '#default_value' => $config->get('response_modes_supported') ?: ['query', 'fragment'],
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
    // Convert textarea arrays to proper arrays and validate.
    $array_fields = ['ui_locales_supported', 'additional_claims_supported', 'additional_signing_algorithms'];
    foreach ($array_fields as $field) {
      $value = $form_state->getValue($field);
      if (!empty($value)) {
        $array_value = array_filter(array_map('trim', explode("\n", $value)));
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
    if (empty($response_types)) {
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
      $value = $form_state->getValue($field);

      // Handle checkbox arrays (response types and modes).
      if (in_array($field, ['response_types_supported', 'response_modes_supported'])) {
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

    // Check for Dynamic Client Registration module.
    if ($this->moduleHandler->moduleExists('simple_oauth_client_registration')) {
      try {
        $url = Url::fromRoute('simple_oauth_client_registration.register');
        $endpoints['registration'] = $url->setAbsolute()->toString();
      }
      catch (\Exception $e) {
        // Route doesn't exist or error generating URL.
      }
    }

    // Check for Device Flow module.
    if ($this->moduleHandler->moduleExists('simple_oauth_device_flow')) {
      try {
        $url = Url::fromRoute('simple_oauth_device_flow.device_authorization');
        $endpoints['device_authorization'] = $url->setAbsolute()->toString();
      }
      catch (\Exception $e) {
        // Route doesn't exist or error generating URL.
      }
    }

    // Check for core Simple OAuth revocation endpoint.
    if ($this->moduleHandler->moduleExists('simple_oauth')) {
      try {
        // Check if revocation route exists.
        $routes = $this->routeProvider->getRoutesByPattern('/oauth/revoke');
        if (count($routes) > 0) {
          $url = Url::fromUri('internal:/oauth/revoke');
          $endpoints['revocation'] = $url->setAbsolute()->toString();
        }
      }
      catch (\Exception $e) {
        // Route doesn't exist.
      }

      try {
        // Check if introspection route exists.
        $routes = $this->routeProvider->getRoutesByPattern('/oauth/introspect');
        if (count($routes) > 0) {
          $url = Url::fromUri('internal:/oauth/introspect');
          $endpoints['introspection'] = $url->setAbsolute()->toString();
        }
      }
      catch (\Exception $e) {
        // Route doesn't exist.
      }
    }

    return $endpoints;
  }

}
