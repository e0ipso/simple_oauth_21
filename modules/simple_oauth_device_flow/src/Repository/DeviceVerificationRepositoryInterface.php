<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Repository;

use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;

/**
 * Retains the combined League and local verification repository contract.
 */
interface DeviceVerificationRepositoryInterface extends DeviceCodeRepositoryInterface, DeviceVerificationOperationsInterface {}
