# Simple OAuth 2.1 API Documentation

This document provides comprehensive API documentation for the OAuth 2.0/2.1 RFC compliance endpoints implemented by Simple OAuth 2.1 and its submodules.

## Quick Reference

### Public OAuth Endpoints

- `POST /oauth/token` - Token endpoint (RFC 6749)
- `GET|POST /oauth/authorize` - Authorization endpoint (RFC 6749)
- `POST /oauth/device_authorization` - Device authorization (RFC 8628)
- `GET|POST /oauth/device` - Device verification (RFC 8628)
- `POST /oauth/register` - Dynamic client registration (RFC 7591)
- `GET|PUT|DELETE /oauth/register/{client_id}` - Client management (RFC 7591)
- `GET /.well-known/oauth-authorization-server` - Server metadata (RFC 8414)
- `GET /.well-known/oauth-protected-resource` - Resource metadata (RFC 9728)
- `GET /.well-known/openid-configuration` - OpenID Connect discovery
- `GET /oauth/userinfo` - OpenID Connect UserInfo
- `GET /oauth/jwks` - JSON Web Key Set
- `GET /oauth/debug` - Token introspection debug

### Authentication Requirements

- **No authentication**: Discovery endpoints, device authorization, client registration
- **OAuth Bearer token**: UserInfo, JWKS, debug endpoints
- **Client credentials**: Token endpoint, client management endpoints
- **User authentication**: Authorization endpoint, device verification

## Core OAuth 2.0 Endpoints

### POST /oauth/token

OAuth 2.0 token endpoint for exchanging authorization codes, refresh tokens, or client credentials for access tokens.

**Purpose**: RFC 6749 token endpoint with RFC 7636 PKCE and RFC 8628 device flow support
**Authentication**: Client credentials (Basic or POST body)
**Content-Type**: application/x-www-form-urlencoded

#### Authorization Code Grant Request

```bash
curl -X POST https://example.com/oauth/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -H "Authorization: Basic $(echo -n 'client_id:client_secret' | base64)" \
  -d "grant_type=authorization_code&code=auth_code_here&redirect_uri=https://client.example.com/callback&code_verifier=code_verifier_for_pkce"
```

#### Client Credentials Grant Request

```bash
curl -X POST https://example.com/oauth/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -H "Authorization: Basic $(echo -n 'client_id:client_secret' | base64)" \
  -d "grant_type=client_credentials&scope=read write"
```

#### Device Code Grant Request

```bash
curl -X POST https://example.com/oauth/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -H "Authorization: Basic $(echo -n 'client_id:client_secret' | base64)" \
  -d "grant_type=urn:ietf:params:oauth:grant-type:device_code&device_code=device_code_here"
```

#### Refresh Token Request

```bash
curl -X POST https://example.com/oauth/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -H "Authorization: Basic $(echo -n 'client_id:client_secret' | base64)" \
  -d "grant_type=refresh_token&refresh_token=refresh_token_here"
```

#### Success Response

```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "def502001b5f8b2c4d6e7f8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z4",
  "scope": "read write"
}
```

#### Error Responses

- **400 Bad Request**: Invalid request parameters
- **401 Unauthorized**: Invalid client credentials
- **400 invalid_grant**: Invalid or expired authorization code/refresh token
- **400 invalid_client**: Client authentication failed
- **400 unsupported_grant_type**: Grant type not supported
- **400 invalid_scope**: Requested scope is invalid

### GET|POST /oauth/authorize

OAuth 2.0 authorization endpoint for user consent and authorization code generation.

**Purpose**: RFC 6749 authorization endpoint with RFC 7636 PKCE support
**Authentication**: User session required
**Content-Type**: application/x-www-form-urlencoded (POST)

#### Authorization Request

```bash
# Redirect user to:
https://example.com/oauth/authorize?response_type=code&client_id=client123&redirect_uri=https://client.example.com/callback&scope=read%20write&state=random_state&code_challenge=CODE_CHALLENGE&code_challenge_method=S256
```

#### Success Response (Redirect)

```
HTTP/1.1 302 Found
Location: https://client.example.com/callback?code=authorization_code_here&state=random_state
```

#### Error Response (Redirect)

```
HTTP/1.1 302 Found
Location: https://client.example.com/callback?error=access_denied&error_description=The+user+denied+the+request&state=random_state
```

## Device Authorization Grant (RFC 8628)

### POST /oauth/device_authorization

Initiates the device authorization flow for input-constrained devices.

**Purpose**: RFC 8628 device authorization endpoint
**Authentication**: Client ID required in request body
**Content-Type**: application/x-www-form-urlencoded

#### Request Format

```bash
curl -X POST https://example.com/oauth/device_authorization \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "client_id=client123&scope=read write"
```

#### Response Format

```json
{
  "device_code": "GmRhmhcxhwEzkoEqiMEg_DnyEysNkuNhszIySk9eS",
  "user_code": "WDJB-MJHT",
  "verification_uri": "https://example.com/oauth/device",
  "verification_uri_complete": "https://example.com/oauth/device?user_code=WDJB-MJHT",
  "expires_in": 1800,
  "interval": 5
}
```

#### Error Responses

- **400 Bad Request**: Missing client_id parameter
- **401 Unauthorized**: Invalid client_id
- **403 Forbidden**: Client not authorized for device flow

### GET /oauth/device

Displays the device verification form for users to enter their user code.

**Purpose**: RFC 8628 device verification interface
**Authentication**: User session required (redirects to login if anonymous)
**Content-Type**: text/html

#### Example Request

```bash
# User visits in browser:
https://example.com/oauth/device
# Or with pre-filled code:
https://example.com/oauth/device?user_code=WDJB-MJHT
```

### POST /oauth/device

Processes device verification form submission.

**Purpose**: RFC 8628 device verification submission
**Authentication**: User session required
**Content-Type**: application/x-www-form-urlencoded

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

## OpenID Connect Discovery

### GET /.well-known/openid-configuration

Provides OpenID Connect discovery metadata for client configuration.

**Purpose**: OpenID Connect Discovery 1.0 specification compliance
**Authentication**: None required
**Content-Type**: application/json

#### Response Format

```json
{
  "issuer": "https://example.com",
  "authorization_endpoint": "https://example.com/oauth/authorize",
  "token_endpoint": "https://example.com/oauth/token",
  "userinfo_endpoint": "https://example.com/oauth/userinfo",
  "jwks_uri": "https://example.com/oauth/jwks",
  "response_types_supported": ["code", "token", "id_token"],
  "subject_types_supported": ["public"],
  "id_token_signing_alg_values_supported": ["RS256"],
  "scopes_supported": ["openid", "profile", "email"],
  "claims_supported": ["sub", "name", "email", "picture"],
  "grant_types_supported": [
    "authorization_code",
    "refresh_token",
    "client_credentials"
  ]
}
```

#### Example Request

```bash
curl -X GET https://example.com/.well-known/openid-configuration \
  -H "Accept: application/json"
```

## OpenID Connect Endpoints

### GET /oauth/userinfo

Returns claims about the authenticated user.

**Purpose**: OpenID Connect UserInfo endpoint
**Authentication**: OAuth 2.0 Bearer token required
**Content-Type**: application/json

#### Request Format

```bash
curl -X GET https://example.com/oauth/userinfo \
  -H "Authorization: Bearer access_token_here" \
  -H "Accept: application/json"
```

#### Response Format

```json
{
  "sub": "248289761001",
  "name": "Jane Doe",
  "given_name": "Jane",
  "family_name": "Doe",
  "preferred_username": "j.doe",
  "email": "janedoe@example.com",
  "email_verified": true,
  "picture": "https://example.com/avatar/248289761001.jpg"
}
```

### GET /oauth/jwks

Provides the JSON Web Key Set for token verification.

**Purpose**: OpenID Connect JWKS endpoint for public key distribution
**Authentication**: OAuth 2.0 Bearer token required
**Content-Type**: application/json

#### Request Format

```bash
curl -X GET https://example.com/oauth/jwks \
  -H "Authorization: Bearer access_token_here" \
  -H "Accept: application/json"
```

#### Response Format

```json
{
  "keys": [
    {
      "kty": "RSA",
      "use": "sig",
      "kid": "2011-04-29",
      "n": "0vx7agoebGcQ...",
      "e": "AQAB",
      "alg": "RS256"
    }
  ]
}
```

## Debug and Introspection

### GET /oauth/debug

Provides token introspection for debugging purposes.

**Purpose**: Token introspection and validation
**Authentication**: OAuth 2.0 Bearer token required
**Content-Type**: application/json

#### Request Format

```bash
curl -X GET https://example.com/oauth/debug \
  -H "Authorization: Bearer access_token_here" \
  -H "Accept: application/json"
```

#### Response Format

```json
{
  "active": true,
  "client_id": "client123",
  "scope": "read write",
  "sub": "248289761001",
  "exp": 1640995200,
  "iat": 1640991600,
  "token_type": "Bearer"
}
```

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

All API endpoints use consistent OAuth 2.0 error response format:

```json
{
  "error": "invalid_request",
  "error_description": "The request is missing a required parameter.",
  "error_uri": "https://example.com/docs/errors#invalid_request"
}
```

### OAuth 2.0 Error Codes

#### Token Endpoint Errors

- **invalid_request**: Malformed request or missing required parameters
- **invalid_client**: Client authentication failed
- **invalid_grant**: Authorization grant is invalid, expired, or revoked
- **unauthorized_client**: Client not authorized for this grant type
- **unsupported_grant_type**: Grant type not supported
- **invalid_scope**: Requested scope is invalid or exceeds granted scope

#### Authorization Endpoint Errors

- **invalid_request**: Missing or invalid request parameters
- **unauthorized_client**: Client not authorized for authorization code grant
- **access_denied**: User denied the authorization request
- **unsupported_response_type**: Response type not supported
- **invalid_scope**: Requested scope is invalid
- **server_error**: Server encountered unexpected condition
- **temporarily_unavailable**: Server temporarily overloaded

#### Device Flow Errors

- **authorization_pending**: User hasn't completed authorization yet
- **slow_down**: Client should reduce polling frequency
- **access_denied**: User denied the device authorization request
- **expired_token**: Device code expired

#### Client Registration Errors

- **invalid_client_metadata**: Invalid client metadata values
- **invalid_redirect_uri**: Invalid redirect URI format or pattern
- **invalid_client_id**: Unknown client identifier
- **access_denied**: Registration operation not permitted

#### PKCE Errors

- **invalid_request**: Missing code_verifier when code_challenge was sent
- **invalid_grant**: Code verifier doesn't match code challenge

### HTTP Status Codes

- **200 OK**: Successful request
- **201 Created**: Resource created successfully
- **204 No Content**: Successful deletion
- **400 Bad Request**: Client error in request
- **401 Unauthorized**: Authentication required or failed
- **403 Forbidden**: Insufficient permissions
- **404 Not Found**: Resource not found
- **422 Unprocessable Entity**: Request validation failed
- **429 Too Many Requests**: Rate limit exceeded
- **500 Internal Server Error**: Server error
- **503 Service Unavailable**: Temporary service unavailability

## Integration Examples

### Complete OAuth 2.1 Flow with PKCE

#### JavaScript Web Application

```javascript
class OAuth21Client {
  constructor(baseUrl, clientId, redirectUri) {
    this.baseUrl = baseUrl;
    this.clientId = clientId;
    this.redirectUri = redirectUri;
  }

  // Generate PKCE challenge
  async generatePKCE() {
    const codeVerifier = this.generateRandomString(128);
    const codeChallenge = await this.sha256(codeVerifier);
    return { codeVerifier, codeChallenge };
  }

  generateRandomString(length) {
    const charset =
      'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
    return Array.from(crypto.getRandomValues(new Uint8Array(length)))
      .map(x => charset[x % charset.length])
      .join('');
  }

  async sha256(plain) {
    const encoder = new TextEncoder();
    const data = encoder.encode(plain);
    const hash = await crypto.subtle.digest('SHA-256', data);
    return btoa(String.fromCharCode(...new Uint8Array(hash)))
      .replace(/\+/g, '-')
      .replace(/\//g, '_')
      .replace(/=/g, '');
  }

  // Start authorization flow
  async authorize(scope = 'read write') {
    const { codeVerifier, codeChallenge } = await this.generatePKCE();
    const state = this.generateRandomString(32);

    // Store for later use
    sessionStorage.setItem('oauth_code_verifier', codeVerifier);
    sessionStorage.setItem('oauth_state', state);

    const params = new URLSearchParams({
      response_type: 'code',
      client_id: this.clientId,
      redirect_uri: this.redirectUri,
      scope: scope,
      state: state,
      code_challenge: codeChallenge,
      code_challenge_method: 'S256',
    });

    window.location.href = `${this.baseUrl}/oauth/authorize?${params}`;
  }

  // Exchange code for token
  async exchangeCode(code, state) {
    const storedState = sessionStorage.getItem('oauth_state');
    const codeVerifier = sessionStorage.getItem('oauth_code_verifier');

    if (state !== storedState) {
      throw new Error('Invalid state parameter');
    }

    const response = await fetch(`${this.baseUrl}/oauth/token`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        grant_type: 'authorization_code',
        client_id: this.clientId,
        code: code,
        redirect_uri: this.redirectUri,
        code_verifier: codeVerifier,
      }),
    });

    if (response.ok) {
      const tokens = await response.json();
      sessionStorage.removeItem('oauth_code_verifier');
      sessionStorage.removeItem('oauth_state');
      return tokens;
    } else {
      const error = await response.json();
      throw new Error(`Token exchange failed: ${error.error_description}`);
    }
  }

  // Make authenticated API call
  async apiCall(endpoint, accessToken, options = {}) {
    const response = await fetch(`${this.baseUrl}${endpoint}`, {
      ...options,
      headers: {
        Authorization: `Bearer ${accessToken}`,
        Accept: 'application/json',
        ...options.headers,
      },
    });

    if (response.ok) {
      return await response.json();
    } else {
      const error = await response.json();
      throw new Error(`API call failed: ${error.error_description}`);
    }
  }
}

// Usage example
const client = new OAuth21Client(
  'https://example.com',
  'your_client_id',
  'https://yourapp.com/callback',
);

// Start authorization
client.authorize('read write profile');

// Handle callback (in your callback page)
const urlParams = new URLSearchParams(window.location.search);
const code = urlParams.get('code');
const state = urlParams.get('state');

if (code) {
  client
    .exchangeCode(code, state)
    .then(tokens => {
      console.log('Access token:', tokens.access_token);
      // Make API calls
      return client.apiCall('/oauth/userinfo', tokens.access_token);
    })
    .then(userInfo => {
      console.log('User info:', userInfo);
    })
    .catch(error => {
      console.error('Error:', error.message);
    });
}
```

### Device Flow Implementation

#### JavaScript Device Application

```javascript
class DeviceFlowClient {
  constructor(baseUrl, clientId) {
    this.baseUrl = baseUrl;
    this.clientId = clientId;
  }

  // Start device authorization
  async startDeviceFlow(scope = 'read write') {
    const response = await fetch(`${this.baseUrl}/oauth/device_authorization`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        client_id: this.clientId,
        scope: scope,
      }),
    });

    if (response.ok) {
      return await response.json();
    } else {
      const error = await response.json();
      throw new Error(
        `Device authorization failed: ${error.error_description}`,
      );
    }
  }

  // Poll for token
  async pollForToken(deviceCode, interval = 5) {
    return new Promise((resolve, reject) => {
      const poll = async () => {
        try {
          const response = await fetch(`${this.baseUrl}/oauth/token`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
              grant_type: 'urn:ietf:params:oauth:grant-type:device_code',
              client_id: this.clientId,
              device_code: deviceCode,
            }),
          });

          if (response.ok) {
            const tokens = await response.json();
            resolve(tokens);
          } else {
            const error = await response.json();

            if (error.error === 'authorization_pending') {
              // Continue polling
              setTimeout(poll, interval * 1000);
            } else if (error.error === 'slow_down') {
              // Increase interval
              setTimeout(poll, (interval + 5) * 1000);
            } else {
              // Terminal error
              reject(
                new Error(`Token polling failed: ${error.error_description}`),
              );
            }
          }
        } catch (err) {
          reject(err);
        }
      };

      poll();
    });
  }
}

// Usage example
const deviceClient = new DeviceFlowClient(
  'https://example.com',
  'device_client_id',
);

deviceClient
  .startDeviceFlow('read write')
  .then(authData => {
    console.log(`Please visit: ${authData.verification_uri}`);
    console.log(`Enter code: ${authData.user_code}`);

    // Start polling for token
    return deviceClient.pollForToken(authData.device_code, authData.interval);
  })
  .then(tokens => {
    console.log('Device authorized! Access token:', tokens.access_token);
  })
  .catch(error => {
    console.error('Device flow error:', error.message);
  });
```

### Python OAuth 2.1 Client

```python
import requests
import secrets
import hashlib
import base64
import urllib.parse
import time
from typing import Dict, Optional

class OAuth21Client:
    def __init__(self, base_url: str, client_id: str, client_secret: Optional[str] = None):
        self.base_url = base_url.rstrip('/')
        self.client_id = client_id
        self.client_secret = client_secret
        self.session = requests.Session()

    def generate_pkce(self) -> Dict[str, str]:
        """Generate PKCE code verifier and challenge."""
        code_verifier = base64.urlsafe_b64encode(secrets.token_bytes(32)).decode('utf-8').rstrip('=')
        code_challenge = base64.urlsafe_b64encode(
            hashlib.sha256(code_verifier.encode('utf-8')).digest()
        ).decode('utf-8').rstrip('=')
        return {
            'code_verifier': code_verifier,
            'code_challenge': code_challenge
        }

    def get_authorization_url(self, redirect_uri: str, scope: str = 'read write',
                            state: Optional[str] = None) -> Dict[str, str]:
        """Generate authorization URL with PKCE."""
        pkce = self.generate_pkce()
        if not state:
            state = secrets.token_urlsafe(32)

        params = {
            'response_type': 'code',
            'client_id': self.client_id,
            'redirect_uri': redirect_uri,
            'scope': scope,
            'state': state,
            'code_challenge': pkce['code_challenge'],
            'code_challenge_method': 'S256'
        }

        auth_url = f"{self.base_url}/oauth/authorize?{urllib.parse.urlencode(params)}"

        return {
            'url': auth_url,
            'code_verifier': pkce['code_verifier'],
            'state': state
        }

    def exchange_code_for_token(self, code: str, redirect_uri: str,
                              code_verifier: str) -> Dict:
        """Exchange authorization code for access token."""
        data = {
            'grant_type': 'authorization_code',
            'client_id': self.client_id,
            'code': code,
            'redirect_uri': redirect_uri,
            'code_verifier': code_verifier
        }

        headers = {'Content-Type': 'application/x-www-form-urlencoded'}

        if self.client_secret:
            # Use client credentials authentication
            auth = (self.client_id, self.client_secret)
            response = self.session.post(f'{self.base_url}/oauth/token',
                                       data=data, headers=headers, auth=auth)
        else:
            # Public client
            response = self.session.post(f'{self.base_url}/oauth/token',
                                       data=data, headers=headers)

        if response.status_code == 200:
            return response.json()
        else:
            error = response.json()
            raise Exception(f"Token exchange failed: {error.get('error_description', 'Unknown error')}")

    def start_device_flow(self, scope: str = 'read write') -> Dict:
        """Start device authorization flow."""
        data = {
            'client_id': self.client_id,
            'scope': scope
        }

        response = self.session.post(
            f'{self.base_url}/oauth/device_authorization',
            data=data,
            headers={'Content-Type': 'application/x-www-form-urlencoded'}
        )

        if response.status_code == 200:
            return response.json()
        else:
            error = response.json()
            raise Exception(f"Device authorization failed: {error.get('error_description', 'Unknown error')}")

    def poll_for_device_token(self, device_code: str, interval: int = 5) -> Dict:
        """Poll for device token until authorized or error."""
        data = {
            'grant_type': 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id': self.client_id,
            'device_code': device_code
        }

        headers = {'Content-Type': 'application/x-www-form-urlencoded'}

        while True:
            if self.client_secret:
                auth = (self.client_id, self.client_secret)
                response = self.session.post(f'{self.base_url}/oauth/token',
                                           data=data, headers=headers, auth=auth)
            else:
                response = self.session.post(f'{self.base_url}/oauth/token',
                                           data=data, headers=headers)

            if response.status_code == 200:
                return response.json()

            error = response.json()
            error_code = error.get('error')

            if error_code == 'authorization_pending':
                time.sleep(interval)
                continue
            elif error_code == 'slow_down':
                interval += 5
                time.sleep(interval)
                continue
            else:
                raise Exception(f"Device token polling failed: {error.get('error_description', 'Unknown error')}")

    def make_api_call(self, endpoint: str, access_token: str, method: str = 'GET', **kwargs) -> Dict:
        """Make authenticated API call."""
        headers = kwargs.pop('headers', {})
        headers['Authorization'] = f'Bearer {access_token}'
        headers['Accept'] = 'application/json'

        response = self.session.request(
            method, f'{self.base_url}{endpoint}', headers=headers, **kwargs
        )

        if response.status_code == 200:
            return response.json()
        else:
            error = response.json() if response.headers.get('content-type', '').startswith('application/json') else {}
            raise Exception(f"API call failed: {error.get('error_description', response.text)}")

# Usage examples

# Authorization Code Flow with PKCE
client = OAuth21Client('https://example.com', 'your_client_id')
auth_data = client.get_authorization_url('https://yourapp.com/callback', 'read write profile')
print(f"Visit: {auth_data['url']}")

# After user authorizes and you get the code:
# tokens = client.exchange_code_for_token(code, 'https://yourapp.com/callback', auth_data['code_verifier'])
# user_info = client.make_api_call('/oauth/userinfo', tokens['access_token'])

# Device Flow
device_client = OAuth21Client('https://example.com', 'device_client_id')
device_auth = device_client.start_device_flow('read write')
print(f"Visit: {device_auth['verification_uri']}")
print(f"Enter code: {device_auth['user_code']}")

# tokens = device_client.poll_for_device_token(device_auth['device_code'], device_auth['interval'])
# print(f"Device authorized! Access token: {tokens['access_token']}")
```

### Dynamic Client Registration

```javascript
// Register a new OAuth client
async function registerClient(baseUrl, clientData) {
  const response = await fetch(`${baseUrl}/oauth/register`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify({
      client_name: clientData.name,
      redirect_uris: clientData.redirectUris,
      grant_types: ['authorization_code', 'refresh_token'],
      response_types: ['code'],
      scope: 'read write',
      client_uri: clientData.homepage,
      logo_uri: clientData.logo,
      contacts: [clientData.contact],
      tos_uri: clientData.termsOfService,
      policy_uri: clientData.privacyPolicy,
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
async function updateClient(baseUrl, clientId, registrationToken, updates) {
  const response = await fetch(`${baseUrl}/oauth/register/${clientId}`, {
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

## OAuth 2.1 Security Features

### PKCE (Proof Key for Code Exchange)

PKCE is mandatory for public clients and recommended for confidential clients:

```bash
# PKCE configuration endpoints
GET /admin/config/people/simple_oauth/oauth-21/pkce
```

**Configuration Options:**

- **Enforcement Level**: Mandatory, optional, or disabled
- **Allowed Methods**: S256 (recommended), plain (deprecated)
- **Code Challenge Length**: 43-128 characters

### Native Apps Security (RFC 8252)

Enhanced security for mobile and desktop applications:

```bash
# Native apps configuration
GET /admin/config/people/simple_oauth/oauth-21/native-apps
```

**Security Features:**

- **WebView Detection**: Prevents use of embedded browsers
- **Custom URI Schemes**: Support for app-specific redirect URIs
- **Loopback Redirects**: Enhanced security for desktop apps
- **Exact URI Matching**: Strict redirect URI validation

### Client Registration Security

#### Access Control

- Configure appropriate permissions for client registration endpoints
- Consider requiring authentication for client registration in production
- Implement rate limiting to prevent abuse
- Monitor registration activity for suspicious patterns

#### Client Validation

- Validate redirect URIs against allowed patterns
- Enforce HTTPS for web application redirect URIs
- Validate client metadata for malicious content
- Implement client approval workflows for sensitive environments

### Token Security

#### Token Management

- Use JWT tokens with RSA or ECDSA signatures
- Implement appropriate token lifetimes
- Support refresh token rotation
- Provide token revocation capabilities

#### Token Validation

```bash
# Validate tokens using debug endpoint
GET /oauth/debug
Authorization: Bearer YOUR_TOKEN
```

### Device Flow Security

#### User Code Security

- Limited character set to prevent confusion
- Configurable expiration times
- Rate limiting on verification attempts
- Audit logging of all device flow operations

#### Polling Security

- Configurable polling intervals
- Automatic rate limiting for slow_down responses
- Device code expiration enforcement

### Monitoring and Logging

#### OAuth Event Logging

- Log all token requests and grants
- Monitor failed authentication attempts
- Track unusual client behavior patterns
- Log all client registration operations

#### Security Alerts

- Implement alerting for suspicious activity
- Monitor for brute force attacks
- Track abnormal token usage patterns
- Alert on configuration changes

### Compliance and Best Practices

#### OAuth 2.1 Compliance

- PKCE required for public clients
- Redirect URI exact matching
- No implicit flow support
- Enhanced security for native apps

#### OpenID Connect Security

- ID token signature validation
- Nonce parameter support
- Claims validation
- Subject identifier consistency

## Troubleshooting

### Common Issues

#### Authorization and Token Issues

**Q: Authorization code flow fails with "invalid_grant"**
A: Check that:

- Authorization code hasn't expired (typically 10 minutes)
- PKCE code_verifier matches the original code_challenge
- Redirect URI exactly matches the one used in authorization request
- Client credentials are correct

**Q: PKCE validation failing**
A: Ensure:

- Code verifier is 43-128 characters long
- Code challenge is properly base64url encoded SHA256 hash
- Using S256 method (plain is deprecated)
- PKCE is enabled in module configuration

**Q: Device flow returns "authorization_pending"**
A: This is normal - continue polling until user completes authorization or timeout occurs.

**Q: Device flow returns "slow_down"**
A: Increase polling interval by 5 seconds and continue polling.

#### Client Registration Issues

**Q: Client registration returns 403 Forbidden**
A: Check that:

- Client registration module is enabled
- Permissions are configured correctly
- Authentication is provided if required

**Q: Registration access token not working**
A: Verify:

- Token is included in Authorization header as "Bearer TOKEN"
- Token hasn't expired
- Token was issued for the correct client

**Q: Redirect URI validation failing**
A: Ensure:

- URIs use HTTPS (except localhost for development)
- URIs exactly match registered patterns
- No URL fragments (#) in redirect URIs
- Custom schemes are properly formatted

#### Discovery and Metadata Issues

**Q: Server metadata endpoint returns 404**
A: Verify:

- Server metadata module is enabled
- Route cache is cleared
- Proper URL structure is used

**Q: OpenID Connect discovery returns 404**
A: Check:

- OpenID Connect is not disabled in simple_oauth settings
- Server metadata module is enabled
- Clear all caches

### Debug Configuration

#### Enable Detailed Logging

```php
// Enable debug logging for OAuth operations
\Drupal::logger('simple_oauth')->debug('Token request: @data', [
  '@data' => json_encode($token_data)
]);

// Device flow logging
\Drupal::logger('simple_oauth_device_flow')->debug('Device authorization: @data', [
  '@data' => json_encode($device_data)
]);

// Client registration logging
\Drupal::logger('simple_oauth_client_registration')->debug('Registration attempt: @data', [
  '@data' => json_encode($registration_data)
]);
```

#### Testing Endpoints

```bash
# Test server metadata
curl -v https://example.com/.well-known/oauth-authorization-server

# Test token endpoint with verbose output
curl -v -X POST https://example.com/oauth/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "grant_type=client_credentials&client_id=test&client_secret=secret"

# Test device authorization
curl -v -X POST https://example.com/oauth/device_authorization \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "client_id=test&scope=read"
```

#### Checking Module Status

```bash
# Check if required modules are enabled
vendor/bin/drush pm:list | grep simple_oauth

# Enable missing modules
vendor/bin/drush pm:enable simple_oauth_device_flow simple_oauth_server_metadata

# Clear caches
vendor/bin/drush cache:rebuild
```

#### Configuration Validation

```bash
# Check OAuth configuration
vendor/bin/drush config:get simple_oauth.settings

# Check PKCE settings
vendor/bin/drush config:get simple_oauth_pkce.settings

# Check device flow settings
vendor/bin/drush config:get simple_oauth_device_flow.settings
```

### Performance Optimization

#### Caching Recommendations

- Enable appropriate caching for metadata endpoints
- Use Redis or Memcache for token storage
- Configure proper cache headers for static responses

#### Database Optimization

- Regular cleanup of expired tokens and device codes
- Index optimization for client and token queries
- Consider token storage backend optimization

### Security Audit Checklist

- [ ] PKCE enabled and enforced for public clients
- [ ] HTTPS enforced for all OAuth endpoints
- [ ] Proper client authentication configured
- [ ] Redirect URI validation properly configured
- [ ] Token lifetimes set appropriately
- [ ] Rate limiting configured for all endpoints
- [ ] Logging enabled for security events
- [ ] Regular security updates applied

For additional support and documentation, visit:

- [Simple OAuth Module Documentation](https://www.drupal.org/docs/contributed-modules/simple-oauth)
- [OAuth 2.1 Security Best Practices](https://datatracker.ietf.org/doc/draft-ietf-oauth-security-topics/)
- [RFC 6749: OAuth 2.0 Authorization Framework](https://tools.ietf.org/html/rfc6749)
- [RFC 7636: PKCE](https://tools.ietf.org/html/rfc7636)
- [RFC 8628: Device Authorization Grant](https://tools.ietf.org/html/rfc8628)
