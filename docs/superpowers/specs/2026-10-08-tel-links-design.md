# Tel links — Design

New module `TelLinks`, toggled by `enable_tel_links` in `sfx_general_options`, plus a
shared root-level class `SFX\TelNormalizer`. Verified against Bricks 2.4.2 in this
checkout (2026-10-08). No story cited — unprofiled.

## Goal

`normalize_tel()` (PRs #49/#50) cleans phone numbers for `tel:` links, but only where the
Contact Infos module builds the link. Every other `tel:` link reaches the page as it was
typed: a Bricks link `tel:{acf_phone}` renders `href="tel:0151 15921554"`,
`tel:{acf_job_general_contacts_phone}` renders `href="tel:0208 / 207 658 0"`, and Gutenberg
or hand-written links carry the same spaces and slashes. RFC 3966 allows neither. The
theme cleans every `href="tel:…"` in rendered HTML, whatever produced it.

## Decisions settled with Daniel (2026-10-08)

- Own module (`inc/TelLinks/`), not hooks inside `GeneralThemeOptions` — rewriting page
  content is not a theme-options concern, and a separate module keeps the coupling count
  unchanged.
- The normaliser moves to a shared class; it is not duplicated.
- On by default, switchable per site in the theme options, plus a filter for code.
- Only `href` changes. Link text, stored values (ACF fields, contact infos) and the
  behaviour of `{contact_info:…@format:tel}` stay as they are.
- Unquoted `href=tel:…` and attributes other than `href` are out of scope: Bricks and
  Gutenberg always quote.

## Components

### `inc/TelNormalizer.php` — `SFX\TelNormalizer`

Holds `public static function normalize_tel(string $value): string`, moved from
`SFX\ContactInfos\Shortcode\SC_ContactInfos` **unchanged in behaviour**, docblock
included. While moving, the dead `$ext = '';` and its duplicated comment (two lines
before `$ext_raw = '';`) are dropped — `$ext` is assigned again before any read.

- The country-code filter keeps its name, `sfx_contact_info_default_country_code`.
  Renaming it would silently break sites that set it.
- `SC_ContactInfos` calls `\SFX\TelNormalizer::normalize_tel()` at both call sites
  (`format="tel"` and `render_phone_field()`); its own method is removed, no wrapper.
- `tests/contact-info-tel-test.php` requires the new file and calls the new class.
- Root-level shared service, like `inc/AccessControl.php`: available to every module, so
  ContactInfos → TelNormalizer and TelLinks → TelNormalizer are not module-to-module
  edges.

### `inc/TelLinks/Controller.php`

Toggle-only module in the `NavMenuQuery` shape: registers hooks, holds no logic.

```php
get_feature_config(): [
  'class' => self::class,
  'activation_option_name' => 'sfx_general_options',
  'activation_option_key'  => 'enable_tel_links',
  'option_value' => true, 'hook' => null,
  'error' => 'Missing TelLinks Controller class in theme',
]
```

Constructor:

```php
add_filter('bricks/frontend/render_data', [Rewriter::class, 'filter_html'], 20);
add_filter('the_content',                 [Rewriter::class, 'filter_html'], 20);
```

**Priority 20 on both, deliberately.** Bricks resolves dynamic data (`{acf_phone}`) in its
own `bricks/frontend/render_data` callback at priority 10
(`bricks/includes/integrations/dynamic-data/providers.php:146`); before that the href
still reads `tel:{acf_phone}`. On `the_content`, `do_shortcode` runs at 11, so 20 also
covers shortcode output.

Coverage of the Bricks hook (`bricks/includes/frontend.php:990`, inside
`Frontend::render_data()`): header, content, footer and popup areas, and the AJAX/REST
re-renders (query-loop pagination, filters, popup content) all go through it. Nested
renders call it again; the rewrite is idempotent, so that only costs the `stripos` check.

### `inc/TelLinks/Rewriter.php` — `SFX\TelLinks\Rewriter`

`public static function filter_html(mixed $html): mixed`

1. Not a string → return it untouched (filters can receive anything).
2. `apply_filters('sfx_normalize_tel_links', true)` is falsy → return unchanged. Checked
   per call, so code can switch it off for one request or one area.
3. `stripos($html, 'tel:') === false` → return unchanged. **`stripos`, not the task's
   `strpos`**: the scheme is case-insensitive (`TEL:` is valid), and a case-sensitive
   pre-check would skip HTML the regex below would have matched. Same cost.
4. One `preg_replace_callback` over the attribute:

   ```
   /(?<![\w-])(href\s*=\s*)(?:"\s*(tel:)([^"]*)"|'\s*(tel:)([^']*)')/i
   ```

   - `(?<![\w-])` keeps `data-href`, `xhref` and similar out.
   - The quote character is kept as found; only the part after `tel:` is replaced.
5. Per match, with `$raw` the value after `tel:`:
   - `$decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')` —
     `&nbsp;` becomes U+00A0 and `&#32;` a space, which `normalize_tel()` already treats
     as separators.
   - `$decoded = rawurldecode($decoded)` — `tel:0151%2015921554` must not keep `20` as
     digits. See "Open point" below.
   - `$clean = TelNormalizer::normalize_tel($decoded)`.
   - `$clean === ''` (no digits) → return the match unchanged (`tel:` alone, an unresolved
     `tel:{acf_phone}` with an empty field).
   - `$clean === $raw` → return the match unchanged (already clean, byte for byte).
   - Otherwise → the same attribute with the value replaced by `esc_attr($clean)`
     (escaped at output, invariant 3; the value only contains `+`, digits and `;ext=`).
   - Scheme spelling: an unchanged match keeps its `TEL:`; a rewritten one keeps the
     spelling found too — only the value after the colon is touched.

Examples (from the task):

| href in | href out |
|---|---|
| `tel:0151 15921554` | `tel:+4915115921554` |
| `tel:0208 / 207 658 0` | `tel:+492082076580` |
| `tel:+4915115921554` | unchanged |
| `tel:0151&nbsp;15921554` | `tel:+4915115921554` |
| `tel:` / `tel:abc` | unchanged |
| `mailto:a@b.de`, `https://…` | unchanged |

**Not touched:** visible link text and every other attribute; `href=\"tel:…\"` inside
JSON in a `<script>` (the backslash before the quote does not match `\s*"`), and escaped
markup in text (`&lt;a href=…`) — neither is a real link.

### Theme option

`GeneralThemeOptions\Settings::get_fields()` gets, after `enable_editor_prose`:

```php
'id' => 'enable_tel_links',
'label' => __('Clean phone links', 'sfxtheme'),
'description' => __('Removes spaces, slashes and other characters from every tel: link on the frontend, so phones can dial it. The visible text stays as entered.', 'sfxtheme'),
'type' => 'checkbox', 'default' => 1, 'group' => 'general',
```

- Existing sites get "on" without re-saving: `is_general_option_enabled()` falls back to
  the field default when the key is missing from the stored array
  (`inc/SFXBricksChildTheme.php:292`).
- No new option key: `sfx_general_options` is already in the `DataPurge` ownership list
  and already exported by ImportExport.
- `ThemeSettingsOverview\OverviewProvider::build_builtin_modules_group()` lists it as
  `'enable_tel_links' => ['label' => __('Clean phone links', 'sfxtheme')]` — this adds
  to an existing mirror of option ids, not a new module edge.
- German translations for the two new strings go into `languages/de_DE.po` / `.mo` like the
  other module labels.

### Switching off

- **Option off** → the registry never constructs `TelLinks\Controller`, no hook is added,
  HTML passes through untouched.
- **Filter** `sfx_normalize_tel_links` returning `false` → hooks stay, `filter_html()`
  returns its input unchanged.

## Testing — `tests/tel-links-test.php`

Plain PHP in the style of `tests/contact-info-tel-test.php`; stubs for `apply_filters`
and `esc_attr`; requires `TelNormalizer.php` and `TelLinks/Rewriter.php`.

1. Bricks-shaped HTML with several `tel:` links in different formats (spaces, slash,
   `(0)`, `00`, single quotes, `TEL:`) → each href cleaned, the rest of the string
   byte-identical.
2. Already clean links → output identical to input.
3. Link text unchanged (`>0151 15921554</a>` still present verbatim).
4. `mailto:`, `https:`, `data-href="tel:…"` untouched.
5. Entities in the href: `&nbsp;`, `&#32;`, `&#x20;` → cleaned.
6. `tel:` alone, `tel:abc` → unchanged.
7. No `tel:` in the string → returned as is; non-string input → returned as is.
8. Switched off:
   - `sfx_normalize_tel_links` → `false`: HTML returned unchanged.
   - Option off: `Controller::get_feature_config()` names `sfx_general_options` /
     `enable_tel_links`, and the real `Settings::get_fields()` source carries
     `enable_tel_links` with `'default' => 1` (same source-read pattern as the
     `enable_editor_prose` check in `tests/theme-settings-overview-provider-test.php`).
     The registry's gating itself is existing, shared code and is not re-tested here.
9. Idempotence: `filter_html(filter_html($x)) === filter_html($x)`.

Also updated: `tests/contact-info-tel-test.php` (new class), and
`tests/theme-settings-overview-provider-test.php` gets `enable_tel_links` active by
default and inactive when set to 0.

Live check, local site: one Bricks page with an ACF phone link and one Gutenberg post
with a hand-written link; view source shows the cleaned href and the original text.

## Docs

`AGENTS.md`: "What this is" names the module ("phone link cleanup"); the root-level
shared services list gains `inc/TelNormalizer.php`.

## Open point for Daniel

`rawurldecode` is an addition beyond the task. Without it, `tel:0151%2015921554` (a
percent-encoded space, which some editors produce) becomes `+49151201592…` — a wrong
number, worse than an untidy one. With it, a literal `%` followed by two hex digits in a
phone field would be read as an encoding, which no real number contains.
