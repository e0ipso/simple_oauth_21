# Simple OAuth 2.1

A comprehensive OAuth 2.1 compliance module that provides centralized coordination and assessment of OAuth 2.1 implementation across Drupal's OAuth ecosystem.

## Overview

The Simple OAuth 2.1 module serves as an umbrella coordination module that ensures OAuth 2.1 compliance through integration with specialized submodules. It provides a compliance dashboard that monitors and assesses OAuth 2.1 implementation status in real-time.

![Dashboard](./assets/simple_oauth_21.png)

### OAuth 2.1 Compliance

OAuth 2.1 represents the next evolution of the OAuth 2.0 standard, consolidating security best practices and eliminating deprecated flows. This module coordinates compliance with:

- **Mandatory PKCE**: Proof Key for Code Exchange for all authorization code flows
- **Enhanced Security**: Elimination of deprecated implicit grant flow
- **Native App Security**: RFC 8252 compliance for mobile and desktop applications
- **Server Metadata**: RFC 8414 automatic discovery capabilities

## OAuth 2.0 RFC Compliance

This module now provides complete compliance with:

- **RFC 7591**: Dynamic Client Registration - Automated client onboarding via `/oauth/register`
- **RFC 9728**: Protected Resource Metadata - Resource discovery via `/.well-known/oauth-protected-resource`
- **RFC 8414**: Authorization Server Metadata - 100% compliant server discovery

### Available Endpoints

- `/.well-known/oauth-authorization-server` - Authorization server metadata
- `/.well-known/oauth-protected-resource` - Protected resource metadata
- `/oauth/register` - Dynamic client registration (POST)
- `/oauth/register/{client_id}` - Client management (GET/PUT/DELETE)

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

## Submodules

The Simple OAuth 2.1 ecosystem consists of specialized submodules:

### simple_oauth_pkce

Implements RFC 7636 PKCE (Proof Key for Code Exchange) for OAuth 2.1 compliance.

- Mandatory S256 challenge method support
- Configurable enforcement levels
- Authorization code interception protection

### simple_oauth_native_apps

Provides RFC 8252 native application security enhancements.

- WebView detection and blocking
- Custom URI schemes and loopback redirects
- Enhanced PKCE requirements for native clients
- Exact redirect URI matching

### simple_oauth_server_metadata

Implements RFC 8414 Authorization Server Metadata for automatic discovery.

- `/.well-known/oauth-authorization-server` endpoint
- Capability advertisement and automatic client configuration
- Support for extended metadata fields

### simple_oauth_client_registration

Implements RFC 7591 Dynamic Client Registration for automated client onboarding.

- `/oauth/register` endpoint for dynamic client registration
- Full CRUD operations for client management
- RFC 7591 compliant client metadata support
- Automatic client configuration and credential generation

## Installation

### Installation via Composer

```bash
composer require e0ipso/simple_oauth_21
```

### Enable Modules

Enable the umbrella module and desired submodules:

```bash
# Enable the main coordination module and OAuth 2.1 compliance submodules.
drush pm:enable simple_oauth_21 simple_oauth_pkce simple_oauth_native_apps simple_oauth_server_metadata simple_oauth_client_registration
```

## Configuration

### Basic Setup

2. **Access Dashboard**: Navigate to `/admin/config/people/simple_oauth/oauth-21`
3. **Review Compliance**: Check overall compliance status and address critical issues
4. **Configure Submodules**: Use dashboard links to configure individual submodules

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

- **administer simple_oauth entities**: Required to access the compliance dashboard
- Individual submodules may have additional permission requirements

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

### Step 4: Dynamic Client Registration (Optional)

1. Enable `simple_oauth_client_registration` module
2. Configure registration endpoint permissions
3. Test `/oauth/register` endpoint functionality
4. Configure client metadata requirements

### Step 5: Verification

1. Access compliance dashboard
2. Verify "Fully Compliant" status
3. Address any remaining recommendations
4. Test OAuth flows with real clients

</details>

## Documentation

### Additional Resources

- **[API Documentation](./API.md)**: Comprehensive API reference for all OAuth RFC compliance endpoints
- **[Migration Guide](./MIGRATION.md)**: Step-by-step guide for upgrading existing installations
- **[Module Help](/admin/help/simple_oauth_client_registration)**: In-context help for client registration features

## Troubleshooting

### Common Issues

**Q: Dashboard shows "Non-Compliant" despite enabling submodules**
A: Clear the compliance cache by disabling/re-enabling modules or clearing Drupal caches.

**Q: Configuration changes not reflected in dashboard**
A: Configuration changes trigger automatic cache invalidation. Try clearing all caches if issues persist.

**Q: Module compatibility issues**
A: Ensure all modules are updated to compatible versions. Check module dependencies.

**Q: Performance issues with compliance checking**
A: Verify that caching is enabled and consider increasing cache lifetime for stable configurations.

### Debug Mode

Enable detailed logging by setting the log level to DEBUG for the 'simple_oauth_21' channel:

```php
\Drupal::logger('simple_oauth_21')->debug('Debug message');
```
