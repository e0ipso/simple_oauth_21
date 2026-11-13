<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_device_flow\Kernel\Fixture;

use Drupal\simple_oauth_device_flow\Repository\DeviceCodeLifecycleOperationsInterface;
use Drupal\simple_oauth_device_flow\Repository\DeviceCodeMaintenanceInterface;

/**
 * Combines storage-free lifecycle and maintenance operations for test doubles.
 */
interface MaintainedLifecycleInterface extends DeviceCodeLifecycleOperationsInterface, DeviceCodeMaintenanceInterface {}
