<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_device_flow\Entity;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Owns the device table's unique user codes and lookup/cleanup indexes.
 */
final class DeviceCodeStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $table = $entity_type->getBaseTable();
    $schema[$table]['unique keys']['user_code'] = ['user_code'];
    foreach (['client_id', 'expires_at', 'user_id'] as $column) {
      $schema[$table]['indexes'][$column] = [$column];
    }
    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  protected function getSharedTableFieldSchema(FieldStorageDefinitionInterface $storage_definition, $table_name, array $column_mapping) {
    $schema = parent::getSharedTableFieldSchema($storage_definition, $table_name, $column_mapping);
    // Preserve the former hook table's required columns on fresh installs.
    // Retain recorded legacy nullability until a separate field migration can
    // validate existing rows without rebuilding tables or changing values.
    if (in_array($storage_definition->getName(), ['user_code', 'client_id', 'created_at', 'expires_at'], TRUE)) {
      $installed = $this->loadFieldSchemaData($storage_definition);
      foreach ($column_mapping as $column) {
        $schema['fields'][$column]['not null'] = $installed[$table_name]['fields'][$column]['not null'] ?? TRUE;
      }
    }
    return $schema;
  }

  /**
   * Adopts an installed table without rebuilding it or changing any rows.
   *
   * @param \Drupal\Core\Entity\ContentEntityTypeInterface $entity_type
   *   The last installed entity definition with the schema handler updated.
   *
   * @throws \RuntimeException
   *   If data conflicts, schema is missing, or a key cannot be verified.
   */
  public function adoptInstalledTable(ContentEntityTypeInterface $entity_type): void {
    $table = $entity_type->getBaseTable();
    $schema_handler = $this->database->schema();
    if (!$schema_handler->tableExists($table)) {
      throw new \RuntimeException('The device code table is missing. Restore it from backup before retrying the update.');
    }
    // Check before any DDL, including retries. Do not expose credentials.
    $duplicates = $this->database->select($table, 'device')
      ->fields('device', ['user_code'])
      ->groupBy('user_code')
      ->having('COUNT(*) > 1')
      ->range(0, 1)
      ->execute()->fetchField();
    if ($duplicates !== FALSE) {
      throw new \RuntimeException('Duplicate user codes prevent enforcing device-code uniqueness. Back up the database, resolve the conflicting authorizations through an explicit administrative recovery, then retry. No codes have been changed.');
    }
    $schema = $this->getEntitySchema($entity_type, TRUE);
    if (!$this->hasUniqueUserCodeKey()) {
      if ($schema_handler->indexExists($table, 'user_code')) {
        throw new \RuntimeException('The user_code index conflicts with the required unique key. Back up the database and repair the index definition before retrying. No codes have been changed.');
      }
      $schema_handler->addUniqueKey($table, 'user_code', ['user_code']);
    }
    foreach ($schema[$table]['indexes'] as $name => $columns) {
      if (!$schema_handler->indexExists($table, $name)) {
        $schema_handler->addIndex($table, $name, $columns, $schema[$table]);
      }
    }
    // Track ownership after all DDL succeeds. Completed DDL survives retries.
    $this->saveEntitySchemaData($entity_type, $schema);
  }

  /**
   * Checks a complete, nonpartial unique key using supported driver catalogs.
   *
   * Schema API has no portable public unique-key inspection operation. Check
   * catalogs rather than dropping existing keys or trusting an index's name.
   *
   * @return bool
   *   TRUE if an existing key enforces uniqueness of the whole user code.
   *
   * @throws \RuntimeException
   *   If the database driver cannot safely verify the key.
   */
  private function hasUniqueUserCodeKey(): bool {
    switch ($this->database->databaseType()) {
      case 'sqlite':
        $prefix = $this->database->getPrefix();
        $parts = explode('.', $prefix . 'oauth2_device_code', 2);
        [$namespace, $table] = count($parts) === 2 ? $parts : ['main', $parts[0]];
        $indexes = $this->database->query('SELECT name FROM pragma_index_list(:table, :namespace) WHERE [unique] = 1 AND partial = 0', [
          ':table' => $table,
          ':namespace' => $namespace,
        ])->fetchCol();
        foreach ($indexes as $index) {
          $columns = $this->database->query('SELECT name FROM pragma_index_info(:index, :namespace) ORDER BY seqno', [
            ':index' => $index,
            ':namespace' => $namespace,
          ])->fetchCol();
          if ($columns === ['user_code']) {
            return TRUE;
          }
        }
        return FALSE;

      case 'mysql':
        $keys = [];
        foreach ($this->database->query('SHOW INDEX FROM {oauth2_device_code}')->fetchAll() as $index) {
          if ((int) $index->Non_unique === 0) {
            $keys[$index->Key_name][] = $index->Sub_part === NULL ? $index->Column_name : NULL;
          }
        }
        return in_array(['user_code'], $keys, TRUE);

      case 'pgsql':
        return (bool) $this->database->query("SELECT 1 FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) WHERE i.indrelid = CAST(:table AS regclass) AND i.indisunique AND i.indisvalid AND i.indpred IS NULL AND i.indexprs IS NULL AND i.indnatts = 1 AND a.attname = 'user_code'", [
          ':table' => $this->database->prefixTables('{oauth2_device_code}'),
        ])->fetchField();

      default:
        throw new \RuntimeException('Cannot verify device user-code uniqueness for this database driver. No codes have been changed.');
    }
  }

}
