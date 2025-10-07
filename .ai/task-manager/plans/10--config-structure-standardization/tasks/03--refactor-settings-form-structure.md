---
id: 3
group: 'form-standardization'
dependencies: [1, 2]
status: 'pending'
created: 2025-10-07
skills:
  - drupal-backend
  - php
---

# Refactor NativeAppsSettingsForm to Use Nested Structure

## Objective

Restructure the NativeAppsSettingsForm to use nested form arrays that directly match the configuration schema, eliminating the need for structure conversion between form values and configuration storage.

## Skills Required

- **drupal-backend**: Deep understanding of Drupal Form API, `#tree`, nested elements, and `#states`
- **php**: Ability to refactor complex form building, validation, and submission logic

## Acceptance Criteria

- [ ] Form elements use nested keys matching schema (`$form['webview']['detection']` not `$form['webview']['webview_detection']`)
- [ ] Form validation reads nested values directly without transformation
- [ ] Form submission saves nested values directly to configuration
- [ ] `#states` selectors updated to match new nested element names
- [ ] `#default_value` reads from correct nested config paths
- [ ] Method `convertFormValuesToConfig()` simplified or eliminated
- [ ] Form can be rendered, validated, and submitted successfully

## Technical Requirements

**File to modify**: `src/Form/NativeAppsSettingsForm.php`

**Major changes required**:

1. **buildForm** (lines 93-287): Restructure all form elements to nested keys
2. **convertFormValuesToConfig** (lines 322-345): Simplify since form matches config
3. **validateForm & validateFormSpecificRules** (lines 293-393): Update field references
4. **submitForm** (lines 398-432): Direct save without mapping

**Key transformation pattern**:

```php
// BEFORE
$form['webview']['webview_detection'] = [...];
$config->set('webview.detection', $values['webview']['webview_detection']);

// AFTER
$form['webview']['detection'] = [...];
$config->set('webview.detection', $values['webview']['detection']);
```

## Input Dependencies

- Task 1: Schema defines canonical nested structure
- Task 2: Validator accepts nested structure for validation

## Output Artifacts

- Refactored form using native nested structure
- Simplified form processing with direct config mapping
- Pattern for consumer form to follow

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Phase 1: Update Form Element Structure (buildForm)

1. **WebView elements** (lines 121-181):

   ```php
   // BEFORE
   $form['webview']['webview_detection'] = [
     '#type' => 'radios',
     '#default_value' => $config->get('webview.detection'),
   ];

   // AFTER
   $form['webview']['detection'] = [
     '#type' => 'radios',
     '#default_value' => $config->get('webview.detection'),
   ];
   ```

   - Update: `webview_detection` → `detection`
   - Update: `webview_custom_message` → `custom_message`
   - Update: `webview_whitelist` → under `advanced['whitelist']`
   - Update: `webview_patterns` → under `advanced['patterns']`

2. **Update #states selectors** (line 142):

   ```php
   // BEFORE
   ':input[name="webview[webview_detection]"]' => ['!value' => 'off'],

   // AFTER
   ':input[name="webview[detection]"]' => ['!value' => 'off'],
   ```

3. **Redirect URI elements** (lines 191-222):

   ```php
   // BEFORE
   $form['redirect_uri']['allow_custom_uri_schemes'] = [...]

   // AFTER
   $form['allow']['custom_uri_schemes'] = [...]
   ```

   - Move to `$form['allow']['custom_uri_schemes']`
   - Move to `$form['allow']['loopback_redirects']`
   - Keep `require_exact_redirect_match` at top level per schema

4. **PKCE elements** (lines 242-266):

   ```php
   // BEFORE
   $form['pkce']['enhanced_pkce_for_native'] = [...]
   $form['pkce']['enforce_method'] = [...]

   // AFTER
   $form['native']['enhanced_pkce'] = [...]
   $form['native']['enforce'] = [...]
   ```

   - Note: Group name changes from `pkce` to `native` to match schema

### Phase 2: Simplify convertFormValuesToConfig (lines 322-345)

Since form structure now matches config structure, this method becomes trivial:

```php
protected function convertFormValuesToConfig(array $values): array {
  // Form structure now matches config structure - minimal transformation needed
  return [
    'enforce_native_security' => $values['security']['enforce_native_security'] ?? FALSE,
    'webview' => $values['webview'] ?? [],
    'require_exact_redirect_match' => $values['redirect_uri']['require_exact_redirect_match'] ?? TRUE,
    'allow' => $values['allow'] ?? [],
    'native' => $values['native'] ?? [],
  ];
}
```

### Phase 3: Update Validation (lines 293-393)

1. **validateForm method** (lines 293-311):
   - Values already in nested structure, pass directly to validator
   - Remove any structure mapping

2. **validateFormSpecificRules method** (lines 355-393):

   ```php
   // BEFORE
   if (empty($values['pkce']['enforce_method'])) {

   // AFTER
   if (empty($values['native']['enforce'])) {
   ```

   - Update all field references to match new nested structure
   - Update: `pkce][enforce_method` → `native][enforce`
   - Update: `pkce][enhanced_pkce_for_native` → `native][enhanced_pkce`

### Phase 4: Update Submission (lines 398-432)

Direct mapping since form values match config structure:

```php
// BEFORE
$config->set('webview.detection', $values['webview']['webview_detection']);

// AFTER
$config->set('webview.detection', $values['webview']['detection']);
```

Apply this pattern to all config saves:

- `$config->set('webview.detection', $values['webview']['detection'])`
- `$config->set('webview.custom_message', $values['webview']['custom_message'])`
- `$config->set('allow.custom_uri_schemes', $values['allow']['custom_uri_schemes'])`
- `$config->set('allow.loopback_redirects', $values['allow']['loopback_redirects'])`
- `$config->set('native.enhanced_pkce', $values['native']['enhanced_pkce'])`
- `$config->set('native.enforce', $values['native']['enforce'])`

### Phase 5: Test Form Operations

```bash
# Clear cache after changes
vendor/bin/drush cache:rebuild

# Manual testing:
# 1. Navigate to /admin/config/services/simple-oauth/native-apps
# 2. Verify form renders without errors
# 3. Change settings and save
# 4. Verify configuration saves correctly
# 5. Reload form and verify default values load correctly
```

### Phase 6: Verify No Errors

```bash
vendor/bin/phpstan analyse src/Form/NativeAppsSettingsForm.php
vendor/bin/drush watchdog:show --severity=Error
```

</details>
