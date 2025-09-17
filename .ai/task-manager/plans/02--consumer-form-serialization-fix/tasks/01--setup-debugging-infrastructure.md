---
id: 1
group: 'form-debugging'
dependencies: []
status: 'pending'
created: '2025-09-17'
skills: ['drupal-backend', 'php']
complexity_score: 4
complexity_notes: 'Low-medium complexity debugging setup task'
---

# Set up Debugging Infrastructure for Form Serialization Analysis

## Objective

Establish comprehensive debugging tools and logging mechanisms to identify the source of non-serializable closures in the Consumer entity form that cause AJAX serialization failures.

## Skills Required

- **drupal-backend**: Understanding of Drupal form API, form alter hooks, and entity forms
- **php**: PHP debugging techniques, serialization analysis, and closure detection

## Acceptance Criteria

- [ ] Custom logging service created to trace form element processing
- [ ] Form state inspection utility implemented to detect non-serializable elements
- [ ] Debug mode configuration added for detailed form analysis
- [ ] Logging system captures form alter hook execution sequence
- [ ] Closure detection mechanism identifies problematic form elements
- [ ] Debug output is structured and easily analyzable

## Technical Requirements

Create debugging infrastructure that can:

1. Log all form alter hooks affecting Consumer entity forms
2. Inspect form elements for closures before serialization attempts
3. Provide detailed form state dumps during AJAX processing
4. Track the sequence of form modifications across modules
5. Generate actionable reports on serialization issues

The debugging system should integrate with Drupal's existing logging mechanisms and be easily enabled/disabled for development environments.

## Input Dependencies

None - this is the foundational debugging infrastructure.

## Output Artifacts

- Custom debugging service with logging capabilities
- Form state inspection utilities
- Configuration for enabling/disabling debug mode
- Documentation on using the debugging tools
- Log output structure for analyzing form serialization issues

## Implementation Notes

<details>
<summary>Detailed Implementation Guidance</summary>

**Debugging Service Creation:**

1. Create a new service `simple_oauth_21.form_serialization_debugger` in `simple_oauth_21.services.yml`
2. Implement a class `FormSerializationDebugger` in `src/Service/FormSerializationDebugger.php`
3. Add methods for:
   - `logFormAlterHook()` - Log when form alter hooks are called
   - `inspectFormState()` - Examine form state for non-serializable elements
   - `detectClosures()` - Recursively scan form arrays for closure objects
   - `generateReport()` - Create structured analysis reports

**Form State Inspection:**

1. Use PHP's `is_callable()` and `is_object()` to detect potential closures
2. Recursively traverse form arrays using `array_walk_recursive()`
3. Check for specific patterns like `$form['#ajax']['callback']` that commonly contain closures
4. Log the exact location and type of non-serializable elements

**Configuration Integration:**

1. Add debug configuration to module's configuration schema
2. Use `\Drupal::config('simple_oauth_21.debug')` to check if debugging is enabled
3. Ensure debug logging only occurs in development environments

**Hook Integration:**

1. Implement `hook_form_alter()` to instrument Consumer entity forms
2. Add debugging calls before and after each form modification
3. Use `\Drupal::service('simple_oauth_21.form_serialization_debugger')` in hooks

**Error Handling:**

- Wrap all debugging operations in try-catch blocks
- Ensure debugging failures don't break form functionality
- Provide fallback logging to Drupal's standard logger if custom logging fails
</details>
