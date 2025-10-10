---
id: 2
group: 'unit-test-cleanup'
dependencies: []
status: 'pending'
created: '2025-10-10'
skills:
  - php
---

# Remove DeviceCodeEntityTest Unit Test

## Objective

Delete the `DeviceCodeEntityTest` unit test file as it only validates PHP language features and upstream library interface compliance, providing no meaningful coverage of module business logic.

## Skills Required

- **php**: Understanding PHP testing patterns to validate removal rationale

## Acceptance Criteria

- [ ] `DeviceCodeEntityTest.php` file deleted
- [ ] Verified test validates only language features (reflection-based method existence checks)
- [ ] Verified test validates only upstream interface compliance (League OAuth2 Server interfaces)
- [ ] No business logic tests removed
- [ ] Remaining unit test `DeviceCodeGrantTest` reviewed and retained if it validates grant logic

## Technical Requirements

- File location: `modules/simple_oauth_device_flow/tests/src/Unit/DeviceCodeEntityTest.php`
- Git for tracking file deletion

## Input Dependencies

None - can run in parallel with task #1.

## Output Artifacts

- Deleted unit test file
- Git commit removing the file (if working in feature branch)

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Review DeviceCodeEntityTest

Read the file at `modules/simple_oauth_device_flow/tests/src/Unit/DeviceCodeEntityTest.php` to confirm it only tests:

1. **`testLeagueInterfaceCompliance()`**: Uses `class_implements()` to check interface implementation
   - **Rationale for removal**: PHP's type system enforces interface compliance at runtime; this test is redundant

2. **`testRequiredMethodsExist()`**: Uses reflection to verify methods exist
   - **Rationale for removal**: PHP ensures methods exist; missing methods would cause immediate fatal errors, not silent failures

Both tests validate language features and upstream library contracts, not module-specific business logic.

### Step 2: Review DeviceCodeGrantTest

Check `modules/simple_oauth_device_flow/tests/src/Unit/Plugin/Oauth2Grant/DeviceCodeGrantTest.php`:

- **If it tests grant-specific logic** (device code generation, validation rules, expiration handling): **KEEP IT**
- **If it only tests getter/setters or interface compliance**: Consider for removal

Based on the plan's analysis, DeviceCodeGrantTest likely tests grant logic and should be kept.

### Step 3: Delete DeviceCodeEntityTest

```bash
rm modules/simple_oauth_device_flow/tests/src/Unit/DeviceCodeEntityTest.php
```

### Step 4: Verify No References

Check for any references to the deleted test:

```bash
grep -r "DeviceCodeEntityTest" modules/simple_oauth_device_flow/
grep -r "DeviceCodeEntityTest" phpunit.xml 2>/dev/null || true
```

If any references exist, remove them.

### Step 5: Run Remaining Unit Tests

Verify the other unit test still passes:

```bash
cd /var/www/html
vendor/bin/phpunit modules/simple_oauth_device_flow/tests/src/Unit/
```

### Critical Validation

Before deletion, confirm the test file contains ONLY these two methods:

1. `testLeagueInterfaceCompliance()`
2. `testRequiredMethodsExist()`

If additional test methods exist that validate business logic, DO NOT delete this file. Instead, mark this task as "needs-clarification" and document the additional methods.

</details>
