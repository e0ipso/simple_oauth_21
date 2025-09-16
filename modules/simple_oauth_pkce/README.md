# Simple OAuth PKCE

Provides RFC 7636 PKCE (Proof Key for Code Exchange) implementation for OAuth 2.1 compliance with configurable enforcement levels and challenge method support.

## Overview

The Simple OAuth PKCE module implements RFC 7636 PKCE for protecting OAuth authorization code flows against interception attacks. PKCE is mandatory in OAuth 2.1 for all authorization code flows, making this module essential for OAuth 2.1 compliance.

### OAuth 2.1 Compliance

OAuth 2.1 mandates PKCE for all authorization code flows to prevent authorization code interception attacks. This module provides:

- **Mandatory PKCE Enforcement**: Site-wide PKCE requirements for OAuth 2.1 compliance
- **S256 Challenge Method**: SHA256-based challenge method (OAuth 2.1 recommended)
- **Plain Method Support**: Legacy plain text method (configurable for backward compatibility)
- **Comprehensive Validation**: Detailed PKCE parameter validation and error reporting

## Features

### PKCE Implementation

- **RFC 7636 Compliant**: Complete implementation of the PKCE specification
- **Multiple Challenge Methods**: Support for both S256 (SHA256) and plain methods
- **Configurable Enforcement**: Three enforcement levels to match deployment requirements
- **Enhanced Security**: Protection against authorization code interception attacks

### Configuration Options

- **Enforcement Levels**:
  - `disabled`: PKCE not required (development only)
  - `optional`: PKCE accepted but not required (migration phase)
  - `mandatory`: PKCE required for all flows (OAuth 2.1 compliance)
- **Challenge Methods**:
  - **S256**: SHA256 hash method (recommended for production)
  - **Plain**: Plain text method (legacy client support)

### Administrative Interface

- **Dedicated Configuration**: Professional admin interface with security guidance
- **OAuth 2.1 Guidance**: Configuration recommendations for compliance
- **Validation Warnings**: Alerts for insecure configuration combinations
- **Integration Links**: Direct access from compliance dashboard

## Installation

### Requirements

- Drupal 9.0+ or Drupal 10.0+
- Simple OAuth module (contrib or core)
- PHP 8.1+ with hash extension (for S256 method)

### Installation Steps

1. Enable the module: `drush pm:enable simple_oauth_pkce`
2. Configure settings: Navigate to **Administration → Configuration → People → Simple OAuth → PKCE Settings**
3. Set enforcement level and challenge methods based on your requirements

## Configuration

### Access Configuration

Navigate to: **Administration → Configuration → People → Simple OAuth → PKCE Settings**
(`/admin/config/people/simple_oauth/oauth-21/pkce`)

### Configuration Options

#### Enforcement Level

```yaml
enforcement: 'mandatory' # disabled/optional/mandatory
```

- **disabled**: PKCE parameters ignored (not recommended)
- **optional**: PKCE validated if provided, not required
- **mandatory**: PKCE required for all authorization requests (OAuth 2.1)

#### Challenge Methods

```yaml
s256_enabled: true # Enable SHA256 challenge method
plain_enabled: false # Enable plain text challenge method
```

### OAuth 2.1 Recommended Configuration

For OAuth 2.1 compliance, use these settings:

```yaml
enforcement: 'mandatory'
s256_enabled: true
plain_enabled: false
```

### Migration Configuration

For migrating existing deployments:

```yaml
enforcement: 'optional'
s256_enabled: true
plain_enabled: true
```

## Implementation Guide

### Client Implementation

OAuth clients must implement PKCE by adding challenge parameters to authorization requests and verifier parameters to token requests.

#### Step 1: Generate Code Verifier

```javascript
// Generate cryptographically random string (43-128 characters)
const codeVerifier = base64url(randomBytes(32));
```

#### Step 2: Create Code Challenge

For S256 method:

```javascript
const codeChallenge = base64url(sha256(codeVerifier));
```

For plain method:

```javascript
const codeChallenge = codeVerifier;
```

#### Step 3: Authorization Request

```http
GET /oauth/authorize?
  response_type=code&
  client_id=your-client&
  redirect_uri=your-redirect&
  scope=your-scopes&
  code_challenge=generated-challenge&
  code_challenge_method=S256
```

#### Step 4: Token Request

```http
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code&
code=received-authorization-code&
client_id=your-client&
redirect_uri=your-redirect&
code_verifier=original-code-verifier
```

### Server-Side Validation

The module automatically validates PKCE parameters:

1. **Authorization Request Validation**:
   - Validates `code_challenge` format and length
   - Verifies `code_challenge_method` is supported
   - Stores challenge for later verification

2. **Token Request Validation**:
   - Validates `code_verifier` format and length
   - Verifies verifier matches stored challenge
   - Ensures challenge method consistency

## Security Considerations

### Attack Prevention

**Authorization Code Interception**:

- PKCE prevents use of intercepted authorization codes
- Attacker needs both code and verifier (only client knows verifier)

**Network Attacks**:

- Man-in-the-middle attackers cannot replay authorization codes
- Code challenge transmitted over HTTPS, verifier never transmitted

**Malicious Applications**:

- Prevents malicious apps from using intercepted codes
- Each authorization has unique challenge/verifier pair

### Production Security Checklist

- [ ] Enforcement set to "mandatory"
- [ ] S256 challenge method enabled
- [ ] Plain challenge method disabled (unless legacy clients require it)
- [ ] All OAuth clients updated to support PKCE
- [ ] HTTPS enforced for all OAuth endpoints
- [ ] Proper client secret management (for confidential clients)

## OAuth 2.1 Compliance

### Mandatory Requirements

OAuth 2.1 mandates PKCE for:

- **All Public Clients**: Mobile apps, SPAs, CLI tools
- **Native Applications**: Mobile and desktop apps
- **Single-Page Applications**: Browser-based JavaScript apps
- **Any Authorization Code Flow**: Including confidential clients

### Implementation Status

This module provides complete OAuth 2.1 PKCE compliance:

- ✅ Mandatory PKCE enforcement
- ✅ S256 challenge method support
- ✅ Proper parameter validation
- ✅ RFC 7636 compliance
- ✅ OAuth 2.1 security requirements

## Troubleshooting

### Common Issues

**"Missing PKCE parameters" Error**

- Cause: Client not sending `code_challenge` with enforcement = mandatory
- Solution: Update client to include PKCE parameters or change enforcement level

**"PKCE validation failed" Error**

- Cause: `code_verifier` doesn't match stored `code_challenge`
- Solution: Verify client PKCE implementation and challenge generation

**"Unsupported challenge method" Error**

- Cause: Client using method not enabled in configuration
- Solution: Enable required method or update client implementation

**Configuration Validation Warnings**

- Cause: Insecure configuration combinations detected
- Solution: Follow OAuth 2.1 recommendations (mandatory enforcement, S256 method)

### Debug Logging

Enable detailed PKCE logging:

1. Navigate to **Administration → Configuration → Development → Logging**
2. Set log level to DEBUG for 'simple_oauth_pkce' channel
3. Monitor logs at **Reports → Recent log messages**

Example log entries:

```
simple_oauth_pkce: PKCE validation successful for client [client_id]
simple_oauth_pkce: Code challenge method S256 validated
simple_oauth_pkce: Authorization code bound to PKCE challenge
```

### Testing PKCE Implementation

#### Manual Testing

1. Configure enforcement to "mandatory"
2. Attempt authorization without PKCE (should fail)
3. Test with valid PKCE parameters (should succeed)
4. Test with mismatched verifier (should fail)

#### Automated Testing

```bash
# Run PKCE-specific tests
vendor/bin/phpunit modules/contrib/simple_oauth_21/modules/simple_oauth_pkce/tests/
```

## Technical Implementation

### Architecture

**PkceSettingsForm**: Configuration form with validation and OAuth 2.1 guidance
**Event Subscribers**: Integration with OAuth authorization and token flows
**Validation Services**: PKCE parameter validation and challenge verification
**Configuration Management**: Secure storage and cache invalidation

### Code Challenge Methods

**S256 Method (Recommended)**:

```
code_challenge = base64url(sha256(code_verifier))
code_challenge_method = "S256"
```

**Plain Method (Legacy)**:

```
code_challenge = code_verifier
code_challenge_method = "plain"
```

### Integration Points

- **Simple OAuth Authorization**: Intercepts authorization requests for PKCE validation
- **Token Endpoint**: Validates code verifier against stored challenge
- **Server Metadata**: Advertises supported challenge methods
- **Compliance Dashboard**: Reports PKCE configuration status

## Standards Compliance

This module implements:

- **RFC 7636**: Proof Key for Code Exchange by OAuth Public Clients
- **OAuth 2.1 Draft**: Mandatory PKCE requirements
- **RFC 8414**: OAuth 2.0 Authorization Server Metadata (challenge methods)

### RFC 7636 Compliance

- ✅ Section 4.1: Client creates code verifier
- ✅ Section 4.2: Client creates code challenge
- ✅ Section 4.3: Client sends challenge in authorization request
- ✅ Section 4.4: Server stores challenge
- ✅ Section 4.5: Client sends verifier in token request
- ✅ Section 4.6: Server verifies code challenge

## Performance Considerations

### Caching

- Configuration settings cached for performance
- Cache automatically invalidated on configuration changes
- No additional database overhead for PKCE validation

### Computational Impact

- S256 method requires SHA256 computation (minimal overhead)
- Plain method has no computational overhead
- Challenge verification occurs only during token exchange

## Contributing

### Development Guidelines

- Follow Drupal coding standards
- Include comprehensive tests for new features
- Update documentation for configuration changes
- Consider security implications of all changes

### Testing Requirements

- Unit tests for validation logic
- Kernel tests for configuration management
- Functional tests for OAuth flow integration
- Security tests for attack prevention

## Support

- **Issue Queue**: Part of simple_oauth_21 project
- **Documentation**: OAuth 2.1 and PKCE implementation guides
- **Security**: Follow Drupal security reporting procedures for vulnerabilities

## License

GPL-2.0+
