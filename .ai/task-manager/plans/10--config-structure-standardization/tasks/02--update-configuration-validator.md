---
id: 2
group: 'validator-standardization'
dependencies: [1]
status: 'pending'
created: 2025-10-07
skills:
  - drupal-backend
  - php
---

# Update ConfigurationValidator to Use Full Nested Structure

## Objective

Update the ConfigurationValidator service to consistently expect nested configuration structure throughout all validation methods, completing the partial nested structure that already exists.

## Skills Required

- **drupal-backend**: Understanding of Drupal services and configuration validation
- **php**: Ability to refactor PHP code and update array access patterns

## Acceptance Criteria

- [ ] All validation methods use nested structure (`$config['webview']['detection']` not `$config['webview_detection']`)
- [ ] No references to flat structure patterns remain
- [ ] Class documentation updated to reflect nested structure expectation
- [ ] All validation logic functions correctly with nested arrays
- [ ] PHPStan analysis passes at level 1

## Technical Requirements

**File to modify**: `src/Service/ConfigurationValidator.php`

**Critical updates needed**:

1. Line 75: `$config['webview_detection']` → `$config['webview']['detection']`
2. Line 116: `$config['allow_custom_uri_schemes']` → `$config['allow']['custom_uri_schemes']`
3. Line 116: `$config['allow_loopback_redirects']` → `$config['allow']['loopback_redirects']`
4. Line 240: `$config['logging_level']` → `$config['log']['level']`

**Note**: Lines 83-96 and 143+ already use nested structure correctly

## Input Dependencies

- Task 1: Clean schema definition establishes canonical structure

## Output Artifacts

- Updated ConfigurationValidator service expecting only nested configuration
- Validation methods that directly accept form values without transformation

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

1. **Update validateWebViewConfig method** (lines 62-99):

   ```php
   // BEFORE (line 75)
   $detection_policy = $config['webview_detection'] ?? '';

   // AFTER
   $detection_policy = $config['webview']['detection'] ?? '';
   ```

   - Update validation error messages if they reference field names
   - Lines 83-96 already correctly use `$config['webview']['whitelist']` and `$config['webview']['patterns']`

2. **Update validateRedirectUriConfig method** (lines 101-128):

   ```php
   // BEFORE (line 116)
   $custom_uri_disabled = isset($config['allow_custom_uri_schemes']) && $config['allow_custom_uri_schemes'] === 'web';
   $loopback_disabled = isset($config['allow_loopback_redirects']) && $config['allow_loopback_redirects'] === 'web';

   // AFTER
   $custom_uri_disabled = isset($config['allow']['custom_uri_schemes']) && $config['allow']['custom_uri_schemes'] === 'web';
   $loopback_disabled = isset($config['allow']['loopback_redirects']) && $config['allow']['loopback_redirects'] === 'web';
   ```

3. **Verify validatePkceConfig method** (lines 130-170):
   - This method already correctly uses `$config['native']['enforce']` and `$config['native']['enhanced_pkce']`
   - No changes needed

4. **Update validateLoggingConfig method** (lines 226-247):

   ```php
   // BEFORE (line 240)
   if (isset($config['logging_level']) && !in_array($config['logging_level'], $valid_levels, TRUE)) {

   // AFTER - Note schema uses 'log' not 'logging'
   if (isset($config['log']['level']) && !in_array($config['log']['level'], $valid_levels, TRUE)) {
   ```

5. **Update class DocBlock** (lines 9-12):
   - Add documentation explaining this validator expects nested configuration structure
   - Document expected structure format
   - Remove any references to "flat structure" or structure mapping

6. **Test validator with nested config**:

   ```php
   $test_config = [
     'webview' => ['detection' => 'block'],
     'allow' => ['custom_uri_schemes' => 'native'],
     'native' => ['enforce' => 'S256'],
     'log' => ['level' => 'info'],
   ];
   $errors = $this->configurationValidator->validateConfiguration($test_config);
   ```

7. **Run static analysis**:
   ```bash
   vendor/bin/phpstan analyse src/Service/ConfigurationValidator.php
   ```

</details>
