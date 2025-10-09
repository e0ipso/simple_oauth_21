---
id: 2
group: 'revocation'
dependencies: []
status: 'completed'
created: 2025-10-09
skills:
  - php
  - drupal-backend
---

# Token Revocation Service

## Objective

Create a service that encapsulates token lookup, ownership validation, and revocation operations, providing a clean abstraction over the existing `RevocableTokenRepositoryTrait` for both access tokens and refresh tokens.

## Skills Required

- **php**: Implement token lookup and validation logic
- **drupal-backend**: Work with Drupal entity API, token repositories, and service patterns

## Acceptance Criteria

- [ ] Service implements `revokeToken(string $tokenValue, string $clientId, bool $bypassOwnership = FALSE): bool` method
- [ ] Supports both access tokens and refresh tokens
- [ ] Performs token lookup using hashed token value
- [ ] Validates token ownership (client_id match) unless bypass is enabled
- [ ] Calls existing `revoke()` method from `RevocableTokenRepositoryTrait`
- [ ] Returns true if token successfully revoked (or already revoked)
- [ ] Returns false if token not found or ownership validation fails
- [ ] Handles edge cases (null tokens, empty strings, invalid formats)
- [ ] Follows Drupal coding standards with `declare(strict_types=1);` and `final class`
- [ ] Includes comprehensive PHPDoc comments

## Technical Requirements

**File Location:** `simple_oauth_server_metadata/src/Service/TokenRevocationService.php`

**Service Definition:** Add to `simple_oauth_server_metadata.services.yml`:

```yaml
simple_oauth_server_metadata.token_revocation:
  class: Drupal\simple_oauth_server_metadata\Service\TokenRevocationService
  arguments:
    - '@entity_type.manager'
```

**Dependencies:**

- `EntityTypeManagerInterface` for accessing token storage
- Token entity types: `oauth2_token` (access tokens) and `oauth2_refresh_token` (refresh tokens)
- Existing `RevocableTokenRepositoryTrait::revoke()` method

**Key Functionality:**

1. Hash the provided token value to match database storage format
2. Query both access token and refresh token repositories
3. Validate token belongs to specified client_id (unless bypass)
4. Call `revoke()` method on found token entity
5. Return success/failure status

## Input Dependencies

None - This service has no task dependencies but relies on existing Simple OAuth infrastructure.

## Output Artifacts

- `simple_oauth_server_metadata/src/Service/TokenRevocationService.php`
- Service definition in `simple_oauth_server_metadata.services.yml`

This service will be consumed by:

- Task 3 (Token Revocation Controller)

## Implementation Notes

<details>
<summary>Detailed Implementation Guide</summary>

### Class Structure

```php
<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service for revoking OAuth tokens.
 *
 * Provides token lookup, ownership validation, and revocation operations
 * for both access tokens and refresh tokens.
 */
final class TokenRevocationService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Revokes a token if valid and owned by the specified client.
   *
   * @param string $tokenValue
   *   The token value to revoke.
   * @param string $clientId
   *   The client ID that owns the token.
   * @param bool $bypassOwnership
   *   If TRUE, skip ownership validation (for admin bypass permission).
   *
   * @return bool
   *   TRUE if token was revoked, FALSE otherwise.
   */
  public function revokeToken(string $tokenValue, string $clientId, bool $bypassOwnership = FALSE): bool {
    // Implementation here
  }

  // Private helper methods
}
```

### Token Lookup Strategy

Tokens are stored hashed in the database. You need to:

1. Hash the incoming token value (same algorithm used during creation)
2. Search both access token and refresh token entity types
3. Return the first matching token found

```php
// Hash the token (check simple_oauth for exact hashing implementation)
// Likely uses password_hash or similar
$hashedValue = $this->hashTokenValue($tokenValue);

// Try access tokens first
$accessTokenStorage = $this->entityTypeManager->getStorage('oauth2_token');
$tokens = $accessTokenStorage->loadByProperties(['value' => $hashedValue]);

if (empty($tokens)) {
  // Try refresh tokens
  $refreshTokenStorage = $this->entityTypeManager->getStorage('oauth2_refresh_token');
  $tokens = $refreshTokenStorage->loadByProperties(['value' => $hashedValue]);
}
```

### Ownership Validation

Check if the token's client entity matches the provided client_id:

```php
if (!$bypassOwnership) {
  $tokenClient = $token->get('client')->entity;
  if (!$tokenClient || $tokenClient->uuid() !== $clientId) {
    // Client doesn't own this token
    return FALSE;
  }
}
```

**Note:** Verify whether client matching uses UUID, client_id field, or entity ID. Check existing Simple OAuth code for the correct approach.

### Revocation Execution

Tokens using `RevocableTokenRepositoryTrait` have a `revoke()` method:

```php
// Check if token is already revoked (idempotent operation)
if ($token->isRevoked()) {
  return TRUE; // Already revoked, report success
}

// Revoke the token
$token->revoke();
$token->save();
return TRUE;
```

### Edge Cases to Handle

1. **Empty token value:** Return false immediately
2. **Token not found:** Return true per RFC 7009 (don't reveal token existence)
3. **Already revoked token:** Return true (idempotent operation)
4. **Ownership mismatch:** Return false (unless bypass enabled)
5. **Invalid token format:** Return true (treat as non-existent)

### RFC 7009 Privacy Consideration

Per RFC 7009, the endpoint SHOULD respond with success even if the token doesn't exist or is invalid. This prevents token enumeration attacks. Therefore:

```php
// Token not found - still return TRUE to not reveal token existence
if (empty($tokens)) {
  return TRUE;
}
```

### Testing Considerations

Create test scenarios for:

- Valid access token revocation
- Valid refresh token revocation
- Token ownership validation
- Bypass ownership flag
- Already-revoked tokens
- Non-existent tokens
- Tokens owned by different clients

### Integration Notes

Check the actual Simple OAuth implementation for:

- Exact token hashing algorithm (may be in token repository or entity class)
- Client relationship field name on token entities
- Method names on token entities (`revoke()`, `isRevoked()`, etc.)

Refer to:

- `/var/www/html/web/modules/contrib/simple_oauth/src/Repositories/RevocableTokenRepositoryTrait.php:87-121`
- Token entity classes for field structure

</details>
