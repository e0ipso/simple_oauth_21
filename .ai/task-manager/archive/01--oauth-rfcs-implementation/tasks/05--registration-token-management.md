---
id: 5
group: 'rfc-7591-client-registration'
dependencies: [1]
status: 'pending'
created: '2025-09-16'
skills: ['oauth-protocols', 'drupal-backend']
complexity_score: 5.0
---

# Registration Token Management

## Objective

Implement secure registration access token generation and management using Simple OAuth's existing League OAuth2 Server library, following established token generation patterns for consistency and security.

## Skills Required

- **oauth-protocols**: OAuth 2.0 token generation, RFC 7591 specification
- **drupal-backend**: Service integration, League OAuth2 Server usage

## Acceptance Criteria

- [ ] Registration access token generation using League OAuth2 Server
- [ ] Token storage mechanism for client management endpoints
- [ ] Token validation service for registration updates/deletion
- [ ] Client management endpoints: GET, PUT, DELETE `/oauth/register/{client_id}`
- [ ] Proper token expiration and cleanup

## Technical Requirements

**Token Requirements:**

- Cryptographically secure using League OAuth2 Server
- Unique per registered client
- Used for client metadata management operations
- Proper expiration handling

**Management Endpoints:**

- `GET /oauth/register/{client_id}` - Retrieve client metadata
- `PUT /oauth/register/{client_id}` - Update client metadata
- `DELETE /oauth/register/{client_id}` - Delete client registration

## Input Dependencies

- Task 1: Module structure and routing

## Output Artifacts

- Token generation service using League OAuth2 Server
- Client management endpoints for RFC 7591 compliance

## Implementation Notes

<details>
<summary>Detailed Implementation Instructions</summary>

Leverage Simple OAuth's existing League OAuth2 Server (v9.2.0) infrastructure:

**Token Generation Service:**

```php
<?php

namespace Drupal\simple_oauth_client_registration\Service;

class RegistrationTokenService {

  protected $authorizationServer;

  public function __construct($authorization_server) {
    // Inject Simple OAuth's existing authorization server
    $this->authorizationServer = $authorization_server;
  }

  public function generateRegistrationAccessToken($clientId): string {
    // Use League library's token generation
    // Follow Simple OAuth's existing token patterns
  }
}
```

**Token Storage:**

- Store tokens in a dedicated table or use existing OAuth token infrastructure
- Link tokens to Consumer entity IDs
- Include expiration timestamps

**Management Endpoints Routing:**

```yaml
simple_oauth_client_registration.manage:
  path: '/oauth/register/{client_id}'
  defaults:
    _controller: 'ClientRegistrationController::manage'
  methods: [GET, PUT, DELETE]
  requirements:
    _access: 'TRUE'
```

**Controller Methods:**

- `manage(Request $request, $client_id)` - Route to appropriate method based on HTTP verb
- Validate registration_access_token from Authorization header
- Follow RFC 7591 Section 3 for client configuration endpoint

**Token Validation:**

- Extract `Bearer {registration_access_token}` from Authorization header
- Validate token against stored tokens for the client_id
- Return appropriate 401/403 errors for invalid tokens

Use Simple OAuth's existing League OAuth2 Server patterns for all token operations to ensure consistency and security.

</details>
