<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_native_apps\TypedData;

use Drupal\Core\TypedData\Plugin\DataType\FloatData;
use Drupal\Core\TypedData\Type\StringInterface;

/**
 * Describes a numeric threshold without rewriting its stored representation.
 *
 * This setting predates its schema. Integers, floats and numeric strings remain
 * unchanged on config import and unrelated saves. Float and range constraints
 * validate provided values; schema casting must not turn invalid data into 0.
 * StringInterface also lets core schema checks recognize numeric strings.
 */
final class DetectionSensitivity extends FloatData implements StringInterface {

  /**
   * Returns the original numeric representation without coercion.
   *
   * @return mixed
   *   The supplied value, unchanged. Callers must validate before writing it.
   */
  public function getCastedValue(): mixed {
    return $this->getValue();
  }

}
