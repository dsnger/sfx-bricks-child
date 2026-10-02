# REST API access for guests — Design

Module: `WPOptimizer` (option `sfx_wpoptimizer_options`). Verified against WordPress
7.1.2 and Bricks 2.4.2 in this checkout (2026-10-02). No story cited — unprofiled.

## Goal

WP Optimizer offers two all-or-nothing REST switches: `disable_rest_api` (REST off for
everyone — breaks Gutenberg, Bricks and plugins) and `disable_rest_api_non_authenticated`
(every guest request → 401). On real sites guests still need a few namespaces: Bricks
AJAX query loops, filters, pagination and live search call `bricks/v1`, so the guest
switch breaks the frontend. And `/wp-json/` publicly lists every namespace, which
discloses the plugin stack. Admins get a per-site middle ground.

## Decisions settled with Daniel (2026-10-02)

- Three modes: `open` (default, WordPress behaviour), `allowlist`, `closed`.
- Namespaces that appear after the list was saved stay **blocked** until someone ticks
  them (option (a)). Two signals make the cause findable: an admin notice naming the new
  namespaces, and the 401 message naming the namespace.
- "Test as guest" runs in the browser (`fetch` without credentials), not as a server
  loopback request.
- Migration is read-time, no database rewrite.

## Settings

All in `sfx_wpoptimizer_options`, group `security`. `Settings::get_fields()` gains:

| id | type | default | meaning |
|---|---|---|---|
| `rest_guest_mode` | `select` (`open`\|`allowlist`\|`closed`) | `open` | the mode |
| `rest_guest_namespaces` | `rest_namespaces` | `null` = never saved | map `namespace => 'get'\|'all'` of allowed namespaces |
| `rest_guest_hide_index` | `checkbox` | `1` | hide index + discovery for guests; effective only in `allowlist`/`closed` |
| `rest_guest_seen` | `hidden_list` (no UI row) | `[]` | namespaces present at the last save |

`disable_rest_api_non_authenticated` is **removed** from the fields. `disable_rest_api`
stays; its label becomes "Disable REST API completely (dangerous)" and its description
says it breaks the block editor, Bricks and most plugins, and points to the guest modes.

### Read-time migration

`Settings::get('rest_guest_mode')`: if the stored option has no `rest_guest_mode` key
and `disable_rest_api_non_authenticated` is truthy → `closed`; otherwise the stored value
if it is one of the three, else `open`. The sanitizer applies the same rule when its
input has no `rest_guest_mode` key — `update_option` runs the registered sanitize
callback, so an Import/Export restore of an old export (which carries only the old key)
must still land on `closed`. A form save always submits the mode, so the old key simply
drops out of the stored option on the next save (the sanitizer only writes known fields).

### Sanitizer

- `rest_guest_mode`: one of the three, else the migration rule above.
- `rest_guest_namespaces`: input is `namespaces[<ns>][allowed]` (checkbox) and
  `namespaces[<ns>][method]` (`get`|`all`). Kept: entries whose key matches
  `^[a-z0-9._-]+(/[a-z0-9._-]+)*$` (case-insensitive; namespaces are plugin-supplied)
  and whose `allowed` is set; method defaults to `all`. Output is the map; an empty map
  is a valid saved state ("nothing allowed"), distinct from `null` (never saved).
- `rest_guest_seen`: recomputed on every save from the live namespace list (below), not
  from input.
- Unknown types keep today's behaviour (checkbox → 0/1).

### Defaults before the first save

While `rest_guest_namespaces` is `null`, the allowlist **in effect and shown** is the
default set intersected with the live namespaces:

- `bricks/v1` → `all` (Bricks' query, filter and pagination routes are `POST`,
  `includes/api.php`); `oembed/1.0` → `get`;
- when present: `contact-form-7/v1` → `all`, `fluentform/v1` → `all`, `burst/v1` → `all`
  (Burst tracks through its beacon `endpoint.php`; REST is its fallback).

Never defaulted (listed as admin namespaces with a hint): `wp-site-health/v1`,
`wp-block-editor/v1`, `wp-abilities/v1`, `fluent-smtp`, `fluent-snippets`,
`core-framework/v2`. Everything not in either list starts unticked. `wp/v2` offers
`get` with the hint "public content readable (SEO tools, embeds); writes need a login
anyway".

## Enforcement

### The decision (pure, testable)

`SFX\WPOptimizer\classes\RestGuestAccess::decide(string $route, string $method,
string $mode, array $allowed, bool $hide_index, array $namespaces): ?string` returns
`null` (allowed) or the namespace label for the 401 (`''` for the index):

1. `mode === 'open'` → `null`.
2. `mode === 'closed'` → blocked.
3. `allowlist`:
   - route `/` (index) → blocked if `hide_index`, else allowed;
   - namespace = the **longest** entry of `$namespaces` such that the route equals
     `/<ns>` or starts with `/<ns>/`; none → blocked (unknown routes are not guest
     surface);
   - route exactly `/<ns>` (namespace discovery) → blocked if `hide_index`, else follows
     the namespace's entry;
   - namespace not in `$allowed` → blocked; entry `get` → only `GET` and `HEAD` allowed;
     `all` → any method.

### Hook

`rest_pre_dispatch` (priority 10), registered from `handle_context_sensitive_options`
(init 1) when the master switch allows and the mode is not `open`:

- Act only when `wp_is_serving_rest_request()` — a guest page render that calls
  `rest_do_request()` internally (blocks, plugins) is never affected.
- Pass through when a result is already set (another filter hijacked) or when
  `get_current_user_id() > 0`. By `rest_pre_dispatch` WordPress has run
  `check_authentication()` (application passwords at `rest_authentication_errors` 90,
  cookie + nonce at 100, which resets a cookie user without nonce to 0), so every
  authentication method is resolved; a cookie without nonce counts as a guest, as it
  does for WordPress itself. (Today's switch sat on `rest_authentication_errors` 10,
  before the nonce check — `closed` is therefore slightly stricter for nonce-less
  cookie requests. Intended.)
- `$allowed = apply_filters('sfx/rest_guest_allowed_namespaces', <effective map>)`.
- `$blocked = decide(...)`; then
  `$is_allowed = (bool) apply_filters('sfx/rest_guest_is_allowed', $blocked === null, $request)`.
- Not allowed → `new WP_Error('rest_forbidden_guest', <message>, ['status' => 401])`.
  Message: "The REST namespace %s is not available to guests." (namespace) or "The REST
  API index is not available to guests." (index / closed with route `/`), text domain
  `sfxtheme`. In `closed` mode the namespace message is used for namespace routes too.

`rest_pre_dispatch` also runs for each sub-request of `batch/v1` (they are checked one
by one) and for `_embed` sub-requests inside a served request; an embed into a blocked
namespace is therefore blocked as well. Documented, accepted.

### Discovery links

When the mode is `allowlist`/`closed`, `hide_index` is on and the visitor is a guest
(checked on `wp`, priority 0): `remove_action('wp_head', 'rest_output_link_wp_head', 10)`
and `remove_action('template_redirect', 'rest_output_link_header', 11)`. Logged-in
users keep both. oEmbed discovery links are not touched.

### Independence

`disable_rest_api` (REST off for everyone) wins whenever it is on. `block_rest_users_anonymous`,
`block_author_query` and Password Protection's `rest_authentication_errors` filter are
unchanged and independent. The master switch `disable_wp_optimizer` turns this off too.

## Namespace list (admin)

`RestGuestAccess::live_namespaces()`: `rest_get_server()->get_namespaces()`, sorted.
Called only on the WP Optimizer page, on save, and on the notice pages below — it builds
every route.

Per row: namespace, owner, hint, allowed checkbox, method select (`all` / `GET only`),
"new" badge if the namespace is not in `rest_guest_seen` (only after a first save).

**Owner detection:** for the first route of the namespace, reflect its first handler
callback (`ReflectionFunction` / `ReflectionMethod`; string `Class::method` and array
forms) to its file; under `WP_PLUGIN_DIR` → plugin folder → name via `get_plugins()`;
under the theme root → theme name; under `ABSPATH . WPINC` → "WordPress". Anything else
or any reflection error → empty. Known namespaces also carry a fixed hint (Bricks,
oEmbed, forms, Burst, wp/v2, admin ones).

Saved rows whose namespace no longer exists are kept in the stored map (plugin
temporarily off) and listed as "not present".

## Admin UI

On the WP Optimizer page, security group:

- Mode select. The namespace table and the hide-index checkbox show only for
  `allowlist` (hide-index also for `closed`), via the page's existing conditional-field
  script.
- **Bricks warning:** mode `allowlist`, Bricks is the active parent/template theme
  (`get_template() === 'bricks'`), and `bricks/v1` not allowed → inline warning
  "Bricks query loops, filters and pagination will fail for visitors".
- **Test as guest:** button below the table. JS `fetch(url, {credentials: 'omit'})`
  against `rest_url()` and `rest_url(<ns>)` for each allowed namespace **as saved**, shows
  `namespace → status`. Notes in the UI: tests the saved state; namespace discovery
  routes return 401 when the index is hidden (expected); a page cache or Password
  Protection can change results.
- **New-namespace notice:** `admin_notices` on `index.php`, `plugins.php` and the WP
  Optimizer page, for `manage_options`, mode `allowlist`, a saved list exists, and live
  namespaces minus `rest_guest_seen` is non-empty → "New REST namespaces are blocked for
  guests: …" with a link to the setting. Saving the page clears it.

## Theme settings overview

`OverviewProvider::build_wp_optimizer_group` counts checkbox fields only. Changes:
`rest_guest_hide_index` is skipped there (it would read "active" in `open` mode), and
the security section gets one item "REST API for guests" — active when the mode is not
`open`, detail = the mode label. Removing `disable_rest_api_non_authenticated` lowers the
security count by one; the new item adds it back.

## Import/Export and purge

Everything lives in `sfx_wpoptimizer_options`, already in both ownership lists — no list
changes. `rest_guest_seen` travels with an export; on another site it only affects which
namespaces show as "new" until the next save.

## Testing

Automated (`tests/wpoptimizer-rest-guest-test.php`, stubs in the style of
`wpoptimizer-security-behavior-test.php`):

- `decide()`: guest GET to an allowed namespace → allowed; disallowed namespace → blocked
  with that namespace; `/` with hide-index → blocked, without → allowed; `/<ns>`
  discovery with hide-index → blocked; `get` entry with `POST` → blocked, `HEAD` →
  allowed; longest-prefix match (`wp/v2` vs a hypothetical `wp`); unknown route →
  blocked; mode `open` → always allowed; `closed` → always blocked.
- The hook: logged-in user → untouched; not serving REST → untouched; existing result
  → untouched; `sfx/rest_guest_is_allowed` can flip both ways.
- Migration: old key 1 + no mode → `closed` (via `get` and via the sanitizer); old key 0
  → `open`; explicit mode wins.
- Sanitizer: invalid namespace keys dropped, method default, empty map vs `null`.

Live check against the local site (manual, `tests/support/`, fixtures behind one
teardown): with mode `allowlist` set and restored by the harness — `curl` guest to
`bricks/v1` route → not 401; guest to a disallowed namespace → 401
`rest_forbidden_guest`; guest `/wp-json/` → 401; same requests with an application
password → not 401; mode `open` → no 401 anywhere.

## Changelog

`CHANGELOG.md` entry under the next version: "WP Optimizer: REST API access for guests
— open / allowlist / closed, per-namespace methods, hidden index, new-namespace notice,
test button. The old guest switch maps to closed."

## Out of scope

Per-route (not per-namespace) rules; rate limiting; rules for logged-in roles; changing
`disable_rest_api`'s behaviour.

## Invariants touched

2 (the settings write stays on `options.php` + `register_setting`, capability and nonce
from WordPress' settings API), 3 (escaping in the new rows, notice, warning), 4 (all new
strings in `sfxtheme`). Coupling: none new — the overview already reads WP Optimizer.
