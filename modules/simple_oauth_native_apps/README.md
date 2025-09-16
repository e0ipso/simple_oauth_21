# Simple OAuth Native Apps

Provides RFC 8252 OAuth 2.0 for Native Apps security enhancements including WebView detection, enhanced PKCE, custom URI schemes, and comprehensive native application security features.

## Overview

The Simple OAuth Native Apps module implements RFC 8252 "OAuth 2.0 for Native Apps" to provide comprehensive security enhancements for mobile and desktop applications. Native applications face unique security challenges that this module addresses through WebView detection, enhanced PKCE requirements, and specialized redirect URI handling.

### RFC 8252 Compliance

RFC 8252 establishes OAuth 2.0 security requirements specifically for native applications. This module provides:

- **External User-Agent Requirement**: Detection and blocking of embedded WebViews
- **Enhanced PKCE**: Mandatory S256 challenge method for native clients
- **Redirect URI Security**: Support for custom URI schemes and loopback addresses
- **Native Client Detection**: Automatic identification and security enforcement

## Features

### WebView Detection and Blocking

- **Advanced Pattern Matching**: 100+ built-in detection patterns for iOS, Android, and web frameworks
- **Policy Options**: Configurable blocking, warning, or allowing of WebView requests
- **Custom Patterns**: Extensible pattern system for new WebView types
- **Whitelist Support**: Exception handling for trusted applications

### Enhanced PKCE for Native Apps

- **S256 Enforcement**: Mandatory SHA256 challenge method for native clients
- **Enhanced Validation**: Additional entropy and security requirements
- **Native Client Requirements**: Automatic PKCE enforcement based on client type
- **Security Logging**: Comprehensive audit trail for PKCE validation

### Redirect URI Security

- **Custom URI Schemes**: Support for `myapp://callback` style redirects
- **Loopback Addresses**: Support for `http://127.0.0.1:port/callback` patterns
- **Exact Matching**: Disables partial URI matching for enhanced security
- **Validation Framework**: Comprehensive URI validation with security constraints

### Administrative Interface

- **Professional Configuration**: Dedicated settings page with security guidance
- **Per-Client Overrides**: Individual client security configurations
- **Validation Warnings**: Real-time configuration security assessment
- **Integration Links**: Direct access from compliance dashboard

## Installation

### Requirements

- Drupal 9.0+ or Drupal 10.0+
- Simple OAuth module (contrib or core)
- PHP 8.1+

### Installation Steps

1. Enable the module: `drush pm:enable simple_oauth_native_apps`
2. Configure settings: Navigate to **Administration → Configuration → People → Simple OAuth → Native Apps Settings**
3. Set security policies based on your native application requirements

## Configuration

### Access Configuration

Navigate to: **Administration → Configuration → People → Simple OAuth → Native Apps Settings**
(`/admin/config/people/simple_oauth/oauth-21/native-apps`)

### Core Configuration Options

#### Native App Security Enforcement

```yaml
enforce_native_security: true # Enable native app security features
```

#### WebView Detection

```yaml
webview:
  detection: 'block' # off/warn/block
  custom_message: '' # Custom error message
  whitelist: [] # Whitelisted patterns
  patterns: [] # Additional detection patterns
```

#### Redirect URI Configuration

```yaml
require_exact_redirect_match: true # Exact URI matching
allow:
  custom_uri_schemes: true # myapp://callback support
  loopback_redirects: true # 127.0.0.1 support
```

#### Enhanced PKCE

```yaml
enhanced_pkce_for_native: true # Mandatory S256 for native clients
```

### RFC 8252 Recommended Configuration

For complete RFC 8252 compliance:

```yaml
enforce_native_security: true
webview:
  detection: 'block'
require_exact_redirect_match: true
allow:
  custom_uri_schemes: true
  loopback_redirects: true
enhanced_pkce_for_native: true
```

### Development Configuration

For development and testing:

```yaml
enforce_native_security: true
webview:
  detection: 'warn'
require_exact_redirect_match: false
allow:
  custom_uri_schemes: true
  loopback_redirects: true
enhanced_pkce_for_native: false
```

## Implementation Guide

### Native Application Setup

#### Step 1: Configure OAuth Client

Create a public OAuth client for your native application:

```yaml
client_type: 'public'
grant_types: ['authorization_code']
redirect_uris:
  - 'com.yourapp.oauth://callback' # Custom URI scheme
  - 'http://127.0.0.1:8080/callback' # Loopback (development)
```

#### Step 2: Implement Authorization Flow

```javascript
// Example using AppAuth library
const config = {
  issuer: 'https://your-drupal-site.com',
  clientId: 'your-native-client-id',
  redirectUrl: 'com.yourapp.oauth://callback',
  scopes: ['read', 'write'],
  serviceConfiguration: {
    authorizationEndpoint: '/oauth/authorize',
    tokenEndpoint: '/oauth/token',
  },
  usesPkce: true,
  usesStateParameter: true,
};

// Initiate authorization
const authResult = await authorize(config);
// Result contains accessToken, refreshToken, etc.
```

#### Step 3: Handle Authorization Response

```javascript
// In your app's URI scheme handler
function handleAuthorizationResponse(url) {
  if (url.startsWith('com.yourapp.oauth://callback')) {
    const params = parseUrlParams(url);
    if (params.code) {
      // Exchange authorization code for tokens
      return exchangeCodeForToken(params.code);
    }
  }
}
```

### Server-Side Integration

#### Custom WebView Detection

```php
// Add custom WebView patterns
use Drupal\simple_oauth_native_apps\Service\UserAgentAnalyzer;

$analyzer = \Drupal::service('simple_oauth_native_apps.user_agent_analyzer');

// Check if request is from WebView
if ($analyzer->isEmbeddedWebView($userAgent)) {
  // Handle WebView detection
  \Drupal::logger('oauth_security')->warning('WebView authorization attempt blocked');
  throw new SecurityException('External browser required for authorization');
}
```

#### Enhanced PKCE Validation

```php
// Access PKCE enhancement service
$pkceService = \Drupal::service('simple_oauth_native_apps.pkce_enhancement');

// Validate native client PKCE requirements
$isNativeClient = $pkceService->isNativeClient($clientId);
if ($isNativeClient && !$pkceService->hasValidS256Challenge($request)) {
  throw new ValidationException('Native clients must use S256 PKCE');
}
```

## Security Considerations

### WebView Security

**Risk**: Embedded WebViews can be compromised by malicious applications
**Mitigation**: Automatic detection and blocking of WebView authorization attempts

**Detection Methods**:

- User-Agent pattern analysis (100+ patterns)
- WebView-specific HTTP headers
- JavaScript environment detection
- Custom detection rules

**Policy Options**:

- **Block**: Reject WebView requests (recommended for production)
- **Warn**: Log warnings but allow requests (useful for monitoring)
- **Off**: Disable detection (not recommended)

### PKCE Security

**Risk**: Authorization code interception attacks
**Mitigation**: Enhanced PKCE with mandatory S256 method for native clients

**Enhancements**:

- Automatic S256 enforcement for detected native clients
- Enhanced code verifier entropy validation
- Comprehensive challenge validation
- Security audit logging

### Redirect URI Security

**Risk**: Authorization code theft through malicious redirects
**Mitigation**: Strict redirect URI validation with exact matching

**Validation Features**:

- Exact URI matching (no partial matches)
- Custom URI scheme format validation
- Loopback address security validation
- Blacklist support for known malicious patterns

### Production Security Checklist

- [ ] WebView detection enabled and set to "block"
- [ ] Enhanced PKCE enabled for native clients
- [ ] Exact redirect URI matching enabled
- [ ] Custom URI schemes properly registered in mobile apps
- [ ] HTTPS enforced for all OAuth endpoints
- [ ] Regular security monitoring and log analysis
- [ ] Client applications use system browsers only

## Native App Implementation Patterns

### Mobile App Patterns

#### iOS Implementation

```swift
// Using AppAuth-iOS
import AppAuth

let configuration = OIDServiceConfiguration(
    authorizationEndpoint: URL(string: "https://yoursite.com/oauth/authorize")!,
    tokenEndpoint: URL(string: "https://yoursite.com/oauth/token")!
)

let request = OIDAuthorizationRequest(
    configuration: configuration,
    clientId: "your-client-id",
    scopes: ["read", "write"],
    redirectURL: URL(string: "com.yourapp.oauth://callback")!,
    responseType: OIDResponseTypeCode,
    additionalParameters: nil
)

// Present authorization
OIDAuthState.authState(byPresenting: request, presenting: viewController) { authState, error in
    // Handle authorization result
}
```

#### Android Implementation

```java
// Using AppAuth-Android
AuthorizationServiceConfiguration config = new AuthorizationServiceConfiguration(
    Uri.parse("https://yoursite.com/oauth/authorize"),
    Uri.parse("https://yoursite.com/oauth/token")
);

AuthorizationRequest request = new AuthorizationRequest.Builder(
    config,
    "your-client-id",
    ResponseTypeValues.CODE,
    Uri.parse("com.yourapp.oauth://callback")
)
.setScopes("read", "write")
.build();

// Start authorization
authService.performAuthorizationRequest(request, pendingIntent);
```

### Desktop App Patterns

#### Electron Implementation

```javascript
// Main process - handle protocol registration
app.setAsDefaultProtocolClient('com.yourapp.oauth');

// OAuth flow initiation
const { shell } = require('electron');
shell.openExternal(authorizationUrl);

// Handle callback in protocol handler
app.on('open-url', (event, url) => {
  if (url.startsWith('com.yourapp.oauth://')) {
    handleAuthorizationCallback(url);
  }
});
```

#### CLI Application Pattern

```python
# Python CLI application using loopback
import http.server
import webbrowser
from urllib.parse import urlparse, parse_qs

# Start local server for callback
server = http.server.HTTPServer(('127.0.0.1', 8080), CallbackHandler)

# Open browser for authorization
auth_url = f"https://yoursite.com/oauth/authorize?client_id={client_id}&redirect_uri=http://127.0.0.1:8080/callback&response_type=code&code_challenge={challenge}&code_challenge_method=S256"
webbrowser.open(auth_url)

# Handle callback
server.handle_request()
```

## WebView Detection Details

### Detection Patterns

The module includes comprehensive detection patterns for:

#### Mobile WebViews

- **iOS**: WKWebView, UIWebView, CFNetwork patterns
- **Android**: WebView, Chrome Custom Tabs indicators
- **Cross-Platform**: Cordova, PhoneGap, Ionic, React Native

#### Social Media WebViews

- **Facebook**: FBAN, FBAV, FB_IAB patterns
- **Instagram**: Instagram app WebView
- **Twitter**: TwitterAndroid, Twitter for iPhone
- **LinkedIn**: LinkedInApp patterns
- **WhatsApp**: WhatsApp internal browser
- **TikTok**: TikTok app WebView

#### Messaging App Browsers

- **WeChat**: MicroMessenger patterns
- **Line**: Line app browser
- **QQ Browser**: QQ-specific patterns
- **UC Browser**: UCBrowser patterns

### Custom Pattern Configuration

```yaml
# Add custom detection patterns
webview:
  patterns:
    - 'YourApp/.*WebKit'
    - 'CustomBrowser/[0-9.]+'
    - 'EmbeddedWebView.*Mobile'
```

### Whitelist Configuration

```yaml
# Allow specific trusted applications
webview:
  whitelist:
    - 'TrustedApp/1.0'
    - 'WhitelistedBrowser/.*'
```

## Troubleshooting

### Common Issues

**"WebView detected" Error**

- Cause: Application using embedded WebView instead of system browser
- Solution: Update application to use system browser or add to whitelist

**"Enhanced PKCE required" Error**

- Cause: Native client not using S256 challenge method
- Solution: Update client to use S256 PKCE or disable enhanced PKCE

**"Invalid redirect URI" Error**

- Cause: Redirect URI doesn't match configured client URIs exactly
- Solution: Verify URI configuration and exact matching requirements

**Custom URI Scheme Not Working**

- Cause: URI scheme not properly registered in mobile app
- Solution: Verify app manifest configuration and URI scheme registration

### Debug Logging

Enable detailed logging for troubleshooting:

```yaml
# Configuration
logging:
  level: 'debug'
  channels:
    - 'simple_oauth_native_apps'
    - 'oauth_security'
```

Example log entries:

```
simple_oauth_native_apps: WebView detected: Facebook App [FBAN/FBIOS]
simple_oauth_native_apps: Enhanced PKCE validation successful for native client
simple_oauth_native_apps: Custom URI scheme validation passed: com.yourapp.oauth://callback
```

### Testing Tools

#### Manual Testing

1. Test authorization flow in system browser (should succeed)
2. Test in WebView (should be blocked)
3. Test custom URI scheme handling
4. Verify PKCE enforcement

#### Automated Testing

```bash
# Run native apps specific tests
vendor/bin/phpunit modules/contrib/simple_oauth_21/modules/simple_oauth_native_apps/tests/

# Test WebView detection
vendor/bin/phpunit --filter WebViewDetectionTest

# Test PKCE enhancement
vendor/bin/phpunit --filter PKCEEnhancementTest
```

## Standards Compliance

This module implements:

- **RFC 8252**: OAuth 2.0 for Native Apps (complete implementation)
- **RFC 7636**: PKCE extension with native app enhancements
- **OAuth 2.1 Draft**: Native app security requirements

### RFC 8252 Compliance Checklist

- ✅ Section 7.1: Custom URI scheme support
- ✅ Section 7.3: Loopback interface redirects
- ✅ Section 8.1: External user-agent requirement
- ✅ Section 8.3: PKCE mandatory for native apps
- ✅ Section 8.12: Embedded user-agent risks (WebView detection)

## Performance Considerations

### Caching Strategy

- User-Agent analysis results cached for performance
- Configuration settings cached with automatic invalidation
- WebView detection patterns compiled for efficiency

### Scalability

- Efficient pattern matching algorithms
- Minimal database overhead
- Optimized for high-traffic OAuth endpoints

### Monitoring

- Comprehensive logging for security analysis
- Performance metrics for detection algorithms
- Audit trails for compliance verification

## Contributing

### Development Guidelines

- Follow Drupal coding standards
- Include comprehensive tests for new features
- Update documentation for configuration changes
- Ensure RFC 8252 compliance for all changes

### Testing Requirements

- Unit tests for all security components
- Kernel tests for configuration validation
- Functional tests for OAuth flow integration
- Security tests for attack prevention

### Security Considerations

- All changes must maintain or improve security posture
- New WebView patterns require thorough testing
- Configuration changes need security impact assessment

## Support

- **Issue Queue**: Part of simple_oauth_21 project
- **Documentation**: RFC 8252 and native app security guides
- **Security**: Follow Drupal security reporting procedures

## License

GPL-2.0+
