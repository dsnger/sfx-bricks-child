# Media Credits — Hooks & Filters (Addendum)

**Date:** 2026-08-24
**Branch:** `feature/image-credit-fields`
**Status:** concept, not implemented — **revised after Codex Gate A passes 1, 2 and 3**
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

The rule is therefore — and pass 2 forced it to be simpler than the first revision:

1. Non-array return → discard entirely, use the unfiltered parts.
2. `copyright` → cast to string; absent → the unfiltered value.
3. `ai_key` → whitelist against `Settings::get_labels()`; anything unknown → `''`.
4. **The validated `ai_key` is authoritative. `ai_label` and `icon_id` are ALWAYS derived from it**,
   exactly as `Credit::for()` does, and any values the filter supplied for them are discarded.
5. Therefore `ai_key === ''` yields `ai_label === ''` and `icon_id === 0`, with no special case needed.

The first revision let a filter supply `ai_label` / `icon_id` explicitly when it changed the key. Pass 2
showed that this re-opened the hole in a new shape: a filter could return `ai_key => 'ai_edited'`
carrying the `ai_generated` seal, and same-key overrides were left unspecified entirely. The escape
clause is withdrawn. It was flexibility nobody asked for, guarding a case nobody described, at the cost
of the one guarantee this validation exists to make.

Little is lost by removing it, and pass 3 was right that the first wording overstated even that. A site
that wants different wording has `sfx_media_credits_labels`; a different seal for a key, the settings; a
different seal markup, `sfx_media_credits_seal_html`. None of those three can desynchronise the tuple.

But all three are **global**: labels and seal ids are per key, not per attachment (`Settings.php:63-72`,
`:114-117`). So two things are genuinely **not** possible and are hereby declared unsupported rather than
covered:

- a per-attachment label variant — one image saying "KI-generiert (Midjourney)" while others say
  "KI-generiert";
- a per-attachment seal where the key's global seal is unset or unusable.

Both are refused deliberately. A per-attachment label that the `data-sfx-ai` attribute cannot carry
would let the visible disclosure and the machine-readable one diverge, which is the failure the tuple
rule exists to prevent. A site that truly needs either can still replace the whole rendered credit
through `sfx_media_credits_line` — which is honest about the fact that it is bypassing the module's
composition rather than extending it.

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
merely de-scripted. The honest use is alternate *allowed* HTML. Pass 2 caught a second wrong example in the first
revision: `<picture>` is stripped too, and so is `<source>`. What survives `wp_kses_post()` and is
actually useful here: `div` (`kses.php:156`), `figure` (`:170`), `img` (`:215`), `span` (`:295`) — so a
wrapper with extra classes, a `<figure>`, or a differently-attributed `<img>`. A site that genuinely needs inline SVG must widen the allowlist itself,
which is its decision to make and not something this module should do on its behalf.

### `sfx_media_credits_line` *(exists)*

Unchanged. Remains the last word before the gate.

## 2 · Output hooks — the ones that need new escaping

### `sfx_media_credits_should_auto_output`

```php
apply_filters('sfx_media_credits_should_auto_output', bool $should, string $mode, int $attachment_id, $element): bool
// $mode: 'caption' | 'overlay'
```

**Evaluated once per element, at settings time, and reused at render time.** The first revision said
"fires in three places". Pass 2 refuted that: Bricks assigns the filtered settings back onto the element
before rendering (`base.php:2948-2953`), so two independent evaluations can legitimately disagree even
for a deterministic callback — one keying on `isset($element->settings['tag'])` answers `true` before
`force_wrapper` writes that key and `false` afterwards, which is precisely the wrapper-with-no-credit
outcome the hook was added to prevent.

The contract is therefore:

- The decision is taken in `Bricks::element_settings()`, once, before the caption branch and before
  `force_wrapper` writes `$settings['tag']`, and memoised **against the element object instance** in a
  request-local `SplObjectStorage`.
- `Bricks::render_element()` **consumes** that memoised decision rather than re-running the filter.
- If no settings-time decision was recorded for an element — the module never saw it in
  `element_settings()` — `render_element()` evaluates once itself.

**The memo key must be object identity, not `$id` or `$uid`.** Pass 3 caught this and it would have been
a live bug in every query loop. Bricks constructs a fresh instance per render —
`new $element_class_name($element)` at `frontend.php:743` — and hands that same instance to
`bricks/frontend/render_element` at `:752`, so the object is stable across the two moments this hook
cares about. But `$this->id` and `$this->uid` come from the stored element definition
(`base.php:74-76`, `$uid = $id`), so a loop rendering one element for twenty posts presents the **same
id twenty times**. Keying on it would freeze the first post's decision for the other nineteen —
silently, and worst in exactly the case (a query loop over attachments) this module most expects.

`SplObjectStorage` keyed by the instance is therefore the mechanism, not an implementation detail left
open. Frontend entries are **removed once consumed** in `render_element()`, so the storage cannot grow
across a long page. Builder-AJAX renders never reach `render_element()` and so never consume their
entry; those are discarded with the request.

Arguments are the attachment id, the mode and the element, so a callback has what it needs without
reaching for per-phase state that changes between the two moments.

**Known and documented:** Bricks' builder AJAX path calls `init()` without ever applying
`bricks/frontend/render_element` (`ajax.php:885-891`), so while editing, a forced wrapper can appear
with no overlay inside it until the canvas reloads. That is the same partial-parity constraint the
parent spec already documents for overlay mode, not a new defect introduced here.

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

Firing contract, made precise after pass 2 found the first wording ambiguous for partial saves:

- From `MediaLibrary::save()`, context `save` — fires when **at least one** of the two fields was present
  in the submitted `attachments[<id>]` payload and therefore written. Not once per field. `save()` writes
  each field only if its key is present (`MediaLibrary.php:222-236`), so "after both were written" was
  never accurate.
- From `MediaLibrary::prefill_iptc()`, context `iptc` — fires **only after an actual copyright write**.
  That path has several earlier no-write returns (both one-shot guards, a non-image, an empty IPTC value,
  a field the editor already filled), and none of them should wake a listener.
- Both arguments always carry the **current post-write values of both fields**, re-read after the writes,
  so a listener never has to guess which one changed or fetch them itself.

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
| A **dedicated** hook on the `data-sfx-ai` attribute name or value | The attribute name *is* the machine-readable contract; making the name filterable defeats the only reason it exists. The **value** has no dedicated hook either — but it is not immutable, and pass 2 was right that the first wording implied otherwise: it follows the validated `ai_key` that `sfx_media_credits_parts` produces. That is the single, coherence-checked route, which is the point. |
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
- **Backwards compatibility, stated precisely.** With no filters registered, *rendered output* is
  byte-identical to the shipped module: same credit line, same caption, same overlay, same attribute.
  Every existing assertion in all three suites passes unchanged.

  **The settings page is explicitly excluded**, and pass 3 was right that the previous wording promised
  otherwise: section 5 adds a documentation card, so admin markup changes by design, with no filters
  registered. That is the intended deliverable, not a compatibility break, and no test should assert
  the settings page is unchanged.

  It is **not** unconditionally byte-identical behaviour, and pass 2 was right to call the first
  revision's blanket claim a contradiction: `Credit::reset_cache()` now runs before
  `sfx_media_credits_saved`, so a read-save-read sequence inside one request returns fresh values where
  it previously returned the pre-save cache. That change happens with zero filters registered. It is
  intentional — the old behaviour was a latent bug that only became reachable once an action existed to
  observe it — and it is the one documented exception to the compatibility rule.

## 7 · Claims a reviewer should verify one by one

Pass 1 refuted claims 1, 8 and 10 of the first draft. Pass 2 then refuted claims 2, 3, 7, 9 and 10 of the
first revision — every one of them a contradiction introduced by pass 1's own fixes. Both rounds are
corrected above. The list below is what pass 3 should check.

1. Making the validated `ai_key` authoritative and **always** deriving `ai_label` and `icon_id` from it
   closes the tuple hole completely, in the changed-key case, the same-key case and the empty case — and
   removing the escape clause takes away no capability the three named alternative routes do not cover.
2. Taking the `should_auto_output` decision **once** at settings time and memoising it per element
   removes the disagreement pass 2 found, and the memoisation key is one that cannot collide between two
   elements or leak between requests.
3. The fallback path — `render_element()` evaluating for itself when no settings-time decision was
   recorded — cannot itself produce the wrapper-with-no-credit outcome.
4. The narrowed compatibility claim is now internally consistent with the required `reset_cache()`, and
   there is no *other* behaviour that changes with zero filters registered that the document has missed.
5. The `data-sfx-ai` wording in section 4 now matches what the code will actually do.
6. The `seal_html` examples that remain (`div`, `figure`, `img`, `span`) genuinely survive
   `wp_kses_post()`, and no third wrong example is left anywhere in the document.
7. The `sfx_media_credits_saved` firing contract is now unambiguous for every path through `save()` and
   `prefill_iptc()`, including the case where only one field is submitted.
8. The four-sink table is still complete after these edits.
9. Nothing in section 4 should be hooked, still.
10. Nothing in this revision has re-opened anything pass 1 closed.
