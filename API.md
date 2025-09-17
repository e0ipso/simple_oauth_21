# Simple OAuth 2.1 API Documentation

This document provides comprehensive API documentation for the OAuth 2.0 RFC compliance endpoints implemented by Simple OAuth 2.1 and its submodules.

## Authorization Server Metadata (RFC 8414)

### GET /.well-known/oauth-authorization-server

Retrieves authorization server metadata for client discovery and configuration.

**Purpose**: RFC 8414 compliant endpoint for authorization server discovery
**Authentication**: None required
**Content-Type**: application/json

#### Response Format

```json
{
  "issuer": "https://example.com",
  "authorization_endpoint": "https://example.com/oauth/authorize",
  "token_endpoint": "https://example.com/oauth/token",
  "registration_endpoint": "https://example.com/oauth/register",
  "jwks_uri": "https://example.com/oauth/jwks",
  "response_types_supported": ["code"],
  "grant_types_supported": ["authorization_code", "client_credentials"],
  "token_endpoint_auth_methods_supported": [
    "client_secret_basic",
    "client_secret_post"
  ],
  "code_challenge_methods_supported": ["S256"],
  "scopes_supported": ["read", "write"],
  "service_documentation": "https://example.com/docs/oauth"
}
```

#### Example Request

```bash
curl -X GET https://example.com/.well-known/oauth-authorization-server \
  -H "Accept: application/json"
```

#### Error Responses

- **404 Not Found**: Server metadata module not enabled
- **500 Internal Server Error**: Configuration error

## Protected Resource Metadata (RFC 9728)

### GET /.well-known/oauth-protected-resource

Retrieves protected resource metadata for resource server discovery.

**Purpose**: RFC 9728 compliant endpoint for resource discovery
**Authentication**: None required
**Content-Type**: application/json

#### Response Format

```json
{
  "resource": "https://example.com",
  "authorization_servers": ["https://example.com"],
  "scopes_supported": ["read", "write", "admin"],
  "bearer_methods_supported": ["header", "body", "query"],
  "resource_documentation": "https://example.com/docs/api"
}
```

#### Example Request

```bash
curl -X GET https://example.com/.well-known/oauth-protected-resource \
  -H "Accept: application/json"
```

## Dynamic Client Registration (RFC 7591)

### POST /oauth/register

Registers a new OAuth client dynamically.

**Purpose**: RFC 7591 compliant dynamic client registration
**Authentication**: Optional (configurable)
**Content-Type**: application/json

#### Request Format

```json
{
  "client_name": "My Application",
  "client_uri": "https://myapp.example.com",
  "logo_uri": "https://myapp.example.com/logo.png",
  "redirect_uris": ["https://myapp.example.com/callback", "myapp://callback"],
  "grant_types": ["authorization_code"],
  "response_types": ["code"],
  "scope": "read write",
  "contacts": ["admin@myapp.example.com"],
  "tos_uri": "https://myapp.example.com/terms",
  "policy_uri": "https://myapp.example.com/privacy",
  "jwks_uri": "https://myapp.example.com/jwks",
  "software_id": "550e8400-e29b-41d4-a716-446655440000",
  "software_version": "1.0.0"
}
```

#### Response Format

```json
{
  "client_id": "client_abc123",
  "client_secret": "secret_xyz789",
  "client_id_issued_at": 1640995200,
  "client_secret_expires_at": 0,
  "client_name": "My Application",
  "client_uri": "https://myapp.example.com",
  "logo_uri": "https://myapp.example.com/logo.png",
  "redirect_uris": ["https://myapp.example.com/callback", "myapp://callback"],
  "grant_types": ["authorization_code"],
  "response_types": ["code"],
  "scope": "read write",
  "contacts": ["admin@myapp.example.com"],
  "tos_uri": "https://myapp.example.com/terms",
  "policy_uri": "https://myapp.example.com/privacy",
  "registration_client_uri": "https://example.com/oauth/register/client_abc123",
  "registration_access_token": "reg_token_def456"
}
```

#### Example Request

```bash
curl -X POST https://example.com/oauth/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_name": "My Application",
    "redirect_uris": ["https://myapp.example.com/callback"],
    "grant_types": ["authorization_code"],
    "scope": "read write"
  }'
```

#### Error Responses

- **400 Bad Request**: Invalid client metadata
- **401 Unauthorized**: Authentication required but not provided
- **403 Forbidden**: Registration not permitted
- **422 Unprocessable Entity**: Invalid redirect URIs or other validation errors

### GET /oauth/register/{client_id}

Retrieves client configuration.

**Purpose**: RFC 7591 client configuration retrieval
**Authentication**: Registration access token required
**Content-Type**: application/json

#### Request Headers

```
Authorization: Bearer reg_token_def456
Accept: application/json
```

#### Response Format

Same as POST response format above.

#### Example Request

```bash
curl -X GET https://example.com/oauth/register/client_abc123 \
  -H "Authorization: Bearer reg_token_def456" \
  -H "Accept: application/json"
```

#### Error Responses

- **401 Unauthorized**: Invalid or missing registration access token
- **404 Not Found**: Client not found
- **403 Forbidden**: Access denied

### PUT /oauth/register/{client_id}

Updates client configuration.

**Purpose**: RFC 7591 client configuration update
**Authentication**: Registration access token required
**Content-Type**: application/json

#### Request Format

Same as POST request format. All fields are optional; only provided fields will be updated.

#### Response Format

Updated client configuration in same format as POST response.

#### Example Request

```bash
curl -X PUT https://example.com/oauth/register/client_abc123 \
  -H "Authorization: Bearer reg_token_def456" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_name": "My Updated Application",
    "scope": "read write admin"
  }'
```

#### Error Responses

- **400 Bad Request**: Invalid client metadata
- **401 Unauthorized**: Invalid or missing registration access token
- **404 Not Found**: Client not found
- **403 Forbidden**: Update not permitted
- **422 Unprocessable Entity**: Validation errors

### DELETE /oauth/register/{client_id}

Removes client registration.

**Purpose**: RFC 7591 client registration removal
**Authentication**: Registration access token required

#### Request Headers

```
Authorization: Bearer reg_token_def456
```

#### Response

- **204 No Content**: Client successfully deleted
- **401 Unauthorized**: Invalid or missing registration access token
- **404 Not Found**: Client not found
- **403 Forbidden**: Deletion not permitted

#### Example Request

```bash
curl -X DELETE https://example.com/oauth/register/client_abc123 \
  -H "Authorization: Bearer reg_token_def456"
```

## Error Response Format

All API endpoints use consistent error response format:

```json
{
  "error": "invalid_request",
  "error_description": "The request is missing a required parameter."
}
```

### Common Error Codes

- **invalid_request**: Malformed request
- **invalid_client_metadata**: Invalid client metadata values
- **invalid_redirect_uri**: Invalid redirect URI format or pattern
- **invalid_client_id**: Unknown client identifier
- **access_denied**: Operation not permitted
- **server_error**: Internal server error

## Integration Examples

### JavaScript Client Registration

```javascript
// Register a new OAuth client
async function registerClient() {
  const response = await fetch('/oauth/register', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify({
      client_name: 'My JavaScript App',
      redirect_uris: ['https://myapp.example.com/callback'],
      grant_types: ['authorization_code'],
      response_types: ['code'],
      scope: 'read write',
    }),
  });

  if (response.ok) {
    const client = await response.json();
    console.log('Client registered:', client.client_id);
    return client;
  } else {
    const error = await response.json();
    console.error('Registration failed:', error.error_description);
    throw new Error(error.error_description);
  }
}

// Update client configuration
async function updateClient(clientId, registrationToken, updates) {
  const response = await fetch(`/oauth/register/${clientId}`, {
    method: 'PUT',
    headers: {
      Authorization: `Bearer ${registrationToken}`,
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify(updates),
  });

  if (response.ok) {
    const updatedClient = await response.json();
    console.log('Client updated:', updatedClient.client_id);
    return updatedClient;
  } else {
    const error = await response.json();
    console.error('Update failed:', error.error_description);
    throw new Error(error.error_description);
  }
}
```

### Python Client Registration

```python
import requests
import json

def register_client(base_url):
    """Register a new OAuth client."""
    registration_data = {
        'client_name': 'My Python App',
        'redirect_uris': ['https://myapp.example.com/callback'],
        'grant_types': ['authorization_code'],
        'response_types': ['code'],
        'scope': 'read write'
    }

    response = requests.post(
        f'{base_url}/oauth/register',
        json=registration_data,
        headers={'Accept': 'application/json'}
    )

    if response.status_code == 201:
        client = response.json()
        print(f"Client registered: {client['client_id']}")
        return client
    else:
        error = response.json()
        print(f"Registration failed: {error['error_description']}")
        raise Exception(error['error_description'])

def get_client_config(base_url, client_id, registration_token):
    """Retrieve client configuration."""
    response = requests.get(
        f'{base_url}/oauth/register/{client_id}',
        headers={
            'Authorization': f'Bearer {registration_token}',
            'Accept': 'application/json'
        }
    )

    if response.status_code == 200:
        return response.json()
    else:
        error = response.json()
        raise Exception(error['error_description'])
```

## Security Considerations

### Registration Access Control

- Configure appropriate permissions for client registration endpoints
- Consider requiring authentication for client registration in production
- Implement rate limiting to prevent abuse
- Monitor registration activity for suspicious patterns

### Client Validation

- Validate redirect URIs against allowed patterns
- Enforce HTTPS for web application redirect URIs
- Validate client metadata for malicious content
- Implement client approval workflows for sensitive environments

### Token Management

- Store registration access tokens securely
- Implement token rotation for long-lived clients
- Monitor token usage for anomalies
- Provide token revocation capabilities

### Monitoring and Logging

- Log all registration, update, and deletion operations
- Monitor for failed authentication attempts
- Track client usage patterns
- Implement alerting for suspicious activity

## Troubleshooting

### Common Issues

**Q: Client registration returns 403 Forbidden**
A: Check that the client registration module is enabled and permissions are configured correctly.

**Q: Registration access token not working**
A: Verify the token was included in the Authorization header and hasn't expired.

**Q: Redirect URI validation failing**
A: Ensure redirect URIs use allowed schemes and match configured patterns.

**Q: Server metadata endpoint returns 404**
A: Verify the server metadata module is enabled and properly configured.

### Debug Configuration

Enable debug logging for detailed error information:

```php
\Drupal::logger('simple_oauth_client_registration')->debug('Registration attempt: @data', [
  '@data' => json_encode($registration_data)
]);
```
