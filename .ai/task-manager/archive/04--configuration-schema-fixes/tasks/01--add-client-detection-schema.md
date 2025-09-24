---
id: 1
group: 'schema-definition'
dependencies: []
status: 'pending'
created: '2025-09-22'
skills: ['drupal-backend']
complexity_score: 3.3
complexity_notes: 'Low complexity - single file schema addition with minor decisions about structure'
---

# Add client_detection Schema Definition

## Objective

Add the missing `client_detection` field schema definition to the `simple_oauth_native_apps.schema.yml` file to resolve configuration schema compliance errors for consumer configurations.

## Skills Required

- **drupal-backend**: Drupal configuration schema system expertise, including YAML schema definition syntax and constraints

## Acceptance Criteria

- [ ] `client_detection` field schema added to `simple_oauth_native_apps.consumer.*` mapping in schema file
- [ ] Schema definition matches the structure of existing `client_detection` data in consumer configurations
- [ ] Schema validates successfully against current configuration data
- [ ] No configuration schema errors reported for `client_detection` field

## Technical Requirements

- Extend the existing `simple_oauth_native_apps.consumer.*` schema definition in `/modules/simple_oauth_native_apps/config/schema/simple_oauth_native_apps.schema.yml`
- Define `client_detection` as appropriate type based on current data structure (appears to be empty arrays currently)
- Follow Drupal configuration schema conventions and syntax
- Ensure schema is compatible with existing consumer configuration data

## Input Dependencies

- Analysis of existing `client_detection` data structure in consumer configurations
- Understanding of ConsumerNativeAppsFormAlter form section that creates this data

## Output Artifacts

- Updated `simple_oauth_native_apps.schema.yml` file with `client_detection` field definition
- Schema definition that validates existing consumer configuration data

## Implementation Notes

<details>
<summary>Detailed Implementation Instructions</summary>

1. **Analyze existing data structure**:
   - Run `drush config:get simple_oauth_native_apps.consumer.1` to examine current `client_detection` data
   - Check other consumer configurations to understand data patterns
   - Examine ConsumerNativeAppsFormAlter.php to understand intended data structure

2. **Add schema definition**:
   - Open `/modules/simple_oauth_native_apps/config/schema/simple_oauth_native_apps.schema.yml`
   - Locate the `simple_oauth_native_apps.consumer.*` mapping section (around line 90)
   - Add `client_detection` field definition after existing fields

3. **Schema structure options**:
   - If `client_detection` stores empty arrays, define as: `type: mapping` with appropriate sub-fields
   - If it's meant for client detection results, consider: `type: sequence` with detection result items
   - Based on form structure, likely needs to be a mapping for detection results and UI state

4. **Example schema addition**:

   ```yaml
   client_detection:
     type: mapping
     label: 'Client detection configuration'
     description: 'Storage for client type detection results and configuration'
     mapping:
       results:
         type: sequence
         label: 'Detection results'
         sequence:
           type: string
       last_analysis:
         type: timestamp
         label: 'Last analysis timestamp'
   ```

5. **Validation**:
   - Clear Drupal cache: `drush cache:rebuild`
   - Check configuration status: `drush config:status`
   - Verify no schema errors: Check admin/reports/status for configuration schema issues
   </details>
