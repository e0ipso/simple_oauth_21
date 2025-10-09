---
id: 12
summary: 'Fix uninstall crash in simple_oauth_native_apps by removing obsolete hook_install field definitions'
created: 2025-10-09
---

# Plan: Fix Native Apps Module Uninstall Crash

## Original Work Order

> When I try to uninstall `simple_oauth_native_apps` I get a server crash:
>
> ```
>   The website encountered an unexpected error. Try again later.
>
> Drupal\Core\Database\DatabaseExceptionWrapper: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'native_app_override' in 'WHERE': SELECT 1 AS "expression" FROM "consumer_field_data" "t" WHERE "native_app_override" IS NOT NULL LIMIT 1 OFFSET 0; Array ( ) in Drupal\Core\Entity\Sql\SqlContentEntityStorage->countFieldData() (line 1784 of core/lib/Drupal/Core/Entity/Sql/SqlContentEntityStorage.php).
> ```
>
> We need to fix it.

## Executive Summary

The `simple_oauth_native_apps` module crashes during uninstall with a database error attempting to query a non-existent `native_app_override` column. This is caused by conflicting field definitions between `hook_entity_base_field_info()` (in `.module` file) and obsolete `hook_install()` code (in `.install` file) that still attempts to manually install base fields.

The root cause is that base fields defined in `hook_entity_base_field_info()` are automatically managed by Drupal and should NOT be manually installed via `hook_install()`. The presence of duplicate installation logic creates a mismatch where:

1. **Module install**: `hook_install()` creates fields with old values (`''`, `'0'`, `'1'`)
2. **Drupal's entity system**: Ignores manually installed fields because `hook_entity_base_field_info()` already defines them
3. **Module uninstall**: `hook_uninstall()` tries to remove fields that were never actually installed by checking for data using the NEW field definition values (`'auto-detect'`, `'web'`, `'native'`)
4. **Database query fails**: Column doesn't exist in the database table

The fix involves removing the obsolete manual field installation/uninstallation code from the `.install` file and relying entirely on Drupal's automatic base field management.

## Context

### Current State

The `simple_oauth_native_apps` module has contradictory field management code:

**In `simple_oauth_native_apps.module` (lines 148-201):**

- Defines `native_app_override` and `native_app_enhanced_pkce` as base fields via `hook_entity_base_field_info()`
- Uses modern values: `'auto-detect'`, `'web'`, `'native'`
- This is the CORRECT approach for base fields

**In `simple_oauth_native_apps.install` (lines 13-97):**

- `hook_install()`: Manually installs the same fields with OBSOLETE values: `''`, `'0'`, `'1'`
- `hook_uninstall()`: Attempts to remove manually installed fields
- This code is REDUNDANT and CONFLICTING with the base field definitions

**Database reality:**

- The fields `native_app_override` and `native_app_enhanced_pkce` columns DO NOT EXIST in `consumer_field_data` table
- This proves that manual installation in `hook_install()` failed or was overridden
- Uninstall attempts to query these non-existent columns, causing the crash

**Error chain:**

1. User initiates module uninstall
2. `hook_uninstall()` calls `$definition_update_manager->uninstallFieldStorageDefinition()`
3. Drupal's `EntityDefinitionUpdateManager` checks if field has data
4. Query uses CURRENT field definition from `hook_entity_base_field_info()`
5. Database table doesn't have the column → SQL error
6. Module uninstall fails with server crash

### Target State

After successful implementation:

- `hook_install()` removed entirely (base fields don't need manual installation)
- `hook_uninstall()` removed entirely (base fields are auto-managed)
- Only `hook_entity_base_field_info()` defines the fields
- Module uninstalls cleanly without database errors
- Drupal's entity update system handles all field lifecycle management
- Update hook migrates any existing installations to clean state

### Background

**Drupal field management patterns:**

1. **Base fields** (defined in `hook_entity_base_field_info()`):
   - Automatically managed by Drupal
   - Installed/uninstalled by entity update manager
   - DO NOT require manual installation
   - Schema updates happen via update hooks

2. **Configurable fields** (created via Field UI or config):
   - Stored in configuration
   - Managed through config import/export
   - Different lifecycle from base fields

**Historical context:**

The obsolete installation code in `.install` file likely dates back to an earlier implementation approach where the fields might have been manually managed. The Plan 01 changes (migrating from numeric to text-based values) exposed this issue because it changed the field definition but didn't remove the conflicting manual installation code.

**Why this wasn't caught earlier:**

- Module installation may have appeared to work (fields accessible via entity system)
- Only uninstall triggers the field data check that exposes the database mismatch
- Tests may not have covered the uninstall workflow

## Technical Implementation Approach

### Phase 1: Remove Obsolete Installation Code

**Objective**: Eliminate the conflicting manual field installation/uninstallation logic

The `.install` file currently contains three functions:

1. `simple_oauth_native_apps_install()` - REMOVE (obsolete)
2. `simple_oauth_native_apps_uninstall()` - REMOVE (obsolete)
3. `simple_oauth_native_apps_update_10001()` - KEEP (valid config migration)

**Actions:**

- Delete `hook_install()` function entirely (lines 13-78)
- Delete `hook_uninstall()` function entirely (lines 80-97)
- Preserve `simple_oauth_native_apps_update_10001()` for config migration
- Update file docblock to clarify remaining purpose

**Rationale:**
Base fields defined in `hook_entity_base_field_info()` are automatically managed by Drupal's entity update system. Manual installation via `hook_install()` creates conflicts and should never be used for base fields.

### Phase 2: Create Update Hook for Existing Installations

**Objective**: Fix installations that already have the broken state

Some sites may have already installed the module with the obsolete code, resulting in orphaned field definitions or inconsistent states. An update hook will clean this up.

**Implementation:**
Create `simple_oauth_native_apps_update_10002()` that:

1. **Clear entity definition cache** to ensure Drupal uses current definitions
2. **Check field storage definitions** in the database vs code
3. **Remove any manually installed field storage** that conflicts with base field definitions
4. **Trigger entity schema update** to ensure base fields are properly registered
5. **Log the cleanup actions** for site administrator visibility

**Why an update hook:**

- Fixes sites that installed the module with the broken code
- Ensures clean state before module can be uninstalled
- Provides logging/visibility into what was fixed

### Phase 3: Verify Base Field Definitions

**Objective**: Ensure the remaining base field definitions are complete and correct

Review `hook_entity_base_field_info()` to verify:

1. **Field value consistency**: Confirm `'auto-detect'`, `'web'`, `'native'` are correct
2. **Required vs optional**: Verify `setRequired(FALSE)` per Plan 01 changes
3. **Default values**: Confirm `setDefaultValue('auto-detect')` is appropriate
4. **Display configuration**: Ensure form/view settings are complete
5. **Description clarity**: Verify help text explains automatic detection

**Key validation points:**

- Field definitions must be self-contained (no dependencies on .install file)
- Values must match what the service layer (NativeClientDetector) expects
- Form integration must work with these values

### Phase 4: Test Uninstall Workflow

**Objective**: Comprehensive testing to ensure uninstall works correctly

**Test scenarios:**

1. **Fresh installation test:**
   - Install module on clean site
   - Verify fields are created automatically
   - Create test consumer with native app settings
   - Uninstall module successfully
   - Verify fields are removed

2. **Upgrade test:**
   - Simulate site with broken state
   - Run update hook 10002
   - Verify cleanup occurs
   - Uninstall module successfully

3. **Data preservation test:**
   - Create consumers with various native app override values
   - Verify data is accessible before uninstall
   - Uninstall should handle existing data appropriately

4. **Module dependencies:**
   - Verify simple_oauth_21 umbrella module uninstall cascade
   - Check for orphaned data in consumer entity

## Risk Considerations and Mitigation Strategies

### Technical Risks

- **Field Data Loss on Uninstall**: Removing fields will delete consumer native app settings
  - **Mitigation**: Document that uninstalling removes settings; consider export/backup instructions

- **Update Hook Timing**: Update 10002 may run before sites update to fixed code
  - **Mitigation**: Update hook is defensive, handles both broken and fixed states

- **Entity Cache Issues**: Cached entity definitions may not reflect base field changes
  - **Mitigation**: Update hook explicitly clears entity definition caches

### Implementation Risks

- **Breaking Existing Installations**: Sites mid-upgrade could have inconsistent state
  - **Mitigation**: Update hook detects and fixes all known inconsistent states

- **Missing Edge Cases**: Unknown installation states not covered by update hook
  - **Mitigation**: Comprehensive logging in update hook shows what state was found

### Integration Risks

- **Module Dependencies**: Other OAuth modules may reference these fields
  - **Mitigation**: Review simple_oauth_pkce and other submodules for field references

- **Config Dependencies**: Configuration may reference the field names
  - **Mitigation**: Search for 'native_app_override' in all config files

## Success Criteria

### Primary Success Criteria

1. Module uninstalls without SQL errors or crashes
2. Fresh installation followed by uninstallation works correctly
3. Existing installations can run update 10002 and then uninstall successfully
4. No base field installation code exists in .install file
5. Base fields are fully managed by hook_entity_base_field_info()

### Quality Assurance Metrics

1. All existing PHPUnit tests continue to pass
2. Manual uninstall test on development environment succeeds
3. Update hook logs show successful cleanup on test installation
4. No references to obsolete field values (`''`, `'0'`, `'1'`) remain in codebase
5. Module can be installed/uninstalled multiple times without errors

## Resource Requirements

### Development Skills

- Deep understanding of Drupal entity system and field lifecycle
- Experience with entity definition update manager
- Knowledge of base fields vs configurable fields
- Ability to write defensive update hooks
- Understanding of module install/uninstall lifecycle

### Technical Infrastructure

- Local Drupal environment with simple_oauth_native_apps installed
- Database access for schema verification
- Test consumers with native app settings
- Ability to test both fresh install and upgrade scenarios

## Integration Strategy

This fix integrates with the broader Simple OAuth ecosystem:

- **simple_oauth_21**: Umbrella module uninstall should properly cascade
- **simple_oauth**: Core OAuth functionality continues to work
- **simple_oauth_pkce**: PKCE module may interact with native app detection
- **Consumer entity**: Field changes affect consumer entity schema

**Coordination points:**

- Verify no other modules rely on the specific field storage approach
- Check that compliance dashboard doesn't expect manual field installation
- Ensure NativeClientDetector service works with base-field-only approach

## Implementation Order

1. **Analyze current codebase**: Review all references to field installation
2. **Create update hook 10002**: Defensive cleanup for existing installations
3. **Remove obsolete install/uninstall hooks**: Delete manual field management
4. **Verify base field definitions**: Ensure completeness and correctness
5. **Test fresh installation**: Install → configure → uninstall
6. **Test upgrade path**: Broken state → update hook → uninstall
7. **Verify integration**: Check other OAuth modules and services

## Notes

- Base fields should NEVER be manually installed via `hook_install()` - this is a Drupal anti-pattern
- The original code may have worked by accident (fields accessible despite database mismatch)
- This issue highlights the importance of testing complete module lifecycle (install → use → uninstall)
- Update hooks must be defensive and handle multiple possible states
- Future field changes should only modify `hook_entity_base_field_info()` and use update hooks for migrations
