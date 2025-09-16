# Simple OAuth Server Metadata

Provides RFC 8414 Authorization Server Metadata implementation for automatic OAuth server discovery and client configuration with comprehensive metadata endpoint support.

## Overview

The Simple OAuth Server Metadata module implements RFC 8414 "OAuth 2.0 Authorization Server Metadata" to provide automatic discovery capabilities for OAuth clients. It exposes a standardized `/.well-known/oauth-authorization-server` endpoint that advertises server capabilities, supported features, and endpoint URLs.

### RFC 8414 Compliance

RFC 8414 defines a standard mechanism for OAuth clients to discover authorization server capabilities. This module provides:

- **Well-Known Endpoint**: Standard `/.well-known/oauth-authorization-server` endpoint
- **Automatic Discovery**: Enables clients to automatically configure OAuth settings
- **Capability Advertisement**: Exposes supported grant types, response types, and extensions
- **Extensible Metadata**: Support for custom metadata fields and OAuth extensions

## Features

### Core Metadata Endpoint
- **Standard Endpoint**: RFC 8414 compliant `/.well-known/oauth-authorization-server`
- **JSON Response**: Properly formatted OAuth server metadata
- **Caching**: Optimized performance with intelligent cache invalidation
- **CORS Support**: Cross-origin request support for browser-based clients
- **Error Handling**: Graceful degradation with proper HTTP status codes

### Automatic Discovery Services
- **Endpoint Discovery**: Automatically discovers OAuth endpoints (authorization, token, etc.)
- **Grant Type Discovery**: Detects and advertises supported grant types
- **Scope Discovery**: Discovers and lists available OAuth scopes
- **Authentication Discovery**: Advertises supported client authentication methods

### Configurable Metadata
- **Optional Endpoints**: Configurable revocation, introspection, and registration endpoints
- **Service Information**: Service documentation and policy URIs
- **Localization**: Supported UI locales configuration
- **Custom Claims**: Additional claims and signing algorithms
- **Extension Support**: Framework for custom OAuth extensions

### Administrative Interface
- **Dedicated Configuration**: Professional admin interface with metadata guidance
- **Validation**: Real-time metadata validation and RFC compliance checking
- **Preview**: Live preview of metadata endpoint response
- **Integration**: Direct access from compliance dashboard

## Installation

### Requirements
- Drupal 9.0+ or Drupal 10.0+
- Simple OAuth module (contrib or core)
- PHP 8.1+

### Installation Steps
1. Enable the module: `drush pm:enable simple_oauth_server_metadata`
2. Configure metadata: Navigate to **Administration → Configuration → People → Simple OAuth → Server Metadata Settings**
3. Test endpoint: Visit `/.well-known/oauth-authorization-server` to verify metadata

## Configuration

### Access Configuration
Navigate to: **Administration → Configuration → People → Simple OAuth → Server Metadata Settings**
(`/admin/config/people/simple_oauth/oauth-21/server-metadata`)

### Configuration Options

#### Optional Endpoints
```yaml
registration_endpoint: ''           # RFC 7591 Client Registration
revocation_endpoint: ''            # RFC 7009 Token Revocation
introspection_endpoint: ''         # RFC 7662 Token Introspection
```

#### Service Information
```yaml
service_documentation: ''         # Human-readable documentation URL
op_policy_uri: ''                 # Operator policy document URL
op_tos_uri: ''                    # Terms of service URL
```

#### Localization and Extensions
```yaml
ui_locales_supported: []          # Supported UI locales (e.g., ['en', 'es'])
additional_claims_supported: []    # Custom claims beyond defaults
additional_signing_algorithms: []  # JWT algorithms beyond RS256
```

### Production Configuration Example
```yaml
registration_endpoint: '/oauth/register'
revocation_endpoint: '/oauth/revoke'
introspection_endpoint: '/oauth/introspect'
service_documentation: 'https://yoursite.com/docs/oauth'
op_policy_uri: 'https://yoursite.com/oauth-policy'
op_tos_uri: 'https://yoursite.com/terms'
ui_locales_supported: ['en', 'es', 'fr']
```

## Metadata Endpoint

### Endpoint URL
```
https://your-drupal-site.com/.well-known/oauth-authorization-server
```

### Response Format
The endpoint returns JSON metadata conforming to RFC 8414:

```json
{
  "issuer": "https://your-drupal-site.com",
  "authorization_endpoint": "https://your-drupal-site.com/oauth/authorize",
  "token_endpoint": "https://your-drupal-site.com/oauth/token",
  "response_types_supported": ["code"],
  "grant_types_supported": ["authorization_code", "refresh_token"],
  "scopes_supported": ["read", "write", "admin"],
  "token_endpoint_auth_methods_supported": ["client_secret_basic", "client_secret_post"],
  "code_challenge_methods_supported": ["S256", "plain"],
  "revocation_endpoint": "https://your-drupal-site.com/oauth/revoke",
  "introspection_endpoint": "https://your-drupal-site.com/oauth/introspect",
  "service_documentation": "https://your-drupal-site.com/docs/oauth"
}
```

### Automatic Fields
The following fields are automatically discovered and included:

#### Required Fields
- **issuer**: Server base URL
- **authorization_endpoint**: OAuth authorization endpoint
- **token_endpoint**: Token exchange endpoint

#### Discovered Fields
- **response_types_supported**: Available response types
- **grant_types_supported**: Enabled grant types
- **scopes_supported**: Available OAuth scopes
- **token_endpoint_auth_methods_supported**: Client authentication methods
- **code_challenge_methods_supported**: PKCE challenge methods (if PKCE enabled)

#### Optional Fields (Configurable)
- **registration_endpoint**: Client registration endpoint
- **revocation_endpoint**: Token revocation endpoint
- **introspection_endpoint**: Token introspection endpoint
- **service_documentation**: Documentation URL
- **op_policy_uri**: Operator policy URI
- **op_tos_uri**: Terms of service URI
- **ui_locales_supported**: Supported locales
- **claims_supported**: Additional claims
- **id_token_signing_alg_values_supported**: Additional signing algorithms

## Client Implementation

### Automatic Discovery
OAuth clients can use the metadata endpoint for automatic configuration:

#### JavaScript Example
```javascript
async function discoverOAuthConfig(issuerUrl) {
  const metadataUrl = `${issuerUrl}/.well-known/oauth-authorization-server`;
  const response = await fetch(metadataUrl);
  const metadata = await response.json();

  return {
    authorizationEndpoint: metadata.authorization_endpoint,
    tokenEndpoint: metadata.token_endpoint,
    revocationEndpoint: metadata.revocation_endpoint,
    supportedGrantTypes: metadata.grant_types_supported,
    supportedScopes: metadata.scopes_supported,
    supportsPKCE: metadata.code_challenge_methods_supported?.includes('S256')
  };
}

// Usage
const config = await discoverOAuthConfig('https://your-drupal-site.com');
```

#### Python Example
```python
import requests

def discover_oauth_config(issuer_url):
    metadata_url = f"{issuer_url}/.well-known/oauth-authorization-server"
    response = requests.get(metadata_url)
    metadata = response.json()

    return {
        'authorization_endpoint': metadata['authorization_endpoint'],
        'token_endpoint': metadata['token_endpoint'],
        'supported_grant_types': metadata['grant_types_supported'],
        'supported_scopes': metadata['scopes_supported'],
        'supports_pkce': 'S256' in metadata.get('code_challenge_methods_supported', [])
    }

# Usage
config = discover_oauth_config('https://your-drupal-site.com')
```

### Client Library Integration

#### AppAuth Libraries
Most AppAuth libraries support automatic discovery:

```javascript
// AppAuth-JS
import { AuthorizationServiceConfiguration } from '@openid/appauth';

const serviceConfiguration = await AuthorizationServiceConfiguration
  .fetchFromIssuer('https://your-drupal-site.com');
```

```swift
// AppAuth-iOS
OIDAuthorizationService.discoverConfiguration(forIssuer: issuerUrl) { configuration, error in
    // Use discovered configuration
}
```

```java
// AppAuth-Android
AuthorizationServiceConfiguration.fetchFromIssuer(issuerUri, callback);
```

## Technical Implementation

### Architecture

**ServerMetadataController**: HTTP endpoint controller with proper headers and caching
**ServerMetadataService**: Core metadata generation with discovery services
**Discovery Services**: Specialized services for each metadata category
**Cache Management**: Intelligent caching with automatic invalidation

### Discovery Services

#### EndpointDiscoveryService
Discovers OAuth endpoints by analyzing:
- Route definitions
- Enabled modules
- Configuration settings
- URL generation

#### GrantTypeDiscoveryService
Detects supported grant types through:
- Simple OAuth configuration
- Module availability
- Security policies
- Client configurations

#### ScopeDiscoveryService
Discovers available scopes via:
- Drupal entity analysis
- Module-defined scopes
- Custom scope definitions
- Permission mappings

#### ClaimsAuthDiscoveryService
Identifies authentication methods by:
- Client authentication settings
- Security configurations
- Module capabilities
- Extension support

### Caching Strategy

**Cache Duration**: 1 hour default (configurable)
**Cache Tags**: Automatic invalidation on configuration changes
**Performance**: Minimal overhead with efficient discovery algorithms
**Scalability**: Optimized for high-traffic OAuth servers

### URL Handling
- **Absolute URLs**: Automatic conversion from relative to absolute URLs
- **Base URL Detection**: Intelligent base URL discovery
- **HTTPS Enforcement**: Automatic HTTPS URL generation when appropriate
- **Port Handling**: Proper handling of non-standard ports

## Security Considerations

### Information Disclosure
- **Controlled Exposure**: Only exposes intentionally configured metadata
- **Sensitive Information**: Avoids exposing internal system details
- **Scope Limitation**: Only lists scopes available to OAuth clients
- **Endpoint Validation**: Validates all exposed endpoints before inclusion

### CORS and Headers
- **Proper CORS**: Configured for cross-origin client access
- **Cache Headers**: Appropriate caching headers for performance
- **Content Type**: Correct JSON content type headers
- **Security Headers**: Additional security headers as needed

### Production Security Checklist
- [ ] HTTPS enforced for metadata endpoint
- [ ] Only necessary endpoints configured
- [ ] Service documentation URLs point to public resources
- [ ] No sensitive information exposed in metadata
- [ ] Proper CORS configuration for client domains
- [ ] Cache settings appropriate for environment

## Integration with OAuth Ecosystem

### Simple OAuth Integration
- **Automatic Discovery**: Detects Simple OAuth configuration and endpoints
- **Grant Type Detection**: Discovers enabled grant types from Simple OAuth
- **Scope Integration**: Integrates with Simple OAuth scope definitions
- **Client Authentication**: Discovers supported authentication methods

### PKCE Integration
- **Challenge Methods**: Advertises supported PKCE challenge methods
- **Capability Detection**: Automatically detects PKCE module availability
- **Method Support**: Lists S256 and plain methods based on configuration

### OpenID Connect
- **OIDC Compatibility**: Compatible with OpenID Connect discovery when available
- **Claim Support**: Supports additional claims for OIDC implementations
- **Algorithm Support**: Advertises supported signing algorithms

### Extension Support
- **Custom Endpoints**: Framework for adding custom endpoint discovery
- **Metadata Extensions**: Support for OAuth extension metadata
- **Plugin System**: Extensible discovery service architecture

## Troubleshooting

### Common Issues

**"Metadata endpoint not accessible"**
- Cause: Module not enabled or configuration incomplete
- Solution: Verify module is enabled and configuration is saved

**"Missing endpoints in metadata"**
- Cause: Endpoints not configured or modules not enabled
- Solution: Configure optional endpoints or enable required modules

**"CORS errors in browser clients"**
- Cause: CORS not properly configured for client domains
- Solution: Verify CORS settings and allowed origins

**"Outdated metadata after configuration changes"**
- Cause: Cache not invalidated after configuration updates
- Solution: Clear caches or wait for automatic cache expiration

### Debug Information

Enable debug logging to troubleshoot discovery issues:
```yaml
# Configuration for detailed logging
logging:
  level: 'debug'
  channels:
    - 'simple_oauth_server_metadata'
    - 'endpoint_discovery'
```

Example debug log entries:
```
server_metadata: Discovered authorization endpoint: /oauth/authorize
server_metadata: Grant type 'authorization_code' detected and enabled
server_metadata: PKCE challenge methods: S256, plain
server_metadata: Metadata cache invalidated due to configuration change
```

### Testing the Endpoint

#### Manual Testing
```bash
# Test metadata endpoint
curl -H "Accept: application/json" \
  https://your-drupal-site.com/.well-known/oauth-authorization-server

# Validate JSON response
curl -s https://your-drupal-site.com/.well-known/oauth-authorization-server | jq .

# Check specific fields
curl -s https://your-drupal-site.com/.well-known/oauth-authorization-server | \
  jq '.authorization_endpoint'
```

#### Automated Testing
```bash
# Run server metadata tests
vendor/bin/phpunit modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/tests/

# Test endpoint discovery
vendor/bin/phpunit --filter EndpointDiscoveryTest

# Test metadata generation
vendor/bin/phpunit --filter ServerMetadataTest
```

## Standards Compliance

This module implements:
- **RFC 8414**: OAuth 2.0 Authorization Server Metadata (complete implementation)
- **RFC 7591**: Dynamic Client Registration endpoint advertisement
- **RFC 7009**: Token Revocation endpoint advertisement
- **RFC 7662**: Token Introspection endpoint advertisement

### RFC 8414 Compliance Checklist
- ✅ Section 2: Authorization Server Metadata Request
- ✅ Section 3: Authorization Server Metadata Response
- ✅ Section 3.1: Required metadata parameters
- ✅ Section 3.2: Optional metadata parameters
- ✅ Section 4: Obtaining Authorization Server Metadata

### Metadata Parameter Support

#### Required Parameters (RFC 8414)
- ✅ **issuer**: Authorization server identifier
- ✅ **authorization_endpoint**: Authorization endpoint URL
- ✅ **token_endpoint**: Token endpoint URL (if applicable)
- ✅ **response_types_supported**: Supported response types

#### Optional Parameters (RFC 8414)
- ✅ **grant_types_supported**: Supported grant types
- ✅ **scopes_supported**: Available scopes
- ✅ **token_endpoint_auth_methods_supported**: Authentication methods
- ✅ **revocation_endpoint**: Token revocation endpoint
- ✅ **introspection_endpoint**: Token introspection endpoint
- ✅ **code_challenge_methods_supported**: PKCE challenge methods
- ✅ **service_documentation**: Documentation URL
- ✅ **op_policy_uri**: Operator policy URI
- ✅ **op_tos_uri**: Terms of service URI
- ✅ **ui_locales_supported**: Supported UI locales

## Performance Considerations

### Caching Strategy
- **Smart Caching**: Configuration-aware cache invalidation
- **Performance**: Minimal impact on OAuth endpoint performance
- **Scalability**: Designed for high-traffic OAuth servers
- **Efficiency**: Optimized discovery algorithms

### Network Optimization
- **Compression**: Supports HTTP compression for metadata responses
- **CDN Friendly**: Appropriate cache headers for CDN deployment
- **Minimal Payload**: Efficient JSON structure with no unnecessary data
- **HTTP/2**: Compatible with modern HTTP protocols

### Database Impact
- **Read-Only**: Metadata generation is read-only operation
- **No Tables**: Uses existing Drupal configuration system
- **Minimal Queries**: Efficient database access patterns
- **Caching**: Reduces database load through intelligent caching

## Contributing

### Development Guidelines
- Follow Drupal coding standards for all code
- Include comprehensive tests for new discovery features
- Update documentation for new metadata parameters
- Ensure RFC 8414 compliance for all changes
- Consider performance impact of discovery algorithms

### Testing Requirements
- Unit tests for discovery services
- Kernel tests for metadata generation
- Functional tests for endpoint behavior
- Integration tests with Simple OAuth

### Extension Development
The module provides APIs for extending metadata discovery:
- **Discovery Service Interface**: For custom endpoint discovery
- **Metadata Extension Events**: For adding custom metadata
- **Cache Tag System**: For proper cache invalidation
- **Configuration Integration**: For additional configurable fields

## Support

- **Issue Queue**: Part of simple_oauth_21 project
- **Documentation**: RFC 8414 implementation and OAuth discovery guides
- **Standards**: RFC 8414 specification and OAuth working group resources

## License

GPL-2.0+