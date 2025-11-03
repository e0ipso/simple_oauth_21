# Simple OAuth Server Metadata - Developer Documentation

**Target Audience:** Developers and AI assistants working with the Simple OAuth Server Metadata module

This document provides architectural guidance, extension patterns, and implementation details for the Simple OAuth Server Metadata module. It explains how various components work and how to extend them.

---

## Table of Contents

- [Module Overview](#module-overview)
- [Architecture](#architecture)
- [OAuth Protected Resource Metadata (RFC 9728)](#oauth-protected-resource-metadata-rfc-9728)
- [OAuth Authorization Server Metadata (RFC 8414)](#oauth-authorization-server-metadata-rfc-8414)
- [OpenID Connect Discovery](#openid-connect-discovery)
- [Token Revocation & Introspection](#token-revocation--introspection)
- [Service Architecture](#service-architecture)
- [Extension Patterns](#extension-patterns)
- [Configuration System](#configuration-system)
- [Caching Strategy](#caching-strategy)
- [Testing](#testing)

---

## Module Overview

The `simple_oauth_server_metadata` module implements three discovery standards:

1. **RFC 8414** - OAuth 2.0 Authorization Server Metadata
2. **RFC 9728** - OAuth 2.0 Protected Resource Metadata
3. **OpenID Connect Discovery** - `.well-known/openid-configuration`

It also provides token management endpoints:

- **RFC 7009** - Token Revocation
- **RFC 7662** - Token Introspection

### Key Files

```
src/
├── Controller/
│   ├── ResourceMetadataController.php       # RFC 9728 endpoint
│   ├── ServerMetadataController.php         # RFC 8414 endpoint
│   ├── OpenIdConfigurationController.php    # OIDC Discovery endpoint
│   ├── TokenRevocationController.php        # RFC 7009 endpoint
│   └── TokenIntrospectionController.php     # RFC 7662 endpoint
├── Service/
│   ├── ResourceMetadataService.php          # Resource metadata generator
│   ├── ServerMetadataService.php            # Server metadata generator
│   ├── OpenIdConfigurationService.php       # OIDC metadata generator
│   ├── EndpointDiscoveryService.php         # Discovers OAuth endpoints
│   ├── GrantTypeDiscoveryService.php        # Discovers grant types
│   ├── ScopeDiscoveryService.php            # Discovers scopes
│   ├── ClaimsAuthDiscoveryService.php       # Discovers claims/auth methods
│   ├── ClientAuthenticationService.php      # Client auth for revocation
│   └── TokenRevocationService.php           # Token revocation logic
├── EventSubscriber/
│   └── IntrospectionExceptionSubscriber.php # Handles introspection errors
└── Form/
    └── ServerMetadataSettingsForm.php       # Admin configuration form
```

---

## Architecture

### Request Flow

```
1. HTTP GET /.well-known/oauth-protected-resource
   ↓
2. ResourceMetadataController::__invoke()
   ↓
3. ResourceMetadataService::getResourceMetadata()
   ↓
4. [Discovery Services aggregate data]
   ↓
5. CacheableJsonResponse with CORS headers
```

### Service Dependencies

All metadata services depend on discovery services:

- **EndpointDiscoveryService**: Discovers endpoint URLs by route inspection
- **GrantTypeDiscoveryService**: Discovers grant types from OAuth plugins
- **ScopeDiscoveryService**: Discovers scopes from roles and configuration
- **ClaimsAuthDiscoveryService**: Discovers authentication methods and claims

---

## OAuth Protected Resource Metadata (RFC 9728)

### Endpoint

**Route:** `simple_oauth_server_metadata.resource_metadata`
**Path:** `/.well-known/oauth-protected-resource`
**Methods:** GET
**Access:** Public (no authentication required)

### Implementation

#### Controller: `ResourceMetadataController`

The controller:

1. Calls `ResourceMetadataService::getResourceMetadata()`
2. Validates metadata with `validateMetadata()`
3. Returns `CacheableJsonResponse` with CORS headers
4. Handles errors gracefully with `ServiceUnavailableHttpException`

#### Service: `ResourceMetadataService`

**Dependencies:**

- `ConfigFactoryInterface $configFactory`
- `EndpointDiscoveryService $endpointDiscovery`

**Key Method:** `getResourceMetadata(array $config_override = []): array`

**Metadata Structure:**

```php
[
  // REQUIRED per RFC 9728
  'resource' => 'https://example.com',              // From issuer
  'authorization_servers' => [                      // From issuer
    'https://example.com'
  ],

  // DEFAULT
  'bearer_methods_supported' => [                   // Default methods
    'header', 'body', 'query'
  ],

  // CONFIGURABLE (from simple_oauth_server_metadata.settings)
  'resource_documentation' => 'https://...',        // Optional
  'resource_policy_uri' => 'https://...',           // Optional
  'resource_tos_uri' => 'https://...',              // Optional
]
```

**Processing Steps:**

1. Set required fields (`resource`, `authorization_servers`)
2. Set default `bearer_methods_supported`
3. Add configurable fields from settings
4. Convert relative URLs to absolute URLs
5. Filter empty optional fields
6. Return metadata array

**Configurable Fields:**

The service reads these fields from `simple_oauth_server_metadata.settings`:

- `resource_documentation`
- `resource_policy_uri`
- `resource_tos_uri`

These are processed by `addConfigurableFields()` which:

- Checks for config overrides (for preview)
- Converts relative URLs to absolute URLs with `ensureAbsoluteUrl()`
- Filters empty values

**URL Conversion:**

`ensureAbsoluteUrl()` handles:

- Already absolute URLs (with `https?://`) → return as-is
- Relative paths (starting with `/`) → convert using `Url::fromUserInput()->setAbsolute()`
- Other formats → return as-is

### How to Extend Resource Metadata

**Current State:** No alter hooks exist yet.

#### Method 1: Configuration (For Simple String Fields)

Add values via admin UI or configuration:

```yaml
# config/simple_oauth_server_metadata.settings.yml
resource_documentation: 'https://example.com/api-docs'
resource_policy_uri: 'https://example.com/privacy'
resource_tos_uri: 'https://example.com/terms'
```

**Admin UI:** `/admin/config/people/simple_oauth/oauth-21/server-metadata`

#### Method 2: Service Decoration (For Complex Custom Fields)

Decorate the `simple_oauth_server_metadata.resource_metadata` service:

```yaml
# your_module.services.yml
services:
  your_module.resource_metadata:
    class: Drupal\your_module\Service\CustomResourceMetadataService
    decorates: simple_oauth_server_metadata.resource_metadata
    arguments:
      - '@your_module.resource_metadata.inner'
      - '@config.factory'
      - '@simple_oauth_server_metadata.endpoint_discovery'
```

```php
// src/Service/CustomResourceMetadataService.php
<?php

declare(strict_types=1);

namespace Drupal\your_module\Service;

use Drupal\simple_oauth_server_metadata\Service\ResourceMetadataService;

final class CustomResourceMetadataService extends ResourceMetadataService {

  public function getResourceMetadata(array $config_override = []): array {
    // Get base metadata from parent
    $metadata = parent::getResourceMetadata($config_override);

    // Add custom RFC 9728 fields
    $metadata['resource_signing_alg_values_supported'] = ['RS256', 'ES256'];

    // Add custom scope claim mapping
    $metadata['scope_claim_name'] = 'scope';

    // Add resource-specific capabilities
    $metadata['resource_capabilities'] = [
      'versioning' => 'v1',
      'rate_limiting' => TRUE,
    ];

    return $metadata;
  }
}
```

#### Method 3: Symfony Event Subscribers (Recommended)

**Use Case:** React to and modify metadata generation events in a decoupled, testable way. This is the recommended approach for extending resource metadata functionality.

**Advantages:**

- Follows Symfony/Drupal best practices
- Fully testable and mockable
- Supports priority ordering for multiple subscribers
- Decoupled from service implementation
- Event objects provide rich context

**Available Events:**

The module dispatches events during metadata generation:

- `ResourceMetadataEvent::class` - Fired when resource metadata is being generated
- `ServerMetadataEvent::class` - Fired when server metadata is being generated
- `OpenIdConfigurationEvent::class` - Fired when OpenID Connect configuration is being generated

**Example: Event Subscriber Implementation**

Create an event subscriber to add custom resource metadata fields:

```php
<?php

declare(strict_types=1);

namespace Drupal\your_module\EventSubscriber;

use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for customizing OAuth resource metadata.
 */
final class ResourceMetadataSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ResourceMetadataEvent::class => ['onResourceMetadata', 0],
    ];
  }

  /**
   * Modifies resource metadata.
   *
   * @param \Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent $event
   *   The resource metadata event.
   */
  public function onResourceMetadata(ResourceMetadataEvent $event): void {
    // Direct property access for reading and modifying metadata.
    $event->metadata['resource_signing_alg_values_supported'] = ['RS256', 'ES256'];

    // Add custom scope claim mapping.
    $event->metadata['scope_claim_name'] = 'scope';

    // Add resource-specific capabilities.
    $event->metadata['resource_capabilities'] = [
      'versioning' => 'v1',
      'rate_limiting' => TRUE,
      'pagination' => 'cursor',
    ];

    // Alternatively, use convenience methods for individual fields.
    $event->addMetadataField('custom_field', 'custom_value');
  }

}
```

**Service Registration:**

Register the event subscriber in `your_module.services.yml`:

```yaml
services:
  your_module.resource_metadata_subscriber:
    class: Drupal\your_module\EventSubscriber\ResourceMetadataSubscriber
    tags:
      - { name: event_subscriber }
```

**Common Use Cases:**

**1. Adding Conditional Fields Based on Configuration:**

```php
public function onResourceMetadata(ResourceMetadataEvent $event): void {
  $config = \Drupal::config('your_module.settings');

  if ($config->get('enable_advanced_auth')) {
    $event->metadata['resource_signing_alg_values_supported'] = [
      'RS256',
      'RS384',
      'RS512',
      'ES256',
      'ES384',
      'ES512',
    ];
    $event->metadata['dpop_signing_alg_values_supported'] = ['RS256', 'ES256'];
  }
}
```

**2. Integrating Third-Party Service Data:**

```php
public function onResourceMetadata(ResourceMetadataEvent $event): void {
  // Fetch capabilities from external service.
  $apiService = \Drupal::service('your_module.api_service');
  $capabilities = $apiService->getResourceCapabilities();

  if (!empty($capabilities)) {
    $event->metadata['supported_features'] = $capabilities;
  }
}
```

**3. Adding Environment-Specific Metadata:**

```php
public function onResourceMetadata(ResourceMetadataEvent $event): void {
  // Add environment indicator.
  $environment = \Drupal::config('environment_indicator.indicator');
  if ($environment->get('name')) {
    $event->metadata['environment'] = $environment->get('name');
  }

  // Add deployment version.
  $event->metadata['api_version'] = \Drupal::VERSION;
}
```

**Event Subscriber Priorities:**

Control the order of execution using priority values (higher = earlier):

```php
public static function getSubscribedEvents(): array {
  return [
    // Run early to set defaults (priority 100).
    ResourceMetadataEvent::class => ['onResourceMetadata', 100],
  ];
}
```

**Priority Guidelines:**

- **100+**: Early processing, set defaults or base values
- **0**: Normal processing (default)
- **-100 or lower**: Late processing, final modifications or cleanup

**Multiple Event Handlers in One Subscriber:**

```php
final class MetadataSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [
      ResourceMetadataEvent::class => ['onResourceMetadata', 0],
      ServerMetadataEvent::class => ['onServerMetadata', 0],
      OpenIdConfigurationEvent::class => ['onOpenIdConfiguration', 0],
    ];
  }

  public function onResourceMetadata(ResourceMetadataEvent $event): void {
    // Handle resource metadata.
  }

  public function onServerMetadata(ServerMetadataEvent $event): void {
    // Handle server metadata.
  }

  public function onOpenIdConfiguration(OpenIdConfigurationEvent $event): void {
    // Handle OpenID configuration.
  }

}
```

**Best Practices:**

1. **Cache Awareness:**
   - Event subscribers don't automatically invalidate caches
   - Add cache tags to your event subscriber service if metadata depends on dynamic data
   - Implement cache invalidation logic when your custom fields change

   ```php
   // Invalidate metadata cache when your config changes.
   function your_module_config_save($config) {
     if ($config->getName() === 'your_module.settings') {
       \Drupal::service('cache_tags.invalidator')
         ->invalidateTags(['simple_oauth_server_metadata']);
     }
   }
   ```

2. **Avoid Field Conflicts:**
   - Check if a field already exists before adding it
   - Use namespaced field names for custom extensions (e.g., `x_your_module_field`)
   - Document which fields your module adds

   ```php
   if (!isset($event->metadata['custom_field'])) {
     $event->metadata['custom_field'] = 'value';
   }
   ```

3. **RFC Compliance:**
   - Only add fields that are RFC-compliant or properly namespaced
   - Validate field values match expected types
   - Document which RFC sections your custom fields relate to

4. **Error Handling:**
   - Wrap external service calls in try-catch blocks
   - Log errors appropriately
   - Don't let exceptions prevent metadata generation

   ```php
   try {
     $data = $externalService->fetch();
     $event->metadata['external_data'] = $data;
   }
   catch (\Exception $e) {
     \Drupal::logger('your_module')->error(
       'Failed to fetch external data: @message',
       ['@message' => $e->getMessage()]
     );
   }
   ```

5. **Testing:**
   - Write unit tests for event subscribers
   - Mock dependencies for isolated testing
   - Test with multiple subscribers to verify priority ordering

   ```php
   public function testResourceMetadataSubscriber() {
     $event = new ResourceMetadataEvent(['resource' => 'https://example.com']);
     $subscriber = new ResourceMetadataSubscriber();
     $subscriber->onResourceMetadata($event);

     $this->assertArrayHasKey('resource_capabilities', $event->metadata);
   }
   ```

**Comparison of Extension Methods:**

| Feature                 | Configuration        | Service Decoration           | Event Subscribers       |
| ----------------------- | -------------------- | ---------------------------- | ----------------------- |
| **Complexity**          | Low                  | Medium                       | Medium                  |
| **Flexibility**         | Limited to strings   | Full control                 | Full control            |
| **Testability**         | N/A                  | Medium                       | High                    |
| **Maintenance**         | Easy                 | Medium                       | Easy                    |
| **Best For**            | Static string fields | Complete service replacement | Additive modifications  |
| **Decoupling**          | High                 | Low (tight coupling)         | High                    |
| **Priority Control**    | No                   | No                           | Yes                     |
| **Multiple Extensions** | No conflicts         | Can conflict                 | Clean ordering          |
| **Recommended When**    | Simple text values   | Need to change core logic    | Adding/modifying fields |

**Summary:**

Event subscribers are the recommended approach for most use cases because they:

- Follow Drupal/Symfony best practices
- Allow multiple modules to extend metadata without conflicts
- Provide clean, testable, maintainable code
- Support priority-based ordering of modifications
- Don't require replacing or decorating services

---

## OAuth Authorization Server Metadata (RFC 8414)

### Endpoint

**Route:** `simple_oauth_server_metadata.well_known`
**Path:** `/.well-known/oauth-authorization-server`
**Methods:** GET
**Access:** Public

### Implementation

#### Controller: `ServerMetadataController`

Similar pattern to `ResourceMetadataController`:

1. Calls `ServerMetadataService::getServerMetadata()`
2. Validates with `validateMetadata()`
3. Returns cacheable JSON with CORS headers

#### Service: `ServerMetadataService`

**Dependencies:**

- `ConfigFactoryInterface $configFactory`
- `EndpointDiscoveryService $endpointDiscovery`
- `GrantTypeDiscoveryService $grantTypeDiscovery`
- `ScopeDiscoveryService $scopeDiscovery`
- `ClaimsAuthDiscoveryService $claimsAuthDiscovery`

**Key Method:** `getServerMetadata(array $config_override = []): array`

**Metadata Structure:**

```php
[
  // REQUIRED (RFC 8414)
  'issuer' => 'https://example.com',
  'response_types_supported' => ['code'],

  // DISCOVERED AUTOMATICALLY
  'authorization_endpoint' => 'https://example.com/oauth/authorize',
  'token_endpoint' => 'https://example.com/oauth/token',
  'jwks_uri' => 'https://example.com/oauth/jwks',
  'userinfo_endpoint' => 'https://example.com/oauth/userinfo',
  'grant_types_supported' => ['authorization_code', 'refresh_token', ...],
  'scopes_supported' => ['read', 'write', ...],
  'token_endpoint_auth_methods_supported' => ['client_secret_basic', ...],
  'code_challenge_methods_supported' => ['S256', 'plain'],

  // OPTIONAL DISCOVERED (module-dependent)
  'registration_endpoint' => 'https://example.com/oauth/register',
  'revocation_endpoint' => 'https://example.com/oauth/revoke',
  'introspection_endpoint' => 'https://example.com/oauth/introspect',
  'device_authorization_endpoint' => 'https://example.com/oauth/device_authorization',

  // CONFIGURABLE
  'service_documentation' => 'https://...',
  'op_policy_uri' => 'https://...',
  'op_tos_uri' => 'https://...',
  'ui_locales_supported' => ['en', 'es'],
  'additional_claims_supported' => [...],
  'additional_signing_algorithms' => [...],

  // OPENID CONNECT (if enabled)
  'claims_supported' => ['sub', 'iss', ...],
  'subject_types_supported' => ['public'],
  'id_token_signing_alg_values_supported' => ['RS256'],
]
```

**Processing Steps:**

1. Add required fields from `EndpointDiscoveryService`
2. Add discovered grant types from `GrantTypeDiscoveryService`
3. Add discovered scopes from `ScopeDiscoveryService`
4. Add auth methods from `ClaimsAuthDiscoveryService`
5. Add OpenID Connect fields (if OIDC not disabled)
6. Add configurable fields from settings
7. Filter empty optional fields (preserving boolean fields)

**Configurable Fields:**

```php
$configurable_fields = [
  'registration_endpoint',         // Can override discovered endpoint
  'service_documentation',
  'op_policy_uri',
  'op_tos_uri',
  'ui_locales_supported',
  'additional_claims_supported',
  'additional_signing_algorithms',
];
```

**Special Logic:**

- **Registration Endpoint**: Auto-discovers from route if not configured
- **Boolean Fields**: Preserved even when FALSE (meaningful information)
  - `request_uri_parameter_supported`
  - `require_request_uri_registration`

### How to Extend Server Metadata

Same patterns as Resource Metadata:

#### Method 1: Configuration

```yaml
# simple_oauth_server_metadata.settings.yml
service_documentation: 'https://example.com/docs/oauth'
op_policy_uri: 'https://example.com/privacy-policy'
op_tos_uri: 'https://example.com/terms'
ui_locales_supported:
  - 'en'
  - 'es'
  - 'fr'
additional_claims_supported:
  - 'custom_claim'
additional_signing_algorithms:
  - 'ES256'
```

#### Method 2: Service Decoration

```yaml
# your_module.services.yml
services:
  your_module.server_metadata:
    class: Drupal\your_module\Service\CustomServerMetadataService
    decorates: simple_oauth_server_metadata.server_metadata
    arguments:
      - '@your_module.server_metadata.inner'
      - '@config.factory'
      - '@simple_oauth_server_metadata.endpoint_discovery'
      - '@simple_oauth_server_metadata.grant_type_discovery'
      - '@simple_oauth_server_metadata.scope_discovery'
      - '@simple_oauth_server_metadata.claims_auth_discovery'
```

```php
<?php

declare(strict_types=1);

namespace Drupal\your_module\Service;

use Drupal\simple_oauth_server_metadata\Service\ServerMetadataService;

final class CustomServerMetadataService extends ServerMetadataService {

  public function getServerMetadata(array $config_override = []): array {
    $metadata = parent::getServerMetadata($config_override);

    // Add custom OAuth extensions
    $metadata['custom_extension_field'] = 'value';
    $metadata['mtls_endpoint_aliases'] = [
      'token_endpoint' => 'https://mtls.example.com/token',
    ];

    return $metadata;
  }
}
```

---

## OpenID Connect Discovery

### Endpoint

**Route:** `simple_oauth_server_metadata.openid_configuration`
**Path:** `/.well-known/openid-configuration`
**Methods:** GET
**Access:** Public (if OIDC not disabled)

### Implementation

#### Controller: `OpenIdConfigurationController`

**Special Behavior:**

1. Checks if OpenID Connect is disabled:
   ```php
   $is_disabled = $simple_oauth_config->get('disable_openid_connect');
   if ($is_disabled) {
     throw new NotFoundHttpException('OpenID Connect is not enabled');
   }
   ```
2. Returns 404 if OIDC disabled
3. Otherwise returns metadata like other controllers

#### Service: `OpenIdConfigurationService`

**Dependencies:** Same as `ServerMetadataService` plus:

- `array $openIdClaims` (service parameter from `simple_oauth.openid.claims`)

**Metadata Structure:**

Combines OAuth server metadata with OpenID Connect specific fields:

```php
[
  // All fields from ServerMetadataService
  ...

  // OPENID CONNECT REQUIRED
  'issuer' => 'https://example.com',
  'authorization_endpoint' => '...',
  'token_endpoint' => '...',
  'jwks_uri' => '...',
  'response_types_supported' => [...],
  'subject_types_supported' => ['public'],
  'id_token_signing_alg_values_supported' => ['RS256'],

  // OPENID CONNECT RECOMMENDED
  'userinfo_endpoint' => '...',
  'scopes_supported' => ['openid', 'profile', 'email', ...],
  'claims_supported' => ['sub', 'iss', 'aud', 'exp', ...],

  // OPENID CONNECT OPTIONAL
  'claims_parameter_supported' => FALSE,
  'request_parameter_supported' => FALSE,
  'request_uri_parameter_supported' => FALSE,
]
```

### Claims Configuration

OpenID Connect claims are configured as a service parameter:

```yaml
# simple_oauth_server_metadata.services.yml
parameters:
  simple_oauth.openid.claims:
    - 'sub'
    - 'iss'
    - 'aud'
    - 'exp'
    - 'iat'
    - 'auth_time'
    - 'nonce'
    - 'acr'
    - 'amr'
    - 'azp'
```

To extend claims:

```yaml
# your_module.services.yml
parameters:
  simple_oauth.openid.claims:
    - 'sub'
    - 'iss'
    # ... standard claims ...
    - 'custom_claim'
    - 'another_claim'
```

---

## Token Revocation & Introspection

### Token Revocation (RFC 7009)

**Route:** `simple_oauth_server_metadata.revoke`
**Path:** `/oauth/revoke`
**Methods:** POST
**Authentication:** Client credentials (Basic or POST body)

#### Controller: `TokenRevocationController`

**Dependencies:**

- `ClientAuthenticationService` - Validates client credentials
- `TokenRevocationService` - Revokes tokens
- `HttpMessageFactoryInterface` - Converts to PSR-7

**Key Behavior:**

1. Authenticates client via `ClientAuthenticationService`
2. Validates token ownership (token must belong to authenticated client)
3. Revokes token via `TokenRevocationService`
4. **Privacy:** Returns success even for non-existent tokens (prevents enumeration)

#### Service: `ClientAuthenticationService`

Validates client credentials using:

- HTTP Basic Authentication (`Authorization: Basic ...`)
- POST body parameters (`client_id`, `client_secret`)

#### Service: `TokenRevocationService`

Revokes tokens by:

1. Loading token entity
2. Verifying it's revocable (access token or refresh token)
3. Deleting the token entity

### Token Introspection (RFC 7662)

**Route:** `simple_oauth_server_metadata.token_introspection`
**Path:** `/oauth/introspect`
**Methods:** GET, POST (GET rejected in controller)
**Authentication:** OAuth Bearer token (oauth2 provider)

#### Controller: `TokenIntrospectionController`

**Authentication Pattern:**

Relies on global `SimpleOauthAuthenticationProvider`:

- Bearer token validated before controller is called
- Current user set by authentication provider
- Controller trusts `currentUser` service

**Key Behavior:**

1. Rejects GET requests (workaround for simple_oauth PathValidator bug)
2. Extracts token from request (`token` parameter)
3. Loads token entity and validates:
   - Token exists and not expired
   - Current user owns token OR has admin permission
4. Returns introspection response:
   ```json
   {
     "active": true,
     "scope": "read write",
     "client_id": "client_id",
     "token_type": "Bearer",
     "exp": 1234567890,
     "iat": 1234567890,
     "sub": "user_id"
   }
   ```
5. **Privacy:** Returns `{"active": false}` for unauthorized/invalid tokens

#### Event Subscriber: `IntrospectionExceptionSubscriber`

Catches authentication exceptions and converts them to RFC 7662 responses:

```php
{
  "active": false
}
```

This ensures authentication failures don't leak information.

---

## Service Architecture

### EndpointDiscoveryService

**Purpose:** Discovers OAuth endpoint URLs

**Methods:**

```php
getIssuer(): string
  // Returns base URL as issuer (language-neutral, HTTPS)

getAuthorizationEndpoint(): string
  // Route: oauth2_token.authorize

getTokenEndpoint(): string
  // Route: oauth2_token.token

getJwksUri(): string
  // Route: simple_oauth.jwks

getUserInfoEndpoint(): string
  // Route: simple_oauth.userinfo

getRegistrationEndpoint(): ?string
  // Route: simple_oauth_client_registration.register (if exists)

getRevocationEndpoint(): ?string
  // Route: simple_oauth_server_metadata.revoke (if exists)

getIntrospectionEndpoint(): ?string
  // Route: simple_oauth_server_metadata.token_introspection (if exists)

getCoreEndpoints(): array
  // Returns all discoverable endpoints
  // Checks for device flow module
```

**Module Detection:**

```php
if ($this->moduleHandler->moduleExists('simple_oauth_device_flow')) {
  $endpoints['device_authorization_endpoint'] = ...;
}
```

### GrantTypeDiscoveryService

**Purpose:** Discovers supported grant types

**Methods:**

```php
getGrantTypesSupported(): array
  // Discovers from OAuth2 grant processor plugins
  // Maps plugin IDs to OAuth grant type URNs

getResponseTypesSupported(): array
  // Returns supported OAuth response types
  // From simple_oauth_server_metadata.settings

getResponseModesSupported(): array
  // Returns supported OAuth response modes
  // From simple_oauth_server_metadata.settings
```

**Grant Type Mapping:**

```php
'authorization_code' => 'authorization_code',
'client_credentials' => 'client_credentials',
'refresh_token' => 'refresh_token',
'password' => 'password',
'implicit' => 'implicit',
```

### ScopeDiscoveryService

**Purpose:** Discovers available OAuth scopes

**Dependencies:**

- `OAuth2ScopeProviderInterface $scopeProvider`
- `ConfigFactoryInterface $configFactory`
- `LoggerFactory $loggerFactory`

**Methods:**

```php
getScopesSupported(): array
  // Discovers scopes from:
  // 1. OAuth scope provider
  // 2. Drupal roles (if enabled)
  // 3. Custom scope definitions
```

### ClaimsAuthDiscoveryService

**Purpose:** Discovers authentication methods and claims

**Methods:**

```php
getTokenEndpointAuthMethodsSupported(): array
  // Returns: ['client_secret_basic', 'client_secret_post']

getTokenEndpointAuthSigningAlgValuesSupported(): array
  // Returns: ['RS256', ...]

getCodeChallengeMethodsSupported(): array
  // Returns: ['S256', 'plain'] if PKCE module enabled

getClaimsSupported(): array
  // Returns OpenID Connect claims if not disabled

getSubjectTypesSupported(): array
  // Returns: ['public']

getIdTokenSigningAlgValuesSupported(): array
  // Returns: ['RS256', ...]

getRequestUriParameterSupported(): bool
  // Returns: FALSE

getRequireRequestUriRegistration(): bool
  // Returns: FALSE
```

---

## Extension Patterns

### Pattern 1: Service Decoration

**Use Case:** Add complex logic or custom fields

**Steps:**

1. Create decorated service class extending original
2. Register as decorator in `your_module.services.yml`
3. Override methods to add custom behavior

**Example: Add Custom Discovery Logic**

```php
<?php

declare(strict_types=1);

namespace Drupal\your_module\Service;

use Drupal\simple_oauth_server_metadata\Service\EndpointDiscoveryService as BaseEndpointDiscoveryService;

final class CustomEndpointDiscoveryService extends BaseEndpointDiscoveryService {

  public function getCoreEndpoints(): array {
    $endpoints = parent::getCoreEndpoints();

    // Add custom endpoint
    try {
      $endpoints['pushed_authorization_request_endpoint'] =
        \Drupal::service('url_generator')->generateFromRoute(
          'your_module.par_endpoint',
          [],
          ['absolute' => TRUE]
        );
    }
    catch (\Exception $e) {
      // Endpoint doesn't exist
    }

    return $endpoints;
  }
}
```

```yaml
# your_module.services.yml
services:
  your_module.endpoint_discovery:
    class: Drupal\your_module\Service\CustomEndpointDiscoveryService
    decorates: simple_oauth_server_metadata.endpoint_discovery
    arguments:
      - '@language_manager'
      - '@module_handler'
```

### Pattern 2: Configuration Extension

**Use Case:** Add simple configurable string fields

**Steps:**

1. Add configuration schema
2. Extend configuration form
3. Service decoration reads custom config

**Example: Add Custom Resource Field**

```yaml
# your_module/config/schema/your_module.schema.yml
simple_oauth_server_metadata.settings:
  type: config_object
  mapping:
    custom_resource_field:
      type: string
      label: 'Custom Resource Field'
```

```php
// Extend ServerMetadataSettingsForm via form_alter or decoration
function your_module_form_server_metadata_settings_form_alter(&$form, &$form_state) {
  $config = \Drupal::config('simple_oauth_server_metadata.settings');

  $form['custom_resource_field'] = [
    '#type' => 'textfield',
    '#title' => t('Custom Resource Field'),
    '#default_value' => $config->get('custom_resource_field'),
  ];
}
```

### Pattern 3: Module Hooks (When Implemented)

**Use Case:** Cleanest extension pattern for other modules

**Proposed Implementation in Module:**

```php
// In ResourceMetadataService::getResourceMetadata()
public function getResourceMetadata(array $config_override = []): array {
  // ... existing logic ...

  // Allow modules to alter metadata
  $this->moduleHandler->alter('simple_oauth_resource_metadata', $metadata);

  return $metadata;
}
```

**Usage in Your Module:**

```php
// your_module.module
function your_module_simple_oauth_resource_metadata_alter(array &$metadata) {
  $metadata['custom_field'] = 'value';
}

function your_module_simple_oauth_server_metadata_alter(array &$metadata) {
  $metadata['custom_server_field'] = 'value';
}

function your_module_simple_oauth_openid_configuration_alter(array &$metadata) {
  $metadata['custom_oidc_field'] = 'value';
}
```

---

## Configuration System

### Configuration Files

#### Module Configuration

```yaml
# config/install/simple_oauth_server_metadata.settings.yml

# Optional endpoint overrides
registration_endpoint: ''
revocation_endpoint: ''
introspection_endpoint: ''
device_authorization_endpoint: ''

# Service documentation
service_documentation: 'https://www.drupal.org/project/simple_oauth'
op_policy_uri: ''
op_tos_uri: ''

# Localization
ui_locales_supported: []

# Extensions
additional_claims_supported: []
additional_signing_algorithms: []

# Resource metadata
resource_documentation: ''
resource_policy_uri: ''
resource_tos_uri: ''

# Response types and modes
response_types_supported:
  - 'code'
  - 'id_token'
  - 'code id_token'
response_modes_supported:
  - 'query'
  - 'fragment'
```

#### Configuration Schema

```yaml
# config/schema/simple_oauth_server_metadata.schema.yml

simple_oauth_server_metadata.settings:
  type: config_object
  label: 'Simple OAuth Server Metadata settings'
  mapping:
    registration_endpoint:
      type: string
      label: 'Registration endpoint URL'
    # ... other fields ...
```

### Admin Form

**Path:** `/admin/config/people/simple_oauth/oauth-21/server-metadata`
**Permission:** `administer simple_oauth entities`
**Form Class:** `ServerMetadataSettingsForm`

**Features:**

- Real-time metadata validation
- Live preview of metadata response
- URL validation for endpoint fields
- Collapsible field groups

---

## Caching Strategy

### Cache Tags

**All Metadata Services implement** `CacheableDependencyInterface`

**ResourceMetadataService:**

```php
$this->cacheTags = [
  'config:simple_oauth.settings',
  'config:simple_oauth_server_metadata.settings',
];

$this->cacheContexts = [
  'url.path',
  'user.permissions',
];
```

**ServerMetadataService:**

```php
$this->cacheTags = [
  'config:simple_oauth.settings',
  'config:simple_oauth_server_metadata.settings',
  'user_role_list',                           // Roles affect scopes
  'oauth2_grant_plugins',                     // Grant plugins
  'route_match',                               // Routes affect endpoints
  'simple_oauth_server_metadata',
  'route:simple_oauth_client_registration.register',
  'route:entity.consumer.add_form',
];

$this->cacheContexts = [
  'url.path',
  'user.permissions',
];
```

### Cache Invalidation

**Hook Implementations in** `simple_oauth_server_metadata.module`:

```php
// Invalidate when roles change (affects scopes)
function simple_oauth_server_metadata_user_role_presave($entity) {
  Cache::invalidateTags(['oauth2_server_metadata']);
}

// Invalidate when config changes
function simple_oauth_server_metadata_config_save($config) {
  if (in_array($config->getName(), ['simple_oauth.settings', 'simple_oauth_server_metadata.settings'])) {
    Cache::invalidateTags([
      'oauth2_server_metadata',
      'simple_oauth_server_metadata',
      'config:' . $config->getName(),
    ]);

    // Warm cache
    \Drupal::service('simple_oauth_server_metadata.server_metadata')->warmCache();
  }
}

// Invalidate when modules install/uninstall
function simple_oauth_server_metadata_module_install() {
  Cache::invalidateTags(['simple_oauth_server_metadata', 'oauth2_grant_plugins']);
}
```

### Cache Warming

**ServerMetadataService** has a `warmCache()` method:

```php
public function warmCache(): void {
  // Generate and cache metadata immediately
  $this->getServerMetadata();
}
```

Called automatically after configuration saves.

---

## Testing

### Test Structure

```
tests/src/
├── Functional/
│   ├── ServerMetadataFunctionalTest.php         # RFC 8414 tests
│   ├── ResourceMetadataFunctionalTest.php       # RFC 9728 tests (if exists)
│   ├── TokenRevocationTest.php                  # RFC 7009 tests
│   └── TokenIntrospectionTest.php               # RFC 7662 tests
├── Kernel/
│   ├── EndpointDiscoveryServiceTest.php         # Unit tests for discovery
│   └── ...
└── Unit/
    └── ...
```

### Running Tests

```bash
# All server metadata tests
cd /var/www/html && vendor/bin/phpunit \
  web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/tests

# Specific test
cd /var/www/html && vendor/bin/phpunit \
  web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/tests/src/Functional/ServerMetadataFunctionalTest.php
```

### Test Patterns

**Functional Tests:**

```php
public function testResourceMetadataEndpoint() {
  // Test endpoint accessibility
  $response = $this->drupalGet('/.well-known/oauth-protected-resource');
  $this->assertSession()->statusCodeEquals(200);

  // Test required fields
  $metadata = json_decode($response, TRUE);
  $this->assertArrayHasKey('resource', $metadata);
  $this->assertArrayHasKey('authorization_servers', $metadata);

  // Test CORS headers
  $this->assertSession()->responseHeaderEquals('Access-Control-Allow-Origin', '*');
}
```

**Service Tests:**

```php
public function testResourceMetadataGeneration() {
  $service = \Drupal::service('simple_oauth_server_metadata.resource_metadata');
  $metadata = $service->getResourceMetadata();

  // Validate structure
  $this->assertIsArray($metadata);
  $this->assertArrayHasKey('resource', $metadata);

  // Validate RFC compliance
  $this->assertTrue($service->validateMetadata($metadata));
}
```

---

## Quick Reference

### Service IDs

```
simple_oauth_server_metadata.resource_metadata
simple_oauth_server_metadata.server_metadata
simple_oauth_server_metadata.openid_configuration
simple_oauth_server_metadata.endpoint_discovery
simple_oauth_server_metadata.grant_type_discovery
simple_oauth_server_metadata.scope_discovery
simple_oauth_server_metadata.claims_auth_discovery
simple_oauth_server_metadata.client_authentication
simple_oauth_server_metadata.token_revocation
```

### Routes

```
simple_oauth_server_metadata.resource_metadata        # /.well-known/oauth-protected-resource
simple_oauth_server_metadata.well_known               # /.well-known/oauth-authorization-server
simple_oauth_server_metadata.openid_configuration     # /.well-known/openid-configuration
simple_oauth_server_metadata.revoke                   # /oauth/revoke
simple_oauth_server_metadata.token_introspection      # /oauth/introspect
simple_oauth_server_metadata.settings                 # /admin/config/people/simple_oauth/oauth-21/server-metadata
```

### Cache Tags

```
oauth2_server_metadata              # General metadata cache
simple_oauth_server_metadata        # Module-specific cache
config:simple_oauth.settings        # Simple OAuth config
config:simple_oauth_server_metadata.settings  # Module config
user_role_list                      # When roles affect scopes
oauth2_grant_plugins                # When grant plugins change
```

---

## Common Tasks

### Add a Custom Metadata Field

1. **Decide on extension method** (config vs decoration)
2. **For configuration:**
   - Add schema in `config/schema/`
   - Extend form to expose field
   - Decorate service to read and include field
3. **For decoration:**
   - Create decorated service class
   - Override `get*Metadata()` method
   - Add field to returned array

### Add a Custom Discovery Service

1. **Create service class** implementing discovery logic
2. **Register service** in `your_module.services.yml`
3. **Decorate metadata service** to use custom discovery
4. **Add cache tags** for invalidation

### Debug Metadata Generation

```php
// Get service
$service = \Drupal::service('simple_oauth_server_metadata.resource_metadata');

// Generate metadata
$metadata = $service->getResourceMetadata();

// Dump for inspection
\Drupal::logger('debug')->debug('<pre>' . print_r($metadata, TRUE) . '</pre>');

// Check validation
$is_valid = $service->validateMetadata($metadata);
```

### Test Custom Extensions

```bash
# Clear cache
vendor/bin/drush cache:rebuild

# Test endpoint
curl -s https://your-site.com/.well-known/oauth-protected-resource | jq .

# Validate against RFC
# Use online validators or custom scripts
```

---

## RFC Compliance Checklist

### RFC 9728 (Resource Metadata)

- ✅ `resource` (REQUIRED)
- ✅ `authorization_servers` (REQUIRED)
- ✅ `bearer_methods_supported` (OPTIONAL)
- ✅ `resource_documentation` (OPTIONAL)
- ✅ `resource_policy_uri` (OPTIONAL)
- ✅ `resource_tos_uri` (OPTIONAL)

### RFC 8414 (Server Metadata)

- ✅ `issuer` (REQUIRED)
- ✅ `authorization_endpoint` (REQUIRED)
- ✅ `token_endpoint` (CONDITIONAL)
- ✅ `response_types_supported` (REQUIRED)
- ✅ All optional fields supported

### OpenID Connect Discovery

- ✅ All OIDC required fields
- ✅ Claims support
- ✅ Conditional on `disable_openid_connect` setting

---

**Last Updated:** 2025-11-03
**Module Version:** Compatible with simple_oauth_21 current
**Drupal Version:** 9.x, 10.x, 11.x
