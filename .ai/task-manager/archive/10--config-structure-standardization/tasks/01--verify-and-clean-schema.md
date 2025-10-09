---
id: 1
group: 'schema-standardization'
dependencies: []
status: 'completed'
created: 2025-10-07
completed: 2025-10-07
skills:
  - drupal-backend
  - yaml
---

# Verify and Clean Configuration Schema

## Objective

Verify that the configuration schema (`config/schema/simple_oauth_native_apps.schema.yml`) uses the target nested structure consistently and remove legacy flat field definitions to establish a single source of truth.

## Skills Required

- **drupal-backend**: Understanding Drupal's typed configuration system and schema conventions
- **yaml**: Ability to read and modify YAML configuration schema files

## Acceptance Criteria

- [ ] Schema uses complete nested structure (webview._, allow._, native._, log._)
- [ ] Legacy flat fields removed or deprecated (enhanced_pkce_for_native, allow_custom_uri_schemes, allow_loopback_redirects)
- [ ] Consumer configuration schema matches global schema patterns
- [ ] Schema validates successfully with Drupal's configuration system
- [ ] Documentation added for canonical nested structure

## Technical Requirements

**File to modify**: `config/schema/simple_oauth_native_apps.schema.yml`

**Target structure**:

```yaml
webview:
  detection: string
  custom_message: string
  whitelist: sequence
  patterns: sequence
allow:
  custom_uri_schemes: string
  loopback_redirects: string
native:
  enhanced_pkce: string
  enforce: string
log:
  pkce_validations: boolean
  detection_decisions: boolean
```

**Legacy fields to remove** (lines 86-97):

- `enhanced_pkce_for_native` (replaced by `native.enhanced_pkce`)
- `allow_custom_uri_schemes` (replaced by `allow.custom_uri_schemes`)
- `allow_loopback_redirects` (replaced by `allow.loopback_redirects`)

## Input Dependencies

None - this is the foundational task

## Output Artifacts

- Clean configuration schema file with only nested structure
- Documentation of canonical configuration structure for downstream tasks

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

1. **Review current schema** (lines 1-147):
   - Identify all mapping definitions
   - Note which fields are already nested vs flat
   - Check consumer schema (simple_oauth_native_apps.consumer.\*)

2. **Remove legacy fields**:
   - Delete or comment out lines 86-97 containing:
     - `enhanced_pkce_for_native`
     - `allow_custom_uri_schemes`
     - `allow_loopback_redirects`
   - These are backward compatibility cruft from flat structure era

3. **Verify nested structure consistency**:
   - Ensure `webview` mapping contains: detection, custom_message, whitelist, patterns
   - Ensure `allow` mapping contains: custom_uri_schemes, loopback_redirects
   - Ensure `native` mapping contains: enhanced_pkce, enforce
   - Ensure `log` mapping contains: pkce_validations, detection_decisions
   - Note: Schema uses `log`, not `logging` - this is important for downstream tasks

4. **Verify consumer schema alignment**:
   - Check `simple_oauth_native_apps.consumer.*` schema
   - Ensure override fields match global structure
   - Consumer-specific settings should mirror global nested structure

5. **Test schema validity**:

   ```bash
   # Clear cache to reload schema
   vendor/bin/drush cache:rebuild

   # Check for schema validation errors in logs
   vendor/bin/drush watchdog:show --severity=Error
   ```

6. **Document canonical structure**:
   - Add comments in schema file explaining nested structure
   - Note that this structure is now used throughout: schema, forms, validators, services

</details>
