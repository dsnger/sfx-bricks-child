# Redirects — Design

**Date:** 2026-09-29
**Branch:** `feature/redirects`
**Story:** none — this cycle is unprofiled (no story file exists for it).

**Scope:**

- new `inc/Redirects/*`
- one toggle in `inc/GeneralThemeOptions/Settings.php`
- one entry in `inc/ThemeSettingsOverview/OverviewProvider.php`
- `inc/DataPurge.php`: two option names, two transient prefixes, a new `TABLE_NAMES` list, `tables` / `tables_locked` / `tables_failed` results
- `inc/GeneralThemeOptions/AdminPage.php`: purge screen copy + report the `tables` count
- one settings group in `inc/ImportExport/Controller.php`
- German strings in `languages/de_DE.po` / `.mo`
- tests in `tests/`, one live harness in `tests/support/`

## Goal

A redirect manager inside the theme, matching what site owners expect from the common
redirect plugins (Redirection, Safe Redirect Manager): 301/302/307/308 redirects and
410 "gone" rules, exact and regular-expression matching, hit counter and last hit, a
404 log with "create redirect from this", an automatic redirect when a published
post's slug changes, and CSV import/export.

Non-goals: see [Out of scope](#out-of-scope).

## Decisions settled with Daniel (2026-09-29)

| Question | Answer |
|---|---|
| Storage | Own database tables (like Redirection), not a post type |
| Scope of v1 | 404 log, automatic redirect on slug change, CSV import/export, regular expressions — all four |
| Who manages redirects | Also editors (`edit_others_posts`), not only administrators |
| Naming | Follow existing modules: `sfx_<module>_…` options, `sfx-<module>` slugs |

## How others do it (researched, drives the design)

- **Blueprint (`sinanisler/snn-brx-child-theme`)**: three hidden post types (rules, one
  post per redirect hit, one post per 404). Every redirect hit costs ~7 writes, every
  404 ~10, all in `wp_posts`/`wp_postmeta`. Only 301. The 404 settings handler has no
  nonce (CSRF). Stores full IPs and user agents. We take its idea of case- and
  trailing-slash-insensitive paths and none of the storage.
- **Redirection**: own tables, exact/regex, 301–308 + 410, hit count + last access,
  404 log with "add redirect", slug-change monitor, CSV/JSON import/export, IP
  anonymisation, configurable capability.
- **Safe Redirect Manager**: post type, wildcard/regex, rule cap (default 1000),
  transient cache.

## Architecture

```
inc/Redirects/
  Controller.php      feature config; hooks: matching, 410, 404 logging,
                      slug-change monitor, cron cleanup, schema install
  Settings.php        option sfx_redirects_options (TYPES, defaults, get,
                      validate_snapshot, admin-post save handler — which
                      calls AdminPage::guard(..., settings: true) first)
  Rule.php            PURE: canonical paths/queries, validate a rule, match,
                      build + check the target, CSV cell escaping/row mapping.
                      No $wpdb, no WordPress state (only pure helpers such as
                      wp_parse_url / wp_sanitize_redirect, stubbed in tests).
  Repository.php      the two tables: schema, CRUD, lookup, hits, 404 upsert,
                      cleanup, loop check. The only file touching $wpdb.
  AdminPage.php       Tools → Redirects: tabs, forms, guard(), every admin-post
                      handler except the settings save (Settings.php)
  RedirectsTable.php  WP_List_Table for rules
  NotFoundTable.php   WP_List_Table for the 404 log
```

`Rule.php` holds every decision that can be tested without WordPress, in the stubbed
test style the theme uses (`tests/*-test.php`, inline stubs, no PHPUnit).

### Registration (same as every module)

`Controller::get_feature_config()`:

```php
'class' => self::class,
'menu_slug' => AdminPage::$menu_slug,            // 'sfx-redirects'
'url' => admin_url('tools.php?page=sfx-redirects'),
'page_title' => __('Redirects', 'sfxtheme'),
'description' => __('…', 'sfxtheme'),
'activation_option_name' => 'sfx_general_options',
'activation_option_key' => 'enable_redirects',
'option_value' => true,
'hook' => null,
'error' => 'Missing Redirects Controller class in theme',
```

Toggle `enable_redirects` in `GeneralThemeOptions\Settings::get_fields()`, **default 0**
(opt-in). Overview entry `enable_redirects` in
`OverviewProvider::build_builtin_modules_group()`. No new bare strings reach the
registry (invariant 4): `get_feature_config()` passes **literal** strings to `__()`
(a variable inside `__()` is invisible to string extraction — PR #41 review).

### Naming

| Thing | Name |
|---|---|
| Module toggle | `sfx_general_options['enable_redirects']` |
| Settings option | `sfx_redirects_options` (`Settings::OPTION_NAME`) |
| Schema version option | `sfx_redirects_db_version` |
| Rules table | `{$wpdb->prefix}sfx_redirects` |
| 404 log table | `{$wpdb->prefix}sfx_redirects_404` |
| Form-refill transient | `sfx_redirects_form_<user_id>` |
| Admin page slug | `sfx-redirects` (under Tools) |
| Cron hook | `sfx_redirects_cleanup` (daily) |
| admin-post actions / nonce actions | see [Admin actions](#admin-actions) |

## Canonical forms (the one coordinate system)

All sources and all incoming requests are compared in **home-relative canonical
form**. `Rule` owns these functions; every other file calls them.

### Path — `Rule::canonical_path(string $raw): string`

1. Take the part before the first `?` and `#`.
2. `rawurldecode` once, split on `/`, drop empty and `.` segments (collapses `//`,
   strips the trailing slash), resolve `..` by removing the previous segment (never
   above root), `rawurlencode` each
   segment, join with `/`, prefix `/`. Encoded dots (`%2e%2e`) decode first and are
   resolved the same way.
3. `strtolower` — **ASCII case-folding only** (no `mbstring` dependency). Hex escapes
   are therefore lowercased too, so `%C3%BC` and `%c3%bc` compare equal; `Ü` and `ü`
   do not. Stated, not guarded.

Properties this buys, each tested:

- **Idempotent**: `canonical_path(canonical_path(x)) === canonical_path(x)`, because
  decode-then-encode is identity. `%252F` stays `%252f` on every pass.
- `/über-uns` and `/%C3%BCber-uns` are the same source.
- A decoded `?` or `#` inside a segment is re-encoded (`/a%3Fb` stays a path, never a
  query). An encoded slash `%2F` becomes a segment separator — policy: `/a%2Fb` ≡ `/a/b`.
- Root is `/`.

### Query — `Rule::canonical_query(string $raw): string`


Split the raw query on `&`, drop empty pieces, sort the pieces as byte strings
(`sort(..., SORT_STRING)`), join with `&`. **No `parse_str`** — repeated keys
(`x=1&x=2`), dotted names (`a.b=1`) and nested keys survive unchanged, and order is
the only thing normalised. Values are case-sensitive.

### Request → canonical — `Rule::request_parts(string $request_uri, string $home_path): array{path:string, query:string}`

`$request_uri` is `wp_unslash($_SERVER['REQUEST_URI'])` (the controller unslashes;
`Rule` never sees slashed input). If `$home_path` (the path of `home_url()`, e.g.
`/blog` on a sub-directory install; empty for a root install) is non-empty, it is
stripped **only as a whole segment prefix**: `/blog` → `/`, `/blog/x` → `/x`,
`/blogger` untouched, `/blog/blog/x` → `/blog/x` (stripped once). Then
`canonical_path` + `canonical_query`.

Rule sources are **always home-relative** and are canonicalised at save time; the
home path is never stripped from a stored source.

### Source identity

`source_hash = sha1(match_type . "\n" . source)` where `source` is the canonical form
(exact) or the regex body (regex). A **UNIQUE** index on `source_hash` enforces
uniqueness in the database (concurrent saves/imports cannot duplicate) and makes
lookups byte-exact regardless of the table collation. A duplicate-key error on insert
is reported as "a rule for this source already exists (#id)".

## Data model

### `{prefix}sfx_redirects`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| `source_hash` | CHAR(40) NOT NULL | UNIQUE |
| `source` | VARCHAR(255) NOT NULL | exact: canonical `path` or `path?query`; regex: pattern body |
| `match_type` | VARCHAR(10) NOT NULL | `exact` \| `regex` |
| `target` | TEXT NOT NULL | empty only for 410 |
| `status_code` | SMALLINT UNSIGNED NOT NULL | 301 \| 302 \| 307 \| 308 \| 410 |
| `enabled` | TINYINT(1) NOT NULL DEFAULT 1 | |
| `hits` | BIGINT UNSIGNED NOT NULL DEFAULT 0 | |
| `last_hit` | DATETIME NULL | UTC |
| `origin` | VARCHAR(10) NOT NULL | `manual` \| `auto` \| `import` \| `404` |
| `note` | VARCHAR(255) NOT NULL DEFAULT '' | |
| `created_at` | DATETIME NOT NULL | UTC |

Indexes: `UNIQUE KEY source_hash (source_hash)`, `KEY enabled_type (enabled, match_type)`.
Both fit the legacy 767-byte index limit.

### `{prefix}sfx_redirects_404`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| `path_hash` | CHAR(40) NOT NULL | `sha1(path)`, UNIQUE |
| `path` | VARCHAR(255) NOT NULL | canonical request path, **no query string** |
| `hits` | BIGINT UNSIGNED NOT NULL DEFAULT 1 | |
| `first_seen` | DATETIME NOT NULL | UTC |
| `last_seen` | DATETIME NOT NULL | UTC, `KEY last_seen (last_seen, id)` |
| `referrer` | VARCHAR(255) NOT NULL DEFAULT '' | last referrer, **scheme+host+path only**; stored as `''` if it is longer than 255 bytes or not valid UTF-8 |

One row per path: a 404 costs one write
(`INSERT … ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = …, referrer = …`).

**Data minimisation:** no IP address, no user agent, no query string (queries are
where tokens, e-mail addresses and search terms live). Referrers lose their query
string too (display only, never acted on). A canonical
path longer than 255 bytes is **not logged** (never truncated), so every logged row is
a complete, actionable path. Paths themselves can still carry personal data (e.g.
`/user/jane-doe`); the log is visible only to users with the module capability and is
deleted by retention.

### Schema install / upgrade — `Repository::maybe_install()`

- Runs on `admin_init`. Does nothing when `get_option('sfx_redirects_db_version') === Repository::DB_VERSION`.
- Otherwise: `require_once ABSPATH . 'wp-admin/includes/upgrade.php'`, `dbDelta()` with
  `$wpdb->get_charset_collate()`, then **verifies** both tables exist (`SHOW TABLES
  LIKE`, `esc_like`'d), have **every column the code uses** (`SHOW COLUMNS`, compared
  against the column list in `Repository`) and the `source_hash` / `path_hash` unique
  keys (`SHOW INDEX`); each column's `Type` must match the definition after
  normalisation (lowercase, display widths of integer types ignored: `bigint(20)
  unsigned` ≡ `bigint unsigned`), `id` must be the primary key with `auto_increment`.
  Only then is the version option written. The whole install runs under the
  [write lock](#write-serialisation), so it cannot interleave with a purge. On failure the version is
  not written (retried next admin request) and an admin notice names the failing table.
- A theme has no activation hook, so this lazy check is the install path; it also
  re-creates tables after a Data Purge dropped them.

### Schema readiness everywhere

`Repository::ready(): bool` — version option equals `DB_VERSION` (one autoloaded
option read, no query). **Every** repository operation checks it first:

| Caller | When not ready |
|---|---|
| front-end matching, 404 logging, hit counter | do nothing (fail open — the page renders as without the module) |
| slug-change monitor (may run from REST/cron before any admin request) | do nothing |
| cron cleanup | do nothing |
| admin screens and handlers | show "Database tables are not installed" notice with the install error; handlers refuse and report |

Front-end queries additionally run inside `$prev = $wpdb->suppress_errors(true); … ;
$wpdb->suppress_errors($prev);` so a table dropped mid-request (purge race) can never
print a database error into a page; a failed query is treated as "no match".
**Self-heal:** when a module query fails with "table doesn't exist" (MySQL error 1146;
`$wpdb->last_error` holds the message, so the check is for the text "doesn't exist"
together with the module's table name), the version option is deleted, so `ready()` turns false
and the next admin request re-installs. A version that says "ready" over missing tables
therefore lasts at most until the next failing request.

### Settings — `sfx_redirects_options`

| Key | Type | Range | Default | Meaning |
|---|---|---|---|---|
| `log_404` | bool | | true | record 404s |
| `log_referrer` | bool | | true | store the (query-stripped) referrer |
| `log_retention_days` | int | 1–365 | 30 | delete 404 rows with older `last_seen` |
| `log_max_rows` | int | 100–50000 | 5000 | keep at most this many 404 rows |
| `auto_slug_redirect` | bool | | true | create redirects on slug change |
| `permanent_cache` | int | one of 3600, 86400, 604800, 0 | 3600 | browser cache time for 301/308 (Addendum A2) |

Pattern: PasswordProtected's — `TYPES`, `defaults()`, pure `validate_snapshot($post,
$existing)`, admin-post save. **`get()` clamps every stored range value into its range,
maps a `permanent_cache` value outside its choices to the default, and falls back to the
default for a wrong type**, so a value written by any other path
(the ImportExport JSON import writes options through its own generic sanitiser) can
never reach cleanup out of range.

## Permissions

`AdminPage::CAPABILITY = 'edit_others_posts'`.

| Action | Gate |
|---|---|
| See the page; add/edit/delete/enable/disable/reset rules; bulk actions; CSV import/export; view/delete/clear 404 log; "create redirect from 404" | `current_user_can(CAPABILITY)` |
| Settings tab (render and save) | `current_user_can(CAPABILITY) && AccessControl::can_access_theme_settings()` — both, so the tab lives on a page the user can open |
| Enabling the module | General Theme Options (unchanged) |

The page is `add_submenu_page('tools.php', …, CAPABILITY, 'sfx-redirects', …)` —
**under Tools, not under the theme menu**, because the theme menu is only registered
for `SFX_THEME_ADMINS` users and editors would never reach a submenu of it. The theme
settings tile links there through the `url` config key.

`render_page()` starts with `if (!current_user_can(CAPABILITY)) wp_die(…, 403)`.

## Admin actions

Every state change is a `POST` to `admin-post.php`, except single-row actions, which
are `wp_nonce_url` links to `admin-post.php` (never a `GET` on the page itself).
**Every handler starts with `AdminPage::guard($action, $settings = false)`**, which runs
`check_admin_referer($action)` and the capability check from the table above, and
`wp_die(…, 403)`s on failure — before any input is read or any write happens
(invariant 2).

| admin-post action (= nonce action) | Input | Effect |
|---|---|---|
| `sfx_redirects_save_rule` | `id` (0 = new), source, match_type, target, status_code, enabled, note, optional `from_404` id | insert/update; on success delete the 404 row `from_404` — only if it exists and logs exactly the saved exact source (see 404 log) |
| `sfx_redirects_rule_action` | `id`, `op` ∈ {enable, disable, delete, reset} | single-row action |
| `sfx_redirects_bulk` | `ids[]`, `op` ∈ {enable, disable, delete, reset} | bulk; per-row outcome counted |
| `sfx_redirects_404_action` | `ids[]` or `id`, `op` ∈ {delete, clear_all} | delete rows / empty the log |
| `sfx_redirects_import` | CSV file | import |
| `sfx_redirects_export` | — | stream CSV (see exception below) |
| `sfx_redirects_save_settings` | settings fields | `guard(…, settings: true)` |

All request values are `wp_unslash`ed once at the handler boundary and type-checked:
scalar fields must be strings; `ids[]` must be an array whose every element is a
digit string (`absint`'d, zeros dropped); `$_FILES['file']` must have the standard
upload shape. Ops matched against the fixed list above; anything else rejected.

**Outcome notices:** handlers call `add_settings_error('sfx_redirects', …)` with **plain
text**, park them in the module's **per-user** transient `sfx_redirects_notices_<user_id>`
(60 s) via `AdminPage::finish($tab)` and redirect with `wp_safe_redirect(page_url($tab))`;
the page prints them in `AdminPage::render_notices()`, escaping every line at the echo
site (invariant 3). Core's site-global `settings_errors` transient is **never** used:
core's `settings_errors()` prints any pending notice unescaped on other screens, and
these notices can carry CSV cells an editor uploaded (Gate B pass 2 finding).
`sfx_redirects_notices_` joins `DataPurge::TRANSIENT_PREFIXES`.

A failed rule validation redirects back to the form **with the submitted values**
(stored in the per-user transient `sfx_redirects_form_<user_id>`, 5 minutes, deleted
when read) so the editor does not lose input.

**Export exception:** `sfx_redirects_export` does not redirect. After `guard()` it sends
`Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment;
filename=sfx-redirects-YYYY-MM-DD.csv`, `nocache_headers()`, the CSV (header row only
if there are no rules), and `exit`. If the tables are not ready it redirects with an
error notice instead, before any header is sent.

**Stale or missing rows:** acting on an id that no longer exists reports "Rule #n no
longer exists"; `$wpdb` returning `false` reports a database error; 0 affected rows on
an update reports "unchanged". Bulk results report counts per outcome.

## Rule validation — `Rule::validate(array $input): array{rule: array, errors: list<string>}`

Applied identically to the form, CSV rows and the slug-change monitor.

- `enabled` is a bool (see below); every other field must be a string (arrays and
  other types rejected); valid UTF-8
  (`preg_match('//u', $v) === 1`, pure — not `wp_check_invalid_utf8`, which depends on
  the site charset); no control characters (`\x00-\x1F\x7F`) anywhere. Rejected, never
  stripped.
- **source**: required; exact → must start with `/` and contain no `#` (a pasted
  full URL is rejected with "enter the path only, e.g. /old-page"), then canonical form
  (path, plus `?canonical_query` only if the canonical query is non-empty), ≤ 255 bytes after canonicalisation; regex → body ≤ 255 bytes,
  must compile (see below).
- **status_code** ∈ {301, 302, 307, 308, 410}; default 301.
- **target**: ignored and stored empty for 410; otherwise required, ≤ 2000 bytes, and
  must pass `Rule::target_ok()` (below).
- **note**: ≤ 255 bytes.
- **match_type** ∈ {exact, regex}; default exact.
- **enabled**: the caller passes a bool — the form maps an absent checkbox to `false`,
  CSV maps its accepted spellings (empty cell → `true`); any other CSV value is an
  error for that record.

`Repository` adds the checks that need the database: uniqueness (`source_hash`),
[loops](#loop-prevention) and the regex cap.

### Target coordinates

Sources are home-relative (see above). **Targets** are either absolute URLs or
home-relative paths (`/new-page/` means `home_url('/new-page/')`, so on a
sub-directory install `/blog` it is sent as `…/blog/new-page/`). One function,
`Rule::absolute_target(string $target, string $home_url): string`, builds the emitted
URL and is used both for sending and for runtime identity. The slug monitor stores its
targets in the same home-relative form (home path stripped as a whole segment prefix).

### Target predicate — `Rule::target_ok(string $t): bool`

Accepted forms:

- **site-relative**: starts with exactly one `/`; second character is not `/` or `\`;
- **absolute**: `wp_parse_url` gives scheme `http`/`https`, a non-empty host, **no
  user/pass**; port allowed. The host must be a plain ASCII name (IDNs as punycode) or
  a canonical dotted-quad IPv4 address — percent-encoded hosts and shortened/hex/integer
  IPv4 forms are rejected, because browsers normalise them and the loop checks would
  compare the wrong host. Bracketed IPv6 hosts are not supported (stated).

Rejected additionally: any backslash, whitespace or control character anywhere; any
value where `wp_sanitize_redirect($t) !== $t` (so what we validate is exactly what
`wp_redirect` will send). `javascript:`, `data:`, `ftp:`, `//host`, `/\host`, bare words
all fail.

For regex rules the predicate runs twice: on the stored target with every `$n`
replaced by the sentinel `x` (see Target substitution), and on the **resolved** target
at match time.

## Matching

### Where it runs

Matching happens **once per front-end request on the `wp` action at priority 0** —
after `WP::main()` has parsed the request, run the main query, handled core 404s and
sent its headers (`send_headers()` runs before `wp`), and before Bricks selects its
templates on `wp` (priority 10). The result is kept in `Controller::$match`.

Nothing is **sent** at `wp`: both outcomes are completed on `template_redirect` at
priority **0** — after PasswordProtected's gate (−10), so on a protected site an
unauthenticated visitor gets the password prompt first and neither a redirect target
nor a "gone" is revealed or counted. Before core's `redirect_canonical` /
`wp_old_slug_redirect` (10), so an explicit rule wins over WordPress's guesses.

- **301/302/307/308**: redirected at `template_redirect` 0 (see
  [Executing a redirect](#executing-a-redirect)).
- **410**: the query state is prepared at `wp` 0 (Bricks needs it there), the status
  and hit happen at `template_redirect` 0 — see [410 Gone](#410-gone).

`wp` and `template_redirect` never fire for wp-admin, admin-ajax, cron or REST. Rules
apply to every HTTP method.

### Requests rules never apply to

Matching is skipped entirely (no lookup) for: feeds (`is_feed()`), `robots.txt`,
`favicon.ico`, sitemaps (`get_query_var('sitemap')` or `sitemap-stylesheet` set),
**Bricks maintenance mode** (`class_exists('\Bricks\Maintenance') &&
\Bricks\Maintenance::get_mode()` truthy — while the site is in maintenance, maintenance
wins and no rule applies, the same precedence as the password gate), and — **only for a
logged-in user with `edit_posts`** — previews (`is_preview()`, `is_customize_preview()`)
and the Bricks builder and its calls (`bricks_is_builder()`, `bricks_is_builder_call()`,
guarded by `function_exists`) including the toolbar preview marked with the
`bricks_preview` query argument. The capability condition matters: `?preview=true` and
a `Referer` containing `bricks=run` are visitor-controlled, so for anyone else they
change nothing. Feeds and sitemaps have their own header handling (content types,
conditional 304 responses in `WP::send_headers()`) and feeds have their own
PasswordProtected path; the builder must be able to edit a page that has a rule. A
rule whose source is a feed URL therefore does nothing — stated in the UI help.

### Lookup (one query, then PHP)

```sql
SELECT id, source, match_type, target, status_code FROM {t}
WHERE enabled = 1 AND (source_hash IN (%s, %s) OR match_type = 'regex')
ORDER BY id ASC
```

with the hashes of `exact\npath?query` and `exact\npath`. Then `Rule::pick()`:

1. exact `path?query` rule (only if the request has a query) → wins;
2. exact `path` rule → wins; the request query is **passed through**;
3. regex rules in id order, first match wins.

### Regex rules

- Stored as a body without delimiters, e.g. `^/blog/(\d+)/(.*)$`, matched against the
  **canonical path** (never the query — one fixed subject, no guessing). Query-aware
  regex matching is out of scope; exact rules cover specific queries.
- Compiled by `Rule::compile_regex($body)`: `'~' . $body . '~iu'`. A body containing an
  unescaped `~` (backslash-parity aware scan) is rejected at save time — "use `\~`".
- Validated at save time: compiles (`@preg_match($re, '') !== false`); capture
  references in the target (below) must not exceed the pattern's group count.
- **Budget:** at match time `pcre.backtrack_limit` is set to 100000 and
  `pcre.recursion_limit` to 10000 around the loop (previous values restored), subjects
  longer than 1024 bytes skip regex matching, and `preg_match` returning `false` means
  "no match" for that rule. The regex loop also stops after **50 ms** total
  (`microtime(true)`), treating the remaining rules as no match. At most **200 enabled
  regex rules**: saving, enabling or
  importing a regex rule beyond that is rejected with a message.
- `// ponytail:` every enabled regex rule is fetched on every request. Bounded by the
  200 cap; a cached compiled list is the upgrade path.

### Target substitution — `Rule::resolve_target(string $target, array $captures): ?string`

- Placeholders are exactly `$1`–`$9` (`$10` is `$1` followed by `0` — documented; nine
  groups are enough). An unmatched group becomes the empty string.
- **Validation of a template target:** `target_ok()` runs on the target with every
  placeholder replaced by the sentinel `x` (WordPress's sanitizer strips `$`, so the
  raw template would never pass). A literal dollar sign in a target is written `%24`;
  `\$` is not supported (backslashes are rejected).
- **Placeholders are only allowed after the authority**: in a relative target anywhere;
  in an absolute target only after the first `/` following `scheme://host[:port]`.
  Placeholders in scheme, host, port or userinfo are rejected at save time.
- Captures are inserted **as they are**: the subject is the canonical path, which
  consists only of `/` and already-encoded segments, so a capture needs no further
  encoding (encoding it again would double-encode `%c3%bc`). It can contain no `?`,
  `#`, `\`, whitespace or scheme characters beyond `:` inside an encoded segment.
- The resolved target must pass `target_ok()` and be ≤ 2000 bytes after substitution
  and query passthrough; for absolute targets its host must equal the stored target's
  host. Otherwise → `null`, no redirect, the request continues
  normally. (Relative `/$1` fed `/evil.com` would give `//evil.com`, which fails.)

### Query passthrough

For exact-path and regex matches, the request's raw query is appended **only when the
target has no query of its own**; a target with a query is sent as written and the
request query is dropped. (No per-key merging: encoded key aliases such as `%72ole`
would otherwise let a visitor override a parameter the rule fixed.) A fragment in the
target stays after the query. The final URL goes through `wp_sanitize_redirect()`; if
that changes it, the sanitized URL is what is sent and counted.

### Loop prevention

Identity is the **resolved absolute URL**: scheme, host, effective port, canonical path,
canonical query (relative targets resolve against `home_url()`).

- **Runtime:** if the resolved target URL equals the current request URL (same identity),
  the redirect is skipped. `/?p=1` → `/?p=2` and `http` → `https` on the same path are
  therefore allowed.
- **Dot segments in targets are rejected** (`/./`, `/../`, trailing `/.` or `/..`,
  literal or percent-encoded, checked on the stored and on the resolved target):
  browsers resolve them with rules (empty segments kept) that differ from our source
  aliasing, so a target like `/a//../b` could loop back undetected. Sources may contain
  them; they are resolved away by `canonical_path`.
- **Save-time, for every transition to "enabled exact rule"** — form save, enable,
  bulk enable, import, and every rule the slug monitor creates, re-points or
  re-enables: `Repository::loop_conflict($rule)` rejects (a) a same-host target whose
  canonical path + canonical query equal the rule's own source — skipped when the
  target is absolute with a scheme different from `home_url()`'s, which is a scheme
  upgrade/downgrade, not a loop (`/?p=1` → `/?p=2` is allowed too) — (b) a
  same-host target whose
  canonical path equals the source path of an enabled exact rule whose **same-host**
  target path equals this rule's source path (A→B→A). 410 rules have no outgoing edge
  and are ignored; external targets end the chain; the rule being saved or enabled is
  excluded from its own reverse-edge lookup (by id). Queries are ignored in this comparison on
  purpose: a path-only source matches every query and passes it through, so
  `/a → /b` plus `/b → /a?x=1` is a loop and is rejected. (Conservative: a
  query-specific pair that would not loop is also rejected; use a different path.) Bulk enable skips conflicting rows and reports them.
- **Chain simulation — best effort, not complete** (added after PR #41 review): for
  every rule becoming enabled — exact or regex — `Repository::matcher_cycle()` runs
  the rule set *with this rule in place* through `Rule::pick()` hop by hop, with
  runtime semantics (query passthrough, the target's scheme, a redirect to the
  current URL is skipped). Start points: an exact rule's source; for a regex rule
  its target plus that target with every query an exact rule on the same path keys
  on. A revisited address on a chain this rule is part of is refused; a chain that
  ends anywhere else is allowed, however it gets there. Up to 10 hops are followed
  and the address the 10th hop reaches is still checked. The simulation runs under
  the write lock, so one save spends at most 200 rule lookups across all start
  points; a spent budget counts as "no loop found".

  **Known gaps, stated:** loop detection over rules with passthrough queries is not
  decidable from finitely many start points, so cycles that only appear for a query
  no start point carries (e.g. a path-only rule into a regex whose *downstream*
  exact rule keys on a query), regex targets with `$n` placeholders, and chains
  longer than 10 hops are not caught. The simulation also does not model the
  request types rules never apply to (feeds, `robots.txt`, sitemaps …): a chain
  through such an address may be refused although it would end there at runtime —
  the conservative direction. A missed loop costs a visitor one
  "too many redirects" browser error and is visible in the hit counters; it is a
  usability safeguard, not a security boundary.

  **Accepted direction of error (decided by Daniel, 2026-09-29, PR #41):** the
  simulation compares addresses by their canonical identity and does not model
  every runtime effect (excluded request types, the 2000-byte target cap reached
  through long passed-through queries, …). Where that makes it refuse a save whose
  chain would in fact end at runtime, that is accepted — a false refusal is visible
  and has a workaround; further completeness work is out of scope.

### Write serialisation

**Every** write to the rules table except the hit counter — form save, single and bulk
enable/disable/delete/reset, import, slug monitor, schema install, and the Data Purge's
table drop — runs inside the MySQL named lock `GET_LOCK('sfx_redirects_' . md5(DB_NAME . $wpdb->prefix),
5)` (per database and site, always 46 characters) / `RELEASE_LOCK` (released in a `finally`). Loop checks, ownership reads and the
regex cap are therefore evaluated against a state no concurrent writer can change. Lock
not obtained within 5 s → the operation is refused with "Another redirect change is in
progress, try again".

**Connection loss:** `wpdb` transparently reconnects after "server has gone away",
which silently drops the lock and any open transaction. Before **each** write (and
before `COMMIT`) a locked operation therefore checks `SELECT IS_USED_LOCK(name) =
CONNECTION_ID()`; if the lock is no longer ours, it rolls back (if anything is still
open), reports a database error and stops. Stated limit: a reconnect between that check
and the write it guards is not detected.

### Database error contract

`Repository` never trusts a return value alone: after every read it checks
`$wpdb->last_error !== ''` and returns `null` (not an empty result) on failure; writes
check for `false`. A caller that gets `null` from a prerequisite read (ownership, loop
check, cap count, export) aborts and reports a database error — an unreadable state is
never treated as "no rules".

### Executing a redirect

At `template_redirect` 0 with `$pending`:

```php
if (headers_sent()) { return; }                       // cannot redirect; render as usual
if (wp_redirect($url, $code, 'SFX Redirects')) {
    Repository::record_hit($id);   // UPDATE … hits = hits + 1, last_hit = UTC_TIMESTAMP()
    exit;
}
// wp_redirect returned false (a filter cancelled it): no hit, no exit, page renders.
```

302 and 307 also send `nocache_headers()` first (temporary by definition).

### 410 Gone

At `wp` 0, when the picked rule is 410 and `!headers_sent()` (if output already
started, the 410 is not attempted and the request renders normally):

1. Reset the main query to an empty 404: `$wp_query->set_404()`, and also
   `$wp_query->posts = []`, `post_count = 0`, `post = null`, `queried_object = null`,
   `queried_object_id = 0`, and `$GLOBALS['post'] = null` — so Bricks, selecting its
   templates on `wp` 10, picks its **error template** and not the page's content.
   (Feeds and sitemaps never get here, see above.)
2. Remove this request's core guesses that act on `is_404()`:
   `remove_action('template_redirect', 'redirect_canonical')`,
   `remove_action('template_redirect', 'wp_old_slug_redirect')`,
   `remove_action('template_redirect', 'wp_redirect_admin_locations', 1000)`.

At `template_redirect` 0 (i.e. only if the password gate let the request through),
unless `headers_sent()`: `status_header(410); nocache_headers();
Repository::record_hit($id);` and the request
continues into the normal 404 template rendering. `send_headers()` has already run,
so nothing later resets the status. The 404 logger does not log it (status check).

Verified live on an existing Bricks page, see Testing.

### Page caches

Matching runs inside WordPress. A full-page cache that answers before WordPress boots
(Varnish, a CDN, advanced-cache.php drop-ins) keeps serving a cached page for a URL
that just got a rule, and those requests are not counted. Purging such caches after
editing rules is the site operator's job; the module does not integrate with cache
plugins. How long browsers keep a 301/308 is governed by Addendum A2 (default: one
hour); the status-code hint in the admin UI names the current setting.

## 404 log

On `template_redirect` at priority **9999** — after `redirect_canonical` and
`wp_old_slug_redirect` (10) and `wp_redirect_admin_locations` (1000), each of which
exits when it redirects — record when:

- `is_404()`, `log_404` is on, `Repository::ready()`, and `http_response_code() === 404`
  (a 410 rule is therefore not logged);
- the canonical path is ≤ 255 bytes.

Only requests WordPress handles are seen: a missing static file answered by the web
server or CDN never reaches PHP.

**Growth bound:** besides the daily cron cleanup, every logged 404 runs the cleanup
with probability 1/100 (`wp_rand(1, 100) === 1`), so the table stays near
`log_max_rows` even when wp-cron is disabled or never fires. Cleanup = delete rows with
`last_seen` older than `log_retention_days` (also in `LIMIT 1000` batches), then — if `COUNT(*) > log_max_rows` —
delete the oldest by `(last_seen, id)` in batches of `min(1000, excess)`, **at most 5 batches per
run**, stopping at once on a `false` result or a batch that deleted 0 rows. What is
left is done by the next run.

**Cron lifecycle:** `sfx_redirects_cleanup` is scheduled on `admin_init` (next to the
schema install) when the module is loaded and the event is not yet scheduled — not on
`init`, so a front-end or CLI bootstrap of WordPress never writes anything on the
module's behalf; the 1/100 opportunistic cleanup covers the time until an admin visits. When the module is disabled, the Controller
is not loaded: the event keeps recurring with no callback (harmless no-op), the log is
not cleaned — and receives no new rows either. The Data Purge unschedules it
(`wp_clear_scheduled_hook`). Re-enabling finds it still scheduled or reschedules it.

404 screen: sort by hits / last seen, search by path (`esc_like` + `prepare`), bulk
delete, "Clear log", and per row **"Create redirect"** — opens the rule form with
source = that path and `from_404` = the row id; a rule saved with `from_404` gets
`origin = 404` (otherwise the form sets `manual`) — **only** when that log row still
exists and its path equals the saved exact rule's source; otherwise the id is ignored
and no log row is deleted (PR #41 review). The 404 row is deleted **only after the
rule was written successfully**, and only that row. A concurrent 404 for the same path
may re-create the row a moment later; harmless.

## Automatic redirect on slug change

Hook `post_updated` (`$post_id, $post_after, $post_before`). Runs only when
`auto_slug_redirect` is on, `Repository::ready()`, both statuses are `publish`, the
post type is viewable (`is_post_type_viewable()`), not `attachment`, and
`$post_before->post_name !== $post_after->post_name` **or**
`$post_before->post_parent !== $post_after->post_parent`.

Old path = canonical home-relative path of `get_permalink($post_before)` (the rule's
source). New target = the **current** permalink of the post, re-read under the write
lock (`clean_post_cache($post_id)`; if the post is no longer `publish`, stop;
`get_permalink($post_id)`), as a home-relative path
**as WordPress writes it** — trailing slash kept, so the auto rule adds no second hop
through `redirect_canonical`. Using the current permalink (not `$post_after`) makes
callbacks that run out of order reconcile instead of conflict: if A→B and B→C are
saved quickly and the A→B callback runs last, it still produces A→C. Skip when the
canonical forms of old and new are equal or when either permalink has a query string
(plain `?p=` permalinks).

A newly published post at an address an `auto` rule redirects away is not detected
(publishing is not a rename); the rule keeps redirecting until an editor deletes it —
the rule list shows it with origin "auto". Stated, not guarded.

**All monitor comparisons use canonical paths:** rules are found by
`source_hash('exact', canonical)`; "rules whose target is the old path" means rules
whose target, reduced with `Rule::target_path()` (same host → canonical home-relative
path), equals the canonical old path — compared in PHP over the enabled `auto` exact
rules. `// ponytail:` that list is scanned per rename; fine for the thousands.

**Scope limit, stated:** only slug and parent changes are detected. Permalink
structures containing `%category%` (or other term tags) are recomputed by WordPress
from the post's *current* terms, which `wp_insert_post` has already updated before
`post_updated` fires, so the old URL could be computed wrongly. When the post's
permalink structure contains any `%…%` tag other than `%postname%`, `%pagename%`,
`%post_id%`, `%year%`, `%monthnum%`, `%day%`, `%hour%`, `%minute%`, `%second%`,
`%author%` or the post type's own `%<post_type>%` tag, the monitor does nothing. Child pages of a renamed parent get no rules
either (only the edited post is handled; upgrade path: walk `get_page_children()`).

**Ownership:** `origin` records the rule's **current owner**, not only its provenance:
a rule the monitor created stays `auto` until a person touches it — saving it through
the form sets `manual`, updating it through an import sets `import`, and enable,
disable (single or bulk) set `manual` too. Resetting the hit counter and deleting do
not transfer ownership (a counter is not configuration; a deleted row has no owner).
The monitor only ever creates or changes rules with `origin = auto`, so an editor's
disable is never undone by it.
Rules an editor made (`manual`, `import`, `404`) are never modified by it — so a user
who can edit a post but not manage redirects cannot, through a rename, change anyone's
redirect work. The whole operation runs under the [write lock](#write-serialisation)
and inside one `START TRANSACTION … COMMIT`:

1. **Clear the new address:** an `auto` exact rule whose source is the new path is
   deleted (the page is back at an address it used to leave). A non-`auto` rule there
   is left alone — the editor's explicit rule wins.
2. **Flatten chains:** every enabled `auto` exact rule whose target is the old path is
   re-pointed to the new path (A→B then B→C makes A→C).
3. **Old address:** if an `auto` rule for the old path exists → set target = new path,
   status 301, enabled 1. If a non-`auto` rule exists there → leave it. Otherwise
   insert `old → new`, 301, `origin = auto`, passing `loop_conflict()`.

All three steps run in that order every time; none stops the others. Every rule steps
2 and 3 create, re-point or re-enable passes `loop_conflict()` against the
in-transaction state. **Any** failure — a `$wpdb` error (see the
[error contract](#database-error-contract)) or a loop conflict — rolls the whole
operation back and is written to `error_log`; the post save itself is unaffected.
On a non-transactional engine (MyISAM) the rollback cannot undo earlier steps; the
write lock still serialises, and this is stated rather than guarded.

Trashing or unpublishing creates nothing (a later 404 shows up in the log).
WordPress's own `wp_old_slug_redirect` keeps working in parallel.

## Admin screen — Tools → Redirects

Tabs via a `tab` query arg (links, no JS required):

1. **Redirects** — add/edit form (source, match type, target, status code with the
   caching hint, enabled, note) above `RedirectsTable`: columns source, target, code,
   hits, last hit, origin, enabled; search (source/target/note); sort by source, hits,
   last hit, created; 20 per page; row actions edit / enable-disable / delete / reset
   hits; bulk enable, disable, delete, reset hits. The target is ignored server-side
   for 410.
2. **404 Log** — `NotFoundTable`; notice when logging is off.
3. **Import / Export** — CSV upload + "Download CSV", with format help.
4. **Settings** — only for the settings gate.

Empty states: "No redirects yet" / "No 404s recorded". Schema not ready: every tab
shows the install error instead of its content.

**SQL safety (list tables and repository):** values only through `$wpdb->prepare()` or
typed `$wpdb->insert/update/delete` with formats; `LIKE` terms through `esc_like()`;
`orderby` mapped through a fixed column allowlist and `order` through {ASC, DESC};
`paged`/ids through `absint`; table names only from `Repository::table()`.

All output escaped at the echo site (invariant 3); every string in `sfxtheme`
(invariant 4) and translated to German in `de_DE.po`.

## CSV import / export

### Format

UTF-8, comma-separated, `"` enclosure, **no escape character** (`fgetcsv($h, 0, ',',
'"', '')` / `fputcsv(..., ',', '"', '')` — RFC 4180; backslashes in regex bodies
round-trip unchanged), `\n` line endings on export.

```
source,target,status_code,match_type,enabled,note
/old-page,/new-page,301,exact,1,
^/blog/(.*)$,/news/$1,301,regex,1,Blog moved
/gone,,410,exact,1,
```

- A leading UTF-8 BOM is stripped. Header names are trimmed and lowercased.
- `source` is required in the header; `target` is required unless every row is 410.
  A duplicate header name → the whole file is rejected. Unknown columns are ignored.
- Missing column or empty cell → default: `status_code` 301, `match_type` exact,
  `enabled` 1, `note` ''. `enabled` accepts `1/0/yes/no/true/false`.
- Blank lines are skipped. A record with **more fields than the header** is rejected
  (typically an unterminated quote or a stray comma); fewer fields → missing cells
  take defaults. Line numbers in messages are CSV **record** numbers (header = 1).
- Export adds `hits`, `last_hit`, `origin` columns; import ignores them.

### Spreadsheet safety (CSV injection)

On export, a cell whose first character is one of `= + - @ \t \r \n '` **or a
full-width `＝ ＋ － ＠`** — or whose first character after leading bytes up to 0x20
(whitespace, control characters) is one of `= + - @` or a full-width variant (Addendum
A1) — is prefixed with `'`; import removes that one `'` under the same rule. On import, a cell starting with `'`
followed by one of those same characters has that one `'` removed. Because a leading
`'` is itself in the set, the transform is reversible: `'=x` exports as `''=x` and
imports back as `'=x`. Scope stated in the UI help text: this protects a file opened
directly in a spreadsheet; a spreadsheet that re-saves the file may drop the quotes.

### Import processing

- Upload checked in this order: (a request over `post_max_size` arrives with empty
  `$_POST`, so it never reaches this handler — `admin-post.php` fires the generic
  `admin_post` hook; the form states the size limit, and nothing more is promised);
  `UPLOAD_ERR_*` codes mapped to messages; `is_uploaded_file`; size ≤ 2 MB; extension
  `.csv` (the client-supplied MIME type is not trusted). Read from the tmp file, never
  stored.
- At most **5000 records** per file (more → rejected before any write, "split the
  file"). `set_time_limit(120)` where allowed.
- Each record → `Rule::validate()` → `Repository::import()` (an upsert per record by `source_hash`)
  (an update keeps `hits` and `last_hit` and sets `origin = import` — the importer
  takes ownership; new rows get `origin = import`); `loop_conflict()` and the regex cap
  apply.
- **Partial commit, reported honestly:** rows are written one by one (no transaction
  across the file). The result reports `created / updated / skipped (invalid, with
  record numbers, first 20 listed) / failed (database error)`. A request that dies
  mid-file (timeout) leaves the rows before it written; importing the same file again
  is safe because every row is an upsert.
- An export of more than 5000 rules can't be re-imported in one file: the UI says so;
  split the file. Stated, not engineered around.

## Integration points

- **DataPurge**: add `sfx_redirects_options` and `sfx_redirects_db_version` to
  `OPTION_NAMES`; add `sfx_redirects_form_` and `sfx_redirects_notices_` to
  `TRANSIENT_PREFIXES`; add
  `TABLE_NAMES = ['sfx_redirects', 'sfx_redirects_404']` and drop them in `run()`
  (`DROP TABLE IF EXISTS` on `$wpdb->prefix . $name`, counted when the table existed
  before and is gone after), unschedule `sfx_redirects_cleanup`, and return a `tables`
  count.
- **Purge screen** (`GeneralThemeOptions/AdminPage.php`): the warning text says the
  purge also deletes all redirect rules and the 404 log (they are editor work, so
  this is said before the confirmation phrase is typed); the result notice reports the
  `tables` count like the other counts.
- **ImportExport**: one settings group for `sfx_redirects_options` (rules travel as
  CSV, not in the theme JSON). Out-of-range values from that import are neutralised by
  `Settings::get()` clamping.
- **ThemeSettingsOverview**: enabled/disabled like other modules.
- **Disabling the module** keeps tables and settings; re-enabling restores everything.

## Testing

**Pure, stubbed** — `tests/redirects-rule-test.php`:

- `canonical_path`: case, trailing slash, `//`, `.`/`..`/`%2e%2e` segments, encoded chars, `%252F` idempotence
  (twice-applied equals once-applied, for every fixture), `/a%3Fb`, `/über` ≡
  `/%C3%BCber`, root;
- `request_parts`: home path `/blog` with `/blog`, `/blog/x`, `/blogger`, `/blog/blog/x`;
- `canonical_query`: order, repeated keys, dotted and nested keys preserved;
- `target_ok`: accepts `/x`, `https://a.b/x`, `https://a.b:8080/x`; rejects `//a.b`,
  `/\a.b`, `https://u:p@a.b/`, `https:///x`, `javascript:x`, `data:x`, `ftp://a`,
  whitespace, CR/LF, empty (except 410);
- regex: invalid pattern rejected; unescaped `~` rejected, escaped accepted; `$1`
  substitution (a `%c3%bc` capture is not double-encoded); unmatched group → empty;
  `%24` literal kept; template target with `$1` passes validation; `$3` beyond group count
  rejected; placeholder in host rejected; capture `/evil.com` into `/$1` → `null`;
  1025-byte subject skipped; catastrophic pattern `^/(a+)+$` on `/aaaa…ab` (1000
  bytes) returns no match within 1 s; 50 ms aggregate budget stops the loop;
- `pick`: exact+query > exact > regex (id order); query passthrough only when the
  target has none;
- loops: runtime identity allows `/?p=1`→`/?p=2`, skips a self-redirect and
  `/a` → `/x/../a`; 410 rules (empty target) are ignored by the loop checks; an
  external second target does not count as a loop; path-based save-time comparison (pure helper) flags `/a→/b` +
  `/b→/a?x=1`; `/?p=1`→`/?p=2` passes the single-rule check; targets with dot segments
  (`/x/../a`, `/a//../b`, `%2e%2e`) rejected;
- CSV: BOM, header mapping/duplicates, defaults, quoted commas, embedded newline,
  backslashes round-trip, injection escape/unescape round-trip including `'=x`.

**Settings** — `tests/redirects-settings-test.php`: `validate_snapshot` and `get()`
clamping (negative, huge, wrong-type values).

**Authorization** — `tests/redirects-handlers-test.php`: with stubbed
`check_admin_referer` (fails → throws) and `current_user_can` (false → throws via
stubbed `wp_die`), every admin-post handler is invoked and must throw **before** a
stub `$wpdb` records any query; the settings handler additionally fails for a user who
has `edit_others_posts` but fails `can_access_theme_settings()`.

**Existing** `tests/data-purge-test.php` Case 2 requires the new `OPTION_NAME` in
DataPurge — the intended guard. Its purge-run cases are extended for the `tables`
count.

**Live** — `tests/support/redirects-live-check.php`, run with MAMP php + `wp-load.php`,
following the AGENTS.md harness rule:

- fatal guards first: abort unless `home_url()` host is `sfx-bricks-child.local`;
  abort unless the two tables are **absent or empty** (so no real rule can intercept a
  harness URL and no real log row can be removed by a cleanup the harness triggers);
- booting WordPress from CLI writes nothing on the module's behalf (no `admin_init`:
  no schema install, no cron scheduling — see Cron lifecycle), so the guards and the
  snapshot, taken right after `wp-load.php`, see the pre-run state even when the module
  was already enabled; the harness then enables it (for the HTTP requests, which
  load it themselves), requires the module classes and calls
  `Repository::maybe_install()` directly;
- snapshot `sfx_general_options`, `sfx_redirects_options`, `sfx_redirects_db_version`
  (value or absence), whether each table existed, and the cron entry, before
  anything; a single `register_shutdown_function` teardown declared **before the first
  fixture** restores them: fixtures deleted, options restored or deleted, tables
  dropped again if they did not exist before, cron restored;
- fixtures carry a random marker (`/sfx-harness-<hex>/…`); teardown deletes rules with
  that marker in source or target, 404 rows with it in the path, and the test posts
  (`wp_delete_post(…, true)`, which also removes their `_wp_old_slug` meta);
- checks over HTTP with `curl -k`, **GET** (not HEAD, since `template-loader.php` exits
  on HEAD before rendering): exact 301 + `Location`; regex with `$1`; query passthrough;
  a 410 rule on an **existing Bricks page** (a harness-created page with Bricks
  content) returns 410 and not the page's content; hit counters moved; a random URL
  creates a 404 row; a slug change on a published test post creates the auto rule and
  a second change flattens the chain.

**Browser** (Chrome, once): add, edit, bulk, CSV round-trip; an editor account sees the
page but not the Settings tab.

## Key invariants touched

1. PSR-4 case — new `inc/Redirects/` files, `SFX\Redirects\…`.
2. Capability + nonce on every write — `AdminPage::guard()` on all seven handlers,
   pinned by `redirects-handlers-test.php`.
3. Escape at output — list tables, forms, notices.
4. `sfxtheme` text domain — every new string, including the registry title.
5./6./7. untouched (no build/release change; work ends as a PR).

Don'ts touched: new option keys carry the prefix **and** are in DataPurge; export is a
deliberate per-key decision (settings exported, rules via CSV only); the live harness
follows the single-teardown rule.

## Out of scope

Conditional matching (role, referrer, cookie, language), groups, .htaccess/Nginx
export, importers from other plugins (except the Redirection CSV subset of Addendum A1), JSON import/export of rules, per-hit log,
IP/user-agent logging, query-aware regex, REST/WP-CLI, wildcard syntax (regex covers
it), term-based permalink tracking, child-page tracking, complete loop detection
(see the stated gaps under Loop prevention), page-cache integration, multisite network-wide rules.

## Addendum A — Redirection import, permanent-redirect cache, target picker (2026-09-29)

Requested by Daniel on PR #41 after comparing with the Redirection plugin's feature set
(https://redirection.me/support/) and another redirect plugin's target UI. Three
additions, same PR.

### A1. Import CSV files exported by the Redirection plugin

Many client sites run the plugin *Redirection* (John Godley). Its CSV export (verified
in its source, `includes/import-export/format/class-csv.php`) has the header

```
source,target,regex,code,type,hits,title,status
```

`regex` `1`/`0`; `code` an HTTP code; `type` the action (`url`, `error`, and also
`random`, `pass`, `nothing`); `title` free text; `status` `active`/`disabled`. Cells its
sanitizer considers dangerous are prefixed with `[FORMULA] ` (a doubled prefix protects a
value that itself starts with the prefix). Sources and targets are **root-relative**
(they include a sub-directory install's home path). The CSV does not carry
Redirection's per-rule query mode, case/slash flags, groups, match conditions or logs.

The goal is a **safe subset**: import what means the same thing here, skip everything
else **with a reason per record** — never import something that would behave
differently in a way the editor cannot see.

**Detection:** after header normalisation (BOM, trim, lowercase), a header containing
both `regex` and `code` is a Redirection export. If it **also** contains `status_code`
or `match_type`, the whole file is rejected as ambiguous ("mixed column names"). Anything
else is our own format, unchanged. The import notice names the recognised format.

**Per-record mapping** — `Rule::redirection_record(array $record, array $map, string
$home_url): array|string` (the full home URL is passed in, so Rule stays pure and knows
scheme, host, port and path) returns our `validate()` input or a skip reason; the result
then goes through the same `Rule::validate()` / `Repository::import()` path as our own
format (no second write path):

| Redirection | Result |
|---|---|
| `type` `url` with `code` 301/302/307/308 | redirect with that code |
| `type` `error` with `code` 410 | 410 rule (target ignored) |
| any other `type`/`code` pair (`random`, `pass`, `nothing`, 303, 304, 404, 451, 5xx …) | skipped: "action/code not supported" |
| exact `source` | root-relative → home-relative: the home path is removed when it is a whole-segment prefix (followed by `/`, `?`, `#` or the end); a source outside the home path is skipped: "source outside this site's home path" |
| `target` starting with `//` (protocol-relative, an external authority) | prefixed with the home URL's scheme (`//cdn.test/x` → `https://cdn.test/x`), then validated as an absolute URL |
| `target` starting with a single `/` | inside the home path (same boundary rule, but **case-sensitive** — a destination's case is kept exactly) → home-relative; outside it → made absolute with the home URL's scheme, host and port, so it still points where it did |
| regex `source` on a sub-directory install | must begin with `^` followed literally by the home path and `/` — only possible when the home path consists of letters, digits, `/`, `_`, `-` and `.`, with every `.` written escaped (`\.`) in the pattern; that home path is removed (`^/blog/old$` → `^/old$`); otherwise skipped: "pattern outside this site's home path" |
| `regex` `1` | regex rule — **only** if the pattern provably matches the whole subject: starts with `^`, ends with an unescaped `$`, contains no top-level alternation (`|` outside parentheses, found by a scan that honours escapes and character classes), and no `\K`, no `(*…)` verb and no `(?` construct other than the non-capturing group
`(?:` (this excludes inline flags such as `(?x)`, which could turn the final `$` into a
comment, and lookarounds); otherwise skipped: "regex not importable safely — Redirection replaces only the matched part; anchor the whole pattern with ^…$". Such patterns make Redirection's `preg_replace` result equal the whole target, which is our semantics. |
| regex target containing `\1`-style or `${1}` references, or `$10`+ | skipped: "replacement syntax not supported (use $1–$9)" |
| target containing Redirection's transform/variable tags (`[userid]`, `[userlogin]`, `[unixtime]`, `[md5]`, `[upper]`, `[lower]`, `[dashes]`, `[underscores]`, with or without `/`) or its legacy tokens `%userid%`, `%userlogin%`, `%userurl%` | skipped: "dynamic target tags not supported" |
| `regex` anything else | exact rule |
| `status` `disabled` → false, anything else → true | `enabled` |
| `title` | `note` |
| `hits` | ignored: new rules start at 0; updating an existing rule keeps its counters (as for our own format) |

`[FORMULA] ` unescaping follows Redirection's own rule: a doubled prefix loses one; a
single prefix is removed only when the remainder is a value its sanitizer would have
escaped (first non-whitespace, non-control character `= + - @` or a full-width variant).
Our own `'`-escape is not applied to Redirection files. To keep such a value safe on our
**export**, `Rule::csv_escape_cell()` now also checks the first non-whitespace character
(not only the first byte).

**Semantics differences, stated in the import help:** our rules match the canonical path
— lowercase (ASCII), no trailing slash, percent-encoding normalised; an exact path rule
matches any query and passes it through, a regex never sees the query. So an imported
rule can match more, fewer, or differently-cased requests than it did in Redirection,
and regex captures are lowercased before they enter the target. Groups, conditions and
logs are not imported. Yoast / Rank Math formats are not supported (not verified).

Same limits as our own import (2 MB, 5000 records, partial commit, loop/cap checks,
`origin = import`).

### A2. Browser cache time for 301 and 308

Browsers may cache a 301/308 for a very long time when no cache header limits it, so a
mistaken permanent redirect sticks. Redirection sends a cache header for this ("HTTP Cache Header",
default one hour).

New setting `permanent_cache` in `sfx_redirects_options`:

| Value (seconds) | Label |
|---|---|
| `3600` (default) | 1 hour |
| `86400` | 1 day |
| `604800` | 1 week |
| `0` | no cache header from this module |

`validate_snapshot()` rejects a value outside the set with an error and keeps the stored
one; `get()` maps a stored value outside the set to the default (covers the ImportExport
JSON path).

In `send_match()`, **after** `wp_redirect()` returned true (a filter may cancel or change
it) and before `exit`: if `http_response_code()` is 301 or 308, the value is > 0, the
visitor is **not logged in**, `DONOTCACHEPAGE` is not defined-and-true (the
ecosystem-wide "do not cache" signal PasswordProtected already sets), **and** the only
cache policy already on the response is one we recognise — either there is no
`Cache-Control` header at all, or the main query is a 404 (`is_404()`) and the
`Cache-Control` value in `headers_list()` is **exactly** core's
`wp_get_nocache_headers()['Cache-Control']` (what `WP::handle_404()` sent) — then send `Cache-Control:
private, max-age=<n>` and `Expires: <now + n, GMT>`, replacing earlier values. In every
other case (any other `Cache-Control` value, a logged-in visitor, `DONOTCACHEPAGE`) the
existing headers stay untouched. Stated limit: code that calls core's `nocache_headers()`
itself on a 404 request without defining `DONOTCACHEPAGE` is indistinguishable from core's
404 handling and is replaced. **Accepted by Daniel on 2026-09-29 (PR #41)**: the
replacement header is `private` (browser only, never a shared cache), limited in time,
and applies to a redirect response only; findings about this case are out of scope. Most redirected addresses
no longer exist, so the 404 case is the common one; without it the setting would be
useless. `private` (not `public`) keeps the redirect out of shared caches and CDNs;
only the visitor's own browser keeps it for `n` seconds. With `0` the module adds nothing. 302/307 keep
`nocache_headers()`; 410 unchanged.

The rule form's status hint and the setting's description say: the time applies to
responses sent from now on — a browser that already cached a 301 keeps it for the time
it was given (without a header, possibly indefinitely). Shared caches and CDNs are not
meant to store it (`private`); a page cache in front of WordPress may still apply its
own rules (see "Page caches").

### A3. Target picker ("redirect to an existing page")

Next to the target field, a type select — **Custom URL** (default), every viewable post
type except attachments (`get_post_types(['public' => true])` filtered by
`is_post_type_viewable()`), and **Term archive** (option value `:term` — a colon cannot occur in a post
type key, so no post type can collide) — and, for a non-custom type, a native `<select>`
of that type's entries, **listed as soon as the type is chosen** (alphabetical, at most
200; a disabled last option says when the list was cut). A search box to narrow the list
appears only when the list has **more than 20 entries** (or was cut, or loading it
failed — then it is the way to retry), and stays once someone has typed (accessible by keyboard and screen reader without extra ARIA work;
changed on Daniel's request, 2026-09-29). Choosing
a result **writes its address into the target field**: home-relative when the permalink
(or term link) has the home URL's scheme, host and effective port and lies inside the
home path (whole-segment rule, case-sensitive), otherwise the absolute URL as returned. The conversion
happens on the server, in the search endpoint (`path` in the response). Attachments are left out: an attachment
of a draft or private post has status `inherit` and would leak through a search. What is stored is exactly what is stored today: the address. A
later rename of a **post** is covered by the slug monitor's auto rule (one extra hop), as
for a hand-typed address; renaming a **term** is not monitored, so a term target can go
stale (stated).

- Search endpoint: `wp_ajax_sfx_redirects_search` (admin-ajax, `GET`), `check_ajax_referer
  ('sfx_redirects_search')` **and** `current_user_can(AdminPage::CAPABILITY)` before
  anything else; input `type` (a post type from the allowed list or `:term`; anything
  else → `wp_send_json_error`, 400) and `q` (string, trimmed; fewer than 2 characters = list
  the type's entries — WordPress's search APIs treat `"0"` as empty —, 2–100 chars =
  search, longer = empty result); returns
  `{items: at most 200 {label, path}, more: bool}` as JSON (`wp_send_json_success`;
  201 are fetched so `more` says whether the list was cut; `more` is decided by the
  fetched count, not by the entries left after dropping unusable links). Posts: `WP_Query` with
  `post_status` `publish`, `no_found_rows`, `suppress_filters` false, 201 per page, and
  either `s` (search, relevance order) or `orderby` title ASC (list). Terms: `get_terms`
  over public taxonomies, `hide_empty` false, `hierarchical` false (so the limit applies
  in SQL), `orderby` name ASC, 201, plus `search` when `q` is given. Labels are plain text; the page
  escapes them when it builds the list (DOM `textContent`, never `innerHTML`). Not the
  core REST search route, because WPOptimizer can switch the REST API off.
- A read, not a write — invariant 2 does not apply, but the capability + nonce pair is
  used anyway: the endpoint discloses titles and paths.
- Plain JavaScript (`inc/Redirects/assets/redirects-admin.js`), enqueued only on the
  Redirects page; no library. Searches are debounced (300 ms) and a response is only
  applied if it answers the latest request (a request counter), so a slow earlier answer
  cannot overwrite newer results; a failed request shows a short message in the select. Without JavaScript the target is a plain text field, as
  now (progressive enhancement). All strings through `wp_localize_script` in
  `sfxtheme`.

### Testing (addendum)

- `tests/redirects-rule-test.php`: format detection (ours, Redirection, mixed →
  rejected); `redirection_record` for every row of the A1 table, including home-path
  stripping on a sub-directory install, anchored/unanchored regex, replacement syntax,
  transform tags, unsupported actions; `[FORMULA] ` unescaping (single, doubled,
  not-dangerous remainder); a real Redirection export line parsed through
  `fgetcsv(…, ',', '"', '')`; `csv_escape_cell` with leading whitespace before `=`.
- `tests/redirects-settings-test.php`: `permanent_cache` validation and `get()` mapping.
- `tests/redirects-handlers-test.php`: the search endpoint dies on a bad nonce and on a
  missing capability before any query.
- Live harness: a 301 on a non-existent source (the common case, after core's 404
  nocache headers) answers with `Cache-Control: private, max-age=3600` and an `Expires`
  header; a 302 still answers `no-cache`/`no-store`.
- Manual check over HTTP: a Redirection-format CSV uploaded through the real import
  handler creates the expected rules and skips the unsupported ones with reasons; the
  search endpoint returns a page's path for an editor.
