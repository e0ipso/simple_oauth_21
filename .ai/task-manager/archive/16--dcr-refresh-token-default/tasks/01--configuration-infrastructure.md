---
id: 1
group: 'configuration'
dependencies: []
status: 'completed'
created: 2025-10-22
skills:
  - drupal-backend
---

# Create Configuration Schema and Default Settings

## Objective

Establish the configuration infrastructure for the DCR refresh token feature by creating the schema definition and install configuration with the `auto_enable_refresh_token` setting defaulting to `true`.

## Skills Required

- `drupal-backend`: Drupal configuration API, YAML schema definitions, config management

## Acceptance Criteria

- [ ] Configuration schema file created at `config/schema/simple_oauth_client_registration.schema.yml`
- [ ] Schema defines `auto_enable_refresh_token` as boolean type with proper label
- [ ] Install configuration file created at `config/install/simple_oauth_client_registration.settings.yml`
- [ ] Default value set to `true` for `auto_enable_refresh_token`
- [ ] Schema validates correctly (can be tested after implementation with `drush config:inspect`)

## Technical Requirements

**Module**: `simple_oauth_client_registration`

**Files to Create**:

1. `modules/simple_oauth_client_registration/config/schema/simple_oauth_client_registration.schema.yml`
2. `modules/simple_oauth_client_registration/config/install/simple_oauth_client_registration.settings.yml`

**Schema Structure** (from plan):

```yaml
simple_oauth_client_registration.settings:
  type: config_object
  mapping:
    auto_enable_refresh_token:
      type: boolean
      label: 'Automatically enable refresh_token grant for DCR clients'
```

**Install Config Structure**:

```yaml
auto_enable_refresh_token: true
```

## Input Dependencies

None - this is a foundational task

## Output Artifacts

- Configuration schema file defining the `auto_enable_refresh_token` setting
- Install configuration file with default value
- These files enable the settings form and service logic to read/write configuration

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Create Schema Directory (if needed)

```bash
cd /var/www/html/web/modules/contrib/simple_oauth_21/modules/simple_oauth_client_registration
mkdir -p config/schema
```

### Step 2: Create Schema File

Create `config/schema/simple_oauth_client_registration.schema.yml` with:

```yaml
simple_oauth_client_registration.settings:
  type: config_object
  label: 'Client Registration Settings'
  mapping:
    auto_enable_refresh_token:
      type: boolean
      label: 'Automatically enable refresh_token grant for DCR clients'
```

### Step 3: Create Install Config Directory (if needed)

```bash
mkdir -p config/install
```

### Step 4: Create Install Config File

Create `config/install/simple_oauth_client_registration.settings.yml` with:

```yaml
auto_enable_refresh_token: true
```

### Important Notes

- Follow Drupal's config schema conventions
- Schema file enables validation and type safety
- Install config is deployed when module is first installed
- Setting defaults to `true` per OAuth 2.1 best practices (opt-out pattern)
- No trailing spaces, newline at end of each file

</details>
