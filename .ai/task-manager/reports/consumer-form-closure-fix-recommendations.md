# Consumer Form Closure Fix Recommendations

## Priority 1: Fix ConsumerNativeAppsFormAlter Service (High Impact)

### Current Problem

Lines 225-228 in `ConsumerNativeAppsFormAlter.php`:

```php
// Add custom validation.
$form['#validate'][] = [$this, 'validateConsumerNativeAppsSettings'];

// Add custom submit handler.
$form['actions']['submit']['#submit'][] = [$this, 'submitConsumerNativeAppsSettings'];
```

This creates object method references that are not serializable.

### Recommended Solution A: Static Wrapper Functions

Create static wrapper functions in the module file:

**File**: `simple_oauth_native_apps.module`

```php
/**
 * Form validation wrapper for consumer native app settings.
 *
 * @param array $form
 *   The form array.
 * @param \Drupal\Core\Form\FormStateInterface $form_state
 *   The form state.
 */
function simple_oauth_native_apps_validate_consumer_settings(array $form, FormStateInterface $form_state): void {
  $form_alter_service = \Drupal::service('simple_oauth_native_apps.consumer_form_alter');
  $form_alter_service->validateConsumerNativeAppsSettings($form, $form_state);
}

/**
 * Form submit wrapper for consumer native app settings.
 *
 * @param array $form
 *   The form array.
 * @param \Drupal\Core\Form\FormStateInterface $form_state
 *   The form state.
 */
function simple_oauth_native_apps_submit_consumer_settings(array $form, FormStateInterface $form_state): void {
  $form_alter_service = \Drupal::service('simple_oauth_native_apps.consumer_form_alter');
  $form_alter_service->submitConsumerNativeAppsSettings($form, $form_state);
}
```

**Then update lines 225-228 in `ConsumerNativeAppsFormAlter.php`**:

```php
// Add custom validation.
$form['#validate'][] = 'simple_oauth_native_apps_validate_consumer_settings';

// Add custom submit handler.
$form['actions']['submit']['#submit'][] = 'simple_oauth_native_apps_submit_consumer_settings';
```

### Alternative Solution B: Class Static Methods

Make the validation and submit methods static and access service through static calls:

**Update `ConsumerNativeAppsFormAlter.php`**:

```php
/**
 * Validates consumer native app settings (static version).
 */
public static function validateConsumerNativeAppsSettingsStatic(array $form, FormStateInterface $form_state): void {
  $form_alter_service = \Drupal::service('simple_oauth_native_apps.consumer_form_alter');
  $form_alter_service->validateConsumerNativeAppsSettings($form, $form_state);
}

/**
 * Submits consumer native app settings (static version).
 */
public static function submitConsumerNativeAppsSettingsStatic(array $form, FormStateInterface $form_state): void {
  $form_alter_service = \Drupal::service('simple_oauth_native_apps.consumer_form_alter');
  $form_alter_service->submitConsumerNativeAppsSettings($form, $form_state);
}
```

**Then update the form alter method**:

```php
// Add custom validation.
$form['#validate'][] = [self::class, 'validateConsumerNativeAppsSettingsStatic'];

// Add custom submit handler.
$form['actions']['submit']['#submit'][] = [self::class, 'submitConsumerNativeAppsSettingsStatic'];
```

**Recommended**: Solution A (static wrapper functions) is preferred as it's cleaner and follows Drupal patterns.

## Priority 2: Field Widget AJAX Callbacks (Medium Impact)

### Current Problem

Unlimited cardinality fields use object method references for AJAX callbacks:

- Contact email field: `EmailDefaultWidget` methods
- Redirect URI field: `StringTextfieldWidget` methods

### Analysis

These are core Drupal field widgets that may have serialization issues by design. The recommended approach is to monitor if this causes actual problems vs. theoretical ones.

### Recommended Solution: Custom AJAX Handlers

If AJAX operations actually fail, create custom AJAX handlers:

**Example for contacts field in `simple_oauth_client_registration.module`**:

```php
/**
 * AJAX callback for contacts field add more operation.
 */
function simple_oauth_client_registration_contacts_add_more_ajax(array &$form, FormStateInterface $form_state) {
  // Delegate to the default widget AJAX handler
  $element = $form_state->getTriggeringElement();
  $parents = array_slice($element['#parents'], 0, -1);
  $field_element = NestedArray::getValue($form, $parents);

  return $field_element;
}

/**
 * AJAX callback for contacts field delete operation.
 */
function simple_oauth_client_registration_contacts_delete_ajax(array &$form, FormStateInterface $form_state) {
  // Similar implementation for delete operation
  $element = $form_state->getTriggeringElement();
  $parents = array_slice($element['#parents'], 0, -2);
  $field_element = NestedArray::getValue($form, $parents);

  return $field_element;
}
```

Then override the AJAX callbacks in a form alter hook.

## Priority 3: AJAX Detection Button (Low Impact)

### Current Problem

The detect button in native apps form alter has string callback `"::detectClientTypeAjax"` which appears correct but may have deeper serialization issues.

### Recommended Solution

Convert to static callback if needed:

```php
$form['native_apps']['client_detection']['detect_button'] = [
  '#type' => 'button',
  '#value' => $this->t('Detect Client Type'),
  '#ajax' => [
    'callback' => 'simple_oauth_native_apps_detect_client_type_ajax',
    'wrapper' => 'client-detection-results',
    'event' => 'click',
  ],
];
```

With wrapper function in module file:

```php
/**
 * AJAX callback for client type detection.
 */
function simple_oauth_native_apps_detect_client_type_ajax(array &$form, FormStateInterface $form_state) {
  $form_alter_service = \Drupal::service('simple_oauth_native_apps.consumer_form_alter');
  return $form_alter_service->detectClientTypeAjax($form, $form_state);
}
```

## Implementation Order

1. **First**: Fix `ConsumerNativeAppsFormAlter` service method references (highest impact)
2. **Second**: Test if field widget AJAX actually fails in practice
3. **Third**: Only implement field widget fixes if actual failures occur
4. **Fourth**: Address detect button if serialization issues persist

## Testing Protocol

After implementing Priority 1 fix:

1. Enable form serialization debugging
2. Create/edit Consumer with multiple contacts and redirect URIs
3. Test all AJAX operations (add more, remove, detect client type)
4. Verify no closure issues in debugging output
5. Confirm all form functionality works correctly

## Risk Assessment

- **Priority 1**: Low risk, high reward - simple function wrapper pattern
- **Priority 2**: Medium risk - may break field functionality if implemented incorrectly
- **Priority 3**: Low risk, low reward - may not be necessary

## Success Criteria

1. No `ConsumerNativeAppsFormAlter` object instances in form validation/submit handlers
2. All Consumer form AJAX operations work without serialization errors
3. Form debugging shows significant reduction in closure issues
4. User experience remains identical or improves
5. No regression in existing functionality

The recommended approach prioritizes the most impactful fix (form alter service) while taking a cautious approach to field widgets that might work correctly despite theoretical serialization concerns.
