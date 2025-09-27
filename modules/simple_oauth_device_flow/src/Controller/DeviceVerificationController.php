<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\simple_oauth_device_flow\Form\DeviceVerificationForm;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for device verification form display and handling.
 *
 * Manages the device authorization flow user interface as specified
 * in RFC 8628 OAuth 2.0 Device Authorization Grant.
 */
final class DeviceVerificationController extends ControllerBase {

  /**
   * The form builder service.
   */
  private FormBuilderInterface $formBuilder;

  /**
   * The current user.
   */
  private AccountInterface $currentUser;

  /**
   * The logger for device flow operations.
   */
  private LoggerInterface $logger;

  /**
   * Constructs a DeviceVerificationController object.
   *
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger for device flow operations.
   */
  public function __construct(
    FormBuilderInterface $form_builder,
    AccountInterface $current_user,
    LoggerInterface $logger,
  ) {
    $this->formBuilder = $form_builder;
    $this->currentUser = $current_user;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('form_builder'),
      $container->get('current_user'),
      $container->get('logger.channel.simple_oauth')
    );
  }

  /**
   * Displays the device verification form.
   *
   * Handles GET requests to the device verification endpoint. If the user
   * is not authenticated, they will be redirected to login with a destination
   * parameter to return to this page after authentication.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   The device verification form render array or redirect response.
   */
  public function form(Request $request) {
    // Check if user is authenticated.
    if ($this->currentUser->isAnonymous()) {
      // Build login URL with destination parameter.
      $destination = $request->getRequestUri();
      $login_url = Url::fromRoute('user.login', [], [
        'query' => ['destination' => $destination],
      ]);

      $this->logger->info('Redirecting anonymous user to login for device verification. Destination: @destination', [
        '@destination' => $destination,
      ]);

      // Return redirect response to login page.
      return new RedirectResponse($login_url->toString());
    }

    // Log the device verification page access.
    $user_code = $request->query->get('user_code');
    if (!empty($user_code)) {
      $this->logger->info('Device verification form accessed by user @user_id with pre-filled user code', [
        '@user_id' => $this->currentUser->id(),
      ]);
    }
    else {
      $this->logger->info('Device verification form accessed by user @user_id', [
        '@user_id' => $this->currentUser->id(),
      ]);
    }

    // Build and return the device verification form.
    $form = $this->formBuilder->getForm(DeviceVerificationForm::class);

    // Add page metadata.
    $form['#title'] = $this->t('Device Authorization');
    $form['#cache'] = [
      'contexts' => ['user', 'url.query_args:user_code'],
    // Don't cache the form to ensure fresh CSRF tokens.
      'max-age' => 0,
    ];

    // Add CSS for better presentation.
    $form['#attached']['library'][] = 'simple_oauth_device_flow/device_verification';

    return $form;
  }

  /**
   * Handles POST submissions to the device verification endpoint.
   *
   * This method is called when the form is submitted. The actual form
   * processing is handled by the DeviceVerificationForm class, but this
   * method can be used for additional processing if needed.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   *
   * @return array
   *   The device verification form render array.
   */
  public function verify(Request $request) {
    // For POST requests, we still want to display the form.
    // The form handling is done by the DeviceVerificationForm class.
    return $this->form($request);
  }

}
