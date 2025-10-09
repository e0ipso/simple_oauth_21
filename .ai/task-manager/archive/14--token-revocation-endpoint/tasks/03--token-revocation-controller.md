---
id: 3
group: 'revocation'
dependencies: [1, 2]
status: 'completed'
created: 2025-10-09
skills:
  - php
  - drupal-backend
---

# Token Revocation Controller

## Objective

Create the HTTP endpoint controller that handles POST requests to `/oauth/revoke`, orchestrates client authentication and token revocation using the authentication and revocation services, and returns RFC 7009-compliant responses.

## Skills Required

- **php**: Implement HTTP request/response handling with proper error codes
- **drupal-backend**: Use Drupal controllers, dependency injection, PSR-7 requests, and permission checks

## Acceptance Criteria

- [ ] Controller implements `revoke()` method handling POST requests
- [ ] Validates required `token` parameter from request body
- [ ] Supports optional `token_type_hint` parameter (access_token, refresh_token)
- [ ] Uses ClientAuthenticationService to authenticate requesting client
- [ ] Checks if user has 'bypass token revocation restrictions' permission
- [ ] Uses TokenRevocationService to revoke tokens with appropriate ownership settings
- [ ] Returns HTTP 200 with empty body on successful revocation
- [ ] Returns HTTP 400 for missing `token` parameter
- [ ] Returns HTTP 401 for failed client authentication
- [ ] Logs revocation attempts using `logger.channel.simple_oauth`
- [ ] Implements RFC 7009 privacy (returns 200 even for non-existent tokens)
- [ ] Follows Drupal coding standards with `declare(strict_types=1);` and `final class`
- [ ] Includes comprehensive PHPDoc comments

## Technical Requirements

**File Location:** `simple_oauth_server_metadata/src/Controller/TokenRevocationController.php`

**Class Structure:** Extend `ControllerBase`

**Dependencies (via constructor injection):**

- `ClientAuthenticationService` (from Task 1)
- `TokenRevocationService` (from Task 2)
- `CurrentUserInterface` (for permission checking)
- `Psr7Bridge` (for converting Symfony Request to PSR-7)
- `LoggerInterface` (logger.channel.simple_oauth)

**HTTP Method:** POST only (will be enforced by routing configuration)

**Request Parameters (POST body):**

- `token` (required) - The token to revoke
- `token_type_hint` (optional) - "access_token" or "refresh_token"
- Client credentials (handled by authentication service)

**Response Codes:**

- `200 OK` - Token revoked successfully (or token doesn't exist - privacy)
- `400 Bad Request` - Missing required `token` parameter
- `401 Unauthorized` - Client authentication failed

## Input Dependencies

**From Task 1:**

- `ClientAuthenticationService` with `authenticateClient()` method

**From Task 2:**

- `TokenRevocationService` with `revokeToken()` method

## Output Artifacts

- `simple_oauth_server_metadata/src/Controller/TokenRevocationController.php`

This controller will be referenced by:

- Task 4 (Routing configuration)
- Task 6 (Functional tests)

## Implementation Notes

<details>
<summary>Detailed Implementation Guide</summary>

### Class Structure

```php
<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_oauth_server_metadata\Service\ClientAuthenticationService;
use Drupal\simple_oauth_server_metadata\Service\TokenRevocationService;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for OAuth 2.0 token revocation endpoint (RFC 7009).
 */
final class TokenRevocationController extends ControllerBase {

  public function __construct(
    private readonly ClientAuthenticationService $clientAuthentication,
    private readonly TokenRevocationService $tokenRevocation,
    private readonly AccountProxyInterface $currentUser,
    private readonly HttpMessageFactoryInterface $httpMessageFactory,
    private readonly LoggerInterface $logger,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('simple_oauth_server_metadata.client_authentication'),
      $container->get('simple_oauth_server_metadata.token_revocation'),
      $container->get('current_user'),
      $container->get('psr7.http_message_factory'),
      $container->get('logger.channel.simple_oauth'),
    );
  }

  /**
   * Handles token revocation requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The HTTP response.
   */
  public function revoke(Request $request): Response {
    // Implementation here
  }
}
```

### Request Processing Flow

```php
public function revoke(Request $request): Response {
  // 1. Convert to PSR-7 request for authentication service
  $psrRequest = $this->httpMessageFactory->createRequest($request);

  // 2. Authenticate the client
  $client = $this->clientAuthentication->authenticateClient($psrRequest);
  if (!$client) {
    $this->logger->warning('Token revocation failed: client authentication failed');
    return new JsonResponse(
      ['error' => 'invalid_client'],
      Response::HTTP_UNAUTHORIZED
    );
  }

  // 3. Extract token parameter (required)
  $token = $request->request->get('token');
  if (empty($token)) {
    return new JsonResponse(
      ['error' => 'invalid_request', 'error_description' => 'Missing token parameter'],
      Response::HTTP_BAD_REQUEST
    );
  }

  // 4. Optional: token_type_hint (can be ignored for this implementation)
  $tokenTypeHint = $request->request->get('token_type_hint');

  // 5. Check if user has bypass permission
  $bypassOwnership = $this->currentUser->hasPermission('bypass token revocation restrictions');

  // 6. Revoke the token
  $clientId = $client->uuid(); // Or client->getClientId() - verify with actual Consumer entity
  $success = $this->tokenRevocation->revokeToken($token, $clientId, $bypassOwnership);

  // 7. Log the revocation attempt
  $this->logger->info('Token revocation request by client @client, bypass: @bypass', [
    '@client' => $clientId,
    '@bypass' => $bypassOwnership ? 'yes' : 'no',
  ]);

  // 8. Always return 200 (RFC 7009 privacy - don't reveal token existence)
  return new Response('', Response::HTTP_OK);
}
```

### RFC 7009 Compliance Notes

**Section 2.1 - Revocation Request:**

- MUST accept `token` parameter (required)
- MAY accept `token_type_hint` parameter (optional)
- MUST require client authentication

**Section 2.2 - Revocation Response:**

- SHOULD respond with HTTP 200 whether token was valid or not
- Empty response body is acceptable
- MUST NOT reveal whether token existed (privacy consideration)

**Error Responses:**

- `invalid_request` (400) - Malformed request (e.g., missing token parameter)
- `invalid_client` (401) - Client authentication failed
- `unsupported_token_type` (400) - Optional, for token_type_hint validation

### Permission Checking

The bypass permission allows Drupal administrators to revoke any token:

```php
$bypassOwnership = $this->currentUser->hasPermission('bypass token revocation restrictions');
```

When `$bypassOwnership = TRUE`, the TokenRevocationService will skip ownership validation.

### Logging Strategy

Log revocation attempts for audit purposes:

```php
// Success
$this->logger->info('Token revocation request by client @client', [
  '@client' => $clientId,
]);

// Authentication failure
$this->logger->warning('Token revocation failed: client authentication failed');
```

**NEVER log the token value itself** - tokens are secrets.

### Error Handling

```php
try {
  // Revocation logic
} catch (\Exception $e) {
  $this->logger->error('Token revocation error: @message', [
    '@message' => $e->getMessage(),
  ]);
  return new JsonResponse(
    ['error' => 'server_error'],
    Response::HTTP_INTERNAL_SERVER_ERROR
  );
}
```

### Testing Considerations

This controller should be testable through:

1. **Unit tests** - Mock dependencies and test request processing logic
2. **Kernel tests** - Test with real services but minimal Drupal bootstrap
3. **Functional tests** - Full HTTP request/response cycle (Task 6)

### Integration Points

- **Service dependencies:** Tasks 1 and 2 MUST be completed first
- **Routing:** Task 4 will define the route to this controller
- **Permissions:** Task 4 will define the bypass permission
- **Metadata:** Task 5 will advertise this endpoint in server metadata

### Security Checklist

- ✅ Client authentication required for all requests
- ✅ Token ownership validated (unless bypass permission)
- ✅ Never log token values or client secrets
- ✅ Generic error messages (don't reveal internal state)
- ✅ Privacy-preserving responses (200 for non-existent tokens)

</details>
