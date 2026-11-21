# POST_EXECUTION Hook

Read `.ai/strikethroo/config/shared/drupal-contrib.md` first.

## Validation Gates

Before you mark the blueprint complete:

- [ ] `ddev phpcs` and `ddev phpstan` pass for the whole module; `ddev eslint`
  and `ddev stylelint` too if the plan changed JavaScript or CSS.
- [ ] Then, with nothing else running, `ddev phpunit` passes. This is the only
  run of Kernel, Functional, and FunctionalJavascript tests.
- [ ] New or changed configuration keys have schema, and `config/install` has
  no site-specific values.
- [ ] `ddev drush en <module> -y`, then `ddev drush pmu <module> -y`, then
  `ddev drush php:eval "print_r(\Drupal::configFactory()->listAll('<module>.'));"`
  prints an empty array.
- [ ] If the plan added update functions, `ddev drush updb -y` runs them
  without errors on the enabled module.
- [ ] `.info.yml` and `composer.json` declare every used module, and
  `core_version_requirement` matches `drupal/core`.
- [ ] Every task has `status: "completed"`.
- [ ] The README, `<module>.api.php` (new hooks), and AGENTS.md are correct.
- [ ] The plan's **Self Validation** steps pass.

## Cleanup

Remove tech debt and dead code the plan left behind, but never public API:
that follows the deprecation path.

## Failure Behavior

If a gate fails, record which one in the plan, add a fix task following
`<root>/config/templates/TASK_TEMPLATE.md`, run it with the `st-execute-task`
skill, and run every gate again.
