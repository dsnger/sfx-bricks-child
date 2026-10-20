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
import/export, general theme options, smooth scroll, redirects with a 404 log, a security check (Sicherheits-Check), editor prose mirroring, `@format:tel` for simple Bricks
dynamic tags, and a settings overview.

Users are site administrators working in wp-admin, plus editors building pages in Bricks.
A change fits this project when it extends a module, adds one, or maintains the repo's
own tooling (CI, release, build, docs) — not when it reaches into WordPress core or the
Bricks parent theme.

## Architecture

- **Autoloading** — PSR-4 `SFX\` → `inc/`, by the theme's own `spl_autoload_register()`
  in `functions.php`, its only class loader at runtime. `composer.json` keeps the same map
  for the test tooling; the theme never loads `vendor/`.
- **Module auto-discovery** — `SFXBricksChildTheme::auto_register_features()` globs
  `inc/*/Controller.php` and, for each class exposing a static `get_feature_config()`,
  calls `SFXBricksChildTheme::register_feature()` itself — a Controller supplies the
  config, it does not register. **There is no central module list to edit** — adding
  `inc/<Module>/Controller.php` with `get_feature_config()` is what registers a module.
- **Per-module file convention** — `Controller.php` is the only required file. Modules
  that own settings add `Settings.php` (option schema) and `AdminPage.php` (admin UI),
  plus module-specific classes (e.g. `Bricks.php`, `MediaLibrary.php`, `Credit.php`).
  Modules with no settings UI (`NavMenuQuery`, `ThemeSettingsOverview`, `TelFormat`) legitimately have
  neither; a few theme-wide classes (e.g. `inc/DataPurge.php`) sit outside a module dir.
- **Bricks elements** — custom elements are registered from `elements/` on `init`
  priority 11 via `\Bricks\Elements::register_element()`.
- **Dependency direction** — modules depend on the theme bootstrap and on WordPress.
  Root-level shared services (`inc/AccessControl.php`, `inc/SFXBricksChildAdmin.php`,
  `inc/MetaFieldManager.php`, `inc/DataPurge.php`, `inc/TelNormalizer.php`) are available to every module.
  `ImportExport` is a catalogue: it names the explicit exportable contracts of thirteen
  other modules by design — with deliberate omissions, see the Don'ts — so it sits outside the
  edge count below rather than being an exception to it.
  `DataPurge` calls `\SFX\SiteCheck\Purge::run()` directly, because purge must remove the
  uploads probes even while the module is disabled. `CustomDashboard` takes widgets from other
  modules through the filter `sfx/custom_dashboard/widgets` — its extension point; the
  Sicherheits-Check supplies its box that way, so neither module names the other's classes.
  Beyond those, three groups of feature-module coupling exist and are deliberate — the
  bootstrap dependency plus eight module-to-module edges: the bootstrap reads
  `GeneralThemeOptions\Settings::get_all_fields()`, and the aggregator modules reach into
  the modules they aggregate (`GeneralThemeOptions` → ImageOptimizer / SecurityHeader /
  SmoothScroll / WPOptimizer, `ThemeSettingsOverview` → WPOptimizer and SecurityHeader
  — the latter by mirroring its option names and defaults in
  `SecurityHeaderStatusResolver`, so a SecurityHeader contract change silently makes the
  overview lie — and `CustomDashboard` → ThemeSettingsOverview), and `SiteCheck` → WPOptimizer:
  the Sicherheits-Check reads WP Optimizer's own switches through its public `Settings::get()`
  (`SiteCheck\ThemeSwitches`, owner ruling 2026-10-11 — the check must know what the theme
  already does instead of suggesting it again). Anything beyond those is
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

5. **The theme loads its classes without `vendor/`, and a release ships only `SHIPPED`.**
   *Why:* the release ZIP holds the `SHIPPED` entries of `bin/plugin.conf` and nothing else
   — no `vendor/`, no tests, no tooling — so code that needs `vendor/` at runtime fatals on
   every site that installs the release. A shipped RC was once built without its Composer
   autoloader and installed broken; the theme dropped runtime Composer instead of guarding
   it. `tests/theme-bootstrap-test.php` loads `functions.php` from a copy of the shipped
   folders and requires the classes to resolve from `inc/`, a present `vendor/autoload.php`
   to stay unloaded, and no Composer notice; `bin/release.sh publish` refuses a ZIP whose
   files differ from `SHIPPED` at `HEAD`. A new runtime dependency is therefore a change to
   this invariant, not a `composer require`.

6. **The release version lives in `style.css`, and only `bin/release.sh` changes it.**
   *Why:* it is what WordPress and the GitHub updater read. `bin/release.sh` is the
   canonical SFX release-script block, placed unchanged (a newer version comes through
   `adopt-block`, never a hand edit), with its values in `bin/plugin.conf`. It releases in
   two phases: `bash bin/release.sh patch|minor|major` on a clean `main` equal to
   `origin/main` sets the one `Version:` line, moves the `## [Unreleased]` changelog
   entries under the new version, writes `languages/sfxtheme.pot`, runs the battery and
   stops on a `release/<version>` branch; after that branch is merged,
   `bash bin/release.sh publish` on `main` builds the ZIP, pushes the tag `v<version>` and
   creates the GitHub release. A git tag alone does not make a release. A pre-release
   (`X.Y.Z-rc.N`) is set and tagged by hand.

7. **Work finishes as a pull request; never merge to `main` directly.**
   *Why:* the bot review gate (`docs/pr-review-bots.md`) only sees changes that arrive
   through a PR. This is instruction-backed, not enforced — `main` currently carries no
   branch protection and no ruleset, so nothing stops a direct push but this rule. The
   release bump is no exception: its `release/<version>` branch is reviewed and merged by
   a PR like any other change, and `publish` pushes only the tag.

## Don'ts

- **Don't read HTML in a check with a hand-written regex or DOMDocument.** Use
  `SiteCheck\Observations::html_document()` (WordPress' `WP_HTML_Tag_Processor`), and
  grade whatever it cannot read with certainty as Nicht prüfbar. *Why:* 25 Gate-B passes in
  two cycles (xxb2ep650r, prv9jv3vrc) over hand-written HTML/header heuristics kept finding
  crafted variants each fix missed (comment forms, whitespace, truncation); the parser swap
  closed its cycle in two passes.
- **Don't edit the Bricks parent theme or `wp-includes/`.** This is a child theme; parent
  changes are lost on update. Extend via hooks and filters.
- **Don't add a runtime dependency.** `composer.json` requires only `php`, and the release
  ships no `vendor/` (invariant 5); what a dependency would do, a few lines of PHP in
  `inc/` do.
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
- **Don't let a verification harness touch real state outside a guaranteed
  teardown.** The manual harnesses (`tests/support/*`) run against the real
  checkout and the real site, where a fatal skips whatever cleanup sits below it.
  So every fixture is removed from **one** teardown path — a `trap` in bash, a
  single `register_shutdown_function` in PHP — declared before the first fixture
  is created, never inline after the check that used it; and every guard that
  decides *where* the harness operates fails fatally rather than continuing in the
  wrong directory. Both halves are paid for: a harness once destroyed the real
  `vendor/` because its `cd` failed without stopping the script, and
  `media-credits-rest-live-check.php` shipped a review round with one of its three
  fixtures deleted inline, which a fatal would have left on the site.

## Commands

| Role | Command |
|---|---|
| quality (the whole battery — what CI runs) | `./quality.sh` |
| typecheck | TODO — no static analyser installed (PHPStan/Psalm); see `todos.md` |
| lint | `./quality.sh` (syntax-lint stage), or `php -l <file>` for one file |
| test | `./quality.sh` — two batteries, `tests/*-test.php` and `tests/*-test.mjs`. A bare `for f in tests/*-test.php` loop returns only the last test's status, and misses the JS ones entirely |
| release | `bash bin/release.sh patch\|minor\|major`, then `bash bin/release.sh publish` (invariant 6); `publish --no-upload` builds the ZIP only and prints its path |
| test instance | `SFX_TEST_SITE=<site> bash bin/test-instance.sh install\|refresh\|remove` — copies `SHIPPED` into a local test site's `wp-content/themes/sfx-bricks-child`; the site path, URL and user come from the environment and never go into the repository |

`quality.sh` was last run on 2026-10-20 and seen to exit cleanly (56 PHP tests, 6 JS
tests, 0 syntax errors). An earlier session also saw it fail on a syntax error outside
`inc/`, on an empty test glob for either battery, and on a failing JS test; its Node
resolution was seen to reject an unusable override with every fallback removed, and a
control run with a real Node in the same stripped environment passed. `bin/release.sh`
is not run in CI; its `publish --no-upload` listing was checked by hand on 2026-10-20.

**Prompt artifacts** are also checked against `docs/prompt-standards.md`; name that file
in the gate prompt when the change touches one. Which files those are is defined once,
in CLAUDE.md §5 under "What counts as prose" — read it there. It is deliberately not
restated here: a second copy drifted narrower than the original within a day of being
written. `tests/prompt-artifact-paths-test.php` pins the citation and fails if this
paragraph starts listing the paths again — it does not police every possible way to
smuggle a copy back in, so keep the rule where it is defined.

**Local PHP:** `quality.sh` resolves `$PHP` (a bare command name goes through PATH, an
absolute path is used as given), then `php` on PATH, so it works in CI and locally without
editing. The local test site runs on DDEV (Docker); `ddev wp` from the site's directory
reaches its WordPress. The manual live checks in `tests/support/` do not run against it yet
(`todos.md`).

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
