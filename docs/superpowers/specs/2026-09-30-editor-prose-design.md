# Editor Prose — Design

**Date:** 2026-09-30 (Gate A: 11 passes; baseline section added after pass 6; passes 6 and 11 without Blocker/Major)
**Branch:** `feature/editor-prose`
**Story:** none — this cycle is unprofiled (no story file exists for it).

**Scope:**

- new `inc/EditorProse/*` (`Controller.php`, `Settings.php`, `AdminPage.php`, `Starter.php`, `assets/editor-prose.js`, `assets/prose.css`)
- one toggle `enable_editor_prose` in `inc/GeneralThemeOptions/Settings.php`
- one entry in `inc/ThemeSettingsOverview/OverviewProvider.php`
- `inc/DataPurge.php`: one option name
- one settings group in `inc/ImportExport/Controller.php`
- German strings in `languages/de_DE.po` / `.mo`
- tests in `tests/`
- README section
- `AGENTS.md`: the ImportExport catalogue count ("eleven other modules") becomes twelve

## Goal

Gutenberg content on Bricks sites is rendered on the frontend inside a Bricks element
(usually Rich Text, `.brxe-text`) carrying a global "prose" class (e.g.
`rich-text-content`). Everything that class defines — typography, colours, headings,
lists, links, quotes, tables, figures and captions — plus Bricks' theme-style spacing
("contextual spacing") applies on the frontend. In the block editor none of it does:
block spacing collapses to 0 and the class rules are missing.

The module makes the editor canvas render like the frontend, configured per site with no
code, so the site snippets (aurantia "Rich-Text: Editor-Styles", visitessen "Magazin:
Editor-Styles") can be removed. Each site's styling stays in its own Bricks class and is
mirrored as-is.

**Principle: one source.** The prose styling lives in exactly one place, the site's Bricks
global class, and applies to every rich-text element and field that carries it. The
module adds no styling of its own to the editor; it only makes the editor's DOM meet the
same conditions the frontend DOM meets (root classes, WordPress' editor-only block
frames and defaults), so the class's rules match the same way. Anything site-specific
— reading widths, alignment widths, component exceptions — belongs in the class, not in
the module.

Optionally, the module also ships a **token-based baseline** (`prose.css`, see
[Baseline](#baseline-prosecss)) so a new site starts with sensible prose styling that
follows its Core Framework / Bricks variables, and that the site's own class overrides.

Non-goals: see [Out of scope](#out-of-scope).

## Approach in one sentence

Give the editor's content root the same classes the frontend wrapper has
(`brxe-text rich-text-content`), and put the prose class CSS — compiled and scoped by
Bricks' own functions — into the editor canvas, so every rule Bricks already sends to the
editor starts matching, and nothing is re-implemented or rewritten.

## Findings (verified 2026-09-30, Bricks 2.4.2, WP 7.1.2)

Code references are under `wp-content/themes/bricks/includes/`.

1. **Bricks already sends its contextual-spacing rules to the editor, but they never
   match.** Bricks scopes its frontend CSS for the editor by prefixing
   `.block-editor-iframe__body` (`integrations/block-editor.php`
   `scope_css_selector_for_gutenberg`). The spacing selectors need a `.brxe-text` /
   `.brxe-post-content:not([data-source=bricks])` ancestor
   (`theme-styles/controls/contextual-spacing.php:50-110`); the editor has none. Live on
   aurantia, the editor contains
   `.block-editor-iframe__body .brxe-text * + :is(h1…h6)` etc., unmatched.
2. **Global class CSS reaches the editor only for classes used by enabled Bricks
   components** (`Block_Editor::generate_gutenberg_global_classes_css`, since 2.3.8),
   plus Style Manager utility classes (`admin.php:2342`). An ordinary prose class
   wrapping native blocks is not included.
3. **Root font size is handled by Bricks** when theme styles load in the editor:
   Bricks' frontend CSS (`frontend-layer.min.css`, layered, or `frontend.min.css` when
   cascade layers are disabled) sets `html{font-size:62.5%}`; the theme style's `html`
   font-size (aurantia `100%`) is scoped to `.block-editor-iframe__html`, unlayered, and
   wins. Measured 16px frontend and editor. Unconditional loading of frontend and
   theme-style CSS into the editor is marked `@since 2.4` (`admin.php:2058`, `2278`) —
   the module therefore requires **Bricks ≥ 2.4**. This also depends on Bricks'
   `disableThemeStylesInBlockEditor` being off (`admin.php:2359`) and, in file-loading
   mode, on the theme-style CSS files existing (`admin.php:2424`) — the same
   prerequisites the rhythm relies on.
4. **Bricks compiles a set of global classes to CSS** — UI settings, breakpoints, class
   chaining (`.cls.brxe-text` unless `disableClassChaining`, `assets.php:4405`), custom
   CSS, Class-Manager order when `globalClassesLoadOrder` is on — via
   `Assets::generate_global_classes($key)` driven by the public static
   `Assets::$global_classes_elements` (`[ class_id => [ element_name, … ] ]`). Bricks
   itself does this for the editor with a save/restore of six static properties
   (`integrations/block-editor.php:726-748`), then `Assets::load_webfonts()` and
   `Block_Editor::scope_css_for_gutenberg()` (`admin.php:2102-2110`).
5. Bricks enqueues its editor-canvas CSS on `enqueue_block_assets`, priority 10
   (`admin.php:19`), gated on `$screen->is_block_editor() && $screen->base === 'post' &&
   $post_id` (`admin.php:2045-2056`).
6. Some class settings with a **dynamic-data** value (e.g. a typography colour) are
   written to the separate `Assets::$inline_css_dynamic_data` bucket
   (`assets.php:4028`), which Bricks' own editor path for component classes also does not
   output. Those are not mirrored (see Out of scope).
7. The editor's content root is rendered by React with
   `className: clsx("is-root-container", className, {is-outline-mode, is-focus-mode,
   is-preview-mode})` (`wp-includes/js/dist/block-editor.js:55038`), so React rewrites
   the class attribute when one of those modes toggles.

### Validated live on aurantia (2026-09-30, read-only, in one browser tab)

With the site snippet's `<style>` removed from the editor tab: all top-level block
margins 0. After adding `brxe-text rich-text-content` to `.is-root-container`: paragraph
margins 20px, identical to the frontend. After additionally injecting the frontend's
`rich-text-content` class rules, prefixed with `.block-editor-iframe__body` as Bricks'
scoper does: margin-top, font-size, line-height and colour of **all 20** top-level
blocks identical to the frontend. Without the prefix, 2 headings differed — the prefix is
load-bearing, because Bricks' editor spacing rules carry it too.

## Decisions settled with Daniel (2026-09-30)

| Question | Answer |
|---|---|
| Root font size | Not handled — Bricks brings the theme style's value (finding 3) |
| Spacing | Bricks' own rules, made to match by the root classes — no values read, no fields |
| Class CSS | Bricks' compiler + Bricks' editor scoper; no fallback to raw `_cssCustom`; requires Bricks ≥ 2.4 |
| How the canvas gets classes and CSS | One small editor script: sets the root classes and injects the CSS into the canvas document only |
| Prose styling shipped by the theme | Optional baseline `prose.css`, off by default, built on the sites' existing token names with literal fallbacks; the site's class always wins |
| Sidebar width, caching | Out |
| Filters | One: `sfx_editor_prose_css` |

## Settings

Option `sfx_editor_prose_options` (array), capability `manage_options`, nonce via the
Settings API (`settings_fields`) like the other modules.

| Key | Type | Default | Sanitize |
|---|---|---|---|
| `classes` | list of Bricks global class names (text field, comma/space separated) | `[]` | each is trimmed, a leading `.` stripped, then must match `/^-?[_a-zA-Z][_a-zA-Z0-9-]*$/D`; invalid entries dropped; duplicates removed |
| `element` | `text` \| `post-content` — the Bricks element that wraps the content on the frontend | `text` | whitelist, else `text` |
| `all_post_types` | bool | `true` | bool |
| `post_types` | list of post type slugs (used only when `all_post_types` is false) | `[]` | keep only registered post types that use the block editor |
| `baseline` | bool — load `prose.css` (frontend + editor) | `false` | coerced like `all_post_types` |
| `title_gap` | CSS value for the gap below the post title | `''` = no rule | trimmed; max 100 chars; rejected (→ `''`) if it contains any of `; { } < > \ ` or `/*` or `url(` |

`all_post_types` is explicit so that dropping unknown post types (import, a post type
removed later) can never widen the selection: with `all_post_types = false` and an empty
list, the editor mirroring applies nowhere (the frontend baseline, if on, is not
post-type-scoped).

The sanitizer is total: a non-array option → defaults; a non-array `classes` /
`post_types` → `[]`, except that a **string** `classes` (the form submits the text field
as-is) is split on `[\s,]+` and then validated token by token; `all_post_types` coerced (`true`/`1`/`'1'` → true, anything else
false); unknown `element` → `text`; non-string `title_gap` → `''`.

**The same sanitizer runs on every read** (`Settings::get()`), not only on save — so an
import, a hand-edited option or a value written while the module was disabled can never
reach the editor unsanitized. ImportExport stays a catalogue and does not call the module (AGENTS.md); an imported value is sanitized on every read, and while the module is active also on write by the Settings API sanitize callback. Import **merge** mode keeps
existing values where the import holds an empty value (`ImportExport/Controller.php`
`deep_merge_arrays`, the behaviour for every module); an exact copy of a site's selection
needs **replace** mode. The README says so.

The module is enabled by `enable_editor_prose` in `sfx_general_options` (default off).

## Mechanism

### Gate

Applies only when all hold, mirroring Bricks (finding 5): `is_admin()`; the current
screen `is_block_editor()` **and** `base === 'post'` (excludes the widgets and site
editor screens); a post ID resolves (`get_the_ID()`, else `filter_input(INPUT_GET,
'post', FILTER_VALIDATE_INT)`); the post's type is eligible (`all_post_types`, or in
`post_types`); Bricks ≥ 2.4 is active (`defined('BRICKS_VERSION') &&
version_compare(BRICKS_VERSION, '2.4', '>=')`); and `classes` is not empty **or**
`baseline` is on.

### Server: build the payload

On `enqueue_block_editor_assets` (admin document only), when the gate holds, enqueue
`inc/EditorProse/assets/editor-prose.js` and pass one config object:

```
{ classes: ['brxe-<element>', ...classes, ('sfx-prose' if baseline)],
  css: '<string>', links: [('<prose.css url>' if baseline), '<font url>', …] }
```

Building `css`:

The build (steps 1–4) runs inside one `try { … } catch (\Throwable) { … }`
(the reset and title steps run after it, outside the try):
any failure — Database lookup, compilation, font extraction, scoping — leaves `css`
holding only the block-margin reset, the editor-frame reset and the title rule, and `classes` still ships.

1. Map configured class names to IDs via `\Bricks\Database::$global_data['globalClasses']`
   (the source `generate_global_classes` reads). Unknown names are skipped; the admin page
   lists them.
2. Save the six `Assets` statics Bricks saves in `generate_gutenberg_global_classes_css`;
   set `Assets::$global_classes_elements = [ id => [ $element ], … ]` for all configured
   classes in **one** call, so Bricks applies its own ordering among them;
   `$css = (string) Assets::generate_global_classes('sfx_editor_prose')` (Bricks returns
   `null` when nothing is mapped); restore in `finally`.
   A used property/method missing → skip to step 5 (the block-margin reset).
3. `links`: the baseline URL (if on), then the `href`s of the `rel="stylesheet"` links in
   `Assets::load_webfonts($css, true)` (Bricks returns link HTML in that mode instead of
   enqueueing; preconnect links are ignored).
4. `$css = Block_Editor::scope_css_for_gutenberg($css)` — Bricks' own prefixing
   (`.block-editor-iframe__body`), which keeps specificity in step with Bricks' editor
   spacing rules (validated above).
5. Append the block-margin reset `html :where(.wp-block) { margin-top: revert-layer; margin-bottom: revert-layer; }`: WordPress' classic editor stylesheet gives every block 28px top/bottom margins (`html :where(.wp-block)`) that the frontend never has. The reset repeats that exact selector (same specificity, injected later), so only that rule is reverted to the layered cascade (Bricks' defaults); block-library rules such as `.wp-block-image`, contextual spacing, prose classes and the title rule still win. Found in the browser verification (2026-09-30); narrowed after Gate B: a broader selector also reverted `.wp-block-image`'s margins.
6. Append the editor-frame reset (`Payload::EDITOR_FRAME_RESET`): Bricks has no
   `theme.json`, so WordPress treats it as a classic theme and wraps every wide/full
   block in an extra `div.wp-block[data-align]` (`block-editor.js`: `isAligned = … &&
   !themeSupportsLayout`), and Bricks component blocks sit in a block wrapper
   `[data-type^="bricks-components/"]` or `[data-type^="bricks-component-ids/"]` (both
   namespaces, `Helpers::get_component_block_name`). Live, the prose wrapper's child is
   the block itself. An **aligned** component block is excluded
   (`:not([data-align], [data-align] > *, .alignwide, .alignfull)`): live, Bricks renders it inside a
   `div.alignwide`/`div.alignfull`, which the editor's block element (with the mirrored
   class) stands for, so it keeps Bricks' alignment sizing. These frames get `max-width: none; width: auto` and zero horizontal
   margin/padding, all `!important` (the prose class's specificity is unknown), so
   neither classic.css (840px, 1100px wide) nor a prose rule aimed at the wrapper's
   children (a reading-width rule) gives them a width. Vertical values stay, so spacing
   between blocks is unchanged. Alignment wrappers are matched with
   `:not([data-block])`, so block elements are never matched as frames (layout themes
   have no frame: WordPress puts `alignwide`/`alignfull` on the block itself, which is
   why the component exclusion also skips those classes); left/right/center wrappers keep classic.css' float handling.
   Found on visitessen (2026-10-01).
7. Append the title rule if `title_gap` is set:
   `.editor-styles-wrapper .editor-post-title { margin-block-end: <title_gap>; }`
8. `$css = apply_filters('sfx_editor_prose_css', $css, $post_type)`.

The payload goes through `wp_add_inline_script(..., 'before')` inside a `<script>`
element of the admin document, encoded with `wp_json_encode($payload, JSON_HEX_TAG |
JSON_HEX_AMP)`: no literal `<` or `>` reaches the element, so neither `</script` nor
`<!--<script` in class CSS can end it or put older HTML parsers into a script-escape
state.

### Client: `editor-prose.js`

One idempotent `sync()`:

- find `iframe[name="editor-canvas"]`; if absent, return. On first sight of an iframe
  element, add its `load` listener (tracked in a `WeakSet` of iframe elements, so once
  per element);
- take its current `contentDocument`; if it is `null` or has no `documentElement`,
  return (the `load` listener retries); if that document is not yet observed (tracked in a
  `WeakSet` of documents), attach one `MutationObserver` to its `documentElement`
  (`childList`, `subtree`, `attributes`, `attributeFilter: ['class', 'data-align']`) calling `sync()` —
  **before** looking for the root, so a root portalled in later is seen;
- if the document has no `.is-root-container` yet, return;
- ensure, in `<head>` and in this order (created once per document): a
  `<style id="sfx-editor-prose">` whose `textContent` (never HTML) is the theme's
  sub-layer order statement `@layer sfx.reset, sfx.utilities, sfx.components,
  sfx.theme;` — the one `assets/css/frontend/styles.css` declares first on the frontend,
  kept as a constant with a test pinning it to that file — followed by `css`; then one
  `<link rel="stylesheet">` per `links` URL. So the layer order matches the frontend.
  Then `ensureClasses(root, classes)`, then `mirrorAlign(root)`: for each
  `.wp-block[data-align]:not([data-block])` frame whose first child has `data-block`,
  that block gets `alignwide`/`alignfull` when the frame says `wide`/`full` — the class
  the block carries live and that the prose class's rules (`figure.alignwide`) match —
  and loses a wide/full class that no longer matches — unless the block's own
  `className` attribute (its "Additional CSS classes", read from the `core/block-editor`
  store by the block's `data-block` client id) contains it, so an author's `alignwide`
  is never removed, whenever it was added. Without the store nothing counts as authored (WordPress keeps frame and
  block across alignment changes, e.g. wide → full or wide → left). React can rewrite the
  block's class attribute and changes only `data-align` on a retained frame; the canvas
  observer watches both and `sync()` restores the state. Writes only when something
  differs, so no observer loop.

Non-iframed editors are not supported — the class CSS and Bricks' own editor rules are
scoped to `.block-editor-iframe__body`, which only the iframe has.

The script is enqueued in the footer (`in_footer: true`), so `document.body` exists.
`sync()` runs once at script start and on each iframe `load`, called directly. Two
`MutationObserver`s trigger it otherwise — one on the admin `document.body` (`childList`,
`subtree`), which sees the canvas mount after a code-editor → visual switch and
device-preview iframe replacement, and one per canvas document. Their callbacks are
coalesced to at most one `sync()` per animation frame via `requestAnimationFrame`
(immediate when it is unavailable). No timeout.

`ensureClasses(element, classes)` only calls `classList.add` for missing classes and
reports whether it changed anything, so the observer's own writes do not loop. It and
the style/link insertion are exported for the Node test.

### Cascade position and limits

The injected `<style>` is appended last in the canvas `<head>`, after Bricks' editor CSS.
Among the configured classes: with Bricks' `globalClassesLoadOrder` on, Class-Manager
order applies, as on the frontend; with it off, Bricks uses encounter order, which here
is the order of the `classes` setting — so list them in the order they appear on the
frontend element (help text says so). This matches the frontend only when those classes
are first encountered on the prose wrapper; Bricks' encounter order is page-wide
(`assets.php:5190`), so if one of them appears earlier on another element of the page,
the frontend order can differ. Accepted limit, documented. Against **other** global classes
Bricks outputs in the editor (component classes, finding 2) the prose CSS always comes
later; equal-specificity conflicts between a prose class and a component class can
therefore resolve differently than on the frontend. Accepted limit, documented in the
README.

Bricks' scoper does not handle every valid construct: after a statement at-rule such as
`@layer x;` the following rule can stay unprefixed as well, and braces inside strings (`content:"}"`) can corrupt the
rule and leave following rules unscoped. The README advises against braces in
`content` strings. Because the CSS lives only inside the canvas
document, anything left unscoped can affect only the canvas, never the admin UI.

### What "parity" covers

Parity holds for prose whose rules depend only on the wrapper element and what is inside
it, plus globals Bricks already supplies to the editor (variables, theme styles, fonts).
It does **not** cover, and the README/help text say so:

- rules depending on frontend ancestors or body classes (`body.single-post .prose p`,
  a variable defined on an enclosing section) — the editor has no such ancestors;
- structural selectors (`:last-child`, `:empty`, sibling spacing) where the editor adds
  its own nodes (block appender, zoom-mode separators) — the editor's DOM is not the
  frontend's;
- relative `url()`s in class CSS — they resolve against the admin URL in the canvas;
- conflicts with **WordPress block CSS**: Bricks' editor prefix adds one class to every
  prose selector, so a prose rule that loses to a core block rule on the frontend (e.g.
  `.prose p` vs the large-quote style's paragraph selector) can win in the editor. This
  is inherent to Bricks' scoper, which the module deliberately reuses; where it matters,
  the prose rule should be as specific as the block rule;
- **theme-style link colours** in Bricks' post-content mode: for posts Bricks renders
  through a WordPress-content template, it maps its Post Content link selector onto
  `.is-root-container` keeping `:not([data-source="bricks"])` (`admin.php:2330`,
  `block-editor.php:1207`), which is more specific than the Rich Text link selector on
  the frontend — a prose-class link rule can then win live and lose in the editor. Give
  prose link rules at least one extra class of specificity where this matters;
- settings on the Bricks wrapper **element itself** (its own style controls, compiled
  to its element ID) — only global-class CSS is mirrored, so prose styling belongs in
  the class;
- selectors on wrapper **attributes** other than class (e.g. `[data-source]`) — only
  classes are reproduced;
- a class reused on **other element types** while Bricks' class chaining is disabled —
  Bricks then emits element-specific rules (e.g. Rich Text's link typography) under the
  bare class on the frontend; the module compiles only for the configured `element`;
- **breakpoints**: Bricks' responsive rules are viewport media queries; the canvas
  iframe's viewport is narrower than the frontend window at the same content width, so
  a rule for a given breakpoint applies in the editor only when the canvas itself is in
  that range (device preview sets it). Parity is per viewport width; percentage spacing
  and container queries additionally need the same wrapper content width.

### Bricks component blocks

No exclusion. On the frontend, component blocks sit inside the prose wrapper too, so
prose rules reach them there as well; mirroring that is parity. The browser verification
compares a component block frontend vs editor. The editor's block wrapper around an
unaligned component took widths the frontend element never gets (visitessen,
2026-10-01); the editor-frame reset (build step 6) makes it width-neutral (an aligned
component's block element stands for Bricks' live `div.alignwide` and keeps its sizing), so the component's own class
decides its width as live.

Remaining structural limit: rules using the child combinator onto a block (`.prose >
figure.alignwide`, `.prose > .brxe-…`) still do not match in the editor, because the
frame stays in the DOM; and a site that clamps components live through a rule on the
wrapper's children sees them unclamped in the editor (that rule now only reaches the
neutral frame). Use descendant selectors in the class where it matters.

### Failure behaviour

Bricks missing or older than 2.4 → editor gate closed, nothing loads in the editor (the
frontend baseline, if on, does not depend on Bricks). A Bricks API missing or
throwing → `css` holds only the block-margin reset, the editor-frame reset and the title rule; classes still ship, so the
theme style's contextual spacing (Bricks' own editor rules) still matches — everything
the prose class itself defines, its own spacing included, is missing until the build
works again. With `WP_DEBUG` on, both a missing Bricks API and a thrown build failure are written to the PHP error log (`[sfx-editor-prose] …`); the editor never shows a notice.

### Trust

Class CSS is Bricks' compiled output of data written by users Bricks' builder permissions
allow (`builder-permissions.php`) — not necessarily administrators. The module adds no
new write path; it places CSS Bricks already outputs on the frontend into the canvas
document only, where it cannot style or read admin UI. CSS can, however, observe the
canvas content (e.g. font requests per `unicode-range`), so **anyone who may write
Bricks global classes must be trusted with the content of every eligible draft** —
the same assumption Bricks already makes when it sends component-class CSS and theme
styles into the editor. The README and help text state this prerequisite. The module's own inputs are
sanitized as above.

## Baseline (`prose.css`)

An optional, token-based starting point, following the pattern of the theme's existing
style modules (`assets/css/frontend/modules/lists.css`): layered, opt-in by class, driven
by variables. Researched on aurantia and visitessen (2026-09-30, read-only): both define
the same token names through Bricks variables (Core Framework scale plus site tokens), and
visitessen's `article__prose` already reads `--caption-*`, `--table-*`, `--quote-*`,
`--bold-font-weight`.

**Trigger and weight.**
- Applies inside an element with the class `sfx-prose` (the `sfx-` prefix avoids
  collisions with a site class named `prose`). On the frontend the site adds `sfx-prose`
  to the Bricks wrapper (e.g. as a class without styles); in the editor the script adds
  it to the root when `baseline` is on.
- Every rule sits in `@layer sfx.components` and uses `:where(.sfx-prose)` (specificity
  0). Unlayered CSS — Bricks theme styles, the site's prose class, Core Framework —
  always wins, without `!important`.
- Everything Bricks-rendered inside the prose area is excluded, as visitessen does, the
  Bricks elements themselves **and** their descendants: selectors end in
  `:not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *))`, so a nested
  `h2.brxe-heading`, an `a.brxe-button` or a component root receives no baseline
  declaration (frontend and editor alike). For pseudo-elements the exclusion precedes
  the pseudo-element (`li:not(…)::marker`). The wrapper itself is not matched (it is not
  a descendant of itself). Exclusion means *no direct declarations*: Bricks elements
  still inherit the wrapper's font size, line height and colour, exactly as they do on
  the frontend — so "unchanged" in the tests means "no baseline declaration applies",
  not "same as without `sfx-prose`".
- **Inheritable defaults go on the wrapper, not the descendants.** Body font size, line
  height and colour are set on `:where(.sfx-prose)` and inherited; paragraphs get no
  typography rule. So typography a site sets on the Bricks wrapper (unlayered, via the
  element or its class) wins through normal inheritance. Descendant rules exist only
  where a descendant needs its own value (headings, links, captions, code, tables).

**Block spacing is not part of the baseline.** Vertical rhythm between blocks — and
between list items, which Bricks' fallback `* + *` also reaches — comes from Bricks'
contextual spacing (theme style), which the root classes already make work in the
editor. The baseline styles spacing *inside* elements only: list indent
(`padding-inline-start`), caption gap (`margin-block-start` on `figcaption`; overridden by Bricks' fallback
contextual spacing when that is set, since `img + figcaption` matches `* + *`), table cell
padding, quote padding.

**What beats the baseline** (all unlayered, so by design): Bricks' "remove default
margins/padding" theme-style resets (`assets.php:1338`) — with padding removal on, list
indent and quote padding are the theme style's or the prose class's job; WordPress core
block CSS for the same property (e.g. `.wp-block-table td` border and padding) — the
baseline styles bare HTML and yields to block styles; and, when Bricks'
`disableBricksCascadeLayer` is on, Bricks' own frontend resets, which then are unlayered
too. The theme's own `disable_bricks_css` setting removes Bricks' and block styles on the
frontend only, while Bricks still loads its styles into the editor; with it on, frontend
and editor differ by design. If the theme's WP Optimizer removes block CSS on the frontend only, frontend and
editor can differ for those blocks — an existing site setting, not this module's.

**Covered:** headings (colour, font, weight, line height), body text (size, line height,
colour — on the wrapper), links and hover, `strong`, lists (`ul`/`ol`, nested,
markers), `blockquote` and citation, `figure` / `img` / `figcaption`, `table` (head,
cells, alternating rows), `code` / `pre`, `hr`, `details`/`summary`.
Link rules also exclude `.wp-block-button__link` and `.wp-element-button`.

**Not covered:** buttons (WordPress' own button block styles and the theme's
`buttons.css` module), gallery and accordion
layouts, alignwide/alignfull widths (layout, the theme's `content-grid.css`),
site-specific extras such as an external-link marker.

**Tokens.** No new public names: each value reads the existing site token, then a Core
Framework token, then a literal. Each rule reads the public tokens **at the element that
uses them** (e.g. `figcaption { color: var(--caption-color, …) }`), without internal
aliases, so a token overridden on a descendant takes effect there too.

| Purpose | Chain |
|---|---|
| body size / line height / colour (on the wrapper) | `--text-m` → `inherit`; `--body-line-height` → `--line-height-m` → `1.6`; `--text-body` → `inherit` |
| heading colour / font / weight | `--text-title` → `inherit`; `--heading-font-family` → `inherit`; `--heading-font-weight` → `700` |
| link / hover | `--link` → `--primary` → `currentColor`; `--link-hover` → `--link` → `--primary` → `currentColor` |
| muted text | `--text-muted` → `--muted` → `currentColor` |
| strong | `--bold-font-weight` → `700` |
| list indent / item gap | `--list-indent` → `1.5em` (item gap: contextual spacing) |
| caption size / colour / gap | `--caption-font-size` → `--text-s` → `0.875em`; `--caption-color` → muted chain; `--caption-gap` → `--space-2xs` → `0.5em` |
| quote padding / border / weight | `--quote-padding-inline` → `--space-m` → `1.5em`; border `--primary` → `currentColor`; `--quote-font-weight` → `inherit` |
| table size / head weight / alt row / border | `--table-font-size` → `--text-s` → `0.875em`; `--table-head-font-weight` → `700`; `--table-row-bg-alt` → `--subtle` → `transparent`; border `--border-primary` → `color-mix(in srgb, currentColor 20%, transparent)` |
| heading line height | `--line-height-s` → `1.2` |
| table cell padding | `--space-2xs` → `0.5em` (block and inline) |
| `pre` padding | `--space-s` → `1em`; background and radius as code |
| `hr` | `border-block-start: 1px solid` border chain as table; no other borders |
| `summary` | `font-weight: var(--bold-font-weight, 700)`; `cursor: pointer` |
| code background / radius | `--subtle` → `color-mix(in srgb, currentColor 8%, transparent)`; `--radius-s` → `0.25em` |

Literal fallbacks are `em`/`inherit`, never `rem`: Bricks' default root is 62.5 %, where
`1rem` is 10px.

Heading **sizes** are left to Core Framework / the theme style, which already size
`h1`–`h6` globally (unlayered, so a layered size would lose anyway).

**Loading.**
- Frontend (and therefore the Bricks builder canvas, which renders the frontend):
  `wp_enqueue_scripts` enqueues `inc/EditorProse/assets/prose.css` when the module and
  `baseline` are on (version `filemtime`), except in the Bricks builder main window (as
  `SmoothScroll` does). This path needs no Bricks version and ignores the post-type
  selection, which governs only the block editor. Layer order: Bricks declares
  `@layer bricks` first (unless `disableBricksCascadeLayer`); `sfx.components` comes
  later, so the baseline beats Bricks' layered resets but nothing unlayered.
- Editor: the script inserts it as the first `links` entry in the canvas, URL with
  `?ver=<filemtime>`. The baseline URL is set before the `try` block, so it ships even
  when the class-CSS build fails.
- Import/export and purge: nothing new — `baseline` lives in `sfx_editor_prose_options`.

**Starter.** The settings page also shows the baseline converted for a site's own class (`Starter::from_file()`): comments and `@layer` removed, `:where(.sfx-prose)` and `.sfx-prose` → `%root%` (the wrapper's zero-specificity `:where()` is dropped — a site's own class is meant to have class specificity) (Bricks' placeholder in a class's custom CSS), token chains unchanged, in a read-only field with a copy button. Generated at render time from `prose.css`, never stored. No header comment (it would be an untranslated user-facing string); the settings page's description explains it. Used instead of the baseline checkbox when a site wants full control.

## Admin page

Under the theme settings menu, following `SmoothScroll/AdminPage.php`: the fields, a
short help text ("everything in the class — typography, lists, links, figures — is
mirrored; design it in Bricks"), and these status lines:
- configured classes not found in Bricks (by name);
- a hint when `baseline` is on: "add the class `sfx-prose` to the Bricks element that
  wraps the content";
- a warning if Bricks' `disableThemeStylesInBlockEditor` is on (spacing and root font
  size then cannot match; finding 3).

Below the form: a read-only starter field with a copy-to-clipboard button (see Baseline → Starter).

All strings `sfxtheme`, escaped at output.

## Testing

Exact expected outputs, not "output differs from input".

- `tests/editor-prose-settings-test.php` (pure PHP): sanitizer — class names (valid,
  leading dot, invalid dropped, duplicates), `element` whitelist, `all_post_types` /
  `post_types` (empty + false = nowhere), `title_gap` accepts `2rem`, `0`,
  `clamp(1rem, 2vw, 2rem)`, `var(--gap, 1rem)`, rejects each forbidden token; title
  rule on/off; empty class list with `baseline` off → gate closed, with `baseline` on →
  gate open. Payload build against stub
  `\Bricks\Assets` / `Database` / `Block_Editor` classes: compiler throws → only the
  block-margin reset, the editor-frame reset and the title rule, and the six saved `Assets` statics hold their prior values afterwards;
  a missing method → same.
- `tests/editor-prose-test.mjs` (Node): `ensureClasses` adds missing classes, keeps
  existing ones, returns "unchanged" when all present (no observer loop); style and link
  insertion are idempotent per document.
- **Browser verification (no automated fixture harness).** On the local site, prepared
  by hand as ordinary dev content through the Bricks UI: a prose class that styles
  paragraphs, headings, lists, links, blockquote, table, figure/figcaption, uses a
  web font and has one tablet-breakpoint override (e.g. paragraph font size); a theme
  style with non-zero contextual spacing and `html` font size `100%`; a page template wrapping post content in a Rich Text element with that
  class; a post containing each of those plus a nested group and one component block.
  At matched viewport conditions (frontend window width = canvas width, above the
  largest breakpoint; then once in a tablet device preview vs a frontend window of the
  same width), after `document.fonts.ready`, with a `FontFace` for the prose font present in
  `document.fonts` with `status === 'loaded'` in both documents, compare
  for every prose element (top-level and descendants: `li`, `a`, `figcaption`,
  `blockquote`, `td`) computed `margin-block-start/end`, `padding`, `border`, `font-family`,
  `font-weight`, `font-size`, `line-height`, `color`, `text-decoration`, `list-style`, and the rendered gap between consecutive top-level blocks
  (`getBoundingClientRect`). Repeat once with the `element` setting `post-content` and a
  Post Content wrapper. Toggle outline mode, switch code editor → visual, switch device
  preview; confirm classes, style and links survive. Confirm the frontend has no
  `sfx-editor-prose`, and the admin document (outside the canvas) has none of the
  injected classes, style or links. A second configured class with a conflicting rule
  checks name-to-ID mapping and the documented ordering. Nothing is created or deleted by a script, so the harness teardown
  rule does not arise. Other Bricks modes (class chaining off, load order on, file CSS
  loading) and multi-class ordering are compiled by Bricks itself and not re-verified
  here; their limits are documented above.
- `tests/editor-prose-baseline-test.mjs` (Node): asserts the layer-order constant
  equals the first `@layer` statement of `assets/css/frontend/styles.css`; parses `prose.css` and asserts every
  style rule is inside `@layer sfx.components`, every selector starts with
  `:where(.sfx-prose)`, and no declaration uses `!important`; and that each custom
  property used ends in a literal fallback (no `var()` chain without one).
- Browser verification of the baseline on the local site: a wrapper with `sfx-prose` and
  no prose class, content covering every covered element (incl. `strong`, `code`/`pre`,
  `hr`, `details`/`summary`, nested list markers, alternating table rows, link hover) —
  the test site sets distinctive values for the public tokens used (e.g. a unique
  `--caption-color`), and for each bare-HTML element (not core-block-styled ones such as
  table-block cells) every property the baseline sets and nothing else on the test site
  sets differs from the same content without `sfx-prose` (so the baseline is
  proven active), and all compared values match between frontend and editor; then a
  prose class setting `color` on `p` — wins in both; Bricks typography set on the
  wrapper element itself — wins on the frontend (not mirrored, see What "parity"
  covers); expected values checked, not only differences — e.g. `figcaption` colour
  equals the distinctive `--caption-color`; once with that token removed, the computed
  value equals the next link of its chain (`--text-muted`); a nested `h2.brxe-heading` and a component block — no baseline declaration
  applies (DevTools matched rules), inheritance as on the frontend.
- `./quality.sh` green.

## Out of scope

- Root font size (finding 3).
- Sidebar width (visitessen snippet).
- Caching across requests — built once per editor load.
- Migration of the aurantia / visitessen snippets (after release).
- Editor mirroring in the classic editor, non-iframed editor, site editor, widgets
  screen, Bricks builder canvas and frontend (the baseline stylesheet itself does load on
  the frontend and builder canvas when enabled).
- Class settings with dynamic-data values (finding 6).
- Cascade order against component classes (see Cascade position and limits).
- Ancestor-dependent rules, editor-only structural nodes, relative `url()`s (see What
  "parity" covers).

## Invariants touched

- **2** (capability + nonce): settings via Settings API with `manage_options`.
- **3** (escape at output): admin page output; inline CSS see [Trust](#trust).
- **4** (text domain): new strings `sfxtheme`; `get_feature_config` with literal `__()`
  like Redirects.
- **Don'ts / option keys**: `sfx_editor_prose_options` added to `DataPurge` and exported
  (no secrets).
- **Coupling**: depends on Bricks (parent theme) and WordPress only. ImportExport names
  the option like every other catalogued module; toggle and overview entry follow the
  existing pattern.
