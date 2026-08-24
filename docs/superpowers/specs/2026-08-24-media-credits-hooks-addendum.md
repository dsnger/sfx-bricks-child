# Media Credits — Hooks & Filters (Addendum)

**Date:** 2026-08-24
**Branch:** `feature/image-credit-fields`
**Status:** concept, not implemented — **revised after Codex Gate A pass 1**
**Parent spec:** `2026-08-24-media-credits-design.md` — read it first; this addendum assumes it and does not restate it.

## Scope

Add extension points to the shipped `inc/MediaCredits/` module so a site can adapt copyright and AI-marking behaviour from code without forking the module, plus a documentation card on the settings page listing them.

The module currently exposes exactly two filters: `sfx_media_credits_labels` (the label vocabulary) and `sfx_media_credits_line` (the finished credit line). Everything else is closed.

Non-goals: a settings UI for any of this, a plugin API, per-hook capability checks, filters on admin-only cosmetics.

## The constraint everything here is subordinate to

The parent spec's headline rule: **every string this module puts into page content passes `Credit::escape_braces()` as its final operation.** Bricks parses the whole assembled document for `{tags}` after every element has rendered (`frontend.php:947`), so an unescaped `{echo:some_function}` reaching the page executes.

Adding filters is precisely where that rule gets broken, because a filter is by definition third-party text arriving late. Every hook below is therefore classified by **which side of the existing gate it fires on**, and that classification decides whether it needs new escaping code.

The existing gate lives at the end of `Credit::for()`:

```php
$line = self::compose(...);
if ($line !== '') {
    $line = (string) apply_filters('sfx_media_credits_line', $line, $attachment_id, $parts);
    $line = self::escape_braces(wp_kses_post($line));   // <- THE GATE
}
return self::$cache[$attachment_id] = $parts + ['line' => $line];
```

- **Upstream of the line gate** → covered *for the composed line only*. See the correction below.
- **Downstream of the gate** → the filter's output reaches the page unprotected. Needs its own `wp_kses_post()` then `escape_braces()`. This is where a mistake is a vulnerability.

### Correction after Gate A pass 1

Pass 1 refuted the first draft's central claim. It said "every composition hook fires upstream of the
gate, therefore anything it returns is caught". That is true for the hooks that only shape `$line`.
It is **false for `sfx_media_credits_parts`**, because `Credit::for()` returns the raw parts array
*alongside* the gated line (`Credit.php:92`), and three consumers read those parts directly, each
with its own separate escaping:

| Sink | Reads | Its own gate |
|---|---|---|
| `Credit::for()['line']` | all parts, composed | `wp_kses_post()` + `escape_braces()` at `Credit.php:89` |
| `Bricks::raw_value()` (dynamic tags) | `copyright`, `ai_label` | `Credit::escape_braces()` per value |
| `Bricks::substitute()` (tag in a caption) | `copyright`, `ai_label` | `escape_braces(esc_html(...))` |
| `Bricks::image_attributes()` | `ai_key` | `esc_attr()`, plus the slug whitelist |

There is **no live brace exploit** — every sink is independently gated today. But the security
argument is per-sink, not one gate, and the tests must enumerate all four rather than assert the
line and infer the rest. That is the correction, and it is why pass 1 called this a blocker: the
proof was wrong even though the code was safe.

## 1 · Composition hooks

Four of these five (`copyright_prefix`, `separator`, `seal_html`, `line`) only ever shape the composed
line, so the existing gate at `Credit.php:89` covers them and they need no new escaping code.
`sfx_media_credits_parts` is the exception — see the correction above; its output reaches four
independently gated sinks.

### `sfx_media_credits_parts`

```php
apply_filters('sfx_media_credits_parts', array $parts, int $attachment_id): array
// $parts keys: copyright (string, raw) | ai_key (string) | ai_label (string) | icon_id (int)
```

Fires after the meta and the global fallback have been resolved, before composition. The single most useful hook: it lets a site source the copyright from ACF, force a label across a taxonomy, or blank a credit for a whole class of images.

**Validation on return must preserve the AI tuple's coherence.** The shipped code derives `ai_key`,
`ai_label` and `icon_id` together from one validated key (`Credit.php:68-74`). Letting a filter change
one and fall back independently on the others breaks that, and pass 1 showed the exact failure: a
filter returning an invalid `ai_key` without an `ai_label` would clear the key — so `data-sfx-ai`
disappears and the composed AI part vanishes — while the *previous* label survives and is still
disclosed through `{sfx_media_ai_label}`. One image would then say two different things.

The rule is therefore:

1. Non-array return → discard entirely, use the unfiltered parts.
2. `copyright` → cast to string; absent → unfiltered value.
3. `ai_key` → whitelist against `Settings::get_labels()`; unknown → `''`.
4. **If the resulting `ai_key` differs from the unfiltered one**, re-derive `ai_label` and `icon_id`
   from the new key exactly as `Credit::for()` does — *unless* the filter supplied that key explicitly,
   in which case the supplied value is used (cast to string / int).
5. `ai_key === ''` forces `ai_label = ''` and `icon_id = 0`, whatever the filter supplied. An empty
   marking cannot carry a label or a seal.

A filter that wants a label without a key is asking for a disclosure the machine-readable attribute
will not carry. That is refused rather than accommodated.

**Memoisation interaction:** the filtered parts are what gets cached in `Credit::$cache`. That is correct — the cache is per request and per attachment id — but it means a filter that varies its return by something other than the attachment id (the current loop position, say) will observe only its first result. Documented, not defended against.

### `sfx_media_credits_copyright_prefix`

```php
apply_filters('sfx_media_credits_copyright_prefix', string $prefix, string $copyright, int $attachment_id): string
// default: '©&nbsp;'
```

Fires in `Credit::with_copyright_prefix()`, only on the branch that actually prepends. The detection of an existing `©` / `(c)` / `Copyright` is deliberately **not** filterable — a site that wants different detection replaces the whole line via `sfx_media_credits_line`.

### `sfx_media_credits_separator`

```php
apply_filters('sfx_media_credits_separator', string $separator, int $attachment_id): string
// default: '&nbsp;·&nbsp;'
```

Fires in `Credit::compose()` immediately before the `implode()`. Only consulted when both parts are non-empty, so a site cannot accidentally introduce a leading separator on a one-part credit.

### `sfx_media_credits_seal_html`

```php
apply_filters('sfx_media_credits_seal_html', string $html, int $icon_id, string $ai_key, int $size, int $attachment_id): string
```

Fires in `Credit::ai_part()` after the `<img>` is built, only when a seal is actually being rendered (`credit_display` is `icon` or `icon_text` **and** the seal attachment resolves).

`$attachment_id` — the *credited* attachment, not the seal — is in the signature because pass 1 noted
this was the only per-attachment composition filter without it, and markup may legitimately depend on
the image being credited rather than on the seal asset. Added now rather than in a later signature bump.

**Inline SVG is not a use case for this hook, contrary to the first draft.** Pass 1 checked the actual
allowlist: `wp_kses_post()` applies the post-content element list (`wp-includes/kses.php:2502-2503`),
which contains neither `svg` nor `path` (`kses.php:68-420`). An SVG return is stripped outright, not
merely de-scripted. The honest use is alternate *allowed* HTML — a `<picture>`, a wrapper with extra
classes, a `<span>` glyph. A site that genuinely needs inline SVG must widen the allowlist itself,
which is its decision to make and not something this module should do on its behalf.

### `sfx_media_credits_line` *(exists)*

Unchanged. Remains the last word before the gate.

## 2 · Output hooks — the ones that need new escaping

### `sfx_media_credits_should_auto_output`

```php
apply_filters('sfx_media_credits_should_auto_output', bool $should, string $mode, int $attachment_id, $element): bool
// $mode: 'caption' | 'overlay'
```

Fires in **three** places, not two:

- `Bricks::element_settings()`, caption branch — before the caption is composed.
- `Bricks::element_settings()`, **overlay branch** — before `force_wrapper` sets `$settings['tag']`.
- `Bricks::render_element()` — before the overlay is composed and injected.

The overlay branch is the one pass 1 caught. `force_wrapper` writes the `tag` setting at settings time
(`Bricks.php:269-270`) while the overlay is injected much later at render time (`Bricks.php:459-461`).
A filter consulted only at render time would suppress the credit but leave a `figure` wrapper that
exists solely to hold it — a layout change for nothing. The decision must be taken at settings time
too, and it must be consistent: the same inputs must produce the same answer in both calls, which is
why the filter receives the attachment id and the element rather than any per-phase state.

This is the hook the module most visibly lacks today: the only current escape hatch is the `no-credit` CSS class, which requires editing every element by hand. Real cases it covers: no credits inside a header or footer template, none on a specific post type, none for images below a size threshold.

Cast to bool on return. No escaping concern — it returns a boolean, and a truthy string cannot become markup.

**It must not remove `data-sfx-ai`.** The parent spec is explicit that the machine-readable marking is not a design decision and survives `no-credit`; the same reasoning applies here. `Bricks::image_attributes()` does not consult this filter.

### `sfx_media_credits_caption_auto_html` — **downstream of the gate**

```php
apply_filters('sfx_media_credits_caption_auto_html', string $html, int $attachment_id, array $settings): string
// default: '<span class="sfx-credit">' . $line . '</span>'
```

Fires in `Bricks::element_settings()` after the marker span is assembled, before it is written into `captionCustom`.

**The name carries the `_auto_` because the scope is narrower than "the caption".** Pass 1 caught this:
a credit can reach a caption two ways — auto-output composes the whole line into a marker span
(`Bricks.php:300-303`), and a hand-placed `{sfx_media_credit}` is substituted per tag with its own
separate span (`Bricks.php:311-333`). This hook covers only the first. Naming it `caption_html` would
promise consistent caption markup and not deliver it. The manual path is deliberately left unhooked:
the two wrap different things — a composed line versus one substituted tag value — and a shared hook
would have to pretend they are the same.

**This is a page-content sink.** `captionCustom` is copied verbatim into the caption (`image.php:805-806`) and never passed through `render_dynamic_data()`, so whatever a filter returns here lands in the assembled document and is then parsed by Bricks for `{tags}`. The implementation **must** apply `wp_kses_post()` then `escape_braces()` to the filter's return, in that order, mirroring the gate.

`escape_braces()` is idempotent on brace-free input, and `$line` arrives already escaped, so re-running it costs nothing and cannot double-encode (`escape_braces` maps only `{`/`}`, and its own output contains neither).

**The marker class is enforced, not delegated.** If a filter returns markup without
`class="sfx-credit"`, the dedup rule stops seeing it and the same image can acquire a second credit —
both the caption path (`Bricks.php:290`) and the overlay path (`Bricks.php:449`) key off `has_marker()`.

The first draft proposed documenting this as the filter author's responsibility. **Pass 1 refuted that
and it is withdrawn.** The decisive argument: `should_auto_output` already exists as the dedicated,
explicit way to suppress a credit, so a markerless return is far more likely an accident than an
intention — and the module should not let an accident silently invalidate its own bookkeeping.

The order on return is therefore:

1. `wp_kses_post()` — a filter cannot add script.
2. If the result is non-empty and carries no `sfx-credit` class token, wrap it in the marker span.
3. `escape_braces()` — **last**, so neither the filter nor the wrapping can reintroduce a Bricks tag.

An **empty** return falls back to the module's own markup rather than suppressing output, because
suppression has its own hook and an empty string is far more likely a filter bug than a decision.

### `sfx_media_credits_overlay_html` — **downstream of the gate**

```php
apply_filters('sfx_media_credits_overlay_html', string $html, int $attachment_id, string $root_tag): string
// default: '<span class="sfx-credit sfx-credit--overlay">' . $line . '</span>'
```

Fires in `Bricks::inject_overlay()` after the span is built, before it is spliced into the rendered HTML. Same sink, same three-step treatment as `caption_auto_html`: `wp_kses_post()`, wrap if the marker is missing, `escape_braces()` last. An empty return falls back to the module's markup.

### `sfx_media_credits_overlay_skip_tags`

```php
apply_filters('sfx_media_credits_overlay_skip_tags', array $tags): array
// default: ['img', 'picture', 'a']
```

Fires in `Bricks::inject_overlay()` before the root-tag test. Lets a site allow an overlay on a root the module refuses by default, or refuse one it currently allows.

Sanitised on return: non-array discarded, entries cast to string, lowercased, and anything not matching `^[a-z0-9-]+$` dropped — the value is compared against a tag name extracted by regex, so a malformed entry is silently inert rather than dangerous, but validating keeps the comparison honest.

## 3 · Media-library hooks

### `sfx_media_credits_iptc_value`

```php
apply_filters('sfx_media_credits_iptc_value', string $value, array $image_meta, int $attachment_id): string
```

Fires in `MediaLibrary::prefill_iptc()` after `iptc_copyright()` has picked `copyright` or `credit`, before the empty-check and the write. Lets a site read a different IPTC field, normalise agency spellings, or suppress the prefill for a source it does not trust (return `''`).

`sanitize_text_field()` runs on the return, then `wp_slash()` as today. It is written to post meta, not to page content, so the brace boundary does not apply here — the escaping happens on read, in `Credit::for()`.

**The two one-shot guards are not filterable.** Neither the `'create'` context check nor the `_sfx_media_iptc_prefilled` marker gets a hook. The parent spec's reasoning stands: dropping either lets a site-wide thumbnail regeneration write hundreds of attachments, and a filter is exactly how that would happen by accident.

### `sfx_media_credits_saved` *(action)*

```php
do_action('sfx_media_credits_saved', int $attachment_id, string $copyright, string $ai_key, string $context)
// $context: 'save' | 'iptc'
```

Fires after both meta values have been written — from `MediaLibrary::save()` with context `save`, and from `prefill_iptc()` with context `iptc`.

**This is the seam the parent spec deliberately left open.** Page-cache invalidation is listed in that spec's Out of Scope table as "add when a site actually runs a page cache and a stale disclosure is observed". This action is how a site does that without the module knowing anything about caches. That motivation should be in its docblock, because a hook whose purpose is undocumented gets removed by the next person tidying up.

Fires on every save that touches either field, whether or not the value changed. Not de-duplicated:
comparing old and new would mean an extra read on every attachment save to serve a listener that can
compare for itself.

**`Credit::reset_cache()` runs immediately before the action fires.** Pass 1 caught this: `Credit::for()`
memoises per request (`Credit.php:50-52`, `:92`), and neither `save()` nor `prefill_iptc()` invalidates
it, so a listener doing the obvious thing — calling `Credit::for($attachment_id)` to see what changed —
would be handed the pre-save value. A hook whose whole purpose is reacting to a change must not serve
stale data to the reaction. Clearing the whole per-request cache is acceptable here because saves are
rare and the cache is per request; a per-id invalidation would be marginally tighter and is not worth
a second API.

## 4 · Deliberately not hooked

| Not hooked | Why |
|---|---|
| The `data-sfx-ai` attribute name or value | The attribute name *is* the machine-readable contract. Making it filterable defeats the only reason it exists. |
| The media-library column markup | Admin cosmetics. A site that wants a different column adds its own via `manage_media_columns`. |
| The resolved attachment id for an element | Bricks resolves it through its own `get_normalized_image_settings()`. A filter here would let a site point a credit at the wrong image, which is the failure this module exists to prevent. |
| The `©` / `(c)` / `Copyright` detection | A site wanting different detection replaces the line. Two overlapping ways to change one behaviour is worse than one. |
| The one-shot IPTC guards | See above. |
| `Settings::normalize()` / the option whitelists | Validation is not an extension point. |

## 5 · Settings-page documentation card

A card on the Media Credits settings page listing every hook: name, signature, one line on when it fires. Placed in the right-hand column beneath the existing "How output works" card.

Constraints: every string translatable with domain `sfxtheme`; hook names and signatures rendered in `<code>` and **not** translatable, since they are identifiers; the card is reference material, not a tutorial — no code examples beyond the signature, which keeps it maintainable when a signature changes.

The card duplicates what a developer could read in the source. It earns its place because the audience is the site owner's developer, who has the admin page open and does not have the theme source in front of them.

## 6 · Testing

Extends `tests/media-credits-bricks-test.php` and `tests/media-credits-credit-test.php`.

**The escaping cases enumerate the four sinks, not the one gate.** That is the direct consequence of
pass 1's blocker: asserting the composed line and inferring the rest is exactly the reasoning that was
wrong. So `sfx_media_credits_parts` returning `{echo:phpinfo}` in `copyright` is asserted separately at
each sink — through `line`, through `{sfx_media_copyright}` (`raw_value`), through a tag substituted
into a caption (`substitute`), and `ai_key` through `data-sfx-ai`.

Every escaping assertion is an **exact string**, never a `not_contains`: a `not_contains('{echo:')`
passes on double-encoded `&amp;#123;` output, which is visibly broken. This branch already learned that
once — the composed-line case asserts the exact string for the same reason.

The load-bearing cases:

- Each downstream filter (`caption_auto_html`, `overlay_html`) returning `{echo:phpinfo}` produces
  exactly-escaped output; returning `<script>` produces inert output.
- Each of the four `parts` sinks, as above.
- Each upstream filter (`copyright_prefix`, `separator`, `seal_html`) returning `{post_title}` is caught
  by the existing gate — proving the "no new escaping upstream" claim rather than assuming it.
- **Coherence:** `parts` returning an invalid `ai_key` with no `ai_label` yields no label at *any* sink —
  specifically `{sfx_media_ai_label}` must be empty, not the stale previous label. This is pass 1's
  MAJOR finding turned into a test.
- `parts` returning a *different valid* `ai_key` with no `ai_label`/`icon_id` re-derives both from the
  new key, and does not keep the old label or the old seal.
- `parts` returning `ai_key => ''` with a non-empty `ai_label` forces the label empty.
- `parts` returning a non-array leaves the credit byte-identical to unfiltered.
- **`should_auto_output` returning `false` in overlay mode leaves no forced `tag` setting** — the
  settings-time half of pass 1's wrapper finding — *and* injects no overlay at render time, *and*
  leaves `data-sfx-ai` in place.
- `should_auto_output` returning `false` in caption mode leaves `caption` and `captionCustom` untouched.
- A `caption_auto_html` / `overlay_html` filter returning markup **without** the marker class gets it
  wrapped back on, and a second auto-output pass therefore does not double the credit.
- The same filters returning `''` fall back to the module's own markup rather than suppressing.
- `overlay_skip_tags` returning `['figure']` suppresses an overlay on a `figure`; a malformed entry
  neither crashes nor matches.
- `sfx_media_credits_saved` fires once per save with the right context, fires from the IPTC path too,
  and a listener calling `Credit::for()` inside it observes the **new** values, not the cached ones.
- **Backwards compatibility:** with no filters registered at all, every existing assertion in all three
  suites still passes unchanged. This is the cheapest guard against the whole addendum and should be
  stated as an explicit goal, not left implicit in the suite passing.

## 7 · Claims a reviewer should verify one by one

Pass 1 refuted claims 1, 8 and 10 of the first draft. Those are now corrected above; the list below is
what pass 2 should check, and it deliberately includes the corrections themselves.

1. The four-sink table is **complete** — there is no fifth consumer of `Credit::for()`'s raw parts
   anywhere in the module, and each of the four listed gates is what the code actually does today.
2. The `parts` coherence rules (1–5) close the stale-label hole pass 1 found, and do not open a new one:
   in particular that rule 4's "unless the filter supplied that key explicitly" cannot be used to pair a
   valid key with another key's seal in a way that misleads.
3. `should_auto_output` firing in the overlay branch of `element_settings()` genuinely prevents the
   forced wrapper, and taking the decision twice (settings time and render time) cannot disagree with
   itself in a way that produces a wrapper with no credit — or, if it can, under what conditions.
4. The three-step order for the downstream hooks — `wp_kses_post()`, wrap-if-markerless,
   `escape_braces()` — leaves `escape_braces()` genuinely last, and the wrapping step cannot introduce
   a brace after it.
5. Falling back to module markup on an empty return is right, and does not make deliberate suppression
   impossible for a site that has a reason to want it.
6. `Credit::reset_cache()` before `sfx_media_credits_saved` is sufficient, and clearing the whole
   per-request cache has no consequence worse than the stale read it fixes.
7. `wp_kses_post()` really does strip `<svg>` (the corrected `seal_html` rationale), so the hook's
   documented use is now accurate.
8. Splitting the caption paths — hooking auto-output only, leaving the manual substitution unhooked —
   is the right call, or the manual path needs its own hook after all.
9. Nothing in section 4 should be hooked, still.
10. With no filters registered, behaviour is byte-identical to the shipped module. Any place this is not
    true is a blocker.
