# Simple OAuth 2.1

A comprehensive OAuth 2.1 compliance module ecosystem that provides centralized coordination and assessment of OAuth 2.1 implementation across Drupal's OAuth ecosystem. This ecosystem consists of 6 interconnected modules implementing multiple OAuth RFCs.

## Overview

The Simple OAuth 2.1 module ecosystem serves as a comprehensive OAuth 2.1 compliance solution through an umbrella coordination module and 5 specialized sub-modules. It provides a real-time compliance dashboard, implements 6 OAuth RFCs, and offers complete OAuth 2.1 functionality including device flows, dynamic client registration, and enhanced security features.

![Dashboard](./assets/simple_oauth_21.png)

### OAuth 2.1 Compliance

OAuth 2.1 represents the next evolution of the OAuth 2.0 standard, consolidating security best practices and eliminating deprecated flows. This module coordinates compliance with:

- **Mandatory PKCE**: Proof Key for Code Exchange for all authorization code flows
- **Enhanced Security**: Elimination of deprecated implicit grant flow
- **Native App Security**: RFC 8252 compliance for mobile and desktop applications
- **Server Metadata**: RFC 8414 automatic discovery capabilities

## OAuth RFC Implementation Matrix

This module ecosystem provides complete compliance with 6 OAuth RFCs:

| RFC          | Standard                      | Module                             | Implementation                                   |
| ------------ | ----------------------------- | ---------------------------------- | ------------------------------------------------ |
| **RFC 7591** | Dynamic Client Registration   | `simple_oauth_client_registration` | Full CRUD operations, metadata support           |
| **RFC 7636** | PKCE                          | `simple_oauth_pkce`                | S256/plain methods, enforcement levels           |
| **RFC 8252** | OAuth for Native Apps         | `simple_oauth_native_apps`         | WebView detection, custom schemes, loopback      |
| **RFC 8414** | Authorization Server Metadata | `simple_oauth_server_metadata`     | Full metadata endpoint, capability advertisement |
| **RFC 8628** | Device Authorization Grant    | `simple_oauth_device_flow`         | Device codes, user verification, polling         |
| **RFC 9728** | Protected Resource Metadata   | `simple_oauth_server_metadata`     | Resource discovery metadata                      |

### Complete API Endpoints

**Server Discovery:**

- `/.well-known/oauth-authorization-server` - Authorization server metadata (RFC 8414)
- `/.well-known/oauth-protected-resource` - Protected resource metadata (RFC 9728)
- `/.well-known/openid-configuration` - OpenID Connect discovery

**Dynamic Client Registration:**

- `/oauth/register` (POST) - Client registration
- `/oauth/register/{client_id}` (GET/PUT/DELETE) - Client management

**Device Authorization Flow:**

- `/oauth/device_authorization` (POST) - Device authorization requests
- `/oauth/device` (GET/POST) - User verification interface

## Features

### Compliance Dashboard

Access the comprehensive compliance dashboard at:
**Administration → Configuration → People → Simple OAuth → OAuth 2.1 Dashboard**

The dashboard provides:

- **Overall Compliance Status**: Visual assessment with clear compliance indicators
- **Score Breakdown**: Detailed scoring across three categories:
  - **Core Requirements** (Mandatory): Essential OAuth 2.1 compliance features
  - **Server Metadata** (Required): RFC 8414 discovery and metadata
  - **Best Practices** (Recommended): Additional security enhancements
- **Critical Issues**: Identification of mandatory requirements that block compliance
- **Actionable Recommendations**: Direct links to configuration pages for missing features
- **Real-time Assessment**: Dynamic compliance monitoring with cache management

### Intelligent Module Detection

The module supports flexible deployment patterns:

- **Submodule Installation**: Integrated submodules within the simple_oauth_21 package
- **Standalone Modules**: Individual contrib modules (simple_oauth_pkce, etc.)
- **Mixed Environments**: Combination of submodules and standalone installations
- **Graceful Degradation**: Partial compliance assessment when modules are missing

## Module Architecture

### Dependency Hierarchy

The Simple OAuth 2.1 ecosystem follows a clear dependency hierarchy:

```
simple_oauth_21 (umbrella)
├── simple_oauth (core OAuth)
├── drupal:system (core)
└── Sub-modules:
    ├── simple_oauth_device_flow
    │   ├── simple_oauth_21
    │   ├── simple_oauth
    │   └── consumers
    ├── simple_oauth_pkce
    │   ├── simple_oauth_21
    │   └── simple_oauth
    ├── simple_oauth_native_apps
    │   ├── simple_oauth_21
    │   └── simple_oauth
    ├── simple_oauth_client_registration
    │   ├── simple_oauth_21
    │   ├── simple_oauth
    │   ├── consumers
    │   └── serialization
    └── simple_oauth_server_metadata
        ├── simple_oauth_21
        └── simple_oauth
```

### Cross-Module Integration

- **Compliance Service Integration:** All modules integrate with the main compliance service for real-time assessment
- **Configuration Coordination:** Umbrella module provides centralized configuration navigation
- **Service Discovery:** Server metadata module advertises capabilities from other modules
- **Enhanced Detection:** Intelligent module detection supports both sub-module and standalone installations

## Module Ecosystem (6 Modules)

The Simple OAuth 2.1 ecosystem consists of 1 umbrella module and 5 specialized sub-modules:

### 1. simple_oauth_21 (Main Module)

**Purpose:** Umbrella coordination module and compliance dashboard

- OAuth 2.1 compliance assessment engine with real-time monitoring
- Comprehensive compliance dashboard with scoring and recommendations
- Centralized configuration navigation for all sub-modules
- Intelligent module detection (supports both sub-modules and standalone installations)
- **Admin Route:** `/admin/config/people/simple_oauth/oauth-21`

### 2. simple_oauth_device_flow

**RFC Implementation:** RFC 8628 OAuth 2.0 Device Authorization Grant

- Complete device authorization flow for TVs, IoT devices, and CLI applications
- Device code generation and user verification interface
- Configurable polling intervals and code lifetimes
- User-friendly verification workflow with accessibility features
- **Admin Route:** `/admin/config/people/simple_oauth/oauth-21/device-flow`
- **Dependencies:** `simple_oauth_21`, `simple_oauth`, `consumers`

### 3. simple_oauth_pkce

**RFC Implementation:** RFC 7636 PKCE (Proof Key for Code Exchange)

- Mandatory OAuth 2.1 PKCE enforcement with configurable levels
- S256 and plain challenge method support
- Authorization code interception protection
- Integration with compliance dashboard for real-time validation
- **Admin Route:** `/admin/config/people/simple_oauth/oauth-21/pkce`
- **Dependencies:** `simple_oauth_21`, `simple_oauth`

### 4. simple_oauth_native_apps

**RFC Implementation:** RFC 8252 OAuth for Native Apps

- WebView detection and blocking for enhanced security
- Custom URI schemes and loopback redirect support
- Enhanced PKCE requirements specifically for native applications
- Exact redirect URI matching for improved security
- Client configuration enhancement for native app settings
- **Admin Route:** `/admin/config/people/simple_oauth/oauth-21/native-apps`
- **Dependencies:** `simple_oauth_21`, `simple_oauth`

### 5. simple_oauth_server_metadata

**RFC Implementations:** RFC 8414 (Authorization Server Metadata), RFC 9728 (Protected Resource Metadata)

- Automatic server capability discovery and advertisement
- Complete metadata endpoints for client auto-configuration
- OpenID Connect discovery support
- Extended metadata fields and custom capability advertisement
- **Admin Route:** `/admin/config/people/simple_oauth/oauth-21/server-metadata`
- **Dependencies:** `simple_oauth_21`, `simple_oauth`

### 6. simple_oauth_client_registration

**RFC Implementation:** RFC 7591 Dynamic Client Registration

- Full dynamic client lifecycle management (CRUD operations)
- RFC 7591 compliant client metadata support
- Automatic client configuration and credential generation
- Bulk operations and credential rotation support
- **Dependencies:** `simple_oauth_21`, `simple_oauth`, `consumers`, `serialization`

## Installation

### Prerequisites

- **Drupal:** 10.2+ or 11.x
- **PHP:** 8.1+ (recommended 8.3+)
- **Required Modules:** `simple_oauth` (will be installed automatically)
- **Optional Modules:** `consumers` (for device flow and client registration), `serialization` (for client registration API)

### Installation via Composer

```bash
composer require e0ipso/simple_oauth_21
```

### Enable Modules

Enable the umbrella module and desired sub-modules:

```bash
# Enable all modules for complete OAuth 2.1 compliance
drush pm:enable simple_oauth_21 simple_oauth_device_flow simple_oauth_pkce simple_oauth_native_apps simple_oauth_server_metadata simple_oauth_client_registration

# Or enable selectively based on your needs
drush pm:enable simple_oauth_21 simple_oauth_pkce simple_oauth_server_metadata
```

### Post-Installation

```bash
# Clear caches to ensure proper module integration
drush cache:rebuild

# Access the compliance dashboard
# Navigate to: /admin/config/people/simple_oauth/oauth-21
```

## Configuration

### Basic Setup

1. **Install Dependencies**: Ensure `simple_oauth` and optional dependencies are installed
2. **Access Dashboard**: Navigate to `/admin/config/people/simple_oauth/oauth-21`
3. **Review Compliance**: Check overall compliance status and address critical issues
4. **Configure Sub-modules**: Use dashboard links to configure individual sub-modules
5. **Verify Integration**: Test OAuth endpoints and compliance assessment

### Compliance Levels

**Fully Compliant**: All mandatory and required features enabled

- PKCE with S256 challenge method
- Implicit grant disabled
- Server metadata endpoint active
- All critical security features enabled

**Mostly Compliant**: Core requirements met with minor recommendations

- Essential OAuth 2.1 features active
- Some recommended features may be missing

**Partially Compliant**: Basic functionality with compliance gaps

- Some mandatory features missing
- Security vulnerabilities may exist

**Non-Compliant**: Critical OAuth 2.1 requirements missing

- PKCE not enabled or improperly configured
- Deprecated flows still active
- Security requirements not met

### Permissions

- **administer simple_oauth entities**: Required to access the compliance dashboard and configuration
- **access simple_oauth_21 compliance dashboard**: View-only access to compliance status
- Individual sub-modules may have additional permission requirements:
  - Device flow: May require permissions for device verification interface
  - Client registration: API access permissions for dynamic registration
  - Server metadata: Public endpoint access (no special permissions needed)

## OAuth 2.1 Implementation Guide

<details>
<summary>Step-by-step Configuration</summary>

### Step 1: Core Requirements

1. Enable `simple_oauth_pkce` module
2. Configure PKCE enforcement to "mandatory"
3. Enable S256 challenge method
4. Disable plain challenge method (production)
5. Verify implicit grant is disabled

### Step 2: Server Metadata (Recommended)

1. Enable `simple_oauth_server_metadata` module
2. Configure optional endpoints (revocation, introspection)
3. Add service documentation URLs
4. Test `/.well-known/oauth-authorization-server` endpoint

### Step 3: Native App Security (If Applicable)

1. Enable `simple_oauth_native_apps` module
2. Configure WebView detection policy
3. Enable custom URI schemes
4. Enable loopback redirects
5. Enable exact redirect URI matching

### Step 4: Device Flow (If Applicable)

1. Enable `simple_oauth_device_flow` module
2. Configure device code lifetime and polling intervals
3. Test device authorization workflow
4. Configure user verification interface

### Step 5: Dynamic Client Registration (Optional)

1. Enable `simple_oauth_client_registration` module
2. Configure registration endpoint permissions
3. Test `/oauth/register` endpoint functionality
4. Configure client metadata requirements

### Step 6: Verification

1. Access compliance dashboard at `/admin/config/people/simple_oauth/oauth-21`
2. Verify "Fully Compliant" status with green indicators
3. Address any remaining recommendations from the dashboard
4. Test OAuth flows with real clients
5. Verify all enabled endpoints are responding correctly:
   - `/.well-known/oauth-authorization-server`
   - `/oauth/device_authorization` (if device flow enabled)
   - `/oauth/register` (if client registration enabled)

</details>

## Security Features

### OAuth 2.1 Security Enhancements

This module ecosystem implements comprehensive OAuth 2.1 security features:

#### Mandatory PKCE (RFC 7636)

- **S256 Challenge Method**: SHA256-based challenge for authorization code protection
- **Enforced by Default**: PKCE is mandatory for all authorization code flows
- **Authorization Code Interception Protection**: Prevents code theft attacks

#### Native App Security (RFC 8252)

- **WebView Detection**: Blocks embedded browser usage for improved security
- **Custom URI Schemes**: Support for app-specific redirect URIs
- **Loopback Redirects**: Secure local redirects for desktop applications
- **Enhanced PKCE**: Additional PKCE requirements for native applications

#### Deprecated Flow Prevention

- **Implicit Grant Disabled**: OAuth 2.1 eliminates insecure implicit flow
- **Legacy Flow Migration**: Guidance for transitioning from deprecated flows

#### Dynamic Security Features

- **Real-time Compliance Assessment**: Continuous security posture monitoring
- **Configuration Validation**: Automatic detection of security misconfigurations
- **Best Practice Enforcement**: Automated enforcement of OAuth 2.1 security requirements

### Security Best Practices

1. **Always Enable PKCE**: Ensure PKCE is configured to "mandatory" for production
2. **Use S256 Challenge Method**: Disable plain text challenge method in production
3. **Configure Native App Settings**: Enable WebView detection for mobile/desktop clients
4. **Monitor Compliance Dashboard**: Regularly review security recommendations
5. **Keep Dependencies Updated**: Maintain current versions of OAuth modules
6. **Implement Proper Scopes**: Use minimal necessary scopes for client applications

## Documentation

### Usage Examples

#### Testing Device Flow (RFC 8628)

```bash
# 1. Start device authorization
curl -X POST https://your-site.com/oauth/device_authorization \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "client_id=your_client_id&scope=basic"

# 2. User visits verification_uri and enters user_code
# 3. Poll for access token
curl -X POST https://your-site.com/oauth/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "grant_type=urn:ietf:params:oauth:grant-type:device_code&device_code=returned_device_code&client_id=your_client_id"
```

#### Testing Dynamic Client Registration (RFC 7591)

```bash
# Register a new client
curl -X POST https://your-site.com/oauth/register \
  -H "Content-Type: application/json" \
  -d '{
    "client_name": "My Application",
    "redirect_uris": ["https://myapp.com/callback"],
    "grant_types": ["authorization_code"],
    "response_types": ["code"]
  }'

# Retrieve client information
curl -X GET https://your-site.com/oauth/register/client_id_here \
  -H "Authorization: Bearer registration_access_token"
```

#### Testing Server Discovery (RFC 8414)

```bash
# Authorization server metadata
curl https://your-site.com/.well-known/oauth-authorization-server

# Protected resource metadata
curl https://your-site.com/.well-known/oauth-protected-resource

# OpenID Connect discovery
curl https://your-site.com/.well-known/openid-configuration
```

### Additional Resources

- **[API Documentation](./API.md)**: Comprehensive API reference for all OAuth RFC compliance endpoints
- **[Migration Guide](./MIGRATION.md)**: Step-by-step guide for upgrading existing installations
- **[Module Help](/admin/help/simple_oauth_client_registration)**: In-context help for client registration features
- **[Discovery Report](./DISCOVERY_REPORT.md)**: Complete technical analysis of module ecosystem functionality

## Troubleshooting

### Common Issues

**Q: Dashboard shows "Non-Compliant" despite enabling sub-modules**
A:

1. Clear all caches: `drush cache:rebuild`
2. Verify module dependencies are met (check `consumers` and `serialization` modules)
3. Check module status: `drush pm:list --status=enabled | grep oauth`
4. Review module interdependencies in admin interface

**Q: Device flow endpoints not working**
A:

1. Ensure `consumers` module is enabled: `drush pm:enable consumers`
2. Verify device flow configuration at `/admin/config/people/simple_oauth/oauth-21/device-flow`
3. Check that device authorization endpoint is accessible: `curl -X POST /oauth/device_authorization`

**Q: Client registration API returning errors**
A:

1. Enable `serialization` module: `drush pm:enable serialization`
2. Verify client registration permissions
3. Test endpoint: `curl -X POST /oauth/register -H "Content-Type: application/json"`

**Q: Server metadata endpoints not found**
A:

1. Clear routing cache: `drush cache:rebuild`
2. Verify server metadata module is enabled
3. Test discovery endpoints:
   - `curl /.well-known/oauth-authorization-server`
   - `curl /.well-known/oauth-protected-resource`

**Q: PKCE validation failures**
A:

1. Check PKCE configuration at `/admin/config/people/simple_oauth/oauth-21/pkce`
2. Verify S256 challenge method is enabled
3. Ensure clients are sending proper PKCE parameters
4. Check native apps configuration if using mobile/desktop clients

**Q: Performance issues with compliance checking**
A:

1. Verify that caching is enabled in Drupal configuration
2. Consider increasing cache lifetime for stable configurations
3. Monitor compliance service performance in webprofiler (if enabled)

**Q: Module compatibility issues after updates**
A:

1. Update all OAuth-related modules: `composer update 'e0ipso/*' 'drupal/simple_oauth'`
2. Run database updates: `drush updatedb`
3. Clear all caches: `drush cache:rebuild`
4. Review module interdependency status in compliance dashboard

### Debug Mode

Enable detailed logging by setting the log level to DEBUG for the 'simple_oauth_21' channel:

```php
\Drupal::logger('simple_oauth_21')->debug('Debug message');
```
