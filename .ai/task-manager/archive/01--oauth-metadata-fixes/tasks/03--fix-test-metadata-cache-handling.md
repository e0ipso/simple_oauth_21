---
id: 3
group: 'test-environment-handling'
dependencies: [1, 2]
status: 'pending'
created: '2025-09-17'
skills: ['drupal-testing', 'cache-api']
---

# Fix Test Environment Metadata Cache Handling

## Objective

Ensure functional tests can reliably verify endpoint advertisement without cache interference by implementing test-specific cache clearing and bypassing aggressive HTTP caching during testing.

## Skills Required

- **drupal-testing**: Expertise in functional testing patterns, test environment setup, and test-specific configurations
- **cache-api**: Understanding of cache bypassing, test cache contexts, and cache invalidation strategies

## Acceptance Criteria

- [ ] Functional tests bypass HTTP caching during metadata verification
- [ ] Test-specific cache clearing affects all cache layers
- [ ] Metadata tests can verify fresh data without cache interference
- [ ] Test environment detection works correctly
- [ ] No impact on production caching behavior

## Technical Requirements

- Modify `ServerMetadataController` to detect test environments and adjust cache headers
- Implement comprehensive test cache clearing that affects all layers
- Provide test utilities for metadata verification with fresh data
- Ensure existing test cache invalidation covers the service-level cache
- Maintain separation between test and production caching strategies

## Input Dependencies

- Task 1: Cache tag synchronization provides foundation for proper test cache clearing
- Task 2: Route discovery fixes ensure auto-detection works in test context

## Output Artifacts

- Updated `ServerMetadataController` with test environment cache handling
- Enhanced test cache clearing utilities
- Test-specific cache bypassing mechanisms

## Implementation Notes

<details>
<summary>Detailed Implementation Guide</summary>

### Root Cause Analysis

The current issue is that the `ServerMetadataController` sets aggressive HTTP caching (`setMaxAge(3600)`, `setPublic()`) which conflicts with test expectations. Even when service-level cache is invalidated, the HTTP response cache prevents fresh metadata from being served.

### Key Areas to Address

1. **Test Environment Detection Enhancement**:

   ```php
   // In ServerMetadataController::metadata()
   if (defined('DRUPAL_TEST_IN_CHILD_SITE') || $this->isTestEnvironment()) {
     $response->setMaxAge(0);
     $response->setPrivate();
     $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
     $response->headers->set('Pragma', 'no-cache');
   }
   ```

2. **Enhanced Test Cache Clearing**:
   - Ensure `ClientRegistrationFunctionalTest::setUp()` properly clears all cache layers
   - Add cache tag-based clearing that works with the new cache tag implementation
   - Clear both configuration cache and metadata service cache

3. **Test-Specific Cache Context**:

   ```php
   // Add cache context specifically for test environments
   if (defined('DRUPAL_TEST_IN_CHILD_SITE')) {
     $cache_contexts[] = 'headers:cache-control';
   }
   ```

4. **Metadata Verification Utilities**:
   - Helper methods for tests to verify fresh metadata
   - Cache-busting techniques for test HTTP requests
   - Direct service access for bypassing HTTP layer in tests

### Files to Modify

- `modules/simple_oauth_server_metadata/src/Controller/ServerMetadataController.php`
- `tests/src/Functional/ClientRegistrationFunctionalTest.php` (enhance cache clearing)

### Testing Strategy

- Verify test cache clearing works with new cache tag system
- Test that configuration changes reflect immediately in test HTTP responses
- Ensure production caching behavior is unchanged
- Validate that all five functional tests pass including metadata endpoint test

### Integration with Previous Tasks

- Leverages cache tags from Task 1 for proper invalidation
- Uses improved route discovery from Task 2 for consistent auto-detection
- Completes the cache synchronization strategy across all layers

</details>
