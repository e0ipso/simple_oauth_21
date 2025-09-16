<?php

namespace Drupal\simple_oauth_server_metadata\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\simple_oauth_server_metadata\Service\ServerMetadataService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for RFC 8414 Server Metadata settings.
 */
class ServerMetadataSettingsForm extends ConfigFormBase {

  /**
   * The server metadata service.
   *
   * @var \Drupal\simple_oauth_server_metadata\Service\ServerMetadataService
   */
  protected $serverMetadataService;

  /**
   * Constructs a ServerMetadataSettingsForm object.
   *
   * @param \Drupal\simple_oauth_server_metadata\Service\ServerMetadataService $server_metadata_service
   *   The server metadata service.
   */
  public function __construct(ServerMetadataService $server_metadata_service) {
    $this->serverMetadataService = $server_metadata_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('simple_oauth_server_metadata.server_metadata')
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
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Server metadata should advertise all supported endpoints for better OAuth 2.1 compliance. Configure optional endpoints that your authorization server supports. (<a href="https://datatracker.ietf.org/doc/html/rfc8414#section-2" target="_blank">RFC 8414 Section 2</a>)'),
      '#open' => TRUE,
    ];

    $form['endpoints']['registration_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Client Registration Endpoint'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> RFC 8414 Authorization Server Metadata recommends including client registration endpoint information for OAuth 2.1 compliance. Leave empty to auto-populate with the Drupal consumer add form URL. (<a href="https://datatracker.ietf.org/doc/html/rfc7591#section-3" target="_blank">RFC 7591 Section 3</a>)'),
      '#default_value' => $config->get('registration_endpoint'),
    ];

    $form['endpoints']['revocation_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Token Revocation Endpoint'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Token revocation endpoint allows clients to invalidate tokens when no longer needed. This enhances security by preventing token misuse after app uninstall or logout. (<a href="https://datatracker.ietf.org/doc/html/rfc7009#section-2" target="_blank">RFC 7009 Section 2</a>)'),
      '#default_value' => $config->get('revocation_endpoint'),
    ];

    $form['endpoints']['introspection_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Token Introspection Endpoint'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> Token introspection endpoint enables resource servers to validate tokens and check their status. This supports OAuth 2.1 security best practices for token validation. (<a href="https://datatracker.ietf.org/doc/html/rfc7662#section-2" target="_blank">RFC 7662 Section 2</a>)'),
      '#default_value' => $config->get('introspection_endpoint'),
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

    // Link to live metadata endpoint.
    $form['metadata_link'] = [
      '#markup' => '<p>' . $this->t('View live metadata: <a href="@url" target="_blank" rel="noopener">@url</a>', [
        '@url' => Url::fromRoute('simple_oauth_server_metadata.well_known', [], ['absolute' => TRUE])->toString(),
      ]) . '</p>',
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
      'service_documentation',
      'op_policy_uri',
      'op_tos_uri',
      'ui_locales_supported',
      'additional_claims_supported',
      'additional_signing_algorithms',
    ];

    foreach ($fields as $field) {
      $value = $form_state->getValue($field);
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

}
