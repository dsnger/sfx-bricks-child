# Editor Prose — Design

**Date:** 2026-09-30 (revised after Gate-A passes 1–3)
**Branch:** `feature/editor-prose`
**Story:** none — this cycle is unprofiled (no story file exists for it).

**Scope:**

- new `inc/EditorProse/*` (`Controller.php`, `Settings.php`, `AdminPage.php`, `assets/editor-prose.js`)
- one toggle `enable_editor_prose` in `inc/GeneralThemeOptions/Settings.php`
- one entry in `inc/ThemeSettingsOverview/OverviewProvider.php`
- `inc/DataPurge.php`: one option name
- one settings group in `inc/ImportExport/Controller.php`
- German strings in `languages/de_DE.po` / `.mo`
- tests in `tests/`
- README section

## Goal

Gutenberg content on Bricks sites is rendered on the frontend inside a Bricks element
(usually Rich Text, `.brxe-text`) carrying a global "prose" class (e.g.
`rich-text-content`). Everything that class defines — typography, colours, headings,
lists, links, quotes, tables, figures and captions — plus Bricks' theme-style spacing
("contextual spacing") applies on the frontend. In the block editor none of it does:
block spacing collapses to 0 and the class rules are missing.

The module makes the editor canvas render like the frontend, configured per site with no
code, so the site snippets (aurantia "Rich-Text: Editor-Styles", visitessen "Magazin:
Editor-Styles") can be removed. The theme ships **no prose styling of its own**; each
site's styling stays in its own Bricks class and is mirrored as-is.

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
| Prose styling shipped by the theme | None — each site's Bricks class is the single source |
| Sidebar width, caching | Out |
| Filters | One: `sfx_editor_prose_css` |

## Settings

Option `sfx_editor_prose_options` (array), capability `manage_options`, nonce via the
Settings API (`settings_fields`) like the other modules.

| Key | Type | Default | Sanitize |
|---|---|---|---|
| `classes` | list of Bricks global class names (text field, comma/space separated) | `[]` | each must match `^-?[_a-zA-Z][_a-zA-Z0-9-]*$` after stripping a leading `.`; invalid entries dropped; duplicates removed |
| `element` | `text` \| `post-content` — the Bricks element that wraps the content on the frontend | `text` | whitelist, else `text` |
| `all_post_types` | bool | `true` | bool |
| `post_types` | list of post type slugs (used only when `all_post_types` is false) | `[]` | keep only registered post types that use the block editor |
| `title_gap` | CSS value for the gap below the post title | `''` = no rule | trimmed; max 100 chars; rejected (→ `''`) if it contains any of `; { } < > \ ` or `/*` or `url(` |

`all_post_types` is explicit so that dropping unknown post types (import, a post type
removed later) can never widen the selection: with `all_post_types = false` and an empty
list, the module applies nowhere.

The sanitizer is total: a non-array option → defaults; a non-array `classes` /
`post_types` → `[]`; `all_post_types` coerced (`true`/`1`/`'1'` → true, anything else
false); unknown `element` → `text`; non-string `title_gap` → `''`.

**The same sanitizer runs on every read** (`Settings::get()`), not only on save — so an
import, a hand-edited option or a value written while the module was disabled can never
reach the editor unsanitized. The ImportExport group uses the same function. Import **merge** mode keeps
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
version_compare(BRICKS_VERSION, '2.4', '>=')`); and `classes` is not empty.

### Server: build the payload

On `enqueue_block_editor_assets` (admin document only), when the gate holds, enqueue
`inc/EditorProse/assets/editor-prose.js` and pass one config object:

```
{ classes: ['brxe-<element>', ...classes], css: '<string>', fonts: ['<url>', …] }
```

Building `css`:

The whole build (steps 1–5) runs inside one `try { … } catch (\Throwable) { … }`:
any failure — Database lookup, compilation, font extraction, scoping — leaves `css`
holding only the title rule, and `classes` still ships.

1. Map configured class names to IDs via `\Bricks\Database::$global_data['globalClasses']`
   (the source `generate_global_classes` reads). Unknown names are skipped; the admin page
   lists them.
2. Save the six `Assets` statics Bricks saves in `generate_gutenberg_global_classes_css`;
   set `Assets::$global_classes_elements = [ id => [ $element ], … ]` for all configured
   classes in **one** call, so Bricks applies its own ordering among them;
   `$css = Assets::generate_global_classes('sfx_editor_prose')`; restore in `finally`.
   A used property/method missing → skip to step 5.
3. `fonts`: the `href`s of the `rel="stylesheet"` links in
   `Assets::load_webfonts($css, true)` (Bricks returns link HTML in that mode instead of
   enqueueing; preconnect links are ignored).
4. `$css = Block_Editor::scope_css_for_gutenberg($css)` — Bricks' own prefixing
   (`.block-editor-iframe__body`), which keeps specificity in step with Bricks' editor
   spacing rules (validated above).
5. Append the title rule if `title_gap` is set:
   `.editor-styles-wrapper .editor-post-title { margin-block-end: <title_gap>; }`
6. `$css = apply_filters('sfx_editor_prose_css', $css, $post_type)`.

The payload goes through `wp_add_inline_script(..., 'before')` inside a `<script>`
element of the admin document; `wp_json_encode` escapes `/` by default, so `</script`
inside the CSS cannot end the element.

### Client: `editor-prose.js`

One idempotent `sync()`:

- find `iframe[name="editor-canvas"]`; if absent, return. On first sight of an iframe
  element, add its `load` listener (tracked in a `WeakSet` of iframe elements, so once
  per element);
- take its current `contentDocument`; if that document is not yet observed (tracked in a
  `WeakSet` of documents), attach one `MutationObserver` to its `documentElement`
  (`childList`, `subtree`, `attributes`, `attributeFilter: ['class']`) calling `sync()` —
  **before** looking for the root, so a root portalled in later is seen;
- if the document has no `.is-root-container` yet, return;
- ensure `<style id="sfx-editor-prose">` with `css` exists in `<head>` (created once per
  document; `textContent`, never HTML), one `<link rel="stylesheet">` per font URL, and
  `ensureClasses(root, classes)`.

Non-iframed editors are not supported — every rule is scoped to
`.block-editor-iframe__body`.

`sync()` runs once at script start, on every mutation of one `MutationObserver` on the
admin `document.body` (`childList`, `subtree`) — which sees the canvas mount after a
code-editor → visual switch and device-preview iframe replacement — and on each iframe
`load`. No timeout.

`ensureClasses(element, classes)` only calls `classList.add` for missing classes and
reports whether it changed anything, so the observer's own writes do not loop. It and
the style/link insertion are exported for the Node test.

### Cascade position and limits

The injected `<style>` is appended last in the canvas `<head>`, after Bricks' editor CSS.
Among the configured classes: with Bricks' `globalClassesLoadOrder` on, Class-Manager
order applies, as on the frontend; with it off, Bricks uses encounter order, which here
is the order of the `classes` setting — so list them in the order they appear on the
frontend element (help text says so). Against **other** global classes
Bricks outputs in the editor (component classes, finding 2) the prose CSS always comes
later; equal-specificity conflicts between a prose class and a component class can
therefore resolve differently than on the frontend. Accepted limit, documented in the
README.

Bricks' scoper does not scope every valid construct (statement at-rules such as
`@layer x;`, braces inside strings). Because the CSS lives only inside the canvas
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
- relative `url()`s in class CSS — they resolve against the admin URL in the canvas.

### Bricks component blocks

No exclusion. On the frontend, component blocks sit inside the prose wrapper too, so
prose rules reach them there as well; mirroring that is parity. The browser verification
compares a component block frontend vs editor; if the editor's block wrappers make them
differ, that is a finding for the plan, not solved speculatively here.

### Failure behaviour

Bricks missing or older than 2.4 → gate closed, nothing loads. A Bricks API missing or
throwing → `css` holds only the title rule (or is empty); classes still ship, so Bricks'
spacing still matches. Nothing is logged; the editor never shows a notice.

### Trust

Class CSS is Bricks' compiled output of data written by users Bricks' builder permissions
allow (`builder-permissions.php`) — not necessarily administrators. The module adds no
new write path; it places CSS Bricks already outputs on the frontend into the canvas
document only, where it cannot style or read admin UI. The module's own inputs are
sanitized as above.

## Admin page

Under the theme settings menu, following `SmoothScroll/AdminPage.php`: the fields, a
short help text ("everything in the class — typography, lists, links, figures — is
mirrored; design it in Bricks"), and two status lines:
- configured classes not found in Bricks (by name);
- a warning if Bricks' `disableThemeStylesInBlockEditor` is on (spacing and root font
  size then cannot match; finding 3).

All strings `sfxtheme`, escaped at output.

## Testing

Exact expected outputs, not "output differs from input".

- `tests/editor-prose-settings-test.php` (pure PHP): sanitizer — class names (valid,
  leading dot, invalid dropped, duplicates), `element` whitelist, `all_post_types` /
  `post_types` (empty + false = nowhere), `title_gap` accepts `2rem`, `0`,
  `clamp(1rem, 2vw, 2rem)`, `var(--gap, 1rem)`, rejects each forbidden token; title
  rule on/off; empty class list → gate closed.
- `tests/editor-prose-test.mjs` (Node): `ensureClasses` adds missing classes, keeps
  existing ones, returns "unchanged" when all present (no observer loop); style and link
  insertion are idempotent per document.
- **Browser verification (no automated fixture harness).** On the local site, prepared
  by hand as ordinary dev content through the Bricks UI: a prose class that styles
  paragraphs, headings, lists, links, blockquote, table, figure/figcaption and uses a
  web font; a page template wrapping post content in a Rich Text element with that
  class; a post containing each of those plus a nested group and one component block.
  At the same content width, after `document.fonts.ready` in both documents, compare
  for every prose element (top-level and descendants: `li`, `a`, `figcaption`,
  `blockquote`, `td`) computed `margin-block-start/end`, `font-family`, `font-size`,
  `line-height`, `color`, and the rendered gap between consecutive top-level blocks
  (`getBoundingClientRect`). Repeat once with the `element` setting `post-content` and a
  Post Content wrapper. Toggle outline mode, switch code editor → visual, switch device
  preview; confirm classes, style and links survive. Confirm the frontend has no
  `sfx-editor-prose`. Nothing is created or deleted by a script, so the harness teardown
  rule does not arise. Other Bricks modes (class chaining off, load order on, file CSS
  loading) are compiled by Bricks itself and not re-verified here.
- `./quality.sh` green.

## Out of scope

- Root font size (finding 3).
- Sidebar width (visitessen snippet).
- Caching across requests — built once per editor load.
- Migration of the aurantia / visitessen snippets (after release).
- Classic editor, non-iframed editor, site editor, widgets screen, Bricks builder canvas,
  frontend.
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
