---
id: 3
group: 'controller-refactoring'
dependencies: [2, 6, 7]
status: 'pending'
created: '2025-09-29'
skills: ['drupal-backend', 'php']
---

# Refactor Controller to Use SDC Components

## Objective

Systematically replace all `html_tag` render arrays in the OAuth21ComplianceController with SDC component calls, simplifying the controller logic and improving code organization.

## Skills Required

- **drupal-backend**: Drupal render system and SDC integration patterns
- **php**: Controller refactoring and render array manipulation

## Acceptance Criteria

- [ ] All identified html_tag patterns replaced with SDC component calls
- [ ] Controller methods simplified with clean component invocations
- [ ] Presentation logic moved from PHP to component templates
- [ ] Data processing separated from UI generation
- [ ] All existing functionality preserved during migration

## Technical Requirements

- Replace html_tag render arrays with SDC render elements
- Use `#type => 'component'` with component names and props
- Pass data through props instead of embedded HTML generation
- Utilize slots for complex content areas requiring dynamic content
- Maintain existing CSS classes and styling compatibility

## Input Dependencies

- Complete SDC component library from Tasks 2, 6, and 7
- Component specifications and usage patterns from Task 1

## Output Artifacts

- Refactored controller with clean component-based rendering
- Simplified controller methods focused on business logic
- Eliminated 95%+ of html_tag render array duplication

## Implementation Notes

<details>
<summary>Detailed Implementation Guidance</summary>

### Migration Pattern

Replace html_tag patterns with SDC components using this transformation:

**Before (html_tag):**

```php
'status' => [
  '#type' => 'html_tag',
  '#tag' => 'span',
  '#value' => $status_indicator . ' ' . $capability['status']['label'],
  '#attributes' => ['class' => ['status-cell', 'status-' . $capability['status']['level']]],
],
```

**After (SDC component):**

```php
'status' => [
  '#type' => 'component',
  '#component' => 'oauth21_compliance:status-badge',
  '#props' => [
    'level' => $capability['status']['level'],
    'icon' => $this->getStatusIndicator($capability['status']['level']),
    'label' => $capability['status']['label'],
    'classes' => ['status-cell'],
  ],
],
```

### Method-by-Method Migration

Process controller methods in this order:

1. **buildRfcMatrixRows()** - Simple status badges and action buttons
2. **buildModulesMatrix()** - Module cards with status and actions
3. **buildSummarySection()** - Metric displays and progress indicators
4. **buildCapabilitiesShowcaseSection()** - Complex nested components
5. **buildUseCasesDisplay()** - Priority badges and module lists

### Component Usage Examples

#### Status Badge Usage

```php
// Replace status indicators throughout the controller
[
  '#type' => 'component',
  '#component' => 'oauth21_compliance:status-badge',
  '#props' => [
    'level' => $status_level,  // success, warning, error
    'icon' => $icon,           // emoji or CSS class
    'label' => $status_text,   // status message
    'classes' => $additional_classes,
  ],
]
```

#### Module Card Usage

```php
[
  '#type' => 'component',
  '#component' => 'oauth21_compliance:module-card',
  '#props' => [
    'title' => $capability['name'],
    'rfc' => $capability['rfc'],
    'description' => $capability['description'],
    'priority' => $capability['priority'],
  ],
  '#slots' => [
    'status' => $status_component,
    'actions' => $action_buttons,
  ],
]
```

#### Metric Display Usage

```php
[
  '#type' => 'component',
  '#component' => 'oauth21_compliance:metric-display',
  '#props' => [
    'label' => $this->t('OAuth Modules'),
    'value' => $this->t('@enabled/@total enabled', [
      '@enabled' => $enabled_modules,
      '@total' => $total_modules,
    ]),
    'type' => 'primary',
  ],
]
```

#### Action Button Usage

```php
[
  '#type' => 'component',
  '#component' => 'oauth21_compliance:action-button',
  '#props' => [
    'label' => $action['label'],
    'url' => $action['url'],
    'type' => 'small',
    'classes' => ['action-' . $action['action']],
  ],
]
```

### Data Processing Optimization

While refactoring, optimize data processing:

1. **Extract Helper Methods**
   - Move repeated logic to private methods
   - Create data transformation utilities
   - Simplify component prop preparation

2. **Eliminate Inline HTML**
   - Remove HTML string concatenation
   - Replace with structured data for props
   - Use slots for complex content areas

3. **Improve Code Organization**
   - Group related component creation logic
   - Create consistent naming patterns
   - Add clear comments for complex transformations

### Validation Strategy

For each refactored method:

1. **Functional Testing**
   - Compare rendered output before/after
   - Verify all dynamic content displays correctly
   - Test with different data scenarios

2. **Visual Validation**
   - Ensure styling remains consistent
   - Check responsive behavior
   - Validate admin theme compatibility

3. **Performance Check**
   - Monitor render times for complex sections
   - Verify caching still works effectively
   - Test with large datasets

### Method Refactoring Checklist

For each controller method:

- [ ] All html_tag instances identified and replaced
- [ ] Component props properly configured
- [ ] Slots used for dynamic content areas
- [ ] Helper methods extracted where beneficial
- [ ] Functionality preserved and tested
- [ ] Code simplified and more readable

### Error Handling

- Maintain existing error handling patterns
- Ensure component rendering failures don't break the dashboard
- Add fallbacks for missing component data
- Log any component-related errors appropriately

### Compatibility Considerations

- Preserve existing CSS class names for theme compatibility
- Maintain data attributes used by JavaScript
- Ensure accessibility attributes are preserved
- Keep admin theme integration intact
</details>
