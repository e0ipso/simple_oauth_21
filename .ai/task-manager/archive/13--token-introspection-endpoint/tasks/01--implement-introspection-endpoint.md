---
id: 1
group: 'token-introspection'
dependencies: []
status: 'completed'
created: '2025-10-22'
skills:
  - drupal-backend
  - php
---

# Implement Token Introspection Endpoint

## Objective

Create RFC 7662 compliant token introspection endpoint with controller, route, permission system, and authorization logic within the `simple_oauth_server_metadata` module.

## Skills Required

- **drupal-backend**: Drupal controller patterns, routing, entity API, dependency injection, service architecture
- **php**: PHP 8.3+ with strict typing, PHPDoc standards, OAuth security best practices

## Acceptance Criteria

- [ ] TokenIntrospectionController created in `modules/simple_oauth_server_metadata/src/Controller/`
- [ ] Route defined in `simple_oauth_server_metadata.routing.yml` with OAuth 2.0 authentication
- [ ] Permission "bypass token introspection restrictions" defined in `simple_oauth_server_metadata.permissions.yml`
- [ ] Controller handles POST requests to `/oauth/introspect` with `token` and `token_type_hint` parameters
- [ ] Authorization enforced: token owner OR bypass permission required
- [ ] RFC 7662 compliant responses with required `active` field and optional fields
- [ ] Consistent error responses prevent information disclosure
- [ ] Code follows Drupal standards with `declare(strict_types=1)`, typed properties, comprehensive PHPDoc

Use your internal Todo tool to track these and keep on track.

## Technical Requirements

### Controller Implementation

- **Location**: `modules/simple_oauth_server_metadata/src/Controller/TokenIntrospectionController.php`
- **Dependencies**: Inject EntityTypeManager, CurrentUser, RequestStack via dependency injection
- **Method**: `introspect()` returns JsonResponse
- **Request parsing**: Support both `application/x-www-form-urlencoded` and `application/json`
- **Token lookup**: Query oauth2_token entities by token value, support access and refresh tokens
- **Authorization**: Check `$current_user->id() === $token->getOwnerId()` OR `$current_user->hasPermission('bypass token introspection restrictions')`
- **Token validation**: Check expiration, revocation status
- **Response fields**:
  - Required: `active` (boolean)
  - Optional: `scope`, `client_id`, `username`, `token_type`, `exp`, `iat`, `nbf`, `sub`, `aud`, `iss`, `jti`
- **Error handling**: Return `{"active": false}` for unauthorized/invalid/expired/non-existent tokens, HTTP 400 for missing `token` parameter

### Route Configuration

- **File**: `modules/simple_oauth_server_metadata/simple_oauth_server_metadata.routing.yml`
- **Route definition**:

```yaml
simple_oauth_server_metadata.token_introspection:
  path: '/oauth/introspect'
  defaults:
    _controller: '\Drupal\simple_oauth_server_metadata\Controller\TokenIntrospectionController::introspect'
    _title: 'OAuth 2.0 Token Introspection'
  methods: [POST]
  requirements:
    _role: 'authenticated'
  options:
    _auth: ['oauth2']
    _format: 'json'
    no_cache: TRUE
```

### Permission System

- **File**: `modules/simple_oauth_server_metadata/simple_oauth_server_metadata.permissions.yml`
- **Permission definition**:

```yaml
bypass token introspection restrictions:
  title: 'Bypass token introspection authorization restrictions'
  description: 'Allow introspection of any token regardless of ownership. This is a sensitive permission.'
  restrict access: TRUE
```

## Input Dependencies

None - this is the foundation task.

## Output Artifacts

- `modules/simple_oauth_server_metadata/src/Controller/TokenIntrospectionController.php`
- Updated `modules/simple_oauth_server_metadata/simple_oauth_server_metadata.routing.yml`
- Created/Updated `modules/simple_oauth_server_metadata/simple_oauth_server_metadata.permissions.yml`
- Functional `/oauth/introspect` endpoint

## Implementation Notes

<details>
<summary>Detailed Implementation Guide</summary>

### Step 1: Examine Existing Patterns

Before writing code, examine these files to understand existing patterns:

- `modules/simple_oauth_server_metadata/src/Controller/ServerMetadataController.php` - Controller pattern reference
- `modules/simple_oauth_server_metadata/simple_oauth_server_metadata.routing.yml` - Routing conventions
- Base simple_oauth module's debug endpoint for token lookup patterns

### Step 2: Create TokenIntrospectionController

**File**: `modules/simple_oauth_server_metadata/src/Controller/TokenIntrospectionController.php`

**Required structure**:

```php
<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Token Introspection controller for RFC 7662 compliance.
 *
 * Provides an endpoint for authorized clients to query metadata about
 * OAuth 2.0 tokens in a standardized format as defined by RFC 7662.
 */
final class TokenIntrospectionController extends ControllerBase {
  // Inject: EntityTypeManagerInterface, AccountProxyInterface, RequestStack
  // Implement: create(), __construct(), introspect()
}
```

**Key implementation details**:

1. **Request parsing**: Extract `token` (required) and `token_type_hint` (optional) from request body
2. **Token lookup**: Use EntityTypeManager to query oauth2_token entities by token value
3. **Authorization**: Implement two-check authorization:
   - First check: Is authenticated user the token owner?
   - Second check: Does authenticated user have bypass permission?
   - If both false: Return `{"active": false}` immediately (don't reveal token existence)
4. **Token validation**: Check expiration (`expires` field < current time), revocation (`revoked` field)
5. **Response construction**: Build RFC 7662 compliant JSON response with all available fields

**Security critical**:

- Never expose actual token values in responses
- Return identical `{"active": false}` for non-existent AND unauthorized tokens
- Use consistent response times to prevent timing attacks
- Handle NULL user IDs gracefully for client credentials tokens

### Step 3: Add Route Definition

**File**: `modules/simple_oauth_server_metadata/simple_oauth_server_metadata.routing.yml`

Add the route configuration as specified in Technical Requirements. Ensure:

- OAuth 2.0 authentication is enforced via `_auth: ['oauth2']`
- Caching is disabled with `no_cache: TRUE`
- Only POST method is allowed

### Step 4: Define Permission

**File**: `modules/simple_oauth_server_metadata/simple_oauth_server_metadata.permissions.yml`

If this file doesn't exist, create it. Add the permission as specified in Technical Requirements.

The `restrict access: TRUE` flag ensures Drupal treats this as a sensitive permission in the admin UI.

### Step 5: Clear Cache

After creating/modifying route and permission files, clear Drupal cache:

```bash
vendor/bin/drush cache:rebuild
```

### Step 6: Validation

Verify the implementation:

1. Check route exists: `vendor/bin/drush route:debug simple_oauth_server_metadata.token_introspection`
2. Manual test with curl (requires valid OAuth token):

```bash
curl -X POST https://example.com/oauth/introspect \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "token=TOKEN_TO_INTROSPECT"
```

### RFC 7662 Response Format Reference

**Active token response**:

```json
{
  "active": true,
  "scope": "authenticated",
  "client_id": "client-uuid",
  "username": "user@example.com",
  "token_type": "Bearer",
  "exp": 1735689600,
  "iat": 1735603200,
  "sub": "user-uuid",
  "aud": "client-uuid",
  "iss": "https://example.com",
  "jti": "token-uuid"
}
```

**Inactive token response** (expired/revoked/non-existent/unauthorized):

```json
{
  "active": false
}
```

### Coding Standards Checklist

- [ ] `declare(strict_types=1);` at top of PHP file
- [ ] `final` keyword on class (unless inheritance needed)
- [ ] All properties have typed declarations
- [ ] Comprehensive PHPDoc on class and all public methods
- [ ] Dependency injection used (no `\Drupal::` static calls in controller)
- [ ] Follows PSR-4 autoloading conventions
- [ ] Run `vendor/bin/phpcs` and `vendor/bin/phpstan` to verify standards

</details>
