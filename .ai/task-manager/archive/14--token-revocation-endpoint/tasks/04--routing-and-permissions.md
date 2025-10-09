---
id: 4
group: 'configuration'
dependencies: [3]
status: 'completed'
created: 2025-10-09
skills:
  - drupal-backend
---

# Routing and Permissions Configuration

## Objective

Define the `/oauth/revoke` route, create the bypass permission for administrative token revocation, and ensure proper access control configuration.

## Skills Required

- **drupal-backend**: Configure Drupal routing, permissions, and access control policies

## Acceptance Criteria

- [ ] Route defined at `/oauth/revoke` path in `simple_oauth_server_metadata.routing.yml`
- [ ] Route accepts POST method only
- [ ] Route has public access (`_access: 'TRUE'`) since authentication happens in controller
- [ ] Route references `TokenRevocationController::revoke` method
- [ ] Permission created: `bypass token revocation restrictions`
- [ ] Permission has `restrict access: TRUE` flag for security
- [ ] Permission file follows Drupal permissions schema
- [ ] Route follows existing naming convention: `simple_oauth_server_metadata.revoke`
- [ ] Configuration validates with Drupal's schema validation

## Technical Requirements

**Files to Modify:**

1. `simple_oauth_server_metadata/simple_oauth_server_metadata.routing.yml`
2. `simple_oauth_server_metadata/simple_oauth_server_metadata.permissions.yml` (create new file)

**Route Specification:**

- Path: `/oauth/revoke`
- Methods: POST only
- Access: Public (TRUE) - authentication handled in controller
- Controller: `Drupal\simple_oauth_server_metadata\Controller\TokenRevocationController::revoke`

**Permission Specification:**

- Machine name: `bypass token revocation restrictions`
- Title: "Bypass token revocation restrictions"
- Description: Clear explanation for site administrators
- Restrict access: TRUE (prevents accidental assignment)

## Input Dependencies

**From Task 3:**

- `TokenRevocationController` class must exist for route reference

## Output Artifacts

- Updated `simple_oauth_server_metadata.routing.yml` with revocation route
- New `simple_oauth_server_metadata.permissions.yml` with bypass permission

These configurations will be used by:

- Task 5 (Server metadata service for endpoint URL generation)
- Task 6 (Functional tests validating route access and permissions)

## Implementation Notes

<details>
<summary>Detailed Implementation Guide</summary>

### Routing Configuration

Add to `simple_oauth_server_metadata/simple_oauth_server_metadata.routing.yml`:

```yaml
simple_oauth_server_metadata.revoke:
  path: '/oauth/revoke'
  defaults:
    _controller: 'Drupal\simple_oauth_server_metadata\Controller\TokenRevocationController::revoke'
    _title: 'OAuth 2.0 Token Revocation'
  methods: [POST]
  requirements:
    _access: 'TRUE'
```

**Key Configuration Points:**

1. **Route name:** Follow convention `simple_oauth_server_metadata.{action}`
2. **Path:** RFC 7009 doesn't mandate specific path, but `/oauth/revoke` is conventional
3. **Methods:** POST only (RFC 7009 requirement)
4. **Access:** `'TRUE'` because authentication is handled in the controller via ClientAuthenticationService
5. **Title:** Used in system logs and admin interfaces

### Why Public Access?

The route has `_access: 'TRUE'` because:

- OAuth client authentication happens within the controller (not Drupal user authentication)
- Clients authenticate via HTTP Basic Auth or POST body credentials
- Drupal's route access system would interfere with OAuth authentication flow
- The controller handles all authentication and authorization logic

### Permissions Configuration

Create `simple_oauth_server_metadata/simple_oauth_server_metadata.permissions.yml`:

```yaml
bypass token revocation restrictions:
  title: 'Bypass token revocation restrictions'
  description: 'Allows revoking any OAuth token regardless of ownership. This is an administrative permission that should only be granted to trusted roles.'
  restrict access: TRUE
```

**Permission Design:**

1. **Machine name:** Descriptive, follows Drupal conventions
2. **Title:** Human-readable for permissions UI
3. **Description:** Clearly explains the security implications
4. **restrict access:** Prevents accidental assignment, shows warning in UI

### Permission Usage

The controller uses this permission to determine if ownership validation should be bypassed:

```php
$bypassOwnership = $this->currentUser->hasPermission('bypass token revocation restrictions');
$this->tokenRevocation->revokeToken($token, $clientId, $bypassOwnership);
```

**Use cases for bypass permission:**

- Site administrators managing OAuth tokens
- Cleanup scripts for revoked users
- Security incident response (revoking compromised tokens)
- Testing and debugging in development environments

### Drupal Permission Best Practices

- **Never use "administer" prefix** unless it's actually a site-wide admin permission
- **Use "bypass" prefix** for permissions that circumvent normal access checks
- **Set restrict access** for sensitive permissions that could impact security
- **Provide clear description** so site builders understand the implications

### Route Validation

After implementing, validate with:

```bash
# Clear cache to register new route
vendor/bin/drush cache:rebuild

# Check route exists
vendor/bin/drush route:list | grep revoke

# Check permission exists
vendor/bin/drush config:get user.role.administrator permissions
```

### Testing Route Configuration

Test route is accessible:

```bash
# Should return 400 (missing token parameter) or 401 (missing client auth)
curl -X POST https://your-site.com/oauth/revoke

# Should return 400 (missing token)
curl -X POST https://your-site.com/oauth/revoke \
  -u "client_id:client_secret"
```

### Integration with Existing Routes

Check existing routes in `simple_oauth_server_metadata.routing.yml` to ensure consistency:

- Follow the same structure and formatting
- Use similar naming patterns
- Match access control patterns for other OAuth endpoints

### Common Issues to Avoid

1. **Don't use `_permission` requirement** - This checks Drupal user permissions, not OAuth client credentials
2. **Don't restrict by role** - OAuth clients aren't Drupal users
3. **Don't use CSRF protection** - OAuth uses different security mechanisms
4. **Don't cache responses** - Token revocation must be immediate

### Documentation

The permission should be documented in the module's README or help text explaining:

- When to grant this permission
- Security implications
- Typical roles that need it (e.g., "Site Administrator")

</details>
