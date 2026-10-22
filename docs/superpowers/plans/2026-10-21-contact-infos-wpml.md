# Contact infos via WPML string translations — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The WPML amendment of the spec: with Polylang absent, contact fields are registered through `wpml_register_single_string` and printed through `wpml_translate_single_string`; `wpml-config.xml` keeps the contact type Not translatable; `"0"` reaches neither plugin.

**Architecture:** Two existing methods of `SFX\ContactInfos\PostType` gain a WPML branch — `register_translatable_strings()` (admin) and `get_translated_field()` (output). Nothing else in the output path changes: `SC_ContactInfos::get_field_value()` and `address_part()` already call `get_translated_field()` with the field and the contact ID.

**Tech Stack:** PHP 8, WordPress hooks, WPML's documented hooks (no WPML code available); plain PHP tests run by `./quality.sh`.

**Spec:** `docs/superpowers/specs/2026-10-21-contact-infos-polylang-strings-design.md` at `27a5bba`, section "WPML (amendment, D10/D12)" and Acceptance 1, 2, 4, 7, 8. This plan builds on the Polylang plan (`docs/superpowers/plans/2026-10-21-contact-infos-polylang-strings.md`, implemented in `337065e`) and does not repeat it.

**Superseded in part (D13, 2026-10-22, Gate B `fi2i4pzg3s` pass 1):** the canonical release and test-instance scripts reject `wpml-config.xml` as a `SHIPPED` entry, so the file and its `SHIPPED` entry were dropped; the spec now relies on WPML's default ("Not translatable") and Acceptance 8 reads "no `wpml-config.xml`, `SHIPPED` unchanged". Every `wpml-config.xml` step below is void.

No story cited — unprofiled.

## Global Constraints

- Working directory `/Users/daniel/DEVELOPMENT/SFX-WP-PLUGINS/sfx-bricks-child`, branch `feat/contact-infos-polylang-strings` (PR #75). `PHP="${PHP:-php}"`; battery `./quality.sh`.
- Commits: `git commit -m 'WIP: …'` snapshots, one `-m`, nothing else in the command; folded into one commit when Gate B closes.
- Context, verbatim: `sfx-bricks-child contact infos` — a class constant `PostType::WPML_CONTEXT`, no text domain (spec: identifier).
- WPML name, verbatim: `sprintf('%s #%d', $field, $post_id)` with the **field key** (`phone #12`), never a label or title.
- Registration call, verbatim shape: `do_action('wpml_register_single_string', PostType::WPML_CONTEXT, $name, $value, false, $source_language)`, `$source_language = apply_filters('wpml_default_language', null)` read once per request.
- Output call, verbatim shape: `apply_filters('wpml_translate_single_string', $value, PostType::WPML_CONTEXT, $name)`.
- Order: Polylang first. `function_exists('pll_register_string')` → Polylang path only; WPML hooks are then never called. In `get_translated_field()` the same rule with `pll__`.
- Registration runs the contact query only when Polylang is present or `has_action('wpml_register_single_string')` is true.
- Skip rule for both plugins: `empty($value)` (drops `''` and `"0"`).
- No WPML detection by constant (spec).

## Review Focus

1. **Polylang site with Polylang's own WPML emulation listening** — must register each field once (Polylang path), never also through the WPML action. Pinned in Task 1 (Polylang test registers a listener and asserts it is never called).
2. **WPML core without String Translation** (nothing listens) — no contact query, output unchanged. Pinned in Task 1 (WPML test, no listener).
3. **Admin language differs from site language** — the WPML name and context are identical whatever `__()` returns. Pinned in Task 1: the WPML test runs registration and output twice, once with identity gettext and once with `$test_gettext` mapping `Phone` → `Telefon`, `Country` → `Land` and `Contact Information` → `Kontaktinformationen`, and asserts the same literal context and key-based names both times.
4. **Composed address on WPML** — each part looked up by its own `<field> #<id>`. Pinned in Task 1.
5. **`"0"` value** — registered with neither plugin, printed as today (`''`). Pinned in Task 1 for both.

## Sources and impact boundary

Read: the amended spec; `inc/ContactInfos/PostType.php` (`register_translatable_strings`, `get_translated_field`); `inc/ContactInfos/Shortcode/SC_ContactInfos.php` (`get_field_value`, `address_part`); `tests/contact-info-polylang-test.php`; `tests/support/social-bricks-stubs.php`; `bin/plugin.conf`; WPML docs for the three hooks and the language-configuration file; Polylang's `src/modules/wpml/` (it reads `wpml-config.xml` and emulates the hooks). Impact: `inc/ContactInfos/PostType.php`, new `wpml-config.xml`, `bin/plugin.conf` (`SHIPPED`), new `tests/contact-info-wpml-test.php`, `tests/contact-info-polylang-test.php`, `tests/support/social-bricks-stubs.php` (guarded hook defaults), `CHANGELOG.md`. Every test that requires the shared stub and reaches `get_translated_field()` without defining `apply_filters` — `tests/social-bricks-dynamic-data-test.php` today — depends on those defaults. Not touched: `SC_ContactInfos` (already passes field and ID), `bin/release.sh` (canonical block; only its config changes).

**Handover boundary:** this plan is one unit; the handover is updated after PR #75 carries it.

---

### Task 1: WPML branch, config file, tests

**Files:**
- Modify: `inc/ContactInfos/PostType.php`
- Create: `wpml-config.xml` (content verbatim from the spec)
- Modify: `bin/plugin.conf` (`SHIPPED` gains `wpml-config.xml`)
- Create: `tests/contact-info-wpml-test.php`
- Modify: `tests/contact-info-polylang-test.php`
- Modify: `tests/support/social-bricks-stubs.php`
- Modify: `CHANGELOG.md` (the `### Added` entry gains WPML: translations under WPML → String Translation, String Translation required, contact type stays Not translatable)

**Interfaces:**
- Produces: `PostType::WPML_CONTEXT`, an untyped `public const` holding a string (typed class constants need PHP 8.3; the theme supports 8.0). `register_translatable_strings()` and `get_translated_field()` keep their signatures.

- [ ] **Step 0: Guarded hook defaults in the shared stub** — `tests/support/social-bricks-stubs.php` gains `if (!function_exists(…))` defaults for `apply_filters` (returns the value), `do_action` (no-op) and `has_action` (false). Tests that define their own (the contact tests do, before the `require`) keep theirs; `tests/social-bricks-dynamic-data-test.php`, which defines none, then reaches the new WPML filter call without a fatal. Run it after Step 4.

- [ ] **Step 1: Write the failing WPML test** `tests/contact-info-wpml-test.php`, harness like `tests/contact-info-polylang-test.php`, defining before the requires: `apply_filters` and `do_action` over a shared callback table and `has_action($hook)` = a callback exists. Every expectation uses the **literal** context `'sfx-bricks-child contact infos'`, never `PostType::WPML_CONTEXT`, so a wrong constant fails. Review Focus 3: cases `w1` and `w2` run twice — identity gettext, then `$test_gettext = ['Phone' => 'Telefon', 'Country' => 'Land', 'Contact Information' => 'Kontaktinformationen']` (`$test_transients` reset between) — with identical expectations. Fixtures as in the Polylang test (410 with `_zip => ['0']`, 411 two rows, 420 composed, 421 empty row). Cases:
  - `w0`: no listener → `register_translatable_strings()` makes no `get_posts()` call; output for 420 address and phone `format=tel` byte-identical to the literals in the Polylang test's case 0.
  - `w1`: listener on `wpml_register_single_string` recording all five arguments, `wpml_default_language` returning `'de'` → recorded set equals exactly `['sfx-bricks-child contact infos', 'phone #410', '0201 123', false, 'de']`, `['country #410', 'Deutschland', …]`, `['address #410', '<p>Hauptstr. 1</p>', …]`, `['opening #410', …]`, `['city #411', 'Essen, Bochum', …]`, `['city #421', ', Essen', …]` and nothing for `zip #410` (`"0"`) or `fax`; query args include `post_status => 'publish'`, `numberposts => -1`.
  - `w2`: listener on `wpml_translate_single_string` returning a translation keyed by `context|name|value` (`'…|phone #410|0201 123' => '+1 555 0100'`, `'…|country #410|Deutschland' => 'Germany'`, `'…|city #420|Essen' => 'Essen (EN)'`, `'…|country #420|Deutschland' => 'Germany'`) → phone prints `+1 555 0100` with `href="tel:+15550100"`; `format=tel` and the Bricks tag `{contact_info:phone:410 @format:tel}` give `+15550100`; country prints `Germany`; composed address on 420 equals `Hauptstr. 1<br>45127 Essen (EN)<br>Germany`; an untranslated field prints its original.
- [ ] **Step 2: Extend the Polylang test** — define `has_action()` (callback-table based, as in Step 1) and `do_action()` at the top; before case `1` install recording listeners on all three WPML hooks — `wpml_register_single_string`, `wpml_default_language`, and `wpml_translate_single_string` returning the distinctive `'WPML!'` — and after cases `1` and `2` assert none was ever called (and no output contains `WPML!`); change the expected set to drop `['ZIP Code #410', '0', …]` (spec "Scope of the amendment").
- [ ] **Step 3: Run both to see them fail** — `"$PHP" tests/contact-info-wpml-test.php` and `"$PHP" tests/contact-info-polylang-test.php` → FAIL (no WPML branch; `"0"` still registered).
- [ ] **Step 4: Implement** in `PostType`: the constant; in `register_translatable_strings()` the early return becomes "neither Polylang nor a WPML listener", the skip becomes `empty($value)`, and the per-field call branches Polylang → `pll_register_string()` (unchanged arguments) else the `do_action` above, with the source language read once and **only on the WPML branch** (never when Polylang is present); in `get_translated_field()` the return becomes `pll__()` when it exists, else the `apply_filters` above. Create `wpml-config.xml`; add it to `SHIPPED`.
- [ ] **Step 5: Run to see them pass** — both tests and `tests/social-bricks-dynamic-data-test.php` exit 0; `"$PHP" -r '$x = simplexml_load_file("wpml-config.xml"); $n = $x ? $x->xpath("/wpml-config/custom-types/custom-type[.=\"sfx_contact_info\"][@translate=\"0\"]") : []; exit(count($n) === 1 ? 0 : 1);'` exit 0 (Acceptance 8: well-formed and the exact node); `grep -E '^SHIPPED=' bin/plugin.conf | cut -d= -f2- | tr ' ' '\n' | grep -qx wpml-config.xml` exit 0 (a real `SHIPPED` token; the file is not shell-sourceable); `./quality.sh` green.
- [ ] **Step 6: Commit** `git add -A inc tests wpml-config.xml bin/plugin.conf CHANGELOG.md` then `git commit -m 'WIP: WPML string translations for contact infos'`.

### Task 2: Gate B, close, PR

- [ ] **Step 1: Gate B** per `.claude/review-gates.md` (floor 3, unprofiled): two sequential `mcp__codex__review` calls per pass (`spec`, `quality`), same full 40-char `baseSha` (`27a5bba`'s plan successor — the commit that closes this plan's Gate A) and `headSha`; `additionalContext` names the spec, this plan, the falsify lens and the slot. Note for the reviewer: no WPML is available (D12); the WPML path is checked against stubs that follow WPML's documented signatures only.
- [ ] **Step 2: Close** — `git reset --soft <plan commit>`, one commit `feat(contact-infos): WPML string translations` carrying the Gate-B provenance line, curve, ledger check, and outcome lines for the owed hardening of cycle `vonch8eq2o` (`rung 0 — Gate A spec review`) where judged so.
- [ ] **Step 3:** push to PR #75, add the WPML part to the PR description (including "not tested against real WPML — D12") and its review records; update `.context/handover-contact-infos-polylang.md`.
