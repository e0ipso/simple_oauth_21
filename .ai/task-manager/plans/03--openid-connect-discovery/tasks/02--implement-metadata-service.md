---
id: 2
group: 'service-implementation'
dependencies: []
status: 'pending'
created: '2025-01-22'
skills: ['drupal-backend', 'php']
complexity_score: 5.0
complexity_notes: 'Multiple metadata fields with integration to existing services'
---

# Implement OpenIdConfigurationService for Metadata Generation

## Objective

Create a service that generates OpenID Connect Discovery metadata by aggregating information from various sources within the simple_oauth ecosystem, including endpoint discovery, supported claims, and other configuration data.

## Skills Required

- **drupal-backend**: Drupal service implementation, dependency injection, caching interfaces, and configuration management
- **php**: Complex data structures, arrays, validation logic, and integration with existing services

## Acceptance Criteria

- [ ] OpenIdConfigurationService class implements CacheableDependencyInterface
- [ ] Service registered in simple_oauth_server_metadata.services.yml
- [ ] getOpenIdConfiguration() method returns complete metadata array
- [ ] All required OpenID Connect Discovery fields are populated
- [ ] Integrates with EndpointDiscoveryService for endpoint URLs
- [ ] Uses %simple_oauth.openid.claims% parameter for supported claims
- [ ] Implements proper cache tags and contexts
- [ ] Validates metadata before returning
- [ ] Includes both required and optional metadata fields
- [ ] Follows Drupal coding standards

## Technical Requirements

**Required Metadata Fields:**

- `issuer` - The issuer identifier (HTTPS URL)
- `authorization_endpoint` - URL of the authorization endpoint
- `token_endpoint` - URL of the token endpoint
- `userinfo_endpoint` - URL of the UserInfo endpoint
- `jwks_uri` - URL of the JSON Web Key Set document
- `scopes_supported` - Array of supported scope values
- `response_types_supported` - Array of supported response types
- `subject_types_supported` - Array of supported subject identifier types
- `id_token_signing_alg_values_supported` - Array of JWS signing algorithms
- `claims_supported` - Array of supported claim names

**Optional Metadata Fields:**

- `response_modes_supported`, `grant_types_supported`, `token_endpoint_auth_methods_supported`, `service_documentation`

**Cache Requirements:**

- Implement CacheableDependencyInterface
- Use appropriate cache tags and contexts
- Invalidate when configuration changes

## Input Dependencies

None - this service can be implemented independently and will be consumed by task 1

## Output Artifacts

- `src/Service/OpenIdConfigurationService.php` - New service class
- Updated `simple_oauth_server_metadata.services.yml` - Service registration

## Implementation Notes

<details>
<summary>Detailed implementation guidance</summary>

### Service Class Structure

```php
<?php

namespace Drupal\simple_oauth_server_metadata\Service;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\simple_oauth_server_metadata\Service\EndpointDiscoveryService;

class OpenIdConfigurationService implements CacheableDependencyInterface {
  use CacheableDependencyTrait;

  protected ConfigFactoryInterface $configFactory;
  protected EndpointDiscoveryService $endpointDiscoveryService;
  protected array $supportedClaims;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    EndpointDiscoveryService $endpoint_discovery_service,
    array $supported_claims
  ) {
    $this->configFactory = $config_factory;
    $this->endpointDiscoveryService = $endpoint_discovery_service;
    $this->supportedClaims = $supported_claims;
  }

  public function getOpenIdConfiguration(): array {
    $config = $this->configFactory->get('simple_oauth_server_metadata.settings');

    $metadata = [
      // Required fields
      'issuer' => $config->get('issuer'),
      'authorization_endpoint' => $this->endpointDiscoveryService->getAuthorizationEndpoint(),
      'token_endpoint' => $this->endpointDiscoveryService->getTokenEndpoint(),
      'userinfo_endpoint' => $this->endpointDiscoveryService->getUserInfoEndpoint(),
      'jwks_uri' => $this->endpointDiscoveryService->getJwksEndpoint(),
      'scopes_supported' => $this->getSupportedScopes(),
      'response_types_supported' => $this->getSupportedResponseTypes(),
      'subject_types_supported' => ['public'],
      'id_token_signing_alg_values_supported' => ['RS256'],
      'claims_supported' => $this->supportedClaims,

      // Optional fields
      'response_modes_supported' => ['query', 'fragment'],
      'grant_types_supported' => $this->getSupportedGrantTypes(),
      'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
      'service_documentation' => 'https://www.drupal.org/project/simple_oauth',
    ];

    // Validate metadata
    $this->validateMetadata($metadata);

    return $metadata;
  }

  protected function getSupportedScopes(): array {
    // Implement scope discovery logic
    // Use ScopeDiscoveryService if available
  }

  protected function getSupportedResponseTypes(): array {
    return ['code', 'token', 'id_token', 'code id_token'];
  }

  protected function getSupportedGrantTypes(): array {
    // Use GrantTypeDiscoveryService
    return ['authorization_code', 'refresh_token', 'client_credentials'];
  }

  protected function validateMetadata(array $metadata): void {
    $required_fields = [
      'issuer', 'authorization_endpoint', 'token_endpoint',
      'userinfo_endpoint', 'jwks_uri', 'scopes_supported',
      'response_types_supported', 'subject_types_supported',
      'id_token_signing_alg_values_supported', 'claims_supported'
    ];

    foreach ($required_fields as $field) {
      if (!isset($metadata[$field]) || empty($metadata[$field])) {
        throw new \InvalidArgumentException("Required field '$field' is missing or empty");
      }
    }
  }

  public function getCacheContexts(): array {
    return ['url.site'];
  }

  public function getCacheTags(): array {
    return ['config:simple_oauth_server_metadata.settings'];
  }

  public function getCacheMaxAge(): int {
    return 3600; // 1 hour
  }
}
```

### Service Registration

Add to `simple_oauth_server_metadata.services.yml`:

```yaml
simple_oauth_server_metadata.openid_configuration:
  class: Drupal\simple_oauth_server_metadata\Service\OpenIdConfigurationService
  arguments:
    - '@config.factory'
    - '@simple_oauth_server_metadata.endpoint_discovery'
    - '%simple_oauth.openid.claims%'
```

### Integration Points

- Use EndpointDiscoveryService for endpoint URLs
- Access %simple_oauth.openid.claims% parameter for claims list
- Leverage ScopeDiscoveryService and GrantTypeDiscoveryService if available
- Follow configuration pattern from simple_oauth_server_metadata.settings

### Key Implementation Requirements

- All required OpenID Connect Discovery fields must be present
- Proper cache implementation with tags and contexts
- Validation of generated metadata
- Integration with existing discovery services
- Fallback values for missing configuration
</details>
