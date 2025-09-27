# Simple OAuth Client Registration

A Drupal module implementing **RFC 7591 OAuth 2.0 Dynamic Client Registration Protocol** for automated OAuth client onboarding and management.

## Overview

The Simple OAuth Client Registration module provides a complete implementation of RFC 7591, enabling OAuth clients to register themselves dynamically without manual administrator intervention. This module integrates seamlessly with Drupal's OAuth 2.0 ecosystem to provide enterprise-grade client management capabilities.

### Key Features

- **RFC 7591 Compliance**: Full implementation of Dynamic Client Registration specification
- **Automated Client Onboarding**: Self-service client registration via RESTful API
- **Complete CRUD Operations**: Create, read, update, and delete client registrations
- **Rich Client Metadata**: Support for branding, contact information, and application details
- **Security Controls**: Registration access tokens and comprehensive validation
- **Standards Integration**: Works with other OAuth RFC implementations

## Requirements

### Drupal Core
- Drupal 10.x or 11.x

### Module Dependencies
- `simple_oauth_21` - Main OAuth 2.1 umbrella module
- `simple_oauth` - Core OAuth 2.0 functionality
- `consumers` - OAuth consumer entity management
- `serialization` - JSON serialization support

### Recommended Modules
- `simple_oauth_server_metadata` - Automatic endpoint discovery
- `simple_oauth_native_apps` - Enhanced native app support
- `simple_oauth_pkce` - PKCE security enhancement

## Installation

1. **Install via Composer** (recommended):
   ```bash
   composer require drupal/simple_oauth_21
   ```

2. **Enable the module**:
   ```bash
   drush pm:enable simple_oauth_client_registration
   ```

3. **Run database updates**:
   ```bash
   drush updatedb
   ```

4. **Clear caches**:
   ```bash
   drush cache:rebuild
   ```

## Configuration

### Basic Setup

1. **Verify OAuth Configuration**:
   - Ensure Simple OAuth is properly configured
   - Configure OAuth scopes and grant types
   - Set up SSL certificates for production use

2. **Module Installation**:
   - The module automatically creates necessary database tables
   - RFC 7591 metadata fields are added to Consumer entities
   - Registration endpoint is configured in server metadata (if available)

### Permissions

Configure access permissions at **Administration » People » Permissions**:

- **Access OAuth client registration**: Controls access to registration endpoints
- **Administer OAuth consumers**: Manage registered clients via admin interface

### Field Configuration

The module adds the following RFC 7591 metadata fields to Consumer entities:

- **Client URI**: Application homepage URL
- **Logo URI**: External logo URL (alternative to uploaded image)
- **Contacts**: Developer email addresses (multiple values)
- **Terms of Service URI**: Application terms URL
- **Privacy Policy URI**: Application privacy policy URL
- **JWKS URI**: JSON Web Key Set document URL
- **Software ID**: Unique application identifier
- **Software Version**: Application version string

## API Reference

### Registration Endpoint

**POST** `/oauth/register`

Register a new OAuth client with the authorization server.

#### Request Headers
```
Content-Type: application/json
```

#### Request Body
```json
{
  "client_name": "My Application",
  "redirect_uris": [
    "https://myapp.example.com/callback",
    "https://myapp.example.com/callback2"
  ],
  "grant_types": [
    "authorization_code",
    "refresh_token"
  ],
  "response_types": [
    "code"
  ],
  "token_endpoint_auth_method": "client_secret_basic",
  "scope": "read write",
  "client_uri": "https://myapp.example.com",
  "logo_uri": "https://myapp.example.com/logo.png",
  "contacts": [
    "admin@myapp.example.com",
    "support@myapp.example.com"
  ],
  "tos_uri": "https://myapp.example.com/terms",
  "policy_uri": "https://myapp.example.com/privacy",
  "software_id": "550e8400-e29b-41d4-a716-446655440000",
  "software_version": "1.0.0",
  "application_type": "web"
}
```

#### Response (201 Created)
```json
{
  "client_id": "s6BhdRkqt3_client_id_example",
  "client_secret": "ZJYCqe3h2_client_secret_example",
  "registration_access_token": "this.is.an.example.token",
  "registration_client_uri": "https://example.com/oauth/register/s6BhdRkqt3_client_id_example",
  "client_id_issued_at": 1571763200,
  "client_secret_expires_at": 0,
  "client_name": "My Application",
  "redirect_uris": [
    "https://myapp.example.com/callback",
    "https://myapp.example.com/callback2"
  ],
  "grant_types": [
    "authorization_code",
    "refresh_token"
  ],
  "response_types": [
    "code"
  ],
  "token_endpoint_auth_method": "client_secret_basic",
  "scope": "read write",
  "client_uri": "https://myapp.example.com",
  "logo_uri": "https://myapp.example.com/logo.png",
  "contacts": [
    "admin@myapp.example.com",
    "support@myapp.example.com"
  ],
  "tos_uri": "https://myapp.example.com/terms",
  "policy_uri": "https://myapp.example.com/privacy",
  "software_id": "550e8400-e29b-41d4-a716-446655440000",
  "software_version": "1.0.0"
}
```

### Client Management Endpoints

All client management operations require the `registration_access_token` obtained during registration.

#### Get Client Configuration

**GET** `/oauth/register/{client_id}`

#### Request Headers
```
Authorization: Bearer {registration_access_token}
```

#### Response (200 OK)
```json
{
  "client_id": "s6BhdRkqt3_client_id_example",
  "client_name": "My Application",
  "redirect_uris": ["https://myapp.example.com/callback"],
  "grant_types": ["authorization_code", "refresh_token"],
  "client_uri": "https://myapp.example.com",
  "logo_uri": "https://myapp.example.com/logo.png"
}
```

#### Update Client Configuration

**PUT** `/oauth/register/{client_id}`

#### Request Headers
```
Authorization: Bearer {registration_access_token}
Content-Type: application/json
```

#### Request Body
```json
{
  "client_name": "My Updated Application Name",
  "client_uri": "https://myapp.example.com/new-homepage",
  "redirect_uris": [
    "https://myapp.example.com/callback",
    "https://myapp.example.com/new-callback"
  ]
}
```

#### Response (200 OK)
Returns updated client metadata in the same format as GET.

#### Delete Client Registration

**DELETE** `/oauth/register/{client_id}`

#### Request Headers
```
Authorization: Bearer {registration_access_token}
```

#### Response (204 No Content)
Empty response body on successful deletion.

### Error Responses

All endpoints return RFC 7591 compliant error responses:

```json
{
  "error": "invalid_client_metadata",
  "error_description": "redirect_uris must be a non-empty array"
}
```

**Common Error Codes:**
- `invalid_client_metadata`: Invalid or missing client metadata
- `invalid_request`: Malformed request
- `server_error`: Internal server error

## Client Metadata Specification

### Required Fields

- **redirect_uris**: Array of valid redirect URIs (required for most grant types)

### Optional Fields

| Field | Type | Description | Example |
|-------|------|-------------|---------|
| `client_name` | string | Human-readable application name | "My Application" |
| `client_uri` | URI | Application homepage URL | "https://myapp.example.com" |
| `logo_uri` | URI | Application logo URL | "https://myapp.example.com/logo.png" |
| `contacts` | array | Developer email addresses | ["admin@example.com"] |
| `tos_uri` | URI | Terms of service URL | "https://myapp.example.com/terms" |
| `policy_uri` | URI | Privacy policy URL | "https://myapp.example.com/privacy" |
| `jwks_uri` | URI | JSON Web Key Set URL | "https://myapp.example.com/.well-known/jwks.json" |
| `software_id` | string | Unique software identifier | "550e8400-e29b-41d4-a716-446655440000" |
| `software_version` | string | Software version | "1.0.0" |
| `grant_types` | array | OAuth grant types | ["authorization_code", "refresh_token"] |
| `response_types` | array | OAuth response types | ["code"] |
| `token_endpoint_auth_method` | string | Token endpoint authentication | "client_secret_basic" |
| `scope` | string | Requested OAuth scopes | "read write" |
| `application_type` | string | Application type | "web" or "native" |

### Authentication Methods

Supported `token_endpoint_auth_method` values:
- `client_secret_basic` - HTTP Basic authentication (default)
- `client_secret_post` - Client credentials in POST body
- `none` - Public client (no authentication)
- `client_secret_jwt` - JWT with shared secret
- `private_key_jwt` - JWT with private key

### Grant Types

Supported OAuth 2.0 grant types:
- `authorization_code` - Standard authorization code flow
- `implicit` - Implicit flow (deprecated)
- `password` - Resource owner password credentials
- `client_credentials` - Client credentials flow
- `refresh_token` - Refresh token usage
- `urn:ietf:params:oauth:grant-type:device_code` - Device authorization flow

## Integration Examples

### PHP Client Registration

```php
<?php

// Register a new OAuth client
$client_data = [
    'client_name' => 'PHP Application',
    'redirect_uris' => ['https://myapp.com/oauth/callback'],
    'grant_types' => ['authorization_code', 'refresh_token'],
    'scope' => 'read write',
    'client_uri' => 'https://myapp.com',
    'contacts' => ['admin@myapp.com']
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://oauth-server.com/oauth/register');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($client_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 201) {
    $client = json_decode($response, true);
    echo "Client registered with ID: " . $client['client_id'] . "\n";
    echo "Client secret: " . $client['client_secret'] . "\n";
    echo "Registration token: " . $client['registration_access_token'] . "\n";
} else {
    echo "Registration failed: " . $response . "\n";
}
```

### JavaScript Client Registration

```javascript
// Register a new OAuth client
const clientData = {
    client_name: 'JavaScript Application',
    redirect_uris: ['https://spa.example.com/callback'],
    grant_types: ['authorization_code'],
    response_types: ['code'],
    token_endpoint_auth_method: 'none', // Public client
    application_type: 'web',
    client_uri: 'https://spa.example.com',
    contacts: ['admin@spa.example.com']
};

fetch('https://oauth-server.com/oauth/register', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
    },
    body: JSON.stringify(clientData)
})
.then(response => {
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    return response.json();
})
.then(client => {
    console.log('Client registered:', client.client_id);
    console.log('Registration token:', client.registration_access_token);

    // Store registration token for future management operations
    localStorage.setItem('registration_token', client.registration_access_token);
    localStorage.setItem('client_id', client.client_id);
})
.catch(error => {
    console.error('Registration failed:', error);
});
```

### cURL Examples

#### Register a Client
```bash
curl -X POST https://oauth-server.com/oauth/register \
  -H "Content-Type: application/json" \
  -d '{
    "client_name": "Test Application",
    "redirect_uris": ["https://test.example.com/callback"],
    "grant_types": ["authorization_code"],
    "client_uri": "https://test.example.com"
  }'
```

#### Update Client Configuration
```bash
curl -X PUT https://oauth-server.com/oauth/register/CLIENT_ID \
  -H "Authorization: Bearer REGISTRATION_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "client_name": "Updated Test Application",
    "client_uri": "https://updated.example.com"
  }'
```

#### Delete Client Registration
```bash
curl -X DELETE https://oauth-server.com/oauth/register/CLIENT_ID \
  -H "Authorization: Bearer REGISTRATION_ACCESS_TOKEN"
```

## Administrative Interface

### Managing Registered Clients

Navigate to **Administration » Configuration » People » OAuth » Consumers** to view and manage all OAuth clients, including those registered dynamically.

#### Client Identification

Dynamically registered clients are identified by:
- **Description**: "Client registered via RFC 7591 Dynamic Client Registration"
- **Third Party**: Marked as TRUE
- **Enhanced Metadata**: Additional RFC 7591 fields populated

#### Administrative Actions

Administrators can:
- View client registration details
- Edit client metadata
- Disable or delete clients
- Monitor client usage
- Export client configurations

### Database Management

The module creates a dedicated table for registration tokens:

```sql
-- Registration access tokens table
CREATE TABLE simple_oauth_client_registration_tokens (
  id SERIAL PRIMARY KEY,
  client_id VARCHAR(128) NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  created INTEGER NOT NULL,
  expires INTEGER NOT NULL,
  UNIQUE KEY client_id (client_id),
  KEY expires (expires)
);
```

#### Token Cleanup

Expired registration tokens are automatically cleaned up. Manual cleanup can be performed:

```php
// Clean up expired tokens
$token_service = \Drupal::service('simple_oauth_client_registration.service.token');
$token_service->cleanupExpiredTokens();
```

## Security Considerations

### Registration Access Tokens

- **Secure Generation**: 256-bit cryptographically secure tokens
- **Hashed Storage**: Tokens are hashed before database storage
- **Long Expiration**: 1-year default expiration for token stability
- **Revocation**: Tokens are revoked when clients are deleted

### Validation and Sanitization

- **URI Validation**: All URI fields validated for proper format
- **Email Validation**: Contact emails validated for proper format
- **Grant Type Validation**: Only supported grant types accepted
- **Response Type Validation**: Valid response type combinations enforced

### HTTPS Requirements

- **Production Deployment**: Always use HTTPS in production
- **Redirect URI Security**: Validate redirect URIs against security policies
- **CORS Configuration**: Proper CORS headers for cross-origin requests

### Rate Limiting

Consider implementing rate limiting for the registration endpoint to prevent abuse:

```php
// Example rate limiting implementation
function simple_oauth_client_registration_rate_limit() {
  $ip = \Drupal::request()->getClientIp();
  $cache = \Drupal::cache();
  $key = 'oauth_registration_rate_limit:' . $ip;

  $count = $cache->get($key);
  if (!$count) {
    $cache->set($key, 1, time() + 3600); // 1 hour window
  } else {
    if ($count->data >= 10) { // 10 registrations per hour
      throw new \Exception('Rate limit exceeded');
    }
    $cache->set($key, $count->data + 1, $count->expire);
  }
}
```

## Troubleshooting

### Common Issues

#### Registration Endpoint Not Found (404)

**Cause**: Module not properly installed or routes not cleared.

**Solution**:
```bash
drush cache:rebuild
drush router:rebuild
```

#### Invalid Client Metadata Error

**Cause**: Missing required fields or invalid data format.

**Solution**: Verify request format matches RFC 7591 specification:
- `redirect_uris` must be a non-empty array
- URI fields must be valid HTTP/HTTPS URLs
- Email addresses must be properly formatted

#### Registration Access Token Invalid

**Cause**: Token expired, client deleted, or token corruption.

**Solution**:
- Verify token hasn't expired (1-year default)
- Check if client still exists in the system
- Re-register client if token is permanently lost

#### Database Connection Errors

**Cause**: Token table not created or database permissions.

**Solution**:
```bash
drush updatedb
drush entity:updates
```

### Debug Information

Enable debug logging for detailed error information:

```php
// In settings.php
$config['system.logging']['error_level'] = 'verbose';

// Check logs
drush watchdog:show --type=simple_oauth_client_registration
```

### Module Conflicts

#### Serialization Module

Ensure the core `serialization` module is enabled for JSON handling.

#### Consumer Entity Modifications

If using custom consumer entity modifications, ensure compatibility with RFC 7591 fields.

## Testing

### Endpoint Testing

Test the registration endpoint functionality:

```bash
# Test registration endpoint
curl -v -X POST https://your-site.com/oauth/register \
  -H "Content-Type: application/json" \
  -d '{"client_name": "Test Client", "redirect_uris": ["https://example.com/callback"]}'

# Test client retrieval
curl -v -X GET https://your-site.com/oauth/register/CLIENT_ID \
  -H "Authorization: Bearer REGISTRATION_ACCESS_TOKEN"
```

### Integration Testing

Verify integration with other OAuth modules:

1. **Server Metadata**: Check that registration endpoint appears in `/.well-known/oauth-authorization-server`
2. **PKCE Module**: Verify PKCE requirements work with dynamically registered clients
3. **Native Apps**: Test enhanced validation for native application registrations

## Performance Considerations

### Database Optimization

- **Token Cleanup**: Implement regular cleanup of expired tokens
- **Indexing**: Ensure proper indexes on client_id and expires columns
- **Connection Pooling**: Use database connection pooling for high-traffic sites

### Caching Strategy

- **Client Metadata**: Consider caching frequently accessed client metadata
- **Registration Tokens**: Token validation involves database queries - consider caching for active tokens

### Monitoring

Monitor registration endpoint performance:

```php
// Example monitoring
$start_time = microtime(true);
// ... registration logic ...
$end_time = microtime(true);
$duration = $end_time - $start_time;

\Drupal::logger('oauth_performance')->info('Registration took @duration seconds', [
  '@duration' => number_format($duration, 3)
]);
```

## Standards Compliance

### RFC 7591 Compliance

This module implements RFC 7591 "OAuth 2.0 Dynamic Client Registration Protocol" with full compliance including:

- ✅ Client Registration Request (Section 2)
- ✅ Client Registration Response (Section 3)
- ✅ Client Update Request (Section 4)
- ✅ Client Configuration Response (Section 5)
- ✅ Client Delete Request (Section 6)
- ✅ Client Registration Endpoint Discovery (Section 8)
- ✅ Client Registration Error Response (Section 9)

### Integration with Other RFCs

- **RFC 6749**: OAuth 2.0 Authorization Framework
- **RFC 7636**: PKCE for OAuth Public Clients
- **RFC 8252**: OAuth 2.0 for Native Apps
- **RFC 8414**: OAuth 2.0 Authorization Server Metadata

## Contributing

When contributing to this module:

1. **Follow RFC Standards**: Ensure all changes maintain RFC 7591 compliance
2. **Test Coverage**: Add tests for new functionality
3. **Documentation**: Update this README for significant changes
4. **Security Review**: All security-related changes require careful review

## License

This module is licensed under the GNU General Public License v2.0 or later.

## Support

- **Issue Queue**: Report bugs and feature requests in the Drupal.org issue queue
- **Documentation**: Additional documentation available on Drupal.org
- **Community**: Get help from the Drupal OAuth community

---

**Version**: Compatible with Drupal 10.x and 11.x
**Maintained by**: Simple OAuth 2.1 project maintainers
**Last Updated**: September 2025