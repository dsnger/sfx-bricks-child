# Todos — sfx-bricks-child

Backlog of stories, follow-ups, and prerequisites referenced by
`docs/hardening-log.md` (`pending` rows point here by `ref`), plus valid review
findings that were out of scope where they surfaced. Tick entries when done and
move them to `## Done` — `docs/hardening-log.md` is append-only, so a row that
references an entry here must still be able to find it.

## Now

## Next
- [ ] **Social Accounts: `class` lands on the wrapper and on every account; `style`/`size`
      only add classes and the theme ships no CSS for them** (found 2026-10-02). Left as is on
      purpose: sites may already style against those classes, and the help documents it.
      Change only with a decision on migrating existing CSS.
- [ ] **Contact Infos: WPML translation never matched** (Gate B, 2026-10-03). Strings are
      registered as `Contact Information` / `contact_info_<field>_<id>` but looked up as
      `sfx_contact_info` / `<field>_<id>`, so WPML sites always see the original. Align both
      and test with a registration-backed stub. Polylang is unaffected.
- [ ] **Live harness `tests/support/rest-guest-live-check.php`: concurrent edits during
      a run are overwritten by the restore** (Greptile on PR #48, 2026-10-02). A correct
      guard needs an atomic compare-and-swap restore per row; documented in the harness
      header for now.
- [ ] **Editor Prose: test which elements `EDITOR_FRAME_RESET` matches** (Greptile on
      PR #46, 2026-10-01): the payload test pins the selector text only; nothing runs
      the selectors against a frame-vs-block DOM, so a later edit that also catches a
      real block (with `!important` widths) would pass once the pin is updated. Needs a
      DOM engine the battery does not have (no dependency today); until then the browser
      verification in the PR covers it.
- [ ] **Database-backed tests in CI for the Redirects module** (Greptile on PR #41,
      2026-09-29; deferred by Daniel): schema install, database-backed matching, the
      slug monitor and the loop simulation are only exercised by the manual harness
      `tests/support/redirects-live-check.php` against the local MAMP site;
      `quality.sh` and CI cannot catch regressions there. Needs a WordPress + MySQL
      test environment in CI (e.g. a MySQL service + wp-env or the core test suite) —
      its own design pass.
- [ ] **release.sh rollback leaves the bump commit as HEAD** (from Greptile on
      PR #30, 2026-08-26): any failure after the release commit — not just a
      missing autoloader, e.g. a zip/rsync failure in `build_theme` — triggers
      `rollback()`, which deletes the tag locally and on the remote but keeps
      the version-bump commit as HEAD (`git reset --hard HEAD` is a no-op
      against the commit itself). The checkout is then on an untagged,
      unpublished release commit. PR #30 removed the most likely trigger by
      checking the autoloader in the preflight; making `rollback()` also
      restore the pre-release HEAD is a separate, riskier change (the trap
      fires for every ERR, and eating a commit on an unrelated failure would
      be worse) and needs its own design pass.
      Narrowed 2026-08-28: the trap is now cleared explicitly when
      `create_github_release` returns — before the zip cleanup — so neither that
      cleanup nor the new release-commit push can roll anything back. Deleting
      the tag of a published release would have been worse than the unpushed
      commit the push was added to fix. See the entry below, though: the window
      is also much smaller than it reads, which changes what this finding is
      worth fixing for.
- [ ] **The release rollback does not fire for failures inside its helpers**
      (Codex Gate B on the release-push PR, 2026-08-28): `release.sh` arms
      `trap rollback ERR` with `set -e` but *without* `set -E`, so the trap runs
      only for failures in `main` itself. A failure inside `create_git_tag`,
      `build_theme` or `create_github_release` — including a `gh release upload`
      that fails after `gh release create` succeeded — ends the script with no
      rollback at all. Verified with a probe, not read off the source. Two
      consequences: the rollback is largely decorative for the steps that
      actually fail, and a failed upload leaves a published release without its
      zip and with the version bump unpushed. Enabling `set -E` is NOT the fix
      on its own — it would let an upload failure delete the tag of a live
      release. This needs explicit checked phases with the irreversible boundary
      at a successful `gh release create`, which is its own design pass.

## Someday
- [ ] **Two pre-existing docs disagree with `AGENTS.md`** (Codex Gate B pass 3,
      2026-08-26): `inc/SFXBricksChildTheme.php:12`'s registry docblock still says
      controllers register themselves — `auto_register_features()` does it at
      :335-338 — and `.cursor/rules/feature-registry-structure.mdc:63` lists 10
      modules where there are 14 (missing SmoothScroll, NavMenuQuery,
      PasswordProtected, ThemeSettingsOverview). Both were already wrong before this
      PR; left alone to keep its diff to the workflow scaffolding.
- [ ] **`./build-theme.sh` is not run in CI** (Codex Gate B on the workflow-init PR,
      2026-08-26): `tests/build-package-exclude-test.php` checks the exclude list as
      *text*, not the archive it produces — which is why tracked `.mcp/` state was
      eligible to ship until this PR caught it by hand. Running the build in CI and
      asserting the resulting zip's contents would make that behavioural — including
      the stale-entry case fixed in this PR (seed a forbidden path into an existing
      same-version zip, rebuild, assert it is gone; verified by hand here, not pinned).
- [ ] **composer.lock is stale relative to composer.json** (Codex Gate B on the
      workflow-init PR, 2026-08-26): `composer validate --strict` exits 2 with
      "lock file is not up to date". `composer install` still installs exactly what
      the lock pins, so CI stays reproducible, but a `composer validate --strict`
      step cannot be added to `.github/workflows/quality.yml` until the lock is
      regenerated. Regenerate and add the step together.
- [ ] No static analyser installed (PHPStan/Psalm). `AGENTS.md` § Commands
      lists the `typecheck` row as TODO and points here.
- [ ] **Most admin JavaScript is still untested** (follow-up to the Node test leg,
      2026-08-28): the leg runs in CI. Since then the Editor Prose and Redirects
      picker scripts got their own tests (4 JS tests in all, 2026-10-09);
      `tests/smooth-scroll-lifecycle-test.mjs` pins wiring rather than real bfcache
      semantics — its premise is set by its own stub. As counted on 2026-08-28, the
      other 12 own files held ~4,130 lines;
      one of them, `inc/SecurityHeader/assets/admin-script.js`, is an empty
      placeholder. The remaining 11 (largest: `inc/CustomDashboard/assets/admin-script.js`
      at 1,318) are admin UI, and between them — not each of them — they touch real
      DOM, jQuery (9 of the 11) and admin-ajax (5 of the 11). Hand-stubbing that is
      not practical, so covering them needs jsdom or a browser runner: the first npm
      dev dependency and the `package.json` this repo has so far avoided. Decide
      whether that trade is worth it before writing tests one file at a time.

## Tooling revalidation
- [ ] **Report two dev-workflow 0.21.0 template defects upstream** (PR #58, kept
      unpatched here by owner decision 2026-10-10, files stay byte-identical to the
      templates): `.claude/review-gates.md` says findings follow "the format above"
      but never defines the six-field line; CLAUDE.md §5 treats a missing
      `.claude/review-gates.md` as "no gate rules" without first checking the agent
      is in the right checkout, so `/workflow-init` could write it in the wrong one
      (CodeRabbit on PR #58).
- [ ] Re-check `docs/prompt-standards.md` against the current model-specific
      prompting pages on every model-generation change (new Claude model in Claude
      Code, new Codex model for the gates).
- [ ] **codebase-memory MCP identity** (recorded 2026-10-10, confirmed by Daniel 2026-10-10, this installation only).
      Name(s) codebase-memory-mcp, user scope, command `/Users/daniel/.local/bin/codebase-memory-mcp`; version 0.9.0; sha256 `04ee3048810c19099502adc8bb83039423f02f2553d17677892a7f03b924e01f`; platform darwin x86_64.
      Source: unknown (no release evidence checked).
      Install route: unknown (binary in ~/.local/bin). Updates: unknown. Network reach: unknown.
      Launch env: none. Store: ~/.cache/codebase-memory-mcp.
      Human refresh: `'/Users/daniel/.local/bin/codebase-memory-mcp' cli index_repository --repo-path '/Users/daniel/DEVELOPMENT/LOCALHOST/sfx-bricks-child.local/wp-content/themes/sfx-bricks-child'` — run by a human only.
      Protects against: accidental or instructed agent misuse, on the client paths the canary showed. Not against: deliberate bypass, a manipulated binary, the server's own store changes, other clients.
      A `worker crashed` answer from `index_repository` can be a refusal or a technical failure; it is no evidence that any boundary works.

## Done
- [x] **A Node test leg now covers the theme's own JavaScript** (raised by Codex
      Gate B on PR #36 in all three passes; done 2026-08-28): `quality.sh` used to
      run `tests/*-test.php` only, so 13 own JS files under `inc/*/assets/`
      (~4,370 lines, excluding the vendored `jquery.mjs.nestedSortable.js`) had no
      regression signal at all — including the bfcache rAF lifecycle fixed in
      v0.22.3, which shipped with `tests/smooth-scroll-bfcache-test.html`, a
      *manual* harness. Step (1) is complete: `quality.sh` resolves and probes
      `$NODE` the way it already did `$PHP`, runs a `tests/*-test.mjs` battery with
      the same empty-glob guard, and CI sets up Node in every PHP leg. Step (2) has
      barely started — see the open follow-up under `## Someday`.
