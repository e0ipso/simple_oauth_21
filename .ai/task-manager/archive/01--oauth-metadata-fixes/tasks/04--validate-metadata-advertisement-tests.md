---
id: 4
group: 'validation-testing'
dependencies: [1, 2, 3]
status: 'pending'
created: '2025-09-17'
skills: ['drupal-testing', 'oauth-rfcs']
---

# Validate Metadata Advertisement in Tests

## Objective

Verify that all implemented fixes work correctly by ensuring the metadata endpoint test passes and the registration endpoint is properly advertised in server metadata across all execution contexts.

## Skills Required

- **drupal-testing**: Expertise in functional testing validation, test debugging, and comprehensive test coverage
- **oauth-rfcs**: Understanding of RFC 8414 compliance requirements and OAuth metadata standards

## Acceptance Criteria

- [ ] All five functional tests in `ClientRegistrationFunctionalTest` pass
- [ ] Registration endpoint appears in `/.well-known/oauth-authorization-server` metadata response
- [ ] Metadata updates reflect immediately in test environments after configuration changes
- [ ] Auto-detection works correctly in CLI, web, and test environments
- [ ] No performance regression in production metadata endpoint response times

## Technical Requirements

- Run complete functional test suite to verify all fixes
- Validate metadata endpoint advertisement across different contexts
- Verify cache invalidation works correctly in all scenarios
- Test auto-detection in multiple execution environments
- Ensure RFC 8414 compliance with proper endpoint advertisement

## Input Dependencies

- Task 1: Cache tag synchronization implementation
- Task 2: Route discovery fixes for test environments
- Task 3: Test environment cache handling improvements

## Output Artifacts

- Validated working metadata advertisement system
- Complete functional test suite passing
- Documentation of validation results and any remaining issues

## Implementation Notes

<details>
<summary>Detailed Implementation Guide</summary>

### Comprehensive Validation Strategy

This task focuses on validation rather than implementation. It ensures all previous fixes work together correctly.

### Key Validation Areas

1. **Functional Test Validation**:

   ```bash
   # Run the specific failing test
   vendor/bin/phpunit web/modules/contrib/simple_oauth_21/tests/src/Functional/ClientRegistrationFunctionalTest.php --filter="testMetadataEndpoints"

   # Run full test suite to ensure no regressions
   vendor/bin/phpunit web/modules/contrib/simple_oauth_21/tests/src/Functional/ClientRegistrationFunctionalTest.php
   ```

2. **Cross-Context Metadata Verification**:

   ```bash
   # Test CLI context
   vendor/bin/drush eval "
   \$service = \Drupal::service('simple_oauth_server_metadata.server_metadata');
   \$metadata = \$service->getServerMetadata();
   var_dump(isset(\$metadata['registration_endpoint']));
   "

   # Test HTTP context
   curl -s http://web/.well-known/oauth-authorization-server | jq .registration_endpoint
   ```

3. **Cache Invalidation Verification**:
   - Verify configuration changes trigger metadata updates
   - Test cache clearing works across all layers
   - Ensure test environment bypasses caching appropriately

4. **Performance Validation**:
   - Measure metadata endpoint response times before/after changes
   - Verify no regression in production caching behavior
   - Validate cache hit rates remain optimal

### Success Criteria Verification

1. **Registration Endpoint Advertisement**:
   - Endpoint appears in metadata JSON response
   - URL is correctly formatted and accessible
   - Advertisement works across all execution contexts

2. **Test Suite Completion**:
   - All five tests in `ClientRegistrationFunctionalTest` pass
   - No new test failures introduced
   - Existing OAuth functionality remains intact

3. **Cache Performance**:
   - No measurable performance regression
   - Cache invalidation works without excessive overhead
   - Production caching behavior unchanged

### Files to Validate

- Metadata endpoint: `/.well-known/oauth-authorization-server`
- Test file: `tests/src/Functional/ClientRegistrationFunctionalTest.php`
- Service implementation: `ServerMetadataService.php`
- Controller caching: `ServerMetadataController.php`

### Debugging Support

If validation fails:

- Check cache tag implementation in Task 1
- Verify route discovery fixes in Task 2
- Validate test cache handling in Task 3
- Provide detailed error analysis and recommendations

### Documentation Requirements

- Document validation results
- Record any remaining issues or edge cases
- Provide recommendations for future improvements
- Ensure RFC 8414 compliance is maintained

</details>
