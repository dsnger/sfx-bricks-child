# Contact infos via Polylang string translations — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Contact field values are registered on Polylang's Translations screen and printed translated, with the never-working Polylang/WPML code removed and output unchanged on sites without Polylang.

**Architecture:** `SFX\ContactInfos\PostType` gets one `admin_init` callback, `register_translatable_strings()`, that registers every non-empty field of every published contact through `pll_register_string()`. The existing helper `PostType::get_translated_field()` loses its WPML branch and stays the single translation step; `SC_ContactInfos::render_address_field()` routes its composed-address parts through it.

**Tech Stack:** PHP 8 (WordPress, Polylang 3.9.1 public API `pll_register_string()` / `pll__()`); tests in plain PHP (`tests/*-test.php`, run by `./quality.sh`).

**Spec:** `docs/superpowers/specs/2026-10-21-contact-infos-polylang-strings-design.md` (Gate A closed, `b1a2135`) — read it first; this plan does not restate its reasoning. Where they differ, the spec wins.

No story cited — unprofiled.

## Global Constraints

- **Working directory:** the theme checkout `/Users/daniel/DEVELOPMENT/SFX-WP-PLUGINS/sfx-bricks-child`. Branch `feat/contact-infos-polylang-strings` (exists, spec committed).
- **Local PHP:** `PHP="${PHP:-php}"`; battery `./quality.sh`.
- **Commits:** tasks commit `WIP: …` snapshots (`git commit -m 'WIP: …'`, one `-m`, nothing else); the real message is written once when Gate B closes (Task 4).
- Polylang is optional: every Polylang call sits behind `function_exists()`; no new dependency, no new option key, no new module edge (invariant 5, AGENTS.md "Dependency direction").
- Group label, verbatim: `__('Contact Information', 'sfxtheme')` — the string already exists in the catalogue (CPT label), so `languages/sfxtheme.pot` needs no change.
- Name, verbatim format: `sprintf('%s #%d', $label, $post_id)` with `$label` from `FieldRegistry::get_fields()`; never the post title.
- `multiline` is `true` exactly for `address` and `opening`.
- Escaping stays at the existing output sites (invariant 3); nothing is escaped at registration.
- `get_translated_field()` keeps its signature and its current empty handling (`empty()` → re-read meta → `''`), so the string `"0"` still prints as `''` without Polylang — Acceptance 1 (byte-identical) depends on it (Gate A pass 3, finding 1).

## Review Focus

1. **A contact title with markup** (`<img src=x onerror=alert(1)>`) — must not reach any `pll_register_string()` name or group. Pinned in Task 1.
2. **Draft or private contact** — not registered (query asks `post_status => 'publish'`). Pinned in Task 1 via the recorded `get_posts()` args.
3. **Composed address without Polylang** — byte-identical to today, including a `zip` without `city`. Pinned in Task 2.
4. **`@format:tel` and the `tel:` link with a translated phone** — both built from the translated number. Pinned in Task 2.
5. **A field with several meta rows** (`ImportExport` writes them with `add_post_meta()`) — the shortcode prints the rows joined with `, `; the registered original must be that same joined text, or its translation never applies. Pinned end to end in Task 2.

## Sources and impact boundary

Read: the spec; `inc/ContactInfos/PostType.php` (init, multilingual block 735–850, admin columns 650–700); `inc/ContactInfos/Shortcode/SC_ContactInfos.php` (`render_contact_info`, `get_field_value`, `render_address_field`); `inc/ContactInfos/Controller.php` (Bricks tag render → shortcode); `inc/ContactInfos/FieldRegistry.php`; `tests/support/social-bricks-stubs.php`; `tests/contact-info-tel-test.php`; Polylang 3.9.1 `src/api.php`, `src/admin/admin-strings.php`, `src/settings/table-string.php`. Impact stays inside `inc/ContactInfos/`, one new test file, one additive line in the shared stub, the deletion of case 9b in `tests/contact-info-tel-test.php` (it pins the removed WPML lookup), `CHANGELOG.md`. Not touched: SocialMediaAccounts, ImportExport, DataPurge (Polylang stores the translations; the theme adds no option), HelpTab (no help text promised by the spec).

**Handover boundary:** the whole plan is one unit; the handover is written after the PR is open (Task 4).

---

### Task 1: Register contact field values with Polylang; remove the dead code

**Files:**
- Modify: `inc/ContactInfos/PostType.php` (`init()` line with `sfx_init_advanced_features`; methods `register_multilingual_support`, `register_polylang_strings`, `register_wpml_strings`)
- Modify: `tests/support/social-bricks-stubs.php` (`get_posts()`: record args)
- Create: `tests/contact-info-polylang-test.php`

**Interfaces:**
- Produces: `PostType::register_translatable_strings(): void` — new public static method, hooked with `add_action('admin_init', [self::class, 'register_translatable_strings'])` in `init()` (replacing the `sfx_init_advanced_features` line). Returns at once, before any query, when `pll_register_string` does not exist. The old `register_polylang_strings()` is deleted with the other two (spec "Removed", Acceptance 4).

- [ ] **Step 1: Shared stub records `get_posts()` args** — in `tests/support/social-bricks-stubs.php` `get_posts()`, append `$args` to `global $test_get_posts_calls` before returning. Additive; existing tests ignore it.

- [ ] **Step 2: Write the failing test** `tests/contact-info-polylang-test.php`, same harness style as `tests/contact-info-tel-test.php` (its `apply_filters` table, then `require` the shared stub and the four ContactInfos classes). The Polylang stubs are declared **conditionally at runtime** (`if (!function_exists('pll_register_string')) { function pll_register_string(...) {...} }`) after the no-Polylang section of Task 2, because a top-level function is declared at compile time and would make `function_exists()` true for the whole file. `pll_register_string($name, $string, $context, $multiline)` appends to `$test_pll_registered`.
  Fixtures: contact 410 published, title `<img src=x onerror=alert(1)>`, meta `_phone => ['0201 123']`, `_address => ['<p>Hauptstr. 1</p>']`, `_opening => ['Mo–Fr 9–17']`, `_country => ['Deutschland']`, `_zip => ['0']`, `_fax => ['']`; contact 411 with `_city => ['Essen', 'Bochum']` (two meta rows); `$test_post_lists['sfx_contact_info'] = [410, 411]`.
  Case `0b`, **before** the conditional stubs: `PostType::register_translatable_strings()` returns and `$test_get_posts_calls` stays empty (no query without Polylang).
  Assertions (case `1`, after the stubs):
  - after `PostType::register_translatable_strings()`, `$test_get_posts_calls` last entry has `post_type === 'sfx_contact_info'`, `post_status === 'publish'` and `numberposts === -1` (WordPress would otherwise return five);
  - registered set contains exactly `['Phone #410', '0201 123', 'Contact Information', false]`, `['Full Address #410', '<p>Hauptstr. 1</p>', 'Contact Information', true]`, `['Opening Hours #410', 'Mo–Fr 9–17', 'Contact Information', true]`, `['Country #410', 'Deutschland', 'Contact Information', false]`, `['ZIP Code #410', '0', 'Contact Information', false]` (the `"0"` reaches Polylang, which skips it itself — spec), and nothing for `fax`;
  - no registered name or context contains `<img`.
  - contact 411 registers `['City #411', 'Essen, Bochum', 'Contact Information', false]` — the joined text the shortcode prints.

- [ ] **Step 3: Run it to see it fail**
  Run: `"$PHP" tests/contact-info-polylang-test.php`
  Expected: FAIL (`register_translatable_strings()` does not exist).

- [ ] **Step 4: Implement** `register_translatable_strings()`: `get_posts(['post_type' => self::$post_type, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids'])`; per ID read all meta once with `get_post_meta($id, '', true)` and per `FieldRegistry::get_fields()` entry take `$all['_' . $field] ?? ''`, joined with `implode(', ', …)` when it is an array — **the same read as `SC_ContactInfos::get_field_value()`**, so the registered original is byte-equal to the printed one (one-line comment saying so); skip `''`; call `pll_register_string()` with the constraints above. Delete `register_multilingual_support()`, `register_polylang_strings()` and `register_wpml_strings()`; replace the `sfx_init_advanced_features` hook line in `init()` with the `admin_init` hook.

- [ ] **Step 5: Run it to see it pass**
  Run: `"$PHP" tests/contact-info-polylang-test.php` → exit 0. Then `grep -n "icl_\|wpml_register\|sfx_init_advanced_features" inc/ContactInfos/PostType.php` → only `icl_t` in `get_translated_field()` remains (removed in Task 2). `Controller.php`'s own `sfx_init_advanced_features` hook (Bricks tags) stays.

- [ ] **Step 6: Commit** `git add -A inc/ContactInfos tests && git commit -m 'WIP: register contact fields with Polylang'`

### Task 2: One translation step on every output path

**Files:**
- Modify: `inc/ContactInfos/PostType.php` (`get_translated_field()`: drop the WPML branch; `pll__()` result returned as is)
- Modify: `inc/ContactInfos/Shortcode/SC_ContactInfos.php` (`render_address_field()` composed branch)
- Modify: `tests/contact-info-polylang-test.php`
- Modify: `tests/contact-info-tel-test.php` (delete case 9b)

**Interfaces:**
- Consumes: `PostType::get_translated_field(int $post_id, string $field, string $default = ''): string` — unchanged signature.

- [ ] **Step 1: Write the failing tests** in `tests/contact-info-polylang-test.php`.
  **Before** the conditional Polylang stubs (no Polylang defined), case `0` — byte-identity without Polylang: contact 420 with `_street => ['Hauptstr. 1']`, `_zip => ['45127']`, `_city => ['Essen']`, `_country => ['Deutschland']`, empty `_address`; `render_contact_info(['field' => 'address', 'contact_id' => '420'])` equals the output captured from `main` for the same fixture (record that string in the test as the literal expected value — run the call once on `main` before the change to obtain it). Same for a `zip`-only contact (no city) and for `phone` with `format => 'tel'`.
  **After** the stubs: `pll__($s)` returns `$test_pll_translations[$s] ?? $s`. Translations: `'0201 123' => '+1 555 0100'` (different digits, so a link built from the original would show), `'Deutschland' => 'Germany'`, `'Essen' => 'Essen (EN)'`, `'<p>Hauptstr. 1</p>' => '<p>1 Main St</p>'`, `'Essen, Bochum' => 'Essen and Bochum'`. Case `2` asserts:
  - `field => 'phone'` on 410 prints `+1 555 0100` and its `href` is `tel:+15550100`;
  - `field => 'phone', format => 'tel'` prints `+15550100`; the Bricks route `Controller::render_bricks_dynamic_tag('{contact_info:phone:410 @format:tel}', null)` returns the same;
  - `field => 'country'` prints `Germany`; `field => 'address'` on 410 contains `1 Main St`; an untranslated field (`opening`) prints its original;
  - `field => 'city'` on 411 prints `Essen and Bochum` (registration-to-output, multi-row);
  - composed address on 420 contains `Essen (EN)` and `Germany` and still `45127`;
  - contact 421 with `_city => ['', 'Essen']` (an empty row): registration offers `', Essen'`; with `', Essen' => 'Essen (EN)'` translated, both `field => 'city'` and the composed address print `Essen (EN)`; without that translation the composed address prints `Essen` exactly as on `main` (case `0` gets the same fixture for the no-Polylang byte-identity).
  Case `0c`, also **before** the Polylang stubs: `define('ICL_SITEPRESS_VERSION', 'test')` and a runtime-declared `icl_t()` that records its calls; `field => 'city'` on 420 prints `Essen` and `icl_t` was never called — WPML is no longer consulted (spec "Removed"). Case 9b of `tests/contact-info-tel-test.php` is deleted: it pins the removed lookup, and that file declares `pll__` at top level, so it cannot observe a WPML-only site.

- [ ] **Step 2: Run to see it fail** — `"$PHP" tests/contact-info-polylang-test.php` → FAIL on the composed-address assertions (parts bypass translation today).

- [ ] **Step 3: Implement** — in `render_address_field()` composed branch, per part (`street`, `zip`, `city`, `country`): look the translation up under the **registered original** — the rows joined with `implode(', ', …)` as in Task 1 — through `\SFX\ContactInfos\PostType::get_translated_field($contact_id, '<field>', $joined)`; use the result when it differs from `$joined`, otherwise keep today's `convert_meta_to_string()` value. That keeps no-Polylang output byte-identical (empty rows still dropped) while a translation entered for the registered text also reaches the composed address. In `get_translated_field()`, delete the WPML block and return `pll__($value)` directly when `pll__` exists (the `!== $value` comparison adds nothing).

- [ ] **Step 4: Run to see it pass** — new test exit 0; `"$PHP" tests/contact-info-tel-test.php` exit 0 (only case 9b removed); `grep -rn "icl_t\|wpml_register_single_string" inc/ContactInfos/` → no output (Acceptance 4).

- [ ] **Step 5: Battery and commit** — `./quality.sh` green (Acceptance 6); `git add -A inc/ContactInfos tests && git commit -m 'WIP: translate composed address, drop WPML lookup'`.

### Task 3: Live verification on the DDEV site (Acceptance 5)

Named verification, not a committed harness. Site: DDEV project `sfx-bricks-child` at `/Users/daniel/DEVELOPMENT/LOCALHOST/sfx-bricks-child.local` (`SITE`), `https://sfx-bricks-child.ddev.site`; WP-CLI as `ddev wp` run from `SITE`; the theme there is a plain copy (`THEME=$SITE/wp-content/themes/sfx-bricks-child`); `REPO` is the absolute theme checkout path. The site is a single-user local test site; the run requires exclusive use of it while it runs, because the database is restored wholesale at the end.

- [ ] **Step 1: Preconditions (stop, change nothing, if any fails)** — `cd "$SITE" || exit 1`; `test ! -e "$SITE/wp-content/plugins/polylang"`; `ddev wp option get polylang` fails; baseline `git -C "$REPO" archive main inc/ContactInfos | tar -x -C "$SCRATCH/main-baseline"` and `diff -rq "$SCRATCH/main-baseline/inc/ContactInfos" "$THEME/inc/ContactInfos"` empty (seen 2026-10-21: identical).
- [ ] **Step 2: One teardown, registered before the first change** — a bash script in the scratchpad, `set -u`, `trap teardown EXIT` declared before anything is created, with two stage flags `DB_SAVED=0`, `THEME_SAVED=0`. Setup, each step fatal on failure and **before any site change**: `ddev snapshot --name sfx-pll-<run>` → `DB_SAVED=1`; `cp -a "$THEME/inc/ContactInfos" "$SCRATCH/theme-backup"` followed by `diff -rq` against the theme (must be empty) → `THEME_SAVED=1`. `teardown` keeps the original exit status, restores only what was saved and collects every failure: if `DB_SAVED`, `ddev snapshot restore sfx-pll-<run>` (the whole database back to the moment before the run — Polylang's options, languages, default-category translations, `polylang_mo` posts, the disposable contact and pages, whatever Polylang created); `rm -rf "$SITE/wp-content/plugins/polylang"` (absent before, per Step 1); if `THEME_SAVED`, restore `$THEME/inc/ContactInfos` from the backup. Then check `ddev wp plugin list` (no Polylang), `ddev wp option get polylang` (fails) and `diff -rq` backup vs theme (empty); any failed restore or check → report it, **keep the snapshot and the backup** for manual recovery, exit non-zero. Only when everything passed are snapshot and backup deleted. Guards that choose `SITE`, `THEME` or `SCRATCH` exit non-zero on failure.
- [ ] **Step 3: Counterfactual first** — `ddev wp plugin install polylang --version=3.9.1 --activate`; languages Deutsch (default, `de_DE`) and English (`en_US`) via `PLL()->model->add_language()` in `ddev wp eval`; create a disposable contact (`sfx_contact_info`, published, phone `0201 7777`, country `Deutschland`) and one page per language with `[contact_info field="phone" contact_id="<id>"]` and `[contact_info field="country" contact_id="<id>"]`. With the theme copy still at `main`, Languages → Translations (logged-in browser) shows no "Contact Information" group.
- [ ] **Step 4: Observe with the change** — copy `$REPO/inc/ContactInfos/` over `$THEME/inc/ContactInfos/`. The Translations screen now lists `Phone #<id>` and `Country #<id>` in "Contact Information". Enter `+1 555 0177` and `Germany`; the English page shows them with `href="tel:+15550177"`, the German page the originals. Change the contact's phone to `0201 8888` (never translated); the English page shows `0201 8888`. Record URL and value for each observation.
- [ ] **Step 5: Teardown** — exit the script normally so the trap runs; its checks must pass.

### Task 4: Changelog, Gate B, PR

- [ ] **Step 1:** `CHANGELOG.md` `## [Unreleased]` → `### Added`: "Contact infos: with Polylang, every contact field can be translated under Languages → Translations (group "Contact Information"); a translation applies until the original text changes. The unused WPML lookup is removed." Commit it as `git commit -a -m 'WIP: changelog'`; the close in Step 3 folds every WIP into one commit.
- [ ] **Step 2: Gate B** per `.claude/review-gates.md` (floor 3, unprofiled): two sequential `mcp__codex__review` calls per pass (`spec`, then `quality`), same full 40-char `baseSha` (parent of the first WIP) and `headSha`; `additionalContext` carries the spec path, the falsify lens and the slot path. Repair, re-review until the closure ordering closes.
- [ ] **Step 3: Close** — `git reset --soft <parent-of-first-WIP>` then one commit `feat(contact-infos): translate contact fields through Polylang strings` carrying the Gate-B provenance line, curve, ledger check, and the live observations of Task 3 (steps 3–4); plus outcome lines for the spec cycle's owed hardening (`cycle 3crcighj4n; hardening gate-a-spec-3crcighj4n-pass-1:5: rung 0 — Gate A` and the same for `pass-2:1`), if judged rung 0.
- [ ] **Step 4:** push, open the PR (never merge), write `.context/handover-contact-infos-polylang.md`.
