# Drupal Contrib Module Rules

This repository is one Drupal contrib module, not a site. Every site that
installs it gets its public API, default configuration, and dependencies.

## Knowledge pack

If `.ai/kenkeep/nodes/drupal/` exists, it is the Drupal API reference. Read
`contrib/` first (it replaces site habits such as config sync), then the
branch `index.md` files that the calling hook names, then only the leaves you
need. A node that recommends a contrib module applies only if this module
already depends on it; never add a dependency because a node recommends it.
Without the pack, use this file only.

## Commands

If `.ddev/config.yaml` exists and `ddev describe` succeeds, use `ddev phpunit`,
`ddev phpcs`, `ddev phpcbf`, `ddev phpstan`, `ddev eslint`, `ddev stylelint`,
and `ddev drush`. Otherwise ask the user how to run PHPUnit, PHPCS, PHPStan,
and Drush. Do not guess paths, do not install a site in the repository, and
never commit generated site files (`web/`, `vendor/`, `composer.contrib.json`).

## Test tiers

Prefer integration tests: Functional or FunctionalJavascript tests that
exercise the behavior the way a site uses it. Use Unit or Kernel only when the
behavior is well scoped to that tier. "Integration test" in Strikethroo
prompts means a Functional or FunctionalJavascript test.

| Tier | Base class | Use it when | Cost |
| --- | --- | --- | --- |
| Unit | `UnitTestCase` | Self-contained logic with mocked collaborators | Low |
| Kernel | `KernelTestBase` | A service scoped to the database, entities, config schema, or the container | High |
| Functional | `BrowserTestBase` | Default: routes, forms, access, rendered output, or subsystems working together | Very high |
| FunctionalJavascript / Nightwatch | `WebDriverTestBase` | The behavior needs JavaScript | Highest |

### Assertion budget

Tests cost time and resources, so every assertion must pay for itself. Do not
assert:

- trivial behavior, such as getters, setters, or constants;
- behavior that another assertion already covers;
- upstream behavior: Drupal core, PHP, or a dependency.

### One class, one method

A Kernel, Functional, or FunctionalJavascript test installs a Drupal
environment for each test method. To install it once:

- Each module and sub-module has at most one test class per tier (Kernel,
  Functional, FunctionalJavascript).
- Each class has one test method. Add another only when it is exceptionally
  necessary. A test method only calls protected helper methods, one for each
  scenario, and never reinstalls or rebuilds the site.
- A helper may use state that earlier helpers left. Its docblock states what
  it needs and what it leaves.
- One task in the final phase writes these classes. The plan's Test Plan
  names the helper for each behavior. Other tasks do not edit these classes.

### When tests run

- **Inside a phase:** tasks run at the same time, and Kernel and Functional
  runs compete for CPU and the test database. Run only your task's Unit tests
  (`ddev phpunit --filter <TestClass>`). Check Kernel and higher-tier tests
  with `php -l`, do not run them, and list them in the completion notes.
- **After all phases (POST_EXECUTION):** run every tier once, scoped to the
  module. Never run the core test suite.

## Architecture

- Hooks, controllers, forms, and plugins stay thin and call a service. Logic
  lives in services, so Unit tests can cover it.
- Extend through plugins, events, tagged services, and alter hooks, not
  `moduleExists()` branches.
- Plugins and services honor their full interface contract.
- Depend on the narrowest interface (for example `EntityTypeManagerInterface`),
  never the container. Give each public service an interface.
- Use constructor injection with promoted `readonly` properties and
  autowiring (`#[Autowire]` where needed). Use `\Drupal::` only in procedural `.module` or `.install` code.
- Prefer OOP hooks (`#[Hook]` in `src/Hook/`) and attribute plugins when
  `core_version_requirement` allows.
- Mark non-API classes `@internal` and `final` to keep the public API small.

## Configuration

- Defaults go in `config/install`; configuration that needs another module
  goes in `config/optional`. No site names, URLs, credentials, or environment
  values.
- Every key has a schema entry in `config/schema/*.schema.yml`.
- Read site-adjustable values from configuration, not hardcoded values. Put
  runtime values in State or the key-value store.
- Changes to stored configuration or schema need an update for existing
  sites: `hook_update_N()` for schema, `hook_post_update_NAME()` for data and
  configuration.

## Backwards compatibility

Public API is everything not `@internal`: services and interfaces, plugin
types, hooks, events, configuration keys, routes, and permissions. Follow
semver: no removals or changes in minor or patch releases. To replace public
API, keep the old one, mark it `@deprecated` with `@trigger_error()` and a
change record link, and remove it in the next major. `@internal` code and
code this plan adds have no compatibility obligation.

## Dependencies

- Declare each module dependency in `.info.yml` (`project:module`) and
  `composer.json` (`drupal/<project>`). Keep `core_version_requirement` equal
  to the `drupal/core` constraint.
- A new dependency goes through the clarification gate, with what it gives
  and what it costs every site.
- Never edit core, other projects, or `vendor/` in place. Fix upstream with an
  issue and a patch.
