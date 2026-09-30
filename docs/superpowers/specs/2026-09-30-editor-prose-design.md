# Editor Prose — Design

**Date:** 2026-09-30 (revised after Gate-A passes 1–2)
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
   components** (`Block_Editor::generate_gutenberg_global_classes_css`, since 2.3.8). An
   ordinary prose class wrapping native blocks is not included.
3. **Root font size is handled by Bricks** when theme styles load in the editor:
   `frontend.min.css` sets `html{font-size:62.5%}` (layered); the theme style's `html`
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
6. Class settings with a **dynamic-data** value (e.g. a colour from a custom field) are
   written to the separate `Assets::$inline_css_dynamic_data` bucket
   (`assets.php:4028`), which Bricks' own editor path for component classes also does not
   output. Not mirrored (see Out of scope).
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

**The same sanitizer runs on every read** (`Settings::get()`), not only on save — so an
import, a hand-edited option or a value written while the module was disabled can never
reach the editor unsanitized. The ImportExport group uses the same function.

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

1. Map configured class names to IDs via `\Bricks\Database::$global_data['globalClasses']`
   (the source `generate_global_classes` reads). Unknown names are skipped; the admin page
   lists them.
2. Save the six `Assets` statics Bricks saves in `generate_gutenberg_global_classes_css`;
   set `Assets::$global_classes_elements = [ id => [ $element ], … ]` for all configured
   classes in **one** call, so Bricks applies its own ordering among them;
   `$css = Assets::generate_global_classes('sfx_editor_prose')`; restore in `finally`.
   A `Throwable`, or a used property/method missing, → `css` empty; classes still ship.
3. `fonts`: the `href`s from `Assets::load_webfonts($css, true)` (Bricks returns link
   HTML in that mode instead of enqueueing).
4. `$css = Block_Editor::scope_css_for_gutenberg($css)` — Bricks' own prefixing
   (`.block-editor-iframe__body`), which keeps specificity in step with Bricks' editor
   spacing rules (validated above).
5. Append the title rule if `title_gap` is set:
   `.editor-styles-wrapper .editor-post-title { margin-block-end: <title_gap>; }`
6. `$css = apply_filters('sfx_editor_prose_css', $css, $post_type)`.

The payload goes through `wp_add_inline_script(..., 'before')` with `wp_json_encode`
(JSON-escapes `</`), so no HTML context is involved.

### Client: `editor-prose.js`

One idempotent `sync()`:

- find `iframe[name="editor-canvas"]`; if absent, or its document has no
  `.is-root-container` yet, return (non-iframed editors are not supported — every rule
  is scoped to `.block-editor-iframe__body`);
- in that document: ensure `<style id="sfx-editor-prose">` with `css` exists in `<head>`
  (create once per document; `textContent`, never HTML), ensure one
  `<link rel="stylesheet">` per font URL, and `ensureClasses(root, classes)`;
- if that document is not yet observed, attach one `MutationObserver` to its
  `documentElement` (`childList`, `subtree`, `attributes`, `attributeFilter: ['class']`)
  calling `sync()`; observed documents are tracked in a `WeakSet`, so a replaced document
  gets its own observer and the old one is dropped with it.

`sync()` is triggered by one `MutationObserver` on the admin `document.body`
(`childList`, `subtree`) — which sees the canvas mount after a code-editor → visual
switch, device-preview iframe replacement, and body portalling — and by the iframe's
`load` event (listener added when `sync()` first sees an iframe element). No timeout.

`ensureClasses(element, classes)` only calls `classList.add` for missing classes and
reports whether it changed anything, so the observer's own writes do not loop. It and
the style/link insertion are exported for the Node test.

### Cascade position and limits

The injected `<style>` is appended last in the canvas `<head>`, after Bricks' editor CSS.
Among the configured classes Bricks' order applies. Against **other** global classes
Bricks outputs in the editor (component classes, finding 2) the prose CSS always comes
later; equal-specificity conflicts between a prose class and a component class can
therefore resolve differently than on the frontend. Accepted limit, documented in the
README.

Bricks' scoper does not scope every valid construct (statement at-rules such as
`@layer x;`, braces inside strings). Because the CSS lives only inside the canvas
document, anything left unscoped can affect only the canvas, never the admin UI.

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
  by hand as ordinary dev content through the Bricks UI (a prose class, a page template
  wrapping post content in a Rich Text element with that class, a post with paragraphs,
  headings, a list, an image with caption and one component block): compare computed
  margin, font-size, line-height and colour of every top-level block frontend vs editor,
  as done on aurantia; toggle outline mode, switch code editor → visual and device
  preview and confirm classes and style survive; confirm the frontend has no
  `sfx-editor-prose`. Nothing is created or deleted by a script, so the harness teardown
  rule does not arise.
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
