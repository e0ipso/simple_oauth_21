---
id: 2
group: 'database-migration'
dependencies: [1]
status: 'pending'
created: '2025-10-09'
skills:
  - drupal-backend
  - database
---

# Create Schema Update and Data Migration Hooks

## Objective

Create Drupal update hooks to migrate the DeviceCode scopes field from serialized string storage to oauth2_scope_reference field storage, ensuring all existing data is preserved.

## Skills Required

- **drupal-backend**: Understanding of Drupal update hooks, entity definition updates, and batch processing
- **database**: Knowledge of database schema changes, data migration patterns, and SQL operations

## Acceptance Criteria

- [ ] Update hook 11001 created to install new field storage definition
- [ ] Update hook 11002 created for batch data migration
- [ ] Batch processing handles large datasets (50 records per batch)
- [ ] Invalid scope references are logged but don't fail migration
- [ ] hook_schema() updated to remove scopes from main table
- [ ] Migration is rerunnable without causing errors
- [ ] All existing device code scopes migrate successfully

Use your internal Todo tool to track these and keep on track.

## Technical Requirements

**File to modify:** `modules/simple_oauth_device_flow/simple_oauth_device_flow.install`

**Key changes:**

1. **Create `simple_oauth_device_flow_update_11001()`:**
   - Install the new oauth2_scope_reference field storage definition
   - Use `\Drupal::entityDefinitionUpdateManager()->installFieldStorageDefinition()`

2. **Create `simple_oauth_device_flow_update_11002(&$sandbox)`:**
   - Implement batch processing for data migration
   - Read serialized scope data from old field
   - Convert to field items using `appendItem(['scope_id' => $scope_id])`
   - Process 50 device codes per batch
   - Track progress using sandbox

3. **Update `hook_schema()`:**
   - Remove the `scopes` field definition from the main table schema
   - Field API will create separate field tables automatically

## Input Dependencies

- Task 1 must be completed (DeviceCode entity field definition updated)
- Understanding of current schema definition in simple_oauth_device_flow.install (lines 37-40)

## Output Artifacts

- Two update hooks in simple_oauth_device_flow.install file
- Updated schema definition without scopes field in main table
- Migration path for existing installations

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Create Update Hook 11001 (Field Storage Installation)

Add this function to `simple_oauth_device_flow.install`:

```php
/**
 * Install oauth2_scope_reference field storage for device code scopes.
 */
function simple_oauth_device_flow_update_11001() {
  $entity_definition_update_manager = \Drupal::entityDefinitionUpdateManager();

  // Get the old field definition to uninstall it first.
  $old_field_definition = $entity_definition_update_manager->getFieldStorageDefinition('scopes', 'oauth2_device_code');

  if ($old_field_definition) {
    // We'll handle data migration in the next update, so just update the definition.
    $field_definition = \Drupal\Core\Field\BaseFieldDefinition::create('oauth2_scope_reference')
      ->setLabel(t('Scopes'))
      ->setDescription(t('The scopes for this Device Code.'))
      ->setCardinality(\Drupal\Core\Field\FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setTranslatable(FALSE);

    $entity_definition_update_manager->updateFieldStorageDefinition($field_definition);
  }

  return t('Updated device code scopes field to use oauth2_scope_reference type.');
}
```

### Step 2: Create Update Hook 11002 (Data Migration)

Add this batch processing function:

```php
/**
 * Migrate existing device code scope data to oauth2_scope_reference field.
 */
function simple_oauth_device_flow_update_11002(&$sandbox) {
  $storage = \Drupal::entityTypeManager()->getStorage('oauth2_device_code');
  $database = \Drupal::database();

  // Initialize sandbox.
  if (!isset($sandbox['current'])) {
    $sandbox['current'] = 0;
    $sandbox['max'] = $storage->getQuery()->accessCheck(FALSE)->count()->execute();
    $sandbox['errors'] = [];
  }

  // Process 50 device codes per batch.
  $device_code_ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->range($sandbox['current'], 50)
    ->execute();

  foreach ($storage->loadMultiple($device_code_ids) as $device_code) {
    try {
      // Read old serialized data directly from database.
      $serialized_scopes = $database->select('oauth2_device_code', 'd')
        ->fields('d', ['scopes'])
        ->condition('device_code', $device_code->id())
        ->execute()
        ->fetchField();

      if ($serialized_scopes) {
        $scope_ids = @unserialize($serialized_scopes, ['allowed_classes' => FALSE]);

        if (is_array($scope_ids) && !empty($scope_ids)) {
          // Clear existing field values.
          $device_code->set('scopes', []);

          // Add each scope as a field item.
          foreach ($scope_ids as $scope_id) {
            // Verify scope exists before adding.
            $scope_storage = \Drupal::entityTypeManager()->getStorage('oauth2_scope');
            $scope_exists = $scope_storage->loadByProperties(['name' => $scope_id]);

            if (!empty($scope_exists)) {
              $device_code->get('scopes')->appendItem(['scope_id' => $scope_id]);
            }
            else {
              // Log but don't fail - scope might have been deleted.
              $sandbox['errors'][] = t('Scope @scope_id not found for device code @device_code', [
                '@scope_id' => $scope_id,
                '@device_code' => $device_code->id(),
              ]);
            }
          }

          // Save without triggering validations that might fail during migration.
          $device_code->save();
        }
      }

      $sandbox['current']++;
    }
    catch (\Exception $e) {
      $sandbox['errors'][] = t('Error migrating device code @id: @message', [
        '@id' => $device_code->id(),
        '@message' => $e->getMessage(),
      ]);
      $sandbox['current']++;
    }
  }

  $sandbox['#finished'] = $sandbox['max'] > 0 ? $sandbox['current'] / $sandbox['max'] : 1;

  if ($sandbox['#finished'] >= 1) {
    $message = t('Migrated @current of @max device code scope entries.', [
      '@current' => $sandbox['current'],
      '@max' => $sandbox['max'],
    ]);

    if (!empty($sandbox['errors'])) {
      $message .= ' ' . t('Encountered @count warnings (see logs).', [
        '@count' => count($sandbox['errors']),
      ]);

      foreach ($sandbox['errors'] as $error) {
        \Drupal::logger('simple_oauth_device_flow')->warning($error);
      }
    }

    return $message;
  }
}
```

### Step 3: Update hook_schema()

Find the `simple_oauth_device_flow_schema()` function and remove the scopes field definition. The field will be managed by Field API after migration:

```php
// Remove these lines from the 'oauth2_device_code' table definition:
'scopes' => [
  'type' => 'text',
  'description' => 'Serialized array of requested scopes.',
],
```

The Field API will automatically create the necessary field tables (`oauth2_device_code__scopes` and `oauth2_device_code_revision__scopes` if needed).

### Important Considerations

- **MUST** use `declare(strict_types=1);` at the top of the .install file
- **Migration Safety**: Use try-catch blocks to handle errors gracefully
- **Batch Processing**: 50 records per batch prevents timeout on large datasets
- **Scope Validation**: Check that scope entities exist before creating references
- **Logging**: Log warnings for missing scopes but don't fail the migration
- **Rerunnable**: The migration should be safe to run multiple times
- **Database Access**: Use direct database queries to read old serialized data to avoid entity API complications during field transition

### Testing the Migration

After implementing, test with:

```bash
cd /var/www/html
vendor/bin/drush updatedb
```

Check logs for any warnings:

```bash
vendor/bin/drush watchdog:show --type=simple_oauth_device_flow
```

</details>
