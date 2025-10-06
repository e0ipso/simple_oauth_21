---
id: 2
group: 'component-development'
dependencies: [1]
status: 'pending'
created: '2025-09-29'
skills: ['drupal-theming', 'twig']
complexity_score: 3.8
complexity_notes: 'Decomposed from original task due to high technical complexity (6.0 → 3.8)'
---

# Create Status Badge SDC Component

## Objective

Create a reusable Single-Directory Component for status indicators with icons and labels, the most frequently used UI pattern in the OAuth compliance module.

## Skills Required

- **drupal-theming**: Single-Directory Components architecture
- **twig**: Template development with props for flexible configuration

## Acceptance Criteria

- [ ] Status badge component created with proper directory structure
- [ ] Component YAML definition with flexible props schema
- [ ] Twig template supporting different status levels and icons
- [ ] Component handles success, warning, error, and info states
- [ ] Proper CSS classes for styling and theme compatibility

## Technical Requirements

- Create `components/status-badge/` directory structure
- Implement component.yml with props validation
- Create Twig template with conditional icon rendering
- Support status levels: success, warning, error, info
- Maintain existing CSS class compatibility

## Input Dependencies

- Component specifications from audit (Task 1)
- Understanding of status indicator usage patterns

## Output Artifacts

- Complete status-badge SDC component ready for integration
- Component handles 15+ usage instances identified in audit

## Implementation Notes

<details>
<summary>Detailed Implementation Guidance</summary>

### Component Structure

```
components/status-badge/
├── status-badge.component.yml
└── status-badge.twig
```

### Component Definition (status-badge.component.yml)

```yaml
$schema: https://git.drupalcode.org/project/drupal/-/raw/10.1.x/core/modules/sdc/src/metadata.schema.json
name: Status Badge
status: stable
description: Displays module status with icon and label
props:
  type: object
  properties:
    level:
      type: string
      enum: [success, warning, error, info]
      title: Status Level
      description: Visual level for styling and icon selection
    icon:
      type: string
      title: Icon
      description: Emoji or CSS class for status icon
    label:
      type: string
      title: Status Label
      description: Text description of the status
    classes:
      type: array
      items:
        type: string
      title: Additional CSS classes
      description: Extra classes for context-specific styling
  required: [level, label]
```

### Template Implementation (status-badge.twig)

```twig
{# status-badge.twig #}
<span class="status-badge status-badge--{{ level }}{{ classes ? ' ' ~ classes|join(' ') : '' }}">
  {% if icon %}
    <span class="status-badge__icon">{{ icon }}</span>
  {% endif %}
  <span class="status-badge__label">{{ label }}</span>
</span>
```

### Usage Examples

The component should handle these common patterns from the audit:

**Success Status:**

```php
[
  '#type' => 'component',
  '#component' => 'oauth21_compliance:status-badge',
  '#props' => [
    'level' => 'success',
    'icon' => '✅',
    'label' => 'Fully Configured',
    'classes' => ['status-cell'],
  ],
]
```

**Warning Status:**

```php
[
  '#type' => 'component',
  '#component' => 'oauth21_compliance:status-badge',
  '#props' => [
    'level' => 'warning',
    'icon' => '⚠️',
    'label' => 'Needs Configuration',
    'classes' => ['status-cell'],
  ],
]
```

**Error Status:**

```php
[
  '#type' => 'component',
  '#component' => 'oauth21_compliance:status-badge',
  '#props' => [
    'level' => 'error',
    'icon' => '🚨',
    'label' => 'Critical Issue',
    'classes' => ['status-cell'],
  ],
]
```

### Validation Checklist

- [ ] YAML schema validates correctly
- [ ] Twig template renders without errors
- [ ] All status levels work as expected
- [ ] Icons display properly in admin theme
- [ ] CSS classes apply correctly
- [ ] Component integrates with Drupal's SDC system

### Testing

- Test with different status levels
- Verify icon and label combinations
- Check CSS class application
- Validate in admin theme context
</details>
