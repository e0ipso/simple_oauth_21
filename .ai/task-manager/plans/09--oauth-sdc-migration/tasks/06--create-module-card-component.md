---
id: 6
group: 'component-development'
dependencies: [1]
status: 'pending'
created: '2025-09-29'
skills: ['drupal-theming', 'twig']
complexity_score: 4.2
complexity_notes: 'Decomposed from original task due to high technical complexity (6.0 → 4.2)'
---

# Create Module Card SDC Component

## Objective

Create a reusable Single-Directory Component for displaying OAuth module information with title, description, RFC reference, and action areas using slots for flexible content.

## Skills Required

- **drupal-theming**: Single-Directory Components with slots implementation
- **twig**: Complex template development with props and slots

## Acceptance Criteria

- [ ] Module card component created with proper structure
- [ ] Component supports props for title, RFC, description, priority
- [ ] Slots implemented for status area and actions area
- [ ] Priority-based styling variations supported
- [ ] Component handles complex nested content through slots

## Technical Requirements

- Create `components/module-card/` directory structure
- Implement component.yml with props and slots schemas
- Create Twig template with flexible layout structure
- Support priority levels for styling variations
- Use slots for dynamic content areas (status, actions)

## Input Dependencies

- Component specifications from audit (Task 1)
- Understanding of module card usage patterns and variations

## Output Artifacts

- Complete module-card SDC component ready for integration
- Component handles complex module display requirements

## Implementation Notes

<details>
<summary>Detailed Implementation Guidance</summary>

### Component Structure

```
components/module-card/
├── module-card.component.yml
└── module-card.twig
```

### Component Definition (module-card.component.yml)

```yaml
$schema: https://git.drupalcode.org/project/drupal/-/raw/10.1.x/core/modules/sdc/src/metadata.schema.json
name: Module Card
status: stable
description: Displays OAuth module information with flexible content areas
props:
  type: object
  properties:
    title:
      type: string
      title: Module Title
      description: Display name of the OAuth module
    rfc:
      type: string
      title: RFC Reference
      description: RFC specification reference (e.g., "RFC 7636")
    description:
      type: string
      title: Module Description
      description: Detailed description of module functionality
    priority:
      type: string
      enum: [low, medium, high]
      title: Priority Level
      description: Priority level for styling variations
      default: medium
  required: [title, description]
slots:
  status:
    title: Status Area
    description: Area for status indicators and badges
    required: false
  actions:
    title: Actions Area
    description: Area for action buttons and links
    required: false
```

### Template Implementation (module-card.twig)

```twig
{# module-card.twig #}
<div class="module-card module-card--priority-{{ priority|default('medium') }}">
  <div class="module-card__header">
    <h3 class="module-card__title">{{ title }}</h3>
    {% if rfc %}
      <span class="module-card__rfc">{{ rfc }}</span>
    {% endif %}
  </div>

  {% if status %}
    <div class="module-card__status">
      {{ status }}
    </div>
  {% endif %}

  <div class="module-card__content">
    <p class="module-card__description">{{ description }}</p>
  </div>

  {% if actions %}
    <div class="module-card__actions">
      {{ actions }}
    </div>
  {% endif %}
</div>
```

### Usage Examples

The component should handle these patterns from the audit:

**Basic Module Card:**

```php
[
  '#type' => 'component',
  '#component' => 'oauth21_compliance:module-card',
  '#props' => [
    'title' => 'PKCE (Proof Key for Code Exchange)',
    'rfc' => 'RFC 7636',
    'description' => 'Provides enhanced security for public OAuth clients',
    'priority' => 'high',
  ],
  '#slots' => [
    'status' => $status_badge_component,
    'actions' => $action_buttons_array,
  ],
]
```

**Card with Complex Status:**

```php
[
  '#type' => 'component',
  '#component' => 'oauth21_compliance:module-card',
  '#props' => [
    'title' => 'Dynamic Client Registration',
    'rfc' => 'RFC 7591',
    'description' => 'Enables automated OAuth client registration and management',
    'priority' => 'low',
  ],
  '#slots' => [
    'status' => [
      'badge' => $status_badge,
      'details' => $additional_status_info,
    ],
    'actions' => [
      'configure' => $configure_button,
      'documentation' => $docs_link,
    ],
  ],
]
```

### Slot Content Examples

**Status Slot Content:**

- Status badge components
- Additional metadata
- Configuration indicators
- Dependency information

**Actions Slot Content:**

- Configure buttons
- Documentation links
- Enable/disable toggles
- Quick action buttons

### Styling Considerations

- Priority-based visual variations
- Responsive card layout
- Admin theme compatibility
- Accessible heading hierarchy
- Proper spacing and alignment

### Validation Checklist

- [ ] YAML schema validates with props and slots
- [ ] Twig template renders all sections correctly
- [ ] Slots accept various content types
- [ ] Priority styling variations work
- [ ] Component maintains accessibility standards
- [ ] Integration with existing admin theme styles

### Testing Strategy

- Test with different priority levels
- Verify slot content flexibility
- Check responsive behavior
- Validate accessibility features
- Test with complex nested content
</details>
