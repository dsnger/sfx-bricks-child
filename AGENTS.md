# AGENTS.md — sfx-bricks-child

Single source of truth for this project's architecture and invariants. Read directly by
Codex (both review gates, CLAUDE.md §5) and by the PR review bots. Discipline rules live
in @CLAUDE.md; this file is what "check it against our invariants" resolves to.

## What this is

A WordPress child theme of **Bricks** (`Template: bricks`, text domain `sfxtheme`),
published from `dsnger/sfx-bricks-child` and installed on client sites. It ships
theme-level capability as admin modules under `inc/`: image optimization, WordPress
optimization/cleanup, media credits, password-protected pages, security headers,
nav-menu queries, social accounts, contact infos, custom scripts, a custom dashboard,
import/export, general theme options, smooth scroll, and a settings overview.

Users are site administrators working in wp-admin, plus editors building pages in Bricks.
A change fits this project when it extends a module, adds one, or maintains the repo's
own tooling (CI, release, build, docs) — not when it reaches into WordPress core or the
Bricks parent theme.

## Architecture

- **Autoloading** — PSR-4 `SFX\` → `inc/`, via Composer. `functions.php` falls back to an
  `spl_autoload_register` shim when `vendor/` is absent, and shows an admin notice.
- **Module auto-discovery** — `SFXBricksChildTheme::auto_register_features()` globs
  `inc/*/Controller.php` and, for each class exposing a static `get_feature_config()`,
  calls `SFXBricksChildTheme::register_feature()` itself — a Controller supplies the
  config, it does not register. **There is no central module list to edit** — adding
  `inc/<Module>/Controller.php` with `get_feature_config()` is what registers a module.
- **Per-module file convention** — `Controller.php` is the only required file. Modules
  that own settings add `Settings.php` (option schema) and `AdminPage.php` (admin UI),
  plus module-specific classes (e.g. `Bricks.php`, `MediaLibrary.php`, `Credit.php`).
  Modules with no settings UI (`NavMenuQuery`, `ThemeSettingsOverview`) legitimately have
  neither; a few theme-wide classes (e.g. `inc/DataPurge.php`) sit outside a module dir.
- **Bricks elements** — custom elements are registered from `elements/` on `init`
  priority 11 via `\Bricks\Elements::register_element()`.
- **Dependency direction** — modules depend on the theme bootstrap and on WordPress.
  Root-level shared services (`inc/AccessControl.php`, `inc/SFXBricksChildAdmin.php`,
  `inc/MetaFieldManager.php`, `inc/DataPurge.php`) are available to every module.
  `ImportExport` is a catalogue: it names the explicit exportable contracts of ten other
  modules by design — with deliberate omissions, see the Don'ts — so it sits outside the
  edge count below rather than being an exception to it.
  Beyond those, three groups of feature-module coupling exist and are deliberate — the
  bootstrap dependency plus seven module-to-module edges: the bootstrap reads
  `GeneralThemeOptions\Settings::get_all_fields()`, and the aggregator modules reach into
  the modules they aggregate (`GeneralThemeOptions` → ImageOptimizer / SecurityHeader /
  SmoothScroll / WPOptimizer, `ThemeSettingsOverview` → WPOptimizer and SecurityHeader
  — the latter by mirroring its option names and defaults in
  `SecurityHeaderStatusResolver`, so a SecurityHeader contract change silently makes the
  overview lie — and `CustomDashboard` → ThemeSettingsOverview). Anything beyond those is
  new coupling — don't add it.

## Key invariants

Non-negotiable. Violating one is a bug regardless of what the ticket asked for.

1. **PSR-4 path case matches class case byte-for-byte.**
   *Why:* macOS APFS is case-insensitive, so `inc/wpoptimizer/AdminPage.php` resolves
   locally while Linux and GitHub zipballs fatal on autoload. Guarded by
   `tests/psr4-path-case-test.php`.

2. **Every admin settings write checks a capability AND a nonce.**
   *Why:* either alone leaves a hole — a capability check without a nonce is CSRF-able, a
   nonce without a capability check authorizes the wrong user. `manage_options` is the
   default gate; narrower caps (`edit_posts`, `edit_post`,
   `upload_files`, `edit_others_posts`) apply in post and media contexts.

3. **All output is escaped at the point of output**, never at assignment.
   *Why:* escaping early is invisible to the reader at the echo site and silently decays
   when the value is reassigned. Use `esc_html` / `esc_attr` / `esc_url` / `wp_kses`.

4. **Every NEW user-facing string uses the `sfxtheme` text domain.**
   *Why:* a bare string drops out of the translation catalogue silently — no error, just
   an untranslated UI on a German-language site. Known debt: several modules still hold
   bare `$page_title` / `$description` statics that reach the feature registry —
   SmoothScroll, GeneralThemeOptions, ImageOptimizer, PasswordProtected, SecurityHeader,
   WPOptimizer, CustomDashboard, CustomScriptsManager, ImportExport. Don't add more, and
   don't report the existing ones as new violations.

5. **A built package must contain a WORKING `vendor/autoload.php`.**
   *Why:* a shipped RC was once built without it and installed broken. Existence is not
   the property that matters — on 2026-08-28 a one-line `<?php` placeholder, written into
   the checkout by a test harness, satisfied every file-exists check and produced a package
   whose autoloader loads no class at all. Checking the file's shape is not enough either —
   a vendor/ holding only `autoload.php` and `ClassLoader.php` looks plausible and fatals on
   `require`, because Composer's entry point pulls in `composer/autoload_real.php`. Both
   guards therefore RESOLVE CLASSES through it. Weaker predicates all failed in review: an
   object exposing `loadClass()` passed, and so did a loader advertising the `SFX\` prefix
   while it pointed at a directory that was not there. Both guards now require
   `SFX\SFXBricksChildTheme` *and* `SFX\SFXBricksChildAdmin` to resolve — two classes, because
   a stale `--optimize-autoloader` classmap can carry one and miss the other, and because
   `functions.php` also requires the first by hand. Both discard the autoloader's own output and
   demand a marker back on stdout rather than trusting an exit code, and `build-theme.sh` runs
   `quality.sh`'s numeric version probe on its interpreter — `PHP=/usr/bin/true` waved
   everything through until it did. Defense in depth, both stay. `tests/build-vendor-guard-test.php`
   pins the build side across missing, placeholder and real; the `stubvendor` scenario in
   `tests/support/release-push-harness.sh` drives release.sh end to end with the placeholder.

6. **The release version lives in `style.css`.**
   *Why:* it is what WordPress and the GitHub updater read. A git tag alone does not make
   a release, and does not create a GitHub Release.

7. **Work finishes as a pull request; never merge to `main` directly.**
   *Why:* the bot review gate (`docs/pr-review-bots.md`) only sees changes that arrive
   through a PR. This is instruction-backed, not enforced — `main` currently carries no
   branch protection and no ruleset, so nothing stops a direct push but this rule.
   *One named exception, and only this one:* the release commit `release.sh` makes and
   pushes. It carries `style.css` and `CHANGELOG.md` and nothing else, both generated
   from the version argument and the release notes, so there is nothing for a reviewer
   to find. That commit has always been made directly on the release branch; since
   2026-08-28 the script pushes it as well, because leaving that to a human hand is
   what let v0.22.3 be tagged on the remote while the bump stayed local. Everything
   else — including any change to `release.sh` itself — goes through a PR.

## Don'ts

- **Don't edit the Bricks parent theme or `wp-includes/`.** This is a child theme; parent
  changes are lost on update. Extend via hooks and filters.
- **Don't add a runtime dependency for what a few lines of PHP do.** `composer.json` has
  exactly one (`erusev/parsedown`); keep it that way unless there's a real reason.
- **Don't bypass the module convention.** A feature registered from `functions.php`
  instead of a Controller is invisible to the feature registry and to the settings
  overview.
- **Don't write option keys without the module's prefix, and don't stop there.** Purge
  (`inc/DataPurge.php`) and export (`inc/ImportExport/`) both work from explicit
  ownership lists, never by `sfx_` prefix match — other plugins on this estate share the
  prefix. A new key must carry the prefix *and* be added to `DataPurge`, or it is never
  purged. Export is a separate, deliberate decision per key: secrets and site-local data
  stay out (`sfx_password_protected_options` holds a password hash and a bearer token
  and is purged but never exported).
- **Don't commit `vendor/`** — it is gitignored; `composer install` restores it.

## Commands

| Role | Command |
|---|---|
| quality (the whole battery — what CI runs) | `./quality.sh` |
| typecheck | TODO — no static analyser installed (PHPStan/Psalm); see `todos.md` |
| lint | `./quality.sh` (syntax-lint stage), or `php -l <file>` for one file |
| test | `./quality.sh` — two batteries, `tests/*-test.php` and `tests/*-test.mjs`. A bare `for f in tests/*-test.php` loop returns only the last test's status, and misses the JS ones entirely |
| build | `./build-theme.sh` — produces the distributable zip (not run in CI; see `todos.md`) |

`quality.sh` was run and seen to exit cleanly (25 PHP tests, 1 JS test, 0 syntax errors),
and seen to fail on a syntax error outside `inc/`, on an empty test glob for either
battery, and on a failing JS test. Its Node resolution was seen to reject an unusable
override with every fallback removed, and a control run with a real Node in the same
stripped environment passed. `build-theme.sh` was not run in that session — it writes a
release artifact — so treat its row as documented, not verified.

**Prompt artifacts** are also checked against `docs/prompt-standards.md`; name that file
in the gate prompt when the change touches one. Which files those are is defined once,
in CLAUDE.md §5 under "What counts as prose" — read it there. It is deliberately not
restated here: a second copy drifted narrower than the original within a day of being
written. `tests/prompt-artifact-paths-test.php` pins the citation and fails if this
paragraph starts listing the paths again — it does not police every possible way to
smuggle a copy back in, so keep the rule where it is defined.

**Local PHP:** development runs under MAMP (`/Applications/MAMP/bin/php/php8.5.2/bin/php`).
`quality.sh` resolves `$PHP` (a bare command name goes through PATH, an absolute path is
used as given), then `php` on PATH, then that MAMP path, so it works in CI and locally
without editing. `build-theme.sh` uses the same three candidates and the same version probe (it does
not repeat quality.sh's linter check, which is about linting rather than loading) — it needs
PHP now, because verifying the Composer autoloader means loading it. WP-CLI cannot reach the local socket — use
`php` plus `wp-load.php` for anything needing a booted WordPress.

**Local Node:** `quality.sh` resolves `$NODE` the same way, then `node` on PATH, then
`~/.local/share/fnm/aliases/default/bin/node`. That last one is the fnm *alias*, not
fnm's multishell path, which changes per shell session — a bare `node` is invisible in a
shell where fnm was never initialised. Node 18 is the floor — a guard against something
ancient or fake answering the probe, not a support statement: 18 and 20 are both past
end-of-life, and the JS test needs nothing newer than 14. CI runs 22. Neither interpreter
is optional — no usable Node is a hard error, because a
battery that reports PASS without having run the JS tests is the hole the JS battery
exists to close. Both resolvers probe every candidate rather than trust it:
`NODE=/usr/bin/true` reported every test as passed before that was added.
