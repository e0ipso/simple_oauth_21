# POST_PLAN Hook

Check the plan against `.ai/strikethroo/config/shared/drupal-contrib.md`.

Make sure the plan fills these sections of
`.ai/strikethroo/config/templates/PLAN_TEMPLATE.md` with content specific to
this work order: **Existing Solutions Considered**, **Public API and Backwards
Compatibility**, **Configuration, Schema, and Update Path**, **Test Plan**,
and **Self Validation**. Self Validation steps run on the ddev-drupal-contrib
site with `ddev drush`; use a browser check only for UI work.

Also answer: _Does this plan need to update the README, `<module>.api.php`,
`hook_help()`, or AGENTS.md?_
