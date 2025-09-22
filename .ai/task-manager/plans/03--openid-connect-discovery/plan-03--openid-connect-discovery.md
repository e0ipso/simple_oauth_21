---
id: 3
summary: 'Implement OpenID Connect Discovery 1.0 endpoint at /.well-known/openid-configuration in the simple_oauth_server_metadata module'
created: 2025-01-22
---

# Plan: OpenID Connect Discovery Endpoint Implementation

## Original Work Order

> ## Summary
>
> Implement the OpenID Connect Discovery 1.0 endpoint at `/.well-known/openid-configuration` in the `simple_oauth_server_metadata` module. This endpoint is required for automatic discovery of OpenID Connect provider configuration by client applications, as specified in the OpenID Connect Discovery 1.0 specification.
>
> ## Background
>
> The `simple_oauth` module already provides comprehensive OpenID Connect support including:
>
> - UserInfo endpoint (`/oauth/userinfo`)
> - JWKS endpoint (`/oauth/jwks`)
> - OAuth 2.1 Server Metadata endpoint (`/.well-known/oauth-authorization-server`)
> - UserClaimsNormalizer for handling OpenID Connect claims
> - OpenID Connect scope entities and repositories
>
> The `simple_oauth_server_metadata` module currently only implements the Protected Resource Metadata endpoint (`/.well-known/oauth-protected-resource`) per RFC 9728. We need to extend it to also provide the OpenID Connect Discovery endpoint.
>
> ## Requirements
>
> ### Functional Requirements
>
> 1. **Endpoint Implementation**
>    - Path: `/.well-known/openid-configuration`
>    - Method: GET
>    - Response: JSON document with OpenID Connect provider metadata
>    - Must be publicly accessible (no authentication required)
> 2. **Required Metadata Fields** (per OpenID Connect Discovery 1.0)
>    - `issuer`: The issuer identifier (HTTPS URL)
>    - `authorization_endpoint`: URL of the authorization endpoint
>    - `token_endpoint`: URL of the token endpoint
>    - `userinfo_endpoint`: URL of the UserInfo endpoint
>    - `jwks_uri`: URL of the JSON Web Key Set document
>    - `scopes_supported`: Array of supported scope values
>    - `response_types_supported`: Array of supported response types
>    - `subject_types_supported`: Array of supported subject identifier types
>    - `id_token_signing_alg_values_supported`: Array of JWS signing algorithms
>    - `claims_supported`: Array of supported claim names
> 3. **Optional Metadata Fields**
>    - `response_modes_supported`
>    - `grant_types_supported`
>    - `token_endpoint_auth_methods_supported`
>    - `service_documentation`
>    - `claims_parameter_supported`
>    - `request_parameter_supported`
>    - `request_uri_parameter_supported`
>    - `require_request_uri_registration`
>
> ### Technical Requirements
>
> 1. **Integration with Existing Infrastructure**
>    - Leverage existing `UserClaimsNormalizer` to determine supported claims
>    - Use the `%simple_oauth.openid.claims%` parameter for claims list
>    - Reuse endpoint discovery logic from `EndpointDiscoveryService`
>    - Follow the established pattern from `ResourceMetadataController`
> 2. **Caching and Performance**
>    - Implement proper cache tags and contexts
>    - Return `CacheableJsonResponse` with appropriate cache metadata
>    - Cache should invalidate when configuration changes
> 3. **Error Handling**
>    - Return 503 Service Unavailable if metadata cannot be generated
>    - Log errors for debugging
>    - Validate metadata before returning
>
> ## Acceptance Criteria
>
> - [ ] Endpoint returns valid OpenID Connect Discovery metadata
> - [ ] All required fields are present and correctly populated
> - [ ] Claims list matches UserClaimsNormalizer configuration
> - [ ] Endpoint is publicly accessible without authentication
> - [ ] Response includes appropriate cache headers
> - [ ] CORS headers are properly set
> - [ ] Unit and functional tests pass
> - [ ] Metadata validates against OpenID Connect Discovery 1.0 specification
> - [ ] Integration with existing simple_oauth OpenID Connect features works correctly

## Executive Summary

This plan outlines the implementation of an OpenID Connect Discovery 1.0 endpoint at `/.well-known/openid-configuration` within the existing `simple_oauth_server_metadata` module. The endpoint will provide automatic discovery capabilities for OpenID Connect clients, enabling them to obtain provider configuration dynamically without manual configuration.

The implementation leverages the existing infrastructure in the `simple_oauth` ecosystem, particularly the OpenID Connect support already present in the parent module. By extending the `simple_oauth_server_metadata` module, which currently provides OAuth 2.1 Server Metadata and Protected Resource Metadata endpoints, we create a comprehensive metadata discovery solution that covers all standard OAuth and OpenID Connect discovery specifications.

The approach prioritizes reusability of existing components, proper caching for performance, and full compliance with the OpenID Connect Discovery 1.0 specification while maintaining consistency with the module's established patterns.

## Context

### Current State

The `simple_oauth` module ecosystem currently provides extensive OAuth 2.1 and OpenID Connect functionality, but lacks the OpenID Connect Discovery endpoint. The `simple_oauth_server_metadata` module exists and already implements:

- OAuth 2.1 Server Metadata endpoint at `/.well-known/oauth-authorization-server`
- Protected Resource Metadata endpoint at `/.well-known/oauth-protected-resource`

The infrastructure for metadata discovery is established through services like `EndpointDiscoveryService`, `GrantTypeDiscoveryService`, and `ScopeDiscoveryService`. OpenID Connect specific functionality exists in the parent `simple_oauth` module, including UserInfo endpoint, JWKS endpoint, and claims normalization through `UserClaimsNormalizer`.

However, OpenID Connect clients cannot automatically discover the provider's configuration, requiring manual configuration of endpoints and capabilities.

### Target State

After implementation, the module will provide a fully functional OpenID Connect Discovery endpoint that:

- Responds to requests at `/.well-known/openid-configuration` with valid JSON metadata
- Automatically discovers and reports all OpenID Connect capabilities from the simple_oauth module
- Integrates seamlessly with existing OAuth 2.1 metadata endpoints
- Provides proper caching and performance optimization
- Enables OpenID Connect clients to automatically configure themselves using the discovery document

### Background

OpenID Connect Discovery 1.0 is a critical component of the OpenID Connect protocol suite. It allows clients to dynamically discover information about OpenID Connect Providers, including endpoint URLs, supported scopes, response types, and other capabilities. This eliminates the need for manual configuration and enables more flexible, maintainable integrations.

The `simple_oauth_server_metadata` module was specifically created to handle metadata discovery endpoints, making it the appropriate location for this implementation. The existing pattern established by `ResourceMetadataController` and `ServerMetadataController` provides a clear template for implementation.

## Technical Implementation Approach

### Controller Layer

**Objective**: Create a controller that handles requests to the OpenID Connect Discovery endpoint and returns properly formatted metadata.

The controller will follow the established pattern from `ResourceMetadataController`, creating an `OpenIdConfigurationController` that:

- Handles GET requests to `/.well-known/openid-configuration`
- Injects the `OpenIdConfigurationService` to generate metadata
- Returns a `CacheableJsonResponse` with appropriate cache metadata
- Implements error handling for service unavailability
- Adds CORS headers to support cross-origin requests from JavaScript clients

### Service Layer

**Objective**: Implement a service that generates OpenID Connect Discovery metadata by aggregating information from various sources within the simple_oauth ecosystem.

The `OpenIdConfigurationService` will:

- Implement `CacheableDependencyInterface` for proper cache integration
- Leverage existing discovery services (`EndpointDiscoveryService`, `ScopeDiscoveryService`)
- Integrate with the `UserClaimsNormalizer` configuration to determine supported claims
- Generate both required and optional metadata fields according to the specification
- Validate the generated metadata before returning it

### Integration with Existing Infrastructure

**Objective**: Maximize reuse of existing components while maintaining module boundaries and responsibilities.

The implementation will:

- Use `EndpointDiscoveryService` to discover OAuth endpoints that are also used by OpenID Connect
- Access the `%simple_oauth.openid.claims%` parameter to determine supported claims
- Leverage configuration from `simple_oauth_server_metadata.settings` for issuer and other metadata
- Maintain consistency with existing metadata endpoints in response format and caching strategy

### Configuration Management

**Objective**: Extend the existing configuration form to support OpenID Connect Discovery specific settings.

The `ServerMetadataSettingsForm` will be enhanced to:

- Add a toggle for enabling/disabling the OpenID Connect Discovery endpoint
- Provide fields for optional metadata values specific to OpenID Connect
- Allow customization of supported response types and modes
- Enable configuration of service documentation URL

## Risk Considerations and Mitigation Strategies

### Technical Risks

- **Dependency on simple_oauth Module Structure**: The implementation relies on internal structure and services from the simple_oauth module
  - **Mitigation**: Use dependency injection and interfaces where possible to minimize coupling

- **Claims Discovery Complexity**: Determining supported claims from UserClaimsNormalizer configuration may be complex
  - **Mitigation**: Implement fallback to a default claims list if automatic discovery fails

- **Cache Invalidation**: Ensuring cache is properly invalidated when configuration changes
  - **Mitigation**: Use appropriate cache tags and contexts, following Drupal best practices

### Implementation Risks

- **Specification Compliance**: Ensuring full compliance with OpenID Connect Discovery 1.0 specification
  - **Mitigation**: Implement comprehensive validation and testing against specification requirements

- **Cross-Origin Request Handling**: CORS configuration must work correctly for JavaScript clients
  - **Mitigation**: Follow established CORS patterns in Drupal and test with various client scenarios

### Integration Risks

- **Version Compatibility**: Ensuring compatibility with different versions of simple_oauth module
  - **Mitigation**: Check for service availability and provide graceful degradation

## Success Criteria

### Primary Success Criteria

1. Endpoint responds with valid JSON at `/.well-known/openid-configuration`
2. All required OpenID Connect Discovery fields are present and correctly populated
3. Metadata validates against OpenID Connect Discovery 1.0 specification
4. Integration with existing simple_oauth OpenID Connect features functions correctly

### Quality Assurance Metrics

1. Unit test coverage exceeds 80% for new code
2. Functional tests verify all endpoint behaviors and error conditions
3. Response time remains under 100ms for cached responses
4. No regressions in existing simple_oauth_server_metadata functionality

## Resource Requirements

### Development Skills

- Drupal module development expertise
- Understanding of OAuth 2.0 and OpenID Connect specifications
- Experience with Drupal's caching and dependency injection systems
- Knowledge of RESTful API design and implementation

### Technical Infrastructure

- Drupal development environment with simple_oauth and simple_oauth_21 modules
- PHPUnit for testing
- Development tools for API testing (Postman, curl, etc.)
- Access to OpenID Connect Discovery specification documentation

## Integration Strategy

The implementation will integrate with existing systems through:

1. Service dependency injection to access simple_oauth services
2. Configuration schema extension to maintain backward compatibility
3. Cache tag integration with existing metadata caching strategy
4. Routing system integration following Drupal standards

## Notes

- The implementation must maintain backward compatibility with existing installations
- Consider future extensibility for additional OpenID Connect specifications
- Follow Drupal coding standards and best practices throughout
- Ensure proper documentation for API consumers

## Execution Blueprint

**Validation Gates:**

- Reference: `@.ai/task-manager/config/hooks/POST_PHASE.md`

### ✅ Phase 1: Foundation Implementation

**Parallel Tasks:**

- ✔️ Task 001: Create OpenID Connect Discovery Endpoint Route and Controller
- ✔️ Task 002: Implement OpenIdConfigurationService for Metadata Generation
- ✔️ Task 003: Update Configuration Form for OpenID Connect Discovery Settings

### Phase 2: Testing and Validation

**Parallel Tasks:**

- Task 004: Write Functional Tests for OpenID Connect Discovery Endpoint (depends on: 001, 002)

### Post-phase Actions

After successful completion of all phases:

1. Verify all acceptance criteria are met
2. Run final integration tests
3. Update documentation
4. Archive completed plan

### Execution Summary

- Total Phases: 2
- Total Tasks: 4
- Maximum Parallelism: 3 tasks (in Phase 1)
- Critical Path Length: 2 phases
