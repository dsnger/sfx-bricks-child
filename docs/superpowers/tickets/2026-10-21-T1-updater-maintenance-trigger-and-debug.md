# T-1 — Updater maintenance trigger and debug switch lack administrator checks

**Category:** bug · **State:** in-progress · **Severity:** blocker · **First seen:** unknown — not bisected; present at de07ac0
**No story:** the commitment comes from AGENTS.md § Key invariants 2; the fix's Gate-B provenance line names `none`
**Feature:** theme updater, `inc/GitHubThemeUpdater.php`

## Commitment
"Every admin settings write checks a capability AND a nonce." — AGENTS.md § Key invariants 2

## Observed
`GitHubThemeUpdater::force_check()`, hooked on `admin_init`, deletes the `update_themes` site transient when the request carries `force-check=sfx-theme-update`, without a capability check or a nonce. The constructor turns on debug logging (which writes the `github_theme_updater_debug` option) when the request carries `debug_updater=1`, likewise without a capability check. — evidence: `inc/GitHubThemeUpdater.php:28`, `:250`, `:487-498` at de07ac0; the debug page's own force-check action does the same work behind `manage_options` (`:327`) and a nonce (`:333`).

## Expected
The update transient is cleared on request only through the debug page's nonce-checked action; debug logging is switched on only for a user with `manage_options`. — source: AGENTS.md § Key invariants 2

## Repro
1. Run `tests/github-updater-admin-checks-test.php` against the code at de07ac0: it fails on both points above.

## Brief
- Fix scope: remove `force_check()`, its `admin_init` hook and the `?updated=force-check` notice; the debug page's nonce-checked action stays the only route. Turn debug logging on only when the user has `manage_options`. Update `test-github-updater.php`'s hint that links to `?force-check`.
- Out of scope: the debug page itself, `check_version_change()`, the `functions.php` initialisation condition.
- Feedback loop: failing test at the nearest seam (`tests/*-test.php`, stubbed WordPress functions, as `tests/github-updater-token-scope-test.php` does) — the nearest seam is feasible; red before the fix: `initialize()` registers `force_check` on `admin_init`, and the constructor sets `debug` for a user without `manage_options`.
- Read first: AGENTS.md § Key invariants 2, `inc/GitHubThemeUpdater.php`, `tests/github-updater-token-scope-test.php`.

## Log
- 2026-10-21 · created · ready
- 2026-10-21 · in-progress · fix on fix/updater-maintenance-hooks
