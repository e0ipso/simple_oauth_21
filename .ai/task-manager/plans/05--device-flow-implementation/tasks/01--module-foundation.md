---
id: 1
group: 'module-foundation'
dependencies: []
status: 'completed'
created: '2025-09-26'
skills: ['drupal-backend']
---

# Create Device Flow Module Foundation

## Objective

Create the basic module structure and configuration files for the simple_oauth_device_flow sub-module, including module definition, service registration, routing, and configuration schema.

## Skills Required

- **drupal-backend**: Module development patterns, service registration, routing configuration

## Acceptance Criteria

- [ ] Module info.yml file with proper dependencies and metadata
- [ ] Module .module file with basic hooks
- [ ] Services.yml file with repository and service definitions
- [ ] Routing.yml file with device authorization and verification endpoints
- [ ] Configuration schema for module settings

## Technical Requirements

- Follow simple_oauth_21 sub-module patterns (reference simple_oauth_pkce)
- Declare dependencies on simple_oauth and consumers modules
- Define routes for /oauth/device_authorization and /oauth/device endpoints
- Configure default settings for code lifetime, polling interval, user code length

## Input Dependencies

None - this is the foundation task

## Output Artifacts

- simple_oauth_device_flow.info.yml
- simple_oauth_device_flow.module
- simple_oauth_device_flow.services.yml
- simple_oauth_device_flow.routing.yml
- config/schema/simple_oauth_device_flow.schema.yml
- config/install/simple_oauth_device_flow.settings.yml

## Implementation Notes

<details>
<summary>Detailed implementation guidance</summary>

**Module Structure:**
Create the module at: `web/modules/contrib/simple_oauth_21/modules/simple_oauth_device_flow/`

**Info.yml pattern:**

```yaml
name: 'Simple OAuth Device Flow'
type: module
description: 'Implements RFC 8628 OAuth 2.0 Device Authorization Grant'
core_version_requirement: ^10 || ^11
package: OAuth
dependencies:
  - simple_oauth:simple_oauth
  - consumers:consumers
```

**Services pattern (study simple_oauth_pkce.services.yml):**

- Register DeviceCodeRepository implementing DeviceCodeRepositoryInterface
- Register UserCodeGenerator service
- Register DeviceCodeService for lifecycle management

**Routing pattern:**

- POST /oauth/device_authorization -> DeviceAuthorizationController::authorize
- GET /oauth/device -> DeviceVerificationController::form
- POST /oauth/device -> DeviceVerificationController::verify

**Configuration schema:**
Define schema for device_code_lifetime (1800), polling_interval (5), user_code_length (8), user_code_charset, verification_uri ('/oauth/device')

</details>
