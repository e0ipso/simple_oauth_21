# CODE_REVIEW Hook

After opt-in (`code_review.enabled: true`), this is the mandate for the review
that runs after blueprint execution, once lint, tests, and Self Validation
pass. A reviewer in a clean session reads the plan's full diff and emits
findings, which are checked against the vendored schema and recorded. The gate
only reports: nothing is applied, and the implementer decides what to act on.

## Mandate

Report only:

- **Requirement conformance:** the code does not do what the plan explicitly
  asks.
- **Demonstrable defects:** runtime failure, wrong behavior, a broken declared
  contract, a security hole, data loss, or breakage elsewhere in the plan.

Cite concrete evidence for each finding. Style (the linter's job), design
critique, taste, SOLID concerns, and `\Drupal::` use are out of scope.

### Drupal defects

If `.ai/kenkeep/nodes/drupal/` exists, read the `security/` and
`data-cache/cache-render/` branch indexes.

- A content entity query with no `accessCheck()`.
- A route with no access requirement, or one that admits the wrong users.
- Unescaped user data: `#markup` or `Markup::create()` with unfiltered input,
  or `|raw` in Twig.
- SQL concatenated with input.
- Render output that varies by user, route, configuration, or entity without
  the matching cache context or tag.
- A configuration key read by code with no schema entry, no `config/install`
  default, or no update function for existing sites.
- A module, class, or service used but not declared in `.info.yml` or
  `composer.json`.
- Public API (not `@internal`) removed or changed without deprecation.

## Severity and confidence

Advisory labels for sorting; nothing filters on them.

- Severity: `critical` (data loss, security hole, crash, or corruption on a
  reachable path), `major` (wrong behavior or broken contract), `minor`
  (bounded: edge case or maintenance hazard), `info` (no defect).
- Confidence: `high` (traced from the diff alone), `medium` (rests on one
  unverified assumption), `low` (speculative). Do not upgrade a guess.

## Guarantee

The code, not this file, enforces it: an uncertified review is never reported
as clean. A missing or schema-invalid findings document halts the gate and
says which. Without `xmllint` the gate skips, and a skip is never a pass.
