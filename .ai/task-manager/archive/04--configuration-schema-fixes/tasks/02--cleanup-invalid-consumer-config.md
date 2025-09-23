---
id: 2
group: 'data-cleanup'
dependencies: [1]
status: 'completed'
created: '2025-09-22'
skills: ['drupal-backend']
complexity_score: 4.4
complexity_notes: 'Moderate complexity - requires careful data analysis and Drupal config API usage'
---

# Clean Up Invalid Consumer Configuration Data

## Objective

Remove invalid configuration data and ensure proper structure for all existing consumer configurations to eliminate schema compliance errors and orphaned fields.

## Skills Required

- **drupal-backend**: Drupal configuration API expertise, data manipulation, and understanding of configuration storage mechanisms

## Acceptance Criteria

- [ ] All consumer configurations cleaned of orphaned or improperly structured fields
- [ ] `client_detection` fields properly structured according to new schema definition
- [ ] No configuration contains fields without schema definitions
- [ ] All consumer configurations validate successfully against their schemas
- [ ] Backup of original configurations created before cleanup

## Technical Requirements

- Use Drupal Configuration API to read, modify, and save consumer configurations
- Identify all consumer configurations with `simple_oauth_native_apps.consumer.*` pattern
- Remove fields that don't have corresponding schema definitions
- Restructure `client_detection` data to match the schema added in task 1
- Maintain functional configuration while removing orphaned data

## Input Dependencies

- Completed schema definition from task 1 (client_detection field schema)
- Analysis of all existing consumer configurations
- Understanding of which fields are valid vs orphaned

## Output Artifacts

- Cleaned consumer configuration files
- Backup of original configurations
- Documentation of changes made to each configuration

## Implementation Notes

<details>
<summary>Detailed Implementation Instructions</summary>

1. **Backup existing configurations**:

   ```bash
   # Export all consumer configs for backup
   drush config:export --partial simple_oauth_native_apps.consumer.*
   # Or manually backup with:
   mkdir -p /tmp/oauth_consumer_backup
   drush sql:query "SELECT name, data FROM config WHERE name LIKE 'simple_oauth_native_apps.consumer.%'" > /tmp/oauth_consumer_backup/original_configs.sql
   ```

2. **Identify all consumer configurations**:

   ```bash
   # List all consumer configs
   drush sql:query "SELECT name FROM config WHERE name LIKE 'simple_oauth_native_apps.consumer.%'"
   ```

3. **Analyze current data structure**:
   - Get each consumer config: `drush config:get simple_oauth_native_apps.consumer.X`
   - Document current structure and identify orphaned fields
   - Compare against schema definition to identify valid vs invalid fields

4. **Clean up configurations using PHP/Drush**:

   ```php
   // Example cleanup script
   $config_factory = \Drupal::configFactory();
   $configs = $config_factory->listAll('simple_oauth_native_apps.consumer.');

   foreach ($configs as $config_name) {
     $config = $config_factory->getEditable($config_name);
     $data = $config->getRawData();

     // Remove empty client_detection arrays if they serve no purpose
     if (isset($data['client_detection']) && empty($data['client_detection'])) {
       unset($data['client_detection']);
     }

     // Restructure client_detection if needed based on new schema
     // ... additional cleanup logic ...

     $config->setData($data)->save();
   }
   ```

5. **Validation steps**:
   - Clear cache: `drush cache:rebuild`
   - Verify configurations load: `drush config:get simple_oauth_native_apps.consumer.1`
   - Check for schema errors in admin/reports/status
   - Test consumer form functionality

6. **Handle edge cases**:
   - Empty configurations
   - Configurations with only invalid fields
   - Configurations with mixed valid/invalid data
   - Ensure no functional data is lost during cleanup
   </details>
