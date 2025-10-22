<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
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
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
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
    $config = $this->config('simple_oauth_client_registration.settings');

    $form['grant_defaults'] = [
      '#type' => 'details',
      '#title' => $this->t('Grant Type Defaults'),
      '#open' => TRUE,
      '#description' => $this->t('Configure default grant types for dynamically registered clients.'),
    ];

    $form['grant_defaults']['auto_enable_refresh_token'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically enable refresh_token grant for DCR clients'),
      '#default_value' => $config->get('auto_enable_refresh_token') ?? TRUE,
      '#description' => $this->t('<strong>RFC 7591 Compliance:</strong> According to RFC 7591, if a client registration request does not explicitly specify the <code>grant_types</code> parameter, the authorization server MAY establish default grant types. This setting determines whether <code>refresh_token</code> is included in those defaults.<br><br><strong>OAuth 2.1 Best Practice:</strong> Refresh tokens are recommended for maintaining secure, long-lived sessions without storing credentials. Enabling this setting ensures dynamically registered clients can obtain refresh tokens by default, improving security and user experience.<br><br><strong>Scope:</strong> This setting applies ONLY to Dynamic Client Registration (DCR) via the <code>/oauth/register</code> endpoint. It does NOT affect clients created through other means (e.g., via the admin UI or Consumers module directly).<br><br><strong>Override Behavior:</strong> If a client explicitly specifies <code>grant_types</code> in their registration request, that explicit list takes precedence, and this default setting is ignored.<br><br><em>References: <a href="https://datatracker.ietf.org/doc/html/rfc7591#section-2" target="_blank">RFC 7591 Section 2</a> | <a href="https://datatracker.ietf.org/doc/html/draft-ietf-oauth-v2-1/#section-7.2" target="_blank">OAuth 2.1 Draft Section 7.2</a></em>'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('simple_oauth_client_registration.settings')
      ->set('auto_enable_refresh_token', (bool) $form_state->getValue('auto_enable_refresh_token'))
      ->save();

    parent::submitForm($form, $form_state);

    $this->messenger()->addStatus(
      $this->t('Client Registration configuration has been saved.')
    );
  }

}
