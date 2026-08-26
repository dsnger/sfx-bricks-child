# Handoff — Media Credits

**Date:** 2026-08-26 · **Status:** shipped in **v0.20.0**, merged to `main`, released

## Where this stands

Media Credits is done. PR #25 merged (`67534d4`), released as v0.20.0 with notes and a
zip asset. 18/18 test suites green on `main`, working tree clean.

Two bodies of work, both in: the module (`inc/MediaCredits/`) and its twelve-hook
extension surface, documented on the settings page.

**There is no pending task in this module.** What follows is what a next session needs in
order not to break it, and the things that were deliberately left undone.

### The one thing still worth acting on

Every `figcaption` on Daniel's local install computes to `display: none` — including one
the editor set that this module never touched, and disabling the module's stylesheet does
not restore them. So **caption mode, which the settings page recommends as the reliable
one, writes the credit correctly and shows nothing on that install.**

Not this module's bug: it is a theme or Bricks caption-visibility matter, and it is
unresolved. It is listed here first because it is the only item that changes what a user
sees, and because it was previously buried far enough down the page to be missed.

## The four things a shortcut would break

1. **The escaping boundary is per sink, not per gate.** Every string the module puts into
   page content passes `Credit::escape_braces()` as its *final* operation, because Bricks
   parses the finished document for `{tags}` and the copyright field is free text typed by
   anyone who can upload media. `Credit::for()` returns raw `$parts` alongside the gated
   `line`, and **four** consumers read them, each independently gated. Asserting the line
   and inferring the rest is the reasoning an independent review called a blocker.
2. **The IPTC prefill has two independent guards** — `$context === 'create'` *and* the
   `_sfx_media_iptc_prefilled` marker. Dropping either lets a site-wide thumbnail
   regeneration write hundreds of attachments, or resurrects a value an editor cleared.
3. **No `handle_*()` in `GeneralThemeOptions\Controller`.** Four sibling modules have one
   that deletes their options on toggle-off. This one must not — switching the feature off
   must not destroy a site's copyright records.
4. **`should_auto_output` is one decision per element, memoised in an `SplObjectStorage`
   keyed by the object instance.** Never key on `$element->id`: Bricks builds a fresh
   instance per render but `$uid` comes from the stored definition, so a query loop
   presents the same id for every post and the first post's decision would freeze for all
   the others.

Do not re-derive the design. Several obvious-looking approaches were tried and rejected;
the reasoning is in `docs/superpowers/specs/2026-08-24-media-credits-design.md` and the
hooks addendum beside it, which survived seven independent Codex Gate A passes.

## Verified, and not

**Verified live** against real WordPress and real Bricks: a copyright of `{echo:phpinfo}`
renders as literal text and survives Bricks' own `Providers::render_content()`
byte-identical. Caption dedup, caption preservation, the `no-credit` opt-out keeping
`data-sfx-ai`, overlay root-tag behaviour, `force_wrapper` and the seal all behave as
designed, checked in the browser.

**Never run, and reported as not run:** the IPTC thumbnail-regeneration check (needs an
IPTC-bearing upload plus a regeneration plugin) and the settings **import** (skipped on
purpose — it writes options wholesale and could clobber local config).

## Open, deliberately

- **`uninstall.php` is gone**, and with it the `delete_on_uninstall` switch. Resolved in
  v0.21.0, not still open: WordPress never loads a theme's `uninstall.php`, so the switch
  promised something with no moment to happen in. A Danger Zone on General Theme Options
  now purges on demand while the theme is active, `SFX\DataPurge` holds the list, and the
  Media Credits attachment meta goes only when explicitly ticked — it is editor-typed
  content. See `docs/superpowers/specs/2026-08-25-theme-data-purge-design.md`.
- **Eight modules pass `manage_options` to `add_submenu_page()`** while `AccessControl`
  also admits a role-based `SFX_THEME_ADMINS` user who lacks it, so such a user is
  authorised and then has the entry hidden. The full reasoning, including why the obvious
  fix is a security hole, is at `inc/MediaCredits/AdminPage.php:44-56`. **Read that before
  touching it** — the `option_page_capability_*` filter route was tried and reverted
  because it hands the caller WordPress' global "All Settings" screen on a bare GET. The
  real fix is moving off `options.php` onto a nonce-checked handler, the way
  `PasswordProtected` already did. Theme-wide question, not a Media Credits one. Inert on
  Daniel's installs, which all set `SFX_THEME_ADMINS` to `manage_options`.
- **`CustomDashboard` and `CustomScriptsManager` gate registration but not render.**
  Defence-in-depth missing, not an open hole — an unregistered page has no callback.
- **14 GitHub Releases below v0.14.0 still have empty bodies.** No changelog section
  exists for them; filling those would mean inventing history.

## The local test instance — keep it

Test page **#765**, `https://sfx-bricks-child.local/mc-testseite/`, eight labelled image
cases A–H, plus seeded attachment meta on `#666` "Foto Müller" + AI-generated · `#665`
"Agentur Nord" + Digitally altered · `#591` `{echo:phpinfo}` + AI-edited · `#579` no
credit. **Deliberately kept** (decided 2026-08-25): local-only instance, and the fixtures
are useful for the next change here. Do not offer to delete them.

Current settings there: `output_mode=overlay`, `force_wrapper=1`,
`credit_display=icon_text`, `seal_ai_generated=591`, feature toggle **on**.

## Environment notes that cost time to discover

- **MAMP, not Local.** Run PHP with `/Applications/MAMP/bin/php/php8.5.2/bin/php` plus
  `require wp-load.php`. WP-CLI fails — the system PHP does not know MAMP's socket at
  `/Applications/MAMP/tmp/mysql/mysql.sock`. Run the suite with
  `for f in tests/*-test.php; do php "$f"; done`; only 8.3+ is installed locally, so PHP
  8.0–8.2 compatibility can be reasoned about but not linted.
- **Bricks blocks direct writes to `_bricks_page_content_2`** without an authenticated
  capable user (`Bricks\Ajax::update_bricks_postmeta`). From CLI you must
  `wp_set_current_user()` an administrator first. Not a defect; a sensible guard.
- **Do not trust in-page CSS rule enumeration.** Walking `document.styleSheets` failed
  twice here to find rules that demonstrably applied. Disable a stylesheet and re-measure.
- **Stay inside `sfx-bricks-child.local`.** The session may list other paths as working
  directories — `wp-branicks-theme` under `WEBSITES_ONLINE` is one, and it is an unrelated
  client project. Do not read or search it. Scope every grep: an unscoped one reaches every
  declared directory and returns matches that read like evidence. Inside the site root,
  `wp-includes/` and the Bricks parent theme are fair game and worth reading — two findings
  here turned on checking a claim against core rather than trusting a summary of it.
- **A subagent's finding is a claim to verify, not a fact to relay.** One reported files in
  the branicks directory that do not exist there, and it was repeated onward unchecked.
  Same rule as for the PR bots.

## Bot and release tooling — what to expect

- **Greptile edits its existing PR comment in place** on a re-review; it does not post a
  new one. Polling for a *new* comment times out while the review is already done. Read it
  through the Greptile MCP (`list_code_reviews` / `get_code_review`) instead, and trigger
  a re-review with `trigger_code_review` — no push needed.
- **A green CodeRabbit check is not proof it reviewed.** With the quota spent it reports
  `pass` with the reason *"Review rate limited"* and no review ran. Read the reason.
- **Its merge-risk badge lags.** It stayed at 🟡 Moderate for a commit after CodeRabbit
  had itself retracted two of the three items, in a comment.
- **`./release.sh <version> "<notes>"` does the whole release**, and has had two gaps:
  - Its changelog extractor was broken from the start and published an empty body for
    every release from v0.19.1 to v0.20.0. Fixed in `1547cd2`; it now aborts rather than
    publish a blank one. Check the body is non-empty, not just that the release exists.
  - **It does not push the release commit.** With v0.21.0 the tag and the GitHub Release
    were out while `origin/main` still advertised the old version. After every release run
    `git rev-list --count origin/main..main` and push if it is not 0.
