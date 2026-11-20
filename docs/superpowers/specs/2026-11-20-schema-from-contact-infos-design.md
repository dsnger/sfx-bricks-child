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
  (their location post types are not read); changing a plugin's `@type`, `url` or `logo`.
- **Anti-goals (a failure even if it technically works):** more than one *automatic primary
  company entity* on a page (nested `department` locations are part of that one entity and
  allowed; what an administrator writes into the per-page field is theirs); a broken page or
  broken plugin JSON when a plugin changes; any change to what existing shortcodes, Bricks
  dynamic tags or translations show; anyone below administrator gaining a way to put
  arbitrary JSON-LD or script into a page.

## Decisions

Owner decisions (Daniel, 2026-11-20, in conversation):

- **D1 — inside Contact Infos.** Part of the `ContactInfos` module; no new module. One new
  module edge, **ContactInfos → SocialMediaAccounts** (reads published accounts' URLs for
  `sameAs`), approved; `AGENTS.md` § Architecture records it in this change.
- **D2 — extend, don't duplicate.** With Yoast or Rank Math active, the theme adds its data to
  that plugin's organization entry through the plugin's documented filter. Accepted price: a
  dependency on those filters, watched by a release check (AC-6).
- **D3 — The SEO Framework:** the admin switches off that plugin's organization output in its
  settings; the theme then publishes its own entry.
- **D4 — any other SEO or schema plugin:** the theme stays out and names the plugin.
- **D5 — per-page field: administrators only.**
- **D6 — contacts as flexible as possible:** several entries in the roles organization,
  location, contact point for an area (the Visit Essen case).
- **D7 — opening hours backward compatible:** the existing editor field `_opening` stays
  exactly as it is; a new, optional structured field feeds only the schema.
- **D8 — roles reuse `_contact_type`:** `main` → organization, `branch` → location, plus a new
  third value for a contact point; existing entries keep their type.
- **D9 — languages:** the schema follows the page language, for Polylang and WPML alike.
- **D10 — off by default, switched on per site** (story AC-1).

My calls (CLAUDE.md "Autonomous run"; Daniel can overturn any):

- **C1 — extension merge.** In extension mode the theme sets, from Contact Infos, `name`,
  `address`, `telephone`, `email`, `contactPoint`, `department`; its non-empty value replaces
  the plugin's, an empty one leaves the plugin's value. `sameAs` is merged and de-duplicated.
  `@id`, `@type`, `url`, `logo`, `image` stay the plugin's (other graph nodes reference them;
  the logo is an `ImageObject` with its own `@id`). The settings page says which values come
  from where.
- **C2 — `@type` is never changed in extension mode.** Consequence, accepted and shown on the
  settings page: the organization's *own* structured opening hours are published only in
  own-output mode with type LocalBusiness (C4); in extension mode they are not added, even if
  the plugin's type happens to be a LocalBusiness subtype. Locations always carry theirs.
- **C3 — entity shapes.** Locations are `department` entries of type `LocalBusiness` (name,
  address, telephone, email, `openingHoursSpecification`). Contact points are `contactPoint`
  entries (`ContactPoint`: `contactType` = area label, `telephone`, `email`).
- **C4 — organization type setting:** `Organization` (default) or `LocalBusiness`, used only in
  own-output mode.
- **C5 — selection is stored on the contacts, written only from the settings page.** Two meta
  keys on contact posts — "is the organization" and "excluded from schema" — written only by
  the settings screen (`manage_options` + nonce), never by the contact edit form. Why on the
  contacts and not as post IDs in an option: the existing export already carries every contact
  meta key with the contact and import re-creates it, so the selection travels without any ID
  remapping (Gate A pass 1, finding 8). Because a mark can be duplicated (import, overlapping
  saves) or vanish with its post, the option also records whether an organization was ever
  chosen on this site; see "Selection of contacts" for how both cases fail closed.
- **C6 — own output appears on every front-end HTML page**, in one
  `<script type="application/ld+json">` printed in `wp_head`.
- **C7 — release check as a manual harness** under `tests/support/`, plus a release-checklist
  line in `README.md`. `bin/release.sh` is a canonical block and is not edited (invariant 6).
- **C8 — logo (own output only):** the site's custom logo, else the site icon, else none.
  **URL (own output only):** `home_url('/')` for the current request (Polylang/WPML filter it to
  the language home where they do).
- **C9 — the option is not exported.** `sfx_contact_infos_schema` holds only the on/off switch,
  the type and the "chosen once" flag — per-site state (D10, C5) — so it stays out of the ImportExport catalogue and
  `inc/ImportExport/` is not changed. Recorded per AGENTS.md Don'ts (export is a decision per
  key). The per-page field is not exported either (page content). The new contact meta keys
  travel with contact export as all contact meta does today.
- **C10 — lifecycle of the switch:** option missing → off; a stored value is never changed by
  a theme update. So the update that introduces the feature leaves it off (AC-1), and a site
  that switched it on stays on through later updates.

## Approaches considered

1. **Own output only, stay out whenever an SEO plugin is active.** No foreign dependency, but
   no value on the typical client site. Rejected by Daniel (D2).
2. **Extend the plugin's entry through its filter (chosen).** One entry per page, full value on
   Yoast/Rank Math sites; costs a dependency on two documented filters, contained by C1/C2, by
   failing safe and by the release check (AC-6).
3. **Replace the plugin's entry** (remove its piece, print our own). Same dependency as 2, plus
   broken references to the removed `@id` in Yoast; nothing gained. Rejected.

## Behaviour

### Mode selection (per request, front end)

Evaluated in this order; the first match decides. "Active" means the plugin's constant below
is defined.

1. **Off** — feature switch off. Nothing is printed, the per-page field included (AC-1).
2. **Conflict, stay out** — any of these is active: SEOPress (`SEOPRESS_VERSION`, read from
   10.4), All in One SEO (`AIOSEO_VERSION`, defined from its constants list in
   `app/AIOSEO.php`, read from 5.0.5.1), Schema & Structured Data for WP & AMP
   (`SASWP_VERSION`, read from 1.71); **or** more than one of Yoast, Rank Math, The SEO
   Framework is active at once. No automatic output, no extension; the settings page names the
   plugin(s) (AC-8).
3. **Yoast SEO** (`WPSEO_VERSION`, read from 28.9). Extend via `wpseo_schema_organization`
   (`$data, $context`). Yoast emits that piece only when the site represents a company
   (`Organization::is_needed()`: `site_represents === 'company'`). Settings page states:
   "extension active" when Yoast's site representation is company, otherwise "Yoast is set to
   a person — nothing is added".
4. **Rank Math** (`RANK_MATH_VERSION`, read from 1.0.282). Extend `$data['publisher']` via
   `rank_math/json_ld` (`$data, $jsonld`) at a priority after its Local SEO module (9) and
   before its entity connector (99). Acted on only when Rank Math's setting
   `titles.knowledgegraph_type` is `company` **and** the publisher's `@type` (string or array)
   contains no `Person` — Rank Math emits `['Person','Organization']` and
   `['Organization','Person']` in person mode. When the theme replaces the address and
   `$data['place']` exists (Local SEO's `add_place_entity()`), the theme writes the same
   address into `$data['place']['address']` too; the place's `geo` stays Rank Math's, and the
   settings page reminds that coordinates are kept in Rank Math. Settings page states
   "extension active", "Rank Math is set to a person", or "Rank Math's schema module is off"
   (module id `rich-snippet`, `Helper::is_module_active()`); on singular pages where Rank Math
   itself decides to add no global entities (`JsonLD::can_add_global_entities()`), there is no
   publisher and the theme adds nothing — that is Rank Math's page-level choice.
5. **The SEO Framework** (`THE_SEO_FRAMEWORK_VERSION`, read from 5.2.1). While its
   `knowledge_output` setting is on → no automatic output; the settings page says where to
   switch it off (AC-7). When off → own output. Read from its code: with `knowledge_output`
   off, its `WebSite`/`WebPage` entities drop their `publisher`/`about` references, so no
   dangling reference remains.
6. **Own output** — none of the above (AC-2).

The settings page shows the mode the next front-end request would take, with its reason, and
distinguishes "extension configured" from "data confirmed in a page", which only the release
check (AC-6) establishes.

### Selection of contacts

- **Organization**, decided on every request from the marks and the option's "chosen once"
  flag (a boolean in `sfx_contact_infos_schema`, set the first time the settings screen saves an
  organization, never cleared except by purge):
  - exactly one contact carries the mark, and it is a published `main` contact → it;
  - exactly one carries the mark, but it is not published or not `main` → no automatic output
    and no extension, settings page asks for a choice;
  - more than one carries the mark (an import or two overlapping saves) → no automatic output
    and no extension, settings page lists them and asks for one choice;
  - none carries the mark and "chosen once" is set (the chosen contact was permanently
    deleted, its meta with it) → no automatic output and no extension, settings page asks for a
    choice — no silent substitution;
  - none carries the mark and "chosen once" is not set (a site never configured) → the first
    published `main` contact by menu order, then publish date.
- **Saving a choice** removes the mark from every other contact, then sets it on the chosen
  one, then sets "chosen once". Two overlapping saves can still leave two marks for a moment;
  the "more than one" rule above makes that state visible instead of guessing.
- The organization cannot be excluded (the settings page shows no exclusion box for it).
- Other `main` contacts are not published (one organization only); the settings page lists
  them as "not used".
- `branch` and contact-point contacts are included unless excluded.
- The settings screen re-validates on save: a submitted organization that is not a published
  `main` contact is rejected with a notice; exclusions for IDs that are not published contacts
  are ignored. Output re-validates on every request (status and type read fresh).

### Eligibility and minimum data

An entity is published only with its minimum data; otherwise it is left out and the settings
page lists it as incomplete with what is missing:

- **Organization:** name (company). Without it: no automatic output and no extension.
- **Location (`department`, a `LocalBusiness`):** name (post title) and a usable postal address.
- **Contact point:** area label and at least one of telephone or email.
- Own-output type `LocalBusiness` without a usable organization postal address → published as
  `Organization`, with a settings-page notice.

**Address** (all entities): only ever a `PostalAddress` built from the parts (`streetAddress`,
`postalCode`, `addressLocality`, `addressCountry`, text as entered). It is *usable* when street
and city are filled; otherwise no `address` is published. The formatted `_address` field is
never turned into schema (Google requires `PostalAddress` for LocalBusiness): a contact that has
only the formatted field is listed on the settings page as "fill in street, zip, city and
country for the schema". In extension mode a missing usable address leaves the plugin's own
`address` (and Rank Math's place) unchanged (C1). The existing shortcode behaviour is
unchanged.

### What is published

In the page language (D9), empty values omitted, never published as empty strings:

- Organization: `name`, `address`, `telephone`, `email`, `sameAs` (URLs of published Social
  Media Accounts, in their list order), `contactPoint[]`, `department[]`; own output adds
  `@context`, `@type` (C4), `@id` (`home_url('/') . '#organization'`), `url`, `logo` (C8).
- Own output with type `LocalBusiness`: the organization's `openingHoursSpecification`.
- Locations: `openingHoursSpecification` when their structured hours are filled.

### Opening hours (new, optional, per contact)

- Per weekday one of three states: **not specified** (default), **closed**, or **one or two
  time ranges** `HH:MM`–`HH:MM`. A range whose end is earlier than its start crosses midnight
  (schema.org allows `closes` before `opens`). Two ranges on one day must not overlap.
- Output: ranges become `OpeningHoursSpecification` entries; closed days become an entry with
  `opens` and `closes` `00:00` (Google's documented form for closed days); not-specified days
  produce nothing. All days not specified → no `openingHoursSpecification`.
- Validation on save; invalid input for a day keeps that day's stored value and shows an admin
  notice. Clearing a day means choosing "not specified". Language-independent.

### Contact edit screen (existing meta box, additive)

- Contact type gains a third value, "contact point for an area". Its allowlist
  (`PostType::validate_meta_fields`, which today maps unknown values to `main`) accepts it. As
  with `branch`, the business fields stay main-only and are cleared on save.
- Every reader of `_contact_type` keeps its behaviour for `main`/`branch` and handles the new
  value explicitly: shortcode `type` filter and its type-lookup cache, admin list column,
  dynamic tags.
- New field **area label** (for the contact-point type) and the structured opening hours.
  Area label and post title are registered as translatable strings through the module's
  existing registration (`register_translatable_strings()`), under the same Polylang group and
  WPML context, so location names and area labels are translatable like the other fields.
- Existing fields and their meta keys are untouched (D7).

### Shortcode cache lifecycle

The shortcode's type-lookup cache and both clearing paths (automatic on save, manual button)
cover the new type and the new fields; unpublishing, retyping or reordering any contact
clears the type lookups for all three types; a cached contact ID is not used when the contact
is no longer published. The schema output itself does not use these caches.

### Languages (D9)

Every text value comes through `PostType::get_translated_field()`, the module's existing
Polylang/WPML path (spec `2026-10-21-contact-infos-polylang-strings-design.md`): Polylang
`pll__()`, WPML `wpml_translate_single_string`. WPML remains documentation-built and
stub-tested only (that spec's D12); this feature inherits that limit and says so in the build
record.

### Per-page custom field (D5, AC-9, AC-10)

- **Who:** a user may see and save it only with `manage_options` **and** `unfiltered_html`
  **and** `edit_post` for that post. (`unfiltered_html` alone would admit single-site Editors;
  on multisite only super admins hold it — accepted.)
- **Where:** a meta box on public post types that have an edit UI. Stored under a protected
  (underscore, module-prefixed) meta key, not registered for REST, so neither REST nor the
  Custom Fields box can write it.
- **Save:** nonce + the three capabilities. The field is processed only when its nonce is in
  the request; a save without it (another user, Quick Edit, REST, a plugin) leaves the stored
  value untouched.
  - empty submission (whitespace only) → meta deleted;
  - valid JSON object, or array whose items are all objects, ≤ 64 KB → stored as submitted;
  - anything else → not stored, old value kept, admin notice.
- **Output** (feature on, every mode except Off, including conflict and extension modes — it
  is the administrator's explicit content and its duplicates are theirs): only on singular
  front-end requests, for the queried object (`get_queried_object_id()`), when that post is
  `publish`ed and `post_password_required()` is false. Printed as the decoded value
  re-encoded with `wp_json_encode()` using
  `JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` in its own
  `<script type="application/ld+json">`; if encoding fails, nothing is printed. Raw stored
  text is never echoed.

### Output encoding (security boundary)

Contact values are editor-controlled (the contact type maps to `edit_others_posts`). Own output
and the per-page field are printed only as `wp_json_encode()` output with `JSON_HEX_TAG |
JSON_HEX_AMP` (so `</script>` and `<!--` cannot survive); encoding failure prints nothing. In
extension mode the plugin prints: the theme hands it plain strings (tags stripped,
`wp_strip_all_tags`) and arrays only. Rank Math 1.0.282 encodes with `JSON_UNESCAPED_SLASHES`
after `wp_kses_post_deep`, without `JSON_HEX_TAG`, so stripping tags before handing values over
is required, and the abuse path below proves the script boundary in both plugin modes.

### Fail-safe in extension mode (AC-5)

The filter callbacks return their input unchanged when it is not an array of the expected
shape, and catch any `Throwable` and return the input unchanged. A plugin that stops calling
the filter simply gets no additions.

### Settings and purge

- Settings screen under the Contact Infos menu, `manage_options` + nonce (invariant 2),
  `sfxtheme` strings (invariant 4), layout per `DESIGN.md`. Holds the switch, the type (C4),
  the organization choice and exclusions (C5), the mode with reason, incomplete entities and
  the C1/C2 notes.
- Option `sfx_contact_infos_schema` (switch, type, "chosen once") added to `DataPurge`'s option list.
- **Purge (AC-12):** the default purge (`DataPurge::run(false)`) removes the option, the
  per-page meta key and every new contact meta key (area label, opening hours, organization
  mark, exclusion mark) through a new, unconditional schema-meta list, separate from the
  optional Media Credits `META_KEYS`. Existing contact meta and contact posts are untouched, as
  today. The purge page's description names the new data.

## Verification (evidence mode battery+check+verification+abuse-path)

- **Battery:** `./quality.sh` green.
- **Check (unit tests, `tests/*-test.php`, stubs), each failing without the feature:**
  - mode selection for every row above, including conflict when two of Yoast/Rank Math/TSF are
    active and when an "other" plugin is active beside Yoast;
  - published structure for a fixture of one organization, two locations with hours, two
    contact points, social accounts; empty values omitted; incomplete entities left out per
    "Eligibility"; a formatted-address-only contact gets no address, a formatted-only location is left out, and own LocalBusiness downgrades to Organization;
  - selection: marked organization unpublished/retyped → no output; two marked (merge import
    fixture) → no output; marked contact permanently deleted with "chosen once" set and another
    published `main` present → no output; never configured → first `main`; saving a choice
    leaves exactly one mark; organization not excludable; other `main` contacts not published;
  - Yoast and Rank Math callbacks: merge per C1 with deliberately conflicting plugin values;
    `@id`/`@type`/`url`/`logo` unchanged; Rank Math place address synced; person mode in
    scalar `Person` and both array orders → unchanged; missing publisher, non-array input and
    a throwing data source → input returned unchanged;
  - opening hours: three states, overnight range, overlap rejected, closed-day form;
  - per-page field: capability matrix (administrator / editor with `unfiltered_html` / author),
    clear, untouched-save, invalid-replacement, 64 KB cap, encoding of
    `</script><script>alert(1)</script>` and `<!--`, password-protected and draft posts print
    nothing;
  - `_contact_type` allowlist keeps `main`/`branch` and accepts the new value; shortcode type
    cache cleared on retype/unpublish;
  - purge: default purge removes the option, per-page key and new contact keys, keeps existing
    contact meta and Media Credits meta.
- **Named verification (DDEV test site, WordPress 7.1.4):** fetch the same page per setup and
  record the result in the PR:
  - no SEO plugin → one theme organization entry with the fixture data, valid JSON;
  - Yoast free (company) and Rank Math free (company) → the plugin's single organization entry
    carrying the fixture data; Rank Math's place shows the same address;
  - TSF with organization output **off** → theme organization entry with fixture data;
    TSF with it **on** → TSF's output unchanged, no theme company data, settings page shows
    the guidance;
  - Polylang second language → translated values, including a location name and an area
    label;
  - Schema.org validator and Google Rich Results Test on one sample per mode without errors
    (AC-11), recorded with date.
- **Abuse path:** as an editor with `unfiltered_html` (single-site default): no per-page meta
  box; a forged POST with a valid-looking body and a REST write of the meta key leave it
  unchanged. A contact value (company, location name, area label, and a Polylang translation)
  containing `</script><script>alert(1)</script>` and `<!--`: own output shows it hex-escaped;
  in Yoast and Rank Math mode the page's script elements stay intact and their JSON valid.
- **Release check (AC-6, C7):** `tests/support/` harness that activates each supported SEO
  plugin on the test site in turn, fetches a page, and reports per plugin one of
  "delivered", "deliberately suppressed (expected for this setup)" or "not delivered";
  restores the plugins' activation state from one teardown declared before the first change
  (`AGENTS.md` Don'ts, harness teardown). `README.md`'s release section gains the line to run
  it.

## Sources and impact boundary

- Read: `inc/ContactInfos/{Controller,PostType,FieldRegistry}.php`,
  `inc/ContactInfos/Shortcode/SC_ContactInfos.php`,
  `inc/SocialMediaAccounts/{PostType,FieldRegistry}.php`, `inc/DataPurge.php`,
  `inc/ImportExport/Controller.php` (export list and contact export, which copies all post
  meta), the 2026-10-21 Polylang spec, `AGENTS.md`, `.claude/review-gates.md`,
  `.claude/living-docs.md`.
- Third-party APIs, per pinned version:
  - Yoast SEO 28.9 — Context7 `/yoast/developer` (`wpseo_schema_organization`, signature
    `$data, $context`) and plugin source (`src/generators/schema/organization.php`,
    `src/generators/schema-generator.php`, `wp-seo-main.php`).
  - Rank Math 1.0.282 — Context7 `/websites/rankmath_kb` (`rank_math/json_ld`, `$data,
    $jsonld`) and plugin source (`includes/modules/schema/snippets/class-publisher.php`,
    `includes/modules/local-seo/class-local-seo.php`, `includes/modules/schema/class-jsonld.php`,
    `includes/helpers/class-conditional.php`, `includes/module/class-manager.php`).
  - The SEO Framework 5.2.1 — Context7 `/sybrew/the-seo-framework` (schema filters; it does not
    document `knowledge_output`, so the plugin source is the material for that:
    `autodescription.php`, `bootstrap/define.php`, `inc/classes/data/plugin/setup.class.php`,
    `inc/classes/meta/schema/entities/{organization,website,webpage}.class.php`).
  - SEOPress 10.4, All in One SEO 5.0.5.1, Schema & Structured Data for WP & AMP 1.71 — only
    their version constants, read from plugin source (not in Context7 for this purpose).
  - WordPress core 7.1.4 (test site) — Context7 `/websites/developer_wordpress_reference_functions`,
    looked up 2026-11-20 for each API this spec commits to: `wp_json_encode` (flags passed to
    `json_encode`, returns `false` on failure), `post_password_required`, `register_post_meta`
    / `register_meta` (`show_in_rest` defaults to false; without `auth_callback` a protected
    key falls back to `__return_false`), `delete_post_meta_by_key` (deletes the key across all
    posts), `current_user_can` (meta cap `edit_post` with post ID), `get_queried_object_id`,
    `wp_strip_all_tags` (removes `script`/`style` with their contents, then `strip_tags`).
    Context7 documents the current reference, not a 7.1.4-pinned one; none of these
    signatures differs in the test site's `wp-includes` (the builder confirms at use).
  - Bricks 2.4.5 (test site) emits only accordion FAQ schema; not otherwise used.
- Impact: `inc/ContactInfos/*` (new classes for schema output, settings screen, per-page meta
  box; meta-box, validation and cache changes in `PostType.php` and `SC_ContactInfos.php`),
  `inc/DataPurge.php`, the purge description on its admin page, `AGENTS.md` (edge list, module
  summary), `README.md`, `CHANGELOG.md`, `tests/`. Not touched: Bricks parent, `wp-includes`,
  `inc/ImportExport/`, other modules' code.
- Actions outside the repository needing Daniel: none. Installing Yoast, Rank Math, The SEO
  Framework and Polylang on the local DDEV test site is local and reversible.

## Left to the builder

Class split inside `inc/ContactInfos/`, settings-screen layout (per `DESIGN.md`), the
opening-hours input UI, the exact Rank Math priority between 9 and 99, the meta key names
(module prefix), and how the harness switches plugins.
