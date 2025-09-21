<?php

namespace Drupal\simple_oauth_21\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Custom access checker for OAuth 2.1 routes in Drupal 11.
 *
 * This provides a compatible access checker for routes that need anonymous
 * access but don't work properly with '_access: TRUE' in D11 test environments.
 */
class OAuth21AccessChecker implements AccessInterface {

  /**
   * Checks access for OAuth 2.1 routes.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   * @param \Symfony\Component\Routing\Route $route
   *   The route to check against.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, Route $route) {
    // OAuth 2.1 endpoints should be accessible to all users.
    return AccessResult::allowed();
  }

}
