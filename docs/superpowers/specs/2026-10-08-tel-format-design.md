# `@format:tel` for every Bricks tag — Design

New module `TelFormat`, plus a shared root-level class `SFX\TelNormalizer`. Verified
against Bricks 2.4.2 and the local site (2026-10-08). No story cited — unprofiled.

## Goal

`{contact_info:phone@format:tel}` (PRs #49/#50) gives a clean number for a `tel:` link.
Other tags have no such option: a Bricks link `tel:{acf_phone}` renders
`href="tel:0151 15921554"`, `tel:{acf_job_general_contacts_phone}` renders
`href="tel:0208 / 207 658 0"`. RFC 3966 allows neither the spaces nor the slash.

The same attribute, `@format:tel`, works on **every** Bricks dynamic tag:

```
tel:{acf_phone @format:tel}                     → tel:+4915115921554
tel:{acf_job_general_contacts_phone @format:tel} → tel:+492082076580
tel:{contact_info:phone @format:tel}            → unchanged behaviour
```

## Decisions settled with Daniel (2026-10-08)

- **Opt-in by attribute only.** Nothing is cleaned automatically; a tag without
  `@format:tel` renders exactly as today. Gutenberg and hand-written links are not
  touched.
- **One solution:** one attribute, one normaliser, for our own tags and for Bricks' own
  (ACF and every other provider). No new placeholder.
- **Not tied to Bricks internals:** only the documented hooks for custom dynamic data
  (`bricks/dynamic_data/render_content`, already used by ContactInfos and
  SocialMediaAccounts) and the public helper `bricks_render_dynamic_data()`
  (`bricks/functions.php:325`). The undocumented `bricks/dynamic_data/format_value` and
  `bricks/dynamic_data/allowed_keys` are deliberately not used.
- **Uniform spelling, Bricks' own:** a space before each `@` attribute, as in Bricks'
  `{tag @fallback:'…'}`. Every example, help text and picker entry uses it. The parsers
  keep accepting the old spelling without a space.
- **Backward compatible**, see the section below. No option, no database change.

An earlier version of this spec (automatic rewriting of every `href="tel:…"` in the
rendered HTML) was dropped after Gate A pass 1 and the discussion above.

## Evidence from the local site

Probe against coach post 135 (`phone` = `+49-(0)-171-1700557`), Bricks 2.4.2, no theme
change applied:

- `bricks_render_dynamic_data('tel:{acf_phone @format:tel}', 135, 'link')` returns the
  input **unchanged** — Bricks does not recognise a tag carrying an unknown `@` key and
  leaves it as literal text. The same holds without the space and in `text` context. So
  no existing page can be relying on `@format:tel` on a non-contact tag: today it shows
  the raw tag.
- `bricks/dynamic_data/render_content` at priority 9 receives the whole string with the
  tag intact, before Bricks' own resolver at priority 10
  (`bricks/includes/integrations/dynamic-data/providers.php:148`).
- `bricks/dynamic_data/render_tag` is **not** called for such a tag, so the content hook
  alone is enough.
- A throwaway prototype of the hook below turned `tel:{acf_phone @format:tel}` into
  `tel:+491711700557`, with and without the space, in `text` and `link` context; an
  empty field (`acf_fax`) gave `tel:`.

## Components

### `inc/TelNormalizer.php` — `SFX\TelNormalizer`

`public static function normalize_tel(string $value): string`, moved from
`SFX\ContactInfos\Shortcode\SC_ContactInfos` **unchanged in behaviour**, docblock
included. The dead `$ext = '';` and its duplicated comment (two lines before
`$ext_raw = '';`) are dropped — `$ext` is assigned again before any read.

- The country-code filter keeps its name, `sfx_contact_info_default_country_code`.
- Root-level shared service like `inc/AccessControl.php`, so ContactInfos → TelNormalizer
  and TelFormat → TelNormalizer are not module-to-module edges.

`SC_ContactInfos::normalize_tel()` **stays**, as a one-line delegation to
`TelNormalizer::normalize_tel()`. It is public and static; site code may call it, and
removing it would fatal there. Its own two call sites call `TelNormalizer` directly.

### `inc/TelFormat/Controller.php` — `SFX\TelFormat\Controller`

Always-on module: `get_feature_config()` without an activation key, `hook => null`,
`show_in_theme_settings => false` (nothing to configure). The constructor registers one
filter:

```php
add_filter('bricks/dynamic_data/render_content', [self::class, 'render_content'], 9, 3);
```

`public static function render_content($content, $post, $context = 'text')`:

1. `$content` not a string, or no `@format:tel` in it (`stripos`) → return unchanged.
2. `preg_replace_callback` over tags of the form

   ```
   /\{([^{}]*?)\s*@format:tel(?=[\s@}])([^{}]*)\}/i
   ```

   - The tag body without the `@format:tel` segment is `trim($1 . $2)`; other attributes
     (`@fallback:'…'`) stay with it.
   - `[^{}]` keeps nested tags (`{echo:fn({acf_x})}`) out — they are left as they are.
   - The lookahead stops `@format:telefax` and similar from matching.
3. Per match:
   - Body starts with `contact_info:` → return the match **unchanged**. ContactInfos
     parses its own attributes (priority 20) and already handles `format:tel`.
     Resolving it here would return the rendered phone HTML (link markup included) and
     the normaliser would read digits out of the markup.
   - Otherwise `$value = bricks_render_dynamic_data('{' . $body . '}', $post->ID ?? 0, $context)`.
   - Result not a string (array, object) → `''`.
   - Return `TelNormalizer::normalize_tel($value)`. An empty field gives `''`, as
     `{contact_info:…@format:tel}` does.
4. If `preg_replace_callback` returns `null` (PCRE failure) → return the original
   `$content`.

**Why priority 9:** Bricks' resolver runs at 10 and would otherwise see the tag first.
It currently leaves it alone (probe above), but the module must not depend on that.

**Post context:** `render_content` receives the post Bricks resolved for the current
loop or page (`providers.php:930-957`) and `bricks_render_dynamic_data()` re-applies the
same loop logic, so a query loop over coaches resolves each item's own field. Checked
live in the plan.

**Escaping:** the value returns into Bricks' pipeline, which escapes link and text
output itself; `normalize_tel()` output only holds `+`, digits and `;ext=`. Nothing is
echoed by this module (invariant 3 not engaged).

### ContactInfos — spelling only

- Picker entries (`Controller.php:125`): `'{contact_info:' . $field . ' @format:tel}'`.
- Help tab (`HelpTab.php:200,211,232,233,235`): all examples in the space form,
  `|link=false` becomes ` @link:false`. A new line explains that `@format:tel` works on
  any Bricks tag, e.g. `tel:{acf_phone @format:tel}`.
- SocialMediaAccounts help tab (`HelpTab.php:191`): ` @size:small`.
- Code comments that quote the tag (`Controller.php:121,167`,
  `SC_ContactInfos.php:135`) follow the space form.
- Parsers unchanged: both already accept `\s*[@|]\s*` and trim each pair.
- German translations for changed or new help strings in `languages/de_DE.po` / `.mo`.

## Backward compatibility

| What | Before | After |
|---|---|---|
| `{contact_info:…@format:tel}` (no space) | works | works, unchanged |
| `{contact_info:… @format:tel}` (space) | works | works, unchanged |
| `\|link=false` and other pipe forms | work | work; only no longer shown in help |
| `SC_ContactInfos::normalize_tel()` | public | public, delegates |
| filter `sfx_contact_info_default_country_code` | read | read, same name |
| any tag **without** `@format:tel` | Bricks output | identical |
| non-contact tag **with** `@format:tel` | raw tag shown as text | clean number |
| picker entries already inserted in pages | no-space form | still work |

## Testing

`tests/tel-format-test.php`, plain PHP in the style of `tests/contact-info-tel-test.php`,
with a stubbed `bricks_render_dynamic_data()` that records its arguments and returns
fixture values:

1. `tel:{acf_phone @format:tel}` and `tel:{acf_phone@format:tel}` → `tel:+4915115921554`
   for `0151 15921554`; `0208 / 207 658 0` → `+492082076580`.
2. The stub receives `{acf_phone}` (attribute removed), the post ID and the context.
3. Other attributes survive: `{acf_phone @fallback:'x' @format:tel}` → the stub gets
   `{acf_phone @fallback:'x'}`.
4. `{contact_info:phone @format:tel}` → returned unchanged, stub not called.
5. No `@format:tel`, `@format:telefax`, nested tag → unchanged, stub not called.
6. Empty field → `''`; array from the stub → `''`.
7. Two tags in one string, plus surrounding text → both replaced, text intact.
8. Non-string `$content` → returned as is.

Also:
- `tests/contact-info-tel-test.php` calls `TelNormalizer::normalize_tel()`, keeps one
  assertion through `SC_ContactInfos::normalize_tel()` (the delegation), and adds
  `{contact_info:phone:310 @format:tel}` and `@link:false @wrap:true` (space form) next to
  the existing no-space cases, so both spellings stay pinned.
- `tests/social-bricks-dynamic-data-test.php` requires `inc/TelNormalizer.php` (it loads
  `SC_ContactInfos` directly).
- `tests/contact-social-help-tab-test.php` expects the space form.

Live check on the local site (plan): a Bricks button with `tel:{acf_phone @format:tel}`
on a coach page and inside a coach query loop; view source shows the clean `href` and
the visible text unchanged.

## Docs

`AGENTS.md`: "What this is" names the module (`@format:tel` for dynamic tags); the
root-level shared services list gains `inc/TelNormalizer.php`.
