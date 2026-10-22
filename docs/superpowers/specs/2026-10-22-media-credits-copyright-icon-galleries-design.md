# Media Credits — copyright icon and Bricks galleries (design)

Date: 2026-10-22 · Brief: Daniel's work order "Media Credits — Copyright-Icon und Galerien"
(visitessen.de, 22.10.2026). Daniel chose to skip the question round (D14); the brief is the
requirement, and every point below that the brief does not settle is marked **(call)** — a
decision made here, open to Gate A and to Daniel.

No story cited — unprofiled.

## Why

On visitessen the credit line shows the copyright as text even where the site shows the AI
marking as a seal, and Bricks image galleries bypass the module entirely: the gallery prints the
WordPress caption itself, so copyright, AI marking, seals and the `sfx_media_credits_line` filter
never reach gallery images. Daniel wants one credit line, with an optional copyright icon,
everywhere an image appears.

## Current behaviour (read from the code, Bricks 2.4.3)

- `Credit::for()` composes `copyright` (text with `©` prefix) and the AI part. `credit_display`
  (`text` | `icon` | `icon_text`) only swaps the AI label for its seal image (`seal_<slug>`).
- `Bricks::element_settings()` returns early for every element other than `image`.
- The Bricks element `image-gallery` (`includes/elements/image-gallery.php`, `render()`), per item
  with an attachment id:
  - with `link = lightbox` and `lightboxCaption` set: `wp_get_attachment_caption($id)` →
    `set_attribute("a-$index", 'data-lightbox-caption', …)`, then the `<a>` is printed through
    `render_attributes("a-$index")`, which applies `bricks/element/render_attributes`;
  - with the `caption` checkbox set: `wp_get_attachment_caption($id)` again, printed unescaped as
    `<figcaption class="bricks-image-caption">…</figcaption>`, only when non-empty.
  - Both lookups go through WordPress' `wp_get_attachment_caption` filter.
  - `bricks/element/settings` fires in `init()` immediately before `render()`, in both the
    frontend and the builder render; `bricks/frontend/render_element` fires after it.

## Goals

1. **Copyright icon setting.** One image picker "Copyright icon" in the module settings, like the
   AI seals. Under the existing display setting:
   - `icon`: the icon replaces the copyright text. Its `alt` is the full plain-text notice, prefix
     included (`© Diana Blinkert/EMG`), so nothing is lost to screen readers.
   - `icon_text`: icon, then the text; the icon's `alt` is empty (the text follows).
   - `text`, no icon chosen, or the chosen attachment no longer resolves to a URL: text as today.
   - Copyright and AI marking keep their order and their separator; with both icons in `icon`
     mode the line is copyright icon, separator, seal.
2. **Galleries.** A setting "Include galleries" (off by default, so no existing site changes
   unasked). When on, each `image-gallery` image with a non-empty credit line shows that line in
   its figcaption, as the table under "Galleries" sets out — the same `Credit::for()` line, so
   `sfx_media_credits_parts`, `sfx_media_credits_line` and the display setting apply unchanged.
3. **`$parts['copyright_icon_id']`.** The parts array handed to `sfx_media_credits_parts` and
   `sfx_media_credits_line`, and returned by `Credit::for()`, gains `copyright_icon_id` (int):
   the configured icon's attachment id when the copyright part is non-empty, else 0.

## Non-goals

- An "icon with expand" display (Daniel, 22.10.: visitessen solves it with its own snippet on
  `sfx_media_credits_line`).
- Overlay output for galleries, credits in other Bricks elements (carousel, slider), and in the
  WordPress core gallery block.
- Changing the `{sfx_media_copyright}` tag, which stays plain text, or the media library column.
- A new filter. Existing hooks keep their signatures and meaning.

## Behaviour

### Copyright icon

- `Credit::for()` resolves `copyright_icon_id` after `validate_parts()`: it is **derived** from the
  validated copyright (non-empty → the setting, else 0), exactly as `icon_id` is derived from
  `ai_key`; a value a parts filter returns for it is ignored **(call)** — same reason as for seals:
  a filter must not pair one copyright with another's icon by accident.
- The icon markup: `<img src alt width height class="sfx-credit__seal sfx-credit__copyright">`,
  width and height from the existing `icon_size`. It reuses the seal sizing rule and the existing
  stylesheet condition (`needs_stylesheet()` already loads it for `icon` and `icon_text`)
  **(call)**. Inside galleries that rule must win against Bricks' own gallery image selectors —
  `.bricks-layout-wrapper[data-layout=metro] img{height:100%}` (specificity 0,2,1, in
  `frontend.min.css`) stretches any `img` in a metro gallery — so the generated sizing rule is
  raised above them for seals and copyright icons alike, without touching the gallery's own images. `sfx_media_credits_seal_html` is not applied to it; a site that wants different
  markup uses `sfx_media_credits_line` **(call)**.
- The `alt` text is derived from the same `with_copyright_prefix()` result as the text, reduced to
  plain text, so the `sfx_media_credits_copyright_prefix` filter and the existing "already starts
  with ©" rule apply to the alt text too.
- The composed line still passes through `wp_kses_post()` and brace escaping last.

### Galleries

Active only when `include_galleries` is on and the gallery element's CSS classes field does not
carry `no-credit` (same test as for image elements).

**Scope.** The gallery's caption lookups are intercepted through WordPress' `wp_get_attachment_caption`
filter, limited to one gallery element instance and to the attachment ids that gallery renders
(its normalised items). The scope opens in `element_settings()` and closes at
`bricks/frontend/render_element` for that instance, and at the next `bricks/element/settings` call
of any element. Bricks 2.4.3 calls an element's `init()` from exactly two places,
`frontend.php:778` and `ajax.php:1515`, and both apply `bricks/frontend/render_element` right after
it, so on every supported route the scope ends with the gallery. **Residual, stated:** a route that
called `init()` without that filter would leave the scope open until the next element starts, and
in that window only a caption lookup for one of that gallery's own images could be affected.

**Re-entry.** While the module composes a credit inside the scope, a nested caption lookup — a
`sfx_media_credits_parts` or `sfx_media_credits_line` callback reading `wp_get_attachment_caption()`
of the same image, say — gets the unfiltered caption. The guard is released on every exit path,
including an exception. While the guard is held, `element_settings()` neither opens nor closes a
gallery scope: a Bricks element a callback renders during composition leaves the outer gallery's
scope intact for its remaining images and gets no gallery credits of its own **(call)** — nesting
galleries inside a credit is not a supported use, and this is its safe failure.

Per gallery image with an attachment id ("copyright" means the composed copyright part, fallback
copyright included; "credit line" the full `Credit::for()` line):

| copyright | credit line | figcaption |
|---|---|---|
| non-empty | non-empty | the credit line in `<span class="sfx-credit">…</span>`, replacing the WordPress caption |
| empty | non-empty (AI only) | the WordPress caption, then `<br>` and the credit span; the credit span alone when the WordPress caption is empty or the gallery's "Image caption" checkbox is off |
| empty | empty | the WordPress caption when the checkbox is on (none when empty) — unchanged |
| non-empty | empty (a line filter returned `''`, or kses emptied it) | as the row above: the WordPress caption when the checkbox is on, else none — an empty line is no credit, and never an empty span |

- **Replacement when there is a copyright (call).** The brief says the WordPress caption "stays"
  when the module has no copyright, which only means something if a module copyright replaces it.
  On visitessen the WordPress caption usually repeats the copyright, so appending would print it
  twice.
- **No copyright, but an AI marking: both (call).** The brief keeps the WordPress caption, and the
  disclosure must not be lost either; this mirrors caption mode on single images (caption, `<br>`,
  credit).
- **Credit shown with the checkbox off (call).** Caption mode on single images already adds the
  credit when the editor chose no caption; galleries follow the same rule. `no-credit` is the
  opt-out. The WordPress caption itself is never shown where the checkbox is off.
- **Independent of "Automatic output", by scope decision (call).** The gallery switch alone
  decides, even with "Automatic output" off, and `sfx_media_credits_should_auto_output` and
  `sfx_media_credits_caption_auto_html` are not fired for galleries: this change keeps gallery
  output to one switch and one opt-out class. The admin page says so (see below).
- **Lightbox caption is plain text, end to end.** Bricks hands `data-lightbox-caption` to
  PhotoSwipe's dynamic caption (`assets/js/frontend.js` → `photoswipe-caption.umd.min.js`), which
  assigns the decoded attribute to `innerHTML`; and Bricks escapes the attribute with `esc_attr()`,
  which does not re-encode an existing entity. So in an enabled gallery the value is built so that
  what the lightbox finally shows is the WordPress caption's **text**, literally:
  - tags removed (`wp_strip_all_tags()`), entities decoded to text, then encoded so that the
    attribute decode and the `innerHTML` parse together yield that text — an encoded
    `&lt;img onerror=…&gt;` in a caption shows as those characters and never becomes an element;
  - Bricks' dynamic-data delimiters neutralised with the module's `Credit::escape_braces()`, last,
    because Bricks renders dynamic tags across the assembled page after the element
    (`frontend.php`, `bricks/frontend/render_data`) — a caption `{post_title}` stays literal.
  - It never carries the credit markup. Where the text is empty the attribute is absent, as Bricks
    does today for an empty caption.
  Galleries with the switch off or with `no-credit` keep today's value byte for byte; whatever
  Bricks itself does with such captions there is unchanged and out of scope.
- **Not interactive.** Bricks styles `.brxe-image-gallery .bricks-image-caption` with
  `pointer-events:none`; the module leaves that as it is. A site snippet on `sfx_media_credits_line`
  that adds hover or click controls restores pointer events for them itself.
- Images without an attachment id (Bricks placeholders) are untouched — Bricks makes no caption
  lookup for them.
- Load-more items, query loops and repeated images behave like any other item: each lookup is per
  image and per call.
- The builder canvas shows the gallery credits too, since `element_settings()` runs there.

### Settings and admin page

- Option `sfx_media_credits_options` gains `copyright_icon` (attachment id, validated with
  `wp_attachment_is_image()` like the seals, default 0) and `include_galleries` (bool, default
  false). Written through the existing sanitize callback; no new option, so `DataPurge` is
  unchanged.
- Export (`ImportExport`, subset): `include_galleries` is added; `copyright_icon` stays out, like
  the seal ids, because attachment ids are site-local **(call)**.
- The admin page: a "Copyright icon" picker using the existing seal picker markup and script; an
  "Include galleries" checkbox with a one-line explanation; the display setting and the size field
  are relabelled to cover both icons ("Credit style": "Text only" / "Icons only" / "Icons and
  text"; "Icon size (px)"); the gallery checkbox's explanation says it works independently of
  "Automatic output"; a tip on galleries, `no-credit` and the lightbox; the developer-hook text for
  `sfx_media_credits_parts` and `sfx_media_credits_line` names `copyright_icon_id`, and the text for
  `sfx_media_credits_should_auto_output` and `sfx_media_credits_caption_auto_html` says they do not
  fire for galleries.
- New and changed strings use the `sfxtheme` text domain and get German translations in
  `languages/de_DE.po` / `.mo`.

## Invariants touched

- 2 (capability and nonce): the settings save path is unchanged (`options.php`, existing cap).
- 3 (escape at output): icon URL `esc_url`, alt `esc_attr`, text `esc_html`; the line keeps its
  kses + brace-escape gate; the lightbox value is plain text Bricks escapes as before.
- 4 (text domain): every new string.
- 5 (no runtime dependency): none added.
- Module coupling: none new; only `MediaCredits` and the `ImportExport` catalogue entry change.

## Acceptance

1. Single image, copyright set, display `icon`, copyright icon set: the line holds the icon and no
   copyright text; the icon's `alt` equals the plain notice (`© Diana Blinkert/EMG`).
2. The same without a copyright icon (or with a deleted one): text as before, byte-identical to
   today's line.
3. Copyright + AI `ai_generated`, both icons set, display `icon`: copyright icon, separator, seal.
4. `icon_text`: icon (empty alt) then text, for each part.
5. `sfx_media_credits_line` receives `$parts['copyright_icon_id']` (the icon id with a copyright,
   0 without), and a parts filter cannot set it independently.
6. Gallery, switch on: an image with a copyright shows the credit line as its figcaption; an
   image without copyright and without AI marking keeps its WordPress caption; an image with only
   an AI marking and a WordPress caption shows both; with the checkbox off and no credit there is
   no figcaption; `data-lightbox-caption` carries the WordPress caption as text (a caption
   `<em>Zeche</em> Zollverein` shows `Zeche Zollverein`; a caption holding the encoded
   `&lt;img src=x onerror=alert(1)&gt;` shows those characters and creates no element in the opened
   lightbox — checked live; a caption `{post_title}` stays literal after the full frontend render;
   the attribute is absent when the text is empty); a
   `sfx_media_credits_line` filter's change appears in the gallery figcaption; a line filter that
   reads `wp_get_attachment_caption()` of the same image on an uncached composition terminates and
   gets the unfiltered caption; a copyright whose line a filter empties behaves like no credit
   (WordPress caption with the checkbox on, nothing with it off); in a two-image gallery, a line
   filter that renders a Bricks element while composing the first image leaves the second image's
   credit intact.
7. Gallery with `no-credit`, or switch off: output byte-identical to today.
8. The scope ends: after `render_element` for the gallery, a `wp_get_attachment_caption()` call for
   one of its images returns the unfiltered caption; with no `render_element`, a lookup for an
   attachment that is not in the gallery is never affected, and the next element's settings call
   ends the scope.
9. Icon sizing holds in a metro gallery: a non-square seal or copyright icon keeps its
   `icon_size` width and its aspect ratio (live check), and the gallery's own images are unchanged.
10. Settings: `copyright_icon` rejects a non-image id; `include_galleries` normalises to bool;
   export carries `include_galleries` and not `copyright_icon`.
11. Battery green; a live check on the DDEV test site shows 1, 3, 6 and 9 in rendered pages, with all
    fixtures removed by one teardown.
12. Version bump and changelog entry through `bin/release.sh`; Daniel installs on staging.
