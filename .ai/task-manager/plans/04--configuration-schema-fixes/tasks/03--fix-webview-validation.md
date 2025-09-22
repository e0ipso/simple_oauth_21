---
id: 3
group: 'validation-fixes'
dependencies: []
status: 'pending'
created: '2025-09-22'
skills: ['drupal-backend']
complexity_score: 5.5
complexity_notes: "Higher complexity due to debugging validation logic and form interaction uncertainty"
---

# Fix WebView Detection Policy Validation

## Objective

Resolve WebView detection policy validation errors that incorrectly reject valid policy values, ensuring the ConfigurationValidator properly processes all valid policy options ('off', 'warn', 'block').

## Skills Required

- **drupal-backend**: Drupal form validation, configuration validation, debugging validation logic, and form API expertise

## Acceptance Criteria

- [ ] WebView detection policy validation accepts all valid values ('off', 'warn', 'block')
- [ ] No "Invalid WebView detection policy" errors during form submission or configuration validation
- [ ] ConfigurationValidator service correctly processes WebView detection settings
- [ ] Form validation logic handles edge cases and malformed data gracefully
- [ ] Clear error messages provided for actually invalid policy values

## Technical Requirements

- Debug and fix ConfigurationValidator::validateWebViewConfig() method
- Investigate form handling in ConsumerNativeAppsFormAlter and NativeAppsSettingsForm
- Ensure proper data structure handling during form submission
- Test validation against various configuration scenarios
- Maintain compatibility with existing valid configurations

## Input Dependencies

- Understanding of current validation error patterns
- Analysis of ConfigurationValidator service logic
- Knowledge of form submission data flow

## Output Artifacts

- Fixed ConfigurationValidator validation logic
- Updated form handling if necessary
- Enhanced error reporting for invalid cases
- Validated working form submission process

## Implementation Notes

<details>
<summary>Detailed Implementation Instructions</summary>

1. **Investigate current validation errors**:
   - Reproduce the "Invalid WebView detection policy" error
   - Check logs: `drush watchdog:show --type=simple_oauth_native_apps`
   - Examine form submission data structure
   - Test with all valid policy values: 'off', 'warn', 'block'

2. **Debug ConfigurationValidator**:
   - Open `/src/Service/ConfigurationValidator.php`
   - Review `validateWebViewConfig()` method around line 70
   - Check if validation is using correct array structure for `$config['webview']['detection']`
   - Verify the validation logic matches expected data format

3. **Common validation issues to check**:
   - Array structure mismatch: validator expects `$config['webview']['detection']` but gets flat structure
   - Form submission creating unexpected data format
   - Missing null/empty value handling
   - Case sensitivity issues
   - Whitespace or encoding issues

4. **Debug form data flow**:
   - Add temporary debugging in ConsumerNativeAppsFormAlter::submitForm()
   - Log the actual form values being processed: `\Drupal::logger('debug')->info(print_r($form_state->getValues(), TRUE));`
   - Compare form data structure with what validator expects

5. **Potential fixes**:
   - Update validator to handle actual data structure from forms
   - Fix form processing to create expected data structure
   - Add proper null/empty value handling
   - Ensure consistent data format between global and consumer-specific settings

6. **Testing scenarios**:
   - Global setting changes in admin/config/people/simple_oauth/oauth-21/native-apps
   - Consumer-specific overrides in consumer edit forms
   - Configuration import/export operations
   - Invalid values to ensure proper error handling

7. **Validation verification**:
   - Test all three valid values: 'off', 'warn', 'block'
   - Test empty/null values
   - Test invalid values to ensure they still trigger errors
   - Clear cache and retest: `drush cache:rebuild`
</details>