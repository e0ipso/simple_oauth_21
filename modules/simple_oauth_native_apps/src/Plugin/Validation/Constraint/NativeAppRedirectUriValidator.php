<?php

namespace Drupal\simple_oauth_native_apps\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\simple_oauth_native_apps\Service\RedirectUriValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the NativeAppRedirectUri constraint.
 */
class NativeAppRedirectUriValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * The redirect URI validator service.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\RedirectUriValidator
   */
  protected $redirectUriValidator;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new NativeAppRedirectUriValidator.
   *
   * @param \Drupal\simple_oauth_native_apps\Service\RedirectUriValidator $redirect_uri_validator
   *   The redirect URI validator service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(RedirectUriValidator $redirect_uri_validator, ConfigFactoryInterface $config_factory) {
    $this->redirectUriValidator = $redirect_uri_validator;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('simple_oauth_native_apps.redirect_uri_validator'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    if (!$constraint instanceof NativeAppRedirectUri) {
      return;
    }

    // Handle different value types.
    $uris = [];
    if (is_string($value)) {
      $uris = [$value];
    }
    elseif (is_object($value) && method_exists($value, 'getValue')) {
      $field_values = $value->getValue();
      foreach ($field_values as $item) {
        if (isset($item['value'])) {
          $uris[] = $item['value'];
        }
      }
    }
    elseif (is_array($value)) {
      $uris = $value;
    }

    // Validate each URI.
    foreach ($uris as $uri) {
      if (empty($uri)) {
        continue;
      }

      $this->validateSingleUri($uri, $constraint);
    }
  }

  /**
   * Validates a single URI.
   *
   * @param string $uri
   *   The URI to validate.
   * @param \Drupal\simple_oauth_native_apps\Plugin\Validation\Constraint\NativeAppRedirectUri $constraint
   *   The constraint.
   */
  protected function validateSingleUri(string $uri, NativeAppRedirectUri $constraint): void {
    // Basic URI format validation.
    if (!filter_var($uri, FILTER_VALIDATE_URL)) {
      $this->context->addViolation($constraint->invalidUriMessage, ['%uri' => $uri]);
      return;
    }

    $parsed = parse_url($uri);
    if (!$parsed || !isset($parsed['scheme'])) {
      $this->context->addViolation($constraint->invalidUriMessage, ['%uri' => $uri]);
      return;
    }

    $scheme = strtolower($parsed['scheme']);

    // Handle different URI types.
    switch ($scheme) {
      case 'http':
      case 'https':
        $this->validateLoopbackUri($uri, $constraint);
        break;

      default:
        $this->validateCustomSchemeUri($uri, $constraint);
        break;
    }
  }

  /**
   * Validates a loopback URI.
   *
   * @param string $uri
   *   The URI to validate.
   * @param \Drupal\simple_oauth_native_apps\Plugin\Validation\Constraint\NativeAppRedirectUri $constraint
   *   The constraint.
   */
  protected function validateLoopbackUri(string $uri, NativeAppRedirectUri $constraint): void {
    if (!$this->redirectUriValidator->validateLoopbackInterface($uri)) {
      // Check if loopbacks are disabled.
      $config = $this->configFactory->get('simple_oauth_native_apps.settings');
      if (!$config->get('allow_loopback_redirects')) {
        $this->context->addViolation($constraint->loopbackDisallowedMessage, ['%uri' => $uri]);
        return;
      }

      // Invalid loopback address.
      $this->context->addViolation($constraint->invalidLoopbackMessage, ['%uri' => $uri]);
    }
  }

  /**
   * Validates a custom scheme URI.
   *
   * @param string $uri
   *   The URI to validate.
   * @param \Drupal\simple_oauth_native_apps\Plugin\Validation\Constraint\NativeAppRedirectUri $constraint
   *   The constraint.
   */
  protected function validateCustomSchemeUri(string $uri, NativeAppRedirectUri $constraint): void {
    if (!$this->redirectUriValidator->validateCustomScheme($uri)) {
      $parsed = parse_url($uri);
      $scheme = $parsed['scheme'] ?? '';

      // Check if custom schemes are disabled.
      $config = $this->configFactory->get('simple_oauth_native_apps.settings');
      if (!$config->get('allow_custom_uri_schemes')) {
        $this->context->addViolation($constraint->customSchemeDisallowedMessage, ['%uri' => $uri]);
        return;
      }

      // Check for dangerous schemes.
      $dangerous_schemes = [
        'javascript',
        'data',
        'file',
        'ftp',
        'mailto',
        'tel',
        'sms',
      ];

      if (in_array(strtolower($scheme), $dangerous_schemes)) {
        $this->context->addViolation($constraint->dangerousSchemeMessage, ['%uri' => $uri]);
        return;
      }

      // Invalid custom scheme format.
      $this->context->addViolation($constraint->invalidCustomSchemeMessage, ['%uri' => $uri]);
    }
  }

}
