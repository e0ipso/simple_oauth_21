---
id: 6
group: 'rfc-9728-protected-resource'
dependencies: []
status: 'completed'
created: '2025-09-16'
skills: ['api-endpoints', 'drupal-backend']
complexity_score: 5.0
---

# Add Protected Resource Metadata to Server Module

## Objective

Extend the existing `simple_oauth_server_metadata` submodule to provide the `/.well-known/oauth-protected-resource` endpoint by adding a `ResourceMetadataController` and `ResourceMetadataService` following established patterns.

## Skills Required

- **api-endpoints**: Well-known endpoint implementation, JSON responses
- **drupal-backend**: Service extension, controller patterns

## Acceptance Criteria

- [ ] `/.well-known/oauth-protected-resource` route added to server_metadata module
- [ ] `ResourceMetadataController` created following `ServerMetadataController` patterns
- [ ] `ResourceMetadataService` with caching following `ServerMetadataService` patterns
- [ ] RFC 9728 compliant JSON response structure
- [ ] Configuration form extension for resource-specific settings

## Technical Requirements

**RFC 9728 Response Fields:**

- `resource` - Resource server identifier
- `authorization_servers` - Array of supported authorization servers
- `bearer_methods_supported` - Token transmission methods
- `resource_documentation` - Documentation URI
- `resource_policy_uri` - Resource policy URI
- `resource_tos_uri` - Terms of service URI

## Input Dependencies

None - extends existing server_metadata module

## Output Artifacts

- Functional `/.well-known/oauth-protected-resource` endpoint
- Resource metadata service with caching
- Extended configuration forms

## Implementation Notes

<details>
<summary>Detailed Implementation Instructions</summary>

Extend `simple_oauth_server_metadata` module by copying exact patterns:

**Routing Addition (in simple_oauth_server_metadata.routing.yml):**

```yaml
simple_oauth_server_metadata.resource_metadata:
  path: '/.well-known/oauth-protected-resource'
  defaults:
    _controller: 'ResourceMetadataController::metadata'
    _title: 'OAuth 2.0 Protected Resource Metadata'
  methods: [GET]
  requirements:
    _access: 'TRUE'
  options:
    _format: 'json'
    no_cache: FALSE
```

**Controller Structure (copy ServerMetadataController exactly):**

```php
<?php

namespace Drupal\simple_oauth_server_metadata\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

class ResourceMetadataController extends ControllerBase {

  protected $resourceMetadataService;

  // Copy exact constructor and create() patterns from ServerMetadataController

  public function metadata(): JsonResponse {
    // Copy exact structure from ServerMetadataController::metadata()
    // Use ResourceMetadataService instead of ServerMetadataService
  }
}
```

**Service Structure (extend ServerMetadataService patterns):**

```php
class ResourceMetadataService {
  // Copy exact caching structure from ServerMetadataService
  // Implement getCacheTags(), invalidateCache(), warmCache()
  // Generate RFC 9728 compliant metadata
}
```

**Service Registration (in simple_oauth_server_metadata.services.yml):**

```yaml
simple_oauth_server_metadata.resource_metadata:
  class: Drupal\simple_oauth_server_metadata\Service\ResourceMetadataService
  arguments: ['@cache.default', '@config.factory']
```

**Configuration Form Extension:**
Extend `ServerMetadataSettingsForm` to include resource-specific fields following the exact field definition patterns already established in the form.

</details>
