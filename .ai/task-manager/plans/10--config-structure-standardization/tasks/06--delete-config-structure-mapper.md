---
id: 6
group: 'cleanup'
dependencies: [4, 5]
status: 'completed'
created: 2025-10-07
completed: 2025-10-07
skills:
  - drupal-backend
---

# Delete ConfigStructureMapper Service and All References

## Objective

Remove the ConfigStructureMapper service completely from the codebase, validating that the nested structure standardization is complete and no mapping layer is needed.

## Skills Required

- **drupal-backend**: Understanding of Drupal service architecture and cleanup procedures

## Acceptance Criteria

- [ ] ConfigStructureMapper.php file deleted
- [ ] ConfigStructureMappingTest.php file deleted
- [ ] Service definition removed from simple_oauth_native_apps.services.yml
- [ ] No references to ConfigStructureMapper remain in codebase
- [ ] No import statements for ConfigStructureMapper remain
- [ ] Cache cleared and system functions normally
- [ ] All tests pass after deletion

## Technical Requirements

**Files to delete**:

1. `src/Service/ConfigStructureMapper.php`
2. `tests/src/Unit/ConfigStructureMappingTest.php`

**Service definition to remove**:

- `simple_oauth_native_apps.config_structure_mapper` from `simple_oauth_native_apps.services.yml`

## Input Dependencies

- Task 4: ConsumerNativeAppsFormAlter no longer uses mapper
- Task 5: All services use nested paths directly

## Output Artifacts

- Cleaner codebase without unnecessary abstraction layer
- Proof that nested structure works end-to-end without mapping

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Phase 1: Verify No References Before Deletion

**Critical verification step** - if any references remain, deletion will cause errors:

```bash
# Search entire codebase for references
grep -r "ConfigStructureMapper" src/
grep -r "ConfigStructureMapper" tests/
grep -r "config_structure_mapper" *.yml
grep -r "configMapper" src/

# Expected result: NO matches (all removed in previous tasks)
# If matches found, identify and update those files first
```

### Phase 2: Delete Service Class

```bash
# Delete the mapper service
rm src/Service/ConfigStructureMapper.php

# Verify deletion
ls src/Service/ | grep Config
# Should NOT show ConfigStructureMapper.php
```

### Phase 3: Delete Service Tests

```bash
# Delete the mapper test
rm tests/src/Unit/ConfigStructureMappingTest.php

# Verify deletion
ls tests/src/Unit/ | grep Config
# Should NOT show ConfigStructureMappingTest.php
```

### Phase 4: Remove Service Definition

**File**: `simple_oauth_native_apps.services.yml`

Find and remove the service definition:

```yaml
# DELETE THIS ENTIRE SECTION
simple_oauth_native_apps.config_structure_mapper:
  class: Drupal\simple_oauth_native_apps\Service\ConfigStructureMapper
  arguments: []
```

### Phase 5: Clear All Caches

```bash
# Clear Drupal cache to unregister service
vendor/bin/drush cache:rebuild

# Verify no service registration errors
vendor/bin/drush watchdog:show --severity=Error
```

### Phase 6: Run Full Test Suite

```bash
# Run all tests to verify nothing breaks
cd /var/www/html
vendor/bin/phpunit web/modules/contrib/simple_oauth_21/modules/simple_oauth_native_apps/tests

# Expected: All tests pass
# If failures: Investigate and fix before proceeding
```

### Phase 7: Final Verification

```bash
# 1. Verify files are deleted
test -f src/Service/ConfigStructureMapper.php && echo "ERROR: File still exists" || echo "OK: File deleted"
test -f tests/src/Unit/ConfigStructureMappingTest.php && echo "ERROR: Test still exists" || echo "OK: Test deleted"

# 2. Verify no references in codebase
echo "Checking for any remaining references..."
grep -r "ConfigStructureMapper" . 2>/dev/null | grep -v ".git" | grep -v "node_modules"
# Expected: No output (no references found)

# 3. Verify service not registered
vendor/bin/drush config:get simple_oauth_native_apps.services
# Should not list config_structure_mapper

# 4. Test form operations manually
echo "Manual testing checklist:"
echo "[ ] Navigate to /admin/config/services/simple-oauth/native-apps"
echo "[ ] Form renders without errors"
echo "[ ] Can save settings successfully"
echo "[ ] Edit a consumer entity"
echo "[ ] Consumer native apps settings work"
echo "[ ] No PHP errors in logs"
```

### Phase 8: Document Removal

Update any documentation that may reference the mapper:

- Check README.md for references
- Check any architecture documents
- Update inline comments if they mention "structure mapping"

### Success Indicator

The successful deletion of ConfigStructureMapper with all tests passing proves that:

1. Nested structure is fully implemented end-to-end
2. No mapping layer is needed
3. Configuration flows directly from forms to storage
4. The refactoring is complete and successful

</details>
