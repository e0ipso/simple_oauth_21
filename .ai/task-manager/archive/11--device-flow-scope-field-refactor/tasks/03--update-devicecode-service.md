---
id: 3
group: 'service-layer'
dependencies: [1]
status: 'pending'
created: '2025-10-09'
skills:
  - drupal-backend
  - php
---

# Update DeviceCodeService for Field API

## Objective

Refactor DeviceCodeService to use Field API methods for scope handling instead of manual serialization, aligning with simple_oauth patterns.

## Skills Required

- **drupal-backend**: Understanding of Drupal Field API, service patterns, and entity manipulation
- **php**: Strong PHP skills for service layer refactoring and array operations

## Acceptance Criteria

- [ ] All `serialize()` and `unserialize()` calls removed from DeviceCodeService
- [ ] Scope assignment uses field API `appendItem()` method
- [ ] Scope retrieval uses field API `getScopes()` method
- [ ] Code follows simple_oauth service patterns
- [ ] PHPStan analysis passes with no new errors
- [ ] No breaking changes to service method signatures

Use your internal Todo tool to track these and keep on track.

## Technical Requirements

**File to modify:** `modules/simple_oauth_device_flow/src/Service/DeviceCodeService.php`

**Key changes:**

1. **Update scope assignment in `generateDeviceAuthorization()`:**
   - Replace `serialize()` call with field API operations
   - Use `appendItem(['scope_id' => $scope_id])` for each scope

2. **Update any scope reading operations:**
   - Replace `unserialize()` with `$device_code->get('scopes')->getScopes()`
   - Use field API methods consistently throughout

3. **Review TokenEntityNormalizer patterns:**
   - Ensure consistency with how simple_oauth handles scopes
   - Follow established patterns for scope manipulation

## Input Dependencies

- Task 1 completed (DeviceCode entity field definition updated)
- Review of TokenEntityNormalizer in simple_oauth module for patterns
- Current DeviceCodeService.php implementation (especially lines around 329-335)

## Output Artifacts

- Modified DeviceCodeService.php using Field API
- Service code aligned with simple_oauth patterns
- No serialization logic remaining

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Locate Current Serialization Usage

Find all occurrences of `serialize()` and `unserialize()` in DeviceCodeService.php. Based on the plan, the main usage is around line 332:

```php
// Current code (BEFORE):
$scope_array = !empty($scope) ? explode(' ', trim($scope)) : [];
$device_code_entity->set('scopes', serialize($scope_array));
```

### Step 2: Replace with Field API

Update the scope assignment logic:

```php
// New code (AFTER):
$scope_array = !empty($scope) ? explode(' ', trim($scope)) : [];

// Clear any existing scopes first.
$device_code_entity->set('scopes', []);

// Add each scope using field API.
foreach ($scope_array as $scope_id) {
  $device_code_entity->get('scopes')->appendItem(['scope_id' => $scope_id]);
}
```

### Step 3: Update Scope Reading Operations

Search for any code that reads scopes using `unserialize()`. Replace:

```php
// BEFORE:
$scopes_data = $device_code->get('scopes')->value;
$scope_identifiers = unserialize($scopes_data, ['allowed_classes' => FALSE]);
```

With:

```php
// AFTER:
$scopes = $device_code->get('scopes')->getScopes();
$scope_identifiers = array_map(function($scope) {
  return $scope->getIdentifier();
}, $scopes);
```

### Step 4: Review Integration Points

Check for any other methods in DeviceCodeService that interact with scopes:

1. **Device code creation**: Ensure scopes are properly set during entity creation
2. **Token exchange**: Verify scopes are correctly passed when exchanging device code for token
3. **Scope validation**: Ensure scope validation still works with field API

### Step 5: Align with simple_oauth Patterns

Review how simple_oauth's TokenEntityNormalizer handles scopes (src/Normalizer/TokenEntityNormalizer.php, lines 42-45):

```php
$scopes = array_map(function (ScopeEntityInterface $scope_entity) {
  $scope_id = $scope_entity instanceof ScopeEntity ?
    $scope_entity->getScopeObject()->id() :
    $scope_entity->getIdentifier();
  return ['scope_id' => $scope_id];
}, $token_entity->getScopes());
```

Ensure DeviceCodeService follows similar patterns when working with scope arrays.

### Step 6: Verify No Breaking Changes

Ensure that:

- Public method signatures remain unchanged
- Service interface (if any) is still satisfied
- Existing code calling DeviceCodeService continues to work
- Scope string format (space-separated) is still accepted

### Important Considerations

- **MUST** use `declare(strict_types=1);` at the top of the file
- **MUST** maintain comprehensive PHPDoc comments
- **Field API Methods**: Use `appendItem()` for adding, `getScopes()` for reading
- **Empty Scopes**: Handle empty scope arrays gracefully
- **Validation**: Let the field API handle scope validation automatically
- **Performance**: Consider that field API operations may have slight overhead, but this is acceptable for consistency

### Testing Approach

After implementation, verify:

```bash
cd /var/www/html
# Run Device Flow tests
vendor/bin/phpunit web/modules/contrib/simple_oauth_21/modules/simple_oauth_device_flow/tests
```

Specifically test:

- Device authorization generation with scopes
- Token exchange with scope preservation
- Empty scope handling
- Multiple scope handling

</details>
