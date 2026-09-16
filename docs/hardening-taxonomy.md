# Hardening taxonomy — sfx-bricks-child

Project-specific fingerprint classes, extending the stack-neutral **base taxonomy**
in the `dev-workflow:harden-finding` skill. The skill reads both on every fingerprint
step; the base classes are not repeated here.

A class belongs here (not in the base list) when it names *this* project's entities,
frameworks, or invariants — e.g. a class about a specific table, a specific auth
helper, or a specific framework's API.

**Before minting:** grep the base list and this one for a near match. A slightly
imprecise class you reuse beats a precise class nobody greps for — recurrence
detection is the entire value, and it only works when the same defect maps to the
same string twice.

**Format:** kebab-case `domain-problem-class`, one line, with an alias hint naming
the synonyms a future reader might search for instead.

## Classes

<!-- Add classes as harden-finding mints them, e.g.:
- `orders-missing-idempotency-key` — a retryable order write accepted without an idempotency key
-->

- `harness-unguarded-real-state` — a manual verification harness creates or mutates
  real-environment state outside a guaranteed teardown or guard path
  (aliases: harness cleanup, teardown, fixture leak, stray fixture, scratch guard,
  `cd` guard, live-site write, `trap`, `register_shutdown_function`). Covers both
  halves: a fixture removed inline instead of from the single teardown, and a guard
  that decides where the harness operates without failing fatally. This project's
  harnesses (`tests/support/*`) run against the real checkout and the real site,
  which is what makes the class worth its own name here rather than a generic
  test-hygiene one.
