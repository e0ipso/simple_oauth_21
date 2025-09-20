---
id: 01
plan_id: 01
summary: 'Remove required constraints from Native App Override and Enhanced PKCE base field definitions'
type: 'implementation'
priority: 'high'
estimated_effort: '2 hours'
dependencies: []
status: "in-progress"
created: 2025-01-15
status: "in-progress"
---

# Task: Field Definition Updates

## Description

Modify the base field definitions for `native_app_override` and `native_app_enhanced_pkce` fields in the Simple OAuth Native Apps module to remove the required constraints that prevent users from selecting auto-detect options.

## Technical Details

### Files to Modify

- `/var/www/html/web/modules/contrib/simple_oauth_21/modules/simple_oauth_native_apps/simple_oauth_native_apps.module`

### Specific Changes Required

1. **Native App Override Field (lines 160-181)**:
   - Change `->setRequired(TRUE)` to `->setRequired(FALSE)` on line 163
   - Verify `->setDefaultValue('')` remains on line 169
   - Confirm allowed values include `'' => '- Automatic detection -'` on line 165

2. **Enhanced PKCE Field (lines 184-205)**:
   - Change `->setRequired(TRUE)` to `->setRequired(FALSE)` on line 187
   - Verify `->setDefaultValue('')` remains on line 192
   - Confirm allowed values include `'' => '- Automatic determination -'` on line 189

### Validation Requirements

- Ensure field descriptions accurately reflect that auto-detect is the preferred default
- Verify that form display configurations remain appropriate for non-required fields
- Confirm that existing allowed values arrays are preserved

## Acceptance Criteria

- [ ] `native_app_override` field is no longer marked as required
- [ ] `native_app_enhanced_pkce` field is no longer marked as required
- [ ] Both fields retain their empty string default values
- [ ] Field descriptions remain clear and accurate
- [ ] No syntax errors in the module file
- [ ] Code follows Drupal coding standards

## Implementation Steps

1. Open the `simple_oauth_native_apps.module` file
2. Locate the `simple_oauth_native_apps_entity_base_field_info()` function
3. Find the `native_app_override` field definition (around line 160)
4. Change `setRequired(TRUE)` to `setRequired(FALSE)`
5. Find the `native_app_enhanced_pkce` field definition (around line 184)
6. Change `setRequired(TRUE)` to `setRequired(FALSE)`
7. Save the file and verify syntax

## Testing Notes

This task focuses solely on the field definition changes. Integration testing will verify that the changes work correctly with the form system and auto-detection logic in subsequent tasks.

## Risk Mitigation

- Backup the original module file before making changes
- Test field definitions by viewing consumer form after changes
- Ensure no other modules depend on the required constraint
