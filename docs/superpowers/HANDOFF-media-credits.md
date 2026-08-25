# Handoff — Media Credits

**Date:** 2026-08-25 · **Branch:** `feature/image-credit-fields` @ `3f2a5ff` · **Status:** implemented, reviewed, live-verified, **open as PR #25**

## Where this stands

Two bodies of work, both finished, both on one branch, 40 commits:

1. **The module** — `inc/MediaCredits/`. A copyright notice and an AI/alteration marking on every media attachment, rendered from a Bricks Image element. Off by default.
2. **The hook surface** — twelve extension points on top of it, documented on the settings page.

**PR #25** — https://github.com/dsnger/sfx-bricks-child/pull/25 — is open and **not merged**. Daniel finishes branches with a PR and stops there; do not offer or take the merge path.

Working tree clean, 18/18 test suites green, no warnings or deprecations.

## The next step

**CodeRabbit and Greptile have both already commented on PR #25.** Nothing has been done with either. That is the work to pick up.

Use `dev-workflow:process-pr-review` — it validates bot claims per CLAUDE.md §5 rather than implementing them blindly. A meaningful minority of bot findings on this repo have been wrong before; verify each against the source before acting.

## Read these, in this order

1. `docs/superpowers/specs/2026-08-24-media-credits-design.md` — the module's design, with source citations for every Bricks and WordPress mechanism it rests on.
2. `docs/superpowers/specs/2026-08-24-media-credits-hooks-addendum.md` — the hook surface. **Revised three times by independent review**; passes 2 and 3 each found contradictions the previous pass's own fixes had introduced. Its rules are hard-won, not preferences.
3. `.superpowers/sdd/2026-08-24-media-credits/progress.md` and `.superpowers/sdd/2026-08-25-media-credits-hooks/progress.md` — the two execution ledgers. Every ruling, every deferred finding (32 of them), and the live-verification results. Git-ignored, so they will not appear in the PR.

Do not re-derive the design. Several obvious-looking approaches were tried and rejected for reasons recorded there.

## The four things a shortcut would break

1. **The escaping boundary is per sink, not per gate.** Every string the module puts into page content passes `Credit::escape_braces()` as its *final* operation, because Bricks parses the finished document for `{tags}` and the copyright field is free text typed by anyone who can upload media. `Credit::for()` returns raw `$parts` alongside the gated `line`, and **four** consumers read them, each independently gated. Asserting the line and inferring the rest is the reasoning an independent review called a blocker.
2. **The IPTC prefill has two independent guards** — `$context === 'create'` *and* the `_sfx_media_iptc_prefilled` marker. Dropping either lets a site-wide thumbnail regeneration write hundreds of attachments, or resurrects a value an editor deliberately cleared.
3. **No `handle_*()` in `GeneralThemeOptions\Controller`.** Four sibling modules have one that deletes their options on toggle-off. This one must not — switching the feature off must not destroy a site's copyright records.
4. **`should_auto_output` is one decision per element, memoised in an `SplObjectStorage` keyed by the object instance.** Never key on `$element->id`: Bricks builds a fresh instance per render but `$uid` comes from the stored definition, so a query loop presents the same id for every post and the first post's decision would freeze for all the others.

## Verified live, against real WordPress and real Bricks

Not only stubs. On a real Bricks page: a copyright of `{echo:phpinfo}` renders as literal text and survives Bricks' own `Providers::render_content()` byte-identical. Caption dedup, caption preservation, the `no-credit` opt-out keeping `data-sfx-ai`, overlay root-tag behaviour, `force_wrapper`, and the seal all behave as designed.

## Open items

- **PR bot reviews** — CodeRabbit and Greptile, both unprocessed. The next task.
- **A finding that is NOT ours, but affects Daniel.** Every `figcaption` on the local site computes to `display: none`, including one the editor set that the module never touched. Disabling the module's stylesheet does not restore them. So on that install, caption mode writes the credit correctly but nothing is visible — while the settings page recommends caption mode as the reliable one. A theme/Bricks caption-visibility matter for Daniel to decide on.
- **Manual steps still not run**, and reported as not run rather than passed: the IPTC thumbnail-regeneration check (needs an IPTC-bearing upload and a regeneration plugin) and the settings **import** (deliberately skipped — it writes options wholesale and could clobber local config).
- **32 deferred minor findings** across the two ledgers, each triaged as non-blocking by a whole-branch review. One worth naming: no test covers a filter whose return is entirely consumed by `wp_kses_post()`, i.e. the post-kses empty-fallback branch of `Bricks::finish_fragment()` — correct by reading, unguarded by any test, and it sits in the security-critical helper.
- **One-line cosmetic:** the `overlay_skip_tags` entry on the hook card runs to four sentences without a `<br>`, where every other entry is one.

## The local test instance

- Test page **#765**, `https://sfx-bricks-child.local/mc-testseite/` — eight labelled image cases A–H. Delete when done; it is titled "MC Testseite (Media Credits – zum Löschen)".
- Seeded attachment meta: `#666` "Foto Müller" + KI-generiert · `#665` "Agentur Nord" + Digital verändert · `#591` `{echo:phpinfo}` + KI-bearbeitet · `#579` no credit.
- Current settings: `output_mode=overlay`, `force_wrapper=1`, `credit_display=icon_text`, `seal_ai_generated=591`. The feature toggle is **on**.

**Environment notes that cost time to discover:**

- MAMP, not Local. Run PHP with `/Applications/MAMP/bin/php/php8.5.2/bin/php` plus `require wp-load.php`. WP-CLI fails — the system PHP does not know MAMP's socket at `/Applications/MAMP/tmp/mysql/mysql.sock`.
- **Bricks blocks direct writes to `_bricks_page_content_2` without an authenticated capable user** (`Bricks\Ajax::update_bricks_postmeta`). From CLI you must `wp_set_current_user()` on an administrator first. Not a defect; a sensible guard.
- **Do not trust in-page CSS rule enumeration.** Walking `document.styleSheets` failed twice here to find rules that demonstrably applied. Disabling a stylesheet and re-measuring is reliable; enumerating `cssRules` is not.
- Reading files outside the child theme — the Bricks parent theme, `wp-includes/` — triggers permission prompts. Keep subagents inside the theme and hand them the facts they need.
