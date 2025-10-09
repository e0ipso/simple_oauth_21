---
id: 1
group: 'code-cleanup'
dependencies: []
status: 'completed'
created: 2025-10-09
skills:
  - drupal-backend
  - php
---

# Remove Obsolete Installation Hooks from .install File

## Objective

Remove the conflicting `hook_install()` and `hook_uninstall()` functions from `simple_oauth_native_apps.install` that manually manage base fields, while preserving the valid config migration update hook.

## Skills Required

- **drupal-backend**: Understanding of Drupal module lifecycle and hook system
- **php**: Code editing and function removal

## Acceptance Criteria

- [ ] `simple_oauth_native_apps_install()` function removed (lines 13-78)
- [ ] `simple_oauth_native_apps_uninstall()` function removed (lines 80-97)
- [ ] `simple_oauth_native_apps_update_10001()` function preserved
- [ ] File docblock updated to reflect remaining purpose
- [ ] No syntax errors in modified file
- [ ] File follows Drupal coding standards

## Technical Requirements

**File to modify:** `modules/simple_oauth_native_apps/simple_oauth_native_apps.install`

**Functions to DELETE:**

1. `simple_oauth_native_apps_install($is_syncing)` - Lines 13-78
2. `simple_oauth_native_apps_uninstall($is_syncing)` - Lines 80-97

**Function to PRESERVE:**

- `simple_oauth_native_apps_update_10001()` - Lines 102-174 (valid config migration)

**File docblock to UPDATE:**

- Change from "Install/uninstall functions" to "Update functions for Simple OAuth Native Apps module"

## Input Dependencies

None - this is the first task and has no dependencies.

## Output Artifacts

- Modified `simple_oauth_native_apps.install` file with only update hooks
- Updated file docblock

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

1. **Open the .install file:**

   ```
   modules/simple_oauth_native_apps/simple_oauth_native_apps.install
   ```

2. **Update the file docblock (lines 3-6):**

   ```php
   /**
    * @file
    * Update functions for Simple OAuth Native Apps module.
    */
   ```

3. **Delete hook_install() function:**
   - Remove lines 10-78 completely
   - This includes the entire `simple_oauth_native_apps_install()` function
   - This function incorrectly attempts to manually install base fields

4. **Delete hook_uninstall() function:**
   - Remove lines 80-97 completely
   - This includes the entire `simple_oauth_native_apps_uninstall()` function
   - This function causes the uninstall crash by querying non-existent columns

5. **Preserve the update hook:**
   - Keep `simple_oauth_native_apps_update_10001()` intact
   - This is a valid config migration function and should remain

6. **Final file structure should be:**

   ```php
   <?php

   /**
    * @file
    * Update functions for Simple OAuth Native Apps module.
    */

   use Drupal\Core\Field\BaseFieldDefinition;

   /**
    * Migrate configuration from flat structure to nested structure.
    */
   function simple_oauth_native_apps_update_10001() {
     // ... existing migration code ...
   }
   ```

7. **Verify the changes:**
   - Run `vendor/bin/phpcs --standard=Drupal,DrupalPractice modules/simple_oauth_native_apps/simple_oauth_native_apps.install`
   - Ensure no syntax errors: `php -l modules/simple_oauth_native_apps/simple_oauth_native_apps.install`

**Why this fixes the issue:**

- Base fields defined in `hook_entity_base_field_info()` are automatically managed by Drupal
- Manual installation via `hook_install()` creates conflicts
- The uninstall crash occurs because `hook_uninstall()` queries columns that don't exist
- Removing these hooks lets Drupal handle field lifecycle automatically

</details>
