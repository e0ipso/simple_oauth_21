<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\simple_oauth\Plugin\Oauth2GrantManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Dynamic Client Registration settings.
 *
 * Provides administrative interface for configuring RFC 7591 compliance
 * options and OAuth 2.1 best practices for dynamically registered clients.
 */
final class ClientRegistrationSettingsForm extends ConfigFormBase {

  /**
   * Constructs a ClientRegistrationSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\simple_oauth\Plugin\Oauth2GrantManagerInterface $grantManager
   *   The OAuth2 grant plugin manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly Oauth2GrantManagerInterface $grantManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('plugin.manager.oauth2_grant.processor'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['simple_oauth_client_registration.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'simple_oauth_client_registration_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['grant_defaults'] = [
      '#type' => 'details',
      '#title' => $this->t('Grant Type Defaults'),
      '#open' => TRUE,
      '#description' => $this->t('Configure default grant types for dynamically registered clients.'),
    ];

    $form['grant_defaults']['default_grant_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Default grant types for DCR clients'),
      '#options' => array_map(
        fn($definition) => $definition['label'],
        $this->grantManager->getDefinitions()
      ),
      '#config_target' => 'simple_oauth_client_registration.settings:default_grant_types',
      '#description' => $this->t('<strong>RFC 7591 Compliance:</strong> According to RFC 7591, if a client registration request does not explicitly specify the <code>grant_types</code> parameter, the authorization server MAY establish default grant types. Select which grant types should be included in those defaults.<br><br><strong>OAuth 2.1 Best Practice:</strong> The <code>authorization_code</code> grant with <code>refresh_token</code> is recommended for maintaining secure, long-lived sessions without storing credentials. This combination provides the best security and user experience for most client applications.<br><br><strong>Override Behavior:</strong> If a client explicitly specifies <code>grant_types</code> in their registration request, that explicit list takes precedence, and this default setting is ignored.<br><br><em>References: <a href="https://datatracker.ietf.org/doc/html/rfc7591#section-2" target="_blank">RFC 7591 Section 2</a> | <a href="https://datatracker.ietf.org/doc/html/draft-ietf-oauth-v2-1/#section-7.2" target="_blank">OAuth 2.1 Draft Section 7.2</a></em>'),
    ];

    return parent::buildForm($form, $form_state);
  }

}
