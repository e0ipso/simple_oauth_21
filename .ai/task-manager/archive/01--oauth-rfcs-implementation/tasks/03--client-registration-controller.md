---
id: 3
group: 'rfc-7591-client-registration'
dependencies: [1]
status: 'pending'
created: '2025-09-16'
skills: ['api-endpoints', 'drupal-backend']
complexity_score: 4.0
---

# Client Registration Controller

## Objective

Create the `ClientRegistrationController` that handles `/oauth/register` POST requests with proper JSON response handling, following the exact patterns from `ServerMetadataController` for consistency.

## Skills Required

- **api-endpoints**: HTTP controllers, JSON request/response handling
- **drupal-backend**: Drupal controller structure, dependency injection

## Acceptance Criteria

- [ ] `ClientRegistrationController` class created in `src/Controller/`
- [ ] `register()` method handles POST requests to `/oauth/register`
- [ ] Proper JSON request parsing and validation
- [ ] JSON response structure matches RFC 7591 specification
- [ ] Error handling with appropriate HTTP status codes
- [ ] CORS headers and caching headers following ServerMetadataController pattern

## Technical Requirements

**Controller Structure:**

- Extend `ControllerBase`
- Dependency injection for registration service
- Method: `register(): JsonResponse`

**RFC 7591 Response Format:**

```json
{
  "client_id": "s6BhdRkqt3",
  "client_secret": "ZJYCqe3GGRvdrudKyZS0XhGv_Z45DuKhCUk0gx_i",
  "registration_access_token": "this.is.an.access.token.value.ffx83",
  "registration_client_uri": "https://server.example.com/connect/register?client_id=s6BhdRkqt3",
  "client_id_issued_at": 2893256800,
  "client_secret_expires_at": 2893276800
}
```

## Input Dependencies

- Task 1: Module structure and routing configuration

## Output Artifacts

- Functional `/oauth/register` endpoint
- JSON response controller following Simple OAuth patterns

## Implementation Notes

<details>
<summary>Detailed Implementation Instructions</summary>

Copy the exact structure from `ServerMetadataController::metadata()`:

**Controller Template:**

```php
<?php

namespace Drupal\simple_oauth_client_registration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ClientRegistrationController extends ControllerBase {

  protected $registrationService;

  public function __construct($registration_service) {
    $this->registrationService = $registration_service;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('simple_oauth_client_registration.service.registration')
    );
  }

  public function register(Request $request): JsonResponse {
    // 1. Parse JSON request body
    // 2. Validate required fields per RFC 7591
    // 3. Call registration service
    // 4. Return JSON response with proper headers
  }
}
```

**Response Headers (copy from ServerMetadataController):**

- `Content-Type: application/json; charset=utf-8`
- `Access-Control-Allow-Origin: *`
- `Access-Control-Allow-Methods: POST`
- Caching: `setMaxAge(0)` for registration responses

**Error Handling:**

- 400 Bad Request for invalid JSON or missing required fields
- 500 Internal Server Error for registration failures
- Follow RFC 7591 error response format

Copy the exact error handling pattern and response structure from `ServerMetadataController::metadata()` method.

</details>
