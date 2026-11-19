<?php

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
   * Direct database access is required here because the config factory
   * is not yet available during container building in a ServiceProvider.
   *
   * @return array<string>
   *   The additional claims supported, or empty array if not found.
   */
  private function getAdditionalClaimsFromConfig(): array {
    try {
      $connection = Database::getConnection('default');
      $result = $connection->select('config', 'c')
        ->fields('c', ['data'])
        ->condition('name', 'simple_oauth_server_metadata.settings')
        ->execute()
        ->fetchField();

      if ($result !== FALSE && is_string($result)) {
        $config = unserialize($result, ['allowed_classes' => FALSE]);
        if (is_array($config) && isset($config['additional_claims_supported']) && is_array($config['additional_claims_supported'])) {
          return $config['additional_claims_supported'];
        }
      }
    }
    catch (\Exception) {
      // Config table may not exist yet during installation.
    }

    return [];
  }

}
