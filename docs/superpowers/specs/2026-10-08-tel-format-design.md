# `@format:tel` for simple Bricks tags — Design

New module `TelFormat`, plus a shared root-level class `SFX\TelNormalizer`. Verified
against Bricks 2.4.2 and the local site (2026-10-08). No story cited — unprofiled.

## Goal

`{contact_info:phone@format:tel}` (PRs #49/#50) gives a clean number for a `tel:` link.
Other tags have no such option: a Bricks link `tel:{acf_phone}` renders
`href="tel:0151 15921554"`, `tel:{acf_job_general_contacts_phone}` renders
`href="tel:0208 / 207 658 0"`. RFC 3966 allows neither the spaces nor the slash.

The same attribute, `@format:tel`, works on simple Bricks tags:

```
tel:{acf_phone @format:tel}                       → tel:+4915115921554
tel:{acf_job_general_contacts_phone @format:tel}  → tel:+492082076580
tel:{contact_info:phone @format:tel}              → unchanged behaviour (own parser)
```

## Decisions settled with Daniel (2026-10-08)

- **Opt-in by attribute only.** Nothing is cleaned automatically; a tag without
  `@format:tel` renders exactly as today. Gutenberg and hand-written links are not
  touched.
- **One attribute, one normaliser** for our own tags and Bricks' tags. No new
  placeholder.
- **Not tied to Bricks internals:** only the documented hooks for custom dynamic data
  (`bricks/dynamic_data/render_content`, `bricks/frontend/render_data` — the pair
  ContactInfos and SocialMediaAccounts already use) and the public helper
  `bricks_render_dynamic_data()` (`bricks/functions.php:325`). The undocumented
  `bricks/dynamic_data/format_value` and `bricks/dynamic_data/allowed_keys` are not used,
  and Bricks' tag parser is not re-implemented.
- **Simple tags only:** a tag name followed by `@format:tel` and nothing else. Any tag
  with other filters (`:plain`), other attributes (`@fallback:…`), quotes or nesting is
  left exactly as it is today. This is what keeps every currently working tag unchanged
  (see "Why simple tags only").
- **Uniform spelling, Bricks' own:** a space before each `@` attribute, as in Bricks'
  `{tag @fallback:'…'}`. Every example, help text and picker entry uses it. The parsers
  keep accepting the old spelling without a space.
- **Backward compatible**, see the table below. No option, no database change.

History: a first version of this spec rewrote every `href="tel:…"` in the rendered HTML
automatically; a second allowed `@format:tel` on any tag in any combination. Both were
dropped — the first is automatic, the second collided with tags that already work and
would have needed a copy of Bricks' parser.

## Why simple tags only

Bricks' parser recognises a fixed list of `@` keys (`dynamic-data-parser.php:346-350`;
`format` is not among them). What happens today to a tag carrying `@format:tel`:

- **Alone** (`{acf_phone @format:tel}`): Bricks does not recognise the tag and leaves it
  as literal text (local probe, below). No page can rely on this output.
- **After a known key** (`{acf_phone @fallback:'x' @format:tel}`): the fallback value
  swallows the rest up to the closing brace (`dynamic-data-parser.php:95-125`), the tag
  resolves, and the page shows the phone today. Rewriting it would change working output.
- **On our own tags** (`{social_account:url:123 @format:tel}`): the theme's parsers drop
  unknown attributes and the tag works today.

Matching only `{name @format:tel}` touches exactly the first case.

## Evidence from the local site

Probe against coach post 135 (`phone` = `+49-(0)-171-1700557`), Bricks 2.4.2, no theme
change applied:

- `bricks_render_dynamic_data('tel:{acf_phone @format:tel}', 135, 'link')` returns the
  input unchanged; the same without the space and in `text` context.
- `bricks/dynamic_data/render_content` at priority 9 receives the string with the tag
  intact, before Bricks' resolver at priority 10 (`providers.php:148`).
- A throwaway prototype of the hook below turned `tel:{acf_phone @format:tel}` into
  `tel:+491711700557`, with and without the space, in `text` and `link` context; an empty
  field (`acf_fax`) gave `tel:`.

## Components

### `inc/TelNormalizer.php` — `SFX\TelNormalizer`

`public static function normalize_tel(string $value): string`, moved from
`SFX\ContactInfos\Shortcode\SC_ContactInfos` **unchanged in behaviour**, docblock
included. The dead `$ext = '';` and its duplicated comment (two lines before
`$ext_raw = '';`) are dropped — `$ext` is assigned again before any read.

- The country-code filter keeps its name, `sfx_contact_info_default_country_code`.
- Root-level shared service like `inc/AccessControl.php`, so ContactInfos → TelNormalizer
  and TelFormat → TelNormalizer are not module-to-module edges.

`SC_ContactInfos::normalize_tel()` **stays**, as a one-line delegation. It is public and
static; site code may call it, and removing it would fatal there. Its own two call sites
call `TelNormalizer` directly.

### `inc/TelFormat/Controller.php` — `SFX\TelFormat\Controller`

Always-on module: `get_feature_config()` without an activation key, `hook => null`,
`show_in_theme_settings => false` (nothing to configure). The constructor registers:

```php
add_filter('bricks/dynamic_data/render_content', [self::class, 'render_content'], 9, 3);
add_filter('bricks/frontend/render_data',        [self::class, 'render_data'], 9, 2);
```

Both hooks, because `Frontend::render_data()` hands whole element output to Bricks'
resolver through `bricks/frontend/render_data` directly (`providers.php:146`,
`frontend.php:990`), without passing `render_content`. Priority 9 on both: Bricks' own
callbacks run at 10.

`render_data($content, $post)` calls `render_content($content, $post, 'text')`.

`public static function render_content($content, $post, $context = 'text')`:

1. `$content` not a string, or no `@format:tel` in it (`strpos`, case-sensitive like
   ContactInfos' own `format === 'tel'`) → return unchanged.
2. `preg_replace_callback` with

   ```
   /\{([a-zA-Z0-9_-]+)\s*@format:tel\}/
   ```

   - The name allows no `:`, so `contact_info:…`, `social_account:…` and any tag with
     Bricks filters (`:plain`, `:raw`, `:tel`) never match; neither do quotes, other
     `@` keys, nested braces or a repeated `@format:tel`.
   - `@format:telefax` does not match (`}` must follow `tel`).
3. Per match, with `$name` from the capture:
   - `$value = bricks_render_dynamic_data('{' . $name . '}', $post->ID ?? 0, $context)`.
   - **Leave the tag unchanged** (return the full match) when:
     - `$value` is not a string;
     - `$value` still contains `{` — unresolved or unknown tag; normalising would dial
       digits from the tag name (`{acf_phone2}` → `2`);
     - `$value` contains `<` — the provider returned markup; digits from `href` and text
       would be merged.
   - Otherwise return
     `TelNormalizer::normalize_tel(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))`.
     Decoding first, because `normalize_tel()` reads the first `;` as the start of URI
     parameters and `&nbsp;` / `&#160;` would cut the number. An empty field gives `''`,
     as `{contact_info:…@format:tel}` does.
4. `preg_replace_callback` returns `null` (PCRE failure) → return the original `$content`.

**Post context:** `render_content` receives the post Bricks resolved for the current
loop or page (`providers.php:930-957`), and `bricks_render_dynamic_data()` re-applies the
same loop logic. Checked live in the plan with distinguishable loop items.

**Recursion:** the inner call carries `{name}` without `@format:tel`, so it cannot match
again.

**Output:** the value goes back into Bricks' pipeline. In link context Bricks escapes
attributes; text elements such as Text Basic echo content as is
(`bricks/includes/elements/text-basic.php:106`). Safety therefore rests on the output
alphabet: `normalize_tel()` returns only `+`, digits and `;ext=`. A test pins that
alphabet, so a later change to the normaliser cannot silently break the argument.

**Known limit — builder canvas:** the builder previews single-tag fields through
`Providers::render_tag()` (`builder.php:2965`), which this module does not hook, so the
canvas shows the raw tag there. Frontend output is unaffected. ContactInfos has the same
limit; `render_tag` is left alone because a return value at priority 9 would be fed to
Bricks' resolver as a tag name.

### ContactInfos — spelling only, behaviour unchanged

- `@format:tel` on contact tags keeps its existing scope: only `phone`, `mobile`, `fax`
  (`SC_ContactInfos.php:137`); other fields ignore it, as today.
- Picker entries (`Controller.php:125`): `'{contact_info:' . $field . ' @format:tel}'`.
- Help tabs, ContactInfos (`HelpTab.php`) and SocialMediaAccounts (`HelpTab.php`): every
  tag example in the space form, pipe forms (`|link=false`) replaced by ` @link:false`,
  and the prose that explains the attribute syntax (ContactInfos `HelpTab.php:207`,
  SocialMediaAccounts `HelpTab.php:187`) rewritten to describe the space form only. One
  new line: `@format:tel` also works on simple Bricks tags, e.g.
  `tel:{acf_phone @format:tel}`.
- Code comments quoting the tag (`Controller.php:121,167`, `SC_ContactInfos.php:135`)
  follow the space form.
- Parsers unchanged: both accept `\s*[@|]\s*` and trim each pair.
- German translations for changed or new strings in `languages/de_DE.po` / `.mo`.

## Backward compatibility

| What | Before | After |
|---|---|---|
| `{contact_info:…@format:tel}` (no space) | works | works, unchanged |
| `{contact_info:… @format:tel}` (space) | works | works, unchanged |
| `\|link=false` and other pipe forms | work | work; no longer shown in help |
| `SC_ContactInfos::normalize_tel()` | public | public, delegates |
| filter `sfx_contact_info_default_country_code` | read | read, same name |
| any tag without `@format:tel` | Bricks output | identical |
| `{name @format:tel}` (simple tag) | raw tag shown as text | clean number |
| `{name @fallback:… @format:tel}`, `{name:filter @format:tel}` | resolves, attribute ignored | identical |
| `{social_account:… @format:tel}` | resolves, attribute ignored | identical |
| picker entries already inserted in pages | no-space form | still work |

## Testing

`tests/tel-format-test.php`, plain PHP in the style of `tests/contact-info-tel-test.php`.
A stubbed `bricks_render_dynamic_data()` records every call and returns fixture values.

1. `tel:{acf_phone @format:tel}` and `tel:{acf_phone@format:tel}` with `0151 15921554`
   → `tel:+4915115921554`; `0208 / 207 658 0` → `+492082076580`.
2. The stub receives `{acf_phone}`, the post ID and the context; `render_data()` passes
   `'text'`.
3. Left untouched, stub **not** called: `{acf_phone @fallback:'x' @format:tel}`,
   `{acf_phone:plain @format:tel}`, `{contact_info:phone @format:tel}`,
   `{social_account:url:1 @format:tel}`, `{acf_phone @format:telefax}`,
   `{acf_phone @FORMAT:TEL}`, `{acf_phone @format:tel @format:tel}`,
   `{echo:fn({acf_phone @format:tel})}`'s outer tag (the inner simple tag is replaced —
   asserted explicitly, so the expectation is pinned either way), and a string with no
   `@format:tel` at all.
4. Stub returns `''` → `''`; returns `{acf_phone}` (unresolved) → tag unchanged; returns
   `<a href="tel:1">1</a>` → tag unchanged; returns an array → tag unchanged.
5. Entities: stub returns `0151&nbsp;15921554` and `0151&#160;15921554` →
   `+4915115921554`.
6. Two tags in one string with text around them → both replaced, text byte-identical.
7. Non-string `$content` → returned as is.
8. PCRE failure: `ini_set('pcre.backtrack_limit', '1')` around one call (restored in a
   `finally`) → original content returned.
9. Output alphabet: every replacement in tests 1–6 matches `/^\+?[0-9]*(;ext=[0-9]+)?$/`.

Also:
- `tests/contact-info-tel-test.php` calls `TelNormalizer::normalize_tel()`, keeps one
  assertion through `SC_ContactInfos::normalize_tel()` (the delegation), adds
  `{contact_info:phone:310 @format:tel}` and `@link:false @wrap:true` next to the
  existing no-space cases, and the picker assertion (`:207`) expects the space form.
- `tests/social-bricks-dynamic-data-test.php` requires `inc/TelNormalizer.php` (it loads
  `SC_ContactInfos` directly).
- `tests/contact-social-help-tab-test.php` expects the space form.

**Live check on the local site** (plan; uses real hook dispatch and real Bricks
resolution, fixtures removed in one teardown per AGENTS.md):
- Two coach posts with different phone values and one with an empty phone, rendered in
  a Bricks query loop: a button with link `tel:{acf_phone @format:tel}` and label
  `{acf_phone}`. Each item shows its own clean `href`, the label stays as entered, the
  empty item gives `tel:`.
- The same tag rendered through `bricks/frontend/render_data` (a Basic Text element) and
  through `render_content` in `link` context.
- Counterfactual: the same page with the module's filters removed shows the raw tag.

## Docs

`AGENTS.md`: "What this is" names the module (`@format:tel` for simple dynamic tags);
the root-level shared services list gains `inc/TelNormalizer.php`.
