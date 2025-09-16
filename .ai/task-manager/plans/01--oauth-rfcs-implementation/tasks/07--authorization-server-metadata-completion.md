---
id: 7
group: 'rfc-8414-completion'
dependencies: []
status: 'pending'
created: '2025-09-16'
skills: ['drupal-backend', 'oauth-protocols']
complexity_score: 4.0
---

# Complete Authorization Server Metadata

## Objective

Add the remaining 15% of RFC 8414 fields to achieve 100% compliance by extending existing discovery services in the `simple_oauth_server_metadata` module following established patterns.

## Skills Required

- **drupal-backend**: Service extension, discovery service patterns
- **oauth-protocols**: RFC 8414 specification, OAuth 2.0 metadata

## Acceptance Criteria

- [ ] `response_modes_supported` field added to server metadata
- [ ] `token_endpoint_auth_signing_alg_values_supported` field implemented
- [ ] `request_uri_parameter_supported` and `require_request_uri_registration` fields added
- [ ] Additional OpenID Connect specific fields completed
- [ ] All fields integrate with existing caching system
- [ ] 100% RFC 8414 compliance achieved

## Technical Requirements

**Missing RFC 8414 Fields:**

- `response_modes_supported` - OAuth response modes (query, fragment, form_post)
- `token_endpoint_auth_signing_alg_values_supported` - Signing algorithms
- `request_uri_parameter_supported` - Request URI parameter support
- `require_request_uri_registration` - Request URI registration requirement

**Discovery Integration:**

- Extend existing discovery services following established patterns
- Integrate with `ServerMetadataService` cache system

## Input Dependencies

None - extends existing server_metadata module

## Output Artifacts

- Complete RFC 8414 metadata coverage
- Enhanced discovery services

## Implementation Notes

<details>
<summary>Detailed Implementation Instructions</summary>

Extend existing discovery services following the patterns in `GrantTypeDiscoveryService` and `EndpointDiscoveryService`:

**New Discovery Service Methods:**

Add to `GrantTypeDiscoveryService`:

```php
public function getResponseModesSupported(): array {
  // Return supported response modes: query, fragment, form_post
  // Detect based on available grant types and configuration
}
```

Add to `ClaimsAuthDiscoveryService`:

```php
public function getTokenEndpointAuthSigningAlgValuesSupported(): array {
  // Return supported signing algorithms for token endpoint auth
  // Based on configured JWT signing capabilities
}

public function getRequestUriParameterSupported(): bool {
  // Check if request URI parameter is supported
}

public function getRequireRequestUriRegistration(): bool {
  // Check if request URI registration is required
}
```

**Integration with ServerMetadataService:**

Extend `generateMetadata()` method in `ServerMetadataService`:

```php
protected function generateMetadata(array $config_override = []): array {
  // ... existing code ...

  // Add missing fields
  $metadata['response_modes_supported'] = $this->grantTypeDiscovery->getResponseModesSupported();
  $metadata['token_endpoint_auth_signing_alg_values_supported'] = $this->claimsAuthDiscovery->getTokenEndpointAuthSigningAlgValuesSupported();
  $metadata['request_uri_parameter_supported'] = $this->claimsAuthDiscovery->getRequestUriParameterSupported();
  $metadata['require_request_uri_registration'] = $this->claimsAuthDiscovery->getRequireRequestUriRegistration();

  // ... rest of existing code ...
}
```

**Cache Integration:**
Use existing `getCacheTags()` system - no changes needed as new fields are dynamically discovered.

Follow the exact same patterns as existing discovery service methods for consistency.

</details>
