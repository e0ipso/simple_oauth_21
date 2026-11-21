# POST_PHASE Hook

Before the next phase:

- Run `ddev phpcs` and `ddev phpstan` on the phase's changed files and fix
  every error (`ddev phpcbf` fixes some).
- Run the phase's new or changed Unit tests (`ddev phpunit --filter
  <TestClass>`). Higher tiers wait for POST_EXECUTION.
- Run `ddev drush cr` if the phase changed services, routes, plugins, hooks,
  or templates.
- Commit the phase with a descriptive conventional commit (subject and body).
- In the blueprint, prefix the phase title with ✅ and its tasks with ✔️, and
  set those tasks to `completed`.

Valid task status transitions: `pending` → `in-progress` → `completed` or
`failed`; `failed` → `in-progress` to retry.
