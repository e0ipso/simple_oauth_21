---
id: 02
plan_id: 01
summary: 'Verify database schema handles field definition changes and existing consumer data'
type: 'verification'
priority: 'high'
estimated_effort: '1 hour'
dependencies: ['01']
status: "completed"
created: 2025-01-15
status: "completed"
status: "completed"
---

# Task: Database Schema Verification

## Description

Ensure that the database schema and existing consumer entities handle the field definition changes correctly. Verify that removing the required constraint doesn't cause data integrity issues or break existing consumer configurations.

## Technical Details

### Verification Areas

1. **Existing Consumer Data**:
   - Check consumers with current `native_app_override` and `native_app_enhanced_pkce` values
   - Verify empty string values are properly stored and retrieved
   - Confirm no data corruption during field definition updates

2. **Schema Consistency**:
   - Ensure database field definitions remain compatible
   - Verify that field storage doesn't require schema updates
   - Confirm that list_string field type handles empty values correctly

3. **Default Value Behavior**:
   - Test that new consumers get appropriate default values
   - Verify that empty defaults are applied correctly
   - Ensure auto-detection logic receives expected values

### Commands to Execute

```bash
# Clear cache to apply field definition changes
vendor/bin/drush cache:rebuild

# Check for any schema inconsistencies
vendor/bin/drush entity:updates

# Export current configuration to verify changes
vendor/bin/drush config:export --diff
```

## Acceptance Criteria

- [ ] Database schema remains consistent after field definition changes
- [ ] Existing consumer entities load and save correctly with auto-detect values
- [ ] New consumers get proper default values (empty string for auto-detection)
- [ ] No entity update requirements or schema mismatches reported
- [ ] Field storage handles empty string values correctly
- [ ] Configuration export shows no unexpected changes

## Implementation Steps

1. Apply field definition changes from Task 01
2. Clear Drupal caches to apply the changes
3. Run entity updates check to verify schema consistency
4. Test loading existing consumers with auto-detect values
5. Create a new test consumer to verify default behavior
6. Verify field values are properly stored and retrieved
7. Export configuration to check for any unexpected changes

## Testing Commands

```bash
# Test consumer creation with auto-detect values
vendor/bin/drush eval "
\$consumer = \Drupal::entityTypeManager()->getStorage('consumer')->create([
  'label' => 'Test Auto-Detect Consumer',
  'client_id' => 'test-auto-' . time(),
  'native_app_override' => '',
  'native_app_enhanced_pkce' => '',
]);
\$consumer->save();
echo 'Consumer created with ID: ' . \$consumer->id();
"

# Verify the values are stored correctly
vendor/bin/drush eval "
\$consumers = \Drupal::entityTypeManager()->getStorage('consumer')->loadByProperties(['client_id' => 'test-auto-*']);
foreach (\$consumers as \$consumer) {
  echo 'Consumer: ' . \$consumer->label() . \PHP_EOL;
  echo 'Native Override: [' . \$consumer->get('native_app_override')->value . ']' . \PHP_EOL;
  echo 'Enhanced PKCE: [' . \$consumer->get('native_app_enhanced_pkce')->value . ']' . \PHP_EOL;
}
"
```

## Risk Mitigation

- Backup database before testing field changes
- Test with a copy of production data if available
- Verify that existing consumers continue to work as expected
- Check for any entity validation errors with empty values

## Dependencies

This task depends on Task 01 (Field Definition Updates) being completed successfully.
