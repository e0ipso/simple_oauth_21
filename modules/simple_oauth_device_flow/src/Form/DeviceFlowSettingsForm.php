<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures device authorization lifetimes, user codes and cleanup.
 */
final class DeviceFlowSettingsForm extends ConfigFormBase {

  /**
   * The path validator.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected PathValidatorInterface $pathValidator;

  /**
   * Constructs the device flow settings form.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed configuration manager.
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   *   The path validator.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    PathValidatorInterface $path_validator,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->pathValidator = $path_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('path.validator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'simple_oauth_device_flow_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['simple_oauth_device_flow.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('simple_oauth_device_flow.settings');
    $numbers = [
      'device_code_lifetime' => [
        $this->t('Device code lifetime'),
        1800,
        1,
        NULL,
        $this->t('Seconds before a device authorization expires.'),
      ],
      'polling_interval' => [
        $this->t('Polling interval'),
        5,
        1,
        NULL,
        $this->t('Minimum seconds between token polling requests.'),
      ],
      'user_code_length' => [
        $this->t('User code length'),
        8,
        6,
        12,
        $this->t('Number of characters in a user code excluding its separator.'),
      ],
      'cleanup_retention_days' => [
        $this->t('Cleanup retention days'),
        7,
        1,
        NULL,
        $this->t('Days to retain completed device codes.'),
      ],
      'max_cleanup_batch_size' => [
        $this->t('Maximum cleanup batch size'),
        1000,
        1,
        NULL,
        $this->t('Maximum number of codes removed in one cleanup batch.'),
      ],
    ];
    foreach ($numbers as $key => [$title, $default, $min, $max, $description]) {
      $form[$key] = [
        '#type' => 'number',
        '#title' => $title,
        '#description' => $description,
        '#default_value' => $config->get($key) ?? $default,
        '#min' => $min,
        '#max' => $max,
        '#step' => 1,
        '#required' => TRUE,
      ];
    }
    $form['user_code_charset'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User code character set'),
      '#description' => $this->t('At least two distinct uppercase ASCII letters or digits. Exclude ambiguous characters for readability.'),
      '#default_value' => $config->get('user_code_charset') ?? 'BCDFGHJKLMNPQRSTVWXZ',
      '#required' => TRUE,
    ];
    $form['verification_uri'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Verification URI'),
      '#description' => $this->t('Local path to the device verification form or an alias of that path, beginning with a single slash.'),
      '#default_value' => $config->get('verification_uri') ?? '/oauth/device',
      '#required' => TRUE,
    ];
    $form['verification_uri_complete'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include verification URI complete'),
      '#description' => $this->t('Include a verification link containing the user code in authorization responses.'),
      '#default_value' => $config->get('verification_uri_complete') ?? $config->get('verification_uri_complete_enabled') ?? TRUE,
    ];
    $form['enable_statistics_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable statistics logging'),
      '#description' => $this->t('Log device flow usage statistics.'),
      '#default_value' => $config->get('enable_statistics_logging') ?? TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $charset = (string) $form_state->getValue('user_code_charset');
    if (!preg_match('/^[A-Z0-9]+$/D', $charset) || count(array_unique(str_split($charset))) < 2 || strlen(count_chars($charset, 3)) !== strlen($charset)) {
      $form_state->setErrorByName('user_code_charset', $this->t('Use at least two distinct uppercase letters or digits without duplicates or separators.'));
    }
    $uri = (string) $form_state->getValue('verification_uri');
    if (!preg_match('@^/(?!/)[A-Za-z0-9/_-]*$@D', $uri)) {
      $form_state->setErrorByName('verification_uri', $this->t('Enter a local path beginning with one slash, without a query or fragment.'));
      return;
    }
    $url = $this->pathValidator->getUrlIfValid($uri);
    if (!$url || !$url->isRouted() || $url->getRouteName() !== 'simple_oauth_device_flow.device_verification_form') {
      $form_state->setErrorByName('verification_uri', $this->t('Enter the device verification form path or an alias of it.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('simple_oauth_device_flow.settings');
    $integer_keys = [
      'device_code_lifetime',
      'polling_interval',
      'user_code_length',
      'cleanup_retention_days',
      'max_cleanup_batch_size',
    ];
    foreach ($integer_keys as $key) {
      $config->set($key, (int) $form_state->getValue($key));
    }
    foreach (['user_code_charset', 'verification_uri'] as $key) {
      $config->set($key, (string) $form_state->getValue($key));
    }
    foreach (['verification_uri_complete', 'enable_statistics_logging'] as $key) {
      $config->set($key, (bool) $form_state->getValue($key));
    }
    // Keep installations using the earlier option name consistent.
    $config->set('verification_uri_complete_enabled', (bool) $form_state->getValue('verification_uri_complete'));
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
