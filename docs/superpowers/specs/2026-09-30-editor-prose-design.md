# Editor Prose — Design

**Date:** 2026-09-30 (revised after Gate-A pass 1)
**Branch:** `feature/editor-prose`
**Story:** none — this cycle is unprofiled (no story file exists for it).

**Scope:**

- new `inc/EditorProse/*` (`Controller.php`, `Settings.php`, `AdminPage.php`, `assets/editor-prose.js`)
- one toggle `enable_editor_prose` in `inc/GeneralThemeOptions/Settings.php`
- one entry in `inc/ThemeSettingsOverview/OverviewProvider.php`
- `inc/DataPurge.php`: one option name
- one settings group in `inc/ImportExport/Controller.php`
- German strings in `languages/de_DE.po` / `.mo`
- tests in `tests/`, one live harness in `tests/support/`
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
(`brxe-text rich-text-content`), and load the prose class CSS into the editor through
Bricks' own compiler and editor scoper — so every rule Bricks already sends to the editor
starts matching, and nothing is re-implemented or rewritten.

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
   wins. Measured 16px frontend and editor. This depends on Bricks'
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
6. The editor's content root is rendered by React with
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
| Class CSS | Bricks' compiler + Bricks' editor scoper; no fallback to raw `_cssCustom` (requires Bricks ≥ 2.3.8) |
| How the root gets the classes | Small editor script, re-applied when React rewrites the attribute |
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

### Gate (shared by CSS and script)

Applies only when all hold, mirroring Bricks (finding 5): `is_admin()`; the current
screen `is_block_editor()` **and** `base === 'post'` (excludes the widgets and site
editor screens); a post ID resolves (`get_the_ID()`, else `(int) $_GET['post']` via
`filter_input(..., FILTER_VALIDATE_INT)`); the post's type is eligible
(`all_post_types`, or in `post_types`); Bricks is active (`class_exists('\Bricks\Assets')`);
and `classes` is not empty.

### 1. Root classes (script)

`enqueue_block_editor_assets` enqueues `inc/EditorProse/assets/editor-prose.js` with
config `{ classes: ['brxe-<element>', ...classes] }`.

The script finds the editor iframe (`iframe[name="editor-canvas"]`), waits for its
document, and adds the classes to that document's `.is-root-container`. A single
`MutationObserver` on the iframe body (`childList`, `subtree`, `attributes`,
`attributeFilter: ['class']`) re-adds any missing class after React rewrites the
attribute (finding 6) or remounts the root. It only ever calls `classList.add` for
missing classes, so its own writes cause no loop. If the iframe is replaced (device
preview switch), the outer observer on the editor's canvas container re-attaches. If the
editor is not iframed, it uses the main document's `.is-root-container`.

The class-applying step is a pure function `ensureClasses(element, classes)` exported for
the Node test.

### 2. Class CSS (inline style in the canvas)

`enqueue_block_assets`, priority 20 (after Bricks' 10). Once per request, a static cache
in `Controller` holds the built string, so the hook firing twice (admin document and
iframe asset collection) builds once. Each firing registers (`wp_register_style(
'sfx-editor-prose', false)`), enqueues, and adds the inline CSS only if the handle has no
`after` data yet — idempotent in both asset contexts.

Build:

1. Map configured class names to IDs via `\Bricks\Database::$global_data['globalClasses']`
   (the same source `generate_global_classes` reads). Unknown names are skipped; the
   admin page lists them (see below).
2. Save the six `Assets` statics Bricks saves in `generate_gutenberg_global_classes_css`;
   set `Assets::$global_classes_elements = [ id => [ $element ], … ]` for all configured
   classes in **one** call, so Bricks applies its own ordering;
   `$css = Assets::generate_global_classes('sfx_editor_prose')`; restore in `finally`.
   A `Throwable` is caught → no class CSS, the rest still ships. Any of the used
   properties/methods missing → same.
3. `Assets::load_webfonts($css)` — fonts used only by the prose class load, as Bricks
   does for component classes.
4. `$css = Block_Editor::scope_css_for_gutenberg($css)` — Bricks' own prefixing
   (`.block-editor-iframe__body`), which keeps specificity in step with Bricks' editor
   spacing rules (validated above).
5. Append the title rule if `title_gap` is set:
   `.editor-styles-wrapper .editor-post-title { margin-block-end: <title_gap>; }`
6. `$css = apply_filters('sfx_editor_prose_css', $css, $post_type)`; replace any
   `</style` (case-insensitive) with `<\/style`; add only if non-empty.

A class that is also used by an enabled Bricks component is already output by Bricks
(finding 2); duplicating it is harmless (identical rules) and not special-cased.

### Bricks component blocks

No exclusion. On the frontend, component blocks sit inside the prose wrapper too, so
prose rules reach them there as well; mirroring that is parity. The live check compares a
component block frontend vs editor; if the editor's block wrappers make them differ, that
is a finding for the plan, not solved speculatively here.

### Failure behaviour

Bricks missing or a Bricks API missing/throwing drops only the class CSS; the script
still sets the classes (spacing works) and the title rule still ships. The script does
nothing if it finds no root within 10 s. Nothing is logged; the editor never shows a
notice.

### Trust

Class CSS is Bricks' compiled output of data written by users Bricks' builder permissions
allow (`builder-permissions.php`) — not necessarily administrators. The module adds no
new write path and outputs the same CSS Bricks already outputs on the frontend. The
module's own inputs are sanitized as above; the final string has `</style` neutralised.

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
  `clamp(1rem, 2vw, 2rem)`, `var(--gap, 1rem)`, rejects each forbidden token; CSS
  assembly — title rule on/off, `</style` neutralised, empty → no output.
- `tests/editor-prose-test.mjs` (Node): `ensureClasses` adds missing classes, keeps
  existing ones, is a no-op when all present (so the observer cannot loop).
- Live harness `tests/support/editor-prose-live-check.php` on the local site (Bricks
  active): creates one test global class and one post it owns, registers **one**
  `register_shutdown_function` teardown before creating either (restores
  `bricks_global_classes` to its snapshot, deletes the post), asserts the built CSS
  contains the class rules prefixed with `.block-editor-iframe__body` and chained
  `.brxe-text`. Visual comparison (paragraphs, headings, list, image with caption, one
  component block; frontend vs editor computed styles) done in the browser with the
  harness's fixtures, and the frontend checked for absence of `sfx-editor-prose`.
- `./quality.sh` green.

## Out of scope

- Root font size (finding 3).
- Sidebar width (visitessen snippet).
- Caching across requests — built once per editor load.
- Migration of the aurantia / visitessen snippets (after release).
- Classic editor, site editor, widgets screen, Bricks builder canvas, frontend.

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
- **Harness teardown**: the live harness follows the single-teardown rule.
