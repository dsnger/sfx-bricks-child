# Structured data (schema) from contact infos — Design

Story: [2026-11-20-schema-from-contact-infos-story.md](../stories/2026-11-20-schema-from-contact-infos-story.md)
(level 1 — risk standard, security high; evidence battery+check+verification+abuse-path).
**Gate A:** runs — risk standard; security high; evidence battery+check+verification+abuse-path.

## Intent

Client sites already keep their company data in the theme's Contact Infos and their profiles
in Social Media Accounts. This feature publishes that data as schema.org JSON-LD, so it is
maintained in one place and reaches search engines complete — address, phone, email,
locations and contact points per area — without a Pro licence of an SEO plugin. Almost every
client site runs a free SEO plugin (Yoast, Rank Math, sometimes The SEO Framework), so working
*with* those plugins is the core of the feature, not an edge case.

## Goals, non-goals, anti-goals

- **Goals:** the story's AC-1 … AC-12.
- **Non-goals:** a schema builder (types beyond organization, locations and contact points);
  per-page schema beyond one raw JSON-LD field; FAQ, product, article or event schema; reading
  opening hours out of the existing free-text field; Yoast Local SEO / Rank Math Pro features
  (their location post types are not read).
- **Anti-goals (a failure even if it technically works):** two organization entries on one
  page; a broken page or broken plugin JSON when a plugin changes; any change to what existing
  shortcodes, Bricks dynamic tags or translations show; an editor gaining a way to put
  arbitrary script into a page.

## Decisions

Owner decisions (Daniel, 2026-11-20, in conversation):

- **D1 — inside Contact Infos.** The feature is part of the `ContactInfos` module; no new
  module. One new module edge, **ContactInfos → SocialMediaAccounts** (reads published
  accounts' URLs for `sameAs`), approved; `AGENTS.md` § Architecture records it in this change.
- **D2 — extend, don't duplicate.** With Yoast or Rank Math active, the theme adds its data to
  that plugin's organization entry through the plugin's documented filter. Accepted price: a
  dependency on those filters, watched by a release check (AC-6).
- **D3 — The SEO Framework:** the admin switches off that plugin's organization output in its
  settings; the theme then publishes its own entry.
- **D4 — any other SEO or schema plugin:** the theme stays out and names the plugin.
- **D5 — per-page field: administrators only** (users with `unfiltered_html`).
- **D6 — contacts as flexible as possible:** several entries in the roles organization,
  location, contact point for an area (the Visit Essen case).
- **D7 — opening hours backward compatible:** the existing editor field `_opening` stays
  exactly as it is; a new, optional structured field feeds only the schema.
- **D8 — roles reuse `_contact_type`:** `main` → organization, `branch` → location, plus a new
  third value for a contact point; existing entries keep their type.
- **D9 — languages:** the schema follows the page language, for Polylang and WPML alike.
- **D10 — off by default, switched on per site** (story AC-1).

My calls (recorded per CLAUDE.md "Autonomous run"; Daniel can overturn any):

- **C1 — Contact-infos values win in extension mode.** For the keys the theme supplies
  (`address`, `telephone`, `email`, `contactPoint`, `department`), the theme's non-empty value
  replaces the plugin's; `sameAs` is merged, de-duplicated. Why: the point of the feature is
  one source of truth. The settings page says so. A key the theme has no value for is left as
  the plugin set it.
- **C2 — `@type` is never changed in extension mode.** Yoast and Rank Math reference the entry
  by `@id`; changing its type is their setting's business. Consequence: the organization's own
  opening hours are published only in own-output mode with type LocalBusiness (C4). Locations
  always carry theirs.
- **C3 — locations are `department` entries** of type `LocalBusiness` on the organization,
  each with name (post title), address, telephone, email and opening hours. Contact points are
  `contactPoint` entries (`ContactPoint`, `contactType` = the area label, telephone, email); an
  entry with neither telephone nor email is skipped.
- **C4 — organization type setting:** `Organization` (default) or `LocalBusiness`, used only in
  own-output mode. No list of finer subtypes (non-goal).
- **C5 — the choice of entries lives on the settings page,** not on the contact edit screen:
  which main contact is the organization (default: the first published main contact by menu
  order, then date), and per published contact whether it is included (default: included).
  Why: the settings page is `manage_options`, matching AC-3's "administrator decides"; the
  contact screen is open to editors.
- **C6 — own output appears on every front-end HTML page**, like the plugins' own
  organization entries, in one `<script type="application/ld+json">` printed in `wp_head`.
- **C7 — release check as a manual harness** under `tests/support/`, plus a release-checklist
  line in the docs. `bin/release.sh` is a canonical block and is not edited (invariant 6).
- **C8 — logo:** the site's custom logo, else the site icon, else none. **URL:** the front-page
  URL WordPress gives for the current request (Polylang/WPML language home where they filter
  it).

## Approaches considered

1. **Own output only, stay out whenever an SEO plugin is active.** No foreign dependency, but
   no value on the typical client site. Rejected by Daniel (D2).
2. **Extend the plugin's entry through its filter (chosen).** One entry per page, full value on
   Yoast/Rank Math sites; costs a dependency on two documented filters, contained by C1/C2, by
   failing safe (AC-5) and by the release check (AC-6).
3. **Replace the plugin's entry** (remove its piece, print our own). Same dependency as 2, plus
   broken references to the removed `@id` in Yoast; nothing gained. Rejected.

## Behaviour

### Mode selection (per request, front end)

Evaluated in this order; the first match decides:

1. Feature off → nothing (AC-1). The per-page field is not printed either.
2. A known *other* SEO/schema plugin is active (at least SEOPress, All in One SEO, Schema &
   Structured Data for WP & AMP; detection by each plugin's own version constant or main class,
   verified by the builder against that plugin's current release) → nothing; settings page names
   it (AC-8). This wins even when Yoast or Rank Math is also active.
3. Yoast SEO active (`WPSEO_VERSION`, read from 28.9) → **extend** via
   `wpseo_schema_organization` (args `$data, $context`). Yoast emits that piece only when the
   site represents a company (`is_needed()`: `site_represents === 'company'`); when it
   represents a person, nothing is added and the settings page says why.
4. Rank Math active (`RANK_MATH_VERSION`, read from 1.0.282) → **extend** via
   `rank_math/json_ld` (args `$data, $jsonld`) at a priority after its Local SEO module (9) and
   before its entity connector (99); the entry is `$data['publisher']`, acted on only when its
   type is not `Person` (Rank Math's `titles.knowledgegraph_type` = `company`).
5. The SEO Framework active (`THE_SEO_FRAMEWORK_VERSION`, read from 5.2.1): while its
   `knowledge_output` setting is on → nothing, settings page shows where to switch it off
   (AC-7); when off → own output. Read from its code: with `knowledge_output` off, its
   `WebSite`/`WebPage` entities drop their `publisher`/`about` references, so no dangling
   reference remains (not run).
6. None of the above → own output (AC-2).

The settings page shows the mode the next front-end request would take, with its reason.

### What is published

From the organization contact (C5), in the page language (D9):

- `name` (company), `url` (C8), `logo` (C8), `address` (`PostalAddress` from street, zip,
  city, country — free text as entered), `telephone`, `email`, `sameAs` (published Social Media
  Accounts URLs, ordered as in their list).
- `contactPoint[]` from included contacts of the new contact-point type (C3).
- `department[]` from included `branch` contacts (C3), each with `openingHoursSpecification`
  from its structured hours when filled.
- Own-output mode with type `LocalBusiness`: the organization's own
  `openingHoursSpecification` too.

Empty values are omitted, never published as empty strings. No organization contact (none
published, or the chosen one unpublished/deleted) → no own output and no extension; the
settings page says so.

### Contact edit screen (existing meta box, additive)

- Contact type gains a third value, "contact point for an area"; its allowlist
  (`PostType::validate_meta_fields`, today mapping unknown values to `main`) and every place that
  reads `_contact_type` (shortcode `type` filter, admin column, Bricks loops) must treat it
  explicitly; existing shortcode/dynamic-tag behaviour for `main` and `branch` does not change.
  As with `branch`, the business fields stay main-only.
- New field **area label** (shown for the contact-point type), registered as a translatable
  string like the other fields.
- New optional **structured opening hours**: per weekday closed or up to two time ranges
  (`HH:MM`–`HH:MM`); validated on save (well-formed times, start before end); invalid input is
  not saved and the existing value stays, with an admin notice. Language-independent.
- Existing fields and their meta keys are untouched (D7).

### Languages (D9)

Every text value comes through `PostType::get_translated_field()`, the module's existing
Polylang/WPML path (spec `2026-10-21-contact-infos-polylang-strings-design.md`): Polylang
`pll__()`, WPML `wpml_translate_single_string`. The new area label is registered the same way.
WPML remains documentation-built and stub-tested only (that spec's D12); this feature inherits
that limit and says so in the build record.

### Per-page custom field (D5, AC-9, AC-10)

- One meta key on public post types with an edit UI, shown in a meta box only to users with
  `unfiltered_html` (on multisite that is super admins only — accepted).
- Not registered for REST (`show_in_rest` false) and stored under a protected (underscore) key,
  so neither REST nor the Custom Fields box can write it.
- Save: nonce + `current_user_can('unfiltered_html')` + `current_user_can('edit_post', $id)`;
  a save by a user without the capability leaves the stored value untouched (not wiped).
- Validation: must decode as a JSON object or array of objects; otherwise not saved, notice
  shown, old value kept. Size cap 64 KB (my call).
- Output (feature on only): the decoded value re-encoded with `wp_json_encode()` using
  `JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, in its own
  `<script type="application/ld+json">`; no raw stored text is ever echoed. Printed in every
  mode, including when an SEO plugin is active — it is the admin's explicit content.

### Output encoding (all modes)

Own output and the per-page field are printed only as `wp_json_encode()` output with
`JSON_HEX_TAG | JSON_HEX_AMP` (no `</script>` or `<!--` can survive). Contact values are
editor-controlled (`edit_others_posts`), so this encoding is the security boundary for them. In
extension mode the plugin prints; the theme hands it plain strings (tags stripped) and arrays.

### Fail-safe in extension mode (AC-5)

The filter callbacks accept anything: if the incoming value is not an array of the expected
shape, they return it unchanged; any `Throwable` inside them is caught and the input returned
unchanged. A plugin that stops calling the filter simply gets no additions.

### Settings

One option, `sfx_contact_infos_schema` (array: enabled, organization contact ID, type,
excluded contact IDs), saved with `manage_options` + nonce on a settings screen under the
Contact Infos menu. Added to `DataPurge` option list. New post meta keys on contacts and the
per-page meta key are added to `DataPurge` meta keys. Export: the option and the contact meta
travel with the existing Contact Infos export (builder confirms how contact meta is exported
today and keeps the new keys in it); the per-page field is **not** exported (page content, not
theme settings).

## Verification (evidence mode battery+check+verification+abuse-path)

- **Battery:** `./quality.sh` green.
- **Check (unit tests, `tests/*-test.php`, stubs):** mode selection for each row of the
  order above, including "other plugin wins over Yoast"; the published structure for a fixture
  of one organization, two locations with hours, two contact points, social accounts; empty
  values omitted; extension callbacks merge per C1/C2 and return malformed input unchanged;
  opening-hours validation; per-page validation and encoding (a value containing
  `</script><script>alert(1)</script>` and `<!--` comes out hex-escaped); `_contact_type`
  allowlist keeps `main`/`branch` and accepts the new value. Each test fails against the code
  without the feature (counterfactual).
- **Named verification (DDEV test site):** for no plugin, Yoast free, Rank Math free and The
  SEO Framework (organization output off, then on), fetch a page and confirm exactly one
  organization entry carrying the contact-infos data, valid JSON; the same with Polylang in a
  second language showing the translated values. Results recorded in the PR. Schema.org
  validator / Google Rich Results Test on one sample per mode (AC-11), recorded with date.
- **Abuse path:** as an editor (not admin): the per-page meta box is absent; a forged POST and a
  REST write of the meta key do not change it; a contact value containing a script-breaking
  string renders hex-escaped in own output.
- **Release check (AC-6, C7):** `tests/support/` harness that activates each supported SEO
  plugin on the test site in turn, fetches a page, reports whether the theme's data arrived,
  and restores the plugin activation state from one teardown (`AGENTS.md` Don'ts, harness
  teardown); the release docs gain the line to run it.

## Sources and impact boundary

- Read: `inc/ContactInfos/{Controller,PostType,FieldRegistry}.php`,
  `inc/ContactInfos/Shortcode/SC_ContactInfos.php`, `inc/SocialMediaAccounts/{PostType,FieldRegistry}.php`,
  `inc/DataPurge.php`, `inc/ImportExport/Controller.php` (export list), the 2026-10-21 Polylang
  spec, `AGENTS.md`, `.claude/review-gates.md`, `.claude/living-docs.md`.
- Third-party APIs: Yoast SEO 28.9 — Context7 `/yoast/developer` (`wpseo_schema_organization`
  doc) and plugin source (`src/generators/schema/organization.php`, `schema-generator.php`);
  Rank Math 1.0.282 — Context7 `/websites/rankmath_kb` (`rank_math/json_ld`) and plugin source
  (`includes/modules/schema/snippets/class-publisher.php`, `includes/modules/local-seo/class-local-seo.php`);
  The SEO Framework 5.2.1 — not looked up in Context7; plugin source
  (`bootstrap/define.php`, `inc/classes/data/plugin/setup.class.php`,
  `inc/classes/meta/schema/entities/{organization,website,webpage}.class.php`). WordPress core
  APIs (`wp_json_encode`, `register_post_meta`, `current_user_can`) — builder looks them up in
  Context7 for the core version on the test site. Bricks 2.4.5 (test site) emits only accordion
  FAQ schema.
- Impact: `inc/ContactInfos/*` (new classes for schema output, settings screen, per-page meta
  box; meta-box and validation changes in `PostType.php`), `inc/DataPurge.php` (lists),
  `AGENTS.md` (edge list, module summary), `README.md`/`CHANGELOG.md`, `tests/`. Not touched:
  Bricks parent, `wp-includes`, other modules' code.
- Actions outside the repository needing Daniel: none. Installing Yoast, Rank Math and The SEO
  Framework on the local DDEV test site is local and reversible.

## Left to the builder

Class split inside `inc/ContactInfos/`, settings-screen layout (following `DESIGN.md`), the
opening-hours input UI, the exact Rank Math priority between 9 and 99, the detection constant
for each "other" plugin (verified against its current release), the meta key names (module
prefix), and how the harness switches plugins.
