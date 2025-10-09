---
id: 1
group: 'authentication'
dependencies: []
status: 'completed'
created: 2025-10-09
skills:
  - php
  - drupal-backend
---

# Client Authentication Service

## Objective

Create a reusable service that validates OAuth client credentials from HTTP requests, supporting both Basic Authentication and POST body credentials for confidential and public clients.

## Skills Required

- **php**: Implement secure credential validation with constant-time comparison
- **drupal-backend**: Use Drupal service container, dependency injection, and OAuth client repositories

## Acceptance Criteria

- [ ] Service implements `authenticateClient(ServerRequestInterface $request): ?ConsumerInterface` method
- [ ] Supports HTTP Basic Auth header credential extraction (RFC 6749 Section 2.3.1)
- [ ] Supports POST body credentials (`client_id`, `client_secret` parameters)
- [ ] Handles public clients (client_id only, no secret required)
- [ ] Uses constant-time comparison for secret validation (prevents timing attacks)
- [ ] Returns ConsumerInterface on success, null on failure
- [ ] Validates client is not revoked or disabled
- [ ] Never logs or exposes client secrets in error messages
- [ ] Follows Drupal coding standards with `declare(strict_types=1);` and `final class`
- [ ] Includes comprehensive PHPDoc comments

## Technical Requirements

**File Location:** `simple_oauth_server_metadata/src/Service/ClientAuthenticationService.php`

**Service Definition:** Add to `simple_oauth_server_metadata.services.yml`:

```yaml
simple_oauth_server_metadata.client_authentication:
  class: Drupal\simple_oauth_server_metadata\Service\ClientAuthenticationService
  arguments:
    - '@simple_oauth.repositories.client'
```

**Dependencies:**

- `ClientRepositoryInterface` from `simple_oauth.repositories.client` service
- PSR-7 `ServerRequestInterface` for request handling
- Drupal `Crypt::hashEquals()` or PHP `hash_equals()` for secret comparison

**Key Methods:**

1. `authenticateClient(ServerRequestInterface $request): ?ConsumerInterface` - Main authentication method
2. Private helper methods for credential extraction (Basic Auth vs POST body)

**Security Requirements:**

- Use `hash_equals()` or `Crypt::hashEquals()` for all secret comparisons
- Never log client secrets
- Generic error messages (don't reveal if client exists)
- Validate client status before accepting credentials

## Input Dependencies

None - This is a foundational service with no task dependencies.

## Output Artifacts

- `simple_oauth_server_metadata/src/Service/ClientAuthenticationService.php`
- Service definition in `simple_oauth_server_metadata.services.yml`

This service will be consumed by:

- Task 3 (Token Revocation Controller)
- Future token introspection endpoint (out of scope for this plan)

## Implementation Notes

<details>
<summary>Detailed Implementation Guide</summary>

### Class Structure

```php
<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\simple_oauth\Repositories\ClientRepositoryInterface;
use Drupal\consumer\Entity\ConsumerInterface;
use Drupal\Component\Utility\Crypt;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Service for authenticating OAuth clients.
 *
 * Validates client credentials from HTTP requests supporting both
 * HTTP Basic Authentication and POST body credentials per RFC 6749.
 */
final class ClientAuthenticationService {

  public function __construct(
    private readonly ClientRepositoryInterface $clientRepository,
  ) {}

  /**
   * Authenticates an OAuth client from the request.
   *
   * @param \Psr\Http\Message\ServerRequestInterface $request
   *   The HTTP request containing client credentials.
   *
   * @return \Drupal\consumer\Entity\ConsumerInterface|null
   *   The authenticated client entity, or NULL if authentication fails.
   */
  public function authenticateClient(ServerRequestInterface $request): ?ConsumerInterface {
    // Implementation here
  }

  // Private helper methods
}
```

### Authentication Flow

1. **Extract credentials** - Try Basic Auth header first, then POST body
2. **Lookup client** - Use `ClientRepositoryInterface::getClientEntity($clientId)`
3. **Validate secret** - For confidential clients, use `Crypt::hashEquals()` to compare
4. **Check status** - Ensure client is not revoked/disabled
5. **Return result** - ConsumerInterface on success, null on failure

### Basic Auth Extraction

```php
// Get Authorization header
$authHeader = $request->getHeaderLine('Authorization');
if (str_starts_with($authHeader, 'Basic ')) {
  $credentials = base64_decode(substr($authHeader, 6));
  [$clientId, $clientSecret] = explode(':', $credentials, 2);
}
```

### POST Body Extraction

```php
$body = $request->getParsedBody();
$clientId = $body['client_id'] ?? null;
$clientSecret = $body['client_secret'] ?? null;
```

### Secret Validation (Constant-Time)

```php
// NEVER use === or == for secret comparison (timing attack risk)
if ($expectedSecret && $providedSecret) {
  if (!Crypt::hashEquals($expectedSecret, $providedSecret)) {
    return null;
  }
}
```

### Public Client Handling

Public clients (mobile apps, SPAs) don't have secrets. Accept client_id only:

```php
// If client is public and no secret provided, that's valid
if (!$client->isConfidential() && empty($providedSecret)) {
  return $client;
}
```

### Security Checklist

- ✅ Never log `$clientSecret` variable
- ✅ Use constant-time comparison for secrets
- ✅ Generic error messages (don't reveal if client exists)
- ✅ Validate client is active/not revoked
- ✅ Handle edge cases (empty strings, null values, malformed headers)

### Error Handling

Return `null` for all authentication failures. Let the controller determine appropriate HTTP response codes.

### Testing Considerations

This service should be testable with mocked `ClientRepositoryInterface`. Create test fixtures with known client credentials for validation testing.

</details>
