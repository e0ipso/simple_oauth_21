---
id: 2
group: 'token-introspection'
dependencies: [1]
status: 'completed'
created: '2025-10-22'
skills:
  - drupal-backend
---

# Integrate Token Introspection with Server Metadata and Compliance Dashboard

## Objective

Integrate the token introspection endpoint with the server metadata discovery endpoint and OAuth 2.1 compliance dashboard to enable automated client configuration and RFC 7662 compliance tracking.

## Skills Required

- **drupal-backend**: Drupal service modification, compliance service integration patterns, metadata service architecture

## Acceptance Criteria

- [ ] Server metadata endpoint (/.well-known/oauth-authorization-server) advertises introspection_endpoint URL
- [ ] OAuth 2.1 compliance dashboard shows RFC 7662 status as "configured"
- [ ] Introspection endpoint auto-detected via route existence check
- [ ] Absolute URL used for cross-origin discovery
- [ ] Integration follows existing patterns for other RFC implementations
- [ ] No breaking changes to existing metadata or dashboard functionality

Use your internal Todo tool to track these and keep on track.

## Technical Requirements

### Server Metadata Integration

- **File to modify**: `modules/simple_oauth_server_metadata/src/Service/ServerMetadataService.php`
- **Method**: `getServerMetadata()`
- **Change**: Add introspection_endpoint field when route exists
- **Pattern**: Follow existing registration_endpoint auto-detection pattern

### Compliance Dashboard Integration

- **File to modify**: `simple_oauth_21.services.yml` configuration or `src/Service/OAuth21ComplianceService.php` in main module
- **Method**: `getRfcComplianceStatus()`
- **Change**: Add RFC 7662 entry with route-based status determination
- **Pattern**: Follow existing RFC 7636, 8414, 8252, 8628, 7591 patterns

## Input Dependencies

- Task 1: TokenIntrospectionController and route must exist
- Route name: `simple_oauth_server_metadata.token_introspection`

## Output Artifacts

- Updated `modules/simple_oauth_server_metadata/src/Service/ServerMetadataService.php`
- Updated `simple_oauth_21/src/Service/OAuth21ComplianceService.php` (or equivalent)
- Server metadata JSON includes `introspection_endpoint` field
- Compliance dashboard displays RFC 7662 status

## Implementation Notes

<details>
<summary>Detailed Implementation Guide</summary>

### Step 1: Examine Existing Integration Patterns

Before making changes, read these files to understand the patterns:

1. `modules/simple_oauth_server_metadata/src/Service/ServerMetadataService.php` - Look for registration_endpoint auto-detection
2. `simple_oauth_21/src/Service/OAuth21ComplianceService.php` - Look for other RFC status checks (7636, 8414, etc.)

### Step 2: Modify ServerMetadataService

**File**: `modules/simple_oauth_server_metadata/src/Service/ServerMetadataService.php`

**Locate**: The `getServerMetadata()` method

**Add**: Auto-detection for introspection endpoint (add near other endpoint definitions):

```php
// Auto-detect introspection endpoint (RFC 7662)
try {
  $route = $this->routeProvider->getRouteByName('simple_oauth_server_metadata.token_introspection');
  if ($route) {
    $metadata['introspection_endpoint'] = Url::fromRoute(
      'simple_oauth_server_metadata.token_introspection',
      [],
      ['absolute' => TRUE]
    )->toString();
  }
}
catch (RouteNotFoundException $e) {
  // Introspection endpoint not available, skip
}
```

**Important considerations**:

- Use try-catch to handle RouteNotFoundException gracefully
- Use `['absolute' => TRUE]` option for cross-origin compatibility
- Only add field when route exists (no configuration override needed)
- Maintain consistency with existing endpoint detection patterns

**Verify injection**: Ensure `$this->routeProvider` (RouteProviderInterface) is injected in the service. If not, add to constructor dependencies.

### Step 3: Update OAuth21ComplianceService

**File**: `simple_oauth_21/src/Service/OAuth21ComplianceService.php`

**Locate**: The `getRfcComplianceStatus()` method

**Add**: RFC 7662 status check (add in the RFC status array alongside other RFCs):

```php
// RFC 7662 - Token Introspection
try {
  $introspection_route = $this->routeProvider->getRouteByName('simple_oauth_server_metadata.token_introspection');
  $introspection_available = $introspection_route !== NULL;
}
catch (RouteNotFoundException $e) {
  $introspection_available = FALSE;
}

$rfcs['rfc_7662'] = [
  'title' => 'RFC 7662',
  'name' => 'OAuth 2.0 Token Introspection',
  'status' => $introspection_available ? 'configured' : 'not_available',
  'module' => 'simple_oauth_server_metadata',
  'enabled' => $introspection_available,
  'recommendation' => $introspection_available
    ? 'Token introspection endpoint is available for resource servers'
    : 'Token introspection endpoint is not implemented',
  'description' => 'Provides standardized endpoint for querying token metadata',
];
```

**Important considerations**:

- Match the array structure of existing RFC entries (check RFC 7636, 8414, etc. for format)
- Use route existence check for status determination
- Set module attribution to `simple_oauth_server_metadata`
- Provide helpful recommendations for users

**Verify injection**: Ensure `$this->routeProvider` is injected. If not, add to service dependencies.

### Step 4: Clear Cache and Validate

After modifying services, clear Drupal cache:

```bash
vendor/bin/drush cache:rebuild
```

**Validation steps**:

1. **Test server metadata endpoint**:

```bash
curl https://example.com/.well-known/oauth-authorization-server | jq
```

Verify response includes:

```json
{
  "introspection_endpoint": "https://example.com/oauth/introspect",
  ...
}
```

2. **Test compliance dashboard**:
   Navigate to the OAuth 2.1 compliance dashboard (usually at `/admin/config/services/simple-oauth-21/compliance` or similar). Verify:

- RFC 7662 appears in the list
- Status shows "configured" (green)
- Module shows "simple_oauth_server_metadata"
- Recommendation text is displayed

### Step 5: Verify No Breaking Changes

Ensure existing functionality is preserved:

1. Other metadata fields still present (token_endpoint, authorization_endpoint, etc.)
2. Other RFC statuses unchanged in compliance dashboard
3. No errors in Drupal logs after cache clear

### Expected Server Metadata Response

```json
{
  "issuer": "https://example.com",
  "authorization_endpoint": "https://example.com/oauth/authorize",
  "token_endpoint": "https://example.com/oauth/token",
  "introspection_endpoint": "https://example.com/oauth/introspect",
  "registration_endpoint": "https://example.com/oauth/register",
  "grant_types_supported": ["authorization_code", "refresh_token", ...],
  "response_types_supported": ["code", "token"],
  "token_endpoint_auth_methods_supported": ["client_secret_basic"],
  ...
}
```

### Troubleshooting

**Issue**: introspection_endpoint not appearing in metadata

- Verify route exists: `vendor/bin/drush route:debug simple_oauth_server_metadata.token_introspection`
- Check for PHP errors in logs: `vendor/bin/drush watchdog:show`
- Ensure cache is cleared: `vendor/bin/drush cache:rebuild`
- Verify RouteProviderInterface is properly injected

**Issue**: RFC 7662 not appearing in compliance dashboard

- Check compliance service is loaded: `vendor/bin/drush config:get simple_oauth_21.settings`
- Verify no PHP errors: `vendor/bin/drush watchdog:show --severity=Error`
- Ensure correct array key structure matches other RFCs

</details>
