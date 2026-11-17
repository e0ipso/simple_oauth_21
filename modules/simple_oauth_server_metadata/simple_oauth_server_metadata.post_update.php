<?php

/**
 * @file
 * Post update functions for Simple OAuth Server Metadata.
 */

declare(strict_types=1);

/**
 * Remove the unused response type and response mode settings.
 */
function simple_oauth_server_metadata_post_update_remove_legacy_response_settings(): void {
  $config = \Drupal::configFactory()->getEditable('simple_oauth_server_metadata.settings');
  if ($config->isNew()) {
    return;
  }
  $config->clear('response_types_supported')
    ->clear('response_modes_supported')
    ->save();
}
