---
id: 5
group: 'cleanup-optimization'
dependencies: [4]
status: 'pending'
created: '2025-09-29'
skills: ['drupal-backend', 'css']
---

# Clean Up Dead Code and Styles After SDC Migration

## Objective

Remove obsolete code, unused CSS, and legacy styling patterns that are no longer needed after the migration to Single-Directory Components, ensuring a clean and optimized codebase.

## Skills Required

- **drupal-backend**: Code analysis and cleanup in Drupal modules
- **css**: Stylesheet analysis and optimization

## Acceptance Criteria

- [ ] All unused helper methods and HTML generation functions removed
- [ ] Obsolete CSS classes and styling rules identified and cleaned up
- [ ] Legacy render array helper functions eliminated
- [ ] Unused imports and dependencies removed
- [ ] Code documentation updated to reflect new component architecture

## Technical Requirements

- Identify and remove methods that were only used for HTML generation
- Clean up CSS classes that are no longer applied after SDC migration
- Remove any inline styling or legacy presentation logic
- Optimize imports and remove unused dependencies
- Update comments and documentation to reflect component-based approach

## Input Dependencies

- Completed SDC migration and validation from Tasks 3 and 4
- Understanding of what code patterns were replaced by components

## Output Artifacts

- Cleaned up controller code with removed legacy methods
- Optimized CSS with unused rules removed
- Updated documentation reflecting component architecture
- Reduced codebase size and improved maintainability

## Implementation Notes

<details>
<summary>Detailed Implementation Guidance</summary>

### Code Cleanup Strategy

#### 1. Controller Method Cleanup

Remove methods that were only used for HTML generation:

**Identify candidates for removal:**

- Helper methods that only generate HTML tags
- Methods that build complex render arrays no longer used
- Utility functions for CSS class generation
- Inline styling generation methods

**Example removals:**

```php
// Remove methods like this that are now handled by SDCs
private function getStatusIndicator(string $status_level): string {
  return match ($status_level) {
    'fully_configured' => '✅',
    'enabled_unconfigured' => '⚠️',
    'installed_disabled' => '🔧',
    'not_installed' => '⬇️',
    default => '❓',
  };
}

// If this method only builds HTML that's now in components
private function buildComplexRenderArray($data): array {
  // Complex html_tag generation logic
}
```

**Keep if still used:**

- Methods that process business logic or data transformation
- Methods that prepare data for component props
- Utility methods used by multiple components

#### 2. CSS and Styling Cleanup

**Identify unused CSS classes:**

- Classes that were only used by removed html_tag elements
- Legacy styling for HTML structures now handled by components
- Redundant utility classes replaced by component styling

**Analysis method:**

```bash
# Search for CSS classes in codebase
grep -r "class.*=" --include="*.php" src/
grep -r "\.your-class" --include="*.css" .
```

**Common cleanup targets:**

- `.status-cell` classes if replaced by component styling
- `.module-header` classes now handled by module-card component
- `.priority-badge` classes replaced by status-badge component
- Complex layout classes replaced by component structure

**Example CSS cleanup:**

```css
/* Remove unused classes like these */
.legacy-status-indicator {
  /* Styling now handled by status-badge component */
}

.old-module-card-header {
  /* Layout now handled by module-card component */
}

.inline-generated-classes {
  /* Classes that were dynamically generated in PHP */
}
```

#### 3. Import and Dependency Cleanup

**Remove unused imports:**

```php
// Remove if no longer needed
use Drupal\Core\Url;  // If only used for HTML generation
use Some\Helper\Class; // If only used by removed methods
```

**Check for unused dependencies:**

- Review composer.json for packages only used by removed code
- Check .info.yml dependencies that may no longer be needed
- Validate that removed functionality doesn't break dependencies

#### 4. Documentation Updates

**Update method documentation:**

- Remove @deprecated tags for methods that are now deleted
- Update class-level documentation to mention component architecture
- Add component usage examples in place of removed HTML generation docs

**Update README/documentation:**

```markdown
## UI Components

This module now uses Single-Directory Components for UI rendering:

- status-badge: For module status indicators
- module-card: For OAuth capability display
- metric-display: For statistical information
- action-button: For user actions

Legacy HTML generation methods have been removed in favor of reusable components.
```

### Cleanup Validation Process

#### 1. Functional Testing

After each cleanup step:

- [ ] Run existing tests to ensure no functionality broken
- [ ] Load dashboard to verify visual consistency maintained
- [ ] Check that all interactive elements still work

#### 2. Code Analysis

- [ ] No unused methods remain in the controller
- [ ] No unreferenced CSS classes in stylesheets
- [ ] All imports are used by remaining code
- [ ] No dead code paths or unreachable statements

#### 3. Performance Validation

- [ ] Confirm CSS file size reduction
- [ ] Validate JavaScript bundle size (if applicable)
- [ ] Verify no performance regressions from cleanup

### Safe Cleanup Checklist

**Before removing any code:**

- [ ] Confirm the code is only used for HTML generation
- [ ] Verify no other modules or themes depend on the functionality
- [ ] Check that the component equivalent provides the same functionality
- [ ] Run tests to ensure no hidden dependencies

**For CSS cleanup:**

- [ ] Search entire codebase for class usage before removing
- [ ] Check if theme overrides might use the classes
- [ ] Verify component styling covers all use cases
- [ ] Test with different admin themes

**Documentation updates:**

- [ ] Update inline code comments
- [ ] Revise method documentation
- [ ] Update any README or user documentation
- [ ] Add component usage examples

### Cleanup Priorities

1. **High Priority - Remove immediately:**
   - Methods that only generate HTML now handled by components
   - CSS classes that were only used by removed HTML generation
   - Unused imports and dependencies

2. **Medium Priority - Review carefully:**
   - Helper methods that might have mixed purposes
   - CSS classes that might be used by themes
   - Documentation that references old patterns

3. **Low Priority - Consider for future:**
   - Legacy compatibility layers (if any exist)
   - Deprecated functionality with transition periods
   - Code that might be used by other modules

### Expected Cleanup Results

**Code reduction:**

- 20-30% reduction in controller method count
- 15-25% reduction in CSS file size
- Simplified imports and dependencies

**Maintainability improvements:**

- Cleaner, more focused controller methods
- Organized component-based architecture
- Simplified testing and debugging

**Documentation clarity:**

- Clear component usage patterns
- Removal of outdated HTML generation examples
- Updated architecture documentation
</details>
