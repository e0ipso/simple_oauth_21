<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata;

use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Service provider for the simple_oauth_server_metadata module.
 *
 * Merges additional_claims_supported from config with the base OpenID claims.
 */
class SimpleOauthServerMetadataServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    // Get current claims parameter.
    $claims = $container->hasParameter('simple_oauth.openid.claims')
      ? $container->getParameter('simple_oauth.openid.claims')
      : [];

    // Try to load additional_claims_supported from config.
    // We need to access the database directly since the config factory
    // is not yet available during container building.
    $additional_claims = $this->getAdditionalClaimsFromConfig();

    if (!empty($additional_claims)) {
      // Merge additional claims with existing claims.
      $merged_claims = array_unique(array_merge($claims, $additional_claims));
      $container->setParameter('simple_oauth.openid.claims', $merged_claims);
    }
  }

  /**
   * Gets additional claims from config via direct database query.
   *
   * @return array
   *   The additional claims supported, or empty array if not found.
   */
  private function getAdditionalClaimsFromConfig(): array {
    try {
      $db = Database::getConnection('default');
      $result = $db->query(
        "SELECT data FROM {config} WHERE name = :name",
        [':name' => 'simple_oauth_server_metadata.settings']
      )->fetchField();

      if ($result) {
        $config = unserialize($result, ['allowed_classes' => FALSE]);
        if (isset($config['additional_claims_supported']) && is_array($config['additional_claims_supported'])) {
          return $config['additional_claims_supported'];
        }
      }
    }
    catch (\Exception $e) {
      // Config table may not exist yet during installation.
    }

    return [];
  }

}
