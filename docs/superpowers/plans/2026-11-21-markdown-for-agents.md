# Markdown for agents Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A theme module `inc/Markdown/` that serves a clean Markdown version of every eligible public page (`?markdown=1`, `Accept: text/markdown`, head link) and a site-wide `/llms.txt`, never showing more than the HTML page shows.

**Architecture:** The finished HTML response is captured by a template wrapper (`template_include`, latest priority), the Bricks content area (`id="brx-content"`) is converted with `WP_HTML_Processor`, and any doubt sends the captured HTML unchanged (spec D5). Pure parts (Accept rule, URL resolution, escaping, converter, `llms.txt` matching, loopback classification) are unit-tested; the wrapper's buffer and commit paths are tested through a real PHP-FPM process.

**Tech Stack:** PHP ≥ 8.0, WordPress ≥ 6.7 for the front end (theme floor 6.1), Bricks 2.4.5, WordPress HTML API; tests are plain PHP/Node scripts run by `./quality.sh`.

**Spec:** [docs/superpowers/specs/2026-11-21-markdown-for-agents-design.md](../specs/2026-11-21-markdown-for-agents-design.md) (D1–D17) · **Story:** [2026-11-21-markdown-for-agents-story.md](../stories/2026-11-21-markdown-for-agents-story.md) (AC-1 … AC-12).

**Risk:** standard, security high → no plan Gate A (`.claude/review-gates.md` line 4); Gate B after the build.

## Global Constraints

- Module folder `inc/Markdown/`, namespace `SFX\Markdown`; PSR-4 path case matches class case (invariant 1).
- Switch `sfx_general_options['enable_markdown']`, default `0` (D9, AC-1). Own option `sfx_markdown_options` = `['post_types' => string[]]`, default `['page', 'post']`, sanitized on every read.
- No runtime dependency, no `vendor/` at runtime (invariant 5). No regex or `DOMDocument` on HTML (AGENTS.md § Don'ts).
- Nothing in the module reads `User-Agent` (D3).
- Front end inert below WP 6.7: parser available ⇔ `class_exists('WP_HTML_Processor') && method_exists('WP_HTML_Processor', 'create_full_parser')` (D10).
- Markdown and `llms.txt` responses: `Cache-Control: private, no-store, max-age=0`, `DONOTCACHEPAGE` defined, `X-Robots-Tag: noindex` (D11, D12). Markdown also `Link: <canonical>; rel="canonical"`, `Content-Type: text/markdown; charset=UTF-8`.
- `llms.txt` marker header `X-SFX-Llms-Txt: 1`; `Content-Type: text/plain; charset=UTF-8`.
- Hook priorities: `template_include` `PHP_INT_MAX`; `llms.txt` at `template_redirect` `1`.
- Every new user-facing string in `sfxtheme` (invariant 4); escape at output (invariant 3); settings saved through `options.php` with `settings_fields()` (invariant 2), capability `manage_options`.
- Tests follow the existing style: one `tests/<name>-test.php` script, own stubs, `exit(1)` on the first failure; no test may pass without running.

## Decisions this plan settles

Gate A pass-5 findings (todos.md row "Markdown module: Gate A pass-5 findings to settle in the plan"), read against WP 7.1.4 `wp-includes/template.php:935-1124`:

- **P1 — enhancement filter once.** The wrapper applies `wp_template_enhancement_output_buffer` only on the conversion path, to a copy, and converts the filtered copy. Pass-through always echoes the *unfiltered* capture, so core's own outer buffer filters it exactly once. On commit, the wrapper's `ob_end_clean()` on core's buffer runs core's handler in the CLEAN phase, which returns early (no filter, no action); the wrapper then fires `do_action('wp_finalized_template_enhancement_output_buffer', $markdown)` itself, inside a discard buffer and a `try/catch (Throwable)`, only when `wp_should_output_buffer_template_for_enhancement()` is true — what core does for a non-HTML response (`template.php:1025-1029`).
- **P2 — non-removable outer buffer.** Before converting, every buffer below the capture buffer in `ob_get_status(true)` must carry `PHP_OUTPUT_HANDLER_REMOVABLE`; otherwise pass-through. (Narrower than the spec's "stop at the first non-removable"; conservative.)
- **P3 — capture intact.** The capture buffer gets a handler (spec said "no handler" — deviation) that returns its input unchanged and records every call. Any call before the wrapper itself ends the buffer (an `ob_flush()`, `ob_clean()`, or the template closing it, including close-and-reopen) marks the capture broken → pass-through. Together with the level check this detects every way a template can tamper with the capture.

Other choices made here (Claude's, recorded for the handover's Deviations list):

- **P4 — CommonMark meaning check:** `commonmark.js` 0.31.2 `dist/commonmark.js` vendored verbatim under `tests/support/commonmark/` (BSD-2, license file kept), like the vendored HTML API; a Node test renders the converter's output for adversarial cases. `quality.sh` exports `PHP` so the Node test can run the converter; a missing `PHP` fails the test. No Composer or npm dependency.
- **P5 — PHP-FPM for request-level tests:** resolved from `$PHP_FPM`, then `php-fpm` on PATH, then `/usr/sbin/php-fpm<major.minor of $PHP>`, `/usr/local/sbin/php-fpm`, `/opt/homebrew/sbin/php-fpm`, each probed with `-v`. None usable = the test fails (as for Node). setup-php installs `phpX.Y-fpm` on Ubuntu by default (Context7 `/shivammathur/setup-php`, `src/configs/php_packages`). The fallback path runs in a second pool with `php_admin_value[disable_functions] = fastcgi_finish_request,litespeed_finish_request`.
- **P6 — links around blocks** (Bricks cards: `<a><div><h3>…</h3><p>…</p></div></a>`): the link's content is flattened to one inline label, blocks joined by a space, written as its own paragraph. A link whose label is empty after conversion and holds an image becomes the image alone; with no image and no text it is dropped.
- **P7 — plugin detection for guidance:** Yoast `WPSEO_VERSION` (seen, `wp-seo-main.php:18`), AIOSEO `AIOSEO_VERSION`, SEOPress PRO `SEOPRESS_PRO_VERSION` — the last two INFERRED, builder confirms in the versioned zips (AIOSEO 5.0.5.1; for PRO, the free plugin's references to it).
- **P8 — Bricks builder detection:** `bricks_is_builder()`, `bricks_is_builder_iframe()`, `bricks_is_builder_call()` (Bricks 2.4.5 `functions.php:249-288`), each behind `function_exists`.
- **P9 — no feature doc yet:** `docs/features/` is `not yet written`; the build record says `absent` (living-docs § Homes: written when it gets content — the spec holds it).

## Review Focus

1. **Bricks button and icon markup** (`<a class="brxe-button"><i class="ti-arrow"></i><span>Text</span></a>`, inline `<svg>` icons) → `[Text](url)`, no tag remnant, no icon text. Test in Task 3.
2. **Link around a card** (P6) → one paragraph `[Title teaser](url)`, no broken heading or list syntax. Test in Task 3.
3. **Image-only link without alt** (logo links, gallery items) → `![](src)` or nothing, never `[](url)`. Test in Task 3.
4. **`markdown` query value that is not the string `1`** (`markdown[]=1`, `markdown=1 `, `markdown=true`) → HTML, no warning or notice. Test in Task 4.
5. **Deep nesting** (Bricks nests 30+ `div`s; test 300 levels) → converts without recursion error, same text as shallow. Test in Task 3.

## Sources and impact boundary

Read for this plan: the spec and its Sources; WP 7.1.4 `wp-includes/template.php:898-1124` (enhancement buffer, CLEAN phase early return, non-HTML action) and `wp-includes/html-api/` file list on the DDEV site; Bricks 2.4.5 `functions.php:249-288`, `includes/maintenance.php:42`; Context7 `/shivammathur/setup-php` (fpm package on Ubuntu, unversioned; action pinned at 2.37.2); `inc/EditorProse/` (Controller, Settings, AdminPage pattern), `inc/GeneralThemeOptions/Settings.php:89-96`, `inc/ThemeSettingsOverview/OverviewProvider.php:78`, `inc/DataPurge.php:170-174`, `inc/ImportExport/Controller.php:307-314`, `tests/support/html-api.php`, `quality.sh`, `.github/workflows/quality.yml`, `.claude/living-docs.md`; DDEV plugin list (Rank Math 1.0.282 and Yoast 28.9 installed, inactive). Context7 holds no versioned WordPress or Bricks reference (spec Sources); the implementer uses the local 7.1.4 / 2.4.5 sources for every core or Bricks call. Not read: AIOSEO and SEOPress PRO code (P7).

Impact: new `inc/Markdown/`; one field each in GeneralThemeOptions and the overview; one entry each in DataPurge and ImportExport; `tests/support/html-api/` refreshed to 7.1.4 plus the full parser's files; `quality.sh` exports `PHP`; docs (README, CHANGELOG, AGENTS.md, `.cursor/rules/feature-registry-structure.mdc`). No new module-to-module edge.

**Long runs:** none expected ≥ 45 minutes (battery ~1 min; FPM test bounded to 60 s by its own per-request 10 s socket timeout). **Handover boundary:** the completed story (one unit). **Actions outside the repository needing Daniel:** none — the DDEV site, Rank Math and Yoast are already installed; test-instance copy uses `SFX_TEST_SITE` from the environment.

---

### Task 1: Settings, switch, purge, export, overview

**Files:**
- Create: `inc/Markdown/Settings.php`, `inc/Markdown/Controller.php` (feature config only; hooks come in Task 5), `inc/Markdown/index.php` (silence file, as in EditorProse)
- Modify: `inc/GeneralThemeOptions/Settings.php` (field after `enable_editor_prose`), `inc/ThemeSettingsOverview/OverviewProvider.php`, `tests/support/overview-general-theme-options-settings-stub.php`, `inc/DataPurge.php`, `inc/ImportExport/Controller.php`
- Test: `tests/markdown-settings-test.php`; extend `tests/data-purge-test.php`, `tests/theme-settings-overview-provider-test.php`

**Interfaces:**
- Produces: `Settings::OPTION_NAME = 'sfx_markdown_options'`, `Settings::OPTION_GROUP`, `Settings::defaults(): array`, `Settings::get(): array`, `Settings::sanitize($raw): array`, `Settings::offered_post_types(): array<string,string>` (name ⇒ plural label, D14), `Settings::register(): void`; `Controller::get_feature_config(): array` with `activation_option_key => 'enable_markdown'`, `menu_slug => 'sfx-markdown'`.

- [ ] **Step 1: Write the failing tests** — `markdown-settings-test.php`: defaults `['post_types' => ['page','post']]`; non-array → defaults; post types filtered to D14 (stub `get_post_types` returns `page`, `post`, `attachment`, `bricks_template`, `cpt_public` (publicly_queryable), `cpt_private` (not)) → `sanitize(['post_types' => ['page','attachment','bricks_template','cpt_public','cpt_private','ghost','page',3]])['post_types'] === ['page','cpt_public']`; string `post_types` → `[]`; `get()` re-sanitizes a stored junk value. `data-purge-test.php`: `sfx_markdown_options` in `DataPurge::option_names()`. Overview test: `enable_markdown` listed, inactive by default, active when set, schema default `0`.
- [ ] **Step 2: Run** `php tests/markdown-settings-test.php` → FAIL (class missing); the two extended tests → FAIL.
- [ ] **Step 3: Implement** `Settings` on the EditorProse pattern (`register_setting` on `sfx_init_admin_features`); D14 = `get_post_types(['publicly_queryable' => true], 'objects')` plus `page`, minus `attachment`, `bricks_template`. Add the GeneralThemeOptions field (`label` "Enable Markdown for AI agents", default `0`, group `general`), the overview entry, the purge entry (comment `// Markdown`), the ImportExport entry `'markdown' => [... 'option_key' => 'sfx_markdown_options', 'type' => 'single']` with a comment that `Settings::get()` re-sanitizes. Controller: `get_feature_config()` only, literal strings.
- [ ] **Step 4: Run** the three tests → PASS; `./quality.sh` → `QUALITY: PASS`.
- [ ] **Step 5: Commit** `feat(markdown): settings, module switch, purge and export entries`.

### Task 2: URL resolution and escaping

**Files:**
- Create: `inc/Markdown/Url.php`, `inc/Markdown/Escape.php`
- Test: `tests/markdown-url-test.php`, `tests/markdown-escape-test.php`

**Interfaces:**
- Produces: `Url::resolve(string $ref, string $base, string $kind): ?string` — `$kind` is `'link'` (http, https, mailto, tel) or `'image'` (http, https); `null` = drop. `Escape::text(string $s): string` (inline context, every position), `Escape::block_start(string $line): string` (adds line-start escapes to an already `text()`-escaped line), `Escape::label(string $s): string` (newlines → spaces, then `text()`), `Escape::destination(string $url): string` (returns `<…>`), `Escape::code_span(string $s): string`, `Escape::fence(string $code): string` (returns the whole fenced block).

- [ ] **Step 1: Write the failing tests** — URL (stub `WP_Http::make_absolute_url` by copying the 7.1.4 method into a test stub class, verbatim): base `https://ex.test/sub/a/b/` — `'../c'` → `https://ex.test/sub/a/c`; `'?download=1'` → `https://ex.test/sub/a/b/?download=1`; `'//cdn.test/x.png'` → `https://cdn.test/x.png`; base `https://ex.test/?page_id=1120` — `'#s'` → `https://ex.test/?page_id=1120#s`, `''` → `https://ex.test/?page_id=1120`; base `https://ex.test/?p=5&page=2#top` + `'#s'` → `https://ex.test/?p=5&page=2#s`; `"  JaVaScRiPt:alert(1)"`, `"java\tscript:x"`, `"\x01javascript:x"` → `null` for both kinds; `'MAILTO:a@b.test'` link → kept, image → `null`; `'data:image/png;base64,AA'` image → `null`; `'tel:+49 1'` link → kept. Escape: `text('<img src=x>')` contains no unescaped `<`; `text('a*b_c[d]e`f|g&h!')` escapes each listed char; `block_start` escapes `# x`, `+ x`, `- x`, `= x`, `> x`, `12. x`, `3) x`, not `12x`; `label("a\nb")` → `a b`; `destination('https://x.test/a b<c>&d\\é')` → `<https://x.test/a%20b\<c\>\&d\\%C3%A9>`; `code_span('a`b')` → `` ``a`b`` ``; `code_span('`x')` → `` `` `x `` ``; `code_span('   ')` → `` `   ` ``; `fence("x\n```\ny")` uses a 4-backtick fence; `fence("~~~")` keeps `~~~` inside a backtick fence.
- [ ] **Step 2: Run** both tests → FAIL.
- [ ] **Step 3: Implement** per spec § Conversion "URLs" and "Escaping" (exact char lists there). Fragment-only and empty references are handled before `make_absolute_url()`; scheme check after resolution, case-insensitive, after stripping controls and whitespace.
- [ ] **Step 4: Run** both → PASS.
- [ ] **Step 5: Commit** `feat(markdown): URL resolution and Markdown escaping`.

### Task 3: Converter

**Files:**
- Create: `inc/Markdown/Converter.php`
- Modify: `tests/support/html-api/` — refresh the whole set verbatim from WP 7.1.4 (DDEV site `wp-includes/class-wp-token-map.php`, `wp-includes/html-api/*`) and add the full parser's files (`class-wp-html-active-formatting-elements.php`, `-open-elements.php`, `-processor-state.php`, `-processor.php`, `-stack-event.php`, `-token.php`, `-unsupported-exception.php`); `tests/support/html-api.php` loads them in `wp-settings.php` order and its header says 7.1.4. Stubs for core functions the processor calls go in the Markdown test, not in the shared loader.
- Test: `tests/markdown-converter-test.php`; the existing site-check tests must still pass on the refreshed set.

**Interfaces:**
- Consumes: `Url::resolve`, `Escape::*` (Task 2).
- Produces: `Converter::convert(string $html, string $base_url): ?string` — Markdown without trailing blank lines, ending in one `\n`; `null` = pass-through (parser error, `paused_at_incomplete_token()`, unsupported markup, zero/several/hidden/dropped root, empty result).

- [ ] **Step 1: Write the failing tests** — fixtures as inline HTML documents with `<header id="brx-header">`, `<main id="brx-content">`, `<footer id="brx-footer">`. Assert exact Markdown for: each mapped element of spec § Conversion; nested `ul` in `ol` with marker `10. ` (indent 4) and `- ` (indent 2); GFM table vs ragged table (` | ` lines); `figure` → image + caption paragraph; Bricks lazy image (`src="data:…" data-src="/a.png"`) → `![alt](<https://ex.test/a.png>)`; dropped: `script`, `style`, `svg`, `form`, `nav`, `[hidden]`, `[aria-hidden=true]`, `[role=navigation]`, `.bricks-pagination`, `id="brx-header"` inside the root; slider slides kept in order; whitespace collapse and max one blank line. Root cases → `null`: no root, two roots, root with `hidden`, root inside `nav`, empty root, truncated input (`<main id="brx-content"><p>a<`). Unclosed root followed by `<footer id="brx-footer">Foot</footer>` → output lacks `Foot`. Adversarial text: `&lt;img src=x&gt;` stays text, `&amp;`/`&copy;` text and in `href`, heading `# a #` trailing `#` escaped, backtick-only `code`. Review Focus 1, 2, 3, 5 (expected outputs as stated there; deep nesting: 300 nested `div`s around `<p>x</p>` → `x\n`). Builder/editor fixtures: a trimmed copy of the served HTML of `/` and `/editor-prose-test/` from the DDEV site saved under `tests/support/markdown-fixtures/` — assert headings present, no `brx-header` text, no `<`.
- [ ] **Step 2: Run** `php tests/markdown-converter-test.php` → FAIL.
- [ ] **Step 3: Implement** `convert()` with `WP_HTML_Processor::create_full_parser($html)` and `next_token()`; a stack of open blocks keyed by `get_current_depth()`; skip a dropped subtree by depth; text from `get_modifiable_text()`. No recursion (Review Focus 5). Catch `WP_HTML_Unsupported_Exception` and any `get_last_error()` → `null`.
- [ ] **Step 4: Run** converter test and the site-check tests → PASS.
- [ ] **Step 5: Commit** `feat(markdown): convert the Bricks content area to Markdown`.

### Task 4: CommonMark meaning check

**Files:**
- Create: `tests/support/commonmark/commonmark.js` (from the npm tarball `commonmark-0.31.2.tgz`, `dist/commonmark.js`, verbatim; tarball integrity checked against `registry.npmjs.org/commonmark/0.31.2` `dist.integrity`), `tests/support/commonmark/LICENSE`, `tests/support/markdown-convert-cases.php` (prints JSON `{case: markdown}` from `Converter::convert`), `tests/markdown-commonmark-test.mjs`
- Modify: `quality.sh` — `export PHP` after resolution (one line, comment why)

**Interfaces:**
- Consumes: `Converter::convert` (Task 3).

- [ ] **Step 1: Write the failing test** — the `.mjs` spawns `process.env.PHP` (missing → `assert.fail`), renders each case with commonmark.js (`createRequire` on the UMD file), and asserts on the rendered HTML: text `<img src=x>` yields no `<img`; text `[a](b)` yields no `<a`; line-start `1. `/`- `/`# `/`> ` text yields no `<ol>`/`<ul>`/`<h1>`/`<blockquote>`; a link whose URL has spaces, `<`, `>` keeps one `<a>` with the encoded href; `javascript:` never appears in an `href`; code spans with backticks render the exact original text in `<code>`; heading with trailing `#` keeps it in the text; `&amp;` text renders `&amp;` (i.e. a literal `&`), `&copy;` text renders a literal `&copy;`, not `©`.
- [ ] **Step 2: Run** `PHP=php node tests/markdown-commonmark-test.mjs` → FAIL (cases script missing).
- [ ] **Step 3: Implement** the cases script and the `quality.sh` export.
- [ ] **Step 4: Run** the test, then `./quality.sh` → PASS. Fix escaping in Task 2's code if a case fails.
- [ ] **Step 5: Commit** `test(markdown): CommonMark meaning check for converter output`.

### Task 5: Request rules and front-end hooks

**Files:**
- Create: `inc/Markdown/Request.php`, `inc/Markdown/Headers.php`
- Modify: `inc/Markdown/Controller.php`
- Test: `tests/markdown-request-test.php`

**Interfaces:**
- Produces: `Request::parser_available(): bool`; `Request::accepts_markdown(string $accept): bool` (D8); `Request::wants_markdown(): bool` (`$_GET['markdown'] === '1'` or D8 on `$_SERVER['HTTP_ACCEPT']`); `Request::is_eligible(): bool` (spec § Eligible request, P8); `Request::markdown_url(): ?string` (`wp_get_canonical_url()` + `markdown=1` via `add_query_arg`); `Headers::merge_vary(string $token): void` in `inc/Markdown/Headers.php` (reads `headers_list()`, writes one merged `Vary`). Controller constructor: `Settings::register()`, `AdminPage::register()` (Task 8; add the call there), and only when `Request::parser_available()`: `template_include` `PHP_INT_MAX` → `Response::maybe_take_over` (Task 6), `exit_on_http_head` → false when eligible and wanting Markdown, `wp_head` → link, `template_redirect` `PHP_INT_MAX` → `Vary: Accept` when eligible, `template_redirect` `1` → `LlmsTxt::maybe_serve` (Task 7).

- [ ] **Step 1: Write the failing tests** — Accept: `text/markdown` → true; `text/html, text/markdown` → true; `text/markdown;q=0.5, text/html` → false; `text/markdown;q=0.5, text/*;q=0.5` → true; `text/markdown;q=0.4, */*;q=0.5` → false; `text/*` → false; `*/*` → false; `text/markdown;q=0` → false; `text/markdown;q=abc` → true (malformed = 1); `text/markdown;q=0.1, text/markdown;q=1, text/html;q=0.5` → false (first occurrence); `TEXT/MARKDOWN` → true; a typical Chrome header → false. `wants_markdown`: Review Focus 4 values → false, no notice (error handler turns notices into failures). Eligibility, with query-function stubs: published page → true; `post_status` private, preview, posts-index page, `post_password_required`, feed, embed, robots, favicon, trackback, AJAX, REST, builder iframe, excluded post type, module parser missing → false each. Head link printed only when eligible, `esc_url`ed, containing `markdown=1`; `exit_on_http_head` filter returns false only for eligible + wanting; `merge_vary` keeps `Accept-Encoding, Cookie` and adds `Accept` once (run in a child `php -r` with `headers_list()` under CLI? — CLI `headers_list()` is empty: test `merge_vary` through a pure helper `Headers::merged_vary(array $headers_list, string $token): string` and keep `merge_vary` a one-line wrapper).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** `Request`, `Headers`, the Controller hooks.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(markdown): Accept rule, eligibility, head link and Vary`.

### Task 6: Response wrapper and request-level tests

**Files:**
- Create: `inc/Markdown/Response.php`, `inc/Markdown/wrapper.php` (one statement: `\SFX\Markdown\Response::run();`), `tests/support/fastcgi-client.php`, `tests/support/wp-template-enhancement.php` (core's three enhancement functions copied verbatim from 7.1.4 `template.php:898-1130`, GPL note as in `html-api.php`), `tests/support/markdown-fpm-app.php` (front script: WP stubs, module classes, scenario from `$_GET['scenario']`, emulated WP shutdown = `shutdown` action callbacks then core's `wp_ob_end_flush_all()` body), `tests/markdown-fpm-test.php`
- Test: also `tests/markdown-response-test.php` for the pure checks

**Interfaces:**
- Consumes: `Converter::convert`, `Request::*`, `Headers::*`.
- Produces: `Response::maybe_take_over(string $template): string` (eligible + wants Markdown → remembers `$template`, returns `__DIR__ . '/wrapper.php'`; else `$template`); `Response::run(): void` (capture → P3 handler → level check → P2 → P1 → convert → headers → commit, spec § Markdown response); `Response::passes_through(int $status, array $headers_list, bool $headers_sent): bool`; `fcgi_request(string $socket, array $params): array{status:int, headers:array<string,string>, body:string}`.

- [ ] **Step 1: Write the failing tests** — `markdown-response-test.php`: `passes_through` true for status 404, 301, a `Location` header, `Content-Type: application/json`, headers sent; false for 200 `text/html; charset=UTF-8` and for no Content-Type with `default_mimetype` `text/html`. `markdown-fpm-test.php`: resolve FPM (P5); temp dir with two pools (normal, fallback) on Unix sockets; `register_shutdown_function` teardown (kill FPM by PID file, remove temp dir) declared before FPM starts; a failed start or socket wait > 10 s fails the test. Scenarios, each one request, expected:
  - `convert` — status 200; body exactly the expected Markdown; `Content-Type: text/markdown; charset=UTF-8`; `Vary` contains `Accept` and the app's preset `Accept-Encoding`; `Cache-Control: private, no-store, max-age=0`; `X-Robots-Tag: noindex`; `Link` canonical; no `Content-Length`, `ETag`, `Last-Modified`.
  - `late-output` — `shutdown` callbacks at 1 and 10, a `register_shutdown_function` echo, and an outer handler appending `<!--outer-->`: body still exactly the Markdown.
  - `late-output` on the fallback pool — same body (discard buffer path).
  - `unclosed-root` — no footer text in the body.
  - `throw`, `leave-buffer-open`, `close-own-buffer`, `ob-flush`, `close-and-reopen`, `exit-in-template`, `non-removable-outer` (P2), `status-404`, `location`, `json` — `Content-Type` HTML (or the scenario's own), body equals the plain HTML render of the same scenario with the module off, no Markdown headers.
  - `enhance-convert` — an enhancement filter adding `<p>ENHANCED</p>` into the root: `ENHANCED` appears once in the Markdown; the finalized-action callback ran once with the Markdown (it sets header `X-Finalized: <md5 of its argument>`, compared with md5 of the body).
  - `enhance-passthrough` — no root: the filter's marker appears exactly once in the HTML body (P1).
- [ ] **Step 2: Run** `php tests/markdown-response-test.php` and `php tests/markdown-fpm-test.php` → FAIL.
- [ ] **Step 3: Implement** `Response` and the support files. The capture buffer: `ob_start($handler, 0, PHP_OUTPUT_HANDLER_STDFLAGS)`; the wrapper sets a flag before its own `ob_get_clean()` so the handler can tell its own end from tampering. Commit order: headers → remove outer buffers (P2 guaranteed removable) → P1 action → echo → `flush()` → finish function or discard buffer (`ob_start(static fn() => '')`) → `define('DONOTCACHEPAGE', true)` before echo if not defined.
- [ ] **Step 4: Run** both tests → PASS; `./quality.sh` → PASS.
- [ ] **Step 5: Commit** `feat(markdown): capture, convert and commit the Markdown response`.

### Task 7: `/llms.txt`

**Files:**
- Create: `inc/Markdown/LlmsTxt.php`
- Test: `tests/markdown-llms-txt-test.php`

**Interfaces:**
- Consumes: `Settings::get`, `Settings::offered_post_types`, `Escape::label`, `Escape::destination`, `Request::parser_available`.
- Produces: `LlmsTxt::headers(): array<string,string>` (the spec's five headers, also used by `maybe_serve`); `LlmsTxt::newest_listed_url(): ?string` (newest listed post's `?markdown=1`-less permalink, for Task 8); `LlmsTxt::matches(string $request_uri, string $home_path, int $status, ?string $matched_rule, bool $maintenance_applied): bool` (D13); `LlmsTxt::render(): string`; `LlmsTxt::maybe_serve(): void` (reads `$_SERVER['REQUEST_URI']`, `http_response_code()`, `$GLOBALS['wp']->matched_rule`, `\Bricks\Maintenance::is_applied()` — class or method missing ⇒ applied; sends spec headers, echoes, exits).

- [ ] **Step 1: Write the failing tests** — `matches` with home path `/`: `/llms.txt`, 404 → true; `/llms.txt?robots=1`, `/llms.txt?`, `/llms.txt?feed=rss2` → false; `/llms.txt/` → false; `/LLMS.txt` → false; status 410, 503, 302 → false; status 200 with matched rule `^llms\.txt$` → true; 200 with another rule → false; maintenance applied → false. Home path `/sub/`: `/sub/llms.txt` → true, `/llms.txt` → false. `render`: site name `A *B*` and tagline escaped; section headings from plural labels; line `- [Title \[x\]](<https://ex.test/p/?markdown=1>)`; listed only `publish`, no password, included type, not posts-index; pages by `menu_order` then title, posts newest first (assert the `get_posts` args the stub receives: `post_status => 'publish'`, `has_password => false`, `numberposts => 501`); 501 found → 500 lines plus one "more exist" line; no tagline → no `>` line.
`headers()` equals exactly `Content-Type: text/plain; charset=UTF-8`, `X-Robots-Tag: noindex`, `Cache-Control: private, no-store, max-age=0`, `X-SFX-Llms-Txt: 1`; `newest_listed_url()` → `null` when nothing is listable.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement**; the 500 cap via fetching 501; `maybe_serve` defines `DONOTCACHEPAGE` and `status_header(200)`.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(markdown): serve /llms.txt for addresses nothing else claimed`.

### Task 8: Settings page with loopback checks

**Files:**
- Create: `inc/Markdown/AdminPage.php`, `inc/Markdown/LoopbackCheck.php`
- Modify: `inc/Markdown/Controller.php` (`AdminPage::register()`)
- Test: `tests/markdown-loopback-test.php`, `tests/markdown-admin-page-test.php`

**Interfaces:**
- Produces: `LoopbackCheck::classify_llms($response): array{outcome:string, detail:string}` with outcomes `theme`, `redirected`, `none`, `failed`, `html`, `foreign` (spec § `/llms.txt`, Settings-page check); `LoopbackCheck::classify_cache($control, $warm, $accept): string` with `no_convert`, `cache_html`, `clean`, `failed`; `LoopbackCheck::guidance(): string[]` (P7, active plugins only, plus the "remove the file" line); `AdminPage::$menu_slug = 'sfx-markdown'`, `AdminPage::render_page(): void`. Requests: `wp_remote_get($url, ['timeout' => 5, 'redirection' => 0, 'sslverify' => apply_filters('https_local_ssl_verify', false)])`, cache third request with `headers => ['Accept' => 'text/markdown']`.

- [ ] **Step 1: Write the failing tests** — classify_llms: `WP_Error` → `failed`; 200 + marker → `theme`; 301 with Location `/login` → `redirected`, detail holds `/login`; 404, 410 → `none`; 500 → `failed`; 200 `text/html` no marker → `html`; 200 `text/plain` no marker → `foreign`; 200 no content type → `failed`; 200 `application/json` → `failed`. classify_cache: control not `text/markdown` → `no_convert`; control redirect or error → `failed`; control Markdown + third `text/html` → `cache_html`; both Markdown → `clean`. guidance: with `WPSEO_VERSION` defined → Yoast line; none defined → only the file line. Admin page: capability gate (`AccessControl::die_if_unauthorized_theme` called), form posts to `options.php` with `settings_fields(Settings::OPTION_GROUP)`, checkboxes for offered types with escaped labels, WP < 6.7 notice when the parser is missing, checks not run (no `wp_remote_get` call) when the module is off or the parser missing, "no page to check" when `LlmsTxt::newest_listed_url()` is `null`, CDN limit sentence present, the three example addresses for this site shown (`?markdown=1` of the newest listed post, the `Accept` sentence, `home_url('/llms.txt')`), each `esc_url`/`esc_html`ed.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** on the `EditorProse\AdminPage` pattern; all output escaped at the echo.
- [ ] **Step 4: Run** → PASS; `./quality.sh` → PASS.
- [ ] **Step 5: Commit** `feat(markdown): settings page with llms.txt and cache checks`.

### Task 9: Docs and build record

**Files:**
- Modify: `README.md` (module list), `CHANGELOG.md` (`## [Unreleased]`), `AGENTS.md` (§ What this is: "Markdown versions of pages for AI agents with `/llms.txt`"; § Architecture: ImportExport "fourteen"), `.cursor/rules/feature-registry-structure.mdc` (module line)
- [ ] **Step 1:** Edit the docs; `sh scripts/check-doc-links.sh` → OK; `./quality.sh` → PASS (docs-runtime-claims test included).
- [ ] **Step 2:** Write the living-docs build record (one line each: `docs/PRD.md`, `docs/ARCHITECTURE.md`, `docs/TECH-STACK.md`, `docs/features/` → `absent`; README, CHANGELOG, AGENTS.md, CLAUDE.md → status and what was checked; date-stamped effort line) into `.context/handover-markdown.md`, for Gate B and the PR body.
- [ ] **Step 3: Commit** `docs(markdown): module in README, CHANGELOG and AGENTS.md`.

### Task 10: Live verification and abuse path (AC-12, AC-5)

**Files:**
- Create: `tests/support/markdown-live-check.sh` (manual harness; not in the battery)
- Evidence: `.context/markdown-live-check-<date>.md` (commands, status lines, headers, body excerpts)

- [ ] **Step 1: Write the harness** — `set -euo pipefail`; site path, URL and user from `SFX_TEST_SITE` and friends (never in the repo); a fatal guard that `ddev wp option get siteurl` answers from that path; **one** `trap` teardown declared before the first change, restoring each recorded prior state: `enable_markdown`, Rank Math active state and its llms module, the theme's password protection option, Bricks maintenance setting, the fixture posts it created (draft, pending, future, private, trashed, password, paginated) deleted by recorded IDs.
- [ ] **Step 2: Run** `SFX_TEST_SITE=… bash bin/test-instance.sh refresh`, then the harness. It checks, per spec § Verification: front page and post 1120 (`?markdown=1` body and headers, head link, `Accept` response, browser `Accept` → HTML + `Vary: Accept`, `llms.txt` lines); Rank Math llms on → theme's version (marker), module off → Rank Math's; lazy images; paginated part 2; `HEAD` with both ways → same headers as `GET`; editor session (cookie via `ddev wp` auth cookie for the configured user) on private post and draft preview → HTML, no head link; every abuse-path case of the spec, each compared with the HTML request of the same visitor and the module-off baseline. Each check prints `PASS`/`FAIL`; the script exits non-zero on any `FAIL`.
- [ ] **Step 3:** Record the run in the evidence file; confirm afterwards with `ddev wp` that every restored option equals its recorded prior value.
- [ ] **Step 4: Commit** `test(markdown): live check harness for AC-12 and the abuse path`.

After Task 10: Gate B (`.claude/review-gates.md`), PR, `/dev-workflow:process-pr-review`, squash-merge, handover (autonomous run, checkpoints none).
