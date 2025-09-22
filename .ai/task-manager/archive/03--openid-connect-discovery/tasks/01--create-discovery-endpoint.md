---
id: 1
group: 'endpoint-implementation'
dependencies: []
status: 'completed'
created: '2025-01-22'
skills: ['drupal-backend', 'api-endpoints']
complexity_score: 3.2
complexity_notes: 'Single controller endpoint following established patterns'
---

# Create OpenID Connect Discovery Endpoint Route and Controller

## Objective

Implement the OpenID Connect Discovery endpoint at `/.well-known/openid-configuration` by creating the route definition and controller that handles requests and returns properly formatted JSON metadata.

## Skills Required

- **drupal-backend**: Drupal module development expertise for creating routes, controllers, and dependency injection
- **api-endpoints**: RESTful API implementation and JSON response handling with proper HTTP headers

## Acceptance Criteria

- [ ] Route defined at `/.well-known/openid-configuration` in routing.yml
- [ ] OpenIdConfigurationController created following ResourceMetadataController pattern
- [ ] Controller checks if OpenID Connect is enabled in simple_oauth module
- [ ] Returns 404 Not Found when OpenID Connect is disabled
- [ ] Controller injects OpenIdConfigurationService (dependency on task 2)
- [ ] Returns CacheableJsonResponse with proper cache metadata
- [ ] Implements error handling for service unavailability (503 status)
- [ ] Adds CORS headers for cross-origin requests
- [ ] Route is publicly accessible without authentication
- [ ] Follows Drupal coding standards

## Technical Requirements

- Route path: `/.well-known/openid-configuration`
- HTTP method: GET
- Response format: JSON
- Access: Public (no authentication)
- Response type: CacheableJsonResponse
- OpenID Connect check: Return 404 if OIDC is disabled in simple_oauth
- Error handling: 503 Service Unavailable for failures, 404 for OIDC disabled
- CORS headers: Access-Control-Allow-Origin: \*

## Input Dependencies

None - this is the foundation task for the endpoint

## Output Artifacts

- `simple_oauth_server_metadata.routing.yml` - Updated with new route
- `src/Controller/OpenIdConfigurationController.php` - New controller class

## Implementation Notes

<details>
<summary>Detailed implementation guidance</summary>

### Route Definition

Add to `simple_oauth_server_metadata.routing.yml`:

```yaml
simple_oauth_server_metadata.openid_configuration:
  path: '/.well-known/openid-configuration'
  defaults:
    _controller: '\Drupal\simple_oauth_server_metadata\Controller\OpenIdConfigurationController'
    _title: 'OpenID Connect Discovery'
  methods: [GET]
  requirements:
    _access: 'TRUE'
  options:
    _format: 'json'
    no_cache: FALSE
```

### Controller Implementation

Follow the exact pattern from `ResourceMetadataController.php`:

```php
<?php

namespace Drupal\simple_oauth_server_metadata\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_oauth_server_metadata\Service\OpenIdConfigurationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OpenIdConfigurationController extends ControllerBase {

  protected OpenIdConfigurationService $openIdConfigurationService;
  protected ConfigFactoryInterface $configFactory;

  public function __construct(
    OpenIdConfigurationService $openId_configuration_service,
    ConfigFactoryInterface $config_factory
  ) {
    $this->openIdConfigurationService = $openId_configuration_service;
    $this->configFactory = $config_factory;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('simple_oauth_server_metadata.openid_configuration'),
      $container->get('config.factory')
    );
  }

  public function handle(): Response {
    // Check if OpenID Connect is enabled in simple_oauth module
    $simple_oauth_config = $this->configFactory->get('simple_oauth.settings');
    if (!$simple_oauth_config->get('openid_connect')) {
      throw new NotFoundHttpException('OpenID Connect is not enabled');
    }

    try {
      $metadata = $this->openIdConfigurationService->getOpenIdConfiguration();
      $response = new CacheableJsonResponse($metadata);

      // Add cache metadata from service
      $response->addCacheableDependency($this->openIdConfigurationService);

      // Add CORS headers
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', 'GET');

      return $response;
    }
    catch (\Exception $e) {
      \Drupal::logger('simple_oauth_server_metadata')->error('Failed to generate OpenID Connect Discovery metadata: @message', ['@message' => $e->getMessage()]);
      return new Response('Service Unavailable', 503);
    }
  }
}
```

### Key Implementation Points

- Check if OpenID Connect is enabled in simple_oauth module configuration
- Return 404 Not Found if OpenID Connect is disabled
- Use dependency injection to inject OpenIdConfigurationService and ConfigFactory
- Follow ResourceMetadataController pattern exactly
- Implement proper error handling with logging
- Add CORS headers for cross-origin JavaScript clients
- Use CacheableJsonResponse with cache dependency from service
- Return 503 status code on service failures, 404 for OIDC disabled
</details>
