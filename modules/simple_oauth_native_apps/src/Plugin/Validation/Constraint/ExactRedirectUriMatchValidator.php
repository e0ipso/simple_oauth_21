<?php

namespace Drupal\simple_oauth_native_apps\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\simple_oauth_native_apps\Service\RedirectUriValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ExactRedirectUriMatch constraint.
 */
class ExactRedirectUriMatchValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * The redirect URI validator service.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\RedirectUriValidator
   */
  protected $redirectUriValidator;

  /**
   * Constructs a new ExactRedirectUriMatchValidator.
   *
   * @param \Drupal\simple_oauth_native_apps\Service\RedirectUriValidator $redirect_uri_validator
   *   The redirect URI validator service.
   */
  public function __construct(RedirectUriValidator $redirect_uri_validator) {
    $this->redirectUriValidator = $redirect_uri_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('simple_oauth_native_apps.redirect_uri_validator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint): void {
    if (!$constraint instanceof ExactRedirectUriMatch) {
      return;
    }

    $request_uri = '';
    if (is_string($value)) {
      $request_uri = $value;
    }
    elseif (is_object($value) && method_exists($value, 'getValue')) {
      $field_values = $value->getValue();
      if (!empty($field_values) && isset($field_values[0]['value'])) {
        $request_uri = $field_values[0]['value'];
      }
    }

    if (empty($request_uri) || empty($constraint->registeredUri)) {
      return;
    }

    if (!$this->redirectUriValidator->validateExactMatch($constraint->registeredUri, $request_uri)) {
      $this->context->addViolation($constraint->exactMatchFailedMessage, [
        '%request_uri' => $request_uri,
        '%registered_uri' => $constraint->registeredUri,
      ]);
    }
  }

}
