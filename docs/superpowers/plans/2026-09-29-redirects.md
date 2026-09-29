# Redirects Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps
> use checkbox (`- [ ]`) syntax for tracking.

**Goal:** a redirect manager module (`inc/Redirects/`) — exact/regex rules, 301/302/307/308/410,
hit counter, 404 log, automatic redirect on slug change, CSV import/export.

**Spec:** `docs/superpowers/specs/2026-09-29-redirects-design.md` — **the contract.** Every
task below names the spec sections it implements; read them in full before coding. The
plan fixes file boundaries, public signatures and the order of work; the spec fixes
behaviour. Where they seem to disagree, the spec wins — stop and report the disagreement.

**Story:** none — unprofiled cycle.

**Branch:** `feature/redirects` (checked out). Commit per task with a `WIP: redirects — <task>`
message (the Gate-B cycle closes with an amend at the end, CLAUDE.md §5 Mechanics).

**Tech:** PHP 8.0+, WordPress, Bricks parent theme (local verification on 2.4.1), no new dependencies. Tests are
standalone PHP scripts (`php tests/<file>.php`, exit ≠ 0 on failure), inline WordPress
stubs, no PHPUnit. Battery: `./quality.sh`.

## Global constraints

- `declare(strict_types=1)`, `namespace SFX\Redirects;`, files under `inc/Redirects/` with
  exact PSR-4 case (invariant 1). Add `inc/Redirects/index.php` = `<?php // Silence is golden.`
- Every user-facing string `__()`/`esc_html__()` etc. with domain `sfxtheme` (invariant 4).
- Escape at the echo site only (invariant 3).
- Every state-changing handler starts with `AdminPage::guard()` (invariant 2).
- Names verbatim from the spec's **Naming** table: `sfx_redirects_options`,
  `sfx_redirects_db_version`, tables `sfx_redirects` / `sfx_redirects_404`, slug
  `sfx-redirects`, cron `sfx_redirects_cleanup`, transient prefix `sfx_redirects_form_`,
  lock name `'sfx_redirects_' . md5(DB_NAME . $wpdb->prefix)` (Repository and DataPurge
  compute it identically), admin-post actions from **Admin actions**.
- Style: match `inc/PasswordProtected/` (4-space indent, typed signatures, docblocks that
  explain *why*, not *what*). `// ponytail:` comments where the spec names a ceiling.
- `Rule.php` must not reference `$wpdb`, options, hooks or the current user.
- Run `php -l` on every file you touch and your own test file(s) before you report done.
  `./quality.sh` is run by the controller at the **end of each wave** (mid-wave it can
  be red for reasons outside your task, e.g. data-purge Case 2 sees Task 2's
  `OPTION_NAME` before Task 3 adds it).
- MAMP php for anything needing WordPress: `/Applications/MAMP/bin/php/php8.5.2/bin/php`.
  Do not read or write outside the site root `/Users/daniel/DEVELOPMENT/LOCALHOST/sfx-bricks-child.local`.

## File structure

| File | Task | Responsibility |
|---|---|---|
| `inc/Redirects/Rule.php`, `inc/Redirects/index.php` | 1 | pure logic |
| `tests/redirects-rule-test.php` | 1 | its tests |
| `inc/Redirects/Settings.php` | 2 | option schema + save handler |
| `tests/redirects-settings-test.php` | 2 | its tests |
| `inc/DataPurge.php`, `inc/GeneralThemeOptions/{Settings,AdminPage}.php`, `inc/ThemeSettingsOverview/OverviewProvider.php`, `inc/ImportExport/Controller.php`, `tests/data-purge-test.php`, `tests/data-purge-handler-test.php`, `tests/theme-settings-overview-provider-test.php`, `tests/support/data-purge-stubs.php`, `tests/support/data-purge-handler-stubs.php` | 3 | integrations |
| `inc/Redirects/Repository.php` | 4 | all SQL |
| `inc/Redirects/Controller.php` | 5 | registration + front-end hooks + monitor + cron |
| `inc/Redirects/AdminPage.php`, `RedirectsTable.php`, `NotFoundTable.php` | 6 | admin UI + handlers |
| `tests/redirects-handlers-test.php` | 6 | authorization test |
| `languages/de_DE.po`, `.mo` | 7 | German |
| `tests/support/redirects-live-check.php` | 8 | live harness |

## Interfaces (fixed — later tasks code against these)

```php
final class Rule {
    public const STATUS_CODES = [301, 302, 307, 308, 410];
    public const MATCH_TYPES  = ['exact', 'regex'];
    public const MAX_SOURCE_BYTES = 255;  public const MAX_TARGET_BYTES = 2000;
    public const MAX_NOTE_BYTES = 255;    public const MAX_REGEX_SUBJECT = 1024;
    public const MAX_ENABLED_REGEX = 200;

    public static function canonical_path(string $raw): string;
    public static function canonical_query(string $raw): string;
    /** @return array{path:string, query:string, raw_query:string}
     *  query = canonical (lookup + identity); raw_query = as sent, minus a leading '?'
     *  (passthrough). */
    public static function request_parts(string $request_uri, string $home_path): array;
    public static function source_hash(string $match_type, string $source): string;

    public static function target_ok(string $target): bool;
    /** Returns the compiled pattern or null if invalid (incl. unescaped ~). */
    public static function compile_regex(string $body): ?string;
    /**
     * @param array $input raw strings/bools: source, match_type, target, status_code, enabled(bool), note
     * @return array{rule: array{source:string, match_type:string, target:string, status_code:int, enabled:bool, note:string}, errors: list<string>}
     */
    public static function validate(array $input): array;

    /**
     * @param list<array{id:int, source:string, match_type:string, target:string, status_code:int}> $candidates
     * @return null|array{id:int, status_code:int, url:?string}  url null for 410; url is
     *         the final ABSOLUTE URL (absolute_target applied) incl. raw-query passthrough,
     *         after resolve_target + target_ok + length cap + wp_sanitize_redirect; this
     *         exact string is used for the runtime identity check AND for sending.
     *         A match whose target fails resolution returns null.
     */
    public static function pick(array $candidates, string $path, string $query, string $raw_query, string $home_url): ?array;
    public static function resolve_target(string $target, array $captures): ?string;
    public static function append_query(string $target, string $query): string;
    /** Identity of an absolute URL for runtime loop detection. */
    public static function url_identity(string $absolute_url): string;
    /** Same-host canonical home-relative path (+ '?'query) used by loop checks and the
     *  monitor; null for external targets and for 410 (empty) targets. */
    public static function target_path(string $target, string $home_url): ?string;
    /**
     * Pure save-time loop decision (spec "Loop prevention", both checks). $reverse are
     * the enabled exact rules (id, source, target, status_code) whose source path equals
     * this rule's same-host target path; the rule's own id is excluded by the caller or
     * here. @return string|null  error message, null = no conflict
     */
    public static function loop_conflict(array $rule, int $id, array $reverse, string $home_url): ?string;
    /** Home-relative target → absolute URL under home_url; absolute returned as is.
     *  Built by string concatenation with the home_url() value, never home_url($path). */
    public static function absolute_target(string $target, string $home_url): string;
    /** Strip the home path as a whole-segment prefix from a URL path. */
    public static function home_relative(string $path, string $home_path): string;

    // CSV
    public static function csv_escape_cell(string $cell): string;
    public static function csv_unescape_cell(string $cell): string;
    /**
     * @param list<string> $header raw header row
     * @return array{map: array<string,int>, errors: list<string>}
     */
    public static function csv_header_map(array $header): array;
    /** Maps one record to validate() input, or returns an error string. @return array|string */
    public static function csv_record(array $record, array $map);
}

final class Settings {
    public const OPTION_NAME = 'sfx_redirects_options';
    public static function defaults(): array;
    public static function get(): array;                     // clamped
    /** @return array{values: array, errors: list<string>} */
    public static function validate_snapshot(array $post, array $existing): array;
    public static function register(): void;                 // add_action admin_post_sfx_redirects_save_settings
    public static function save_from_request(): void;        // guard first
}

final class Repository {
    public const DB_VERSION = '1';
    public static function table(): string;                  // $wpdb->prefix . 'sfx_redirects'
    public static function log_table(): string;              // $wpdb->prefix . 'sfx_redirects_404'
    public static function ready(): bool;
    public static function maybe_install(): void;            // admin_init
    public static function install_error(): string;          // '' when fine

    /** @return list<array>|null  null on DB error */
    public static function find_candidates(string $path, string $query): ?array;
    public static function record_hit(int $id): void;

    /** @return array{status:'found'|'missing'|'error', row?:array} */
    public static function get(int $id): array;
    /**
     * @return array{status:'created'|'updated'|'unchanged'|'missing'|'duplicate'|'conflict'|'cap'|'locked'|'error',
     *               id?:int, message?:string}   message = user-facing, translated
     */
    public static function save(array $rule, int $id, string $origin): array;  // lock + loop + cap + unique
    /**
     * @param iterable<int, array{record:int, rule:array}> $records  already validated by
     *        Rule::validate (the handler maps + validates; invalid records never reach here)
     * @return array{created:int, updated:int, unchanged:int, conflicts:list<string>, failed:int}
     */
    public static function import(iterable $records): array;
    /** @return array{done:int, unchanged:int, missing:int, conflicts:list<string>, failed:int, locked:bool} */
    public static function apply_op(string $op, array $ids): array;  // enable|disable|delete|reset
    /** @return array{items: list<array>, total:int}|null */
    public static function list_rules(string $search, string $orderby, string $order, int $page, int $per_page): ?array;
    public static function export_rows(): ?iterable;

    public static function log_404(string $path, string $referrer): void;
    public static function maybe_cleanup(bool $force = false): void;  // 1/100 unless forced
    public static function list_404(string $search, string $orderby, string $order, int $page, int $per_page): ?array;
    public static function delete_404(array $ids): int|false;
    public static function clear_404(): bool;

    /** Slug monitor, spec "Automatic redirect on slug change". Re-reads the post's
     *  current permalink itself under the lock. */
    public static function on_slug_change(int $post_id, string $old_source): void;
}
```

`Controller` and `AdminPage` expose only what WordPress hooks need plus
`AdminPage::CAPABILITY`, `AdminPage::$menu_slug`, `AdminPage::page_url(string $tab = '')`
and `AdminPage::guard(string $action, bool $settings = false): void`.

Signatures may gain *optional* parameters if a task needs them; any other change is
reported back before it is made.

## Waves

- **Wave 1 (parallel):** Task 1, Task 2, Task 3, Task 4 (Task 4 codes against the `Rule`
  interface above; until Task 1 lands, it stubs nothing — it only calls `Rule::` methods).
- **Wave 2 (parallel, after wave 1 is merged into the branch):** Task 5, Task 6.
- **Wave 3:** Task 7, then Task 8 (controller runs the live harness and browser check).

Each subagent works on the shared branch and touches **only its files**. Commits are made
by the controller after review, not by subagents.

---

## Task 1 — `Rule.php` (pure) + tests

**Spec sections:** Canonical forms · Source identity · Rule validation · Target predicate ·
Matching → Lookup (the `pick` part), Requests rules never apply to (not here — controller),
Regex rules · Target substitution · Query passthrough · Loop prevention (pure parts) ·
CSV import/export → Format + Spreadsheet safety · Testing → "Pure, stubbed".

- [ ] Write `tests/redirects-rule-test.php` first with every case the spec's Testing list
      names for `Rule` (loop cases through `Rule::loop_conflict` itself; request_parts
      returns raw_query unchanged; canonical_path incl. idempotence over all fixtures, request_parts,
      canonical_query, target_ok, regex, pick, loops, CSV). Stubs needed: `wp_parse_url`
      (delegate to `parse_url`), `wp_sanitize_redirect` (copy core's body verbatim from
      `wp-includes/pluggable.php` — our validation depends on its exact behaviour), `__`.
      `wp_sanitize_redirect`'s body calls `_wp_sanitize_utf8_in_redirect`,
      `wp_kses_no_null` and `_deep_replace` — copy those three verbatim into the test as
      well. Assertion helper style as in `tests/password-protected-settings-test.php`
      (`assert_true` → STDERR + exit 1; end with `echo "OK\n"`).
- [ ] Run it: fails (class missing).
- [ ] Implement `inc/Redirects/Rule.php` to the interface above.
- [ ] PCRE budget: `pick()` sets `pcre.backtrack_limit=100000`, `pcre.recursion_limit=10000`
      via `ini_set`, restores previous values in `finally`; skips regex when
      `strlen($path) > MAX_REGEX_SUBJECT`. The catastrophic-pattern test must finish < 1 s.
- [ ] Run test: passes. `php -l`. `./quality.sh` green.

## Task 2 — `Settings.php` + tests

**Spec:** Settings — `sfx_redirects_options` · Permissions (settings gate) · Admin actions
(notice mechanism).

- [ ] Test first: `tests/redirects-settings-test.php` — defaults; `validate_snapshot` types,
      clamps (1–365, 100–50000), bool checkboxes absent → false; `get()` clamps out-of-range
      and wrong-type stored values back into range/default.
- [ ] Implement following `inc/PasswordProtected/Settings.php`'s shape (TYPES, defaults,
      get, validate_snapshot). `save_from_request()` calls
      `AdminPage::guard('sfx_redirects_save_settings', true)` first (AdminPage arrives in
      Task 6; reference it by name only), then the PasswordProtected write/notice/redirect
      sequence with `settings-updated=true` to `AdminPage::page_url('settings')`.
- [ ] Test passes, `./quality.sh` green.

## Task 3 — Integrations

**Spec:** Registration · Integration points · Testing ("Existing").

- [ ] `GeneralThemeOptions/Settings.php::get_fields()`: add `enable_redirects` after
      `enable_media_credits`, default 0, label "Enable Redirects", description "Manage
      redirects (301, 302, 307, 308, 410), log 404 errors and create redirects automatically
      when a page's address changes." (both `sfxtheme`).
- [ ] `ThemeSettingsOverview/OverviewProvider.php::build_builtin_modules_group()`: add
      `'enable_redirects' => ['label' => __('Redirects', 'sfxtheme')]` in the same style;
      run `tests/theme-settings-overview-provider-test.php`.
- [ ] `DataPurge.php`: `// Redirects` block with `sfx_redirects_options`,
      `sfx_redirects_db_version`; `sfx_redirects_form_` in `TRANSIENT_PREFIXES`; new
      `TABLE_NAMES` const + `table_names()` accessor; `run()` drops each (count only when
      it existed before and is gone after — check with `SHOW TABLES LIKE` + `esc_like`),
      `wp_clear_scheduled_hook('sfx_redirects_cleanup')`, the drops run under
      `GET_LOCK('sfx_redirects_' . md5(DB_NAME . $wpdb->prefix), 5)` (same name as
      `Repository::lock_name()`; DataPurge must not depend on the module class, so it
      computes it itself) with the full lifecycle: lock not obtained → no drop, report
      `tables_locked => true`; before each `DROP` re-check `IS_USED_LOCK(name) =
      CONNECTION_ID()` (lost → stop, `tables_locked => true`); `RELEASE_LOCK` in
      `finally`. The purge notice says "redirect tables not deleted: another change was
      in progress" when `tables_locked` is set. Returns `tables` in its array
      (update the `@return` shape). Extend `tests/data-purge-test.php` (and
      `tests/support/data-purge-stubs.php` if `$wpdb`/cron stubs are missing) for the
      table drop and the count; keep every existing case green.
- [ ] `GeneralThemeOptions/AdminPage.php`: purge warning text mentions redirect rules and
      the 404 log; the result notice reports the tables count like the others (read how
      `handle_purge` passes counts through the redirect query args and follow it).
      Extend `tests/data-purge-handler-test.php` to assert the tables count and the
      `tables_locked` notice reach the redirect URL / screen, and
      `tests/theme-settings-overview-provider-test.php` to assert `enable_redirects` is
      listed; run both.
- [ ] `ImportExport/Controller.php::get_settings_groups()`: a `redirects` group
      (`option_key` `sfx_redirects_options`, type `single`, label/description `sfxtheme`),
      placed with the other module groups; add a short comment that rules travel as CSV.
- [ ] `./quality.sh` green.

## Task 4 — `Repository.php`

**Spec:** Data model (both tables, indexes) · Schema install / upgrade · Schema readiness
everywhere · Lookup · Write serialisation · Database error contract · Loop prevention
(save-time) · Regex cap · 404 log (logging, growth bound, cleanup) · Automatic redirect on
slug change (ownership, steps, stale check, transaction) · CSV import processing
(upsert semantics, counts) · Admin screen → SQL safety.

- [ ] DDL for `dbDelta` (two spaces after `PRIMARY KEY`, one field per line — dbDelta's
      format rules), `get_charset_collate()`. `maybe_install()` requires
      `wp-admin/includes/upgrade.php`, runs dbDelta, verifies tables/columns/unique keys,
      then writes the version; on failure stores the error in a static for
      `install_error()` and does not write the version.
- [ ] Every public **data** method: `if (!self::ready()) return <documented empty/false/null/'error'>;`.
      Exempt: `ready()`, `maybe_install()`, `install_error()`, `table()`, `log_table()`,
      `lock_name()`.
      Front-end methods (`find_candidates`, `record_hit`, `log_404`, `maybe_cleanup`) wrap
      queries in `suppress_errors(true)` / restore.
- [ ] Also add `public static function lock_name(): string`.
- [ ] Internal reads check `$wpdb->last_error` and return `null` on error; public
      methods translate that into their documented result (`get()` → `status 'error'`,
      `list_*`/`find_candidates`/`export_rows` → `null`, `save`/`apply_op`/`import` →
      `error`/`failed` counts). No public method's declared return type is violated.
- [ ] Lock helper: `with_lock(callable $fn)` using `GET_LOCK(%s, 5)`/`RELEASE_LOCK` in
      `finally`, name `'sfx_redirects_' . md5(DB_NAME . $wpdb->prefix)`, plus
      `lock_is_ours()` (`IS_USED_LOCK(name) = CONNECTION_ID()`) checked before the final
      write/COMMIT. Used by **every** rules-table write except `record_hit`, and by
      `maybe_install`. Expose `public static function lock_name(): string` so DataPurge
      can take the same lock (Task 3 uses the literal formula; keep them identical).
- [ ] Self-heal: a front-end/admin read whose `last_error` indicates error 1146 (table
      doesn't exist) deletes `sfx_redirects_db_version`.
- [ ] `save()`: `Rule::validate` is the caller's job — `save()` receives a validated rule;
      inside the lock: loop check = fetch reverse-edge candidates, call
      `Rule::loop_conflict()` (never re-implement the predicate here), regex
      cap when the result is an enabled regex, insert/update by id, duplicate-key →
      "a rule for this source already exists (#id)" (look the id up by hash). Editing
      through the form sets `origin` to the passed `$origin` (`manual` from the form,
      `404` when created from the log).
- [ ] `import()`: per record, upsert by `source_hash`; update keeps hits/last_hit but sets
      `origin = import`; counts per spec; one lock per record (not per file — a 2 MB file
      must not hold the lock for minutes).
- [ ] `apply_op`: fixed op list; `enable` goes through loop + cap checks per row; results
      counted.
- [ ] `list_rules` / `list_404`: orderby allowlists (`source`, `hits`, `last_hit`,
      `created_at` / `path`, `hits`, `last_seen`), order ASC|DESC, LIKE via `esc_like`.
- [ ] `log_404`: single `INSERT … ON DUPLICATE KEY UPDATE`, UTC timestamps
      (`gmdate('Y-m-d H:i:s')`), then `maybe_cleanup()`.
- [ ] `maybe_cleanup`: retention delete, then capped batches (≤5 × 1000, stop on false/0).
- [ ] `on_slug_change`: under lock: `clean_post_cache`, post still `publish`, current
      permalink → home-relative target (skip if it has a query or its canonical equals
      `$old_source`); every rule it inserts or changes is first built as input for
      `Rule::validate()` (source, target, 301, exact, enabled) and a validation error
      aborts the whole operation like any other failure; transaction,
      three steps with canonical comparisons, loop check per affected rule, lock check
      before COMMIT, rollback + `error_log` on any failure.
- [ ] Ownership: `save()` sets the passed origin; `apply_op` enable/disable set `manual`;
      reset/delete leave origin; `import` update sets `import`.
- [ ] No automated test in this task (needs a database) — it is exercised by Task 8's live
      harness. `php -l`, `./quality.sh` green (data-purge Case 2 must still pass).

## Task 5 — `Controller.php`

**Spec:** Registration · Matching → Where it runs, Requests rules never apply to,
Executing a redirect, 410 Gone · 404 log (hook, conditions, cron lifecycle) ·
Automatic redirect on slug change (hook, conditions, scope limit).

- [ ] `get_feature_config()` exactly as the spec's block (`url` via `admin_url`).
- [ ] Constructor: `Settings::register()`, `AdminPage::register()`, hooks:
      `admin_init` → `Repository::maybe_install`; `wp` @0 → `match_request`;
      `template_redirect` @0 → `send_match`; `template_redirect` @9999 → `log_404`;
      `post_updated` (3 args) → `on_post_updated`; `admin_init` → schedule cron if
      missing (never on `init` — a CLI/front-end bootstrap must write nothing);
      `sfx_redirects_cleanup` → `Repository::maybe_cleanup(true)`.
- [ ] `match_request`: after `pick()` returns non-null, **branch on status first**: 410 →
      410 preparation (no identity check, `url` is null); 3xx → runtime identity check.
      Exclusions list from the spec (maintenance mode; preview/builder
      only for logged-in `edit_posts` users; `bricks_preview`); `Rule::request_parts(wp_unslash(
      $_SERVER['REQUEST_URI'] ?? '/'), (string) wp_parse_url(home_url(), PHP_URL_PATH))`;
      `Repository::find_candidates`; `Rule::pick`; runtime loop check with
      `Rule::url_identity($match['url'])` against the current request URL (which pick
      already made absolute); store in `self::$match`;
      410 → (only if `!headers_sent()`) query reset + `remove_action`s.
- [ ] `send_match`: 3xx → `headers_sent` check, `nocache_headers()` for 302/307, relative
      send `$match['url']` exactly as `pick()` returned it (no second `home_url()`),
      `wp_redirect(...)` true → `record_hit` + `exit`; 410 →
      unless `headers_sent()`: `status_header(410)`, `nocache_headers()`, `record_hit`.
- [ ] `log_404`: conditions per spec; referrer reduced to scheme+host+path, ≤255 bytes,
      only if `log_referrer`.
- [ ] `on_post_updated`: all conditions + permalink-structure tag allowlist (post type's
      own `%<post_type>%` included), compute the canonical old source from
      `get_permalink($post_before)` via `Rule::home_relative` + `canonical_path`, call
      `Repository::on_slug_change($post_id, $old_source)`.
- [ ] `php -l`, `./quality.sh` green.

## Task 6 — Admin UI + handlers + authorization test

**Spec:** Permissions · Admin actions (table, guard, unslash/type rules, notices,
form-refill transient, export exception, stale rows) · Admin screen · CSV import/export
(all of it, UI side) · Testing → Authorization.

- [ ] Test first: `tests/redirects-handlers-test.php` — stubs: `check_admin_referer`
      (configurable pass/fail → throws `RuntimeException('nonce')`), `current_user_can`,
      `wp_die` (throws `RuntimeException('die')`), a `$wpdb` object recording every
      query/insert/update/delete call, `AccessControl` via a stub class in namespace `SFX`
      if loading the real one is impractical. For each of the seven actions: nonce fail
      → throws, no `$wpdb` call; nonce ok + cap false → throws, no `$wpdb` call. Settings
      handler: cap true + `can_access_theme_settings` false → throws. **Positive
      baseline** for each action: nonce ok + cap ok → does *not* throw at the guard (it
      may throw later at a stubbed `wp_safe_redirect`/`exit` replacement — use a stub that
      throws `RuntimeException('redirect')` and treat that as "passed the guard"), and
      the guard was called with the expected action string. Export's baseline runs with
      the schema **not ready** so the real handler reaches the throwing redirect stub
      instead of streaming and calling `exit`. The file ends by asserting a counter that
      every one of the seven actions × (nonce-fail, cap-fail, baseline) cases ran. Also assert `register()`
      adds an `admin_post_<action>` hook for each action (stub `add_action` records).
- [ ] `AdminPage.php`: `CAPABILITY`, statics, `register()` (admin_menu → `add_submenu_page(
      'tools.php', …)`; `admin_post_<action>` for the six rule/log/import/export actions),
      `guard()`, `page_url()`, `render_page()` with tabs, forms, notices, import help
      (format, 2 MB / 5000 records limit, spreadsheet-quote caveat), 301/308 caching hint,
      feed-URL hint, schema-not-ready state. Row actions as `wp_nonce_url` to admin-post.
- [ ] Handlers per the action table; import reads with
      `fgetcsv($h, 0, ',', '"', '')`, strips BOM, 5000-record cap before any write;
      export streams with `fputcsv(..., ',', '"', '')` + `Rule::csv_escape_cell`.
- [ ] Bulk adapter: each list table is rendered inside its own `<form method="post"
      action="admin-post.php">` with hidden `action=sfx_redirects_bulk` (or
      `sfx_redirects_404_action`) and `wp_nonce_field(<same action>)`. Both tables
      **override `display_tablenav($which)`**: for `top` print exactly one
      `<select name="op">` (the fixed ops) + submit button and nothing else; for `bottom`
      nothing. Pagination is printed by `AdminPage` **outside** the POST form, inside the
      separate `GET` form (hidden `page`, `tab`, `s`, `orderby`, `order`) via a public
      `print_pagination()` that calls `$this->pagination('top')` — so the page-number
      input can never submit the bulk form. Core's tablenav (its own
      `bulk-<plural>` `_wpnonce` and a second bulk select) is therefore never rendered —
      exactly one `_wpnonce` and one `op` per form. Row checkboxes are `ids[]`
      (`column_cb`). Search uses a separate `GET` form to the page (read-only, no nonce).
- [ ] `RedirectsTable.php` / `NotFoundTable.php`: `WP_List_Table` subclasses
      (`require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'` guarded by
      `class_exists`), data from `Repository::list_*`, bulk actions posting to admin-post
      (`sfx_redirects_bulk` / `sfx_redirects_404_action`), columns/sorting per spec, all
      cells escaped.
- [ ] Test passes, `./quality.sh` green.

## Task 7 — German translations

- [ ] Collect every new msgid: `grep -rhoE "__\(|_e\(|esc_html__\(|esc_html_e\(|esc_attr__\(|esc_attr_e\(|_n\(" inc/Redirects` is only a start — read the files.
- [ ] Add entries to `languages/de_DE.po` (check each msgid for an existing duplicate
      first), natural German, "Weiterleitung(en)", "404-Protokoll".
- [ ] `msgfmt --check --statistics languages/de_DE.po -o languages/de_DE.mo`; zero errors.

## Task 8 — Live harness, browser check (controller)

- [ ] Write `tests/support/redirects-live-check.php` exactly per spec Testing → Live
      (guards, snapshot, single teardown declared before the first fixture, marker
      fixtures, GET checks incl. the 410-on-Bricks-page check and the slug-chain check).
- [ ] Run it with MAMP php; fix what it finds (each fix re-runs `./quality.sh`).
- [ ] Browser check in Chrome per spec.

## Finish

- [ ] `./quality.sh` green; Gate B (CLAUDE.md §5) ≥3 passes, final clean; close the cycle
      with `git reset --soft <parent of first WIP>` and one real commit (several WIP
      snapshots exist); push; `gh pr create`. Do not merge.

---

## Addendum A — Redirection import, permanent-redirect cache, target picker

**Spec:** "Addendum A" at the end of the spec — the contract for A1–A3. Same branch,
same global constraints as above. Waves: Tasks A-1, A-2, A-3 run in parallel (disjoint
files); A-3 codes against the A-1 interface below.

### Interfaces (fixed)

```php
// Rule (pure)
public const REDIRECTION_COLUMNS = ['source','target','regex','code','type','hits','title','status'];
/** 'ours' | 'redirection' | error string (mixed header) — takes the raw header row. */
public static function csv_dialect(array $header): string;
/** Header map for a Redirection export (same shape/contract as csv_header_map). */
public static function redirection_header_map(array $header): array; // {map, errors}
/** One Redirection record → validate() input, or a skip reason (translated string). */
public static function redirection_record(array $record, array $map, string $home_url): array|string;
public static function redirection_unescape(string $cell): string;
// csv_escape_cell(): now also looks at the first non-whitespace character.

// Settings
// new key 'permanent_cache' (int, allowed PERMANENT_CACHE_CHOICES = [3600, 86400, 604800, 0]), default 3600
public const PERMANENT_CACHE_CHOICES = [3600, 86400, 604800, 0];
```

### Task A-1 — Rule: Redirection dialect (files: `inc/Redirects/Rule.php`, `tests/redirects-rule-test.php`)

- [ ] Tests first for everything under the addendum's Testing bullet for Rule (detection
      incl. mixed → error; every row of the A1 table; home-path cases on `https://ex.test/blog`
      and `https://ex.test:8443/blog`; regex importability scan: `^/a/(b|c)$` ok,
      `^/a|/b$`, `^/a\K$`, `^/a(?x)# $`, `^/a(*SKIP)$`, `^/a(?|x)$`, `/a/(.*)` rejected,
      `[|]` inside a class ok, escaped `\|` ok; replacement syntax `\1`, `${1}`, `$10`
      rejected; transform tags and `%userid%` rejected; protocol-relative target;
      `[FORMULA] ` single/doubled/not-dangerous; a real export line through
      `fgetcsv($h, 0, ',', '"', '')`; `csv_escape_cell(" =x")` escaped).
- [ ] `csv_unescape_cell()` mirrors the widened escape (a `'` before leading whitespace
      and a trigger is removed); round-trip test `" =x"` → export → import.
- [ ] Implement. Messages in `sfxtheme`. `redirection_record` returns input for
      `validate()` with keys source, match_type, target, status_code (string), enabled
      (bool), note.

### Task A-2 — Cache header (files: `inc/Redirects/Settings.php`, `inc/Redirects/Controller.php`, `tests/redirects-settings-test.php`, `tests/support/redirects-live-check.php`)

- [ ] Settings: key, default, `validate_snapshot` (non-choice → error, keep stored),
      `get()` (non-choice → default); the settings form field is rendered by AdminPage
      (Task A-3) — expose `Settings::permanent_cache_labels(): array<int,string>`.
- [ ] Controller::send_match per spec A2 (after successful `wp_redirect`, final code via
      `http_response_code()` is 301/308, value > 0, not logged in, `DONOTCACHEPAGE` not
      defined-and-true, and (no `Cache-Control` in `headers_list()` **or** (`is_404()`
      **and** that header's value equals `wp_get_nocache_headers()['Cache-Control']`)) →
      `Cache-Control: private, max-age=n` + `Expires`; otherwise headers untouched).
- [ ] Tests: settings cases; harness: the existing 301 check (non-existent source, so
      core's 404 nocache ran first) asserts `private, max-age=3600` and an `Expires`
      header whose date is ~1 h ahead; the existing 302 check still asserts
      no-cache/no-store; a 308 fixture gets the same header; with `permanent_cache` set to
      `0` (harness-owned option, restored by teardown) the 301 carries no positive
      `max-age` (core's `max-age=0` may be there); with `86400` the 301 carries
      `max-age=86400` (the value is read, not hard-coded).
      Logged-in, `DONOTCACHEPAGE` and foreign-`Cache-Control` branches and a cancelled
      `wp_redirect` or a filter changing its status: verified by code review, not by the harness (they need another
      module's state or a filter on the live site); stated.

### Task A-3 — Admin: import dispatch, cache setting field, target picker (files: `inc/Redirects/AdminPage.php`, `inc/Redirects/assets/redirects-admin.js`, `tests/redirects-handlers-test.php`)

- [ ] Import handler: after reading the header, `Rule::csv_dialect()`; error → reject the
      file; `redirection` → `redirection_header_map` + `redirection_record(…, home_url())`
      per record (skip reasons join the existing skipped list); notice names the format.
      Import help lists the Redirection support and the stated differences.
- [ ] Settings tab: `permanent_cache` select using `Settings::permanent_cache_labels()`,
      with the spec's explanatory text; the status-code hint in the rule form names the
      current value and carries the same explanation (future responses only; already
      cached redirects keep their time; shared caches are told not to store it).
- [ ] Picker per spec A3: `wp_ajax_sfx_redirects_search` registered in `register()`,
      handler checks nonce + capability first; server-side path conversion; JS (debounce,
      latest-request guard, native select, textContent only), enqueued on this page only
      via `admin_enqueue_scripts` with the page hook, `wp_localize_script` strings + nonce.
- [ ] Handler test: search endpoint — bad nonce → dies before any query; no capability →
      dies before any query; positive baseline (nonce + capability ok, GET with a valid
      `type` and `q`, stubbed `WP_Query`/`get_terms`) reaches the stubbed
      `wp_send_json_success` with the stubbed item's label and path, and the stub records
      that `WP_Query` received `s`, `post_type`, `post_status` `publish` and 20 per page; `register()` hooks exactly
      `[AdminPage::class, <search handler>]` on `wp_ajax_sfx_redirects_search`.
- [ ] German strings for every new message (languages/de_DE.po + .mo) — done by the
      controller after all three tasks.

### Controller acceptance (after A-1…A-3)

All acceptance steps run through a setup/teardown script like the pre-release UI check
(module state, test users, rules and tables restored or removed by one teardown; rules
created in the browser are deleted there before teardown and the teardown drops what it
created).

- [ ] Over HTTP with generated auth cookies (as in the pre-release UI check): upload a
      Redirection-format CSV through the real import handler → expected rules created,
      unsupported rows skipped with reasons; call the search endpoint as an editor → a
      page's path comes back; as a subscriber-level user → refused.
- [ ] Browser (Chrome, logged-in admin): pick a page in the target picker, the target
      field fills with its path; save works; with JavaScript off the plain target field
      still works. The latest-request guard is verified by reading the code (a local site
      answers too fast to force out-of-order responses); stated.
