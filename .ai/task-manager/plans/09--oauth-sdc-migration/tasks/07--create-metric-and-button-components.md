---
id: 7
group: 'component-development'
dependencies: [1]
status: 'pending'
created: '2025-09-29'
skills: ['drupal-theming', 'twig']
complexity_score: 3.5
complexity_notes: 'Decomposed from original task due to high technical complexity (6.0 → 3.5)'
---

# Create Metric Display and Action Button SDC Components

## Objective

Create two simple but essential SDC components: metric-display for statistical information presentation and action-button for consistent user interaction elements.

## Skills Required

- **drupal-theming**: Single-Directory Components for simple UI elements
- **twig**: Basic template development with props

## Acceptance Criteria

- [ ] Metric display component created for statistics and counters
- [ ] Action button component created for consistent button styling
- [ ] Both components support flexible styling variations
- [ ] Components integrate with existing admin theme patterns
- [ ] Simple, focused components with clear prop interfaces

## Technical Requirements

- Create `components/metric-display/` and `components/action-button/` structures
- Implement component.yml files with simple prop schemas
- Create Twig templates with styling variations
- Support different display types and button styles
- Maintain accessibility and admin theme compatibility

## Input Dependencies

- Component specifications from audit (Task 1)
- Understanding of metric and button usage patterns

## Output Artifacts

- Complete metric-display and action-button SDC components
- Components handle simple but frequently used UI patterns

## Implementation Notes

<details>
<summary>Detailed Implementation Guidance</summary>

### Component Structures

```
components/
├── metric-display/
│   ├── metric-display.component.yml
│   └── metric-display.twig
└── action-button/
    ├── action-button.component.yml
    └── action-button.twig
```

### Metric Display Component

#### Component Definition (metric-display.component.yml)

```yaml
$schema: https://git.drupalcode.org/project/drupal/-/raw/10.1.x/core/modules/sdc/src/metadata.schema.json
name: Metric Display
status: stable
description: Displays statistical information with label and value
props:
  type: object
  properties:
    label:
      type: string
      title: Metric Label
      description: Descriptive label for the metric
    value:
      type: string
      title: Metric Value
      description: The numeric or text value to display
    type:
      type: string
      enum: [default, primary, success, warning, error]
      title: Display Type
      description: Visual style variation
      default: default
    classes:
      type: array
      items:
        type: string
      title: Additional CSS classes
  required: [label, value]
```

#### Template Implementation (metric-display.twig)

```twig
{# metric-display.twig #}
<div class="metric-display metric-display--{{ type|default('default') }}{{ classes ? ' ' ~ classes|join(' ') : '' }}">
  <div class="metric-display__label">{{ label }}</div>
  <div class="metric-display__value">{{ value }}</div>
</div>
```

#### Usage Examples

```php
// Module count metric
[
  '#type' => 'component',
  '#component' => 'oauth21_compliance:metric-display',
  '#props' => [
    'label' => 'OAuth Modules',
    'value' => '5/7 enabled',
    'type' => 'primary',
    'classes' => ['modules-stat'],
  ],
]

// Feature availability metric
[
  '#type' => 'component',
  '#component' => 'oauth21_compliance:metric-display',
  '#props' => [
    'label' => 'OAuth Features',
    'value' => '8/10 available',
    'type' => 'success',
  ],
]
```

### Action Button Component

#### Component Definition (action-button.component.yml)

```yaml
$schema: https://git.drupalcode.org/project/drupal/-/raw/10.1.x/core/modules/sdc/src/metadata.schema.json
name: Action Button
status: stable
description: Consistent action buttons for user interactions
props:
  type: object
  properties:
    label:
      type: string
      title: Button Label
      description: Text displayed on the button
    url:
      type: string
      title: Button URL
      description: Destination URL for the button
    type:
      type: string
      enum: [primary, secondary, small, link]
      title: Button Type
      description: Visual style variation
      default: secondary
    classes:
      type: array
      items:
        type: string
      title: Additional CSS classes
    attributes:
      type: object
      title: Additional HTML attributes
      description: Extra attributes for the link element
  required: [label, url]
```

#### Template Implementation (action-button.twig)

```twig
{# action-button.twig #}
<a href="{{ url }}"
   class="button button--{{ type|default('secondary') }}{{ classes ? ' ' ~ classes|join(' ') : '' }}"
   {% if attributes %}
     {% for attr, value in attributes %}
       {{ attr }}="{{ value }}"
     {% endfor %}
   {% endif %}>
  {{ label }}
</a>
```

#### Usage Examples

```php
// Primary action button
[
  '#type' => 'component',
  '#component' => 'oauth21_compliance:action-button',
  '#props' => [
    'label' => 'Configure Module',
    'url' => '/admin/config/services/consumer',
    'type' => 'primary',
    'classes' => ['action-configure'],
  ],
]

// Small secondary action
[
  '#type' => 'component',
  '#component' => 'oauth21_compliance:action-button',
  '#props' => [
    'label' => 'View Documentation',
    'url' => '/admin/help/simple_oauth',
    'type' => 'small',
    'attributes' => [
      'target' => '_blank',
      'rel' => 'noopener',
    ],
  ],
]
```

### Common Usage Patterns

#### Dashboard Statistics

Replace complex metric render arrays with simple components:

```php
// Old html_tag approach
'stat' => [
  '#type' => 'html_tag',
  '#tag' => 'div',
  '#attributes' => ['class' => ['stat-item']],
  'label' => ['#type' => 'html_tag', '#tag' => 'div', ...],
  'value' => ['#type' => 'html_tag', '#tag' => 'div', ...],
]

// New component approach
'stat' => [
  '#type' => 'component',
  '#component' => 'oauth21_compliance:metric-display',
  '#props' => [
    'label' => 'OAuth Modules',
    'value' => '5/7 enabled',
    'type' => 'primary',
  ],
]
```

#### Action Areas

Replace button render arrays with consistent components:

```php
// Old approach
'action' => [
  '#type' => 'link',
  '#title' => 'Configure',
  '#url' => Url::fromUserInput('/admin/config'),
  '#attributes' => ['class' => ['button', 'button--small']],
]

// New component approach
'action' => [
  '#type' => 'component',
  '#component' => 'oauth21_compliance:action-button',
  '#props' => [
    'label' => 'Configure',
    'url' => '/admin/config',
    'type' => 'small',
  ],
]
```

### Validation Checklist

- [ ] Both components validate YAML schemas
- [ ] Twig templates render correctly
- [ ] Type variations work as expected
- [ ] CSS classes apply properly
- [ ] Components integrate with admin theme
- [ ] Accessibility attributes preserved

### Testing

- Test different metric value formats
- Verify button type variations
- Check URL handling and attributes
- Validate styling in admin context
- Test with various label lengths
</details>
