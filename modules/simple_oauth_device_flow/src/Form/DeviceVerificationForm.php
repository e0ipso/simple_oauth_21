<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\simple_oauth_device_flow\Repository\DeviceCodeRepository;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use Drupal\simple_oauth_device_flow\Service\DeviceCodeService;
use Drupal\simple_oauth_device_flow\Service\UserCodeGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Device verification form for OAuth 2.0 Device Authorization Grant.
 *
 * Handles user input of device authorization codes and associates devices
 * with authenticated users as specified in RFC 8628.
 */
final class DeviceVerificationForm extends FormBase {

  /**
   * The device code repository.
   */
  private DeviceCodeRepository $deviceCodeRepository;

  /**
   * The device code service.
   */
  private DeviceCodeService $deviceCodeService;

  /**
   * The user code generator.
   */
  private UserCodeGenerator $userCodeGenerator;

  /**
   * The current user.
   */
  private AccountInterface $currentUser;

  /**
   * The messenger service.
   */
  private MessengerInterface $messenger;

  /**
   * The logger for device flow operations.
   */
  private LoggerInterface $logger;

  /**
   * Constructs a DeviceVerificationForm object.
   *
   * @param \Drupal\simple_oauth_device_flow\Repository\DeviceCodeRepository $device_code_repository
   *   The device code repository.
   * @param \Drupal\simple_oauth_device_flow\Service\DeviceCodeService $device_code_service
   *   The device code service.
   * @param \Drupal\simple_oauth_device_flow\Service\UserCodeGenerator $user_code_generator
   *   The user code generator.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger for device flow operations.
   */
  public function __construct(
    DeviceCodeRepositoryInterface $device_code_repository,
    DeviceCodeService $device_code_service,
    UserCodeGenerator $user_code_generator,
    AccountInterface $current_user,
    MessengerInterface $messenger,
    LoggerInterface $logger,
  ) {
    $this->deviceCodeRepository = $device_code_repository;
    $this->deviceCodeService = $device_code_service;
    $this->userCodeGenerator = $user_code_generator;
    $this->currentUser = $current_user;
    $this->messenger = $messenger;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('simple_oauth_device_flow.repository.device_code'),
      $container->get('simple_oauth_device_flow.device_code_service'),
      $container->get('simple_oauth_device_flow.user_code_generator'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('logger.channel.simple_oauth')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'simple_oauth_device_flow_verification_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Check if user is authenticated.
    if ($this->currentUser->isAnonymous()) {
      // Redirect to login with destination parameter.
      $login_url = Url::fromRoute('user.login', [], [
        'query' => [
          'destination' => $this->getRequest()->getRequestUri(),
        ],
      ]);

      $form['#attached']['drupalSettings']['simple_oauth_device_flow']['redirect_url'] = $login_url->toString();
      $form['#attached']['library'][] = 'core/drupal.redirect';

      $form['login_message'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' .
        $this->t('You must <a href="@login_url">log in</a> to authorize this device.', [
          '@login_url' => $login_url->toString(),
        ]) . '</div>',
      ];

      return $form;
    }

    $form['#attributes']['class'][] = 'device-authorization-form';

    // Instructions for the user.
    $form['instructions'] = [
      '#type' => 'markup',
      '#markup' => '<div class="device-auth-instructions">' .
      '<h2>' . $this->t('Device Authorization') . '</h2>' .
      '<p>' . $this->t('Enter the code displayed on your device to authorize it.') . '</p>' .
      '</div>',
    ];

    // User code input field.
    $form['user_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Device Code'),
      '#description' => $this->t('Enter the code shown on your device (e.g., ABCD-EFGH).'),
      '#required' => TRUE,
      '#maxlength' => 16,
      '#size' => 16,
      '#placeholder' => 'XXXX-XXXX',
      '#attributes' => [
        'class' => ['device-code-input'],
        'autocomplete' => 'off',
        'autocapitalize' => 'characters',
        'spellcheck' => 'false',
      ],
      '#default_value' => $this->getRequest()->query->get('user_code', ''),
    ];

    // Action buttons.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['authorize'] = [
      '#type' => 'submit',
      '#value' => $this->t('Authorize Device'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['button--authorize'],
      ],
    ];

    $form['actions']['deny'] = [
      '#type' => 'submit',
      '#value' => $this->t('Deny Access'),
      '#button_type' => 'danger',
      '#attributes' => [
        'class' => ['button--deny'],
      ],
      '#submit' => ['::submitDeny'],
    ];

    // Security notice.
    $form['security_notice'] = [
      '#type' => 'markup',
      '#markup' => '<div class="device-auth-security-notice">' .
      '<p class="description">' .
      $this->t('Only authorize devices that you trust. This will give the device access to your account.') .
      '</p>' .
      '</div>',
      '#weight' => 10,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $user_code = trim((string) $form_state->getValue('user_code'));

    if (empty($user_code)) {
      $form_state->setErrorByName('user_code', $this->t('Device code is required.'));
      return;
    }

    // Validate user code format.
    if (!$this->userCodeGenerator->validateCodeFormat($user_code)) {
      $form_state->setErrorByName('user_code', $this->t('Invalid device code format. Please check the code and try again.'));
      return;
    }

    // Normalize the user code for lookup.
    $normalized_code = $this->userCodeGenerator->normalizeUserCode($user_code);
    $formatted_code = $this->userCodeGenerator->formatUserCode($normalized_code);

    // Find device code by user code.
    $device_code_entity = $this->deviceCodeRepository->getDeviceCodeEntityByUserCode($formatted_code);

    if (empty($device_code_entity)) {
      $form_state->setErrorByName('user_code', $this->t('Device code not found. Please check the code and try again.'));
      $this->logger->notice('Device verification failed: user code not found: @user_code', [
        '@user_code' => $formatted_code,
      ]);
      return;
    }

    // Check if device code has expired.
    if ($device_code_entity->getExpiryDateTime() <= new \DateTimeImmutable()) {
      $form_state->setErrorByName('user_code', $this->t('Device code has expired. Please request a new code from your device.'));
      $this->logger->notice('Device verification failed: expired device code: @user_code', [
        '@user_code' => $formatted_code,
      ]);
      return;
    }

    // Check if device code is already authorized.
    if ($device_code_entity->get('authorized')->value) {
      $form_state->setErrorByName('user_code', $this->t('This device has already been authorized.'));
      $this->logger->notice('Device verification failed: device already authorized: @user_code', [
        '@user_code' => $formatted_code,
      ]);
      return;
    }

    // Store the device code entity for use in submit handler.
    $form_state->setTemporaryValue('device_code_entity', $device_code_entity);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $device_code_entity = $form_state->getTemporaryValue('device_code_entity');

    if (empty($device_code_entity)) {
      $this->messenger->addError($this->t('An error occurred during device authorization. Please try again.'));
      return;
    }

    try {
      // Associate device with current user.
      $device_code_entity->set('user_id', $this->currentUser->id());
      $device_code_entity->set('authorized', TRUE);
      $device_code_entity->set('authorized_at', time());
      $device_code_entity->save();

      $this->logger->info('Device authorized successfully for user @user_id with device code @device_code', [
        '@user_id' => $this->currentUser->id(),
        '@device_code' => $device_code_entity->getIdentifier(),
      ]);

      $this->messenger->addStatus($this->t('Device authorized successfully! You can now close this page and continue using your device.'));

      // Redirect to avoid resubmission.
      $form_state->setRedirect('simple_oauth_device_flow.device_verification_form');
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to authorize device: @message', [
        '@message' => $e->getMessage(),
      ]);

      $this->messenger->addError($this->t('An error occurred while authorizing the device. Please try again.'));
    }
  }

  /**
   * Submit handler for deny button.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitDeny(array &$form, FormStateInterface $form_state): void {
    $device_code_entity = $form_state->getTemporaryValue('device_code_entity');

    if (!empty($device_code_entity)) {
      $this->logger->info('Device authorization denied by user @user_id for device code @device_code', [
        '@user_id' => $this->currentUser->id(),
        '@device_code' => $device_code_entity->getIdentifier(),
      ]);
    }

    $this->messenger->addMessage($this->t('Device authorization was denied. The device will not have access to your account.'));

    // Redirect to avoid resubmission.
    $form_state->setRedirect('simple_oauth_device_flow.device_verification_form');
  }

}
