# TASK_EXECUTION_ROUTING Hook

Runs during task generation, after all task files exist and before the
blueprint.

## Classification contract

- Match each new task to a profile in `execution_routing` in
  `.ai/strikethroo/config/config.yaml`; the profile descriptions are the
  contract. Use the task content already in context (`skills`,
  `complexity_score`, objective, acceptance criteria, requirements,
  integration surface); do not reread task files.
- Classify each task once, with configured profile names only. Never invent a
  model, harness, or profile.
- The routing helper persists only the `execution_profile` field; the mapping
  file is temporary, and targets are chosen at dispatch.
- Any routing failure aborts task generation before the blueprint.

## Project-specific guidance

- Use the most capable profile for access control, permissions, update
  functions, entity or field storage, render cache metadata, or public API
  and deprecations.
- Use the documentation profile only for README, `hook_help()`, `.api.php`
  docblocks, and `.info.yml` or `composer.json` edits that add no dependency.
  Configuration schema changes are implementation work.
