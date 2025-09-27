---
id: 2
group: 'module-foundation'
dependencies: [1]
status: 'completed'
created: '2025-09-26'
skills: ['drupal-backend', 'database']
---

# Implement Device Code Database Schema

## Objective

Create the database schema and installation hooks for storing device codes, implementing the oauth2_device_code table with proper indexes for efficient lookups.

## Skills Required

- **drupal-backend**: Drupal installation hooks and schema patterns
- **database**: Table design, indexing, data types

## Acceptance Criteria

- [ ] Database schema defined in hook_schema()
- [ ] Installation hooks for module enable/disable
- [ ] Proper indexes on device_code and user_code fields
- [ ] Foreign key relationships to consumer entities
- [ ] Update hooks for schema changes if needed

## Technical Requirements

- Create oauth2_device_code table with required fields per RFC 8628
- Implement hook_schema() in .install file
- Add database indexes for performance
- Follow Drupal database API patterns

## Input Dependencies

- Module foundation files from task 1

## Output Artifacts

- simple_oauth_device_flow.install file
- Database schema for oauth2_device_code table
- Installation and uninstallation hooks

## Implementation Notes

<details>
<summary>Detailed implementation guidance</summary>

**Required table fields:**

```php
$schema['oauth2_device_code'] = [
  'description' => 'Stores OAuth 2.0 device codes for RFC 8628 device flow',
  'fields' => [
    'device_code' => [
      'type' => 'varchar',
      'length' => 128,
      'not null' => TRUE,
      'description' => 'The device code identifier',
    ],
    'user_code' => [
      'type' => 'varchar',
      'length' => 32,
      'not null' => TRUE,
      'description' => 'Human-readable user code',
    ],
    'client_id' => [
      'type' => 'varchar',
      'length' => 128,
      'not null' => TRUE,
      'description' => 'OAuth client identifier',
    ],
    'scopes' => [
      'type' => 'text',
      'description' => 'Serialized array of requested scopes',
    ],
    'user_id' => [
      'type' => 'int',
      'unsigned' => TRUE,
      'description' => 'User ID once authorized',
    ],
    'authorized' => [
      'type' => 'int',
      'size' => 'tiny',
      'default' => 0,
      'description' => 'Authorization status',
    ],
    'created_at' => [
      'type' => 'int',
      'not null' => TRUE,
      'description' => 'Creation timestamp',
    ],
    'expires_at' => [
      'type' => 'int',
      'not null' => TRUE,
      'description' => 'Expiration timestamp',
    ],
    'last_polled_at' => [
      'type' => 'int',
      'description' => 'Last polling timestamp',
    ],
    'interval' => [
      'type' => 'int',
      'default' => 5,
      'description' => 'Polling interval in seconds',
    ],
  ],
  'primary key' => ['device_code'],
  'unique keys' => [
    'user_code' => ['user_code'],
  ],
  'indexes' => [
    'client_id' => ['client_id'],
    'expires_at' => ['expires_at'],
    'user_id' => ['user_id'],
  ],
];
```

**Installation hooks:**

- hook_install(): Set default configuration
- hook_uninstall(): Clean up configuration and data
- hook_requirements(): Check dependencies
</details>
