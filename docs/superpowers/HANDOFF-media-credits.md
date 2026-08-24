# Handoff — Media Credits

**Date:** 2026-08-24 · **Branch:** `feature/image-credit-fields` · **Status:** design and plan complete, **no implementation yet**

## What this is

A new optional theme module, `inc/MediaCredits/`, adding two fields to every media attachment — a free-text **copyright notice** and an **AI/alteration marking** from a closed five-value list — plus the plumbing to render them from a Bricks Image element, and an uploadable seal image per AI label.

## Read these, in this order

1. `docs/superpowers/specs/2026-08-24-media-credits-design.md` — the design, with source citations for every Bricks and WordPress mechanism it depends on.
2. `docs/superpowers/plans/2026-08-24-media-credits.md` — 12 tasks, TDD, every test and implementation written out.

Do not re-derive the design. Several obvious-looking approaches were tried and rejected for reasons the spec records; the most important is that a dynamic tag placed in a Bricks image element's *Custom caption* is **never resolved by that element**, so the module rewrites `captionCustom` at `bricks/element/settings` instead.

## Where it stands

| | |
|---|---|
| Spec | Written, four Codex Gate A passes, all findings acted on |
| Plan | Written, one Gate A pass, all findings acted on |
| Code | **None written** |
| Tests | **None written** — all three files are specified in the plan |
| Commits on the branch | `56491f3` … `bc78ea5`, documentation only |

## Next step

Execute the plan **subagent-driven**: one fresh subagent per task, review between tasks. Start with Task 1.

Suggested opening prompt for a fresh session:

> Execute `docs/superpowers/plans/2026-08-24-media-credits.md` subagent-driven, starting at Task 1. Read `docs/superpowers/specs/2026-08-24-media-credits-design.md` first.

## The four things a shortcut would break

1. **Escaping boundary.** Every string this module puts into page content passes `Credit::escape_braces()` as its *final* operation — after the `sfx_media_credits_line` filter, on tag values too. Bricks parses the finished document for `{tags}` (frontend.php:947), and the copyright field is free text typed by anyone who can upload media. Without this, `{echo:some_function}` in a copyright notice executes.
2. **IPTC one-shot.** Two independent guards: `$context === 'create'` (WordPress passes `'update'` on regeneration, image.php:185 vs :750) **and** the `_sfx_media_iptc_prefilled` marker. Dropping either lets a site-wide thumbnail regeneration write copyright into hundreds of attachments, or resurrect a value an editor deliberately cleared.
3. **Order inside the settings filter.** Substitute tags → compute the *effective* caption the way Bricks computes it (`empty()` then `trim()`, image.php:805-806) → only then auto-output, and only if that effective caption carries no marker. Testing the raw `captionCustom` instead lets a marker in a caption Bricks will not render suppress the disclosure entirely.
4. **No `handle_*()` in `GeneralThemeOptions\Controller`.** Switching the feature off must not delete anything. Four other modules have such a handler; this one deliberately does not.

## Open, deliberately not in this feature

- **`uninstall.php` never runs for a theme.** A plugin-only convention (`wp-admin/includes/plugin.php:1284`). The theme's entire delete-on-uninstall path — every option and the Text Snippets purge — is inert today. Pre-existing, raised separately, and the plan still adds its entries so the file is correct the day it is fixed.
- A fifth Gate A pass on the spec was skipped by choice after pass 4; the three edits made in that pass are unreviewed (commit `b986566`).
- Out of scope by decision, with reasons in the spec: page-cache invalidation, multisite, per-`<source>` credits on responsive images, overlay output in the builder's single-element re-render.
