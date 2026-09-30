# Editor Prose — Design

**Date:** 2026-09-30
**Branch:** `feature/editor-prose`
**Story:** none — this cycle is unprofiled (no story file exists for it).

**Scope:**

- new `inc/EditorProse/*` (`Controller.php`, `Settings.php`, `AdminPage.php`, `CssBuilder.php`)
- one toggle `enable_editor_prose` in `inc/GeneralThemeOptions/Settings.php`
- one entry in `inc/ThemeSettingsOverview/OverviewProvider.php`
- `inc/DataPurge.php`: one option name
- one settings group in `inc/ImportExport/Controller.php`
- German strings in `languages/de_DE.po` / `.mo`
- one test in `tests/`
- README section

## Goal

Gutenberg content on Bricks sites is rendered on the frontend inside a Bricks element
carrying a global "prose" class (e.g. `rich-text-content`). In the block editor the same
content looks different: block spacing collapses to 0 and the prose class rules are
missing. The module makes the editor canvas look like the frontend, configured per site
with no code, so the site-level snippets (aurantia "Rich-Text: Editor-Styles",
visitessen "Magazin: Editor-Styles") can be removed.

Non-goals: see [Out of scope](#out-of-scope).

## Findings (verified 2026-09-30, Bricks 2.4.2, WP 7.1.2)

Verified in code (`themes/bricks/includes`) and live on aurantia (read-only):

1. **Bricks' spacing rules never match in the editor.** Bricks scopes its frontend CSS
   for the editor by prefixing `.block-editor-iframe__body`
   (`integrations/block-editor.php` `scope_css_selector_for_gutenberg`). Its contextual
   spacing selectors need a `.brxe-text` / `.brxe-post-content` ancestor
   (`theme-styles/controls/contextual-spacing.php`), which the editor has none of. Live on
   aurantia, without the snippet the only matching margin rules are Bricks' resets to `0`.
2. **Global class CSS reaches the editor only for classes used inside Bricks components**
   (`Block_Editor::generate_gutenberg_global_classes_css`, since 2.3.8). A prose class is
   not one of them.
3. **The "62.5 %" problem is solved by Bricks already.** `frontend.min.css` sets
   `html{font-size:62.5%}` (layered); Bricks scopes it to `.block-editor-iframe__html`.
   The theme style's `html` font-size (aurantia: `100%`) is scoped the same way,
   unlayered, and wins. Measured: `html` is 16px on the frontend and in the editor. **The
   module does not touch the root font size.**
4. **Frontend rhythm comes from the theme style's contextual spacing**: on aurantia
   `.brxe-text * + *` 1rem, `* + p` 1.25rem, `* + :is(h1…h6)` 2rem — the values of
   `contextualSpacingFallback` / `contextualSpacingParagraph` / `contextualSpacingHeading`.
   These are readable via the public `\Bricks\Theme_Styles::load_set_styles($post_id)` +
   `Theme_Styles::$settings_by_id`, which Bricks itself uses for the editor
   (`admin.php` `get_gutenberg_theme_styles`).
5. **Bricks can compile a global class to CSS including its UI settings**
   (`\Bricks\Assets::generate_global_classes($key)` driven by
   `Assets::$global_classes_elements`), with Bricks' own save/restore of the static state
   around the call (`generate_gutenberg_global_classes_css`). `%root%` in `_cssCustom` is
   replaced with the class selector by Bricks.
6. Bricks enqueues its editor-canvas CSS on `enqueue_block_assets` (`gutenberg_scripts`,
   priority 10).

## Decisions settled with Daniel (2026-09-30)

| Question | Answer |
|---|---|
| Root font size | Not handled — Bricks already brings the theme style's value (finding 3) |
| Base rhythm | Read from the active Bricks theme style, no fields |
| Class CSS source | Bricks' compiler (UI settings + custom CSS), fallback `_cssCustom` |
| Selector rewriting | Replace the class token with `:scope` inside one `@scope` block — no pattern parser |
| Sidebar width, cache, extra filters | Out |

## Settings

Option `sfx_editor_prose_options` (array), capability `manage_options`, nonce via the
Settings API (`settings_fields`) like the other modules:

| Key | Type | Default | Sanitize |
|---|---|---|---|
| `classes` | list of class names (one text field, comma- or space-separated) | `[]` | each must match `^-?[_a-zA-Z][_a-zA-Z0-9-]*$` (a leading `.` is stripped); invalid entries are dropped |
| `post_types` | list of post type slugs (checkboxes over post types with `show_ui` that use the block editor) | `[]` = all block-editor post types | keep only registered post types |
| `title_gap` | CSS length or `var(--token)` | `''` = no rule | must match `^(var\(--[a-zA-Z0-9_-]+\)\|-?[0-9.]+(px\|rem\|em\|%\|vh\|vw\|ch))$`; otherwise `''` |

The module is enabled by `enable_editor_prose` in `sfx_general_options` (default off),
like every other module.

## Mechanism

`Controller` hooks `enqueue_block_assets` at priority 20 (after Bricks' 10). It returns
early unless all hold: `is_admin()`, the current screen is the block editor
(`get_current_screen()->is_block_editor()`), the screen's post type is configured (or the
list is empty), and at least one of rhythm / classes / title yields CSS.

It registers an empty handle `sfx-editor-prose` and adds the CSS with
`wp_add_inline_style`. `CssBuilder` produces the string; the result passes through
`apply_filters('sfx_editor_prose_css', $css, $post_type)` and is not added if empty.

### CSS output

```css
@scope (.editor-styles-wrapper .is-root-container) to ([data-type^="bricks-components/"]) {
  /* 1. rhythm, from the theme style (each line only if the value is set) */
  :scope * + * { margin-block-start: <fallback>; }
  :scope * + p { margin-block-start: <paragraph>; }
  :scope * + :is(h1, h2, h3, h4, h5, h6) { margin-block-start: <heading>; }

  /* 2. per configured class: compiled class CSS with the class token replaced by :scope */
}
/* 3. title */
.editor-styles-wrapper .editor-post-title { margin-block-end: <title_gap>; }
```

- **Rhythm** mirrors the frontend selectors (`.brxe-text * + p`, descendant, not child),
  in the same order as Bricks outputs them, so the cascade matches. Values: for each of
  the three keys, the last non-empty value across `Theme_Styles::$settings_by_id` after
  `load_set_styles($post_id)`. Each value is checked with the `title_gap` pattern; a value
  that fails is skipped (a number without unit gets `px`, as Bricks does for `number`
  controls — to verify in the plan against Bricks' output for a unitless value).
- **Class CSS**: for each configured class name, find the entry in
  `\Bricks\Database::$global_data['globalClasses']` (fallback `get_option('bricks_global_classes')`)
  with `name === $class`. Primary source: `Assets::generate_global_classes()` with
  `$global_classes_elements = [ $id => ['text'] ]`, wrapped in the same save/restore of
  Assets' static state that Bricks uses. Fallback (method/property missing, or it
  returns empty): the entry's `settings._cssCustom` with `%root%` replaced by `.$class`.
  Then every occurrence of `.$class` not followed by `[A-Za-z0-9_-]` is replaced with
  `:scope`. Occurrences inside other contexts (e.g. `.foo .$class p`) become
  `.foo :scope p`, which simply matches nothing inside the scope — no broken CSS.
- **Component exclusion** is the `to (...)` limit of `@scope`: rules stop at a Bricks
  component block. No per-selector `:not()`.
- **Title** rule only if `title_gap` is set.
- `@font-face` / `@keyframes` are not allowed inside `@scope`. A class's `_cssCustom`
  may contain them, so they are moved in front of the `@scope` block with the same
  regexes Bricks uses in `scope_css_for_gutenberg`.

### Failure behaviour

Any missing Bricks class/method/property (`\Bricks\Assets`, `\Bricks\Theme_Styles`,
`\Bricks\Database`) drops only the part that needs it; the rest still ships. If Bricks is
not active, the module outputs only the title rule (or nothing). A throwable from
Bricks' compiler is caught, the Assets state restored (`finally`), and the fallback used.
Nothing is logged — the editor must never break or show a notice.

### Trust

All CSS inputs are written by administrators (Bricks classes, theme styles, this
module's settings), the same trust level as Bricks' own editor CSS, which it also adds
unescaped via `wp_add_inline_style`. The module's own inputs (class names, `title_gap`)
are whitelisted by pattern. As defence against a class CSS that closes the style
element, any `</style` in the final string (case-insensitive) is replaced with `<\/style`.

## Admin page

`AdminPage` under the theme settings menu, following `SmoothScroll/AdminPage.php`:
the three fields, a short help text on the authoring convention (class CSS nested under
`%root%` or the class selector; element UI settings are included automatically), and a
read-only line showing the rhythm values found in the theme style (or "none found").
All strings in `sfxtheme`, escaped at output.

## Testing

- `tests/editor-prose-css-test.php` (pure PHP, no WordPress): `CssBuilder` class-token
  replacement (`.x`, `:is(.x):is(.x)`, nested `&`, `.x-y` untouched, `.foo .x p`), rhythm
  output order and skipping of invalid values, title rule on/off, `</style` neutralised,
  `@font-face` moved out, empty input → empty string; sanitize of class names and
  `title_gap`. Each case fails if the builder returns its input unchanged.
- Live check on the local site: a test prose class with `%root%`-nested CSS and one UI
  setting, a post with paragraphs, headings, list, image with caption and one Bricks
  component block; compare computed margins of the same blocks frontend vs editor, and
  confirm the component block is unaffected. Frontend must show no `sfx-editor-prose`
  handle.
- `./quality.sh` green.

## Out of scope

- Root font size (finding 3).
- Sidebar width (visitessen snippet) — unrelated to prose.
- Caching — the CSS is built only when the editor loads.
- Migration of aurantia / visitessen snippets (separate, after release).
- Classic editor, Bricks builder canvas, frontend.

## Invariants touched

- **2** (capability + nonce): settings write via Settings API with `manage_options`.
- **3** (escape at output): admin page output; the inline CSS is not HTML-escaped by
  nature — see [Trust](#trust).
- **4** (text domain): all new strings `sfxtheme`, `get_feature_config` with literal
  `__()` like Redirects.
- **Don'ts / option keys**: `sfx_editor_prose_options` added to `DataPurge` and exported
  (no secrets).
- **Coupling**: the module depends on Bricks (parent theme) only; no new module-to-module
  edge. The toggle in GeneralThemeOptions and the overview entry follow the existing
  pattern of every module.
