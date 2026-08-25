# Media Credits — Hooks & Filters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Add the extension points the addendum specifies to the shipped `inc/MediaCredits/` module, plus a documentation card on the settings page.

**Spec:** `docs/superpowers/specs/2026-08-24-media-credits-hooks-addendum.md` — read it alongside this plan. It survived three Codex Gate A passes; every rule below has a reason recorded there and **the reasons matter more than usual**, because two of the three rounds found contradictions introduced by well-meant fixes.

**Parent spec:** `docs/superpowers/specs/2026-08-24-media-credits-design.md` — the module's own design. Still binding.

**Branch:** `feature/image-credit-fields` (already checked out, at `be416c0`).

**Tech Stack:** PHP 8.0+, WordPress, Bricks 2.3.x, no new dependencies. Tests are plain PHP assert scripts run with `php tests/<file>.php`.

## Global Constraints

- PHP 8.0+, `declare(strict_types=1)`, namespace `SFX\MediaCredits`. No new files except where a task says so.
- Every user-facing string through `__()` / `esc_html__()` / `esc_html_e()`, domain `sfxtheme`. Hook **names and signatures** are identifiers and are NOT translatable.
- Hook names, verbatim and final — three Gate passes settled these, do not improve them:
  `sfx_media_credits_parts`, `sfx_media_credits_copyright_prefix`, `sfx_media_credits_separator`,
  `sfx_media_credits_seal_html`, `sfx_media_credits_should_auto_output`,
  `sfx_media_credits_caption_auto_html`, `sfx_media_credits_overlay_html`,
  `sfx_media_credits_overlay_skip_tags`, `sfx_media_credits_iptc_value`,
  `sfx_media_credits_saved` (action).
  Already existing and unchanged: `sfx_media_credits_labels`, `sfx_media_credits_line`.
- **The escaping boundary is per sink, not per gate.** `Credit::for()` returns raw `$parts` alongside the gated `line`, and four consumers read them, each with its own escaping. Any test that asserts the composed line and infers the rest is repeating the mistake Gate pass 1 caught.
- Escaping assertions are **exact strings**, never `assert_not_contains`. A `not_contains('{echo:')` passes on double-encoded `&amp;#123;` output, which is broken output.
- **Backwards compatibility:** with no filters registered, rendered output is byte-identical. The two documented exceptions: `Credit::reset_cache()` before the saved action, and the new settings-page card.
- Never delete attachment meta outside `uninstall.php`.

## File Structure

**Modified:** `inc/MediaCredits/Credit.php`, `inc/MediaCredits/Bricks.php`, `inc/MediaCredits/MediaLibrary.php`, `inc/MediaCredits/AdminPage.php`, `tests/media-credits-credit-test.php`, `tests/media-credits-bricks-test.php`, `tests/media-credits-iptc-test.php`, `languages/de_DE.po` + `.mo`.

**Created:** nothing.

---

### Task 1: Composition hooks in `Credit.php`

**Files:** Modify `inc/MediaCredits/Credit.php`, `tests/media-credits-credit-test.php`.

**Produces:** the four composition filters; a private `Credit::validate_parts(array $filtered, array $original): array`.

**Interfaces consumed:** `Settings::get_labels()`, `Settings::get()`.

- [ ] **Step 1: failing tests first** in `tests/media-credits-credit-test.php`, inserted before the epilogue.

Cases, all with `Credit::reset_cache()` between them:

- `parts` filter changing `copyright` → the new value appears in `line` with the `©` prefix applied.
- `parts` returning a **non-array** → credit byte-identical to unfiltered.
- **Coherence, the rule three Gate passes converged on:** the validated `ai_key` is authoritative and `ai_label` + `icon_id` are ALWAYS derived from it.
  - filter returns `ai_key => 'ai_hallucinated'` (unknown) and no `ai_label` → `ai_key === ''`, `ai_label === ''`, `icon_id === 0`.
  - filter returns `ai_key => 'ai_hallucinated'` **and** `ai_label => 'Erfunden'` → the supplied label is **discarded**, `ai_label === ''`. (This is the stale-label hole from pass 1.)
  - filter returns a different *valid* key `ai_edited` with no label → `ai_label` is `KI-bearbeitet`, i.e. derived from the new key, not the old.
  - filter returns `ai_key => 'ai_edited'` **and** `icon_id => <the ai_generated seal id>` → the supplied icon is **discarded** and the `ai_edited` seal (or 0) is used. (This is the pass-2 hole.)
  - filter returns `ai_key => ''` with a non-empty `ai_label` → label forced to `''`.
- `copyright_prefix` filter → its value replaces `©&nbsp;`, and is NOT consulted when the text already starts with `©` / `(c)` / `Copyright`.
- `separator` filter → replaces `&nbsp;·&nbsp;`, and is not consulted when only one part exists (assert a one-part line contains no separator at all).
- `seal_html` filter → replaces the `<img>`; receives the **credited attachment id** as its fifth argument (assert the callback sees the right id).
- **Upstream escaping, proving rather than assuming:** each of `copyright_prefix`, `separator`, `seal_html` returning `{post_title}` produces a `line` where the braces are entity-escaped — exact string.

- [ ] **Step 2: run, confirm failures.** `php tests/media-credits-credit-test.php`

- [ ] **Step 3: implement.**

In `Credit::for()`, after `$parts` is assembled (currently around line 76) and **before** `self::compose(...)`:

```php
$parts = self::validate_parts(
    (array) apply_filters('sfx_media_credits_parts', $parts, $attachment_id),
    $parts
);
```

Guard the cast: if the filter returned a non-array, `apply_filters` gives it back as-is, so check `is_array()` before casting and fall back to `$parts` untouched.

`validate_parts()` implements the addendum's five rules. The load-bearing half:

```php
$labels = Settings::get_labels();

$ai_key = (string) ($filtered['ai_key'] ?? $original['ai_key']);
if (!isset($labels[$ai_key])) {
    $ai_key = '';
}

// The validated key is authoritative. ai_label and icon_id are ALWAYS derived
// from it, and anything the filter supplied for them is discarded. Gate passes 1
// and 2 both found holes here: an invalid key with a stale label kept disclosing
// a marking the data-sfx-ai attribute no longer carried, and a valid key could be
// paired with another key's seal.
return [
    'copyright' => (string) ($filtered['copyright'] ?? $original['copyright']),
    'ai_key'    => $ai_key,
    'ai_label'  => $ai_key === '' ? '' : $labels[$ai_key],
    'icon_id'   => $ai_key === '' ? 0 : (int) Settings::get('seal_' . $ai_key),
];
```

Then recompute `$line` from the validated parts, and keep the existing `sfx_media_credits_line` + `wp_kses_post()` + `escape_braces()` gate exactly as it is (currently lines 85-90).

The other three filters:

- `with_copyright_prefix()` (line 99): the prefix becomes `apply_filters('sfx_media_credits_copyright_prefix', '©&nbsp;', $text, $attachment_id)`. The method currently takes only `$text` — it needs the attachment id. It is public and listed in the class's produced interface, but its only caller anywhere is `compose()`; adding a **second parameter with a default of 0** keeps the signature backwards compatible.
- `compose()` (line 123): `implode(apply_filters('sfx_media_credits_separator', '&nbsp;·&nbsp;', $attachment_id), $bits)` — but only build the separator when `count($bits) > 1`, so a one-part credit never consults it.
- `ai_part()` (line 144): wrap the `sprintf(...)` result in `apply_filters('sfx_media_credits_seal_html', $img, $icon_id, $ai_key, $size, $attachment_id)`.

`compose()` and `ai_part()` are private and currently do not receive `$attachment_id`. Thread it through from `for()`.

- [ ] **Step 4: run green.** Then the full suite: `for f in tests/*-test.php; do printf "%s: " "$f"; php "$f" 2>&1 | tail -1; done` — 18 files.

- [ ] **Step 5: commit** — `feat(media-credits): composition filters with an authoritative AI key`

---

### Task 2: The four-sink escaping tests

**Files:** Modify `tests/media-credits-bricks-test.php`.

No production code. This task exists on its own because it is the direct remedy for Gate pass 1's blocker, and folding it into Task 1 would hide it.

- [ ] **Step 1: write the cases.** A `sfx_media_credits_parts` filter setting `copyright` to `{echo:phpinfo}` and `ai_label`'s key to a value whose label contains a brace, then assert **each sink independently**, each as an exact string:

| Sink | How to reach it |
|---|---|
| `Credit::for()['line']` | direct call |
| `Bricks::raw_value()` | `Bricks::render_tag('{sfx_media_copyright}', $post, null)` |
| `Bricks::substitute()` | `Bricks::element_settings()` on an element whose `captionCustom` holds `{sfx_media_copyright}` |
| `Bricks::image_attributes()` | `Bricks::image_attributes(['src'=>'x'], $attachment)` — assert `data-sfx-ai` is the validated slug, never filter text |

- [ ] **Step 2:** run — they should **pass** immediately if Task 1 is correct. If any fails, that is a real defect in Task 1, not a test to adjust. Report it rather than fixing the assertion.

- [ ] **Step 3: commit** — `test(media-credits): escaping asserted at each of the four parts sinks`

---

### Task 3: `should_auto_output` with a single memoised decision

**Files:** Modify `inc/MediaCredits/Bricks.php`, `tests/media-credits-bricks-test.php`, `tests/support/media-credits-bricks-stubs.php` if a double is needed.

**This is the task most likely to go wrong.** Read the addendum's `should_auto_output` section in full before starting.

- [ ] **Step 1: failing tests.**

- caption mode, filter returns `false` → `element_settings()` leaves `caption` and `captionCustom` untouched.
- overlay mode, filter returns `false` → **no `tag` key is set** even with `force_wrapper` on. This is the settings-time half of the pass-1 finding.
- overlay mode, filter returns `false` → `render_element()` injects nothing.
- filter returns `false` → `image_attributes()` still adds `data-sfx-ai`. The machine-readable marking is not a design decision.
- **Loop safety, the pass-3 finding:** two *distinct element instances* carrying the **same `id`** (what Bricks does when one element renders for many posts in a query loop) each get their own decision. Construct a filter that returns `true` for the first call and `false` for the second, and assert the second element gets no credit. Keying the memo on `$id` makes this test fail; that is the point of it.
- No filter registered → behaviour byte-identical to before this task.

- [ ] **Step 2: run, confirm failures.**

- [ ] **Step 3: implement.**

A private request-local store:

```php
/** @var \SplObjectStorage<object, bool>|null decisions taken at settings time, consumed at render time */
private static ?\SplObjectStorage $auto_output_decisions = null;
```

The memo key **must be the element object instance**, never `$id` or `$uid`. Bricks constructs a fresh instance per render (`frontend.php:743`) and passes that same instance to `bricks/frontend/render_element` (`:752`), but `$uid = $id` comes from the stored element definition (`base.php:74-76`) — so a loop rendering one element for twenty posts presents the same id twenty times.

In `element_settings()`, after the `no-credit` check and after `$id` resolves, before the mode branch:

```php
$should = (bool) apply_filters('sfx_media_credits_should_auto_output', true, $mode, $id, $element);
self::remember_decision($element, $should);
if (!$should) {
    return $settings;
}
```

In `render_element()`, replace a fresh evaluation with consumption:

```php
// Consume the settings-time decision. Evaluating again here would let a
// deterministic callback disagree with itself: Bricks assigns the filtered
// settings back onto the element (base.php:2948-2953) between the two moments,
// so a callback keyed on isset($settings['tag']) flips once force_wrapper wrote it.
$should = self::consume_decision($element);
if ($should === null) {
    $should = (bool) apply_filters('sfx_media_credits_should_auto_output', true, 'overlay', $id, $element);
}
if (!$should) {
    return $html;
}
```

`consume_decision()` **removes** the entry, so the storage cannot grow across a page. Builder-AJAX renders never reach `render_element()` and their entries die with the request.

Add a `Bricks::reset_decisions()` test seam alongside `Credit::reset_cache()`, and call it from the test between cases.

- [ ] **Step 4: run green, full suite.**
- [ ] **Step 5: commit** — `feat(media-credits): should_auto_output as one memoised decision per element`

---

### Task 4: The two downstream HTML filters

**Files:** Modify `inc/MediaCredits/Bricks.php`, `tests/media-credits-bricks-test.php`.

- [ ] **Step 1: failing tests.**

For **each** of `caption_auto_html` and `overlay_html`:

- filter returning `{echo:phpinfo}` → exact-string escaped output.
- filter returning `<script>alert(1)</script><em>ok</em>` → script gone, `<em>` kept.
- filter returning markup **without** `class="sfx-credit"` → the marker is wrapped back on, and a second auto-output pass over the result adds no second credit (assert `substr_count(..., 'sfx-credit') === 1` on the class token).
- filter returning `''` → falls back to the module's own markup, NOT suppression.
- filter returning markup that already has the marker → left as the filter wrote it, not double-wrapped.

Plus `overlay_skip_tags`:
- returning `['figure']` → no overlay injected into a `figure`.
- returning `[]` → an overlay IS injected into an `img` root (proving the list is really consulted).
- returning a malformed entry (`['<figure>', 123, '']`) → neither crashes nor matches.

- [ ] **Step 2: run, confirm failures.**

- [ ] **Step 3: implement.** A shared private helper, because both sinks need the identical three steps:

```php
/**
 * The three-step treatment every filtered page-content fragment gets.
 *
 * Order is the whole point: kses so a filter cannot add script, the marker
 * restored so a filter cannot silently break dedup, and escape_braces LAST so
 * neither the filter nor the wrapping can reintroduce a Bricks dynamic tag.
 */
private static function finish_fragment(string $filtered, string $fallback, string $extra_class = ''): string
{
    if (trim($filtered) === '') {
        return $fallback;               // an empty return is a filter bug, not a decision
    }
    $html = wp_kses_post($filtered);
    if (trim($html) === '') {
        return $fallback;
    }
    if (!self::has_marker($html)) {
        $class = self::MARKER_CLASS . ($extra_class !== '' ? ' ' . $extra_class : '');
        $html  = '<span class="' . $class . '">' . $html . '</span>';
    }
    return Credit::escape_braces($html);
}
```

Wire it into `element_settings()` (caption branch, around line 300) and `inject_overlay()` (around line 480). `inject_overlay()` passes `self::MARKER_CLASS . '--overlay'` as `$extra_class`.

`overlay_skip_tags` in `inject_overlay()`: filter `['img','picture','a']`, then sanitise — non-array discarded, entries cast to string, `strtolower`, drop anything not matching `/^[a-z0-9-]+$/`.

- [ ] **Step 4: run green, full suite.**
- [ ] **Step 5: commit** — `feat(media-credits): filterable caption and overlay markup with enforced marker`

---

### Task 5: `iptc_value` filter and the `saved` action

**Files:** Modify `inc/MediaCredits/MediaLibrary.php`, `tests/media-credits-iptc-test.php`.

- [ ] **Step 1: failing tests.**

- `iptc_value` filter rewrites the value before it is written; returning `''` suppresses the write entirely **but still sets the one-shot marker** (we looked; that is what the marker records).
- `saved` fires from `save()` when **only** the copyright field is submitted; and when only the AI field is; and when both are. Once per save, not once per field.
- `saved` does **not** fire from `save()` when neither field is in the payload.
- `saved` fires from `prefill_iptc()` **only** after an actual copyright write — not when a guard returned early, not when the IPTC value was empty, not when the field was already filled.
- The action's arguments carry the current post-write values of **both** fields, re-read after the writes.
- A listener calling `Credit::for($id)` inside the action sees the **new** values. Assert this by seeding the cache with a `Credit::for()` call before the save.

- [ ] **Step 2: run, confirm failures.**

- [ ] **Step 3: implement.**

In `save()` (line 214): track whether either field was present; after the writes, if so, re-read both meta values, call `Credit::reset_cache()`, then `do_action('sfx_media_credits_saved', $id, $copyright, $ai_key, 'save')`.

In `prefill_iptc()`: apply `apply_filters('sfx_media_credits_iptc_value', $value, $image_meta, $id)` after `iptc_copyright()` and before the empty check, then `sanitize_text_field()` on the result. Fire the same action with context `'iptc'` immediately after the successful `update_post_meta()` at line 303, again after `Credit::reset_cache()`.

`Credit::reset_cache()` before the action is required: `Credit::for()` memoises per request and neither path invalidates it, so a listener doing the obvious thing would be handed the pre-save value.

The test harness has no `do_action`. Add one to `tests/support/media-credits-stubs.php` alongside `apply_filters`, recording invocations so a test can assert count and arguments. Purely additive; add any new fixture global to `test_reset()`.

- [ ] **Step 4: run green, full suite.**
- [ ] **Step 5: commit** — `feat(media-credits): IPTC value filter and a post-save action`

---

### Task 6: The settings-page documentation card

**Files:** Modify `inc/MediaCredits/AdminPage.php`.

- [ ] **Step 1: implement.** A second card in the right-hand column, beneath the existing "How output works" card, listing every hook: name, signature, one line on when it fires.

Constraints:
- Hook names and signatures in `<code>` and **NOT** translatable — they are identifiers.
- The surrounding prose (the card title, the one-line descriptions, a lead sentence) IS translatable, domain `sfxtheme`.
- Group as the addendum does: composition, output, media library. Mark the action as an action.
- No code examples beyond the signature — a card with usage snippets rots the moment a signature changes.
- Escape every dynamic value; there should be almost none.

- [ ] **Step 2:** `php -l inc/MediaCredits/AdminPage.php`
- [ ] **Step 3: commit** — `feat(media-credits): hook reference card on the settings page`

---

### Task 7: Translations for the new card

**Files:** Modify `languages/de_DE.po`, `languages/de_DE.mo`.

- [ ] **Step 1:** extract the new translatable strings from Task 6, append German entries to `de_DE.po` in the existing style, matching the catalogue's established register (informal "du" + imperative — verify, do not assume). Hook names must NOT appear as msgids.
- [ ] **Step 2:** `msgfmt languages/de_DE.po -o languages/de_DE.mo`, `msgfmt --check` clean, report the statistics line and confirm the count rose by exactly the number added.
- [ ] **Step 3: commit** — `i18n(media-credits): German translations for the hook reference card`

---

### Task 8: Full suite and verification

- [ ] **Step 1:** `for f in tests/*-test.php; do printf "%s: " "$f"; php "$f" 2>&1 | tail -1; done` — 18 files, all green.
- [ ] **Step 2:** `for f in inc/MediaCredits/*.php; do php -l "$f"; done`
- [ ] **Step 3: backwards compatibility, explicitly.** With no filters registered anywhere, confirm every pre-existing assertion still passes — this is the cheapest guard against the whole plan. The two documented exceptions are the `reset_cache()` before the saved action and the new settings card.
- [ ] **Step 4:** report which steps passed and which did not, quoting what was seen. Anything not run is reported as not run, never as passed.
