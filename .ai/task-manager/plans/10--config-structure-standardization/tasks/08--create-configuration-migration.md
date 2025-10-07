---
id: 8
group: 'migration'
dependencies: [1, 2, 3, 4, 5]
status: 'pending'
created: 2025-10-07
skills:
  - drupal-backend
  - php
---

# Create Configuration Migration Update Hook

## Objective

Create a Drupal update hook that migrates existing flat configuration to nested structure for sites upgrading to the new version, ensuring backward compatibility and smooth upgrades.

## Skills Required

- **drupal-backend**: Understanding of Drupal update hooks, configuration migration patterns
- **php**: Ability to write safe data transformation logic

## Acceptance Criteria

- [ ] Update hook created in .install file
- [ ] Hook migrates all flat keys to nested equivalents
- [ ] Hook handles missing keys gracefully
- [ ] Hook is idempotent (safe to run multiple times)
- [ ] Hook logs migration summary
- [ ] Existing sites can upgrade without manual intervention
- [ ] New installs skip migration automatically

## Technical Requirements

**File to create/modify**: `simple_oauth_native_apps.install`

**Migration mapping**:

```php
$migrations = [
  'webview_detection' => 'webview.detection',
  'webview_whitelist' => 'webview.whitelist',
  'webview_patterns' => 'webview.patterns',
  'allow_custom_uri_schemes' => 'allow.custom_uri_schemes',
  'allow_loopback_redirects' => 'allow.loopback_redirects',
  'enhanced_pkce_for_native' => 'native.enhanced_pkce',
  'logging_level' => 'log.level',
];
```

## Input Dependencies

- Task 1: Schema defines target structure
- Task 2: Validator expects nested structure
- Task 3: Forms use nested structure
- Task 4: Consumer forms use nested structure
- Task 5: Services use nested paths

## Output Artifacts

- Update hook that safely migrates configuration
- Upgrade path for existing installations
- Migration logging for troubleshooting

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Phase 1: Create Update Hook Function

**File**: `simple_oauth_native_apps.install`

Create an update hook (use next available number, e.g., `simple_oauth_native_apps_update_9001`):

```php
/**
 * Migrate configuration from flat structure to nested structure.
 */
function simple_oauth_native_apps_update_9001() {
  $config_factory = \Drupal::configFactory();
  $config = $config_factory->getEditable('simple_oauth_native_apps.settings');

  // Check if migration is needed
  if ($config->get('webview.detection') !== NULL) {
    // Already migrated
    \Drupal::logger('simple_oauth_native_apps')->info('Configuration already uses nested structure. Skipping migration.');
    return t('Configuration already migrated. No changes needed.');
  }

  $migrated = [];
  $missing = [];

  // Define migration mapping
  $migrations = [
    'webview_detection' => 'webview.detection',
    'webview_whitelist' => 'webview.whitelist',
    'webview_patterns' => 'webview.patterns',
    'allow_custom_uri_schemes' => 'allow.custom_uri_schemes',
    'allow_loopback_redirects' => 'allow.loopback_redirects',
    'enhanced_pkce_for_native' => 'native.enhanced_pkce',
  ];

  // Migrate each flat key to nested equivalent
  foreach ($migrations as $old_key => $new_key) {
    $value = $config->get($old_key);

    if ($value !== NULL) {
      $config->set($new_key, $value);
      $config->clear($old_key); // Remove old flat key
      $migrated[] = "$old_key → $new_key";
    }
    else {
      $missing[] = $old_key;
    }
  }

  // Special handling for boolean to enum conversions
  _simple_oauth_native_apps_migrate_boolean_to_enum($config);

  // Save migrated configuration
  $config->save();

  // Log migration summary
  $logger = \Drupal::logger('simple_oauth_native_apps');
  $logger->info('Configuration migration completed. Migrated: @migrated. Missing: @missing', [
    '@migrated' => implode(', ', $migrated),
    '@missing' => implode(', ', $missing),
  ]);

  $message = t('Migrated @count configuration keys from flat to nested structure.', [
    '@count' => count($migrated),
  ]);

  return $message;
}
```

### Phase 2: Add Helper Function for Boolean Migrations

Some fields changed from boolean to enum during the refactor:

```php
/**
 * Helper function to migrate boolean values to enum equivalents.
 */
function _simple_oauth_native_apps_migrate_boolean_to_enum($config) {
  // enhanced_pkce_for_native: boolean → native.enhanced_pkce: enum
  $old_enhanced = $config->get('enhanced_pkce_for_native');
  if (is_bool($old_enhanced)) {
    $new_value = $old_enhanced ? 'enhanced' : 'not-enhanced';
    $config->set('native.enhanced_pkce', $new_value);
    $config->clear('enhanced_pkce_for_native');
  }

  // allow_custom_uri_schemes: boolean → allow.custom_uri_schemes: enum
  $old_custom_schemes = $config->get('allow_custom_uri_schemes');
  if (is_bool($old_custom_schemes)) {
    $new_value = $old_custom_schemes ? 'native' : 'web';
    $config->set('allow.custom_uri_schemes', $new_value);
    $config->clear('allow_custom_uri_schemes');
  }

  // allow_loopback_redirects: boolean → allow.loopback_redirects: enum
  $old_loopback = $config->get('allow_loopback_redirects');
  if (is_bool($old_loopback)) {
    $new_value = $old_loopback ? 'native' : 'web';
    $config->set('allow.loopback_redirects', $new_value);
    $config->clear('allow_loopback_redirects');
  }
}
```

### Phase 3: Add Consumer Configuration Migration

Also migrate consumer-specific overrides:

```php
/**
 * Migrate consumer-specific configuration from flat to nested structure.
 */
function simple_oauth_native_apps_update_9002() {
  $config_factory = \Drupal::configFactory();
  $migrated_consumers = [];

  // Find all consumer configurations
  $consumer_configs = $config_factory->listAll('simple_oauth_native_apps.consumer.');

  foreach ($consumer_configs as $config_name) {
    $config = $config_factory->getEditable($config_name);
    $data = $config->getRawData();

    // Skip if already migrated
    if (isset($data['webview']['detection_override'])) {
      continue;
    }

    // Migrate consumer overrides (if they exist)
    // Consumer configs may be empty or have overrides
    $changed = FALSE;

    if (isset($data['webview_detection_override'])) {
      $config->set('webview.detection_override', $data['webview_detection_override']);
      $config->clear('webview_detection_override');
      $changed = TRUE;
    }

    // Add more consumer-specific migrations as needed

    if ($changed) {
      $config->save();
      $migrated_consumers[] = $config_name;
    }
  }

  $logger = \Drupal::logger('simple_oauth_native_apps');
  $logger->info('Migrated @count consumer configurations to nested structure.', [
    '@count' => count($migrated_consumers),
  ]);

  return t('Migrated @count consumer configurations.', [
    '@count' => count($migrated_consumers),
  ]);
}
```

### Phase 4: Test Migration Hook

1. **Create test site with old config**:

   ```php
   // In a test or manual setup
   $config = \Drupal::configFactory()->getEditable('simple_oauth_native_apps.settings');
   $config->set('webview_detection', 'block')
     ->set('allow_custom_uri_schemes', TRUE)
     ->set('enhanced_pkce_for_native', TRUE)
     ->save();
   ```

2. **Run update hooks**:

   ```bash
   vendor/bin/drush updatedb
   # Or via UI: /update.php
   ```

3. **Verify migration**:

   ```bash
   vendor/bin/drush config:get simple_oauth_native_apps.settings

   # Should show nested structure:
   # webview:
   #   detection: 'block'
   # allow:
   #   custom_uri_schemes: 'native'
   # native:
   #   enhanced_pkce: 'enhanced'
   ```

4. **Test idempotency** (run twice):
   ```bash
   vendor/bin/drush updatedb
   # Should report: "Already migrated. No changes needed."
   ```

### Phase 5: Test Edge Cases

```php
// Test 1: Empty configuration (new install)
// Expected: Hook skips gracefully

// Test 2: Partially nested config (interrupted migration)
// Expected: Hook completes migration

// Test 3: Already migrated config
// Expected: Hook detects and skips

// Test 4: Invalid values
// Expected: Hook preserves invalid values for validation to catch
```

### Phase 6: Document Upgrade Process

Add to module documentation:

```markdown
## Upgrading to Version X.X

Configuration structure has changed from flat to nested arrays.
The update hook `simple_oauth_native_apps_update_9001()` automatically
migrates your configuration.

**Upgrade steps:**

1. Update module code via Composer
2. Run database updates: `drush updatedb`
3. Clear cache: `drush cr`
4. Verify settings at /admin/config/services/simple-oauth/native-apps

**Manual migration** (if needed):
If automatic migration fails, export your configuration before updating,
then manually restructure it according to the schema.
```

### Phase 7: Add Rollback Plan

Document rollback procedure in comments:

```php
/**
 * Rollback procedure for update_9001 (if needed):
 *
 * 1. Export current config: drush config:export
 * 2. Restore code to previous version
 * 3. Run: drush config:import
 * 4. Clear cache: drush cr
 *
 * Note: Rollback requires config backup before update.
 */
```

</details>
