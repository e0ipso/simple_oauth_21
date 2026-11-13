<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\simple_oauth_device_flow\Repository\DeviceVerificationOperationsInterface;
use Drupal\simple_oauth_device_flow\Entity\DeviceCode;
use Drupal\simple_oauth\Entities\ScopeEntity;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use Drupal\simple_oauth_device_flow\Service\DeviceCodeService;
use Drupal\simple_oauth_device_flow\Service\DeviceFlowSettingsService;
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
   * The flood event for failed device verification attempts.
   */
  private const VERIFICATION_EVENT = 'simple_oauth_device_flow.verification';

  /**
   * The default Drupal flood window, in seconds.
   */
  private const VERIFICATION_WINDOW = 3600;

  /**
   * The device code repository.
   */
  protected ?DeviceVerificationOperationsInterface $deviceCodeRepository;

  /**
   * The device code service.
   */
  protected DeviceCodeService $deviceCodeService;

  /**
   * The user code generator.
   */
  protected UserCodeGenerator $userCodeGenerator;

  /**
   * The current user.
   */
  protected AccountInterface $currentUser;

  /**
   * The logger for device flow operations.
   */
  protected LoggerInterface $logger;

  /**
   * The persistent verification attempt limiter.
   */
  protected FloodInterface $flood;

  /**
   * The device flow settings service.
   */
  protected DeviceFlowSettingsService $settings;

  /**
   * Constructs a DeviceVerificationForm object.
   *
   * @param \League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface|\Drupal\simple_oauth_device_flow\Repository\DeviceVerificationOperationsInterface $device_code_repository
   *   The device code repository.
   * @param \Drupal\simple_oauth_device_flow\Service\DeviceCodeService $device_code_service
   *   The device code service.
   * @param \Drupal\simple_oauth_device_flow\Service\UserCodeGenerator $user_code_generator
   *   The user code generator.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger for device flow operations.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The persistent verification attempt limiter.
   * @param \Drupal\simple_oauth_device_flow\Service\DeviceFlowSettingsService $settings
   *   The device flow settings service.
   */
  public function __construct(
    DeviceCodeRepositoryInterface|DeviceVerificationOperationsInterface $device_code_repository,
    DeviceCodeService $device_code_service,
    UserCodeGenerator $user_code_generator,
    AccountInterface $current_user,
    LoggerInterface $logger,
    FloodInterface $flood,
    DeviceFlowSettingsService $settings,
  ) {
    // Retain bare League callers until a breaking release can require local
    // operations. They continue to fail closed as verification unavailable.
    $this->deviceCodeRepository = $device_code_repository instanceof DeviceVerificationOperationsInterface ? $device_code_repository : NULL;
    $this->deviceCodeService = $device_code_service;
    $this->userCodeGenerator = $user_code_generator;
    $this->currentUser = $current_user;
    $this->logger = $logger;
    $this->flood = $flood;
    $this->settings = $settings;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('simple_oauth_device_flow.verification_repository'),
      $container->get('simple_oauth_device_flow.device_code_service'),
      $container->get('simple_oauth_device_flow.user_code_generator'),
      $container->get('current_user'),
      $container->get('logger.channel.simple_oauth_device_flow'),
      $container->get('flood'),
      $container->get('simple_oauth_device_flow.settings'),
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

    // Both controller entry points build this form before accepting guesses.
    if (!$this->isVerificationAllowed()) {
      $form['rate_limit'] = [
        '#markup' => $this->t('Too many failed verification attempts. Please try again later.'),
      ];
      $form['#cache']['max-age'] = 0;
      return $form;
    }

    $form['#attributes']['class'][] = 'device-authorization-form';
    $form['#cache']['max-age'] = 0;
    $confirmation = $form_state->get('confirmation');

    // Instructions for the user.
    $form['instructions'] = [
      '#type' => 'markup',
      '#markup' => '<div class="device-auth-instructions">' .
      '<h2>' . $this->t('Device Authorization') . '</h2>' .
      '<p>' . $this->t('Enter the code displayed on your device to authorize it.') . '</p>' .
      '</div>',
    ];

    // Bound prefilled input before passing it to the text element.
    $prefilled = $this->getRequest()->query->all()['user_code'] ?? '';
    $prefilled = is_string($prefilled) && strlen($prefilled) <= 128
      && mb_strlen($prefilled, 'UTF-8') <= 32 ? $prefilled : '';

    // User code input field.
    $form['user_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Device Code'),
      '#description' => $this->t('Enter the code shown on your device (e.g., ABCD-EFGH).'),
      '#required' => TRUE,
      '#maxlength' => 32,
      '#size' => 16,
      '#placeholder' => 'XXXX-XXXX',
      '#attributes' => [
        'class' => ['device-code-input'],
        'autocomplete' => 'off',
        'autocapitalize' => 'characters',
        'spellcheck' => 'false',
      ],
      '#default_value' => $prefilled,
    ];

    // Action buttons.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['authorize'] = [
      '#type' => 'submit',
      '#value' => is_array($confirmation) ? $this->t('Authorize Device') : $this->t('Review request'),
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
      '#access' => (bool) $confirmation,
    ];

    if (is_array($confirmation)) {
      // Values are bound in server-side form state, never trusted hidden input.
      $form['user_code'] = [
        '#type' => 'value',
        '#value' => $confirmation['user_code'],
      ];
      $device = $this->deviceCodeRepository?->getDeviceCodeEntityByDeviceCode($confirmation['identifier']);
      if ($device instanceof DeviceCode) {
        $client = $device->getClient();
        $form['instructions']['#markup'] = $this->t('Review this request before allowing access to your account.');
        $form['client'] = [
          '#type' => 'item',
          '#title' => $this->t('Application'),
          '#plain_text' => $client->getName() . ' (' . $client->getIdentifier() . ')',
        ];
        $items = [];
        foreach ($device->getScopes() as $scope) {
          $description = $scope instanceof ScopeEntity ? $scope->getDescription('urn:ietf:params:oauth:grant-type:device_code') : '';
          $items[] = ['#plain_text' => $scope->getIdentifier() . ($description !== '' ? ': ' . $description : '')];
        }
        $form['scopes'] = [
          '#theme' => 'item_list',
          '#title' => $this->t('Requested access'),
          '#items' => $items,
          '#empty' => $this->t('No specific permissions were requested.'),
        ];
      }
    }

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
    if (!$this->isVerificationAllowed()) {
      $form_state->setErrorByName('user_code', $this->t('Too many failed verification attempts. Please try again later.'));
      return;
    }

    if ($this->currentUser->isAnonymous() || $this->deviceCodeRepository === NULL) {
      $form_state->setErrorByName('user_code', $this->t('Device verification is unavailable.'));
      return;
    }
    $confirmation = $form_state->get('confirmation');
    $user_code = $confirmation['user_code'] ?? $form_state->getValue('user_code');

    // Reject oversized raw input before any normalization or storage lookup.
    $candidates = is_string($user_code) ? $this->userCodeGenerator->getVerificationCandidates($user_code) : [];
    $device_code_entity = NULL;
    try {
      foreach ($candidates as $candidate) {
        $found = $this->deviceCodeRepository->getDeviceCodeEntityByUserCode($candidate);
        if ($found !== NULL) {
          if ($device_code_entity !== NULL && $device_code_entity->getIdentifier() !== $found->getIdentifier()) {
            throw new \UnexpectedValueException('Ambiguous device user code.');
          }
          $device_code_entity = $found;
        }
      }
    }
    catch (\Exception) {
      $device_code_entity = NULL;
    }

    if (empty($device_code_entity) || (is_array($confirmation) && $device_code_entity->getIdentifier() !== $confirmation['identifier'])) {
      $this->registerFailedVerification();
      $form_state->setErrorByName('user_code', $this->t('Invalid user code. Please check the code and try again.'));
      $this->logger->notice('Device verification failed: user code not found');
      return;
    }

    // Check if device code has expired.
    if ($device_code_entity->getExpiryDateTime() <= new \DateTimeImmutable()) {
      $this->registerFailedVerification();
      $form_state->setErrorByName('user_code', $this->t('Device code has expired. Please request a new code from your device.'));
      $this->logger->notice('Device verification failed: expired device code');
      return;
    }

    // A decision or revocation is terminal, including a denied authorization.
    if ($device_code_entity->getUserIdentifier() !== NULL && !$device_code_entity->getUserApproved()
      || $this->deviceCodeRepository->isDeviceCodeRevoked($device_code_entity->getIdentifier())) {
      $this->registerFailedVerification();
      $form_state->setErrorByName('user_code', $this->t('This device authorization is no longer available. Please request a new code from your device.'));
      return;
    }

    // Check if device code is already authorized.
    if ($device_code_entity->getUserApproved()) {
      $this->registerFailedVerification();
      $form_state->setErrorByName('user_code', $this->t('This device has already been authorized.'));
      $this->logger->notice('Device verification failed: device already authorized');
      return;
    }

    // Store the device code entity for use in submit handler.
    $form_state->setTemporaryValue('device_code_entity', $device_code_entity);
  }

  /**
   * Checks the current user's failed verification budget before any lookup.
   *
   * @return bool
   *   TRUE when another verification attempt is allowed.
   */
  private function isVerificationAllowed(): bool {
    return $this->flood->isAllowed(
      self::VERIFICATION_EVENT,
      max(1, $this->settings->getMaxAuthorizationAttempts()),
      self::VERIFICATION_WINDOW,
      (string) $this->currentUser->id(),
    );
  }

  /**
   * Records one failed lookup without counting successes or blocked retries.
   */
  private function registerFailedVerification(): void {
    $this->flood->register(
      self::VERIFICATION_EVENT,
      self::VERIFICATION_WINDOW,
      (string) $this->currentUser->id(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $device_code_entity = $form_state->getTemporaryValue('device_code_entity');

    if (empty($device_code_entity)) {
      $this->messenger()->addError($this->t('An error occurred during device authorization. Please try again.'));
      return;
    }

    try {
      if (!is_array($form_state->get('confirmation'))) {
        $form_state->set('confirmation', [
          'identifier' => $device_code_entity->getIdentifier(),
          'user_code' => $device_code_entity->getUserCode(),
        ]);
        $form_state->setRebuild();
        return;
      }
      if ($this->deviceCodeRepository === NULL) {
        throw new \RuntimeException('Device verification is unavailable.');
      }
      $this->deviceCodeRepository->decide(
        $form_state->get('confirmation')['identifier'], (string) $this->currentUser->id(), TRUE,
      );

      $this->logger->info('Device authorized successfully for user @user_id', [
        '@user_id' => $this->currentUser->id(),
      ]);

      $this->messenger()->addStatus($this->t('Device authorized successfully! You can now close this page and continue using your device.'));

      // Redirect to avoid resubmission.
      $form_state->setRedirect('simple_oauth_device_flow.device_verification_form');
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to authorize device: @exception_type', [
        '@exception_type' => get_class($e),
      ]);

      $this->messenger()->addError($this->t('An error occurred while authorizing the device. Please try again.'));
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

    if (empty($device_code_entity)) {
      $this->messenger()->addError($this->t('An error occurred during device authorization. Please try again.'));
      return;
    }

    try {
      if (!is_array($form_state->get('confirmation')) || $this->deviceCodeRepository === NULL) {
        throw new \RuntimeException('Device request has not been reviewed.');
      }
      $this->deviceCodeRepository->decide(
        $form_state->get('confirmation')['identifier'], (string) $this->currentUser->id(), FALSE,
      );

      $this->logger->info('Device authorization denied by user @user_id', [
        '@user_id' => $this->currentUser->id(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to deny device authorization: @exception_type', [
        '@exception_type' => get_class($e),
      ]);
      $this->messenger()->addError($this->t('An error occurred while denying device authorization. Please try again.'));
      return;
    }

    $this->messenger()->addMessage($this->t('Device authorization was denied. The device will not have access to your account.'));

    // Redirect to avoid resubmission.
    $form_state->setRedirect('simple_oauth_device_flow.device_verification_form');
  }

}
