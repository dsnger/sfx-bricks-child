# Markdown versions of pages for AI agents — Design

Story: [2026-11-21-markdown-for-agents-story.md](../stories/2026-11-21-markdown-for-agents-story.md)
(level 1 — risk standard, security high; evidence battery+check+verification+abuse-path).
**Gate A:** runs — risk standard; security high; evidence battery+check+verification+abuse-path.

## Intent

AI tools read pages more cheaply as Markdown than as HTML. Client sites are built with Bricks,
and the plugins tried on the demo site either return an empty body for Bricks pages or leave raw
Bricks markup behind (story § 1). This module gives every eligible public page a clean Markdown
version of its main content, makes it discoverable (fixed address, head link, `Accept`
negotiation, `/llms.txt`), and never shows anyone more than the HTML page would show them.
Selling point: "works with Bricks". That AI tools prefer Markdown is not established and not
promised (story § 2).

## Goals, non-goals, anti-goals

- **Goals:** the story's AC-1 … AC-12.
- **Non-goals:** Markdown for archives, the blog index, search results, feeds or the REST API;
  user-agent sniffing of any kind (owner decision); statistics of which agents fetched what;
  pre-generated files on disk; an `llms-full.txt`; per-page exclusion or per-page editing of the
  Markdown; honouring SEO plugins' `noindex` flags in `llms.txt` (a `noindex` page is still public,
  so listing it leaks nothing); converting interactive constructs (forms, maps, embeds, media
  players are dropped; slider slides are kept as plain content, see Conversion); `srcset` and
  responsive image variants; `<base href>` (Bricks does not emit one).
- **Anti-goals (a failure even if it technically works):** any content of a page reaching a
  visitor through this module that its HTML response would not show the same visitor, or any
  content or metadata of a post that visitor may not see at all (the `llms.txt` index lists stored
  titles of published public posts, which WordPress itself publishes to everyone — REST API, core
  sitemaps, feeds — even where a Bricks SEO title replaces the document title; Claude's call,
  Gate A pass 3); Markdown on
  a request that asked for neither the Markdown address nor Markdown by `Accept` (so a browser
  following ordinary links never gets it); a change to the HTML page beyond one `<link>` in the
  head and `Accept` added to its `Vary` header; a fatal or a blank page when the HTML API is
  missing or a page's markup is odd; deleting or rewriting files an SEO plugin owns.

## Decisions

Owner decisions (Daniel, 2026-11-21, in conversation; recorded in the story and the handover):

- **D1 — own theme module**, `inc/Markdown/`, not a plugin and not part of another module.
- **D2 — three ways in:** a fixed address per page with a head link to it, `Accept: text/markdown`
  on the normal address, and a site-wide `/llms.txt`.
- **D3 — no user-agent sniffing.** Nothing in this module reads `User-Agent` (cloaking risk).
- **D4 — `llms.txt`: the theme takes priority** over an SEO plugin's wherever that is technically
  possible; where it is not, the settings page names the plugin and says how to switch its version
  off.

Calls made in this run (autonomous run approved; Claude's choices, recorded per CLAUDE.md §4):

- **D5 — convert the finished response, store nothing.** The Markdown is made from the very HTML
  WordPress and Bricks produce for this request, captured by an output buffer, at the end of the
  normal pipeline. Why: freshness (AC-6) holds by construction, and protection (AC-5) follows from
  one rule — the module converts only a complete `200` response of an eligible page and otherwise
  sends the captured response unchanged — so every decision WordPress, Bricks and the theme take
  (404 for drafts, the site-wide password gate, Bricks template and element conditions, Bricks
  maintenance mode, redirects) reaches the visitor exactly as for HTML. Bricks templates are
  covered (story § 5, first question) because the template that renders the page is the one
  converted. Price: a Markdown request costs one normal page render.
- **D6 — the main content is the Bricks content area, the element with `id="brx-content"`.**
  Bricks renders every content path into it and header and footer templates outside it: Bricks
  pages (`Frontend::render_content()`, `includes/frontend.php:1378`), the block-editor fallback
  (`single.php:25,30`), the 404 page (`404.php:9`) — read in Bricks 2.4.5 on the demo site, and
  seen in the served HTML of the front page and of the block-editor post 1120. Matched by `id`,
  not by tag name.
- **D7 — the fixed address is the page's canonical URL with `?markdown=1`.** WordPress resolves
  the post exactly as for the HTML page, so every protection runs unchanged; no rewrite rule or
  `.md` suffix (a suffix would collide with hierarchical page paths and need request-URI
  rewriting). The key is read from `$_GET` (value exactly `1`), not registered as a query var.
- **D8 — `Accept` rule:** Markdown on the normal address only when `Accept` lists `text/markdown`
  explicitly (not via `text/*` or `*/*`) with `q > 0`, and that `q` is not lower than the effective
  quality of `text/html` — the `q` of the most specific of `text/html`, `text/*`, `*/*` present
  (RFC 9110 § 12.5.1), 0 when none is. A malformed parameter counts as `q=1`; a repeated range uses
  its first occurrence. Browsers never list `text/markdown`, so they always get HTML.
- **D9 — one switch, one option.** The module is switched on by `enable_markdown` in the theme's
  general options, default off like every recent module. AC-1's "after a theme update" means an
  update never switches it on; an administrator's own choice is kept across updates, like every
  other module switch. Its own settings are one option, `sfx_markdown_options`, holding the
  included post types (default `page` and `post`). It is purged by `DataPurge` and exported by
  `ImportExport` (nothing secret or site-local in it).
- **D10 — parser: `WP_HTML_Processor::create_full_parser()`** (WP ≥ 6.7), WordPress' spec-compliant
  HTML parser, which builds the element tree (`get_current_depth()`, `get_breadcrumbs()`) the
  Tag Processor does not; no regular expression or `DOMDocument` on HTML (AGENTS.md § Don'ts).
  When it stops with an error or unsupported markup, the response goes out unchanged (D5's rule).
  Feasibility probe on the demo site (WP 7.1.4): both the front page (190 KB) and post 1120
  (158 KB) parse to the end without error in ≤ 0.01 s. The theme declares WP 6.1; below 6.7 the
  whole module is inert on the front end (no Markdown, head link, `Vary` or `llms.txt`) and its
  settings page says why.
- **D11 — caching.** Every Markdown and `llms.txt` response sends `Cache-Control: private, no-store,
  max-age=0` (set explicitly, because `nocache_headers()` only gained `no-store`/`private` in WP 6.8)
  and defines `DONOTCACHEPAGE`, so no cache stores either. The HTML response of every eligible page
  gets `Accept` added to its `Vary` header. Accepted limit: a cache that answers before WordPress
  runs and ignores `Vary` can hand cached HTML to a Markdown request through `Accept`. That gives
  the agent the HTML page, never content it should not see, and never Markdown to a browser (no
  Markdown is ever stored). The `?markdown=1` address is a separate URL and avoids this.
- **D12 — Markdown responses carry `X-Robots-Tag: noindex`** and a `Link: <canonical URL>;
  rel="canonical"` header, so search engines keep the HTML page and never list the Markdown copy as
  duplicate content.
- **D13 — `llms.txt` is served only for an address nothing else claimed.** Exactly the path
  `<home path>llms.txt`, no query string at all, and WordPress' own response status at that point
  is 404 (no page, no redirect rule, no 410, no maintenance answer took the address) — or, with
  Rank Math's llms module on, the request matched Rank Math's own rewrite rule
  (`$wp->matched_rule === '^llms\.txt$'`) and the status is 200. In both cases Bricks maintenance
  must not apply to this request (`\Bricks\Maintenance::is_applied()`, a public static; a Bricks
  without that method counts as "applied", so the index is not served). The query-string rule
  closes `?robots=1` and `?feed=…`, which would exempt the request from the site-wide password
  gate. Rank Math is not switched off: the theme answers its address first (priority 1 before its
  10), and nothing of Rank Math's stored state changes (D4).
- **D14 — post types offered** are public types that can be viewed singly (`publicly_queryable`,
  plus `page`), except `attachment` and Bricks' `bricks_template` (public, but Bricks denies
  anonymous views, `includes/templates.php:76`, `includes/frontend.php:1253-1260`).

- **D15 — SEOPress PRO guidance is general** (Daniel, 2026-11-21, answering the open question
  from Gate A pass 1): "switch off llms.txt in SEOPress PRO's settings", without a menu path; AC-8
  narrowed accordingly in the story with a dated note.
- **D16 — AC-9 narrowed** (Daniel, 2026-11-21, after Gate A pass 2): the guarantee is D11's —
  Markdown is never stored, so never reaches a browser; a cache that answers before WordPress and
  ignores `Vary` may hand HTML to an `Accept` request. The settings page tests for that and says
  so (Settings screen); the `Accept` way stays on, because nothing breaks: the agent gets the
  normal page, as without the module.
- **D17 — SEOPress PRO precedence stays untested** (Daniel, 2026-11-21): the theme answers at
  priority 1; if PRO answers earlier or writes a file, the settings-page check reports a foreign
  version and D15's guidance applies. Agent Readiness' interaction is likewise unknown.

## Approaches considered

1. **Convert the finished response (chosen, D5).** Faithful to what the visitor gets, one code
   path for Bricks and the block editor, fresh and protected by construction. Costs a full render
   per Markdown request.
2. **Render Bricks data directly** (`Helpers::get_bricks_data()` + `Frontend::render_data()`).
   Cheaper, but misses which template and which conditions apply, needs a second path for
   block-editor content, and reaches into Bricks internals the project avoids.
3. **Pre-generate on save and store** (the first tested plugin's way). Fast reads, but stale
   whenever anything besides the post changes, and a stored copy needs its own visibility check —
   the very risk this module must not take.

## Behaviour

### Eligible request

One function decides, used by the Markdown response, the head link and `Vary`:

- the module is on and D10's parser is available;
- `is_singular()` of an included post type (D14), the main query found a post with status
  `publish`, and that post is not the page set as the posts index — private posts and previews
  (`is_preview()`) are excluded, so they always get HTML and the canonical URL always exists;
- `! post_password_required()` for that post;
- not a builder/preview frame of Bricks, not admin, AJAX, REST, cron, feed, embed, trackback,
  robots, favicon or sitemap request (`is_feed()`, `is_embed()`, `is_trackback()`, `is_robots()`,
  `is_favicon()`, `wp_doing_ajax()`, `REST_REQUEST`, `wp_is_serving_rest_request()` where present).

Whether this visitor may see the post is not re-decided here: the main query only finds what this
visitor may see, and everything after the query is covered by D5's rule.

### Markdown response

A request asks for Markdown when `$_GET['markdown'] === '1'` or D8's rule holds. A `HEAD`
request that does both takes the same path: the module turns off WordPress' early exit for it
(`exit_on_http_head` filter, `template-loader.php:36`), so its headers are exactly those of the
`GET` response; the server sends no body.

**Capture, synchronously.** On an eligible request that asks for Markdown, a `template_include`
filter at the latest priority — after every `template_redirect` handler (the site-wide password
gate at -10, Redirects at 0, Bricks' template guards at 10) — remembers the template WordPress chose
and returns the module's own wrapper file instead. WordPress then fires
`wp_before_include_template` (core's enhancement buffer starts, WP 6.9+) and includes the wrapper,
which:

1. opens one plain output buffer (no handler) and includes the remembered template inside it;
   while the template runs the response stays an ordinary HTML response, so the HTML pipeline runs
   as for any visitor;
2. if the template throws, releases what it captured unchanged and rethrows — as without the module;
3. when the template returns, checks that the buffer level is exactly the one it opened (the
   template left no buffer open and closed none of the module's); otherwise releases everything
   unchanged (pass-through);
4. takes the captured HTML and, where core would enhance this template
   (`wp_should_output_buffer_template_for_enhancement()`, WP 6.9+), passes it through core's public
   `wp_template_enhancement_output_buffer` filter with the same two arguments core uses
   (`template.php:1085`), so the module converts what the visitor's HTML would have been;
5. decides pass-through or conversion (the steps below). Pass-through echoes the captured HTML back
   into the outer buffers, unchanged, so the response is exactly the HTML response.

A template that calls `exit` never returns to the wrapper: PHP runs the shutdown, WordPress'
`wp_ob_end_flush_all()` releases the module's buffer as it is, and the visitor gets the HTML response
with HTML headers. Completion is therefore "the template returned", decided by the wrapper itself, not
by any output-handler phase.

**Commit.** After a successful conversion the wrapper sets the headers (step 4 below), then removes
every outer output buffer that PHP reports as removable (`ob_get_status(true)`), discarding what
they hold — only output written before the template, which is not part of the content area — and
stopping at the first one that is not removable; it echoes the Markdown and flushes. Then it ends
the response where the server supports it: `fastcgi_finish_request()` (PHP-FPM, also DDEV) or
`litespeed_finish_request()` (LiteSpeed). From that point the client has the complete response, and
whatever other code writes later during shutdown never reaches it. Where neither function exists
(for example Apache with mod_php), the wrapper opens a buffer whose handler returns the empty
string, which drops later output unless other code drains buffers again; **accepted limit (owner,
2026-11-21):** on such servers, output that other code writes during shutdown after that can still
follow the Markdown body — as it would follow `</html>` on any WordPress page. The verification runs
on PHP-FPM (DDEV), and the battery covers the fallback path.

Converting:

1. **Pass-through checks** — any failing check sends the captured response unchanged, with its
   original headers: headers already sent; status other than 200; a `Location` header present; a
   `Content-Type` other than `text/html`.
2. **Root** — parse the captured response (D10); exactly one element with `id="brx-content"` must
   exist, outside any dropped element (below) and not itself hidden; the parser must reach the end
   without an error and without `paused_at_incomplete_token()`. Zero, several, a hidden or dropped
   root, an incomplete token or a parser error: pass-through. The parser closes elements still
   open at the end of input on its own, so an unclosed root is not detectable; instead, Bricks'
   header and footer areas (`id="brx-header"`, `id="brx-footer"`) are dropped wherever they appear,
   so an unclosed root can never pull the site header or footer into the Markdown. **Narrowed
   promise (Claude's call, Gate A pass 4):** when the content area's own closing tag is missing in
   the markup, whatever the template prints after it — other than those two areas and the dropped
   elements — is included; with well-formed markup, which Bricks emits, nothing outside the content
   area is. An unclosed element inside the root only changes nesting, not which content is included.
3. **Convert** the root's content (Conversion, below). An empty result: pass-through. No title or
   other metadata is added: the Markdown holds only what the content area shows.
4. **Headers** — remove `Content-Length`, `ETag` and `Last-Modified`; set `Content-Type:
   text/markdown; charset=UTF-8`; merge `Accept` into the existing `Vary` value; add the headers of
   D11 and D12; then the Commit above. Headers may still be set here: nothing has been sent
   (checked in step 1).

### Conversion

The parser walks the root's subtree; the module keeps a stack of open converted blocks keyed by
the parser's depth, so closing an element always ends exactly what it opened.

- **Mapped:** `h1`–`h6` → `#`…`######`; `p` → paragraph; `br` → hard line break; `ul`/`ol`/`li` →
  lists, where a nested list and a continuation line are indented by the width of the parent's
  marker plus its space (`- ` = 2, `1. ` = 3, `10. ` = 4); `a[href]` → `[text](url)`; `img` →
  `![alt](url)` with an empty alt when none is set; `strong`/`b` → `**`, `em`/`i` → `*`;
  `blockquote` → `> `; `pre` → fenced code block, `code` → inline code; `hr` → `---`; `table` → GFM
  table when every row has the same number of cells, else one line per row, cells separated by
  ` | `; `figure`/`figcaption` → the image followed by the caption as a paragraph.
- **Images, Bricks lazy loading:** Bricks replaces `src` with a `data:` placeholder and keeps the
  real address in `data-src` (`includes/frontend.php:1187-1226`); when `src` is missing or a
  `data:` URL and `data-src` is set, `data-src` is used.
- **Dropped with their whole subtree:** elements with `id="brx-header"` or `id="brx-footer"`; `head`, `script`, `style`, `noscript`, `template`, `svg`,
  `math`, `iframe`, `form`, `select`, `textarea`, `button`, `nav`, `video`, `audio`, `canvas`,
  `object`, `embed`, `dialog`; any element with the `hidden` attribute, `aria-hidden="true"` or
  `role="navigation"`; Bricks pagination (`class` contains `bricks-pagination`,
  `includes/helpers.php:1192-1207`).
- **Everything else** is unwrapped: its text stays, its tag goes. Slider slides therefore stay as
  ordinary content, in document order.
- **URLs** (`href`, `src`, `data-src`): decoded by the parser; control characters and surrounding
  whitespace removed; resolved against the request's canonical URL as a browser would: an empty
  reference or a fragment-only reference (`#section`) is the base URL without its fragment plus
  that fragment, keeping the base's query (`?page_id=1120#section` on plain permalinks — which
  `WP_Http::make_absolute_url()` would drop, `class-wp-http.php:973-1054`); every other reference
  (`../contact`, `?download=1`, protocol-relative) goes through `WP_Http::make_absolute_url()`; then the scheme is checked against an allowlist — links `http`, `https`,
  `mailto`, `tel`; images `http`, `https`. A URL failing any step is dropped: a link keeps its
  text, an image keeps its alt text as plain text.
- **Escaping, by output context** — text is decoded by the parser, then serialized so it cannot
  become structure or HTML:
  - prose, headings, list items, link and image labels, table cells, and `llms.txt` titles:
    backslash-escape `\` `` ` `` `*` `_` `~` `[` `]` `<` `>` `|` `&` and `!` before `[` anywhere,
    and at a line start additionally `#`, `+`, `-`, `=`, `>`, and a digit run followed by `.` or
    `)`; a heading's trailing `#` is escaped; newlines in labels, cells and titles become spaces;
  - URL destinations: written inside `<…>`; `\`, `<`, `>` and `&` backslash-escaped; space, control
    characters and any non-ASCII byte percent-encoded;
  - inline code: delimited by one more backtick than the longest run inside, with one space of
    padding on both sides when the content starts or ends with a backtick or a space — except
    content made only of spaces, which is written as it is; fenced blocks
    by a backtick fence of at least three, and one longer than the longest backtick run inside.
  No HTML tag can reach the output: tags are never copied, and a literal `<` in text is escaped.
- Whitespace collapses as a browser renders it outside `pre`; at most one blank line between
  blocks.

### Discovery

- **Head link:** on the HTML response of an eligible request, `wp_head` prints
  `<link rel="alternate" type="text/markdown" href="…">` with the request's canonical URL
  (`wp_get_canonical_url()`, which keeps the page part of a paginated post) plus `markdown=1`,
  escaped at output. Paginated singular posts (`<!--nextpage-->`) therefore have one Markdown
  address per part, as they have one HTML address per part.
- **`Vary`:** `Accept` merged into the HTML response's `Vary` of an eligible request (D11).

### `/llms.txt`

Served under D13's conditions, at `template_redirect` priority 1 — after the site-wide password
gate (-10) and the Redirects module's send (0), before Rank Math's handler (10). It needs D10's parser too (otherwise it would list addresses that serve no
Markdown). Status 200, `Content-Type: text/plain; charset=UTF-8`, `X-Robots-Tag: noindex`, D11's
cache headers, and a header `X-SFX-Llms-Txt: 1` that lets the settings page recognise the theme's
version. It then exits, so the Redirects 404 log (9999) never records it.

Content, following the llmstxt.org layout: `# <site name>`, `> <tagline>` when set, then one
`## <post type plural label>` section per included post type with one line per post,
`- [<title>](<permalink with markdown=1>)`, titles escaped as above. Listed: status `publish`, no
post password, an included type (D14), not the posts-index page. The titles are those WordPress
itself already publishes for such posts to every visitor (REST API `/wp/v2/<type>`, core sitemaps,
feeds); the index adds none of a post that is not public. Pages ordered by menu order, then
title; other types newest first; at most 500 per type, then a line saying more exist. The list is
the same for every visitor who gets past the password gate.

**Precedence over SEO plugins (D4)** — read in each plugin's code, not run (Sources):

- **Rank Math** serves `/llms.txt` through a rewrite rule and `template_redirect` priority 10, no
  file. The theme answers that address first (D13) and exits, so Rank Math's handler never runs;
  switching the theme module off, or WordPress below 6.7, leaves Rank Math's version working,
  because nothing of Rank Math's is changed.
- **Yoast SEO** and **All in One SEO** write a real `llms.txt` into the site root. The web server
  delivers a real file before WordPress runs, so the theme cannot outrank them. The theme never
  deletes or rewrites that file (anti-goal).
- **Settings-page check:** when the settings page loads with the module on and D10's parser
  available, it requests `home_url('/llms.txt')` once (loopback, 5 s timeout, no redirects
  followed) and reports exactly one outcome:
  - *theme's version* — status 200 with header `X-SFX-Llms-Txt: 1`;
  - *redirected* — a 3xx response: "/llms.txt redirects to <Location>" — for example the site-wide
    password gate's login, or a Redirects rule; no cause is guessed;
  - *nothing answers* — 404 or 410: "no llms.txt is served; check Redirects rules for /llms.txt";
  - *transport failure*, any other status, or a 200 whose content type is missing or neither HTML
    nor `text/plain`: "could not check", with what came back;
  - *an HTML page answers* — status 200 with an HTML content type and no marker (for example a
    maintenance or coming-soon page): "an HTML page answers at /llms.txt", no plugin guidance;
  - *another version* — status 200, a `text/plain` content type, no marker. Only this outcome gives the guidance, per
    active plugin: Yoast
  "Yoast SEO → Settings → llms.txt: switch off 'Enable llms.txt file feature'" (Yoast deletes its
  own file; one it did not write stays), AIOSEO "All in One SEO → Sitemaps → LLMs.txt: switch off
  'Enable llms.txt'" (AIOSEO deletes the file on save), SEOPress PRO "switch off llms.txt in SEOPress PRO's settings" (no menu path, D15); and in
  every case "if a file llms.txt stays in the site root, remove it there".
- **SEOPress:** the free plugin has no `llms.txt` code; the feature is in SEOPress PRO, whose code
  is not public and whose guide link in the free plugin (`DocsLinks.php:81-82`) returns 404. Its
  serving mechanism and switch-off path are not known; the settings-page check still detects
  whether the theme's version answers, and the guidance is general (D15).
- **The SEO Framework:** no `llms.txt` (story § 1).
- **SEOPress "Agent Readiness"** (story § 5, third question): PRO-only per its readme; whether it
  serves Markdown and would conflict is unknown. Free Yoast, Rank Math, SEOPress and AIOSEO serve
  no Markdown pages (no `text/markdown` in their code).

### Settings screen

A submenu page under the theme's settings (pattern of `EditorProse\AdminPage`), capability
`manage_options`, saved through the Settings API (`options.php`, which checks nonce and capability —
invariant 2). It shows: the included post types (D14); the three addresses as examples for this
site; a notice when WordPress is older than 6.7 (D10); the `llms.txt` check above; and the
**cache check** (D16), only with the module on and D10's parser available. It takes the newest
post of an included type that `llms.txt` would list (none: "no page to check") and requests by
loopback, without following redirects: first its `?markdown=1` address as the control — not
`text/markdown`: "this page does not convert, so the check cannot run" (for example markup the
module passes through); then its normal address, so a cache can store it; then the normal address
with `Accept: text/markdown`. Only when the control converted and the third answer is not
`text/markdown` does it show "your page cache answers Markdown requests by `Accept` header with
HTML; the `?markdown=1` address still works". `llms.txt` availability is reported by its own check,
not here. Its limit is stated next to it: a CDN in front of the site often never sees a loopback
request, so a clean result proves nothing about the CDN. A failed or redirected loopback shows
"could not check".

### Data

- `sfx_general_options['enable_markdown']` (existing option; new GeneralThemeOptions field,
  default 0).
- `sfx_markdown_options` = `['post_types' => string[]]`, sanitized on every read to D14's types.
  Added to `DataPurge` and `ImportExport` (D9).

## Verification (evidence mode battery+check+verification+abuse-path)

- **Battery:** unit tests for the converter (each mapped element; dropped elements and hidden or
  navigation ancestry; a hidden, dropped, duplicated, missing or unclosed root; nested and mixed
  lists with multi-digit markers; adversarial text in every escaping context — `<img src=x>` as
  text, brackets, backticks, line-start markers, newlines in titles; URL resolution for nested
  paths, subdirectory installs, query-only, fragment and protocol-relative references; scheme
  allowlist with mixed case and control characters; fragment-only and empty references on plain
  permalinks (`?page_id=…`, `?p=…&page=2`); code spans and fences whose content starts, ends or is
  made of backticks, `~~~` lines, `&amp;`/`&copy;` as text and in URLs, trailing `#` in headings —
  checked for the meaning a CommonMark renderer gives, not only the bytes; Bricks `data-src`
  images); the wrapper's paths (template returns; template throws; template leaves a buffer open or closes
  one; `exit` inside the template — each pass-through sends HTML unchanged with HTML headers; the
  enhancement filter is applied when core would apply it); request-level tests through a real
  PHP-FPM process (not unit doubles) — a converted page plus a `shutdown` callback at priorities 1
  and 10, a PHP shutdown function, and an outer handler that appends markup, all echoing HTML: the
  wire body is exactly the Markdown with Markdown headers; the same with neither finish function
  (fallback path): a late echo is dropped by the discard buffer; an unclosed root followed by
  `id="brx-footer"`: the footer text is absent; the
  `Accept` rule
  (D8, including `text/*`/`*/*` precedence and malformed `q`); the pass-through checks; header
  finalization (`Vary` merge, removed length and validators); `llms.txt` matching (D13: query
  strings, trailing slash, subdirectory home, prior status 410/503/200, Rank Math's matched rule,
  Bricks maintenance applied) and rendering; every outcome of the settings-page `llms.txt`
  check, including a 200 with a missing or other content type; settings
  sanitizing; purge and export lists (existing tests extended).
- **Check:** `./quality.sh` green.
- **Verification (AC-12)** on the DDEV demo site with the module on, against the real server: for
  the Bricks front page and the block-editor post 1120 — the `?markdown=1` body and headers, the
  head link, the `Accept: text/markdown` response, a browser-like `Accept` on the same URL still
  getting HTML with `Vary: Accept`, the `llms.txt` entry; `llms.txt` with Rank Math's llms module
  on (the theme's version answers; with the theme module off, Rank Math's answers again); a page
  with Bricks lazy-loaded images; a paginated post's second part; `HEAD` with `?markdown=1` and with
  `Accept` — same headers as `GET`; an authorized private post and a draft preview as an editor —
  HTML, no head link.
- **Abuse path (AC-5)**, with `curl`, each compared with the HTML request of the same visitor:
  anonymous for a draft, pending, scheduled, private and trashed post and a `bricks_template` —
  `?markdown=1` and `Accept` give what HTML gives (404 or redirect), no content, and none is in
  `llms.txt`; a password-protected post without its cookie — the password form as HTML, no
  Markdown, no head link, not in `llms.txt`; the same post with a valid cookie — Markdown, then the
  same request without the cookie gets the form again (nothing reused); the theme's site-wide
  password protection on — `?markdown=1`, `Accept` and `/llms.txt` give the login redirect; Bricks
  maintenance mode on — they give the maintenance answer; in both, `/llms.txt?robots=1` and
  `/llms.txt?feed=rss2` give exactly what the same request gives with the module off, and never
  the theme's index or its marker header. The module-off response is the baseline per plugin
  setup: without Rank Math it is WordPress' robots or feed response; with Rank Math's llms module
  on, Rank Math 1.0.282 itself answers `/llms.txt?robots=1` with its own index past the password
  gate (its handler checks only its query var) — an existing exposure of that plugin, not added by
  the theme, recorded in `todos.md`; a Redirects 410 rule on `/llms.txt` — 410 stays.

## Sources and impact boundary

- Bricks 2.4.5 on the demo site: `includes/frontend.php:965` (`render_data`), `:1187-1226` (lazy
  images), `:1253-1260` and `includes/templates.php:76` (template views), `:1375-1378`
  (`render_content`, `id="brx-content"`), `includes/helpers.php:1192-1207` (pagination),
  `includes/maintenance.php:23,33-43,64,120-163` (maintenance at `wp` 9, public `is_applied()`,
  robots/favicon exempt, status 503/200, template swap),
  `single.php:25,30`, `404.php:9`; served HTML of `/` and `/editor-prose-test/`.
- WordPress, library's own material for the running version (Context7 holds no versioned
  WordPress reference — CLAUDE.md's fallback): local WP 7.1.4 `wp-includes/html-api/` —
  `WP_HTML_Processor::create_full_parser` `@since 6.7.0`, `get_current_depth` 6.6.0,
  `get_breadcrumbs`, `get_last_error` 6.4.0, `get_unsupported_exception` 6.7.0; `next_token`,
  `get_token_type`, `get_modifiable_text` 6.5.0 (Tag Processor); `wp-includes/template-loader.php:114,130`
  (`template_include`, `wp_before_include_template`); `wp-includes/template.php:935-960`
  (enhancement buffer, flags); `wp-includes/default-filters.php:437`; `wp_get_nocache_headers()`
  gained `no-store`/`private` in 6.8 (developer.wordpress.org changelog, cited by Gate A pass 1);
  `wp-includes/class-wp-http.php:973-1054` (`WP_Http::make_absolute_url`, drops the base query on
  fragment-only references — Gate A pass 2); `wp-includes/link-template.php:4068-4077`
  (`wp_get_canonical_url`, false for non-`publish` posts); `wp-includes/template-loader.php:36`
  (`exit_on_http_head`); `wp-admin/options.php:47-49,243` (Settings API: capability through
  `option_page_capability_*`, nonce `<option_group>-options` from `settings_fields()` for a
  registered group, `options.php:246`); `paused_at_incomplete_token()`
  (`class-wp-html-tag-processor.php:1172`). The minimum relied on is WP 6.7 (D10).
  Context7 `/websites/developer_wordpress_reference_classes` was queried for `WP_HTML_Tag_Processor`
  (`get_attribute`, `is_tag_closer`, `next_tag`); unversioned.
- Bricks: no Context7 index of the Bricks theme source (CLAUDE.md's fallback: the vendor's own
  material for the running version); Bricks 2.4.5 source as listed above, including
  `Maintenance::is_applied()` (`includes/maintenance.php:42-43`).
- SEO plugins, release zips from wordpress.org read 2026-11-21 (not run): Yoast 28.9
  `src/llms-txt/infrastructure/file/wordpress-file-system-adapter.php:99-117`,
  `…/wordpress-llms-txt-permission-gate.php:48-65`,
  `src/llms-txt/user-interface/enable-llms-txt-option-watcher.php:90-114`; Rank Math 1.0.282
  `includes/modules/llms/class-llms-txt.php:40-41,93-94,103-114`, `includes/module/class-manager.php:48,113`;
  SEOPress 10.4 `src/Actions/Options/Init.php:220`, `readme.txt:93,101,201-203`,
  `inc/admin/docs/DocsLinks.php:81-82`; AIOSEO 5.0.5.1 `app/Common/Llms/Llms.php:206-236,575-579`,
  `app/Common/Options/Options.php:763-783`.
- Theme: `inc/PasswordProtected/Controller.php:50,116-128,236-267` (gate at `template_redirect`
  -10, `is_robots()`/feed exemptions); `inc/Redirects/Controller.php:45-49,133-138` (match at `wp` 0,
  send at `template_redirect` 0, 410 continues, 404 log at 9999); `inc/EditorProse/` (settings and
  admin page pattern); `inc/GeneralThemeOptions/Settings.php` (module switches);
  `inc/DataPurge.php`, `inc/ImportExport/Controller.php` (ownership lists).
- Impact: a new module `inc/Markdown/`; one GeneralThemeOptions field; one entry each in DataPurge
  and ImportExport; docs (README, CHANGELOG, AGENTS.md module list). No new module edge: the module
  names no other theme module's class and relies on hook order only. It reads other plugins'
  state only through `$wp->matched_rule`, Bricks' public `Maintenance::is_applied()` and
  plugin-active detection on its settings page; it changes no other plugin's state.

## Left to the builder

Class split inside `inc/Markdown/`; the converter's internal structure; exact wording of admin
strings (`sfxtheme` domain); the test file layout; how the loopback check is presented.
