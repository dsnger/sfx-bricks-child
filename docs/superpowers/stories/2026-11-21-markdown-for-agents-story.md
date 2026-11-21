# Markdown versions of pages for AI agents — Story

**Date:** 2026-11-21 · **Size:** story · **Checkpoints:** none
**Risk:** standard · **Security:** high · **Validation:** battery+check+verification+abuse-path

## 1. Problem statement

AI tools read web pages more cheaply and reliably as Markdown than as HTML. Client sites are
built with Bricks, and the two WordPress plugins Daniel looked at do not serve them well
(tested on the DDEV demo site, 2026-11-21, Bricks 2.4.5):

- "Markdown for Agents and Statistics" 1.7.4 pre-generates files but reads the WordPress
  content field, which Bricks pages leave empty: the front page's file held only its
  frontmatter (title, dates, URL) and no content.
- "Markdown for AI Agents" 1.0.0 converts the finished page on every request and does capture
  Bricks content (6 KB of Markdown instead of 188 KB of HTML), but leaves raw Bricks button
  HTML, header remnants and context-free labels, and answers only callers that send
  `Accept: text/markdown`.

Several SEO plugins also offer an `llms.txt` (an index for AI tools at the site root): Yoast
writes a real file at the site root, Rank Math serves the address through a rewrite rule,
SEOPress and All in One SEO advertise one (read from their readmes, not their code); The SEO
Framework has none. Daniel's goals: visibility to AI search and a selling point for the theme.

## 2. Desired outcome

A site administrator switches the feature on, and every public page and post built with
Bricks or the block editor has a clean Markdown version of its main content — without site
header and footer — that AI tools can discover and fetch: at a fixed address per page, linked
from the page's head, and when a caller explicitly asks for Markdown. A site-wide `llms.txt`
lists the site's pages for AI tools; where an SEO plugin also provides one, the theme's
version takes priority whenever that is technically possible, and the settings page says how
to switch the plugin's version off where it is not.

Why: AI visibility and a selling point ("works with Bricks") without the gaps of the existing
plugins. Whether AI tools fetch Markdown more than HTML is not established and is not
promised.

## 3. Acceptance criteria
_IDs are permanent once the story is committed: never renumber or reuse one; a new criterion takes the next number unused here and on the branch it merges into, and a collision stops for a human; a removed one stays, struck through and dated; a narrowed one keeps its ID with a dated note; cite as `<story path> AC-<n>`; full rules: dev-workflow:intake, "Acceptance-criterion IDs"._
- [ ] **AC-1** The feature is off on a fresh install and after a theme update; while it is off, no Markdown version, head link, Markdown response or theme `llms.txt` exists.
- [ ] **AC-2** For a published Bricks page (content in Bricks' own field, WordPress content field empty), the Markdown version contains the page's headings, paragraphs, lists, links and images (as Markdown, with alt text) and no site header, footer, navigation, scripts, styles or raw HTML tags.
- [ ] **AC-3** The same holds for a page or post built with the block editor.
- [ ] **AC-4** Each eligible page has a fixed Markdown address, and its HTML head carries a link to it as an alternate `text/markdown` representation; a request to the normal address with `Accept: text/markdown` receives the Markdown version, and any other request receives the normal page unchanged.
- [ ] **AC-5** No Markdown version, address, head link or `llms.txt` entry ever exposes content the normal page would not show the same visitor: drafts, pending, scheduled, private and trashed posts, password-protected posts without the password, and pages restricted by the theme's password protection module all answer as their HTML counterpart would (not found or protected), never with content.
- [ ] **AC-6** After a page is edited and saved, its Markdown version reflects the saved content (no stale version is served); a page that is unpublished or deleted no longer has one.
- [ ] **AC-7** A site-wide `/llms.txt` lists the eligible pages with titles and links to their Markdown versions.
- [ ] **AC-8** With Yoast, Rank Math, SEOPress or All in One SEO active and its own `llms.txt` switched on, the address `/llms.txt` serves the theme's version wherever the theme can take precedence; where it cannot (for example a real file on disk), the settings page names the plugin and tells the administrator exactly how to switch its version off.
- [ ] **AC-9** Responses carry a correct content type and caching headers that keep shared caches from serving Markdown to browsers or HTML to Markdown requests.
- [ ] **AC-10** A settings screen lets an administrator switch the feature on and off and choose which public post types are included; saving checks capability and nonce.
- [ ] **AC-11** Every setting and stored value the feature adds is removed by the theme's data purge; whether it is exported is decided and recorded per value.
- [ ] **AC-12** A live check on the DDEV demo site shows, for a Bricks page and a block-editor page, the Markdown version, the head link, the `Accept` response and the `llms.txt` entry, and for each protected case of AC-5 that nothing leaks.

## 4. Affected AGENTS.md invariants
- `## Key invariants` — "2. **Every admin settings write checks a capability AND a nonce.**"
- `## Key invariants` — "3. **All output is escaped at the point of output**, never at assignment."
- `## Key invariants` — "4. **Every NEW user-facing string uses the `sfxtheme` text domain.**"
- `## Key invariants` — "5. **The theme loads its classes without `vendor/`, and a release ships only `SHIPPED`.**"
- `## Don'ts` — "**Don't add a runtime dependency.**"
- `## Don'ts` — "**Don't read HTML in a check with a hand-written regex or DOMDocument.** Use `SiteCheck\Observations::html_document()` (WordPress' `WP_HTML_Tag_Processor`)"
- `## Don'ts` — "**Don't edit the Bricks parent theme or `wp-includes/`.** … Extend via hooks and filters."
- `## Don'ts` — "**Don't write option keys without the module's prefix, and don't stop there.** … A new key must carry the prefix *and* be added to `DataPurge`"
- `## Don'ts` — "**Don't bypass the module convention.**"

## 5. Open questions
- Which part of a Bricks page is the "main content" when a page uses Bricks templates (header/footer templates vs. content area) — to be settled from Bricks' rendering, not guessed.
- How each SEO plugin's `llms.txt` can be switched off or overridden (Yoast's real file, Rank Math's rewrite rule, SEOPress, AIOSEO) — to be verified in each plugin's code.
- Whether SEOPress's "Agent Readiness" already serves Markdown and would conflict.
- Whether the Markdown version is generated on save and stored, or rendered on request and cached — a design choice with the freshness rule of AC-6 and the protection rule of AC-5 as constraints.

## 6. Suggested size
story — one coherent feature (Markdown versions, discovery, llms.txt, settings); the `llms.txt` part could split off if the spec grows too large.
