# REST API access for guests — Design

Module: `WPOptimizer` (option `sfx_wpoptimizer_options`). Verified against WordPress
7.1.2 and Bricks 2.4.2 in this checkout (2026-10-02). No story cited — unprofiled.

## Goal

WP Optimizer offers `disable_rest_api_non_authenticated` (every guest request → 401, no
exceptions). On real sites guests still need a few namespaces: Bricks AJAX query loops,
filters, pagination and popups call `bricks/v1` (`load_query_page`, `query_result`,
`load_popup_content` — all `POST`, `bricks/includes/api.php`), so the switch breaks the
frontend. And `/wp-json/` publicly lists every namespace, which discloses the plugin
stack. Admins get a per-site middle ground.

## Decisions settled with Daniel (2026-10-02)

- Three modes: `open` (default, WordPress behaviour), `allowlist`, `closed`.
- Namespaces that appear after the list was saved stay **blocked** until someone ticks
  them. Two signals make the cause findable: an admin notice naming them, and the 401
  message naming the namespace.
- "Test as guest" runs in the browser (`fetch` without credentials), not as a server
  loopback request.
- Migration is read-time, no database rewrite.

**Open for Daniel (found in review):** the task asked to label `disable_rest_api` as
dangerous. It is not: WordPress ignores `rest_enabled` since 4.7
(`apply_filters_deprecated`, `class-wp-rest-server.php:348`), so the switch only disables
JSONP, removes the REST/oEmbed discovery links and unregisters the `oembed/1.0` route —
REST itself stays fully open. This spec relabels it truthfully ("Remove REST discovery
links and oEmbed — does not block REST requests") and leaves its behaviour unchanged.
Guest enforcement does not depend on it.

## Storage

All in `sfx_wpoptimizer_options`, group `security`:

| key | stored shape | default | meaning |
|---|---|---|---|
| `rest_guest_mode` | `'open'`\|`'allowlist'`\|`'closed'` | `open` | the mode |
| `rest_guest_namespaces` | `null` (never saved) or a **list** of `['namespace' => string, 'method' => 'get'\|'all']` | `null` | allowed namespaces |
| `rest_guest_hide_index` | `0`\|`1` | `1` | hide index + discovery for guests; effective only in `allowlist`/`closed` |
| `rest_guest_seen` | list of strings | `[]` | namespaces acknowledged by an admin (shown on a saved form) |

A **list of rows**, not a map keyed by namespace: Import/Export sanitizes array keys
with `sanitize_key()` (`ImportExport/Controller.php:1341`), which would turn `bricks/v1`
into `bricksv1`; values go through `sanitize_text_field()`, which keeps them. Its merge
mode replaces indexed arrays whole (`deep_merge_arrays`), so a list is also merged
predictably. No ImportExport change.

`disable_rest_api_non_authenticated` is **removed** from the fields.

## Normalisation (one place, read and write)

`classes/RestGuestAccess.php` owns three pure normalisers, used by the sanitizer **and**
by every read (enforcement, admin page, notice, overview), so a value stored while the
module was off (Import/Export runs without the module's sanitizer) can never reach the
logic malformed:

- `mode(array $option): string` — `rest_guest_mode` if it is one of the three; else, if
  the option has no `rest_guest_mode` key and `disable_rest_api_non_authenticated` is
  truthy → `closed`; else `open`.
- `namespaces($value): ?array` — `null` stays `null` (never saved). An array is read as
  rows; a row is kept when `namespace` is a non-empty string after trimming slashes and
  whitespace (max 200 chars, no other grammar — WordPress registration imposes none) and,
  if the row has an `allowed` key (form input), that key is truthy. `method` is `get` or
  `all` (default `all`). Duplicates: last row wins. Output: list of
  `['namespace','method']`, idempotent on its own output. Anything else (scalar, object)
  → `null`.
- `seen($value): array` — list of non-empty trimmed strings, unique; anything else → `[]`.

### Sanitizer (`Settings::sanitize_options`)

- New field types: `select` (value must be in the field's `options`), `rest_namespaces`,
  `hidden_list`. Unknown types keep today's behaviour.
- `rest_guest_mode`: present in input → `mode()` of the input; absent → `mode()` of the
  input array (applies the legacy rule). So an old export imported with **replace**, or
  onto a site without a mode, lands on `closed`. With **merge** onto a site that already
  stores a mode, the existing mode is kept (merge keeps existing keys the import lacks —
  README "merge keeps existing values") — documented in the README.
- `rest_guest_namespaces`: key present → `namespaces()`; key absent → the currently
  stored value (normalised). The form always posts every displayed row (hidden
  `namespace`, `allowed` checkbox, `method` select), so "nothing ticked" saves as an
  empty list, distinct from `null`.
- `rest_guest_seen`: on a **form** save (input carries the hidden marker
  `rest_guest_form = 1` and the list `rest_guest_displayed[]` of namespaces the form
  showed): previous seen ∪ displayed — a namespace that appeared between rendering and
  saving is not acknowledged. Otherwise (import, programmatic update) → `seen()` of the
  input value, falling back to the stored value when absent.
- The old `disable_rest_api_non_authenticated` key is not written back (only known
  fields are), so it drops out on the next save.

### Defaults before the first save

While `rest_guest_namespaces` is `null`, the list **in effect and shown** is the default
set intersected with the live namespaces: `bricks/v1` → `all`; `oembed/1.0` → `get`;
when present `contact-form-7/v1`, `fluentform/v1`, `burst/v1` → `all` (Burst tracks
through its beacon `endpoint.php`; REST is its fallback). Before the first save no
namespace is "new". Admin namespaces get a hint and are never defaulted:
`wp-site-health/v1`, `wp-block-editor/v1`, `wp-abilities/v1`, `fluent-smtp`,
`fluent-snippets`, `core-framework/v2`. `wp/v2` hint: "GET only keeps public content
readable for SEO tools and embeds; writes need a login anyway".

## Enforcement

### Resolving the request

`RestGuestAccess::resolve(WP_REST_Server $server, string $route): array{kind, namespace}`:

1. route `/` → `kind = index`.
2. Mirror core's `match_request_to_handler()` (`class-wp-rest-server.php:1153`): the
   candidate routes are `get_routes($ns)` for every namespace in `get_namespaces()` order
   with `str_starts_with(trailingslashit(ltrim($route, '/')), $ns)`, merged — or all of
   `get_routes()` when none qualifies; take the first pattern with
   `preg_match('@^' . $pattern . '$@i', $route)` that has a handler for the request
   method (`HEAD` falls back to `GET` as in core; for `OPTIONS` the first pattern match
   counts, as `rest_handle_options_request` uses matching patterns regardless of
   method). Namespace = `$server->get_route_options($pattern)['namespace']`. This is the
   handler WordPress will run, so overlapping namespaces (`foo` registering
   `/foo/bar/items`, `foo/bar` registering `/foo/bar/other`) cannot borrow each other's
   grant. If that pattern is exactly `/<namespace>` → `kind = discovery`, else
   `kind = namespace`. A pattern matches but no handler takes the method → treated as
   step 3 (core answers 404/405 without running a callback).
3. No route matches: the longest namespace of `get_namespaces()` with route `/<ns>/…` →
   `kind = namespace` (core will answer 404 `rest_no_route`; no handler runs, so the
   fallback cannot grant anything — it only makes the "Test as guest" probe meaningful).
4. Otherwise `kind = unknown`, namespace `null` (also `/batch/v1`, which core inserts
   without a namespace).

### The decision (pure)

`RestGuestAccess::decide(array $resolved, string $method, string $mode, array $allowed,
bool $hide_index): bool` (`$allowed`: namespace → method):

- `open` → true. `closed` → false.
- `allowlist`: `index` → `!hide_index`; `discovery` → `!hide_index` and namespace in
  `$allowed`; `namespace` → namespace in `$allowed` and (method `all`, or request
  method in `GET`, `HEAD`, `OPTIONS`); `unknown` → false.

`OPTIONS` counts as read: core answers it with route metadata and runs no callback, and
browsers send it as CORS preflight for allowed reads. Blocked namespaces get 401 for
`OPTIONS` too. Guest batch requests are therefore unsupported in `allowlist` (unless the
filter allows them); in `open` they work as before.

### Hook

`rest_pre_dispatch` at priority **5** — before core's `rest_handle_options_request`
(priority 10), so `OPTIONS` handling is deterministic. Registered on `init` (priority 1,
`handle_context_sensitive_options`) when the master switch `disable_wp_optimizer` is off
and the mode is not `open`.

- Act only when `wp_is_serving_rest_request()`: a guest page render that calls
  `rest_do_request()` internally is never affected.
- Pass through when the incoming result is non-empty (an earlier filter hijacked) or
  `get_current_user_id() > 0`. By `rest_pre_dispatch` WordPress has run
  `check_authentication()` — application passwords (`rest_authentication_errors` 90) and
  cookie + nonce (100, which resets a nonce-less cookie user to 0) — so every auth
  method is resolved; a cookie without nonce is a guest, as for WordPress itself. (The
  old switch sat at priority 10, before the nonce check, so `closed` is slightly stricter
  for nonce-less cookie requests. Intended.)
- `$allowed = apply_filters('sfx/rest_guest_allowed_namespaces', $map)` — the effective
  map `namespace => method`; non-array results are ignored (map kept).
- `$is_allowed = (bool) apply_filters('sfx/rest_guest_is_allowed', decide(...), $request)`.
- Not allowed → return `rest_convert_error_to_response(new WP_Error('rest_forbidden_guest',
  $message, ['status' => 401]))`: a **response**, not a `WP_Error`, because core's batch
  path passes the filter result to response-only code (`class-wp-rest-server.php:1866`);
  `dispatch()` accepts either.
- Message by `kind`, independent of mode and of the filter: namespace/discovery → "The
  REST namespace %s is not available to guests."; index → "The REST API index is not
  available to guests."; unknown → "This REST route is not available to guests."
  (`sfxtheme`).

Sub-requests inside a served request (`_embed`, `batch/v1` items) pass the same filter
and are judged the same way. Documented, accepted.

### Discovery links

Mode `allowlist`/`closed`, `hide_index` on, visitor a guest (checked on `wp`, priority
0): `remove_action('wp_head', 'rest_output_link_wp_head', 10)` and
`remove_action('template_redirect', 'rest_output_link_header', 11)`. Logged-in users keep
both. oEmbed discovery links are not touched.

### Independence

`disable_rest_api` is independent (see "Open for Daniel"). `block_rest_users_anonymous`,
`block_author_query` and Password Protection's `rest_authentication_errors` filter are
unchanged. `disable_wp_optimizer` turns all of this off, and then the notice and the
Bricks warning are suppressed too.

## Admin

### Values shown

The page renders the three new fields from the **normalised** values (the same getters
enforcement uses), not from the raw option — a legacy `closed` site shows `closed`, and
saving any other setting posts `closed` back.

### Namespace table

`RestGuestAccess::live_namespaces()`: `rest_get_server()->get_namespaces()`, sorted;
called only on the WP Optimizer page, on a form save and on the notice pages (it builds
every route). Rows: live namespaces ∪ stored ones. Per row: namespace (escaped), owner,
hint, allowed checkbox, method select (`all` / `GET only`), badge "new" (saved list
exists and namespace not in seen) or "not present" (stored, not live — kept, e.g. plugin
temporarily off).

**Owner:** walk the namespace's routes, **skipping** the generated namespace-index
handler (`WP_REST_Server::get_namespace_index`); for the first handler callback that
reflects (`ReflectionFunction` for closures/strings, `ReflectionMethod` for arrays and
`Class::method`, `__invoke` for invokable objects) to a file: under `WP_PLUGIN_DIR` →
plugin folder → name from `get_plugins()`; under the theme root → theme name; under
`ABSPATH . WPINC` → "WordPress". Internal functions, reflection errors or anything else →
empty.

### Visibility and script

Mode select; the namespace table shows for `allowlist`, the hide-index checkbox for
`allowlist` and `closed`. The page's conditional-field script today only reads
`.checked`; it is extended to read a select's `value` (operator `in` with a value list),
checkbox behaviour unchanged.

### Bricks warning

Bricks is the template (`get_template() === 'bricks'`), the module is on, and either mode
`closed`, or mode `allowlist` with `bricks/v1` missing or set to `GET only` → inline
warning "Bricks query loops, filters, pagination and popups will fail for visitors —
they need bricks/v1 with all methods".

### Test as guest

Button below the table; tests the **saved** state. For `/`, and for each allowed
namespace: the discovery route `/<ns>` and a probe `GET /<ns>/sfx-guest-probe` (a route
that does not exist). Expected: allowed namespace → probe 404 `rest_no_route`; blocked →
401 `rest_forbidden_guest`; discovery and index → 401 when the index is hidden. Each row
shows status, error code and a verdict ("passes the guest gate" / "blocked"). JS:
`fetch(url, {credentials: 'omit', cache: 'no-store', signal})` with a 10 s
`AbortController` timeout per probe, network/abort errors shown as "no response", button
disabled during a run and restored in `finally`, a run id so a stale run cannot
overwrite a newer one, all output via `textContent`, strings from `wp_localize_script`
(`sfxtheme`). UI note: a page cache, Password Protection or a CORS/TLS issue can change
results.

### New-namespace notice

`admin_notices` on `index.php`, `plugins.php` and the WP Optimizer page, for
`manage_options`, module on, mode `allowlist`, saved list exists: namespaces that are
live, not in seen **and not allowed** → "New REST namespaces are blocked for guests: …"
with a link to the setting. Saving the page acknowledges every displayed namespace.

## Theme settings overview

`OverviewProvider::build_wp_optimizer_group` counts checkbox fields only. Changes:
`rest_guest_hide_index` is skipped there (it would read "active" in `open` mode), and
the security section gets one item "REST API for guests" — active when the normalised
mode is not `open`, detail = the mode label. Removing `disable_rest_api_non_authenticated`
lowers the security count by one; the new item adds it back.

## Import/Export and purge

Everything lives in `sfx_wpoptimizer_options`, already in both ownership lists. Storage
is chosen to survive Import/Export's sanitizer unchanged (list rows, see Storage). An
imported seen list is kept as imported.

## Testing

Automated (`tests/wpoptimizer-rest-guest-test.php`, stubs in the style of
`wpoptimizer-security-behavior-test.php`; a stub server exposing `get_routes`,
`get_route_options`, `get_namespaces`):

- `resolve()`: index; discovery; namespace via matched route and method; overlapping namespaces
  (`foo` owns `/foo/bar/items`, `foo/bar` owns `/foo/bar/other`) resolve to the owner;
  unmatched route under a namespace → that namespace; `/batch/v1` and unknown → unknown.
- `decide()`: allowed GET → true; disallowed → false; `get` with `POST` → false, `HEAD`
  and `OPTIONS` → true; index/discovery with and without hide-index; `open` → always
  true; `closed` → always false.
- Hook: logged-in → untouched; not serving REST → untouched; earlier result → untouched;
  blocked → a `WP_REST_Response` with status 401 and code `rest_forbidden_guest`;
  `sfx/rest_guest_is_allowed` flips both ways; message by kind.
- Normalisers: legacy 1 + no mode → `closed`; legacy 0 → `open`; explicit mode wins;
  `namespaces()` idempotent, `null` vs empty list, scalar → `null`, `allowed` handling,
  odd but valid namespace strings kept; `seen()`.
- Sanitizer: form save computes seen from displayed ∪ previous; import keeps imported
  seen; absent namespaces key keeps stored value; a row list passed through
  ImportExport's key sanitizer keeps `bricks/v1`.

Live check against the local site (`tests/support/rest-guest-live-check.php`, one
teardown via `register_shutdown_function` restoring the option and deleting the
application password it creates; it fails fatally outside the site root):

- precondition: temporarily enable application passwords (WP Optimizer's switch) and
  turn Password Protection off for the run, both restored in the teardown;
- mode `allowlist` with `bricks/v1` allowed, `wp/v2` not: guest `GET
  /bricks/v1/sfx-guest-probe` → 404 `rest_no_route`; guest `GET /wp/v2/posts` → 401
  `rest_forbidden_guest`; guest `/` → 401; the same `wp/v2` request with the application
  password → 200;
- mode `open`: guest `GET /wp/v2/posts` → 200.

## Changelog

`CHANGELOG.md` under the next version: "WP Optimizer: REST API access for guests —
open / allowlist / closed, per-namespace methods, hidden index, new-namespace notice,
test button. The old guest switch maps to closed. 'Disable REST API' relabelled: it never
blocked REST requests."

## Out of scope

Per-route rules; rate limiting; rules for logged-in roles; changing `disable_rest_api`'s
behaviour; multisite network-level settings (per-site option as today).

## Invariants touched

2 (settings write stays on `options.php` + `register_setting`; capability and nonce from
the settings API), 3 (escaping in rows, notice, warning; JS output via `textContent`), 4
(new strings in `sfxtheme`). Coupling: none new — the overview already reads WP
Optimizer.
