---
id: 1
group: 'cache-layer-synchronization'
dependencies: []
status: 'pending'
created: '2025-09-17'
skills: ['drupal-backend', 'cache-api']
---

# Implement Cache Tag Synchronization for Server Metadata

## Objective

Fix cache invalidation across all layers (HTTP headers, Drupal cache API, service-level cache) to ensure metadata configuration changes trigger complete cache invalidation and registration endpoint appears in server metadata.

## Skills Required

- **drupal-backend**: Deep understanding of Drupal's cache API, cache tags, and cache contexts
- **cache-api**: Expertise in multi-layer caching strategies and cache dependency management

## Acceptance Criteria

- [ ] Cache tags properly propagate through all cache layers
- [ ] Configuration changes trigger immediate cache invalidation
- [ ] Cache dependencies between configuration and metadata response are established
- [ ] Test environment cache contexts are added
- [ ] No performance regression in production metadata responses

## Technical Requirements

- Modify `ServerMetadataService` class to implement proper cache tags
- Add cache contexts for test environment detection
- Implement cache dependencies between `simple_oauth_server_metadata.settings` config and metadata response
- Ensure cache invalidation propagates to HTTP response headers
- Add cache tag support that works across CLI, web, and test environments

## Input Dependencies

None - this is a foundational fix that other tasks will build upon.

## Output Artifacts

- Updated `ServerMetadataService` with proper cache tag implementation
- Cache invalidation logic that works across all execution contexts
- Documentation of cache tag strategy

## Implementation Notes

<details>
<summary>Detailed Implementation Guide</summary>

### Key Areas to Address

1. **Cache Tag Implementation in ServerMetadataService**:
   - Add cache tags to `getServerMetadata()` method
   - Include `config:simple_oauth_server_metadata.settings` tag
   - Add custom tags for route-based auto-detection

2. **Cache Context for Test Environments**:

   ```php
   // Add cache context that differentiates test vs production
   $cache_contexts = ['url.path', 'user.permissions'];
   if (defined('DRUPAL_TEST_IN_CHILD_SITE')) {
     $cache_contexts[] = 'url.query_args';
   }
   ```

3. **Cache Dependencies**:
   - Ensure metadata cache depends on configuration changes
   - Invalidate when routes are rebuilt
   - Connect service-level cache to HTTP response cache

4. **HTTP Cache Header Management**:
   - Modify `ServerMetadataController` to respect cache tags
   - Ensure test environment bypasses HTTP caching
   - Add cache invalidation hooks

### Files to Modify

- `modules/simple_oauth_server_metadata/src/Service/ServerMetadataService.php`
- `modules/simple_oauth_server_metadata/src/Controller/ServerMetadataController.php`

### Testing Strategy

- Verify cache invalidation works in CLI context (`drush eval`)
- Test cache invalidation in functional test context
- Ensure configuration changes trigger immediate metadata updates
- Validate no performance impact on production responses

</details>
