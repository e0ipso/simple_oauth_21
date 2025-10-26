---
id: 4
group: 'configuration'
dependencies: [2]
status: 'completed'
created: 2025-10-22
skills:
  - drupal-backend
---

# Add Routing Configuration for Settings Form

## Objective

Create or update the routing configuration to make the `ClientRegistrationSettingsForm` accessible at `/admin/config/people/simple_oauth/client-registration` with appropriate permission requirements.

## Skills Required

- `drupal-backend`: Drupal routing system, YAML configuration, access control

## Acceptance Criteria

- [ ] Route added to `simple_oauth_client_registration.routing.yml`
- [ ] Route name follows convention: `simple_oauth_client_registration.settings`
- [ ] Path is `/admin/config/people/simple_oauth/client-registration`
- [ ] Permission is `administer simple_oauth entities` (aligns with existing Simple OAuth permissions)
- [ ] Form class correctly references `\Drupal\simple_oauth_client_registration\Form\ClientRegistrationSettingsForm`
- [ ] Route is accessible after cache rebuild

## Technical Requirements

**Module**: `simple_oauth_client_registration`

**File to Create or Modify**: `modules/simple_oauth_client_registration/simple_oauth_client_registration.routing.yml`

**Route Configuration** (from plan):

- Path: `/admin/config/people/simple_oauth/client-registration`
- Permission: `administer simple_oauth entities`
- Form class: `\Drupal\simple_oauth_client_registration\Form\ClientRegistrationSettingsForm`

**Route Name Convention**: Follow Drupal standards - `{module}.{route_identifier}`

## Input Dependencies

- Task 2: Settings form class must exist for route to be functional

## Output Artifacts

- Routing configuration enabling admin access to settings form
- Form becomes accessible at specified path after `drush cache:rebuild`

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Check for Existing Routing File

```bash
cd /var/www/html/web/modules/contrib/simple_oauth_21/modules/simple_oauth_client_registration
ls -la simple_oauth_client_registration.routing.yml
```

If file exists: add new route to existing file
If file doesn't exist: create new routing file

### Step 2: Create/Update Routing File

File: `simple_oauth_client_registration.routing.yml`

```yaml
simple_oauth_client_registration.register:
  # ... existing routes if any ...

simple_oauth_client_registration.settings:
  path: '/admin/config/people/simple_oauth/client-registration'
  defaults:
    _form: '\Drupal\simple_oauth_client_registration\Form\ClientRegistrationSettingsForm'
    _title: 'Client Registration Settings'
  requirements:
    _permission: 'administer simple_oauth entities'
```

### Key Configuration Points

- **Route Name**: `simple_oauth_client_registration.settings` (matches module namespace)
- **Path**: Integrates with Simple OAuth admin structure at `/admin/config/people/simple_oauth/`
- **\_form**: Points to the form class created in Task 2
- **\_title**: Appears as page title in admin UI
- **\_permission**: Uses existing Simple OAuth permission (no new permission needed)

### Step 3: Verify Route After Implementation

```bash
# Clear caches to register new route
cd /var/www/html
vendor/bin/drush cache:rebuild

# Verify route exists
vendor/bin/drush route:debug simple_oauth_client_registration.settings
```

### Integration Notes

- **No Menu Link Needed**: Per plan notes, route alone is sufficient for admin UI integration
- **Existing Permission**: `administer simple_oauth entities` is already defined by Simple OAuth module
- **Path Structure**: Follows Drupal convention - `/admin/config/{category}/{module}/{page}`

### Coding Standards

- YAML formatting: 2-space indentation
- No trailing spaces
- Newline at end of file
- Proper YAML syntax (colons followed by space)

</details>
