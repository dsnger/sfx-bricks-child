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

**Deviation from the task (hook):** the task named `rest_pre_dispatch`. At that point
WordPress has not matched the route yet, so the module would have to re-implement core's
route matching (namespace pre-filter, pattern order, method fallback, `OPTIONS`) — two
review passes found several ways a copy diverges and lets one namespace borrow another's
grant. This design uses `rest_dispatch_request`, where core hands over the **matched
route pattern** itself. It also runs after authentication (the task's actual reason for
`rest_pre_dispatch`).

## Storage

All in `sfx_wpoptimizer_options`, group `security`:

| key | stored shape | default | meaning |
|---|---|---|---|
| `rest_guest_mode` | `'open'`\|`'allowlist'`\|`'closed'` | `open` | the mode |
| `rest_guest_namespaces` | `null` (never saved) or a **list** of `['namespace' => string, 'method' => 'get'\|'all']` | `null` | allowed namespaces |
| `rest_guest_hide_index` | `0`\|`1` | `1` | hide index + discovery for guests; effective only in `allowlist`/`closed` |
| `rest_guest_seen` | list of strings | `[]` | namespaces an admin has seen on a saved form |

A **list of rows**, not a map keyed by namespace: Import/Export sanitizes array keys
with `sanitize_key()` (`ImportExport/Controller.php:1341`), which would turn `bricks/v1`
into `bricksv1`; values go through `sanitize_text_field()`. Supported namespaces are
restricted to characters both survive unchanged (below), so a stored identity cannot be
altered on the way through an import. No ImportExport change.

`disable_rest_api_non_authenticated` is **removed** from the fields.

## Normalisation (one place, read and write)

`classes/RestGuestAccess.php` owns pure normalisers, used by the sanitizer **and** by
every read (enforcement, admin page, notice, overview). Import/Export can write the
option while the module is off (no module sanitizer), so every read goes through them:

- `option(): array` — `get_option('sfx_wpoptimizer_options')`; anything but an array
  (scalar, `null`, object) → `[]`. All getters below take this array.
- `supported(string $ns): bool` — `^[A-Za-z0-9._~-]+(/[A-Za-z0-9._~-]+)*$`. Covers real
  namespaces (`wp/v2`, `bricks/v1`, `oembed/1.0`, `contact-form-7/v1`); all of these
  characters pass `sanitize_text_field()` and `wp_kses` untouched. A live namespace
  outside it is listed but **not selectable** ("unsupported characters — allow it in
  code via the filter").
- `mode(array $option): string` — `rest_guest_mode` if one of the three; else, if the
  option has no `rest_guest_mode` key and `disable_rest_api_non_authenticated` is truthy
  → `closed`; else `open`.
- `namespaces(array $option): ?array` — key absent or `null` → `null` (never saved). An
  array is read as rows; a row is kept when `namespace` is a string passing
  `supported()` and its `allowed` value — **if the row has that key** (form input) — is
  `'1'`/`1`/`true`. Stored rows have no `allowed` key. `method` `get` or `all`, else
  `all`. Duplicates: last row wins. Any other value (scalar, object) → `[]` —
  restrictive, never the defaults. Idempotent on its own output.
- `seen(array $option): array` — list of strings passing `supported()`, unique; else `[]`.

### Form shape

Each displayed, supported namespace posts `rest_guest_namespaces[i][namespace]`
(hidden), `rest_guest_namespaces[i][allowed]` as a hidden `0` **followed by** the
checkbox `1` (an unchecked box still posts `0`, so an unticked row is never a grant), and
`rest_guest_namespaces[i][method]`. Plus `rest_guest_form = 1` and
`rest_guest_displayed[]` (every namespace the form listed).

### Sanitizer (`Settings::sanitize_options`)

- New field types: `select` (value must be in the field's `options`, else default),
  `rest_namespaces`, `hidden_list`. Unknown types keep today's behaviour.
- `rest_guest_mode` → `mode($input)` (covers the legacy rule).
- `rest_guest_namespaces` → `namespaces($input)`; absent → `null`.
- `rest_guest_seen`: form save (`rest_guest_form = 1`) → previous stored seen ∪
  `rest_guest_displayed` (filtered by `supported()`); a namespace that appeared between
  rendering and saving is not acknowledged. Otherwise (import, programmatic) →
  `seen($input)`.
- The legacy key is not written back (only known fields are), so it drops out on the
  next form save.

Absent keys never borrow the stored value, so a **replace** import gives the same result
with the module on or off: exactly what the import carries, legacy rule included. With
**merge**, Import/Export keeps existing keys the import lacks and skips empty values
(`deep_merge_arrays`), so merging cannot clear the allowlist or seen list and keeps an
existing mode over a legacy flag — use replace for an exact copy (README note).

### Defaults before the first save

While `namespaces()` is `null`, the list **in effect and shown** is the default set
intersected with the live namespaces: `bricks/v1` → `all`; `oembed/1.0` → `get`; when
present `contact-form-7/v1`, `fluentform/v1`, `burst/v1` → `all` (Burst tracks through
its beacon `endpoint.php`; REST is its fallback). Before the first save no namespace is
"new". Admin namespaces get a hint and are never defaulted: `wp-site-health/v1`,
`wp-block-editor/v1`, `wp-abilities/v1`, `fluent-smtp`, `fluent-snippets`,
`core-framework/v2`. `wp/v2` hint: "GET only stops guest writes in this namespace;
endpoint permissions still apply as usual".

## Enforcement

### Hook

`rest_dispatch_request` (filter, args `$result, $request, $route, $handler`), priority
10, registered on `init` priority 1 (`handle_context_sensitive_options`) when the master
switch `disable_wp_optimizer` is off and the mode is not `open`.

Core calls it in `respond_to_request()` (`class-wp-rest-server.php:1238`) for the route
pattern it has matched, after authentication, parameter validation and the route's
`permission_callback`, right before the endpoint callback. A non-`null` return replaces
the callback; a `WP_Error` is turned into a response by core in the same function — on
the normal path and for every `batch/v1` item, which also go through
`respond_to_request()`.

- Act only when `wp_is_serving_rest_request()`: a guest page render that calls
  `rest_do_request()` internally is never affected.
- Pass through when `$result !== null` (another filter already decided) or
  `get_current_user_id() > 0`. Authentication ran in `check_authentication()` before
  dispatch — application passwords (`rest_authentication_errors` 90) and cookie + nonce
  (100, which resets a nonce-less cookie user to 0) — so every auth method is resolved;
  a cookie without nonce is a guest, as for WordPress itself. (The old switch sat at
  `rest_authentication_errors` 10, before the nonce check, so `closed` is slightly
  stricter for nonce-less cookie requests. Intended.)
- `$request->get_method()` is the method of this (sub-)request.

### Classifying the matched route

`RestGuestAccess::classify(string $route, array $handler, ?array $route_options): array{kind, namespace}`:

- `$handler['callback']` is `[WP_REST_Server, 'get_index']` → `index`;
  `[WP_REST_Server, 'get_namespace_index']` → `discovery` with
  `namespace = $route_options['namespace']`. Only the generated callbacks count — a
  plugin handler registered on `/<ns>` itself is a normal `namespace` route and follows
  the method rule.
- otherwise `namespace = $route_options['namespace'] ?? ''`; a non-empty string →
  `namespace`, else `unknown` (e.g. core's `/batch/v1`, inserted without route options).

### The decision (pure)

`RestGuestAccess::decide(string $kind, ?string $namespace, string $method, string $mode,
array $allowed, bool $hide_index): bool` (`$allowed`: namespace → method):

- `open` → true. `closed` → false.
- `allowlist`: `index` → `!hide_index`; `discovery` → `!hide_index` and namespace in
  `$allowed`; `namespace` → namespace in `$allowed` and (method `all`, or request method
  `GET`/`HEAD`); `unknown` → false.

So guest batch requests are blocked in `allowlist` (unless the filter allows them).

### Result

- `$allowed = apply_filters('sfx/rest_guest_allowed_namespaces', $map)`; a non-array
  result is ignored (map kept).
- `$ok = (bool) apply_filters('sfx/rest_guest_is_allowed', decide(...), $request)`.
- Not ok → `new WP_Error('rest_forbidden_guest', $message, ['status' => 401])`. Message
  by `kind`, independent of mode and filter: namespace/discovery → "The REST namespace
  %s is not available to guests."; index → "The REST API index is not available to
  guests."; unknown → "This REST route is not available to guests." (`sfxtheme`).
- Ok → `null` (core runs the callback).

### What guests can still observe (accepted, documented)

- Routes that do not exist answer 404 `rest_no_route` as before — no callback runs, so
  there is nothing to gate.
- `OPTIONS` is answered by core's `rest_handle_options_request` on `rest_pre_dispatch`,
  before matching, so it never reaches this hook: CORS preflights keep working (also for
  authenticated cross-origin requests), and a guest who already knows a route can read
  its argument schema.
- Parameter validation (400) and `permission_callback` (401/403) run before the hook, so
  their answers stay as they are; permission callbacks are checks by contract.

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

The page renders the new fields from the **normalised** values (the same getters
enforcement uses), not from the raw option — a legacy `closed` site shows `closed`, and
saving any other setting posts `closed` back.

### Namespace table

`RestGuestAccess::live_namespaces()`: `rest_get_server()->get_namespaces()`, sorted;
called only on the WP Optimizer page, on a form save and on the notice pages (it builds
every route). Rows: live namespaces ∪ stored ones. Per row: namespace (escaped), owner,
hint, allowed checkbox, method select (`all` / `GET only`), badge "new" (saved list
exists and namespace not in seen), "not present" (stored, not live — kept, e.g. plugin
temporarily off) or "unsupported characters" (not selectable).

**Owner:** walk the namespace's routes, **skipping** the generated
`get_namespace_index` handler; for the first handler callback that reflects
(`ReflectionFunction` for closures/strings, `ReflectionMethod` for arrays and
`Class::method`, `__invoke` for invokable objects) to a file: under `WP_PLUGIN_DIR` →
plugin folder → name from `get_plugins()`; under the theme root → theme name; under
`ABSPATH . WPINC` → "WordPress". Internal functions, reflection errors or anything else →
empty.

### Visibility

Mode select; the namespace table shows for `allowlist`, the hide-index checkbox for
`allowlist` and `closed`, using the page's existing `in_array` condition operator. Its
PHP side (`AdminPage::evaluate_condition`) already supports `in_array`; the JS side today
only reads `.checked` and is extended to read a select's `value` for `in_array` /
`!in_array`, checkbox behaviour unchanged. Server rendering uses the normalised mode.

### Bricks warning

Bricks is the template (`get_template() === 'bricks'`), the module is on, and either mode
`closed`, or mode `allowlist` with `bricks/v1` missing or set to `GET only` → inline
warning "Bricks query loops, filters, pagination and popups will fail for visitors —
they need bricks/v1 with all methods".

### Test as guest

Button below the table; checks the **saved** state, mode-aware, without running any
plugin callback:

- `GET /` — expected 401 when the index is hidden (allowlist/closed), else 200.
- For every live supported namespace: `GET /<ns>?sfx_guest_probe=1`. The hook answers a
  probe on a `discovery` route itself, before the hide-index rule: 200
  `{"sfx_guest_probe":"allowed","method":"get|all"}` when `decide()` for kind
  `namespace` with method `GET` (and the filters) allows it, else the normal 401
  `rest_forbidden_guest`. `get_namespace_index` never runs for a probe, so nothing is
  disclosed beyond what a guest learns by calling any route of that namespace. In
  `open` the hook is not registered and the probe returns the normal namespace index
  (200) — shown as "open".
- Output per namespace: configured state, observed result, verdict "as configured" /
  "differs" / "inconclusive" (any other status, network error, timeout). The `method`
  shown comes from the probe answer (GET-only vs all is configuration, not tested with
  writes).
- JS: `fetch(url, {credentials: 'omit', cache: 'no-store', signal})`, 10 s
  `AbortController` timeout per probe, button disabled during a run and restored in
  `finally`, a run id so a stale run cannot overwrite a newer one, output via
  `textContent`, strings via `wp_localize_script` (`sfxtheme`). UI note: a page cache,
  Password Protection or a CORS/TLS issue can change results.

### New-namespace notice

`admin_notices` on `index.php`, `plugins.php` and the WP Optimizer page, for
`manage_options`, module on, mode `allowlist`, saved list exists: live supported
namespaces that are not in seen **and not allowed** → "New REST namespaces are blocked
for guests: …" with a link to the setting. Saving the page acknowledges every displayed
namespace.

## Theme settings overview

`OverviewProvider::build_wp_optimizer_group` counts checkbox fields only. Changes:
`rest_guest_hide_index` is skipped there (it would read "active" in `open` mode), and
the security section gets one item "REST API for guests" — active when the normalised
mode is not `open`, detail = the mode label. Removing `disable_rest_api_non_authenticated`
lowers the security count by one; the new item adds it back.

## Import/Export and purge

Everything lives in `sfx_wpoptimizer_options`, already in both ownership lists. Storage
is chosen to survive Import/Export's sanitizer unchanged (list rows, supported
characters). Merge/replace semantics: see Sanitizer.

## Testing

Automated (`tests/wpoptimizer-rest-guest-test.php`, stubs in the style of
`wpoptimizer-security-behavior-test.php`):

- `classify()`: `get_index` → index; generated `get_namespace_index` → discovery; a
  plugin handler on `/<ns>` → namespace; normal route → namespace; route options without
  namespace (`/batch/v1`) → unknown.
- `decide()`: allowed GET → true; disallowed → false; `get` with `POST` → false, `HEAD`
  → true; index/discovery with and without hide-index; `open` → always true; `closed` →
  always false.
- Hook: logged-in → `null`; not serving REST → `null`; non-null incoming result →
  unchanged; blocked → `WP_Error` code `rest_forbidden_guest`, status 401, message by
  kind; `sfx/rest_guest_is_allowed` flips both ways; non-array
  `sfx/rest_guest_allowed_namespaces` ignored; probe on discovery → 200 allowed / 401,
  index callback never invoked.
- Normalisers: non-array option → `[]`; legacy 1 + no mode → `closed`; legacy 0 →
  `open`; explicit mode wins; `namespaces()` idempotent, absent/`null` → `null`, scalar
  → `[]`, unticked form row (`allowed = 0`) dropped, ticked kept, stored row without
  `allowed` kept, unsupported namespace dropped; `seen()`.
- Sanitizer: a serialized form with some rows unticked; form save sets seen = previous ∪
  displayed; import keeps imported seen; absent keys → never-saved defaults; a stored
  row list passed through ImportExport's recursive sanitizer keeps `bricks/v1`,
  `oembed/1.0`, `contact-form-7/v1`.

Live check against the local site (`tests/support/rest-guest-live-check.php`; one
teardown via `register_shutdown_function` declared before the first fixture, restoring
the WP Optimizer option and Password Protection settings and deleting the application
password it creates; fails fatally outside the site root):

- preconditions under the teardown: application passwords enabled (WP Optimizer's
  switch), Password Protection off;
- mode `allowlist`, `bricks/v1` and `oembed/1.0` allowed, `wp/v2` not: guest `GET
  /oembed/1.0/embed?url=<home>` → 200; guest `GET /wp/v2/posts` → 401
  `rest_forbidden_guest`; guest `/` → 401; guest probe `/bricks/v1?sfx_guest_probe=1` →
  200, `/wp/v2?sfx_guest_probe=1` → 401; `GET /wp/v2/posts` with the application
  password → 200;
- mode `open`: guest `GET /wp/v2/posts` → 200.

## Changelog

`CHANGELOG.md` under the next version: "WP Optimizer: REST API access for guests —
open / allowlist / closed, per-namespace methods, hidden index, new-namespace notice,
test button. The old guest switch maps to closed. 'Disable REST API' relabelled: it never
blocked REST requests."

## Out of scope

Per-route rules; rate limiting; rules for logged-in roles; changing `disable_rest_api`'s
behaviour; multisite network-level settings (per-site option as today); hiding `OPTIONS`
route metadata.

## Invariants touched

2 (settings write stays on `options.php` + `register_setting`; capability and nonce from
the settings API), 3 (escaping in rows, notice, warning; JS output via `textContent`), 4
(new strings in `sfxtheme`). Coupling: none new — the overview already reads WP
Optimizer.
