<?php

namespace Drupal\simple_oauth_pkce\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for PKCE settings.
 */
class PkceSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['simple_oauth_pkce.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'simple_oauth_pkce_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('simple_oauth_pkce.settings');

    $form['pkce'] = [
      '#type' => 'details',
      '#title' => $this->t('PKCE (Proof Key for Code Exchange) Settings'),
      '#open' => TRUE,
      '#description' => $this->t('Configure PKCE support for enhanced OAuth 2.0 security.'),
    ];

    // OAuth 2.1 Settings now available in OAuth 2.1 Compliance dashboard.
    $form['pkce']['enforcement'] = [
      '#type' => 'select',
      '#title' => $this->t('PKCE Enforcement Level'),
      '#options' => [
        'disabled' => $this->t('Disabled (Not recommended for production)'),
        'optional' => $this->t('Optional (Transition mode)'),
        'mandatory' => $this->t('Mandatory (OAuth 2.1 compliant - Recommended)'),
      ],
      '#default_value' => $config->get('enforcement'),
      '#description' => $this->t('🏅 <strong>OAuth 2.1 Required:</strong> Set to "Mandatory" for full OAuth 2.1 compliance. PKCE is required for all authorization code flows in OAuth 2.1 and provides essential security against authorization code interception attacks. (<a href="https://datatracker.ietf.org/doc/draft-ietf-oauth-v2-1/#section-4.1" target="_blank">OAuth 2.1 Draft Section 4.1</a>, <a href="https://datatracker.ietf.org/doc/html/rfc7636" target="_blank">RFC 7636</a>)'),
    ];

    $form['pkce']['methods'] = [
      '#type' => 'details',
      '#title' => $this->t('Challenge Methods'),
      '#open' => TRUE,
      '#description' => $this->t('Select which PKCE challenge methods to support.'),
    ];

    $form['pkce']['methods']['s256_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable S256 Challenge Method'),
      '#default_value' => $config->get('s256_enabled'),
      '#description' => $this->t('🔒 <strong>OAuth 2.1 Recommended:</strong> SHA256-based challenge method provides the highest security level. This should be enabled for OAuth 2.1 compliance and is the recommended method. (<a href="https://datatracker.ietf.org/doc/html/rfc7636#section-4.2" target="_blank">RFC 7636 Section 4.2</a>)'),
    ];

    $form['pkce']['methods']['plain_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Plain Challenge Method'),
      '#default_value' => $config->get('plain_enabled'),
      '#description' => $this->t('⚠️ <strong>OAuth 2.1 Guidance:</strong> Plain text challenge method should be DISABLED for OAuth 2.1 compliance unless legacy client compatibility is absolutely required. The plain method provides significantly less security than S256. (<a href="https://datatracker.ietf.org/doc/html/rfc7636#section-7.2" target="_blank">RFC 7636 Section 7.2</a>)'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $s256_enabled = $form_state->getValue('s256_enabled');
    $plain_enabled = $form_state->getValue('plain_enabled');
    $enforcement = $form_state->getValue('enforcement');

    // At least one method must be enabled when enforcement is not disabled.
    if ($enforcement !== 'disabled' && !$s256_enabled && !$plain_enabled) {
      $form_state->setError($form['pkce']['methods'],
        $this->t('At least one PKCE challenge method must be enabled when enforcement is not disabled.')
      );
    }

    // Warn if using plain method without S256.
    if ($plain_enabled && !$s256_enabled) {
      $this->messenger()->addWarning(
        $this->t('The plain challenge method is less secure than S256. Consider enabling S256 for better security.')
      );
    }

    // Warn if enforcement is not mandatory.
    if ($enforcement !== 'mandatory') {
      $this->messenger()->addWarning(
        $this->t('For OAuth 2.1 compliance and maximum security, consider setting enforcement to "Mandatory".')
      );
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('simple_oauth_pkce.settings')
      ->set('enforcement', $form_state->getValue('enforcement'))
      ->set('s256_enabled', $form_state->getValue('s256_enabled'))
      ->set('plain_enabled', $form_state->getValue('plain_enabled'))
      ->save();

    parent::submitForm($form, $form_state);

    // Clear caches to ensure new settings take effect.
    drupal_flush_all_caches();

    $this->messenger()->addStatus(
      $this->t('PKCE configuration has been saved. Cache cleared to apply changes.')
    );
  }

}
