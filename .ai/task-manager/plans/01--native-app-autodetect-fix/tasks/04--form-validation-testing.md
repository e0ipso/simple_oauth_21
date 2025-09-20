---
id: 04
plan_id: 01
summary: 'Test form submission and validation with auto-detect options selected'
type: 'testing'
priority: 'high'
estimated_effort: '1.5 hours'
dependencies: ['03']
status: 'pending'
created: 2025-01-15
---

# Task: Form Validation Testing

## Description

Comprehensively test form submission and validation to ensure that consumers can be successfully created and edited with auto-detect options selected for both Native App Override and Enhanced PKCE fields. Verify that Drupal's form validation system accepts empty string values for these fields.

## Technical Details

### Form Scenarios to Test

1. **New Consumer Creation**:
   - Create consumer with both fields set to auto-detect
   - Create consumer with one field auto-detect, one explicit
   - Create consumer with both fields set to explicit values
   - Verify form submission succeeds in all cases

2. **Existing Consumer Editing**:
   - Edit consumer to change from explicit to auto-detect
   - Edit consumer to change from auto-detect to explicit
   - Edit consumer with auto-detect values already set
   - Verify updates save correctly

3. **Form Validation Edge Cases**:
   - Submit form with invalid redirect URIs but valid auto-detect
   - Test form with various combinations of field states
   - Verify validation messages are appropriate

### Validation Points

- Form submission completes without validation errors
- Auto-detect options are properly saved to database
- Form redisplay shows correct selected values
- No PHP errors or warnings during form processing
- Success messages appear for successful submissions

## Acceptance Criteria

- [ ] New consumers can be created with auto-detect options selected
- [ ] Existing consumers can be edited and saved with auto-detect options
- [ ] Form validation passes without errors for auto-detect values
- [ ] Saved values are correctly displayed when form is reloaded
- [ ] No PHP errors or warnings during form submission
- [ ] Success/error messages are appropriate and helpful

## Implementation Steps

1. Test new consumer creation via browser form
2. Test existing consumer editing via browser form
3. Test form validation with various field combinations
4. Verify database storage of auto-detect values
5. Test form redisplay after submission
6. Check for any PHP errors in logs
7. Validate user experience and messaging

## Manual Testing Procedure

### Test Case 1: New Consumer with Auto-Detect

1. Navigate to `/admin/config/services/consumer/add`
2. Fill in basic fields:
   - Label: "Test Auto-Detect Consumer"
   - Redirect URI: "http://127.0.0.1:8080/callback"
3. In Native App Settings section:
   - Set "Native App Override" to "- Automatic detection -"
   - Set "Enhanced PKCE" to "- Automatic determination -"
4. Submit form
5. Verify success message and redirect
6. Check that consumer was created with correct values

### Test Case 2: Edit Existing Consumer

1. Navigate to existing consumer edit form
2. Change Native App Override to "- Automatic detection -"
3. Change Enhanced PKCE to "- Automatic determination -"
4. Submit form
5. Verify changes were saved
6. Reload form to confirm values display correctly

### Test Case 3: Mixed Field States

1. Create consumer with:
   - Native App Override: "Force as Native App"
   - Enhanced PKCE: "- Automatic determination -"
2. Verify form submission succeeds
3. Edit to change:
   - Native App Override: "- Automatic detection -"
   - Enhanced PKCE: "Require Enhanced PKCE"
4. Verify update succeeds

## Browser Testing Commands

```bash
# Get consumer form URL for manual testing
vendor/bin/drush eval "
echo 'Consumer add form: ' . \Drupal\Core\Url::fromRoute('entity.consumer.add_form')->toString() . \PHP_EOL;
echo 'Admin credentials: admin/admin' . \PHP_EOL;
"

# Check recent consumer with auto-detect values
vendor/bin/drush eval "
\$consumers = \Drupal::entityTypeManager()->getStorage('consumer')->loadByProperties(['label' => 'Test Auto-Detect Consumer']);
foreach (\$consumers as \$consumer) {
  echo 'Consumer: ' . \$consumer->label() . \PHP_EOL;
  echo 'Native Override: [' . \$consumer->get('native_app_override')->value . ']' . \PHP_EOL;
  echo 'Enhanced PKCE: [' . \$consumer->get('native_app_enhanced_pkce')->value . ']' . \PHP_EOL;
  echo 'Edit URL: ' . \$consumer->toUrl('edit-form')->toString() . \PHP_EOL;
}
"
```

## Validation Checks

1. **Database Verification**:
   - Confirm auto-detect values are stored as empty strings
   - Verify field storage matches form submission
   - Check that defaults are applied correctly

2. **Log Monitoring**:
   - Watch for PHP errors during form submission
   - Check for validation errors in recent log messages
   - Monitor for any deprecated function warnings

3. **User Experience**:
   - Verify form labels and descriptions are clear
   - Confirm help text explains auto-detect behavior
   - Ensure form submission feedback is appropriate

## Risk Mitigation

- Test with clean browser session to avoid cached form data
- Verify both JavaScript-enabled and disabled browsers
- Test with different user permission levels if applicable
- Monitor system logs throughout testing process

## Dependencies

This task depends on Task 03 (Integration Testing) being completed successfully.
