# Media Credits — Design

**Date:** 2026-08-24
**Branch:** `feature/image-credit-fields`

**Scope:**

- new `inc/MediaCredits/*`
- one field in `inc/GeneralThemeOptions/Settings.php`
- one entry in `inc/ThemeSettingsOverview/OverviewProvider.php`
- one option name in `uninstall.php`
- two tests in `tests/`, one stub file in `tests/support/`

## Goal

Two extra fields on every media attachment — a free-text **copyright notice** and an **AI/alteration label** picked from a fixed list — plus the plumbing to get them onto the page from a Bricks Image element.

Non-goals: a general-purpose attachment meta framework, a custom Bricks element, per-site editable label vocabularies, bulk-editing existing media, schema.org markup. See [Out of Scope](#out-of-scope).

## Why this exists

Two separate obligations, one field pair:

- **Credit.** A copyright/photographer notice belongs next to licensed imagery. Today it is retyped by hand into captions, or forgotten.
- **AI disclosure.** EU AI Act Art. 50 requires AI-generated or AI-manipulated content to be disclosed, and requires the marking to be machine-readable. German press/competition practice additionally distinguishes conventional digital alteration (compositing, retouching) from AI involvement.

This module is **tooling, not legal advice**. It gives the editor a place to record the fact and the site a consistent way to show it. Whether a given image needs a label, and with which wording, stays an editorial decision.

## Background

The theme auto-discovers features: `SFXBricksChildTheme::auto_register_features()` (SFXBricksChildTheme.php:323) globs `inc/*/Controller.php` and registers every class exposing a static `get_feature_config()`. `load_dependencies()` instantiates a controller only when the config's `activation_option_key` is truthy inside `activation_option_name`. `inc/SmoothScroll/` is the closest structural analogue: own settings page, own option array, no post type, purely additive hooks.

### Bricks facts this design rests on

All verified against the installed Bricks (`wp-content/themes/bricks`), 2.3.x:

| Fact | Location |
|---|---|
| Image element has an `HTML tag` control: `figure` / `div` / `custom` | `includes/elements/image.php:74` |
| A wrapper tag renders only if caption, overlay, `_gradient` or `tag` is set — otherwise `<img>` is the root | `includes/elements/image.php:822`, `:830-836` |
| Wrapper renders `_root` attributes | `includes/elements/image.php:976` |
| Caption types: `none` / `attachment` / `custom`; `captionCustom` is a plain text control | `includes/elements/image.php:192-213` |
| `<figcaption>` is rendered after the picture/link tags close | `includes/elements/image.php:1186-1195` |
| `get_normalized_image_settings()` is **public** and resolves dynamic image sources to an attachment id | `includes/elements/image.php:724` |
| The `<img>` is emitted by `wp_get_attachment_image()`; `bricks/element/render_attributes` is applied there for `_root` only | `includes/elements/image.php:1122`, `:1153` |
| `bricks/element/settings` fires with the element instance immediately **before** `render()` | `includes/elements/base.php:2948` |
| `bricks/frontend/render_element` fires with the rendered HTML and the element instance **after** render | `includes/frontend.php:752` |

The consequence that shapes everything below: **dynamic data inside `captionCustom` resolves against the current post, not against the attachment.** `{post_meta:_sfx_media_copyright}` written there returns the *page's* meta. A tag that works per-image has to establish the attachment context itself.

## Architecture

```
inc/MediaCredits/
  Controller.php    registration + get_feature_config()
  Settings.php      option schema, label vocabulary, defaults, sanitizing
  AdminPage.php     settings page (incl. media-uploader fields for the seals)
  MediaLibrary.php  attachment fields, save, IPTC prefill, list column + filter
  Credit.php        single source of truth: id -> resolved credit parts
  Bricks.php        dynamic tags, element context pointer, auto-output
  assets/media-credits.css
  assets/media-credits-admin.js
  index.php
```

`Controller::__construct()` registers the four classes and nothing else; it holds no logic (same shape as `NavMenuQuery\Controller`).

```php
public static function get_feature_config(): array
{
    return [
        'class'                  => self::class,
        'menu_slug'              => AdminPage::$menu_slug,
        'page_title'             => AdminPage::$page_title,
        'description'            => AdminPage::$description,
        'activation_option_name' => 'sfx_general_options',
        'activation_option_key'  => 'enable_media_credits',
        'option_value'           => true,
        'hook'                   => null,
        'error'                  => 'Missing MediaCredits Controller class in theme',
    ];
}
```

Feature off = not a single hook registered.

## Data model

Two post meta on the attachment, underscore-prefixed so they stay out of the Custom Fields box:

| Key | Type | Sanitize |
|---|---|---|
| `_sfx_media_copyright` | string, free text | `sanitize_text_field` |
| `_sfx_media_ai` | string, enum slug | whitelist against the label list; unknown value → `''` |

Registered through `register_meta('post', …)` with `single => true`, `show_in_rest => false`.

**The meta is user content and is never deleted by this module** — not on feature deactivation, not on uninstall. Only the settings option is listed in `uninstall.php`. Turning the feature off hides the fields; it must not silently discard a site's copyright records.

### Label vocabulary

Fixed, five steps, stored as slug:

| Slug | Default label (`sfxtheme`) |
|---|---|
| `''` | *(no marking — default)* |
| `ai_generated` | KI-generiert |
| `ai_edited` | KI-bearbeitet |
| `ai_assisted` | KI-unterstützt |
| `digitally_altered` | Digital verändert |

`Settings::get_labels()` returns the map, passed through the filter `sfx_media_credits_labels` so a site can reword without a settings UI. The **slugs are the contract** — a filter may change wording, never keys.

## `Credit.php` — the single source of truth

```php
Credit::for(int $attachment_id): array
// => [
//   'copyright' => string,   // attachment value, else global fallback, else ''
//   'ai_key'    => string,   // '' | ai_generated | ...
//   'ai_label'  => string,   // resolved, translated label, '' if no key
//   'icon_id'   => int,      // seal attachment id for this key, 0 if none
//   'line'      => string,   // ready-to-print HTML, '' if nothing to show
// ]
```

`line` composition:

1. Copyright part: prefix `©&nbsp;` **unless** the text already starts with `©`, `(c)` or `Copyright` (case-insensitive).
2. AI part: seal `<img>` and/or label text, per the `credit_display` setting (`text` | `icon` | `icon_text`). The seal is always rendered as `<img src="…" alt="{label}" width={size} height={size}>` — never inline SVG, so no SVG sanitizing is needed, and the label stays readable to assistive tech even in icon-only mode.
3. Joined with `&nbsp;·&nbsp;`. Empty parts drop out; both empty → `''`.

Filter `sfx_media_credit_line( string $line, int $id, array $parts )` for the rare site that wants different wording or order.

Results are memoised per request in a static array keyed by attachment id — an image repeated in a loop must not re-query meta each time.

Everything downstream — dynamic tags, auto-output, the media library column — goes through this one function. There is exactly one place where a credit line is composed.

### The global fallback, and its trade-off

`Settings` holds `fallback_copyright`. When an attachment has no copyright of its own, this value is used. It is applied inside `Credit::for()`, so it reaches tags and auto-output alike.

The trade-off is stated here because it is not obvious: **once the fallback is filled in, every image with an id has a credit** — logos, icons, decorative shapes included. That is exactly right on a site that shot all its own imagery, and wrong on a site that did not. There is deliberately no second switch to scope it; the escape hatches are leaving the field empty, or the `no-credit` class on individual elements.

## Media library (`MediaLibrary.php`)

**Fields.** `attachment_fields_to_edit` / `attachment_fields_to_save` — one text input, one select. These render both in the media modal and on the attachment edit screen, for **all** attachment types (image, video, audio, PDF): the AI Act covers more than stills, and restricting by MIME type would only add a check.

**List column.** `manage_media_columns` + `manage_media_custom_column` — one "Credit" column showing the copyright text and, if set, the label.

**Filter.** `restrict_manage_posts` (on `upload.php`) + `pre_get_posts` — a dropdown: *all* · *without copyright* · *with AI marking* · one entry per label. `without copyright` uses a `NOT EXISTS` / empty-value `meta_query`.

`ponytail:` column and filter exist in the **list** view only. The media grid has no columns — that is WordPress, not a shortcut, and the fix would mean reimplementing the grid.

**IPTC prefill.** Filter on `wp_generate_attachment_metadata`: WordPress already parses IPTC/EXIF into `$metadata['image_meta']`. Read `['copyright']`, fall back to `['credit']`, write to `_sfx_media_copyright` **only if it is still empty**. Roughly ten lines and no parser of our own. Non-images have no `image_meta` and are skipped by the same emptiness check.

## Settings (`Settings.php`, `AdminPage.php`)

Option `sfx_media_credits_options`, submenu under `sfx-theme-settings`, guarded by `AccessControl::can_access_theme_settings()` like every other module page.

| Field | Type | Default | Note |
|---|---|---|---|
| `output_mode` | select: `off` / `caption` / `overlay` | `off` | auto-output; see below |
| `force_wrapper` | checkbox | off | see [Wrapper](#the-wrapper-question) |
| `credit_display` | select: `text` / `icon` / `icon_text` | `text` | how the AI part renders |
| `icon_size` | number (px) | `24` | seal edge length |
| `fallback_copyright` | text | `''` | see trade-off above |
| `seal_{slug}` | attachment id | `0` | one per AI label, four in total |

The seal fields use the WP media uploader (`wp_enqueue_media()` + `assets/media-credits-admin.js`, ~30 lines: open frame, write id to hidden input, swap preview). Stored as an id, not a URL, so the image survives a domain change.

Sanitizing: `output_mode` / `credit_display` whitelisted against their option lists, `icon_size` clamped to 8–128, seal ids cast to int and checked with `wp_attachment_is_image()`, `fallback_copyright` through `sanitize_text_field`.

The settings page carries a tips card stating the wrapper requirement in plain words — that is where the user turns the mode on, so that is where the constraint belongs.

## Bricks integration (`Bricks.php`)

### The element context pointer

`bricks/element/settings` (base.php:2948) fires with the element instance immediately before `render()`. For `$element->name === 'image'` we resolve the attachment id via the element's own public `get_normalized_image_settings()` (image.php:724 — it also resolves dynamic image sources) and park it in a static:

```php
self::$current_image_id = $id;   // on bricks/element/settings
self::$current_image_id = 0;     // on bricks/frontend/render_element, always
```

Image elements do not nest, so a single slot is enough; a stack would be ceremony. `render_element` clears unconditionally, including when nothing was injected, so a skipped or failed render cannot leak context into the next element.

`ponytail:` this is the one clever mechanism in the module. It rests on `bricks/element/settings` running before `render()` — a documented Bricks hook (the Academy link sits in Bricks' own source next to the call). The test pins the ordering against stubs, and `{sfx_media_credit:123}` with an explicit id is the escape hatch if a future Bricks release moves it.

### Dynamic tags

Registered via `bricks/dynamic_tags_list`, `bricks/dynamic_data/render_tag` and `bricks/dynamic_data/render_content`, following `NavMenuQuery\MenuItemTags`:

- `{sfx_media_copyright}` — the copyright part
- `{sfx_media_ai_label}` — the label text
- `{sfx_media_credit}` — the composed line, seal included

Context resolution, first hit wins:

1. **Explicit id** — `{sfx_media_credit:123}`
2. **Current image element** — the pointer above. This is what makes the tag work inside the Image element's *Custom caption* field: Bricks renders its own `<figcaption>`, with its own typography controls, around our text. **This is the recommended way to use the module.**
3. **Bricks loop object**, when it is an attachment — covers query loops over attachments
4. **Global `$post`**, when it is an attachment
5. **Featured image** of the current post

A tag that resolves to nothing renders as an empty string, never as the literal tag.

### Auto-output — safety net only

`bricks/frontend/render_element` (frontend.php:752), `image` elements only, active when `output_mode !== 'off'`. Skipped when the element carries the CSS class `no-credit`, and when `Credit::for()` returns an empty line.

**It never creates structure.** Bricks omits the wrapper entirely when no caption, overlay, gradient or `tag` is set (image.php:822) — in that case the root is the bare `<img>` (or `<a>`, or `<picture>`). Injection rules:

| Rendered root | Behaviour |
|---|---|
| `figure` | `caption` mode: append into the existing `</figcaption>` if there is one, otherwise add a `<figcaption>`. `overlay` mode: insert `<span class="sfx-credit sfx-credit--overlay">` before `</figure>`. |
| `div` / custom tag | Same, but a `<div class="sfx-credit">` instead of `<figcaption>` — `figcaption` is only valid inside `figure`. |
| `img` / `picture` / `a` | **Nothing.** No wrapping, no layout surprise. |

Root detection is a single regex on the first tag name; insertion is before the trailing closing tag. At most one `<figcaption>` per `<figure>` is produced, which is what the spec requires.

### The wrapper question

An image without a wrapper cannot receive a credit, and silently doing nothing is a poor answer when the point of the feature is not forgetting. Two answers, and the site picks:

- **Default (`force_wrapper` off).** Structure is the user's business. The settings page states the requirement: set the Image element's *HTML tag* to `figure`, or set a caption, and the credit appears. Nothing is injected where no wrapper exists.
- **`force_wrapper` on.** On `bricks/element/settings`, when auto-output is on and the attachment actually has credit data, set `$settings['tag'] = 'figure'`. This is the element's **own documented option**, set through Bricks' own filter — the same mechanism translation plugins use. Bricks then renders its own `<figure>`, with its own classes, and the markup stays valid. No string surgery, no foreign wrapper.

`ponytail:` a warning rendered into the builder canvas was considered and dropped — on the no-wrapper path the root *is* the `<img>`, so a hint would have to be a sibling node, and Bricks' canvas maps elements by their single root. Not worth risking the builder to save a settings-page sentence. Revisit if the constraint proves confusing in practice.

### Machine-readable marking

`wp_get_attachment_image_attributes` (WordPress core, not Bricks): when the attachment has an AI key, add `data-sfx-ai="{key}"` to the `<img>`. Bricks emits the image through `wp_get_attachment_image()` (image.php:1153), so this covers the Image element — and every other themed image on the site as a bonus. No regex, no structural change, and it satisfies the machine-readable half of Art. 50 even when the visible credit is styled away.

### CSS

One stylesheet, enqueued only when the feature is on and `output_mode === 'overlay'`:

```css
.sfx-credit--overlay { position: absolute; inset-block-end: 0; inset-inline-end: 0; }
:where(figure, div):has(> .sfx-credit--overlay) { position: relative; }
```

`ponytail:` positioning the parent via `:has()` avoids adding a class to markup we did not create. `:has()` is Baseline since 2023; in a browser without it the credit renders in flow instead of overlaid — degraded, not broken.

## Integration points

- `inc/GeneralThemeOptions/Settings.php` — one `enable_media_credits` checkbox, group `general`, default `0`
- `inc/ThemeSettingsOverview/OverviewProvider.php` — one entry in `build_builtin_modules_group()`
- `uninstall.php` — `sfx_media_credits_options` in the option list. **The attachment meta is not listed.**
- No `handle_*()` in `GeneralThemeOptions\Controller` — unlike ImageOptimizer or SmoothScroll, this module's settings are **not** wiped when the feature is switched off. Seal assignments and the fallback notice are configuration a site should get back when it re-enables the feature. `PasswordProtected` and `NavMenuQuery` already set this precedent.

## Testing

Plain PHP assert scripts in `tests/`, in the style of `nav-menu-query-test.php`.

`tests/media-credits-credit-test.php`
- line composition: `©` prefix added, and *not* added when the text already carries `©` / `(c)` / `Copyright`
- separator behaviour when only one part is present, and when neither is
- `credit_display` modes: text-only, icon-only (alt text carries the label), icon+text
- fallback applied when the attachment value is empty, ignored when it is not
- unknown `_sfx_media_ai` slug sanitizes to `''` and produces no label

`tests/media-credits-bricks-test.php` (with `tests/support/media-credits-bricks-stubs.php`)
- context resolution order: explicit id beats element pointer beats loop object beats `$post` beats featured image
- the pointer is set before render and cleared after, including when the element renders nothing
- auto-output injects into `figure` and into `div` with the correct wrapper tag
- auto-output injects **nothing** for `img`, `picture` and `a` roots
- an existing `</figcaption>` is appended to rather than duplicated
- `no-credit` on the element suppresses injection

## Out of scope

| Left out | Add when |
|---|---|
| Custom Bricks element | The tag in a caption proves not to be enough |
| Per-site editable label list | A client needs wording the `sfx_media_credits_labels` filter cannot reach |
| Bulk edit for existing media | A migration actually needs it; the list filter already finds the gaps |
| schema.org `copyrightNotice` | Someone asks for it |
| Builder canvas warning | The settings-page hint proves insufficient |
| Frontend credit for external-URL images | Never — there is no attachment to read meta from |
