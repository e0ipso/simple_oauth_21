---
id: 1
group: 'rfc-7591-client-registration'
dependencies: []
status: 'pending'
created: '2025-09-16'
skills: ['drupal-backend', 'php']
complexity_score: 4.0
---

# Create Client Registration Submodule

## Objective

Set up the `simple_oauth_client_registration` submodule structure with proper Drupal module configuration, service definitions, and routing setup following established Simple OAuth patterns.

## Skills Required

- **drupal-backend**: Module structure, .info.yml, .services.yml configuration
- **php**: PHP 8.3+ namespace and class structure

## Acceptance Criteria

- [ ] Module directory `modules/simple_oauth_client_registration/` created
- [ ] `simple_oauth_client_registration.info.yml` with proper dependencies on simple_oauth and simple_oauth_21
- [ ] `simple_oauth_client_registration.services.yml` with service definitions
- [ ] `simple_oauth_client_registration.routing.yml` with `/oauth/register` route
- [ ] Module can be enabled without errors
- [ ] Basic module structure follows simple_oauth_native_apps pattern

## Technical Requirements

- Dependencies: simple_oauth, simple_oauth_21, consumers
- Route: `/oauth/register` (POST method)
- Service definitions for ClientRegistrationController and supporting services
- Namespace: `\Drupal\simple_oauth_client_registration`

## Input Dependencies

None - this is a foundational task.

## Output Artifacts

- Complete Drupal submodule structure
- Service configuration for client registration functionality
- Route definitions for RFC 7591 endpoints

## Implementation Notes

<details>
<summary>Detailed Implementation Instructions</summary>

Follow the exact pattern from `simple_oauth_native_apps` module:

**Directory Structure:**

```
modules/simple_oauth_client_registration/
├── simple_oauth_client_registration.info.yml
├── simple_oauth_client_registration.services.yml
├── simple_oauth_client_registration.routing.yml
└── src/
    └── Controller/
```

**Info File Requirements:**

- type: module
- core_version_requirement: ^10 || ^11
- dependencies: simple_oauth, simple_oauth_21, consumers, serialization
- description: "RFC 7591 Dynamic Client Registration for OAuth 2.0"

**Services File:**
Define services for:

- `simple_oauth_client_registration.controller.registration`
- `simple_oauth_client_registration.service.registration`

**Routing File:**
Create route `simple_oauth_client_registration.register`:

- path: '/oauth/register'
- methods: [POST]
- controller: ClientRegistrationController::register
- requirements: \_access: 'TRUE' (public endpoint per RFC 7591)

Copy service registration patterns from `simple_oauth_server_metadata.services.yml` for dependency injection structure.

</details>
