---
id: 3
group: 'implementation'
dependencies: [1]
status: 'completed'
created: 2025-10-22
skills:
  - drupal-backend
---

# Update ClientRegistrationService to Apply Default Grant Types

## Objective

Modify `ClientRegistrationService::createConsumer()` method to read the `auto_enable_refresh_token` configuration and conditionally default to `['authorization_code', 'refresh_token']` when clients register without specifying grant types.

## Skills Required

- `drupal-backend`: Drupal service architecture, dependency injection, configuration API, PHP

## Acceptance Criteria

- [ ] `ClientRegistrationService.php` updated at line 78 region
- [ ] Logic reads `auto_enable_refresh_token` from configuration
- [ ] When setting is `true` AND client didn't specify grant_types: defaults to `['authorization_code', 'refresh_token']`
- [ ] When setting is `false` OR client specified grant_types: uses existing behavior
- [ ] Null coalescing operator (`?? TRUE`) used for safe config reads
- [ ] Code follows project standards (typed variables, PHPDoc if adding methods)
- [ ] ConfigFactory is already injected - no service definition changes needed
- [ ] Existing tests still pass (verify basic DCR workflow unbroken)

## Technical Requirements

**Module**: `simple_oauth_client_registration`

**File to Modify**: `modules/simple_oauth_client_registration/src/Service/ClientRegistrationService.php`

**Target Location**: Around line 78 in the `createConsumer()` method

**Current Code** (line 78):

```php
'grant_types' => $clientData->grantTypes ?: ['authorization_code'],
```

**Implementation Pattern** (from plan):

```php
$config = $this->configFactory->get('simple_oauth_client_registration.settings');
$auto_enable = $config->get('auto_enable_refresh_token') ?? TRUE;

$grant_types = $clientData->grantTypes;
if (empty($grant_types) && $auto_enable) {
  $grant_types = ['authorization_code', 'refresh_token'];
}
elseif (empty($grant_types)) {
  $grant_types = ['authorization_code'];
}

$values = [
  // ...
  'grant_types' => $grant_types,
  // ...
];
```

## Input Dependencies

- Task 1: Configuration schema and install config must exist
- ConfigFactory already injected in `ClientRegistrationService` (verify via reading the file)

## Output Artifacts

- Updated `ClientRegistrationService.php` with conditional default grant type logic
- Enables the core feature: DCR clients get refresh tokens by default (when configured)

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Read Current Implementation

```bash
cd /var/www/html/web/modules/contrib/simple_oauth_21/modules/simple_oauth_client_registration
```

Read `src/Service/ClientRegistrationService.php` to understand:

- Current `createConsumer()` method structure (around line 57-120)
- Verify `$this->configFactory` is available (injected in constructor)
- Locate exact line where `grant_types` is set

### Step 2: Modify createConsumer() Method

Replace the single line:

```php
'grant_types' => $clientData->grantTypes ?: ['authorization_code'],
```

With the conditional logic:

```php
// Read configuration for default grant types.
$config = $this->configFactory->get('simple_oauth_client_registration.settings');
$auto_enable = $config->get('auto_enable_refresh_token') ?? TRUE;

// Determine grant types: respect explicit client request, otherwise apply defaults.
$grant_types = $clientData->grantTypes;
if (empty($grant_types) && $auto_enable) {
  $grant_types = ['authorization_code', 'refresh_token'];
}
elseif (empty($grant_types)) {
  $grant_types = ['authorization_code'];
}

$values = [
  'client_id' => $client_id,
  'label' => $clientData->clientName ?? 'Dynamically Registered Client',
  'description' => 'Client registered via RFC 7591 Dynamic Client Registration',
  'grant_types' => $grant_types,  // Use computed grant types
  // ... rest of values array
];
```

### Step 3: Add Inline Comment

Add explanatory comment before the config read to document RFC 7591 authority:

```php
// RFC 7591 Section 3.2.1: Authorization servers MAY provision defaults
// for omitted metadata. Apply refresh_token grant by default when configured.
```

### Key Implementation Points

- **Config Read**: Use `$this->configFactory->get('simple_oauth_client_registration.settings')`
- **Null Safety**: Use `?? TRUE` to handle missing config (backwards compatible)
- **Conditional Logic**: Check both `empty($grant_types)` AND `$auto_enable`
- **Explicit Priority**: Client-specified grant_types always take precedence
- **No Service Changes**: ConfigFactory already injected, no services.yml changes needed

### Testing After Implementation

```bash
cd /var/www/html
vendor/bin/phpunit web/modules/contrib/simple_oauth_21/modules/simple_oauth_client_registration/tests/src/Functional/ClientRegistrationFunctionalTest.php
```

Existing tests should still pass (they explicitly specify grant_types).

### Coding Standards

- No trailing spaces
- Newline at end of file
- Comments are complete sentences with periods
- Maintain existing indentation (2 spaces)

</details>
