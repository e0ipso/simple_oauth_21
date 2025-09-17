---
id: 3
group: 'form-fix'
dependencies: [2]
status: 'pending'
created: '2025-09-17'
skills: ['drupal-backend', 'php']
complexity_score: 5
complexity_notes: 'Medium complexity implementation task dependent on closure source analysis'
---

# Implement Form Serialization Fix

## Objective

Replace the identified non-serializable closure with a serializable alternative that maintains all existing AJAX functionality while enabling proper form caching during Consumer entity form operations.

## Skills Required

- **drupal-backend**: Advanced Drupal form API, AJAX callbacks, service references, and form element processing
- **php**: PHP serialization, object-oriented design, and callback implementation patterns

## Acceptance Criteria

- [ ] Non-serializable closure replaced with serializable alternative
- [ ] AJAX "Add another item" functionality preserved for Contact email field
- [ ] AJAX "Remove" functionality preserved for Contact email field
- [ ] AJAX "Add another item" functionality preserved for Redirect URI field
- [ ] AJAX "Remove" functionality preserved for Redirect URI field
- [ ] Form caching works correctly without serialization errors
- [ ] All existing Consumer entity form functionality maintained
- [ ] No PHP serialization errors in logs during AJAX operations

## Technical Requirements

Based on the closure source analysis from Task 2, implement the appropriate fix:

1. **If closure is in simple_oauth modules**: Replace with string callback references or service method references
2. **If closure is in external consumers module**: Create local workaround and prepare patch for upstream contribution
3. **If closure is in field widgets**: Implement custom field widget or alter existing widget configuration
4. **If closure is in AJAX callbacks**: Convert to proper service-based callback implementations

The fix must maintain backward compatibility and not break existing functionality.

## Input Dependencies

- Closure source analysis and recommended fix strategy from Task 2
- Understanding of exact closure location and introduction mechanism
- Test environment with reproducible AJAX errors

## Output Artifacts

- Fixed code implementing serializable alternatives
- Updated form alter hooks or field definitions as needed
- External module patch files if issue is upstream
- Documentation explaining the fix implementation
- Test cases verifying fix functionality

## Implementation Notes

<details>
<summary>Detailed Implementation Guidance</summary>

**Common Fix Patterns:**

1. **Replace Anonymous Functions with String References:**

   ```php
   // Before (non-serializable):
   $form['field']['#ajax']['callback'] = function($form, $form_state) { ... };

   // After (serializable):
   $form['field']['#ajax']['callback'] = '::ajaxCallback';
   // Or: [SomeClass::class, 'methodName']
   ```

2. **Convert Object Method Callbacks to Service References:**

   ```php
   // Before:
   $form['field']['#ajax']['callback'] = [$this, 'ajaxCallback'];

   // After:
   $form['field']['#ajax']['callback'] = 'simple_oauth_native_apps.consumer_form_alter:detectClientTypeAjax';
   ```

3. **Use Static Class Methods:**

   ```php
   // Before:
   $form['field']['#ajax']['callback'] = [$object_instance, 'method'];

   // After:
   $form['field']['#ajax']['callback'] = [ClassName::class, 'staticMethod'];
   ```

**Implementation Steps:**

1. **Analyze Fix Requirements:**
   - Review findings from Task 2 to understand exact closure type
   - Identify the correct serializable alternative pattern
   - Plan backward compatibility preservation strategy

2. **Implement Fix Based on Source:**

   **If in ConsumerNativeAppsFormAlter:**
   - Modify `detectClientTypeAjax` callback reference in `alterForm()` method
   - Ensure service injection works correctly with new callback pattern
   - Update form alter service definition if needed

   **If in Field Definitions:**
   - Modify field widget configurations in `hook_entity_base_field_info()`
   - Update AJAX callback references to use serializable patterns
   - Test field widget functionality after changes

   **If in External Module:**
   - Create patch file for upstream contribution
   - Implement local workaround using form alter hooks
   - Document external dependency and patch application

3. **Service Configuration Updates:**

   ```yaml
   # In simple_oauth_21.services.yml if needed
   simple_oauth_native_apps.consumer_form_alter:
     class: Drupal\simple_oauth_native_apps\Form\ConsumerNativeAppsFormAlter
     arguments:
       [
         '@config.factory',
         '@entity_type.manager',
         '@simple_oauth_native_apps.configuration_validator',
         '@simple_oauth_native_apps.native_client_detector',
         '@simple_oauth_native_apps.config_structure_mapper',
       ]
   ```

4. **Testing During Implementation:**
   - Test each change incrementally
   - Verify AJAX functionality after each modification
   - Ensure form can be serialized without errors
   - Check that all Consumer entity operations continue working

5. **Error Handling:**
   - Add try-catch blocks around callback operations
   - Provide meaningful error messages if callbacks fail
   - Ensure graceful degradation if AJAX functionality encounters issues

**Patch Creation (if needed):**

1. Create patch file using `git diff` or `diff -u`
2. Follow Drupal.org patch naming conventions
3. Include clear description of the issue and fix
4. Test patch application and functionality
</details>
