# Media Credits — Design

**Date:** 2026-08-24
**Branch:** `feature/image-credit-fields`

**Scope:**

- new `inc/MediaCredits/*`
- one field in `inc/GeneralThemeOptions/Settings.php`
- one entry in `inc/ThemeSettingsOverview/OverviewProvider.php`
- one settings group plus a two-line type rename in `inc/ImportExport/Controller.php`
- one option name and three meta purges in `uninstall.php` (a file that, per [Integration points](#integration-points), never actually executes — a pre-existing theme defect, raised separately)
- three tests in `tests/`, one stub file in `tests/support/`

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
| A wrapper tag renders only if an *effective* caption, overlay, `_gradient` or `tag` is set — otherwise `<img>` is the root. The caption type defaults to `attachment` from theme styles, so an unset caption control is not the same as no caption | `includes/elements/image.php:794-810`, `:822`, `:830-836` |
| A caption forces `tag = figure`; `sources` without link or caption forces `tag = picture`, overriding an explicitly chosen tag | `includes/elements/image.php:955-964` |
| Wrapper renders `_root` attributes | `includes/elements/image.php:976` |
| Caption types: `none` / `attachment` / `custom`; `captionCustom` is a plain text control | `includes/elements/image.php:192-213` |
| `<figcaption>` is rendered after the picture/link tags close | `includes/elements/image.php:1186-1195` |
| `get_normalized_image_settings()` is **public** and resolves a dynamic image source to an attachment id — but only when the provider returns something numeric. A provider returning a URL sets `url` and leaves `id` at `0` | `includes/elements/image.php:724`, `:738-760` |
| The `<img>` is emitted by `wp_get_attachment_image()`; `bricks/element/render_attributes` is applied there for `_root` only | `includes/elements/image.php:1122`, `:1153` |
| `bricks/element/settings` fires with the element instance immediately **before** `render()` | `includes/elements/base.php:2948` |
| `bricks/frontend/render_element` fires with the rendered HTML and the element instance **after** render | `includes/frontend.php:752` |
| Dynamic tags left in element HTML are resolved much later, by `bricks/frontend/render_data` over the whole assembled document | `includes/frontend.php:947` |
| `captionCustom` is **not** passed through `render_dynamic_data()` — it is copied into the caption verbatim | `includes/elements/image.php:805-806` |
| A single-element re-render in the builder calls `init()` directly and never applies `bricks/frontend/render_element` | `includes/ajax.php:885-891` |
| Bricks registers post meta as `{cf_<key>}`, there is no `{post_meta:…}` tag | `includes/integrations/dynamic-data/providers/provider-wp.php:388`, `:545-550` |

The consequence that shapes everything below: **a dynamic tag written into `captionCustom` never sees the attachment.** It is not resolved by the element at all — it survives into the page HTML and is parsed at the end, against the current post. `{cf__sfx_media_copyright}` written there returns the *page's* meta, not the image's. A tag that works per-image has to be resolved before the element renders.

## Architecture

```
inc/MediaCredits/
  Controller.php    registration + get_feature_config()
  Settings.php      option schema, label vocabulary, defaults, sanitizing
  AdminPage.php     settings page (incl. media-uploader fields for the seals)
  MediaLibrary.php  attachment fields, save, IPTC prefill, list column + filter
  Credit.php        single source of truth: id -> resolved credit parts
  Bricks.php        settings-time tag substitution, dynamic tags, auto-output
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
| `_sfx_media_iptc_prefilled` | flag, `'1'` | internal bookkeeping for the one-shot IPTC prefill below; written by the module, never shown |

Registered through `register_meta('post', …)` with `single => true`, `show_in_rest => false`.

**Meta lifecycle, two tiers** — this is the theme's existing contract, not a new rule:

- **Feature switched off** (`enable_media_credits` unchecked): nothing is touched. Neither meta nor settings. The toggle hides the fields; it must not silently discard a site's copyright records. No `handle_*()` in `GeneralThemeOptions\Controller`, unlike ImageOptimizer or SmoothScroll — `PasswordProtected` and `NavMenuQuery` already set that precedent.
- **Theme uninstalled with `delete_on_uninstall` on**: all three meta keys are purged along with the option — three `delete_post_meta_by_key()` calls, no `$wpdb`, written into `uninstall.php` next to the existing purges. The IPTC marker goes with them: leaving it behind would make a later reinstall skip the prefill for attachments whose copyright had just been deleted. Read [Integration points](#integration-points) before relying on this: that file is never executed by WordPress for a theme, so today the code is correct and inert.

Worth naming plainly: if that mechanism is ever repaired, `delete_on_uninstall` will destroy every copyright notice and AI label on the site. That is what the switch promises, and it is the same promise it already makes for Custom Scripts and Contact Infos.

### Label vocabulary

Fixed, five steps, stored as slug:

| Slug | Default label (`sfxtheme`) |
|---|---|
| `''` | *(no marking — default)* |
| `ai_generated` | KI-generiert |
| `ai_edited` | KI-bearbeitet |
| `ai_assisted` | KI-unterstützt |
| `digitally_altered` | Digital verändert |

`Settings::get_labels()` returns the map, passed through the filter `sfx_media_credits_labels` so a site can reword without a settings UI. The **slugs are the contract**, enforced in both directions: `array_merge($defaults, array_intersect_key($filtered, $defaults))`. The intersection drops keys a filter invented; the merge restores keys a filter dropped, with their default wording. Intersection alone would not — a filter returning three entries would leave two slugs with no label at all, and images already marked with them would silently lose their disclosure. Wording is negotiable, keys are not.

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
2. AI part: seal `<img>` and/or label text, per the `credit_display` setting (`text` | `icon` | `icon_text`). The seal renders as `<img src="…" alt="…" width={size} height={size}>` — never inline SVG, so no SVG sanitizing is needed. **`alt` carries the label in `icon` mode and is empty in `icon_text` mode**, where the label is already printed as text; otherwise assistive technology announces it twice.
3. Joined with `&nbsp;·&nbsp;`. Empty parts drop out; both empty → `''`.

A seal whose attachment no longer exists (`wp_get_attachment_image_url()` returns false) is treated as no seal — in `icon` mode the line falls back to the label text rather than emitting a broken image.

Filter `sfx_media_credits_line( string $line, int $id, array $parts )` for the rare site that wants different wording or order.

Results are memoised per request in a static array keyed by attachment id — an image repeated in a loop must not re-query meta each time. Single-site assumption, stated because memoisation makes it load-bearing: attachment ids are site-local, so this cache would be wrong across a `switch_to_blog()`. See [Out of Scope](#out-of-scope).

Everything that renders a credit — dynamic tags, caption auto-output, overlay auto-output — goes through this one function. There is exactly one place where a credit *line* is composed.

The media library column is the deliberate exception: it reads the stored meta directly, because its job is to show what an editor entered, and a value that exists only because of the global fallback has to look different from one that does not. Composing a line there would make the column disagree with its own "without copyright" filter. `Credit::for()` composes output; the column reports state.

### The global fallback, and its trade-off

`Settings` holds `fallback_copyright`. When an attachment has no copyright of its own, this value is used. It is applied inside `Credit::for()`, so it reaches tags and auto-output alike.

The trade-off is stated here because it is not obvious: **once the fallback is filled in, every image with an id has a credit** — logos, icons, decorative shapes included. That is exactly right on a site that shot all its own imagery, and wrong on a site that did not. There is deliberately no second switch to scope it; the escape hatches are leaving the field empty, or the `no-credit` class on individual elements.

`no-credit` suppresses **auto-output only** — caption and overlay. It does not disable a tag an editor placed by hand, and it does not remove `data-sfx-ai`: the machine-readable marking is not a design decision, and an element hidden from the visible credit is exactly where it still belongs.

One guard the fallback does need: for an id whose attachment is gone (`wp_get_attachment_url()` falsy) `Credit::for()` still returns its array — the return type does not change — but with every part empty, `line` included. Bricks keeps the stored id and renders a placeholder for a deleted image (image.php:839-848); without the guard, a missing picture would carry a confident copyright line naming an owner who never took it.

## Media library (`MediaLibrary.php`)

**Fields.** `attachment_fields_to_edit` / `attachment_fields_to_save` — one text input, one select. These render both in the media modal and on the attachment edit screen, for **all** attachment types (image, video, audio, PDF): the AI Act covers more than stills, and restricting by MIME type would only add a check.

**List column.** `manage_media_columns` + `manage_media_custom_column` — one "Credit" column showing the copyright text and, if set, the label. The column reads the **stored** meta, not `Credit::for()`: a value that only exists because of the global fallback is shown greyed and marked as such. Otherwise the column would display a copyright for rows the filter below calls "without copyright".

**Filter.** `restrict_manage_posts` + `pre_get_posts` — a dropdown: *all* · *without copyright* · *with AI marking* · one entry per label. `without copyright` uses a `NOT EXISTS` / empty-value `meta_query` against the stored meta.

The `pre_get_posts` callback carries a scope contract, because that hook fires for every query in the request: act only when `is_admin()`, `$query->is_main_query()`, `$pagenow === 'upload.php'`, the queried post type is `attachment`, and the request parameter holds one of the recognised filter values. Note the screen *id* is `upload`, not `upload.php` — `WP_Screen` strips the suffix (class-wp-screen.php:235-237) — so comparing `get_current_screen()->id` against `upload.php` never matches and silently disables the filter. Anything else returns untouched.

`ponytail:` column and filter exist in the **list** view only. The media grid has no columns — that is WordPress, not a shortcut, and the fix would mean reimplementing the grid.

**IPTC prefill.** Filter on `wp_generate_attachment_metadata`: WordPress already parses IPTC/EXIF into `$metadata['image_meta']`. Read `['copyright']`, fall back to `['credit']`, write to `_sfx_media_copyright`. Roughly ten lines and no parser of our own; non-images have no `image_meta` and drop out.

**Once, on upload only.** That hook also fires when attachment metadata is regenerated — thumbnail regeneration, a media plugin's rebuild (`wp-admin/includes/image.php:184-188`). "Write it if the field is empty" would therefore resurrect a value an editor deliberately cleared, every time someone regenerates thumbnails. The prefill writes once and records that it did, in a `_sfx_media_iptc_prefilled` marker meta; a second run finds the marker and does nothing, whatever the field currently holds.

## Settings (`Settings.php`, `AdminPage.php`)

Option `sfx_media_credits_options`, submenu under `sfx-theme-settings`, guarded by `AccessControl::can_access_theme_settings()` like every other module page.

| Field | Type | Default | Note |
|---|---|---|---|
| `output_mode` | select: `off` / `caption` / `overlay` | `off` | auto-output; see below |
| `force_wrapper` | checkbox | off | overlay mode only; see [Overlay auto-output](#3--overlay-auto-output-the-one-html-injection) |
| `credit_display` | select: `text` / `icon` / `icon_text` | `text` | how the AI part renders |
| `icon_size` | number (px) | `24` | seal edge length |
| `fallback_copyright` | text | `''` | see trade-off above |
| `seal_{slug}` | attachment id | `0` | one per AI label, four in total |

The seal fields use the WP media uploader (`wp_enqueue_media()` + `assets/media-credits-admin.js`, ~40 lines: open frame, write id to hidden input, swap preview, **and a Remove button that writes `0` back**). Without the remove control a chosen seal could never be unchosen. Each field is a labelled control with the preview carrying the label as `alt`, and every string — field labels, filter options, uploader buttons, error text — goes through `sfxtheme`.

Stored as an id, not a URL, so the image survives a domain change.

**Validation happens on write and on read.** On write: `output_mode` / `credit_display` whitelisted against their option lists, `icon_size` clamped to 8–128, `force_wrapper` cast to bool, seal ids cast to int and checked with `wp_attachment_is_image()`, `fallback_copyright` through `sanitize_text_field`. On read, `Settings::get()` applies the same whitelists and the same clamp, falling back to the default for anything unrecognised — see [Import](#integration-points) for why that second pass is not redundant.

The settings page carries a tips card: caption mode is the reliable one, overlay mode needs a wrapper (`HTML tag` → `figure`, or `force_wrapper`), and overlay cannot attach to a responsive-Sources image that has neither a link nor a caption. That is where the user turns the mode on, so that is where the constraints belong.

## Bricks integration (`Bricks.php`)

Three mechanisms, in descending order of how much of the work they carry. Only the last one touches rendered HTML.

### 1 · Tags resolved in the element's own settings

`bricks/element/settings` (base.php:2948) fires with the element instance immediately before `render()`. For an `image` element whose `captionCustom` contains `{sfx_media_`, we resolve the attachment id from the element's own public `get_normalized_image_settings()` (image.php:724) and substitute our tags **in that setting**, wrapping the result in `<span class="sfx-credit">` so the dedup rule below can see it.

This is what makes `{sfx_media_credit}` work in the Image element's *Custom caption* field: Bricks then renders its own `<figcaption>`, with its own typography controls, around finished text.

**One setting, not the whole array.** An earlier draft recursed over every string in the settings, which is both too broad and partly too late: composed HTML dropped into `altText` or a source media query is invalid in context, and `_cssClasses`, `_attributes` and `_conditions` have already been consumed by the time this filter runs (base.php:2908, :2929) — a tag there could never have worked. `captionCustom` is the only setting the Image element renders as free text and does not resolve itself.

**Why not the ordinary dynamic-data route.** `captionCustom` is never passed through `render_dynamic_data()` — image.php:805-806 copies it into the caption verbatim. The tag survives into the page HTML and is only resolved much later, when `bricks/frontend/render_data` runs over the whole assembled document (frontend.php:947) — long after the element that knew which image it belonged to finished rendering (frontend.php:752). A per-element context pointer cleared at `render_element` would already be gone, and the tag would silently resolve against the page's featured image instead. Substituting in the settings is the only place where element and tag are in the same room.

It also works on both render paths — see the builder note below — because `bricks/element/settings` sits inside `Element::init()`, which both paths call.

### 2 · Caption auto-output, written as a setting

When `output_mode === 'caption'`, the same `bricks/element/settings` pass composes the credit into `captionCustom` and sets `caption = 'custom'`, preserving whatever caption would otherwise have shown: the element's own custom caption, or the attachment's `post_excerpt` when the effective caption type is `attachment` (image.php:794-810 — note the type defaults to `attachment` from theme styles, so "no caption control set" does not mean "no caption"). Existing caption and credit are joined by a `<span class="sfx-credit">` on its own line, never by bare concatenation.

Nothing is injected, nothing is wrapped, and the markup is Bricks' own. Two further problems dissolve here:

- **The wrapper question disappears for this mode.** A caption forces `tag = 'figure'` (image.php:955-959), so a bare `<img>` root cannot occur.
- **Responsive Sources stop being a special case.** With `sources` set and no link or caption, Bricks makes `<picture>` the root (image.php:961-964) — overriding even an explicitly chosen `tag`. The caption branch is checked first and wins.

`force_wrapper` is therefore **only** relevant to overlay mode.

### 3 · Overlay auto-output, the one HTML injection

When `output_mode === 'overlay'`, `bricks/frontend/render_element` (frontend.php:752) inserts `<span class="sfx-credit sfx-credit--overlay">` before the closing tag of the rendered root — and **only** when that root is a real wrapper. Root detection is a regex on the first tag name:

| Rendered root | Behaviour |
|---|---|
| `figure`, `div`, other custom tag | insert before the trailing closing tag |
| `img`, `picture`, `a` | **nothing** — no wrapping, no layout surprise |

With `force_wrapper` on, `bricks/element/settings` sets `$settings['tag'] = 'figure'` when the element has no tag of its own. That single key is what flips `$has_html_tag` and makes a wrapper render at all (image.php:822).

It is worth being precise about why that is enough, because the obvious worry is real but does not apply here. `$this->tag` is computed once in the **constructor** — `$this->tag = $this->get_tag()` (base.php:120, :220-233) — long before this filter runs, and image.php:976/:1199 print that property, not the settings key. Assigning the key late therefore cannot change the tag *name*. It does not need to: `Element_Image` declares `public $tag = 'figure'` (image.php:10), so an element with no tag of its own already resolves to `figure`, and one that does have a tag already renders a wrapper and needs no help. Writing the tag name onto the instance as well would only overwrite a `div` the user deliberately chose — a wrapper is a wrapper, and the overlay attaches to either.

One case is not covered: `sources` set with **no link and no caption** re-assigns `$this->tag = 'picture'` inside `render()`, after the filter (image.php:961-964). Only that combination; a Sources image with a caption, or with a link plus a wrapper, keeps its wrapper and takes the overlay normally. Those few elements get no overlay, and the settings page says so — the fix is caption mode.

**Builder parity is partial, and that is a Bricks constraint.** `Ajax::render_element()` re-renders a single element by calling `$element_instance->init()` directly and never applies `bricks/frontend/render_element` (ajax.php:885-891; the REST route delegates to it). So while editing, an overlay credit appears on a full canvas load and disappears from the single element being edited until the canvas reloads. Mechanisms 1 and 2 are unaffected — they live inside `init()`. This is the strongest reason to prefer caption mode.

### Dedup

Auto-output is skipped when the element already carries a credit. The marker is the class `sfx-credit`, matched as a class-attribute token — not as a bare substring, which would also trip over unrelated text or a `sfx-credit-note` class. This is why mechanism 1 wraps its substitution in that same span: an editor who followed the recommended route and placed the tag by hand must not get the credit a second time the moment someone switches auto-output on.

**Order inside the settings filter is fixed, because both mechanisms write the same setting:**

1. Substitute our tags in `captionCustom` (mechanism 1).
2. Determine the **effective** caption — what will actually render, per image.php:794-810: `captionCustom` only when the effective caption type is `custom`, otherwise the attachment's `post_excerpt`, or nothing when the type is `none`.
3. Caption auto-output (mechanism 2) runs only if that effective caption carries no marker.

Step 2 is what makes step 3 correct. Testing the raw `captionCustom` instead would let a marker sitting in a caption Bricks is not going to render — type `attachment` or `none` — suppress the auto-credit entirely, and the image would ship with no disclosure at all.

### Escaping

`Credit::for()` returns `line` as **HTML, already escaped at composition**: `esc_html()` on the copyright text and the label, `esc_url()` on the seal URL, `esc_attr()` on the AI key. The `sfx_media_credits_line` filter output is passed through `wp_kses_post()` before it is used, so a filter can add markup but not script. Callers print `line` unescaped — that contract is stated once, here, because a second `esc_html()` downstream would print the tags.

**Braces are escaped last**, and this one is not cosmetic. Whatever we emit ends up in the page HTML, and Bricks parses the *whole assembled document* for dynamic tags afterwards (frontend.php:947, providers.php:304-447). `sanitize_text_field()` leaves `{` and `}` untouched, so a copyright notice reading `{post_title}` would silently become the page title on the frontend while showing correctly in the admin — and `{echo:some_function}` reaches Bricks' echo tag (provider-wp.php:365, :1026, providers.php:432). A copyright field is free text typed by anyone who can upload media; it must not be a dynamic-data injection point.

The rule is therefore stated as a boundary, not as a step: **every string this module puts into page content passes `Credit::escape_braces()` as the final operation before it leaves the module** — after the `sfx_media_credits_line` filter (so a filter cannot reintroduce a tag), and on the individual tag values too, `{sfx_media_copyright}` and `{sfx_media_ai_label}` included. `wp_kses_post()` does not help here: it filters HTML, and braces are not HTML (kses.php:2502-2503).

### Dynamic tags outside the image element

Registered via `bricks/dynamic_tags_list`, `bricks/dynamic_data/render_tag` (**priority 20**) and `bricks/dynamic_data/render_content`, following `NavMenuQuery\MenuItemTags` — including the two details that file documents as load-bearing: priority 20, and tolerating the `{tag}` form Bricks re-wraps values into (`MenuItemTags.php:166-238`).

- `{sfx_media_copyright}` — the copyright text as `Credit::for()` resolved it, global fallback included, **without** the `©` prefix (braces escaped like every other output)
- `{sfx_media_ai_label}` — the label text alone, no seal
- `{sfx_media_credit}` — the composed line, prefix and seal included

Context resolution, first hit wins:

1. **Explicit id** — `{sfx_media_credit:123}`
2. **Bricks loop object**, when it is an attachment — covers query loops over attachments
3. **Global `$post`**, when it is an attachment
4. **Featured image** of the current post

Inside an image element, mechanism 1 has already substituted the tag before this list is ever consulted.

A tag that resolves to nothing renders as an empty string, never as the literal tag.

### Machine-readable marking

`wp_get_attachment_image_attributes` (WordPress core, not Bricks): when the attachment has an AI key, add `data-sfx-ai="{key}"` to the `<img>`. Bricks emits the image through `wp_get_attachment_image()` (image.php:1153), so the Image element is covered, as is any other image the site renders **through that same core function** — not stored Gutenberg HTML, not CSS backgrounds, not hand-written `<img>` tags, and not Bricks' `<source>` elements (image.php:912-951).

Stated precisely, because the earlier draft overstated it: this attribute is **a** machine-readable signal in the delivered page. It is not a provenance marking in the sense of AI Act Art. 50(2), which contemplates techniques that survive the file leaving the page — watermarks, embedded metadata, C2PA-style provenance. The attribute is lost on download, copy or proxy. Treating it as full compliance would be wrong; treating it as the cheap part that helps is right.

### CSS

One stylesheet, enqueued only when the feature is on and `output_mode === 'overlay'`:

```css
.sfx-credit--overlay {
  position: absolute;
  inset-block-end: 0;
  inset-inline-end: 0;
  padding: 0.25em 0.5em;
  background: rgb(0 0 0 / 0.55);
  color: #fff;
  font-size: 0.75rem;
  line-height: 1.3;
}
*:has(> .sfx-credit--overlay) { position: relative; }
```

The background is not decoration: credit text sits on arbitrary photography, and without an opaque backing WCAG AA contrast cannot be guaranteed for any colour choice.

`ponytail:` positioning the parent via `:has()` avoids adding a class to markup we did not create, and the universal selector covers custom root tags — the earlier draft named only `figure` and `div` while the behaviour table allowed any tag. `:has()` is Baseline since 2023; without it the credit renders in flow instead of overlaid — degraded, not broken.

## Integration points

Four docking points, matching how the theme already works.

**1 · Feature toggle** — `inc/GeneralThemeOptions/Settings.php`: one `enable_media_credits` checkbox, group `general`, default `0`. Picked up automatically by `auto_register_features()` through `get_feature_config()`; off means not one hook is registered.

**2 · Settings overview** — `inc/ThemeSettingsOverview/OverviewProvider.php`: one entry in `build_builtin_modules_group()`, label *Media Credits*.

**3 · Import/Export** — `inc/ImportExport/Controller::get_settings_groups()`:

```php
'media_credits' => [
    'label'       => __('Media Credits Settings', 'sfxtheme'),
    'description' => __('Copyright and AI-labelling output settings', 'sfxtheme'),
    'option_key'  => 'sfx_media_credits_options',
    'type'        => 'subset',
    'fields'      => ['output_mode', 'force_wrapper', 'credit_display', 'icon_size', 'fallback_copyright'],
],
```

The **seal ids are deliberately excluded**. They are attachment ids, meaningful only on the site that stored them; carried into another site's JSON they would resolve to whatever image happens to hold that id — an unrelated picture silently presented as an AI seal. Everything that *is* portable travels; the seals get re-picked on the target site, where the subset importer leaves them untouched (it merges named fields into the existing option rather than replacing it).

That needs the subset mechanism under an honest name: the existing `dashboard_subset` type is already fully generic — subset-of-one-option, nothing dashboard-specific in either `collect_settings_data()` or the import branch. Rename it to `subset` and accept both spellings at the two comparison sites (`Controller.php:415` and `:798`), so the eight dashboard groups keep working untouched:

```php
} elseif (in_array($group['type'], ['subset', 'dashboard_subset'], true)) {
```

`ponytail:` two lines and no migration. Reusing the literal name `dashboard_subset` for a media module would have cost nothing and confused every later reader.

**What the import path does and does not sanitize.** The importer's own `sanitize_option_value()` sends array options through a generic recursive sanitizer, which knows nothing of this module's whitelists. It is not the only gate, though: it calls `update_option()`, and core runs `sanitize_option()` inside it (`wp-includes/option.php:886`), which fires `sanitize_option_{$option}` — the filter `register_setting()` attaches the module's own callback to (`option.php:3072-3074`). So **when the module is loaded, an import does pass through `Settings::sanitize_options()`.**

The gap is narrower than that, and real: `register_setting()` only runs when the feature is enabled, on `admin_init`. An import performed while `enable_media_credits` is off writes the option with the generic sanitizer alone; enabling the feature later then reads values nothing validated. That is why `Settings::get()` whitelists **on read as well as on write** — one defensive pass in the getter closes a case that is otherwise invisible until it misbehaves.

**4 · Uninstall — and a finding about the existing mechanism**

The intended docking is trivial: `sfx_media_credits_options` in `$options_to_delete`, plus `delete_post_meta_by_key()` for the two meta keys, behind the same `delete_on_uninstall` check as everything else in that file.

**But `uninstall.php` never runs for a theme.** That filename is a *plugin* convention: core includes it only from `uninstall_plugin()` (`wp-admin/includes/plugin.php:1284`, `:1317-1327`). `delete_theme()` (`wp-admin/includes/theme.php`) fires `delete_theme`, removes the directory and fires `deleted_theme` — it contains no reference to `uninstall.php` at all, and the child theme registers no handler for either action. Every option in that file, and the legacy Text Snippets purge with them, is dead code today.

This is a **pre-existing defect in the theme, not one this feature introduces**, and fixing it is a separate piece of work: a deleted theme's code is not loaded at deletion time (a theme must be inactive to be deleted), so the cleanup can only be driven from somewhere that outlives it — an mu-plugin, or a "delete my data now" button in the theme settings that runs while the theme is still active.

The spec's position: add the two entries so the file stays internally consistent and is correct on the day the mechanism is fixed, and **do not claim the data is purged on uninstall**. Raised separately; not in this feature's scope.

## Testing

Plain PHP assert scripts in `tests/`, in the style of `nav-menu-query-test.php`.

`tests/media-credits-credit-test.php`
- line composition: `©` prefix added, and *not* added when the text already carries `©` / `(c)` / `Copyright`
- separator behaviour when only one part is present, and when neither is
- `credit_display` modes: text-only, icon-only (alt text carries the label), icon+text
- fallback applied when the attachment value is empty, ignored when it is not
- unknown `_sfx_media_ai` slug sanitizes to `''` and produces no label

- a seal id pointing at a deleted attachment falls back to the label text
- an attachment id whose file is gone yields `''`, fallback copyright included
- `Settings::get()` returns the default for an out-of-list stored value, and clamps an out-of-range `icon_size`
- the export group's `fields` list contains no `seal_*` key

`tests/media-credits-iptc-test.php`
- prefill writes when the field is empty and the marker is absent
- a second `wp_generate_attachment_metadata` run (thumbnail regeneration) with the field deliberately cleared writes **nothing**, because the marker is set
- a non-image attachment with no `image_meta` is left alone

`tests/media-credits-bricks-test.php` (with `tests/support/media-credits-bricks-stubs.php`)
- tag substitution rewrites `captionCustom` in the settings array, and leaves settings without our tags untouched
- caption mode composes with an existing custom caption and with an attachment caption, rather than replacing either
- dedup tests the **effective** caption: a marker in `captionCustom` while the caption type is `attachment` does not suppress auto-output
- context resolution order for tags outside an image element: explicit id beats loop object beats `$post` beats featured image
- overlay injects into `figure` and into a custom root tag, and injects **nothing** for `img`, `picture` and `a`
- dedup: an element that already contains `sfx-credit` gets no second credit
- `no-credit` matches as a whitespace-separated token — `no-credit-card` does not suppress anything
- escaping: a copyright containing `<script>` and a filter returning script markup both come back inert
- brace escaping: a copyright reading `{post_title}` and one reading `{echo:phpinfo}` survive as literal text — through `line`, through `{sfx_media_copyright}`, and through a `sfx_media_credits_line` filter that tries to reintroduce braces
- a missing attachment returns the full array shape with empty parts, not a bare string
- the label map survives a filter that adds keys **and** one that drops them
- `force_wrapper` sets both the `tag` setting and the element's `tag` property
- substitution touches `captionCustom` and leaves `altText` and `_cssClasses` alone

`ponytail:` these stubs prove our logic, not Bricks'. They cannot prove that `bricks/element/settings` still runs before `render()` in a future Bricks release — stubs written from the spec reproduce the spec's assumptions. The implementation plan therefore ends with manual verification in a real Bricks install: caption tag, caption auto-output, overlay with and without a wrapper, a Sources image, and a builder canvas reload.

## Out of scope

| Left out | Add when |
|---|---|
| Custom Bricks element | The tag in a caption proves not to be enough |
| Per-site editable label list | A client needs wording the `sfx_media_credits_labels` filter cannot reach |
| Bulk edit for existing media | A migration actually needs it; the list filter already finds the gaps |
| schema.org `copyrightNotice` | Someone asks for it |
| Builder canvas warning | The settings-page hint proves insufficient |
| Frontend credit for external-URL images | Never — there is no attachment to read meta from |
| Credit for a dynamic image source that resolves to a URL rather than an id | Never, cheaply — Bricks leaves `id` at `0` there (image.php:738-760), and recovering it would mean a DB lookup per image |
| Per-`<source>` credits on responsive images | Never — Bricks emits `<source>` manually (image.php:912-951); the credit follows the main image, so a breakpoint can show a different picture than the one credited. Document, don't build |
| Page-cache invalidation when a credit changes | A site actually runs a page cache and a stale disclosure is observed |
| Multisite (`switch_to_blog`, network-wide purge) | The theme is used on single sites; the per-request memoisation and the purge both assume that |
| Overlay in the builder's single-element re-render | Never — Bricks' AJAX path does not apply `bricks/frontend/render_element` (ajax.php:885-891). Caption mode has no such gap |
| Avoiding the double resolution of a dynamic image source | Never — the settings filter and `render()` both call `get_normalized_image_settings()` (image.php:778). Caching it would mean holding per-element state again, which is what pass 1 removed. Providers are assumed deterministic |
