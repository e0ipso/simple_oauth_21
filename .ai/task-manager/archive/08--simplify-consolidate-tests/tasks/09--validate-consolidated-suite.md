---
id: 9
group: 'validation'
dependencies: [3, 4, 5, 6, 7, 8]
status: 'pending'
created: '2025-10-10'
skills:
  - phpunit
  - bash
---

# Validate Consolidated Test Suite

## Objective

Run the complete consolidated test suite, measure performance improvements, verify code coverage, and validate that all success criteria from the plan have been met. Produce final metrics report comparing baseline to post-consolidation results.

## Skills Required

- **phpunit**: Running PHPUnit test suites with coverage analysis
- **bash**: Scripting metrics collection and comparison

## Acceptance Criteria

- [ ] Full test suite passes with 0 failures
- [ ] Test execution time ≤40% of baseline (60%+ improvement)
- [ ] Exactly 6 functional test classes remain (one per module)
- [ ] Each functional class has one public test method
- [ ] Code coverage ≥95% of baseline
- [ ] Test code reduced by ≥30%
- [ ] Final metrics document created comparing baseline to results
- [ ] All deleted tests documented with removal rationale

## Technical Requirements

- Access to baseline metrics from task #1
- PHPUnit configured with coverage tools (if available)
- All modules enabled in test environment
- Ability to run full test suite

## Input Dependencies

- Completed consolidation tasks #3-7 (all functional tests consolidated)
- Completed task #8 (kernel tests reviewed)
- Baseline metrics from task #1

## Output Artifacts

- **Final metrics report**: `.ai/task-manager/plans/08--simplify-consolidate-tests/final-metrics.txt`
- **Success criteria validation**: Document showing each criterion met/not met
- **Test deletion log**: List of all removed tests with rationale

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Run Full Test Suite

Execute complete test suite and capture output:

```bash
cd /var/www/html
time vendor/bin/phpunit web/modules/contrib/simple_oauth_21__consolidate_tests/ > /tmp/consolidated-test-output.txt 2>&1
```

Note the execution time reported.

### Step 2: Verify Test Pass Rate

Check test output:

```bash
grep -E "(OK|FAILURES|ERRORS)" /tmp/consolidated-test-output.txt
```

**Expected**: All tests pass (OK status)

**If failures exist**: Review and fix before proceeding. Failures indicate consolidation errors.

### Step 3: Count Functional Test Files

```bash
echo "Functional test count:"
find web/modules/contrib/simple_oauth_21__consolidate_tests -path "*/Functional/*Test.php" -type f | wc -l

echo "Functional test files:"
find web/modules/contrib/simple_oauth_21__consolidate_tests -path "*/Functional/*Test.php" -type f -exec basename {} \;
```

**Expected**: Exactly 6 files:

1. `simple_oauth_21`: 1 functional test
2. `simple_oauth_device_flow`: 1 functional test
3. `simple_oauth_pkce`: 1 functional test
4. `simple_oauth_native_apps`: 1 functional test
5. `simple_oauth_server_metadata`: 1 functional test
6. `simple_oauth_client_registration`: 0 or 1 functional test

### Step 4: Verify Single Test Method Per Class

For each functional test file:

```bash
for file in $(find web/modules/contrib/simple_oauth_21__consolidate_tests -path "*/Functional/*Test.php" -type f); do
  echo "=== $(basename $file) ==="
  grep "public function test" "$file" | wc -l
  grep "public function test" "$file"
  echo
done
```

**Expected**: Each file shows exactly 1 public test method (starting with `test`)

### Step 5: Measure Code Coverage (If Available)

```bash
vendor/bin/phpunit --coverage-text web/modules/contrib/simple_oauth_21__consolidate_tests/ | tee /tmp/consolidated-coverage.txt
```

Extract coverage percentage and compare to baseline.

**If coverage tools not available**: Note this in final report.

### Step 6: Count Test Code Lines

```bash
echo "Total lines of test code:"
find web/modules/contrib/simple_oauth_21__consolidate_tests -name "*Test.php" -type f -exec wc -l {} + | tail -1

echo "Total test files:"
find web/modules/contrib/simple_oauth_21__consolidate_tests -name "*Test.php" -type f | wc -l
```

Compare to baseline (target: ≥30% reduction).

### Step 7: Calculate Performance Improvement

Compare execution time from Step 1 to baseline:

```
Baseline time: [X] seconds
Consolidated time: [Y] seconds
Improvement: ((X - Y) / X) * 100 = [Z]%
```

**Target**: Z ≥ 60% (consolidated time ≤40% of baseline)

### Step 8: Document Deleted Tests

Create comprehensive list of removed tests:

```bash
# Compare git diff to see deleted files
git status
git diff --name-status --diff-filter=D
```

For each deleted test, document:

- File name
- Test methods removed
- Classification (language feature, upstream validation, trivial, etc.)
- Rationale for removal

### Step 9: Create Final Metrics Report

Create `.ai/task-manager/plans/08--simplify-consolidate-tests/final-metrics.txt`:

```
OAuth 2.1 Test Suite Consolidation Results
===========================================
Generated: [timestamp]

PERFORMANCE IMPROVEMENT
-----------------------
Baseline execution time:     [X] seconds
Consolidated execution time: [Y] seconds
Improvement:                 [Z]% faster

Target: ≥60% improvement
Status: [✓ MET | ✗ NOT MET]

CODE COVERAGE
-------------
Baseline coverage:           [X]%
Consolidated coverage:       [Y]%
Coverage retention:          [Z]%

Target: ≥95% of baseline
Status: [✓ MET | ✗ NOT MET]

TEST CODE REDUCTION
-------------------
Baseline lines of code:      [X] lines
Consolidated lines of code:  [Y] lines
Reduction:                   [Z]%

Target: ≥30% reduction
Status: [✓ MET | ✗ NOT MET]

TEST FILE COUNT
---------------
Baseline total files:        17 files
Consolidated total files:    [X] files
Files removed:               [Y] files

Baseline functional tests:   11 files
Consolidated functional:     6 files
Functional tests removed:    5 files

TARGET: Exactly 6 functional test classes
Status: [✓ MET | ✗ NOT MET]

FUNCTIONAL TEST STRUCTURE
--------------------------
Each functional test class has:
- One public test method:    [✓ YES | ✗ NO]
- Multiple helper methods:   [✓ YES | ✗ NO]

Module breakdown:
1. simple_oauth_21:                  [X] public test methods
2. simple_oauth_device_flow:         [X] public test methods
3. simple_oauth_pkce:                [X] public test methods
4. simple_oauth_native_apps:         [X] public test methods
5. simple_oauth_server_metadata:     [X] public test methods
6. simple_oauth_client_registration: [X] public test methods

TARGET: Each has exactly 1 public test method
Status: [✓ MET | ✗ NOT MET]

TEST SUITE HEALTH
-----------------
All tests passing:           [✓ YES | ✗ NO]
Test failures:               [X] failures
Test errors:                 [X] errors
Skipped tests:               [X] skipped

SUCCESS CRITERIA SUMMARY
------------------------
1. Test suite executes in ≤40% of original time:  [✓ | ✗]
2. 100% of removed tests validate non-business logic: [✓ | ✗]
3. Exactly 6 functional test classes remain:      [✓ | ✗]
4. Zero regression in RFC compliance coverage:    [✓ | ✗]
5. Test suite passes 100%:                        [✓ | ✗]
6. Code coverage ≥95% of baseline:                [✓ | ✗]
7. Test code reduction ≥30%:                      [✓ | ✗]

OVERALL STATUS: [✓ ALL CRITERIA MET | ✗ SOME CRITERIA NOT MET]
```

### Step 10: Create Test Deletion Log

Create `.ai/task-manager/plans/08--simplify-consolidate-tests/deleted-tests.txt`:

```
Deleted Tests Log
=================

UNIT TESTS REMOVED
------------------
1. DeviceCodeEntityTest.php
   Methods:
   - testLeagueInterfaceCompliance()
   - testRequiredMethodsExist()
   Rationale: Both methods validate language features and upstream
              library interface compliance using reflection. PHP's
              type system enforces these constraints; runtime
              validation is redundant.

FUNCTIONAL TESTS MERGED (Original files deleted)
-------------------------------------------------
simple_oauth_server_metadata module:
1. OpenIdConfigurationFunctionalTest.php
   Merged into: ServerMetadataFunctionalTest.php
   Methods preserved as helpers: [list]

2. TokenRevocationEndpointTest.php
   Merged into: ServerMetadataFunctionalTest.php
   Methods preserved as helpers: [list]

simple_oauth_21 main module:
3. ClientRegistrationFunctionalTest.php
   Merged into: ComplianceDashboardTest.php
   Methods preserved as helpers: [list]

4. OAuthMetadataValidationTest.php
   Merged into: ComplianceDashboardTest.php
   Methods preserved as helpers: [list]

5. OAuthIntegrationContextTest.php
   Merged into: ComplianceDashboardTest.php
   Methods preserved as helpers: [list]

KERNEL TESTS REMOVED (if any)
------------------------------
[List any kernel tests removed with rationale]

TOTAL FILES REMOVED: [X]
TOTAL TEST METHODS REMOVED: [Y]
TOTAL TEST METHODS PRESERVED: [Z]
```

### Step 11: Validation Checklist

Review all success criteria:

**Primary Success Criteria:**

- [ ] Test suite executes in ≤40% of original time (≥60% improvement)
- [ ] 100% of removed tests validate non-business-logic code
- [ ] Exactly 6 functional test classes remain
- [ ] Zero regression in RFC compliance coverage

**Quality Assurance Metrics:**

- [ ] Test suite passes 100%
- [ ] Code coverage ≥95% of baseline
- [ ] Test code reduction ≥30%
- [ ] Clear test documentation in all helpers

### Step 12: Handle Failures

**If any criterion NOT met:**

1. **Document the gap**: What criterion failed and by how much?
2. **Analyze cause**: Why wasn't the target met?
3. **Propose solution**:
   - If performance target missed: Profile tests to find bottlenecks
   - If coverage dropped: Identify missing test scenarios
   - If structure wrong: Fix functional test consolidation
4. **Create follow-up task** if needed

### Step 13: Final Verification

Run tests one more time to ensure stability:

```bash
cd /var/www/html
vendor/bin/phpunit web/modules/contrib/simple_oauth_21__consolidate_tests/ --testdox
```

The `--testdox` flag provides human-readable output showing all test scenarios.

### Step 14: Commit Changes (If Appropriate)

If working in a feature branch and all criteria met:

```bash
git add -A
git status  # Review changes
# Do NOT commit yet - let user review final metrics first
```

**Important**: Do NOT create commit message with AI attribution per AGENTS.md guidelines.

### Success Indicators

**Green flags:**

- ✓ All tests pass
- ✓ Performance improvement ≥60%
- ✓ Exactly 6 functional test classes
- ✓ Each has 1 public test method
- ✓ Coverage maintained
- ✓ Code reduced by ≥30%

**Red flags requiring attention:**

- ✗ Test failures (consolidation introduced bugs)
- ✗ Performance improvement <60% (instance reuse not working)
- ✗ Coverage dropped significantly (tests removed business logic)
- ✗ More or fewer than 6 functional classes (consolidation incomplete)

</details>
