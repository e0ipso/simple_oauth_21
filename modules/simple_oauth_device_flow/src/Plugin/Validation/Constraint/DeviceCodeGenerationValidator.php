<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Plugin\Validation\Constraint;

use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\simple_oauth_device_flow\Service\DeviceFlowSettingsService;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Adapts typed configuration validation to the shared issuance policy.
 */
final class DeviceCodeGenerationValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof DeviceCodeGeneration) {
      throw new UnexpectedTypeException($constraint, DeviceCodeGeneration::class);
    }
    $data = $value instanceof TypedDataInterface ? $value->getValue() : $value;
    try {
      if (!is_array($data)) {
        throw new \UnexpectedValueException('Device generation settings must be a mapping.');
      }
      DeviceFlowSettingsService::resolveGenerationSettings($data);
    }
    catch (\UnexpectedValueException) {
      $this->context->addViolation($constraint->message);
    }
  }

}
