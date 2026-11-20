# Structured data (schema) from contact infos — Story

**Date:** 2026-11-20 · **Size:** story · **Checkpoints:** none
**Risk:** standard · **Security:** high · **Validation:** battery+check+verification+abuse-path

## 1. Problem statement

Client sites keep their company data — company name, address, phone, email, contacts for
different areas ("Ansprechpartner für verschiedene Bereiche", e.g. the Visit Essen site) —
in the theme's Contact Infos, and their profiles in Social Media Accounts. Search engines
read none of it as structured data (JSON-LD).

Almost every client site runs an SEO plugin, mostly Yoast or Rank Math, sometimes The SEO
Framework. Their free versions, which these sites use, publish only a thin organization
entry (name, logo, social profiles); address, phone, several locations and contact points
are paid features (Yoast Premium, Rank Math Pro, SEOPress Pro — per the vendors' own
pages, not tested). Where a plugin publishes anything, the same data is typed in a second
time by hand and drifts from the Contact Infos. Bricks 2.4.5 itself only offers FAQ schema
on the accordion element.

Discussion with Daniel on 2026-11-20 settled the scope: no full schema builder (plugins do
that), only the company data the theme already holds, plus one checked per-page field.

## 2. Desired outcome

A site administrator switches the feature on once, and every page then carries complete,
valid organization data — name, address, phone, email, logo, social profiles, locations
and contact points per area — taken from the data already kept in Contact Infos and Social
Media Accounts, so it is maintained in one place only. This works whichever of the usual
setups the site has:

- **no SEO plugin:** the theme publishes the organization entry itself;
- **Yoast or Rank Math (free):** the theme adds its data to the plugin's own organization
  entry, so there is still exactly one;
- **The SEO Framework:** the admin switches off that plugin's organization output, and the
  theme publishes the full entry instead;
- **any other SEO or schema plugin:** the theme stays out and says why.

Why: complete company data without a Pro licence per client, kept consistent with what the
pages show, and a concrete selling point for the theme.

Additionally, an administrator can add custom structured data to a single page.

## 3. Acceptance criteria
_IDs are permanent once the story is committed: never renumber or reuse one; a new criterion takes the next number unused here and on the branch it merges into, and a collision stops for a human; a removed one stays, struck through and dated; a narrowed one keeps its ID with a dated note; cite as `<story path> AC-<n>`; full rules: dev-workflow:intake, "Acceptance-criterion IDs"._
- [ ] **AC-1** The feature is off on a fresh install and after a theme update; while it is off, the theme adds no structured data to any page.
- [ ] **AC-2** With the feature on and no SEO plugin active, every public page carries one organization entry whose name, address, phone, email, logo, social profiles and contact points match the Contact Infos and Social Media Accounts data shown in wp-admin.
- [ ] **AC-3** An administrator decides which Contact Infos entries appear and in which role — the organization itself, one of its locations, or a contact point for a named area — so a site like Visit Essen with several contacts for different areas is represented completely.
- [ ] **AC-4** With Yoast SEO (free) or Rank Math (free) active and the feature on, a page carries exactly one organization entry — the plugin's — and it contains the Contact Infos data from AC-2; no second organization entry appears.
- [ ] **AC-5** If the extension cannot attach to Yoast's or Rank Math's output (for example after a plugin update changed it), the page still loads and the plugin's own structured data is unchanged and valid.
- [ ] **AC-6** A check on the test site, run as part of every release, shows for each supported SEO plugin whether the theme's data actually arrives in the published page.
- [ ] **AC-7** With The SEO Framework active: while its organization output is on, the theme publishes no organization entry and the settings page tells the admin where to switch it off; once it is off, the theme publishes the entry from AC-2.
- [ ] **AC-8** With any other known SEO or schema plugin active (at least SEOPress, All in One SEO, Schema & Structured Data for WP), the theme publishes no organization entry and its settings page names the plugin found.
- [ ] **AC-9** A per-page field for custom structured data can be seen and saved only by users allowed to insert unfiltered code (administrators); others neither see it nor can write it by any route, including REST.
- [ ] **AC-10** Content saved in the per-page field that is not valid JSON is rejected with a message, and no saved content can end the script element or inject markup into the page.
- [ ] **AC-11** For each setup in AC-2, AC-4 and AC-7, a sample page's output passes the Schema.org validator and Google's Rich Results Test without errors.
- [ ] **AC-12** Every setting and stored value the feature adds is removed by the theme's data purge; whether it is exported is decided and recorded per value.

## 4. Affected AGENTS.md invariants
- `## Architecture` — "Beyond those, three groups of feature-module coupling exist and are deliberate — the bootstrap dependency plus eight module-to-module edges: … Anything beyond those is new coupling — don't add it." (Reading Contact Infos and Social Media Accounts data from a module that does not own it would be new coupling.)
- `## Key invariants` — "2. **Every admin settings write checks a capability AND a nonce.**"
- `## Key invariants` — "3. **All output is escaped at the point of output**, never at assignment."
- `## Key invariants` — "4. **Every NEW user-facing string uses the `sfxtheme` text domain.**"
- `## Key invariants` — "5. **The theme loads its classes without `vendor/`, and a release ships only `SHIPPED`.**"
- `## Don'ts` — "**Don't add a runtime dependency.**"
- `## Don'ts` — "**Don't edit the Bricks parent theme or `wp-includes/`.** This is a child theme; parent changes are lost on update. Extend via hooks and filters."
- `## Don'ts` — "**Don't write option keys without the module's prefix, and don't stop there.** … A new key must carry the prefix *and* be added to `DataPurge`, or it is never purged. Export is a separate, deliberate decision per key"
- `## Don'ts` — "**Don't bypass the module convention.**"

## 5. Open questions
- **Coupling (needs Daniel's ruling):** using Contact Infos and Social Media Accounts data from the schema feature adds module-to-module coupling the Architecture section forbids without an owner ruling. Is that coupling approved, or must the feature live where it creates none?
- **Opening hours:** Contact Infos holds them as free text; structured data needs days and times. Leave them out of v1, or add a structured field?
- **Languages:** Contact Infos are translated through Polylang string translations. Must the structured data follow the page language?
- **Rank Math free:** whether its extension point acts on the free version's organization output is known only from support posts; to be confirmed on the test site before it is promised.
- **The SEO Framework:** whether its pages still reference its own organization entry after that output is switched off is unverified (read from plugin code 5.2.1, not run).

## 6. Suggested size
story — one coherent feature (company data as structured data across the usual SEO-plugin setups, plus one per-page field); the per-page field is independent and could move to its own story if the spec grows too large.
