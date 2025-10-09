---
id: 5
group: 'metadata'
dependencies: [4]
status: 'pending'
created: 2025-10-09
skills:
  - php
  - drupal-backend
---

# Server Metadata Integration

## Objective

Update the EndpointDiscoveryService to automatically advertise the token revocation endpoint URL in server metadata responses at `/.well-known/oauth-authorization-server`, enabling automatic endpoint discovery per RFC 8414.

## Skills Required

- **php**: Implement endpoint URL generation and metadata assembly
- **drupal-backend**: Work with Drupal routing, URL generation, and service modification

## Acceptance Criteria

- [ ] EndpointDiscoveryService includes `revocation_endpoint` in metadata response
- [ ] Endpoint URL is generated dynamically using `Url::fromRoute()`
- [ ] URL generation uses absolute URLs (required for metadata responses)
- [ ] Route existence is validated before including in metadata
- [ ] Falls back to configured value if route doesn't exist
- [ ] Follows existing pattern for other endpoints (token, authorization, etc.)
- [ ] Maintains backward compatibility with existing metadata structure
- [ ] Updates follow Drupal coding standards
- [ ] Includes appropriate PHPDoc comments

## Technical Requirements

**File to Modify:**

- `simple_oauth_server_metadata/src/Service/EndpointDiscoveryService.php`

**Method to Update:**

- Likely `getCoreEndpoints()` or similar method that builds metadata response

**URL Generation Pattern:**

```php
$revocationUrl = Url::fromRoute('simple_oauth_server_metadata.revoke', [], ['absolute' => TRUE])->toString();
```

**Route Validation:**
Use `RouteProviderInterface` to check if route exists before attempting URL generation

**Configuration Fallback:**
If route doesn't exist, fall back to `simple_oauth_server_metadata.settings.revocation_endpoint` config value

## Input Dependencies

**From Task 4:**

- Route `simple_oauth_server_metadata.revoke` must be defined

## Output Artifacts

- Updated `simple_oauth_server_metadata/src/Service/EndpointDiscoveryService.php`

This integration enables:

- Automatic endpoint discovery by OAuth clients
- RFC 8414 compliance for server metadata
- Zero-configuration setup for client applications
- Task 6 (Functional tests can validate metadata includes revocation endpoint)

## Implementation Notes

<details>
<summary>Detailed Implementation Guide</summary>

### Understanding EndpointDiscoveryService

First, examine the existing service to understand the pattern:

```bash
# Read the current implementation
cat simple_oauth_server_metadata/src/Service/EndpointDiscoveryService.php
```

Look for methods that build metadata responses, particularly how other endpoints are included (e.g., `token_endpoint`, `authorization_endpoint`).

### Typical Implementation Pattern

Services like this usually have a method that builds core endpoint metadata:

```php
public function getCoreEndpoints(): array {
  $endpoints = [];

  // Existing endpoints (authorization, token, etc.)
  // ...

  // Add revocation endpoint
  if ($this->routeProvider->getRouteByName('simple_oauth_server_metadata.revoke')) {
    $endpoints['revocation_endpoint'] = Url::fromRoute(
      'simple_oauth_server_metadata.revoke',
      [],
      ['absolute' => TRUE]
    )->toString();
  }
  else {
    // Fallback to configured value
    $configured = $this->configFactory
      ->get('simple_oauth_server_metadata.settings')
      ->get('revocation_endpoint');
    if (!empty($configured)) {
      $endpoints['revocation_endpoint'] = $configured;
    }
  }

  return $endpoints;
}
```

### Required Service Dependencies

Ensure the service has access to:

```php
public function __construct(
  // ... existing dependencies ...
  private readonly RouteProviderInterface $routeProvider,
  private readonly ConfigFactoryInterface $configFactory,
) {}
```

If these aren't already injected, add them to the constructor and update the service definition in `simple_oauth_server_metadata.services.yml`.

### Route Existence Validation

Before generating URLs, check if the route exists:

```php
try {
  $route = $this->routeProvider->getRouteByName('simple_oauth_server_metadata.revoke');
  // Route exists, safe to generate URL
} catch (RouteNotFoundException $e) {
  // Route doesn't exist, use fallback or omit
}
```

### Absolute URL Generation

RFC 8414 requires absolute URLs in metadata responses:

```php
// CORRECT - absolute URL
$url = Url::fromRoute('simple_oauth_server_metadata.revoke', [], ['absolute' => TRUE])->toString();
// Result: https://example.com/oauth/revoke

// INCORRECT - relative URL
$url = Url::fromRoute('simple_oauth_server_metadata.revoke')->toString();
// Result: /oauth/revoke (invalid for metadata)
```

### Configuration Fallback Logic

The configuration schema already has a `revocation_endpoint` field (per the plan). Use it as a fallback:

```yaml
# config/schema/simple_oauth_server_metadata.schema.yml
simple_oauth_server_metadata.settings:
  type: config_object
  mapping:
    # ... other settings ...
    revocation_endpoint:
      type: string
      label: 'Token Revocation Endpoint'
```

This allows manual configuration if needed, but auto-discovery should work when the route exists.

### Metadata Response Format

The metadata response should follow RFC 8414 format:

```json
{
  "issuer": "https://example.com",
  "authorization_endpoint": "https://example.com/oauth/authorize",
  "token_endpoint": "https://example.com/oauth/token",
  "revocation_endpoint": "https://example.com/oauth/revoke",
  "jwks_uri": "https://example.com/.well-known/jwks.json",
  ...
}
```

### Testing the Integration

After implementation:

```bash
# Rebuild cache
vendor/bin/drush cache:rebuild

# Fetch server metadata
curl https://your-site.com/.well-known/oauth-authorization-server | jq .

# Should include:
# "revocation_endpoint": "https://your-site.com/oauth/revoke"
```

### RFC 8414 Compliance

Per RFC 8414 Section 2:

- `revocation_endpoint` is an OPTIONAL metadata field
- MUST be an absolute HTTPS URL
- If present, clients can use it for token revocation

Including this field signals to clients that token revocation is supported.

### Error Handling

```php
try {
  $revocationUrl = Url::fromRoute('simple_oauth_server_metadata.revoke', [], ['absolute' => TRUE])->toString();
  $endpoints['revocation_endpoint'] = $revocationUrl;
} catch (\Exception $e) {
  // Log error but don't fail metadata response
  $this->logger->warning('Failed to generate revocation endpoint URL: @message', [
    '@message' => $e->getMessage(),
  ]);
  // Fall back to config or omit
}
```

### Backward Compatibility

Ensure changes don't break existing metadata responses:

- Don't modify existing endpoint logic
- Only add the new revocation endpoint
- Maintain existing method signatures
- Don't change service constructor signature without updating service definition

### Service Definition Update

If you add new dependencies, update `simple_oauth_server_metadata.services.yml`:

```yaml
simple_oauth_server_metadata.endpoint_discovery:
  class: Drupal\simple_oauth_server_metadata\Service\EndpointDiscoveryService
  arguments:
    - '@config.factory'
    - '@url_generator'
    - '@router.route_provider' # If adding
    # ... other dependencies
```

### Common Patterns to Follow

Look at how other endpoints are included in the service:

- Do they validate route existence?
- Do they use try-catch for URL generation?
- Do they have fallback logic?
- Do they use absolute URLs?

Match the existing patterns for consistency.

</details>
