---
id: 2
group: 'route-discovery-context'
dependencies: [1]
status: 'pending'
created: '2025-09-17'
skills: ['drupal-backend', 'routing']
---

# Fix Route Discovery in Test Environments

## Objective

Fix the `autoDetectRegistrationEndpoint()` method to work consistently across CLI, runtime, and test environments by implementing lazy route discovery and fallback mechanisms for different container contexts.

## Skills Required

- **drupal-backend**: Understanding of Drupal's service container, routing system, and context differences
- **routing**: Expertise in route discovery, router rebuilding, and container state management

## Acceptance Criteria

- [ ] `autoDetectRegistrationEndpoint()` method works in functional tests
- [ ] Route discovery functions correctly in CLI context
- [ ] Lazy route discovery defers resolution until request time
- [ ] Fallback mechanisms handle different container contexts
- [ ] Router rebuilds properly in test setUp when modules are enabled

## Technical Requirements

- Modify `autoDetectRegistrationEndpoint()` method in `ServerMetadataService`
- Implement lazy route discovery that defers resolution until needed
- Add fallback mechanisms for route detection across different contexts
- Ensure router service rebuilds correctly in test environments
- Handle container differences between test and runtime environments

## Input Dependencies

- Task 1: Cache tag synchronization must be completed first to ensure proper cache invalidation supports route discovery fixes

## Output Artifacts

- Updated `autoDetectRegistrationEndpoint()` method with lazy loading
- Robust route discovery that works across all execution contexts
- Fallback mechanisms for different container states

## Implementation Notes

<details>
<summary>Detailed Implementation Guide</summary>

### Root Cause Analysis

The current issue is that `autoDetectRegistrationEndpoint()` calls `$route_provider->getRouteByName()` which relies on the router service state. In test environments, the container and routing context differ from runtime, causing the route discovery to fail.

### Key Areas to Address

1. **Lazy Route Discovery**:

   ```php
   protected function autoDetectRegistrationEndpoint(): ?string {
     // Defer route discovery until request time, not service construction
     $request = \Drupal::requestStack()->getCurrentRequest();
     if (!$request) {
       // In CLI or test context, try alternative discovery
       return $this->fallbackRouteDiscovery();
     }

     // Standard route discovery for web requests
     return $this->discoverRouteInContext();
   }
   ```

2. **Context-Aware Discovery**:
   - Detect execution context (CLI, web, test)
   - Use appropriate discovery method for each context
   - Handle cases where router hasn't been built yet

3. **Fallback Mechanisms**:

   ```php
   private function fallbackRouteDiscovery(): ?string {
     // Try multiple discovery approaches
     // 1. Check if route exists in router
     // 2. Check module enablement status
     // 3. Generate URL based on known pattern
   }
   ```

4. **Test Environment Handling**:
   - Ensure router rebuilds in test setUp
   - Handle container rebuilding in functional tests
   - Provide reliable discovery in isolated test environments

### Files to Modify

- `modules/simple_oauth_server_metadata/src/Service/ServerMetadataService.php`
- Possibly add helper methods for context detection

### Testing Strategy

- Test route discovery in CLI (`drush eval` context)
- Test route discovery in functional test context
- Test route discovery in normal web request context
- Verify fallback mechanisms work when router is not ready

### Error Handling

- Graceful fallback when routes are not available
- Logging for debugging discovery issues
- Clear error messages for troubleshooting

</details>
