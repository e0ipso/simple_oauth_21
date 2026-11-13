<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_native_apps\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\simple_oauth_native_apps\Service\RedirectUriValidator;
use Drupal\simple_oauth_native_apps\Service\RedirectPolicyValue;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the NativeAppRedirectUri constraint.
 */
class NativeAppRedirectUriValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a new NativeAppRedirectUriValidator.
   *
   * @param \Drupal\simple_oauth_native_apps\Service\RedirectUriValidator $redirectUriValidator
   *   The redirect URI validator service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected readonly RedirectUriValidator $redirectUriValidator,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

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
  public function validate($value, Constraint $constraint): void {
    if (!$constraint instanceof NativeAppRedirectUri) {
      return;
    }

    if ($value instanceof FieldItemListInterface && $value->getEntity() instanceof Consumer) {
      foreach ($value as $delta => $item) {
        $uri = $item->get('value')->getValue();
        if (!is_string($uri) || $uri === '') {
          continue;
        }
        try {
          $valid = $this->redirectUriValidator->validateConsumerRedirectUri($uri, $value->getEntity());
        }
        catch (\UnexpectedValueException) {
          $this->context->buildViolation($constraint->policyConflictMessage)
            ->atPath($delta . '.value')->addViolation();
          continue;
        }
        if (!$valid) {
          $this->context->buildViolation($constraint->invalidUriMessage, ['%uri' => $uri])
            ->atPath($delta . '.value')->addViolation();
        }
      }
      return;
    }

    // Preserve standalone constraint calls without consumer context.
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
    $scheme = parse_url($uri, PHP_URL_SCHEME);
    if (!is_string($scheme)) {
      $this->context->addViolation($constraint->invalidUriMessage, ['%uri' => $uri]);
      return;
    }
    // Retain the protected scheme handlers for existing validator extensions.
    if (strtolower($scheme) === 'http') {
      $this->validateLoopbackUri($uri, $constraint);
    }
    elseif (strtolower($scheme) !== 'https') {
      $this->validateCustomSchemeUri($uri, $constraint);
    }
    elseif (!$this->redirectUriValidator->validateRedirectUri($uri)) {
      $this->context->addViolation($constraint->invalidUriMessage, ['%uri' => $uri]);
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
      $permission = RedirectPolicyValue::normalize($config->get('allow.loopback_redirects') ?? 'auto-detect');
      if (!in_array($permission, ['native', 'auto-detect'], TRUE)) {
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
      $permission = RedirectPolicyValue::normalize($config->get('allow.custom_uri_schemes') ?? 'auto-detect');
      if (!in_array($permission, ['native', 'auto-detect'], TRUE)) {
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
