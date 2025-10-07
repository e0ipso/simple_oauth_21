---
id: 1
group: 'component-analysis'
dependencies: []
status: 'pending'
created: '2025-09-29'
skills: ['drupal-backend', 'php']
---

# Audit HTML Tag Usage Patterns for SDC Migration

## Objective

Conduct a comprehensive audit of the OAuth 2.1 compliance module's controller code to identify and catalog all `html_tag` render array patterns that can be extracted into reusable Single-Directory Components.

## Skills Required

- **drupal-backend**: Understanding of Drupal render arrays and theming system
- **php**: Analysis of PHP controller code and render array structures

## Acceptance Criteria

- [ ] Complete inventory of all `html_tag` usage patterns in the controller
- [ ] Patterns grouped by similarity and reusability potential
- [ ] Priority ranking based on frequency of use and complexity
- [ ] Documentation of props and slots needed for each component type
- [ ] Component specifications ready for implementation

## Technical Requirements

- Analyze `/src/Controller/OAuth21ComplianceController.php` for `html_tag` render elements
- Identify recurring patterns: status indicators, badges, cards, metrics, buttons
- Document the data structures and variations for each pattern type
- Create specifications for props (configuration) and slots (content injection)

## Input Dependencies

- Current OAuth 2.1 compliance module codebase
- Understanding of Single-Directory Component architecture

## Output Artifacts

- Component audit document listing all identified patterns
- Component specifications with props/slots definitions
- Priority ranking for implementation order
- Migration checklist for controller refactoring

## Implementation Notes

<details>
<summary>Detailed Implementation Guidance</summary>

### Audit Process

1. **Pattern Identification**
   - Search for all instances of `'#type' => 'html_tag'` in the controller
   - Group similar patterns (e.g., all status indicators, all badges)
   - Document the context where each pattern is used

2. **Component Specification**
   For each identified pattern, document:
   - **Component name** (e.g., `status-badge`, `module-card`)
   - **Props needed** (e.g., status level, icon, label, classes)
   - **Slots needed** (e.g., content area, actions)
   - **Usage frequency** (how many times it appears)
   - **Variation examples** (different configurations used)

3. **Priority Ranking**
   Rank components by implementation priority:
   - **High**: Used 5+ times, clear reusability benefits
   - **Medium**: Used 3-4 times, moderate complexity
   - **Low**: Used 1-2 times, consider if worth extracting

4. **Documentation Format**
   Create a structured document with:

   ```
   # Component: Status Badge
   ## Usage Count: 15 instances
   ## Props:
   - level: string (success, warning, error)
   - icon: string (emoji or class name)
   - label: string (status text)
   - classes: array (additional CSS classes)

   ## Slots:
   - (none for this component)

   ## Examples:
   - Success: ✅ Fully Configured
   - Warning: ⚠️ Needs Configuration
   - Error: 🚨 Critical Issue
   ```

5. **Migration Checklist**
   For each controller method, list:
   - Method name
   - Number of html_tag instances
   - Component types used
   - Complexity of migration

### Focus Areas

Based on the plan's 120+ instances, prioritize these common patterns:

- Status indicators with icons and labels
- Priority/complexity badges with dynamic classes
- Module cards with titles, descriptions, and actions
- Metric containers with labels and values
- Action buttons and links

### Success Criteria Validation

Ensure the audit covers:

- All repetitive `html_tag` patterns identified
- Clear component specifications for reusability
- Implementation roadmap for controller refactoring
</details>
