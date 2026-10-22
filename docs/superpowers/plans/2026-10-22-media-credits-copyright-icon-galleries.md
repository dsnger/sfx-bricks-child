# Media Credits — copyright icon and gallery credits — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A copyright icon under the existing credit display modes, credit lines in Bricks `image-gallery` figcaptions behind an "Include galleries" switch, and `$parts['copyright_icon_id']`.

**Architecture:** `Credit::for()` gains the derived `copyright_icon_id` and renders the copyright part as text, icon or both. `Bricks` gains a gallery scope: opened in `element_settings()`, it intercepts `wp_get_attachment_caption` for that gallery's own attachment ids and rewrites `data-lightbox-caption` through `bricks/element/render_attributes`; it closes at `render_element` and at the next element's settings. Settings, admin page, export and translations follow.

**Tech Stack:** PHP 8.0+, WordPress ≥ 6.1, Bricks 2.4.3 hooks; plain PHP tests run by `./quality.sh`.

**Spec:** `docs/superpowers/specs/2026-10-22-media-credits-copyright-icon-galleries-design.md` (Gate A closed, `243c45e`).

No story cited — unprofiled.

## Global Constraints

- Working directory `/Users/daniel/DEVELOPMENT/SFX-WP-PLUGINS/sfx-bricks-child`, branch `feat/media-credits-copyright-icon-galleries`; battery `./quality.sh`.
- New option keys, verbatim: `copyright_icon` (int, default 0, valid only if `wp_attachment_is_image()`), `include_galleries` (bool, default false), both inside `sfx_media_credits_options`.
- New parts key, verbatim: `copyright_icon_id` (int).
- Copyright icon classes, verbatim: `sfx-credit__seal sfx-credit__copyright`.
- Gallery figcaption credit wrapper, verbatim: `<span class="sfx-credit">…</span>` (`Bricks::MARKER_CLASS`).
- Element name, verbatim: `image-gallery`.
- No new filter; existing hook signatures unchanged. Every new string uses `sfxtheme` and gets a `de_DE` translation.
- Commits: `git commit -m 'WIP: …'` snapshots (one `-m`, nothing else in the command), folded into one commit when Gate B closes.

## Review Focus

1. **Lightbox enabled, checkbox off, image without credit** — the figcaption lookup must return `''`, yet the lightbox must still show the WordPress caption: Bricks skips `set_attribute` when the lookup is empty, so the attribute is (re)written in the render-attributes filter from the unfiltered caption, never from the lookup's return. Pinned in Task 2 (case g6).
2. **The same image twice in one gallery, and load-more items** — each lookup is independent; no per-id state may assume one call per id. Pinned in Task 2 (case g2 repeats id 21).
3. **A custom-link gallery** (`link = custom`, also keyed `a-<n>`) — the attribute filter must act only when `link = lightbox` and `lightboxCaption` is set. Pinned in Task 2 (case g7).
4. **A `wp_get_attachment_caption()` call between galleries** for an image of the closed gallery, and for an image of no gallery while a scope stands — unfiltered. Pinned in Task 2 (case g8).
5. **Fallback copyright set, image without own copyright** — counts as copyright: replaces the caption and shows the icon. Pinned in Task 1 (case c5) and Task 2 (case g10, its own option state).

## Sources and impact boundary

Read: the spec; `inc/MediaCredits/{Credit,Bricks,Settings,AdminPage}.php`, `assets/media-credits{.css,-admin.js}`; `inc/ImportExport/Controller.php` (media_credits group); `tests/media-credits-{credit,bricks}-test.php`, `tests/support/media-credits{,-bricks}-stubs.php`, `tests/support/html-api.php`; Bricks 2.4.3 `includes/elements/image-gallery.php` (`render()`), `includes/elements/base.php` (`set_attribute`, `render_attributes`, `init`), `includes/frontend.php:770-782`, `includes/ajax.php:1505-1519`, `assets/css/frontend.min.css` (metro `img{height:100%}` rules, including the `(0,5,2)` nth-child one), `assets/css/elements/image-gallery.min.css`, `assets/js/frontend.js` + `libs/photoswipe-caption.umd.min.js` (innerHTML). Impact: `inc/MediaCredits/{Credit,Bricks,Settings,AdminPage}.php`, `inc/ImportExport/Controller.php` (one field), `tests/media-credits-{credit,bricks}-test.php`, `tests/support/media-credits-stubs.php` (a `wp_strip_all_tags()` double: `strip_tags()` after removing `<script>`/`<style>` blocks, then `trim()`, as core does), `languages/de_DE.{po,mo}`, `CHANGELOG.md`. Not touched: `DataPurge` (same option), `MediaLibrary`, REST, the Bricks parent theme, `bin/*`.

**Handover boundary:** this plan is one unit, ending with Task 5 (release published, Daniel told to install on staging); the handover is written then.

---

### Task 1: Copyright icon in the credit line

**Files:** Modify `inc/MediaCredits/Settings.php`, `inc/MediaCredits/Credit.php`, `inc/MediaCredits/Bricks.php` (`seal_style_rule()` only); Test `tests/media-credits-credit-test.php`, `tests/media-credits-bricks-test.php` (Case 20).

**Interfaces — produces:** `Settings::get('copyright_icon'): int`; `Credit::for(int): array{copyright, ai_key, ai_label, icon_id, copyright_icon_id, line}`; `Bricks::seal_style_rule(int $size): string`.

- [ ] **Step 1: Failing tests** in `tests/media-credits-credit-test.php`, new cases after the existing ones (fixtures as the file's own: `$test_options`, `$test_post_meta`, `$test_attachment_url`, `$test_attachment_img`, `$test_is_image`; icon attachment 90 with image URL `https://example.test/icon.png`, seal 91):
  - `c1` display `icon`, copyright `Diana Blinkert/EMG`, `copyright_icon` 90 → `line` === `<img src="https://example.test/icon.png" alt="©\u{00A0}Diana Blinkert/EMG" width="24" height="24" class="sfx-credit__seal sfx-credit__copyright">` exactly (the alt holds a real U+00A0, the decoded `&nbsp;`; nothing else in the line); `copyright_icon_id` === 90.
  - `c2` same, `copyright_icon` 0 → `line` === `'©&nbsp;Diana Blinkert/EMG'` (byte-identical to today); also with icon 90 but `$test_attachment_img[90]` unset → same string.
  - `c3` copyright + `ai_generated`, seal 91, display `icon` → copyright `<img` appears before `&nbsp;·&nbsp;` and the seal `<img` after it.
  - `c4` display `icon_text` → copyright icon has `alt=""` and is followed by `&nbsp;©&nbsp;Diana Blinkert/EMG`.
  - `c5` no own copyright, `fallback_copyright` `Stadt Essen`, icon 90, display `icon` → icon with `alt` `© Stadt Essen` (U+00A0), `copyright_icon_id` 90; with no copyright at all → `copyright_icon_id` 0.
  - `c6` a copyright already starting `© EMG` → alt `© EMG` (no doubled prefix).
  - `c7` parts filter returning `copyright_icon_id => 999` → ignored (90); parts filter emptying `copyright` → `copyright_icon_id` 0 and no icon in `line`.
  - `c8` `sfx_media_credits_line` filter receives `$args[1]['copyright_icon_id']` === 90 (capture via `$test_filter_returns`).
  - `c10` re-entry: a line filter that calls `Credit::for(5)` for the attachment being composed (cold cache) gets the empty credit (`line` `''`) once, without recursing (a counter in the filter stays at 1), and the outer `line` is the filter's result; the guard is released after an exception thrown from a parts filter (a later `Credit::for(5)` composes normally).
  - `c9` `Settings::normalize(['copyright_icon' => 90])` keeps 90 when `$test_is_image[90]` is true and gives 0 for a non-image id; default is 0.
  - Case 20 in `tests/media-credits-bricks-test.php`: replace the two exact-string assertions with the new rule (Step 3) for 32 and 9999.
- [ ] **Step 2: Run** `php tests/media-credits-credit-test.php` and `php tests/media-credits-bricks-test.php` → FAIL on the new cases.
- [ ] **Step 3: Implement.** First add the `wp_strip_all_tags()` double to `tests/support/media-credits-stubs.php`, guarded by `function_exists`.
  - `Settings`: `copyright_icon` in `get_defaults()` and `normalize()` (same validation as a seal).
  - `Credit::for()`: after `validate_parts()`, `copyright_icon_id = copyright !== '' ? (int) Settings::get('copyright_icon') : 0`; also present (computed the same way) in the `$parts` handed to `sfx_media_credits_parts`, and `validate_parts()` derives it, ignoring any filter value. `$empty` gains `copyright_icon_id => 0`. Docblock return shape updated.
  - `Credit::for()` re-entry guard: a private set of attachment ids in composition; a call for an id already in it returns the empty credit uncached; the id is removed in a `finally`. This bounds the nested-rendering case the spec declares a safe failure (a credit callback that renders the same image, in a gallery or not, gets no credit inside its own credit) — it also closes the same recursion for single images, which exists today.
  - `compose()` takes the icon id; the copyright fragment comes from a new `private static function copyright_part(string $copyright, int $icon_id, int $attachment_id): string`: `$text = with_copyright_prefix(…)`; icon URL via `wp_get_attachment_image_url($icon_id, 'full')`; display `text` or no URL → `$text`; else `<img src alt width height class="sfx-credit__seal sfx-credit__copyright">` (size `icon_size`, alt = `html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES, 'UTF-8')` in `icon` mode, `''` in `icon_text`), followed by `'&nbsp;' . $text` in `icon_text`. Escape at output: `esc_url`, `esc_attr`.
  - `seal_style_rule($size)` returns two rules: `.sfx-credit__seal{width:%dpx;height:auto}` and `.bricks-layout-wrapper .sfx-credit__seal{width:%dpx!important;height:auto!important}` — the second because Bricks' metro rule `.bricks-layout-wrapper[data-layout=metro].isotope>li:nth-child(3n+1):not(.bricks-isotope-sizer) img` reaches specificity (0,5,2); scoped to gallery wrappers so single images keep today's rule. Docblock: replace "A class selector wins without !important" with the reason.
- [ ] **Step 4: Run** both tests and `./quality.sh` → PASS.
- [ ] **Step 5: Commit** `git add -A inc tests` then `git commit -m 'WIP: copyright icon in media credits'`.

### Task 2: Gallery scope

**Files:** Modify `inc/MediaCredits/Settings.php` (`include_galleries`), `inc/MediaCredits/Bricks.php`; Test `tests/media-credits-bricks-test.php`.

**Interfaces — consumes:** `Credit::for()` from Task 1. **Produces** (all `public static`, registered in `Bricks::register()`):
- `attachment_caption($caption, $post_id)` on `wp_get_attachment_caption`, priority 10, 2 args — returns the figcaption content per the spec's table, or `$caption` untouched outside a scope, for ids not in it, or while the bypass guard is held.
- `gallery_render_attributes($attributes, $key, $element)` on `bricks/element/render_attributes`, 10, 3 args.
- `lightbox_text(string $caption): string` — pure.
- private state: `?array $gallery` (`element`, `ids` int[], `show_wp_caption` bool, `lightbox` bool) and `int $bypass` depth counter.
- **Scope membership comes from the render itself, not from a second resolution** (Bricks' `Helpers::get_normalized_image_settings()` runs dynamic data; calling it twice could disagree with what `render()` resolved): `render()` prints `<li {render_attributes("li-<n>")}>` with `data-id` before either caption lookup of that item, so `gallery_render_attributes()` adds the `data-id` of every `li-<digits>` key it sees for the scope's element to `ids`. A lookup for an id not yet seen there is not touched.

- [ ] **Step 1: Failing tests** in `tests/media-credits-bricks-test.php`. The test file defines `wp_get_attachment_caption($id)` as core does it: `false` without calling the filter when `get_post($id)` is missing or not an attachment, else `Bricks::attachment_caption($post->post_excerpt, $id)` and seeds `$test_posts` with excerpts. Gallery element: the global `new Test_Bricks_Element($settings, null, 'image-gallery', 'g1')`; a helper `t_render_li($el, int $n, int $id)` calls `Bricks::gallery_render_attributes(['li-'.$n => ['data-id' => [$id]]], 'li-'.$n, $el)` to admit an id the way Bricks' render does, for items 20, 21, 22 and 21 again (indices 0-3). Images: 20 copyright `Foto A` + caption `Zeche`; 21 no copyright, `ai_generated`, caption `<em>Halde</em>`; 22 nothing, caption `Kanal`; 23 not in the gallery, caption `Fremd`. Option `include_galleries` true unless stated.
  - `g1` caption checkbox on: after `element_settings`, `$settings['caption']` is truthy; lookups give 20 → `<span class="sfx-credit">©&nbsp;Foto A</span>`; 21 → `<em>Halde</em><br><span class="sfx-credit">AI-generated</span>`; 22 → `Kanal`; 23 → `Fremd`.
  - `g2` checkbox off (no `caption` key): 20 as g1; 21 → `<span class="sfx-credit">AI-generated</span>`; 22 → `''`; second lookup of 21 gives the same as the first.
  - `g3` `sfx_media_credits_line` filter returning `''` for 20, checkbox on → `Zeche`; off → `''`. A line filter appending ` (EMG)` → 20's figcaption contains `(EMG)`.
  - `g4` re-entry: a line filter (via `$test_filter_returns`) that calls `wp_get_attachment_caption(20)` on first (uncached, `Credit::reset_cache()`) composition receives `Zeche` and the outer call returns normally; after it, a lookup of 22 still behaves as in g1 (scope intact). A line filter that calls `Bricks::element_settings([], new Test_Bricks_Element([], null, 'image', 'n'))` while composing 20 → the next lookup of 21 still gives the g1 result.
  - `g5` `_cssClasses => 'no-credit'`, and separately `include_galleries` false → settings returned unchanged (`caption` key absent stays absent) and every lookup returns its caption unchanged.
  - `g6` lightbox: settings `link => 'lightbox', lightboxCaption => true`, checkbox off; attributes `['li-1' => ['data-id' => [21]], 'a-1' => ['href' => ['x']]]` (no `data-lightbox-caption`, as Bricks leaves it after an empty lookup) → `gallery_render_attributes(…, 'a-1', $el)` sets `data-lightbox-caption` to `[lightbox_text('<em>Halde</em>')]`; for id 22 (no credit, caption `Kanal`, checkbox off) the figcaption lookup returns `''` and an absent attribute is restored to `[lightbox_text('Kanal')]`; for a separate image 24 with caption `''` and a stale `data-lightbox-caption => ['x']` → the key is removed; a placeholder `a-5` whose `li-5` has no `data-id` → attributes unchanged, no warning; for id 20 → `lightbox_text('Zeche')`.
  - `lightbox_text`: `'<em>Zeche</em> Zollverein'` → `'Zeche Zollverein'`; `'&lt;img src=x onerror=alert(1)&gt;'` → a value which, after `esc_attr()` (the stub's) and one `html_entity_decode()`, equals `htmlspecialchars('<img src=x onerror=alert(1)>')` — i.e. one decode leaves it escaped; `'{post_title}'` → contains `&#123;post_title&#125;` and no `{`; `'  '` → `''`.
  - `g7` `link => 'custom'` with a `data-lightbox-caption` value present → attributes unchanged; a different element instance → unchanged; key `_root` → unchanged.
  - `g8` after `render_element('<ul></ul>', $gallery_el)` a lookup of 20 returns `Zeche`; with a fresh scope and no `render_element`, a lookup of 23 returns `Fremd`, and after `element_settings([], <image element>)` a lookup of 20 returns `Zeche`.
  - `g0` an id never admitted through `li-<n>` (23) is untouched while the scope stands; an `li-<n>` call for another element instance admits nothing.
  - `g10` own option state: `fallback_copyright` `Stadt Essen`, image 22 (no own copyright, caption `Kanal`) → `<span class="sfx-credit">©&nbsp;Stadt Essen</span>`, replacing `Kanal`.
  - `g11` real order without lightbox caption, cold cache (`Credit::reset_cache()`): `element_settings` → `t_render_li` for 20 → `Bricks::image_attributes(['class' => 'image'], 20)` (what `wp_get_attachment_image()` triggers before the figcaption lookup) → figcaption lookup of 20; run once with a line filter reading `wp_get_attachment_caption(20)` (it receives `Zeche`) and once with a line filter calling `element_settings()` for another element (the following lookups of 20 and 21 still give the g1 results); and once with a line filter that, while composing 20, runs `element_settings()` for a nested `image-gallery` element, admits 20 through its `li-0` and calls `Bricks::image_attributes([], 20)` and `wp_get_attachment_caption(20)` — it terminates, the nested calls return the unfiltered caption and no `data-sfx-ai`, and the outer lookups of 20 and 21 give the g1 results.
  - `g12` a deleted attachment (admitted id 25, no post): `gallery_render_attributes()` for its `a-<n>` with a stale `data-lightbox-caption` removes the key and throws nothing.
  - `g9` `Settings::normalize(['include_galleries' => '1'])['include_galleries']` === true; default false.
  - Existing image-element cases stay green unchanged (switch off and on).
- [ ] **Step 2: Run** `php tests/media-credits-bricks-test.php` → FAIL on the new cases.
- [ ] **Step 3: Implement** in `Bricks`:
  - `element_settings()`: first, unless `$bypass > 0`, close any open scope; then the existing `image` path unchanged; a new `image-gallery` branch (skipped while `$bypass > 0`): switch on, not `no-credit` → open the scope with `ids = []` with `show_wp_caption = !empty($settings['caption'])`, `lightbox = ($settings['link'] ?? '') === 'lightbox' && isset($settings['lightboxCaption'])`; set `$settings['caption'] = true`; return.
  - `attachment_caption()`: the table from the spec, composing under the bypass. **Every composition while a scope stands runs under the bypass** — `attachment_caption()`, `gallery_render_attributes()` and `image_attributes()` (which `wp_get_attachment_image()` reaches before the figcaption lookup) — through one private helper that does `$bypass++` / `try` / `finally $bypass--`.
  - `gallery_render_attributes()`: only for the scope's element; `li-<digits>` → admit its `data-id` (return attributes unchanged); `a-<digits>` only with `lightbox` true and a positive id in the matching `li-<n>` that is in `ids` (a placeholder item has no `data-id`: attributes returned unchanged); id from `$attributes['li-<n>']['data-id'][0]`; original caption via `wp_get_attachment_caption($id)` under the bypass; a non-string result (core returns `false` for a missing or non-attachment post) counts as `''`; `lightbox_text()`; empty → `unset`, else `[$text]`.
  - `lightbox_text()`: `wp_strip_all_tags()`, `html_entity_decode(…, ENT_QUOTES, 'UTF-8')`, `trim`, `''` → `''`; else `htmlspecialchars(htmlspecialchars($text, ENT_QUOTES, 'UTF-8', true), ENT_QUOTES, 'UTF-8', true)` (Bricks' `esc_attr()` keeps existing entities, the attribute decode removes one level, PhotoSwipe's `innerHTML` parses the other); then `Credit::escape_braces()` last.
  - `render_element()`: close the scope when `$element` is the scope's element (before the existing image-only early return).
  - `register()`: the two new hooks.
  - Class docblock: a fourth mechanism, gallery captions.
- [ ] **Step 4: Run** the test and `./quality.sh` → PASS.
- [ ] **Step 5: Commit** `git add -A inc tests` then `git commit -m 'WIP: media credits in Bricks galleries'`.

### Task 3: Admin page, export, translations, changelog

**Files:** Modify `inc/MediaCredits/AdminPage.php`, `inc/ImportExport/Controller.php`, `languages/de_DE.po` (+ rebuilt `.mo`), `CHANGELOG.md`; Test `tests/media-credits-credit-test.php` (Case 9).

- [ ] **Step 1: Failing test** — Case 9 gains: `include_galleries` is in the exported fields; `copyright_icon` is not (add it to the 9d loop).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.**
  - Export: append `include_galleries` to the media_credits `fields`.
  - Admin page, strings (English source → German): label "Credit style" → "Darstellung der Nachweise"; options "Icons only" → "Nur Icons", "Icons and text" → "Icons und Text" ("Text only" kept); "Icon size (px)" → "Icongröße (px)"; a row "Copyright icon" → "Copyright-Icon" using the existing `sfx-mc-seal` cell markup (field `copyright_icon`) with description "Shown instead of the copyright text with "Icons only" (the icon's alternative text is then the full notice), or before the text with "Icons and text"." → German equivalent; a row "Include galleries" → "Galerien einbeziehen", checkbox text "Show the credit line in the captions of Bricks image galleries. Images without a copyright keep their WordPress caption. Works independently of "Automatic output"." → German; a tip "Galleries: add no-credit to a gallery element's CSS classes to exclude it. The lightbox caption stays plain text." → German; the `sfx_media_credits_parts` and `sfx_media_credits_line` hook texts mention `copyright_icon_id` (follows the copyright, like the seal follows the AI key); the `should_auto_output` and `caption_auto_html` texts add "Not fired for galleries." → German. `frameTitle` → "Choose an image" → "Bild auswählen". Old msgids that no longer occur are left in the `.po` (harmless) — only new msgids are added.
  - Rebuild: `msgfmt -o languages/de_DE.mo languages/de_DE.po`.
  - `CHANGELOG.md` `## [Unreleased]` → `### Added`: copyright icon; gallery credits behind "Include galleries" (off by default), lightbox caption plain text; `$parts['copyright_icon_id']`.
- [ ] **Step 4: Run** `./quality.sh` → PASS; `msgfmt --check -o /dev/null languages/de_DE.po` exit 0.
- [ ] **Step 5: Commit** `git add -A inc tests languages CHANGELOG.md` then `git commit -m 'WIP: media credits admin, export, translations'`.

### Task 4: Live check, Gate B, PR

- [ ] **Step 1: Live check on DDEV** (spec Acceptance 1, 3, 6, 9, 11). Install the branch with `SFX_TEST_SITE=/Users/daniel/DEVELOPMENT/LOCALHOST/sfx-bricks-child.local SFX_TEST_WP=<scratchpad>/ddev-wp.sh SFX_TEST_WP_PATH=/var/www/html bash bin/test-instance.sh refresh`. A scratchpad script (not committed) with one `trap` teardown declared before the first fixture. Every attachment it uses is **disposable**: imported by the script from copies of a local image (`wp media import`, ids recorded the moment each exists) and deleted with `wp post delete --force` in the teardown — no existing attachment's caption or meta is touched. The only pre-existing state it changes is `sfx_media_credits_options`, snapshotted before the first change and restored in the teardown. It creates one published test page (id recorded, deleted in teardown) holding a Bricks image element and two `image-gallery` elements (grid + metro, lightbox with caption on) over the disposable images — captions `Zeche`, `&lt;img src=x onerror=alert(1)&gt;`, `{post_title}`, one AI-only — and one non-square icon/seal; fetches the page and checks icon `alt`, both icons, the figcaption contents per the table, the lightbox attributes, and that `{post_title}` is still literal in the served HTML; then opens the page in Chrome (claude-in-chrome), opens the lightbox on the encoded-markup image and requires the caption element to exist with `childElementCount === 0` and `textContent === '<img src=x onerror=alert(1)>'`, and measures the icon's rendered width (`icon_size`) and aspect ratio in the metro gallery. Teardown restores the option and deletes page and attachments; `debug.log` gains no theme entries. Writes run under `wp_set_current_user()` of the admin (Bricks refuses CLI writes without one).
- [ ] **Step 2: Gate B** per `.claude/review-gates.md`: new nonce; per pass two sequential `mcp__codex__review` calls (`spec`, then `quality`), `baseSha` = `243c45e`'s successor that closes this plan's Gate A (full 40 chars), `headSha` = the WIP tip (full 40 chars); `additionalContext` names the spec, this plan, the falsify lens with what changed size or value (`seal_style_rule` output, `Credit::for()` keys, export field list, admin labels), and the slot; floor 3.
- [ ] **Step 3: Close** — `git reset --soft <plan commit>`, one commit `feat(media-credits): copyright icon and credits in Bricks galleries` with provenance line, curve, ledger check; push; PR with the records, the live-check results and the "(call)" decisions listed for Daniel.

### Task 5: Release and hand-over

- [ ] **Step 1:** After the PR's bot review and squash-merge (`dev-workflow:process-pr-review`, records carried into the squash body), on clean `main` equal to `origin/main`: `bash bin/release.sh minor` → `release/0.35.0` branch, its own Gate B (one pass at least, per §5), PR, merge; `bash bin/release.sh publish --no-upload` and check the ZIP lists only `SHIPPED`; then `bash bin/release.sh publish`. Verify the GitHub release is Latest with its ZIP.
- [ ] **Step 2:** Tell Daniel the version and that he installs it on staging (visitessen.instawp.xyz); write `.context/handover-media-credits-icon-galleries.md` (state, open obligations including any `hardening owed` lines, the "(call)" decisions) and update the memory file `media-credits-feature.md`.
