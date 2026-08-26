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

## Tooling revalidation
- [ ] Re-check `docs/prompt-standards.md` against the current model-specific
      prompting pages on every model-generation change (new Claude model in Claude
      Code, new Codex model for the gates).

## Done
