---
id: 1
group: 'preparation'
dependencies: []
status: 'pending'
created: '2025-10-10'
skills:
  - phpunit
  - bash
---

# Measure Baseline Test Metrics

## Objective

Establish baseline performance and coverage metrics for the current test suite to enable accurate measurement of improvements after consolidation. This provides the "before" snapshot for success criteria validation.

## Skills Required

- **phpunit**: Running PHPUnit test suites and interpreting results
- **bash**: Scripting test execution and metrics collection

## Acceptance Criteria

- [ ] Full test suite execution time recorded
- [ ] Code coverage percentage measured and documented
- [ ] Total lines of test code counted
- [ ] Test file count by type (Unit/Kernel/Functional) documented
- [ ] Baseline metrics saved to file for later comparison

## Technical Requirements

- PHPUnit configured and functional in Drupal 11.1 environment
- Access to `vendor/bin/phpunit` command
- Ability to run tests with `--coverage-text` or similar coverage options
- Shell access for line counting and file operations

## Input Dependencies

None - this is the first task in the plan.

## Output Artifacts

- **Baseline metrics document** (suggested: `.ai/task-manager/plans/08--simplify-consolidate-tests/baseline-metrics.txt`)
  - Test execution time (in seconds)
  - Code coverage percentage
  - Total lines of test code
  - Test file counts by type
  - Timestamp of measurement

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Measure Test Execution Time

Run the full test suite and capture execution time:

```bash
cd /var/www/html
time vendor/bin/phpunit web/modules/contrib/simple_oauth_21__consolidate_tests/ > /tmp/test-output.txt 2>&1
```

Extract the total time from the output (PHPUnit reports "Time: X seconds" at the end).

### Step 2: Measure Code Coverage

Run tests with coverage (if xdebug or pcov available):

```bash
vendor/bin/phpunit --coverage-text web/modules/contrib/simple_oauth_21__consolidate_tests/ | tee /tmp/coverage.txt
```

Extract the overall coverage percentage from the output.

**Note**: If coverage tools aren't available, skip this and note it in the baseline document.

### Step 3: Count Test Code Lines

```bash
find web/modules/contrib/simple_oauth_21__consolidate_tests -name "*Test.php" -type f -exec wc -l {} + | tail -1
```

### Step 4: Count Test Files by Type

```bash
echo "Unit tests:"
find web/modules/contrib/simple_oauth_21__consolidate_tests -path "*/Unit/*Test.php" -type f | wc -l

echo "Kernel tests:"
find web/modules/contrib/simple_oauth_21__consolidate_tests -path "*/Kernel/*Test.php" -type f | wc -l

echo "Functional tests:"
find web/modules/contrib/simple_oauth_21__consolidate_tests -path "*/Functional/*Test.php" -type f | wc -l
```

### Step 5: Create Baseline Document

Create a file at `.ai/task-manager/plans/08--simplify-consolidate-tests/baseline-metrics.txt` with:

```
OAuth 2.1 Test Suite Baseline Metrics
Generated: [timestamp]

EXECUTION TIME
--------------
Total test suite execution: [X] seconds

CODE COVERAGE
-------------
Overall coverage: [X]%
(Or "Coverage tools not available")

TEST CODE SIZE
--------------
Total lines of test code: [X]
Total test files: [X]

TEST FILE BREAKDOWN
-------------------
Unit tests: [X] files
Kernel tests: [X] files
Functional tests: [X] files

MODULES TESTED
--------------
- simple_oauth_21 (main): [X] test files
- simple_oauth_device_flow: [X] test files
- simple_oauth_native_apps: [X] test files
- simple_oauth_pkce: [X] test files
- simple_oauth_server_metadata: [X] test files
- simple_oauth_client_registration: [X] test files
```

### Important Notes

- Run tests from the container/environment where they normally execute
- Ensure all modules are enabled before running tests
- If tests are failing, note the failure count but still record metrics
- This baseline will be compared against final metrics in task #9

</details>
