<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_device_flow\Kernel;

// cspell:ignore BCDF GHJK
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Utility\UpdateException;
use Drupal\simple_oauth_device_flow\Entity\DeviceCodeStorageSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests table ownership and device-code constraints on both install paths.
 */
#[Group('simple_oauth_device_flow')]
final class DeviceCodeSchemaTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'serialization', 'image', 'file', 'options',
    'consumers', 'simple_oauth', 'simple_oauth_21',
  ];

  /**
   * Tests the real module installer creates entity-owned tables and indexes.
   */
  public function testModuleInstallerSchema(): void {
    $this->container->get('module_installer')->install(['simple_oauth_device_flow']);
    $this->assertDeviceSchema();
    $this->container->get('module_handler')->loadInclude('simple_oauth_device_flow', 'install');
    $this->assertSame([], simple_oauth_device_flow_schema());
  }

  /**
   * Tests entity-only installation has the same keys and lookup indexes.
   */
  public function testEntitySchema(): void {
    $this->enableModules(['simple_oauth_device_flow']);
    $this->installEntitySchema('oauth2_device_code');
    $this->assertDeviceSchema();
  }

  /**
   * Tests user-code uniqueness without depending on named index assertions.
   */
  public function testEntityUserCodeUniqueness(): void {
    $this->enableModules(['simple_oauth_device_flow']);
    $this->installEntitySchema('oauth2_device_code');
    $this->assertUniqueUserCode();
  }

  /**
   * Tests fresh entity installs retain the former hook's required columns.
   */
  public function testEntityRequiredColumns(): void {
    $this->enableModules(['simple_oauth_device_flow']);
    $this->installEntitySchema('oauth2_device_code');
    $database = $this->container->get('database');
    foreach (['user_code', 'client_id', 'created_at', 'expires_at'] as $column) {
      $values = [
        'device_code' => 'required-' . $column,
        'user_code' => 'BCDF-GHJK',
        'client_id' => 'required-client',
        'created_at' => 100,
        'expires_at' => 200,
        'langcode' => 'en',
      ];
      $values[$column] = NULL;
      try {
        $database->insert('oauth2_device_code')->fields($values)->execute();
        $this->fail('The required ' . $column . ' must reject NULL.');
      }
      catch (IntegrityConstraintViolationException) {
        $this->assertSame(0, (int) $database->select('oauth2_device_code')->countQuery()->execute()->fetchField());
      }
    }
  }

  /**
   * Tests adoption and retries preserve all base rows and scope deltas.
   *
   * @param bool $hook_table
   *   Whether to reproduce the former hook-owned base table.
   *
   * @dataProvider legacyInstallProvider
   */
  #[DataProvider('legacyInstallProvider')]
  public function testUpgradePreservesRows(bool $hook_table): void {
    $this->installLegacySchema($hook_table);
    $database = $this->container->get('database');
    $schema = $database->schema();
    $before = $this->snapshotRows();
    $repository = $this->container->get('entity.last_installed_schema.repository');
    $fields_before = $repository->getLastInstalledFieldStorageDefinitions('oauth2_device_code');
    $this->assertSame(SqlContentEntityStorageSchema::class, $repository->getLastInstalledDefinition('oauth2_device_code')->getHandlerClass('storage_schema'));
    simple_oauth_device_flow_update_10001();
    $this->assertSame($before, $this->snapshotRows());
    $this->assertSame(DeviceCodeStorageSchema::class, $repository->getLastInstalledDefinition('oauth2_device_code')->getHandlerClass('storage_schema'));
    $this->assertEquals($fields_before, $repository->getLastInstalledFieldStorageDefinitions('oauth2_device_code'));
    $tracked = $this->container->get('keyvalue')->get('entity.storage_schema.sql')->get('oauth2_device_code.entity_schema_data');
    $this->assertSame(['user_code'], $tracked['oauth2_device_code']['unique keys']['user_code']);
    foreach (['client_id', 'expires_at', 'user_id'] as $index) {
      $this->assertTrue($schema->indexExists('oauth2_device_code', $index));
    }
    // Repeat after completion, then simulate an interrupted partial DDL pass.
    simple_oauth_device_flow_update_10001();
    $schema->dropIndex('oauth2_device_code', 'expires_at');
    simple_oauth_device_flow_update_10001();
    $this->assertTrue($schema->indexExists('oauth2_device_code', 'expires_at'));
    $this->assertSame($before, $this->snapshotRows());
    $this->assertArrayNotHasKey('oauth2_device_code', $this->container->get('entity.definition_update_manager')->getChangeList());
    $row = $database->select('oauth2_device_code', 'd')->fields('d')->condition('device_code', 'pending')->execute()->fetchAssoc();
    $row['device_code'] = 'duplicate';
    $this->expectException(IntegrityConstraintViolationException::class);
    $database->insert('oauth2_device_code')->fields($row)->execute();
  }

  /**
   * Provides the two previous installation paths.
   *
   * @return array<string, array{bool}>
   *   Whether the base table was created by hook_schema().
   */
  public static function legacyInstallProvider(): array {
    return ['entity schema' => [FALSE], 'hook schema' => [TRUE]];
  }

  /**
   * Tests duplicate authorizations stop the update without altering any state.
   */
  public function testUpgradeDuplicatesFailSafely(): void {
    $this->installLegacySchema(FALSE);
    $database = $this->container->get('database');
    $database->update('oauth2_device_code')->fields(['user_code' => 'BCDF-GHJK'])->execute();
    $before = $this->snapshotRows();
    $tracking = $this->container->get('keyvalue')->get('entity.storage_schema.sql')->getAll();
    for ($attempt = 0; $attempt < 2; $attempt++) {
      try {
        simple_oauth_device_flow_update_10001();
        $this->fail('Duplicate user codes must stop the update.');
      }
      catch (UpdateException $exception) {
        $this->assertStringContainsString('Duplicate user codes', $exception->getMessage());
        $this->assertStringNotContainsString('BCDF-GHJK', $exception->getMessage());
      }
      $this->assertSame($before, $this->snapshotRows());
      $this->assertSame($tracking, $this->container->get('keyvalue')->get('entity.storage_schema.sql')->getAll());
    }
  }

  /**
   * Tests a colliding index without uniqueness stops safely.
   */
  public function testUpgradeIndexConflictFailsSafely(): void {
    $this->installLegacySchema(FALSE);
    $schema = $this->container->get('database')->schema();
    $schema->addIndex('oauth2_device_code', 'user_code', ['client_id'], [
      'fields' => ['client_id' => ['type' => 'varchar', 'length' => 128]],
    ]);
    $before = $this->snapshotRows();
    try {
      simple_oauth_device_flow_update_10001();
      $this->fail('A conflicting index must stop the update.');
    }
    catch (UpdateException $exception) {
      $this->assertStringContainsString('index conflicts', $exception->getMessage());
    }
    $this->assertSame($before, $this->snapshotRows());
    $this->assertTrue($schema->indexExists('oauth2_device_code', 'user_code'));
  }

  /**
   * Reproduces generic entity schema, optionally over the former hook table.
   *
   * @param bool $hook_table
   *   Whether to create the original hook-owned base table first.
   */
  private function installLegacySchema(bool $hook_table): void {
    $this->enableModules(['simple_oauth_device_flow']);
    $manager = $this->container->get('entity_type.manager');
    $definition = clone $manager->getDefinition('oauth2_device_code');
    $definition->setHandlerClass('storage_schema', SqlContentEntityStorageSchema::class);
    if ($hook_table) {
      $fields = [];
      foreach (['device_code' => 128, 'user_code' => 32, 'client_id' => 128, 'langcode' => 12] as $name => $length) {
        $fields[$name] = ['type' => 'varchar', 'length' => $length, 'not null' => TRUE];
      }
      $fields['langcode']['default'] = 'en';
      foreach (['user_id', 'authorized', 'authorized_at', 'created_at', 'expires_at', 'last_polled_at', 'interval'] as $name) {
        $fields[$name] = ['type' => 'int'];
      }
      $fields['user_id']['unsigned'] = TRUE;
      $fields['authorized'] += ['size' => 'tiny', 'default' => 0];
      $fields['interval']['default'] = 5;
      $fields['created_at']['not null'] = TRUE;
      $fields['expires_at']['not null'] = TRUE;
      $this->container->get('database')->schema()->createTable('oauth2_device_code', [
        'fields' => $fields,
        'primary key' => ['device_code'],
        'unique keys' => ['user_code' => ['user_code']],
        'indexes' => ['client_id' => ['client_id'], 'expires_at' => ['expires_at'], 'user_id' => ['user_id']],
      ]);
    }
    $field_manager = $this->container->get('entity_field.manager');
    $fields = $field_manager->getFieldStorageDefinitions('oauth2_device_code');
    $repository = $this->container->get('entity.last_installed_schema.repository');
    $repository->setLastInstalledDefinition($definition);
    $repository->setLastInstalledFieldStorageDefinitions('oauth2_device_code', $fields);
    $schema = new SqlContentEntityStorageSchema(
      $manager, $definition, $manager->getStorage('oauth2_device_code'),
      $this->container->get('database'), $field_manager,
    );
    $schema->onFieldableEntityTypeCreate($definition, $fields);
    // Direct saves exercise persisted encodings without validation.
    $storage = $manager->getStorage('oauth2_device_code');
    foreach (['pending', 'approved', 'denied', 'revoked', 'expired'] as $index => $state) {
      $storage->create([
        'device_code' => $state,
        'user_code' => 'BCDF-GHJ' . $index,
        'client_id' => 'legacy-client',
        'created_at' => 100,
        'expires_at' => $state === 'expired' ? 200 : 2000000000,
        'authorized' => $state === 'approved',
        'authorized_at' => in_array($state, ['pending', 'expired'], TRUE) ? NULL : 150,
        'user_id' => in_array($state, ['approved', 'denied'], TRUE) ? 7 : NULL,
        'last_polled_at' => 125,
        'interval' => 5,
        'scopes' => [['scope_id' => 'internal_scope'], ['scope_id' => 'legacy:scope:name']],
      ])->save();
    }
    $this->container->get('module_handler')->loadInclude('simple_oauth_device_flow', 'install');
  }

  /**
   * Captures all base and scope rows for byte-for-byte update comparisons.
   *
   * @return array<string, array>
   *   Ordered rows from both existing tables.
   */
  private function snapshotRows(): array {
    $database = $this->container->get('database');
    return [
      'base' => array_map(static fn(object $row): array => (array) $row, $database->select('oauth2_device_code', 'd')->fields('d')->orderBy('device_code')->execute()->fetchAll()),
      'scopes' => array_map(static fn(object $row): array => (array) $row, $database->select('oauth2_device_code__scopes', 's')->fields('s')->orderBy('entity_id')->orderBy('delta')->execute()->fetchAll()),
    ];
  }

  /**
   * Checks the physical indexes and database-enforced user-code uniqueness.
   */
  private function assertDeviceSchema(): void {
    $database = $this->container->get('database');
    $schema = $database->schema();
    foreach (['client_id', 'expires_at', 'user_id'] as $index) {
      $this->assertTrue($schema->indexExists('oauth2_device_code', $index), $index);
    }
    $this->assertTrue($schema->tableExists('oauth2_device_code__scopes'));
    $this->assertUniqueUserCode();
  }

  /**
   * Checks that direct entity saves cannot persist a duplicate user code.
   */
  private function assertUniqueUserCode(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('oauth2_device_code');
    $values = [
      'user_code' => 'BCDF-GHJK',
      'client_id' => 'schema-client',
      'created_at' => 100,
      'expires_at' => 200,
      'authorized' => FALSE,
    ];
    $storage->create(['device_code' => 'first'] + $values)->save();
    try {
      $storage->create(['device_code' => 'second'] + $values)->save();
      $this->fail('Duplicate user codes must be rejected by storage.');
    }
    catch (EntityStorageException) {
      $this->assertSame(1, (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute());
    }
  }

}
