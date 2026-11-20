# Structured data from contact infos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish the Contact Infos company data as schema.org JSON-LD — own output, or an extension of Yoast's / Rank Math's organization entry — plus an admin-only per-page JSON-LD field.

**Architecture:** New classes under `inc/ContactInfos/Schema/` (namespace `SFX\ContactInfos\Schema`), wired from the existing `ContactInfos\Controller`. Pure logic (hours, selection, graph, merge, mode) is separated from WordPress glue so it is unit-testable with the repo's plain-PHP stub tests. Additive changes to `PostType.php` (third contact type, two new fields) and `SC_ContactInfos.php` (cache lifecycle).

**Tech Stack:** PHP 8.x, WordPress 7.1.4 (test site), Bricks 2.4.5, plain-PHP tests run by `./quality.sh`.

**Spec:** [docs/superpowers/specs/2026-11-20-schema-from-contact-infos-design.md](../specs/2026-11-20-schema-from-contact-infos-design.md) (approved, Gate A cycle k7schm42). Story: [2026-11-20-schema-from-contact-infos-story.md](../stories/2026-11-20-schema-from-contact-infos-story.md). Every task's implementer reads the spec section its task names.

## Global Constraints

- No runtime dependency, no `vendor/` at runtime (AGENTS.md invariant 5, Don'ts).
- PSR-4 path case equals class case byte-for-byte (invariant 1): `inc/ContactInfos/Schema/<Class>.php`.
- Every admin write: capability **and** nonce (invariant 2). Settings: `manage_options`. Per-page field: `manage_options` + `unfiltered_html` + `edit_post`.
- Escape at output (invariant 3); JSON-LD only via `wp_json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`; `false` → print nothing.
- New strings use text domain `sfxtheme` (invariant 4).
- Names fixed by this plan: option `sfx_contact_infos_schema` (`enabled` bool, `type` `'Organization'|'LocalBusiness'`, `chosen_once` bool); contact meta `_sfx_contact_schema_area`, `_sfx_contact_schema_hours`, `_sfx_contact_schema_org` (`'1'` when marked), `_sfx_contact_schema_excluded` (`'1'` when excluded); per-page meta `_sfx_page_schema`; new contact type value `contact_point`.
- Detection constants (spec § Mode selection): `SEOPRESS_VERSION`, `AIOSEO_VERSION`, `SASWP_VERSION` (conflict); `WPSEO_VERSION`, `RANK_MATH_VERSION`, `THE_SEO_FRAMEWORK_VERSION`.
- Do not touch: Bricks parent, `wp-includes`, `inc/ImportExport/`, `bin/release.sh`, other modules' code (except `inc/DataPurge.php` lists and its admin description).

## Review Focus

1. A contact with only the formatted `_address` and no parts → no `address`, listed as incomplete (Task 3 test `formatted_only_contact_has_no_address`).
2. An editor on a single site (holds `unfiltered_html`) opening a page → no per-page box, forged POST ignored (Task 5 test `editor_with_unfiltered_html_cannot_save`).
3. A contact value with `</script><script>alert(1)</script>` or `<!--` → hex-escaped in own output, tag-stripped before handing to a plugin (Task 4 tests `own_output_escapes_script_breakers`, `extension_values_are_tag_stripped`).
4. Rank Math in person mode with an array `@type` in either order → publisher unchanged (Task 4 test `rank_math_person_array_types_untouched`).
5. Merge import bringing a second organization mark → no automatic output (Task 3 test `two_marks_fail_closed`).

## Sources and impact boundary

Spec § "Sources and impact boundary" holds every third-party and WordPress API lookup. Added here (closes the Gate A pass-2 Minor in `todos.md` for this plan): `home_url( $path = '', $scheme = null )` and the `wp_head` action, Context7 `/websites/developer_wordpress_reference_functions`, looked up 2026-11-20. Impact: as the spec lists; additionally `tests/` (new tests, stubs under `tests/support/`).

**Long runs (≥ 45 min):** none expected; `./quality.sh` runs in minutes. **Handover boundary:** the completed story (after merge). **Actions outside the repository needing Daniel:** none — plugin installs on the local DDEV test site are local and reversed by the harness teardown.

---

### Task 1: Opening hours value logic

**Files:**
- Create: `inc/ContactInfos/Schema/OpeningHours.php`
- Test: `tests/contact-schema-hours-test.php`

**Interfaces:**
- Produces: `final class OpeningHours` with
  - `public const DAYS = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];`
  - `public static function sanitize(array $input, array $previous): array` — returns `['hours' => array<string, array{state:'unset'|'closed'|'open', ranges:list<array{0:string,1:string}>}>, 'errors' => list<string>]`; a day whose input is invalid keeps its `$previous` value and adds an error.
  - `public static function to_schema(array $hours): list<array>` — `OpeningHoursSpecification` entries.
- Stored shape (meta `_sfx_contact_schema_hours`): the `hours` array above.

- [ ] **Step 1: Write the failing tests** — `unset_days_produce_nothing`, `closed_day_is_00_00`, (`opens`/`closes` both `'00:00'`), `two_ranges_are_two_entries`, `overnight_range_kept` (`22:00`→`02:00` valid, `closes` `02:00`), `overlapping_ranges_rejected` (`09:00–13:00` + `12:00–18:00` → error, previous kept), `malformed_time_rejected` (`25:00`, `9:00`, `''` in an open state), `more_than_two_ranges_rejected`, `all_unset_gives_empty_list`. Each entry: `['@type'=>'OpeningHoursSpecification','dayOfWeek'=>'https://schema.org/Monday','opens'=>'09:00','closes'=>'17:00']`.
- [ ] **Step 2:** Run `php tests/contact-schema-hours-test.php` — expect failure (class missing).
- [ ] **Step 3:** Implement. Times match `^([01]\d|2[0-3]):[0-5]\d$`; equal start and end is invalid; overlap check treats an overnight range as ending at 24:00 on that day.
- [ ] **Step 4:** Run the test — expect all PASS; run `./quality.sh`.
- [ ] **Step 5:** Commit `feat(contact-infos): opening hours logic for schema`.

### Task 2: Contact edit screen — third type, area label, structured hours, translations

**Files:**
- Modify: `inc/ContactInfos/PostType.php` (type select ~274-280, `validate_meta_fields` ~184, `save_custom_fields` ~540-580, `register_translatable_strings` ~749-790, admin column ~643-646)
- Modify: `inc/ContactInfos/HelpTab.php` (document the new type and fields)
- Test: `tests/contact-schema-edit-screen-test.php`

**Interfaces:**
- Consumes: `OpeningHours::sanitize()`, `OpeningHours::DAYS`.
- Produces: `_contact_type` ∈ `main|branch|contact_point`; meta `_sfx_contact_schema_area` (sanitized text), `_sfx_contact_schema_hours` (Task 1 shape); translatable string fields `schema_area` and `title` readable via `PostType::get_translated_field($id, 'schema_area'|'title', $default)`.

- [ ] **Step 1: Failing tests:** `contact_point_type_survives_validation`; `unknown_type_still_maps_to_main`; `contact_point_clears_business_fields` (as `branch` does); `area_label_saved_sanitized`; `invalid_hours_keep_previous_and_flag_notice`; `save_without_nonce_changes_nothing`; `existing_fields_unchanged` (save of a `main` contact writes the same `_phone`, `_opening`, … as before); `area_and_title_registered_for_polylang` and `…_for_wpml` (reuse the stubs of `tests/contact-info-polylang-test.php` / `contact-info-wpml-test.php`); `schema_org_and_excluded_marks_not_written_by_edit_form` (posted values for those keys are ignored).
- [ ] **Step 2:** Run — expect failures.
- [ ] **Step 3:** Implement. Area label input shown for `contact_point` (plain JS toggle like the existing main-only fields). Hours: per weekday a select (not specified / closed / open) and up to two from–to `type="time"` pairs. Invalid hours → transient-based admin notice keyed by user, matching how the module already shows notices (`admin_notices`).
- [ ] **Step 4:** Run tests + `./quality.sh` — PASS. `tests/contact-info-polylang-test.php` and `contact-info-wpml-test.php` stay green unchanged.
- [ ] **Step 5:** Commit `feat(contact-infos): contact point type, area label and structured hours`.

### Task 3: Selection and graph builder

**Files:**
- Create: `inc/ContactInfos/Schema/Selection.php`, `inc/ContactInfos/Schema/GraphBuilder.php`, `inc/ContactInfos/Schema/Settings.php`
- Test: `tests/contact-schema-graph-test.php`

**Interfaces:**
- `Settings::OPTION = 'sfx_contact_infos_schema'`; `Settings::get(): array{enabled:bool,type:string,chosen_once:bool}` (missing option → `enabled false, type Organization, chosen_once false`); `Settings::update(array $values): void`.
- `Selection::resolve(): array{status:'ok'|'none_marked_unconfigured'|'marked_invalid'|'multiple_marked'|'deleted_after_choice'|'no_main', organization_id:int, location_ids:list<int>, contact_point_ids:list<int>, unused_main_ids:list<int>}` — implements spec § "Selection of contacts" exactly; reads posts fresh (no shortcode cache).
- `Selection::save_choice(int $organization_id, list<int> $excluded_ids): bool` — validates (published `main`), clears every other `_sfx_contact_schema_org`, sets the chosen one, writes exclusions, sets `chosen_once`.
- `GraphBuilder::organization(Selection result, string $type): array{entity:array, incomplete:list<array{id:int, missing:list<string>}>}` — the entity *without* `@context`/`@id`/`url`/`logo` (Task 4 adds those for own output); empty array when not publishable. Includes `sameAs` from published `sfx_social_account` posts' `_link_url` (use `\SFX\SocialMediaAccounts\PostType::$post_type` and `FieldRegistry::get_meta_key('url')`, order `menu_order`), `contactPoint[]`, `department[]`, and — only when `$type === 'LocalBusiness'` and the organization has a usable address — `openingHoursSpecification`; downgrade to `Organization` otherwise.
- `GraphBuilder::postal_address(int $contact_id): ?array` — `null` unless street and city are filled.
- All text through `PostType::get_translated_field()`; location names via field `title`, area labels via `schema_area`; every text value `wp_strip_all_tags()`ed.

- [ ] **Step 1: Failing tests** with a fixture of one `main` (marked), two `branch` (one with hours), two `contact_point`, one excluded `branch`, two social accounts: `full_fixture_structure` (exact expected array), `empty_values_omitted`, `formatted_only_contact_has_no_address`, `location_without_address_left_out_and_listed`, `contact_point_without_label_or_channel_left_out`, `local_business_without_address_downgrades`, `marked_unpublished_fails_closed`, `two_marks_fail_closed`, `deleted_after_choice_fails_closed` (no mark, `chosen_once` true, another `main` published), `unconfigured_picks_first_main_by_menu_order`, `save_choice_leaves_exactly_one_mark`, `save_choice_rejects_non_main`, `organization_not_excludable`, `other_main_contacts_listed_unused`, `translated_values_used` (Polylang stub returns translations for company, a location `title` and a `schema_area`).
- [ ] **Step 2:** Run — expect failures.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run + `./quality.sh` — PASS.
- [ ] **Step 5:** Commit `feat(contact-infos): schema selection and graph builder`.

### Task 4: Mode detection, own output, Yoast and Rank Math extension

**Files:**
- Create: `inc/ContactInfos/Schema/Mode.php`, `inc/ContactInfos/Schema/Output.php`, `inc/ContactInfos/Schema/PluginMerge.php`
- Modify: `inc/ContactInfos/Controller.php` (wire `Output::register()` from the constructor)
- Test: `tests/contact-schema-output-test.php`

**Interfaces:**
- `Mode::detect(): array{mode:'off'|'conflict'|'yoast'|'rank_math'|'tsf_suppressed'|'own', reason:string, plugins:list<string>}` — spec § Mode selection order; TSF reads its `knowledge_output` from option `autodescription-site-settings` (array key `knowledge_output`, default on when absent).
- `PluginMerge::merge(array $plugin_entity, array $theme_entity): array` — C1: theme sets `name`, `address`, `telephone`, `email`, `contactPoint`, `department` when non-empty; `sameAs` merged + de-duplicated; never touches `@id`, `@type`, `url`, `logo`, `image`.
- `Output::register(): void` — adds `wp_head` (own output, priority after SEO plugins' defaults is not required), `wpseo_schema_organization` (10, 2) and `rank_math/json_ld` (priority 20, 2 args) when the respective mode applies; every callback returns its input unchanged on unexpected shape or any `Throwable`.
- Rank Math callback: acts only when `titles.knowledgegraph_type` is `company` (read via `\RankMath\Helper::get_settings()` guarded by `method_exists`) and `$data['publisher']['@type']` contains no `Person`; syncs `$data['place']['address']` when the theme supplies an address and `place` exists.
- Own output: `@context` `https://schema.org`, `@id` `home_url('/') . '#organization'`, `url` `home_url('/')`, `logo` custom logo → site icon → none.

- [ ] **Step 1: Failing tests:** one per mode row incl. `other_plugin_beats_yoast`, `two_of_yoast_rankmath_tsf_is_conflict`, `tsf_on_suppresses`, `tsf_off_is_own`, `off_prints_nothing`; `yoast_merge_conflicting_values` (theme wins, `@id/@type/url/logo` unchanged); `yoast_person_mode_nothing_added` (filter not reached → covered by mode reason); `rank_math_company_merged_and_place_synced`; `rank_math_person_scalar_untouched`; `rank_math_person_array_types_untouched` (both orders); `rank_math_missing_publisher_untouched`; `callbacks_return_input_on_non_array`; `callbacks_return_input_when_builder_throws`; `own_output_escapes_script_breakers` (`</script><script>alert(1)</script>`, `<!--` in company → `<` in output, exactly one `<script type="application/ld+json">`); `extension_values_are_tag_stripped`; `own_output_on_no_organization_prints_nothing`.
- [ ] **Step 2:** Run — expect failures.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run + `./quality.sh` — PASS.
- [ ] **Step 5:** Commit `feat(contact-infos): schema output and SEO plugin extension`.

### Task 5: Per-page custom JSON-LD field

**Files:**
- Create: `inc/ContactInfos/Schema/PageField.php`
- Modify: `inc/ContactInfos/Controller.php` (register)
- Test: `tests/contact-schema-page-field-test.php`

**Interfaces:**
- `PageField::META = '_sfx_page_schema'`; `PageField::can_edit(int $post_id): bool` (`manage_options` && `unfiltered_html` && `edit_post`); `PageField::validate(string $raw): array{action:'delete'|'store'|'reject', value:string, error:?string}` (whitespace → delete; object or array-of-objects ≤ 65536 bytes → store; else reject); `PageField::render_for_request(): string` (spec § Per-page custom field, Output).
- Registered with `register_post_meta('', META, ['show_in_rest' => false, 'single' => true, 'type' => 'string', 'auth_callback' => [PageField::class, 'auth']])`, where `auth` returns `can_edit`.

- [ ] **Step 1: Failing tests:** `admin_can_save`, `editor_with_unfiltered_html_cannot_save`, `author_cannot_save`, `save_without_nonce_keeps_value`, `whitespace_deletes`, `invalid_json_rejected_old_kept`, `scalar_json_rejected`, `array_with_non_object_rejected`, `over_64kb_rejected`, `output_hex_escapes` (`</script>` and `<!--` inside a string value), `password_protected_prints_nothing`, `draft_prints_nothing`, `non_singular_prints_nothing`, `feature_off_prints_nothing`, `conflict_mode_still_prints` (spec: every mode except Off).
- [ ] **Step 2:** Run — expect failures.
- [ ] **Step 3:** Implement (meta box on `get_post_types(['public' => true, 'show_ui' => true])`, rendered only when `can_edit`).
- [ ] **Step 4:** Run + `./quality.sh` — PASS.
- [ ] **Step 5:** Commit `feat(contact-infos): admin-only per-page JSON-LD field`.

### Task 6: Settings screen

**Files:**
- Create: `inc/ContactInfos/Schema/SettingsPage.php`
- Modify: `inc/ContactInfos/Controller.php` (register submenu under the contact CPT menu)
- Test: `tests/contact-schema-settings-page-test.php`

**Interfaces:**
- Consumes: `Settings`, `Selection::resolve()`, `Selection::save_choice()`, `Mode::detect()`, `GraphBuilder::organization()` (for the incomplete list).
- Submenu slug `sfx-contact-schema`, capability `manage_options`, nonce action `sfx_contact_schema_save`.
- Shows: switch, type, organization select (published `main` contacts), exclusion checkboxes (organization has none), current mode + reason, "extension configured" vs "confirmed only by the release check", incomplete entities with missing parts, unused `main` contacts, the C1/C2 notes, TSF guidance, Rank Math geo reminder.

- [ ] **Step 1: Failing tests:** `save_requires_manage_options`, `save_requires_nonce`, `enable_and_type_saved`, `choice_saved_via_selection`, `invalid_choice_notice`, `mode_reason_rendered_escaped` (a contact title with markup is escaped in the page).
- [ ] **Step 2:** Run — expect failures.
- [ ] **Step 3:** Implement; layout per `DESIGN.md`, all strings `sfxtheme`.
- [ ] **Step 4:** Run + `./quality.sh` — PASS.
- [ ] **Step 5:** Commit `feat(contact-infos): schema settings screen`.

### Task 7: Purge and shortcode cache lifecycle

**Files:**
- Modify: `inc/DataPurge.php` (option list; new unconditional `SCHEMA_META_KEYS` deleted in `run()` regardless of `$include_media_credits`; purge description text on its admin page)
- Modify: `inc/ContactInfos/Shortcode/SC_ContactInfos.php` and `inc/ContactInfos/PostType.php` cache-clear paths (~851-880)
- Test: extend `tests/data-purge-test.php`; `tests/contact-schema-cache-test.php`

**Interfaces:** `DataPurge::SCHEMA_META_KEYS = ['_sfx_page_schema','_sfx_contact_schema_area','_sfx_contact_schema_hours','_sfx_contact_schema_org','_sfx_contact_schema_excluded']`.

- [ ] **Step 1: Failing tests:** `default_purge_removes_schema_option_and_meta`, `default_purge_keeps_contact_meta_and_media_credits`; `type_cache_cleared_for_contact_point_on_save`, `retype_clears_all_three_type_lookups`, `unpublished_cached_contact_not_returned`, `manual_clear_covers_contact_point`.
- [ ] **Step 2:** Run — expect failures.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run + `./quality.sh` — PASS (existing purge tests unchanged).
- [ ] **Step 5:** Commit `feat(contact-infos): purge schema data, cache lifecycle for contact points`.

### Task 8: Release check harness and documentation

**Files:**
- Create: `tests/support/contact-schema-release-check.sh`
- Modify: `AGENTS.md` (§ What this is: mention schema; § Architecture: edge ContactInfos → SocialMediaAccounts, "owner ruling 2026-11-20", edge count nine), `README.md` (feature, release step), `CHANGELOG.md` (`## [Unreleased]`), `todos.md` (close the Gate A Minor row as done by this plan's Sources note)

**Interfaces:** `SFX_TEST_SITE=<dir> SFX_TEST_URL=<url> bash tests/support/contact-schema-release-check.sh` — for `none`, `wordpress-seo`, `seo-by-rank-math`, `autodescription` (knowledge output off, then on): activates exactly that plugin via `ddev wp`, fetches the URL, prints one line per setup `delivered | deliberately suppressed | not delivered`; records the initial active-plugin list and TSF option first and restores both from a single `trap` declared before the first change; `set -euo pipefail`; a failed `cd` aborts.

- [ ] **Step 1:** Write the harness; dry-run it against the DDEV site (Task 9 uses it).
- [ ] **Step 2:** Docs edits; `sh scripts/check-doc-links.sh` and `./quality.sh` — PASS.
- [ ] **Step 3:** Commit `docs: schema feature, edge ruling, release check`.

### Task 9: Live verification (main session, not a subagent)

Spec § Verification "Named verification" and "Abuse path", on the DDEV site with the theme copied via `bin/test-instance.sh refresh`. Install (not commit) Yoast, Rank Math, TSF, Polylang locally. Record per setup: command, observed JSON excerpt, pass/fail — in the PR body. Validator / Rich Results Test: the public Google test cannot reach a `.ddev.site` URL; paste the page's JSON-LD into the Schema.org validator and the Rich Results Test's code input; record date and result. Then the living-docs build record.

**Expected:** every row of the spec's named verification matches; abuse path shows escaped output and unchanged meta.
