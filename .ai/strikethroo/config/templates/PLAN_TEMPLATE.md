---
id: [PLAN-ID]
summary: "[Brief one-line description of what this plan accomplishes]"
created: [YYYY-MM-DD]
---

# Plan: [Descriptive Plan Title]

## Original Work Order
[The user input, quoted unchanged]

## Plan Clarifications [only if questions were asked]
[Questions and answers as a table]

## Executive Summary
[2-3 paragraphs: what the plan does, why this approach, expected outcomes]

## Context

### Current State vs Target State
| Current State | Target State | Why? |
|---------------|--------------|------|
| ... | ... | ... |

### Background
[Constraints, failed attempts, and history that affect the approach]

## Existing Solutions Considered
[Core, this module, its dependencies, then drupal.org. One row per candidate.]

| Candidate | Where | Decision | Why |
|-----------|-------|----------|-----|
| ... | core / this module / dependency / drupal.org | used / rejected / upstream patch | ... |

## Architectural Approach
[Strategy and key decisions, with a mermaid diagram. One ### subsection per component, each with an **Objective** and its design rationale.]

## Public API and Backwards Compatibility
[Public API added, changed, or deprecated (services, interfaces, plugin types, hooks, events, config keys, routes, permissions). For each deprecation, the replacement and removal release. "None" if only `@internal` code changes.]

## Configuration, Schema, and Update Path
[New or changed keys with location (`config/install` or `config/optional`), schema, and default. Update functions for existing sites: `hook_update_N()` for schema, `hook_post_update_NAME()` for data and configuration.]

## Test Plan
[One row per behavior, following "Test tiers" in `.ai/strikethroo/config/shared/drupal-contrib.md`. For Unit or Kernel, say why the behavior is scoped to that tier.]

| Behavior | Tier | Helper method | Why this tier |
|----------|------|---------------|---------------|
| ... | Unit / Kernel / Functional / FunctionalJavascript | ... | ... |

## Risk Considerations and Mitigation Strategies
- **[Risk]** (technical, implementation, integration, ...): [description]
  - **Mitigation**: [strategy]

## Success Criteria
1. [Measurable outcome]

## Self Validation
[Specific, executable steps that inspect the real system after all tasks, beyond running existing tests. Examples:
- `ddev drush en <module> -y` installs without errors
- `ddev drush config:get <module>.settings` shows the defaults
- `ddev drush php:eval` calls the new service and prints its result
- `ddev drush updb -y` runs the new update functions
- after `ddev drush pmu <module> -y`, no `<module>.*` configuration remains
- UI work only: Playwright CLI screenshot of the page]

## Documentation
[Updates to README.md, AGENTS.md, and related files]

## Resource Requirements
[Skills, tools, and external dependencies needed]

## Integration Strategy
[Optional: how this work integrates with existing systems]

## Notes
[Optional: other considerations]
