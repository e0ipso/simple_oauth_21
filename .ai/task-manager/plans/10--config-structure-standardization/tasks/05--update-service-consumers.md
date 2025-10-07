---
id: 5
group: 'service-consumers'
dependencies: [1]
status: 'pending'
created: 2025-10-07
skills:
  - drupal-backend
  - php
---

# Update All Service Consumers to Use Nested Configuration Paths

## Objective

Update all services and classes that read configuration from `simple_oauth_native_apps.settings` to use nested configuration paths consistently throughout the codebase.

## Skills Required

- **drupal-backend**: Understanding of Drupal configuration API and service patterns
- **php**: Ability to refactor configuration access patterns across multiple files

## Acceptance Criteria

- [ ] All services use nested config paths (`$config->get('webview.detection')`)
- [ ] No flat key access patterns remain (`$config->get('webview_detection')`)
- [ ] All identified files updated and tested
- [ ] grep for old patterns returns no results in PHP files
- [ ] PHPStan analysis passes for all modified files

## Technical Requirements

**Files to update** (from plan grep results):

1. `src/Service/NativeClientDetector.php`
2. `src/Service/MetadataProvider.php`
3. `src/Plugin/Validation/Constraint/NativeAppRedirectUriValidator.php`
4. `src/Service/RedirectUriValidator.php`
5. Any other files found via grep

**Mapping of old → new paths**:

- `webview_detection` → `webview.detection`
- `allow_custom_uri_schemes` → `allow.custom_uri_schemes`
- `allow_loopback_redirects` → `allow.loopback_redirects`
- `enhanced_pkce_for_native` → `native.enhanced_pkce`
- `logging_level` → `log.level`
- `webview_whitelist` → `webview.whitelist`
- `webview_patterns` → `webview.patterns`

## Input Dependencies

- Task 1: Schema defines canonical nested structure

## Output Artifacts

- All services reading configuration using consistent nested paths
- Complete end-to-end nested structure implementation

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Phase 1: Identify All Configuration Consumers

```bash
# Find all files accessing old flat configuration keys
grep -r "get('webview_detection')" src/
grep -r "get('allow_custom_uri_schemes')" src/
grep -r "get('allow_loopback_redirects')" src/
grep -r "get('enhanced_pkce_for_native')" src/
grep -r "get('logging_level')" src/
grep -r "get('webview_whitelist')" src/
grep -r "get('webview_patterns')" src/

# Note all files found for updates
```

### Phase 2: Update Each File Systematically

For each identified file, apply the transformation pattern:

```php
// BEFORE
$detection = $config->get('webview_detection');
$allow_custom = $config->get('allow_custom_uri_schemes');
$allow_loopback = $config->get('allow_loopback_redirects');
$enhanced_pkce = $config->get('enhanced_pkce_for_native');
$log_level = $config->get('logging_level');

// AFTER
$detection = $config->get('webview.detection');
$allow_custom = $config->get('allow.custom_uri_schemes');
$allow_loopback = $config->get('allow.loopback_redirects');
$enhanced_pkce = $config->get('native.enhanced_pkce');
$log_level = $config->get('log.level');
```

### Phase 3: Update Specific Known Files

1. **NativeClientDetector.php**:
   - Search for config get calls
   - Update to nested paths
   - Verify detection logic still works

2. **MetadataProvider.php**:
   - Search for config get calls
   - Update to nested paths
   - Verify metadata generation works

3. **NativeAppRedirectUriValidator.php**:
   - Search for config get calls
   - Update to nested paths
   - Verify validation constraints work

4. **RedirectUriValidator.php**:
   - Search for config get calls
   - Update to nested paths
   - Verify redirect URI validation works

### Phase 4: Handle Array Access Patterns

Some services may access config as arrays rather than individual gets:

```php
// BEFORE
$config_data = $config->get();
if ($config_data['webview_detection'] === 'block') { ... }

// AFTER
$config_data = $config->get();
if ($config_data['webview']['detection'] === 'block') { ... }
```

### Phase 5: Update Default Value Patterns

```php
// BEFORE
$detection = $config->get('webview_detection') ?? 'warn';

// AFTER
$detection = $config->get('webview.detection') ?? 'warn';
```

### Phase 6: Verify No Flat Keys Remain

```bash
# These should return NO results after updates
grep -r "get('webview_detection')" src/
grep -r "get('allow_custom_uri_schemes')" src/
grep -r "get('allow_loopback_redirects')" src/
grep -r "get('enhanced_pkce_for_native')" src/
grep -r "get('logging_level')" src/

# Also check for array access patterns (harder to grep)
# Manual review may be needed
```

### Phase 7: Test Each Service

For each modified service:

1. **Clear cache**:

   ```bash
   vendor/bin/drush cache:rebuild
   ```

2. **Run static analysis**:

   ```bash
   vendor/bin/phpstan analyse src/Service/
   vendor/bin/phpstan analyse src/Plugin/
   ```

3. **Check for runtime errors**:

   ```bash
   vendor/bin/drush watchdog:show --severity=Error
   ```

4. **Test functionality**:
   - For validators: Test redirect URI validation
   - For metadata: Check OAuth metadata endpoints
   - For detectors: Verify client type detection
   - For services: Exercise the service through normal operations

### Phase 8: Document Changes

Add comments in modified files noting the nested structure:

```php
/**
 * Gets webview detection setting from nested configuration.
 *
 * Configuration structure:
 * @code
 * webview:
 *   detection: 'off|warn|block'
 * @endcode
 */
$detection = $config->get('webview.detection');
```

</details>
