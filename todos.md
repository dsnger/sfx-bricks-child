# Todos — sfx-bricks-child

Backlog of stories, follow-ups, and prerequisites referenced by
`docs/hardening-log.md` (`pending` rows point here by `ref`), plus valid review
findings that were out of scope where they surfaced. Tick entries when done and
move them to `## Done` — `docs/hardening-log.md` is append-only, so a row that
references an entry here must still be able to find it.

## Now

## Next
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
- [ ] **The admin JavaScript is still untested** (follow-up to the Node test leg,
      2026-08-28): the leg runs in CI, but `tests/smooth-scroll-lifecycle-test.mjs`
      is the only JS test, and it pins wiring rather than real bfcache semantics —
      its premise is set by its own stub. The other 12 own files hold ~4,130 lines;
      one of them, `inc/SecurityHeader/assets/admin-script.js`, is an empty
      placeholder. The remaining 11 (largest: `inc/CustomDashboard/assets/admin-script.js`
      at 1,318) are admin UI, and between them — not each of them — they touch real
      DOM, jQuery (9 of the 11) and admin-ajax (5 of the 11). Hand-stubbing that is
      not practical, so covering them needs jsdom or a browser runner: the first npm
      dev dependency and the `package.json` this repo has so far avoided. Decide
      whether that trade is worth it before writing tests one file at a time.

## Tooling revalidation
- [ ] Re-check `docs/prompt-standards.md` against the current model-specific
      prompting pages on every model-generation change (new Claude model in Claude
      Code, new Codex model for the gates).

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
