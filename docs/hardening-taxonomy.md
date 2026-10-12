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

**A class that names a remedy promises only what that remedy does.** Before naming a
function, parser or check as the fix, read its contract in the code and limit the class
to the inputs it handles; name what stays outside. *Why:* a class is read on every
fingerprint step, so an over-broad remedy sends the next hardening to a tool that cannot
handle the case — a review draft of `adversarial-input-against-handwritten-parser`
prescribed the HTML-only `Observations::html_document()` for HTTP header values (Gate B
cycle ez6zo9nu5r, pass 3, 2026-10-12; fixed before it reached main).

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
- `adversarial-input-against-handwritten-parser` — a SiteCheck check reads a site's HTML (a page body, including HTML a header check inspects) with a hand-written regex, prefix test or DOMDocument instead of `Observations::html_document()` (WordPress' `WP_HTML_Tag_Processor`), so each crafted variant becomes a new review finding; the remedy is that parser plus Nicht prüfbar for what it cannot read with certainty. HTTP header values (CSP, HSTS) are not HTML and are outside this class (aliases: regex parser, HTML regex, comment forms, `--!>`, PCRE whitespace, truncated body read as clean, review loop on edge cases, parser swap).
