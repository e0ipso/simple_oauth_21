---
id: 2
group: 'rfc-7591-client-registration'
dependencies: [1]
status: 'pending'
created: '2025-09-16'
skills: ['drupal-backend', 'entity-api']
complexity_score: 5.0
---

# Add Consumer Entity Fields for RFC 7591

## Objective

Add RFC 7591 client metadata fields to the Consumer entity using `hook_entity_base_field_info()` following the exact pattern established by `simple_oauth_native_apps` module.

## Skills Required

- **drupal-backend**: Drupal module hooks, entity system
- **entity-api**: BaseFieldDefinition, entity field management

## Acceptance Criteria

- [ ] `hook_entity_base_field_info()` implemented in `simple_oauth_client_registration.module`
- [ ] All RFC 7591 client metadata fields added to Consumer entity
- [ ] Fields have proper labels, descriptions, and data types
- [ ] Fields are configurable via Consumer edit forms
- [ ] New fields appear in Consumer entity without breaking existing functionality
- [ ] `simple_oauth_client_registration.install` file with proper field installation/uninstallation
- [ ] Module uninstall cleanly removes all added fields using `hook_uninstall()`

## Technical Requirements

**RFC 7591 Required Fields to Add:**

- `client_name` (string) - Human-readable client name
- `client_uri` (uri) - Client information URI
- `logo_uri` (uri) - Client logo URI
- `contacts` (text, multiple) - Contact information
- `tos_uri` (uri) - Terms of service URI
- `policy_uri` (uri) - Privacy policy URI
- `jwks_uri` (uri) - JSON Web Key Set URI
- `software_id` (string) - Software identifier
- `software_version` (string) - Software version

## Input Dependencies

- Task 1: Module structure and .module file must exist

## Output Artifacts

- Consumer entity extended with RFC 7591 metadata fields
- Form integration for field configuration
- Install/uninstall hooks for proper field lifecycle management

## Implementation Notes

<details>
<summary>Detailed Implementation Instructions</summary>

Copy the exact pattern from `simple_oauth_native_apps_entity_base_field_info()` in `simple_oauth_native_apps.module`:

**Hook Implementation:**

```php
function simple_oauth_client_registration_entity_base_field_info(EntityTypeInterface $entity_type) {
  $fields = [];

  if ($entity_type->id() === 'consumer') {
    // Add each RFC 7591 field using BaseFieldDefinition::create()
  }

  return $fields;
}
```

**Field Definition Pattern:**

- Use `BaseFieldDefinition::create('string')` for text fields
- Use `BaseFieldDefinition::create('uri')` for URI fields
- Use `BaseFieldDefinition::create('text_long')` for contacts (with multiple values)
- Set proper `setLabel()`, `setDescription()`, `setRequired(FALSE)`
- Configure `setDisplayOptions('form', [...])` for admin forms
- Set reasonable `setDefaultValue()` where appropriate

**Field Examples:**

- client_name: Required field with max 255 characters
- contacts: Multiple value field for email/contact info
- URIs: Validate as proper URLs

Follow the exact field definition structure from the native_apps module's `native_app_override` field implementation.

**Install/Uninstall Hooks (.install file):**

Create `simple_oauth_client_registration.install` following the exact pattern from `simple_oauth_native_apps.install`:

```php
<?php

use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Implements hook_install().
 */
function simple_oauth_client_registration_install($is_syncing) {
  if (!$is_syncing) {
    $entity_type_manager = \Drupal::entityTypeManager();
    $entity_type_manager->clearCachedDefinitions();

    $definition_update_manager = \Drupal::entityDefinitionUpdateManager();

    // Install each RFC 7591 field using installFieldStorageDefinition()
    // Follow exact pattern from native_apps module lines 23-47
  }
}

/**
 * Implements hook_uninstall().
 */
function simple_oauth_client_registration_uninstall($is_syncing) {
  if (!$is_syncing) {
    $definition_update_manager = \Drupal::entityDefinitionUpdateManager();

    // Remove each RFC 7591 field using uninstallFieldStorageDefinition()
    // Follow exact pattern from native_apps module lines 88-95

    foreach (['client_name', 'client_uri', 'logo_uri', 'contacts', 'tos_uri', 'policy_uri', 'jwks_uri', 'software_id', 'software_version'] as $field_name) {
      if ($field_storage_definition = $definition_update_manager->getFieldStorageDefinition($field_name, 'consumer')) {
        $definition_update_manager->uninstallFieldStorageDefinition($field_storage_definition);
      }
    }
  }
}
```

**Field Installation Pattern:**

- Use `installFieldStorageDefinition($field_name, 'consumer', 'simple_oauth_client_registration', $field_definition)`
- Check field doesn't exist before installing: `if (!$definition_update_manager->getFieldStorageDefinition($field_name, 'consumer'))`
- Include provider module name in installation call

**Uninstall Cleanup:**

- Remove ALL RFC 7591 fields added by the module
- Use `uninstallFieldStorageDefinition()` to properly clean up field storage
- Check field exists before removal to avoid errors
- This ensures Consumer entity returns to its original state when module is uninstalled

**Module Uninstall Impact:**
When the `simple_oauth_client_registration` module is uninstalled:

1. All RFC 7591 metadata fields are removed from Consumer entities
2. Existing Consumer entities lose the RFC 7591 metadata but remain functional
3. No data corruption occurs - only the added fields are cleanly removed
4. Consumer entity reverts to its state before module installation

</details>
