# PRE_PLAN Hook

Read `.ai/strikethroo/config/shared/drupal-contrib.md`. With the kenkeep
Drupal pack, also read the `contrib/`, `architecture/`, `data-cache/`, and
`security/` branch indexes.

## Existing solutions first

Search in order and stop at the first fit:

1. Drupal core (API, plugin type, field type, or module).
2. This module: reuse or extend; never add a second implementation.
3. Modules in `.info.yml` and `composer.json`.
4. drupal.org projects: check `core_version_requirement` support, security
   advisory coverage, maintenance status, and recent releases. These add a
   dependency, so ask the user first.

Record each candidate and decision in the plan. If the fix belongs in another
project, propose an upstream issue and patch instead of a copy.

## Scope and simplicity

Build only what the user explicitly asked for (YAGNI). No extra commands,
infrastructure for future features, abstractions, configuration options,
error handling, or documentation that the request does not need. If unsure,
ask whether the request mentions it. Prefer the simplest working solution,
standard Drupal patterns (services, plugins, events, hooks), and the standard
module layout (`src/`, `config/`, `templates/`, `tests/src/<Tier>`).

## Backwards compatibility

Keep public API compatible (see the shared rules). A needed API change gets a
deprecation path and a removal release. Confirm that a requested breaking
change targets a new major release.

## Tests

Plan tests with the "Test tiers" rules in the shared file, including the
final-phase test task.

## Critical Notes

- Do not write a partial or assumed plan; get the context first. Prefer
  accuracy over speed.
- Use `.ai/strikethroo/config/templates/PLAN_TEMPLATE.md`.
- Do not create tasks or phases; a later step does that.
