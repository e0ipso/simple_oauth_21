<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_21\Plugin;

/**
 * Optionally declares a grant's protocol identifier independently of its ID.
 *
 * Implement this on OAuth grant plugins whose registration ID differs from the
 * grant_type value accepted on the wire. Discovery must not create a
 * client-specific League grant to obtain this value. Plugins without this
 * contract retain their registration ID as their protocol identifier.
 */
interface Oauth2GrantIdentifierInterface {

  /**
   * Gets the OAuth grant_type value without client-specific initialization.
   *
   * @return string
   *   The protocol identifier accepted by the plugin's League grant.
   */
  public function getGrantTypeIdentifier(): string;

}
