# PRE_TASK_EXECUTION Hook

Read `.ai/strikethroo/config/shared/drupal-contrib.md`. With the kenkeep
Drupal pack, also read `quality/index.md` and the branch index for the task's
`skills` (for example `architecture/`, `data-cache/`, `security/`,
`presentation/`, `processing/`, `tooling/`).

## Tests

Add tests only where the plan's Test Plan calls for them; otherwise say why
and implement directly.

- **Unit:** RED (write one failing test, run only it with
  `ddev phpunit --filter <TestClass>`, confirm the failure reason), GREEN
  (minimal code to pass), REFACTOR (clean up, run it again).
- **Kernel and higher:** follow "One class, one method" and "When tests run"
  in the shared rules.

## Before you mark the task complete

- `ddev phpcs` and `ddev phpstan` pass on your changed files.
- New configuration keys have schema.
- New classes use constructor injection, not `\Drupal::`.
- Public API changes have the deprecation the plan describes.
