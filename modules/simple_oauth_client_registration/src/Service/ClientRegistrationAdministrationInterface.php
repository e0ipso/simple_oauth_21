<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Service;

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Defines authorized administrative registration operations.
 */
interface ClientRegistrationAdministrationInterface {

  /**
   * Applies administrative updates or deletions with an outcome for each item.
   *
   * @param array $items
   *   A list of items with client_id, operation (update/delete), and metadata
   *   for updates. Metadata follows the single-client management contract.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The administrator performing the operations.
   * @param \Symfony\Component\Serializer\Normalizer\DenormalizerInterface $normalizer
   *   The shared client metadata denormalizer.
   *
   * @return array
   *   Ordered per-item results containing only success or a safe error code.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the account lacks the existing OAuth administrative permission.
   * @throws \InvalidArgumentException
   *   If the batch is not a list of between one and 100 items.
   */
  public function manageClients(array $items, AccountInterface $account, DenormalizerInterface $normalizer): array;

}
