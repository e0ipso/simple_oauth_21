<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Validates the complete device-code generation configuration.
 *
 * @Constraint(
 *   id = "DeviceCodeGeneration",
 *   label = @Translation("Device code generation policy", context = "Validation"),
 *   type = "mapping"
 * )
 */
final class DeviceCodeGeneration extends Constraint {

  /**
   * The safe violation message, without configuration or credential values.
   */
  public string $message = 'Invalid device code generation settings. Use positive integer lifetime and attempt limits, length 6 to 12, and a unique uppercase ASCII alphabet with enough code space for the default-strength floor and the configured per-user guessing budget.';

}
