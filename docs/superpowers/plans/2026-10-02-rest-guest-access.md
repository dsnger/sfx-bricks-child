# REST API access for guests Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give WP Optimizer a per-site guest REST policy (open / allowlist / closed) with per-namespace methods, a hidden index, a read-time migration of the old guest switch, admin hints and a browser "test as guest".

**Architecture:** One new pure-ish class `SFX\WPOptimizer\classes\RestGuestAccess` owns normalisation, classification, the decision, the `rest_dispatch_request` gate, the probe route and the discovery-link removal. `Settings` gains four fields and routes their sanitising through that class (with a whole-form truncation guard that is repeat-safe). `AdminPage` renders the new controls, the warning, the notice and the test button. `OverviewProvider` gets one item.

**Tech Stack:** PHP 8.0+ (strict types), WordPress REST API (7.1.2), vanilla JS in the admin page, the repo's stub-based PHP tests run by `./quality.sh`.

**Spec:** `docs/superpowers/specs/2026-10-02-rest-guest-access-design.md` (Gate A: 8 passes, final clean).

## Global Constraints

- Option: everything in `sfx_wpoptimizer_options`; new keys `rest_guest_mode`, `rest_guest_namespaces`, `rest_guest_hide_index`, `rest_guest_seen`.
- Modes exactly `open` | `allowlist` | `closed`; default `open`.
- Stored allowlist: `null` (never saved) or a list of `['namespace' => string, 'method' => 'get'|'all']` — never a map keyed by namespace.
- `supported()` regex: `#\A[A-Za-z0-9._~-]+(/[A-Za-z0-9._~-]+)*\z#`, and no segment `.` / `..`.
- Internal probe namespace: `sfx-guest/v1`, route `/probe`.
- Error code `rest_forbidden_guest`, status 401.
- Filters: `sfx/rest_guest_allowed_namespaces` (map `namespace => method`), `sfx/rest_guest_is_allowed` (bool, `WP_REST_Request`).
- Gate hook: `rest_dispatch_request`, priority `PHP_INT_MAX`, 4 args.
- Defaults before first save: `bricks/v1` all, `oembed/1.0` get, `contact-form-7/v1` all, `fluentform/v1` all, `burst/v1` all (each only when live).
- Admin namespaces never defaulted: `wp-site-health/v1`, `wp-block-editor/v1`, `wp-abilities/v1`, `fluent-smtp`, `fluent-snippets`, `core-framework/v2`.
- Form markers: `sfx_wpo_form_start`, `sfx_wpo_form_end`; form save = `$_POST['option_page'] === 'sfx_wpoptimizer_options'`.
- Every user-facing string uses text domain `sfxtheme`; every output escaped at the point of output (AGENTS.md invariants 3, 4).
- No new runtime dependency; no ImportExport change; no change to `block_rest_users_anonymous`, `block_author_query`, Password Protection.

## Review Focus

1. A guest calling an unticked namespace after a plugin update (new namespace) — must be 401 `rest_forbidden_guest` naming the namespace, and the admin notice must list it (Task 2, Task 5 tests).
2. An upgraded site with only `disable_rest_api_non_authenticated = 1` — must enforce, show and re-save `closed` (Task 1, Task 3 tests).
3. A form save cut off by `max_input_vars` — the whole stored option must stay unchanged (Task 3 test).
4. `add_option` running the sanitizer twice on a first save — the allowlist must persist as ticked (Task 3 test).
5. A logged-in editor or an application-password client — never touched by the gate (Task 2 test, Task 7 live check).

---

### Task 1: `RestGuestAccess` normalisers

**Files:**
- Create: `inc/WPOptimizer/classes/RestGuestAccess.php`
- Test: `tests/wpoptimizer-rest-guest-test.php`

**Interfaces:**
- Produces: `RestGuestAccess::option(): array`, `supported(mixed $ns): bool`, `mode(array $o): string`, `namespaces(array $o, bool $from_form = false): ?array`, `seen(array $o): array`, `hide_index(array $o): bool`, `to_map(array $rows): array` (namespace => method), constants `MODES`, `INTERNAL_NAMESPACE`, `DEFAULTS`, `ADMIN_NAMESPACES`.

- [ ] **Step 1: Write the failing test** — `tests/wpoptimizer-rest-guest-test.php`:

```php
<?php

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/../../../../');
define('WPINC', 'wp-includes');

$test_options = [];
$test_filters = [];
$test_actions = [];
$test_removed_actions = [];
$test_user_id = 0;
$test_serving_rest = true;
$test_settings_errors = [];

function __($text, $domain = 'default') { return $text; }
function get_option($name, $default = false) { global $test_options; return array_key_exists($name, $test_options) ? $test_options[$name] : $default; }
function add_filter($hook, $cb, $prio = 10, $args = 1): bool { global $test_filters; $test_filters[$hook][] = ['callback' => $cb, 'priority' => $prio, 'accepted_args' => $args]; return true; }
function add_action($hook, $cb, $prio = 10, $args = 1): bool { global $test_actions; $test_actions[$hook][] = ['callback' => $cb, 'priority' => $prio, 'accepted_args' => $args]; return true; }
function remove_action($hook, $cb, $prio = 10): bool { global $test_removed_actions; $test_removed_actions[] = [$hook, $cb, $prio]; return true; }
function apply_filters(string $hook, $value, ...$args)
{
    global $test_filters;
    foreach ($test_filters[$hook] ?? [] as $f) {
        $value = ($f['callback'])(...array_merge([$value], array_slice($args, 0, max(0, $f['accepted_args'] - 1))));
    }
    return $value;
}
function get_current_user_id(): int { global $test_user_id; return $test_user_id; }
function is_user_logged_in(): bool { return get_current_user_id() > 0; }
function wp_is_serving_rest_request(): bool { global $test_serving_rest; return $test_serving_rest; }
function wp_unslash($v) { return $v; }
function add_settings_error($setting, $code, $message, $type = 'error'): void { global $test_settings_errors; $test_settings_errors[] = $code; }

class WP_Error
{
    public function __construct(public string $code = '', public string $message = '', public $data = null) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data() { return $this->data; }
}

require_once dirname(__DIR__) . '/inc/WPOptimizer/classes/RestGuestAccess.php';

use SFX\WPOptimizer\classes\RestGuestAccess as G;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// 1. option(): non-array stored values read as [].
$test_options['sfx_wpoptimizer_options'] = 'garbage';
assert_same([], G::option(), '1: scalar option -> []');
$test_options['sfx_wpoptimizer_options'] = ['rest_guest_mode' => 'closed'];
assert_same(['rest_guest_mode' => 'closed'], G::option(), '1: array option kept');

// 2. supported(): strict anchors, no dot segments, strings only.
foreach (['wp/v2', 'bricks/v1', 'oembed/1.0', 'contact-form-7/v1', 'fluent-smtp', 'a~b/c_d'] as $ok) {
    assert_same(true, G::supported($ok), "2: supported {$ok}");
}
foreach (["wp/v2\n", 'a/../b', '.', 'a/./b', '', 'a b/v1', 'a+b/v1', 'a%41/v1', '/wp/v2', 'wp/v2/', 123, null] as $bad) {
    assert_same(false, G::supported($bad), '2: unsupported ' . var_export($bad, true));
}

// 3. mode(): explicit, legacy, default.
assert_same('allowlist', G::mode(['rest_guest_mode' => 'allowlist']), '3: explicit');
assert_same('closed', G::mode(['disable_rest_api_non_authenticated' => 1]), '3: legacy 1 -> closed');
assert_same('open', G::mode(['disable_rest_api_non_authenticated' => 0]), '3: legacy 0 -> open');
assert_same('open', G::mode(['rest_guest_mode' => 'open', 'disable_rest_api_non_authenticated' => 1]), '3: explicit wins over legacy');
assert_same('open', G::mode(['rest_guest_mode' => 'weird']), '3: invalid -> open');
assert_same('open', G::mode([]), '3: empty -> open');

// 4. namespaces(): null vs [], rows, form rows, idempotence.
assert_same(null, G::namespaces([]), '4: absent -> null');
assert_same(null, G::namespaces(['rest_guest_namespaces' => null]), '4: null -> null');
assert_same([], G::namespaces(['rest_guest_namespaces' => 'bad']), '4: scalar -> []');
$stored = [['namespace' => 'bricks/v1', 'method' => 'all'], ['namespace' => 'oembed/1.0', 'method' => 'get'], ['namespace' => 'a/../b'], ['namespace' => 'x/v1', 'method' => 'nope']];
$norm = G::namespaces(['rest_guest_namespaces' => $stored]);
assert_same([['namespace' => 'bricks/v1', 'method' => 'all'], ['namespace' => 'oembed/1.0', 'method' => 'get'], ['namespace' => 'x/v1', 'method' => 'all']], $norm, '4: stored rows normalised');
assert_same($norm, G::namespaces(['rest_guest_namespaces' => $norm]), '4: idempotent');
$form = [
    ['namespace' => 'bricks/v1', 'allowed' => '1', 'method' => 'all'],
    ['namespace' => 'wp/v2', 'allowed' => '0', 'method' => 'get'],
    ['namespace' => 'cut/v1'],
];
assert_same([['namespace' => 'bricks/v1', 'method' => 'all']], G::namespaces(['rest_guest_namespaces' => $form], true), '4: form rows need allowed=1');
assert_same([['namespace' => 'b/v1', 'method' => 'get']], G::namespaces(['rest_guest_namespaces' => [['namespace' => 'b/v1', 'method' => 'all'], ['namespace' => 'b/v1', 'method' => 'get']]]), '4: last duplicate wins');

// 5. seen(), hide_index(), to_map().
assert_same(['a/v1', 'odd name'], G::seen(['rest_guest_seen' => ['a/v1', 'a/v1', '', 'odd name', 5, 'sfx-guest/v1']]), '5: seen keeps non-empty strings, unique, no internal namespace');
assert_same([], G::namespaces(['rest_guest_namespaces' => [['namespace' => 'sfx-guest/v1', 'method' => 'all']]]), '5: internal namespace never stored');
assert_same([], G::seen(['rest_guest_seen' => 'x']), '5: seen scalar -> []');
assert_same(true, G::hide_index([]), '5: hide absent -> true');
assert_same(true, G::hide_index(['rest_guest_hide_index' => null]), '5: hide null -> true');
assert_same(false, G::hide_index(['rest_guest_hide_index' => '0']), '5: hide "0" -> false');
assert_same(false, G::hide_index(['rest_guest_hide_index' => 0]), '5: hide 0 -> false');
assert_same(true, G::hide_index(['rest_guest_hide_index' => 1]), '5: hide 1 -> true');
assert_same(['bricks/v1' => 'all', 'oembed/1.0' => 'get'], G::to_map([['namespace' => 'bricks/v1', 'method' => 'all'], ['namespace' => 'oembed/1.0', 'method' => 'get']]), '5: to_map');

echo "wpoptimizer-rest-guest-test: PASS\n";
```

- [ ] **Step 2: Run it to see it fail**

Run: `/Applications/MAMP/bin/php/php8.5.2/bin/php tests/wpoptimizer-rest-guest-test.php`
Expected: fatal "Failed opening required … RestGuestAccess.php".

- [ ] **Step 3: Implement** — `inc/WPOptimizer/classes/RestGuestAccess.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\WPOptimizer\classes;

/**
 * Guest REST policy (spec docs/superpowers/specs/2026-10-02-rest-guest-access-design.md).
 * Every read of the four rest_guest_* keys goes through the normalisers below: Import/Export
 * can write the option while the module is off, so stored values may be anything.
 */
final class RestGuestAccess
{
    public const OPTION = 'sfx_wpoptimizer_options';
    public const MODES = ['open', 'allowlist', 'closed'];
    public const INTERNAL_NAMESPACE = 'sfx-guest/v1';
    public const DEFAULTS = [
        'bricks/v1'         => 'all',
        'oembed/1.0'        => 'get',
        'contact-form-7/v1' => 'all',
        'fluentform/v1'     => 'all',
        'burst/v1'          => 'all',
    ];
    public const ADMIN_NAMESPACES = [
        'wp-site-health/v1', 'wp-block-editor/v1', 'wp-abilities/v1',
        'fluent-smtp', 'fluent-snippets', 'core-framework/v2',
    ];

    public static function option(): array
    {
        $o = get_option(self::OPTION, []);
        return is_array($o) ? $o : [];
    }

    /** Characters that survive sanitize_text_field() and wp_kses unchanged, so an import cannot alter an identity. */
    public static function supported($ns): bool
    {
        if (!is_string($ns) || !preg_match('#\A[A-Za-z0-9._~-]+(/[A-Za-z0-9._~-]+)*\z#', $ns)) {
            return false;
        }
        foreach (explode('/', $ns) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    public static function mode(array $o): string
    {
        $mode = $o['rest_guest_mode'] ?? null;
        if (is_string($mode) && in_array($mode, self::MODES, true)) {
            return $mode;
        }
        // Read-time migration of the old all-or-nothing switch.
        if (!array_key_exists('rest_guest_mode', $o) && !empty($o['disable_rest_api_non_authenticated'])) {
            return 'closed';
        }
        return 'open';
    }

    /** @return list<array{namespace:string, method:string}>|null  null = never saved */
    public static function namespaces(array $o, bool $from_form = false): ?array
    {
        if (!array_key_exists('rest_guest_namespaces', $o) || $o['rest_guest_namespaces'] === null) {
            return null;
        }
        $rows = $o['rest_guest_namespaces'];
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !self::supported($row['namespace'] ?? null) || $row['namespace'] === self::INTERNAL_NAMESPACE) {
                continue;
            }
            // A form row is a grant only with an explicit allowed=1 (a cut-off row has none).
            if ($from_form && ($row['allowed'] ?? null) !== '1') {
                continue;
            }
            $method = ($row['method'] ?? null) === 'get' ? 'get' : 'all';
            $out[$row['namespace']] = ['namespace' => $row['namespace'], 'method' => $method];
        }
        return array_values($out);
    }

    /** @return list<string> */
    public static function seen(array $o): array
    {
        $list = $o['rest_guest_seen'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_unique(array_filter($list, static fn($v) => is_string($v) && $v !== '' && $v !== self::INTERNAL_NAMESPACE)));
    }

    public static function hide_index(array $o): bool
    {
        $v = $o['rest_guest_hide_index'] ?? null;
        if ($v === null) {
            return true;
        }
        return !in_array($v, [0, '0', false], true);
    }

    /** @param list<array{namespace:string, method:string}> $rows @return array<string,string> */
    public static function to_map(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$row['namespace']] = $row['method'];
        }
        return $map;
    }
}
```

Note `namespaces()`: the "last duplicate wins" keyed assignment keeps the **first** position; the test expects one row with the last method — `array_values` after keyed overwrite gives that.

- [ ] **Step 4: Run** `php tests/wpoptimizer-rest-guest-test.php` → `PASS`; `./quality.sh` → `QUALITY: PASS`.

- [ ] **Step 5: Commit**

```bash
git add inc/WPOptimizer/classes/RestGuestAccess.php tests/wpoptimizer-rest-guest-test.php
git commit -m "feat(wpoptimizer): REST guest policy normalisers"
```

---

### Task 2: Classification, decision, gate, probe, discovery links

**Files:**
- Modify: `inc/WPOptimizer/classes/RestGuestAccess.php`
- Test: `tests/wpoptimizer-rest-guest-test.php` (append before the final `echo`)

**Interfaces:**
- Consumes: Task 1 normalisers.
- Produces: `classify(WP_REST_Request $request, string $route, array $handler, ?array $route_options): array{kind:string, namespace:?string}`, `decide(string $kind, ?string $ns, string $method, string $mode, array $allowed, bool $hide): bool`, `effective_map(array $o, array $live): array`, `enforcing(array $o): bool`, `gate($result, $request, string $route, array $handler)`, `live_namespaces(): array`, `register_probe_route(): void`, `probe(WP_REST_Request $request)`, `remove_discovery_links(): void`, `boot(): void`.

- [ ] **Step 1: Append failing tests**

```php
class WP_REST_Request implements ArrayAccess
{
    public function __construct(private string $method = 'GET', private array $params = []) {}
    public function get_method(): string { return $this->method; }
    public function get_param(string $k) { return $this->params[$k] ?? null; }
    public function offsetExists($k): bool { return isset($this->params[$k]); }
    public function offsetGet($k): mixed { return $this->params[$k] ?? null; }
    public function offsetSet($k, $v): void { $this->params[$k] = $v; }
    public function offsetUnset($k): void { unset($this->params[$k]); }
}
class WP_REST_Server
{
    public array $ns = ['wp/v2', 'bricks/v1', 'oembed/1.0', 'sfx-guest/v1'];
    public function get_index() {}
    public function get_namespace_index() {}
    public function get_namespaces(): array { return $this->ns; }
    public function get_route_options($route) {
        foreach ($this->ns as $ns) {
            if ($route === '/' . $ns || str_starts_with($route, '/' . $ns . '/')) {
                return ['namespace' => (string) $ns];
            }
        }
        return null;
    }
}
function register_rest_route($ns, $route, $args): bool { return true; }
$test_server = new WP_REST_Server();
function rest_get_server() { global $test_server; return $test_server; }

$get = new WP_REST_Request('GET');
$h = ['callback' => 'some_callback'];

// 6. classify()
assert_same(['kind' => 'index', 'namespace' => null], G::classify($get, '/', ['callback' => [$test_server, 'get_index']], null), '6: index');
assert_same(['kind' => 'discovery', 'namespace' => 'wp/v2'], G::classify($get, '/wp/v2', ['callback' => [$test_server, 'get_namespace_index']], ['namespace' => 'wp/v2']), '6: discovery');
assert_same(['kind' => 'discovery', 'namespace' => 'bricks/v1'], G::classify(new WP_REST_Request('GET', ['namespace' => 'bricks/v1']), '/wp/v2', ['callback' => [$test_server, 'get_namespace_index']], ['namespace' => 'wp/v2']), '6: discovery judged by ?namespace override');
assert_same(['kind' => 'unknown', 'namespace' => null], G::classify(new WP_REST_Request('GET', ['namespace' => ['x']]), '/wp/v2', ['callback' => [$test_server, 'get_namespace_index']], ['namespace' => 'wp/v2']), '6: array override -> unknown');
assert_same(['kind' => 'unknown', 'namespace' => null], G::classify(new WP_REST_Request('GET', ['namespace' => 123]), '/wp/v2', ['callback' => [$test_server, 'get_namespace_index']], ['namespace' => 'wp/v2']), '6: integer override -> unknown');
assert_same(['kind' => 'namespace', 'namespace' => 'wp/v2'], G::classify($get, '/wp/v2', $h, ['namespace' => 'wp/v2']), '6: plugin handler on namespace root -> namespace');
assert_same(['kind' => 'namespace', 'namespace' => 'wp/v2'], G::classify($get, '/wp/v2/posts', $h, ['namespace' => 'wp/v2']), '6: normal route');
assert_same(['kind' => 'unknown', 'namespace' => null], G::classify($get, '/batch/v1', $h, null), '6: batch -> unknown');
assert_same(['kind' => 'namespace', 'namespace' => '123'], G::classify($get, '/123/x', $h, ['namespace' => 123]), '6: numeric namespace as string');

// 7. decide()
$allowed = ['bricks/v1' => 'all', 'oembed/1.0' => 'get'];
assert_same(true, G::decide('namespace', 'bricks/v1', 'POST', 'allowlist', $allowed, true), '7: allowed all + POST');
assert_same(false, G::decide('namespace', 'wp/v2', 'GET', 'allowlist', $allowed, true), '7: not allowed');
assert_same(false, G::decide('namespace', 'oembed/1.0', 'POST', 'allowlist', $allowed, true), '7: get-only + POST');
assert_same(true, G::decide('namespace', 'oembed/1.0', 'HEAD', 'allowlist', $allowed, true), '7: get-only + HEAD');
assert_same(false, G::decide('index', null, 'GET', 'allowlist', $allowed, true), '7: index hidden');
assert_same(true, G::decide('index', null, 'GET', 'allowlist', $allowed, false), '7: index shown');
assert_same(false, G::decide('discovery', 'bricks/v1', 'GET', 'allowlist', $allowed, true), '7: discovery hidden');
assert_same(true, G::decide('discovery', 'bricks/v1', 'GET', 'allowlist', $allowed, false), '7: discovery shown + allowed');
assert_same(false, G::decide('discovery', 'wp/v2', 'GET', 'allowlist', $allowed, false), '7: discovery shown + not allowed');
assert_same(false, G::decide('unknown', null, 'GET', 'allowlist', $allowed, false), '7: unknown');
assert_same(true, G::decide('namespace', 'wp/v2', 'DELETE', 'open', [], true), '7: open');
assert_same(false, G::decide('namespace', 'bricks/v1', 'GET', 'closed', $allowed, false), '7: closed');

// 8. effective_map(): never saved -> defaults ∩ live; saved -> rows.
assert_same(['bricks/v1' => 'all', 'oembed/1.0' => 'get'], G::effective_map([], ['wp/v2', 'bricks/v1', 'oembed/1.0']), '8: defaults ∩ live');
assert_same([], G::effective_map(['rest_guest_namespaces' => []], ['bricks/v1']), '8: saved empty stays empty');

// 9. gate()
$test_options['sfx_wpoptimizer_options'] = ['rest_guest_mode' => 'allowlist', 'rest_guest_namespaces' => [['namespace' => 'bricks/v1', 'method' => 'all']], 'rest_guest_hide_index' => 1];
$posts = ['callback' => 'cb'];
$r = G::gate(null, $get, '/wp/v2/posts', $posts);
assert_same('rest_forbidden_guest', $r instanceof WP_Error ? $r->get_error_code() : null, '9: blocked guest -> WP_Error');
assert_same(['status' => 401], $r->get_error_data(), '9: status 401');
assert_same('The REST namespace wp/v2 is not available to guests.', $r->get_error_message(), '9: namespace message');
assert_same(null, G::gate(null, new WP_REST_Request('POST'), '/bricks/v1/load_query_page', $posts), '9: allowed guest keeps null');
$early = new stdClass();
assert_same($early, G::gate($early, new WP_REST_Request('POST'), '/bricks/v1/x', $posts), '9: allowed guest keeps an earlier result');
assert_same(true, G::gate($early, $get, '/wp/v2/posts', $posts) instanceof WP_Error, '9: blocked guest overrides an earlier result');
$test_user_id = 5;
assert_same(null, G::gate(null, $get, '/wp/v2/posts', $posts), '9: logged in untouched');
$test_user_id = 0;
$test_serving_rest = false;
assert_same(null, G::gate(null, $get, '/wp/v2/posts', $posts), '9: not serving REST untouched');
$test_serving_rest = true;
assert_same(null, G::gate(null, $get, '/sfx-guest/v1/probe', $posts), '9: probe route always passes');
add_filter('sfx/rest_guest_is_allowed', static fn($ok, $req) => true, 10, 2);
assert_same(null, G::gate(null, $get, '/wp/v2/posts', $posts), '9: is_allowed filter can allow');
$test_filters['sfx/rest_guest_is_allowed'] = [];
add_filter('sfx/rest_guest_allowed_namespaces', static fn($m) => 'not an array');
assert_same(null, G::gate(null, new WP_REST_Request('POST'), '/bricks/v1/x', $posts), '9: non-array map filter ignored');
$test_filters['sfx/rest_guest_allowed_namespaces'] = [];
$test_options['sfx_wpoptimizer_options']['disable_wp_optimizer'] = 1;
assert_same(null, G::gate(null, $get, '/wp/v2/posts', $posts), '9: master switch off -> untouched');
unset($test_options['sfx_wpoptimizer_options']['disable_wp_optimizer']);

// 10. probe()
$p = G::probe(new WP_REST_Request('GET', ['namespace' => 'bricks/v1']));
assert_same(['state' => 'allowed', 'method' => 'all'], $p, '10: allowed');
assert_same(['state' => 'blocked', 'method' => null], G::probe(new WP_REST_Request('GET', ['namespace' => 'wp/v2'])), '10: blocked');
assert_same(['state' => 'blocked', 'method' => null], G::probe(new WP_REST_Request('GET', ['target' => 'index'])), '10: index hidden');
assert_same('rest_no_route', (G::probe(new WP_REST_Request('GET', ['namespace' => 'nope/v1'])))->get_error_code(), '10: unknown namespace -> 404 error');
assert_same('rest_no_route', (G::probe(new WP_REST_Request('GET', ['namespace' => 'sfx-guest/v1'])))->get_error_code(), '10: internal namespace not probed');
$test_options['sfx_wpoptimizer_options']['rest_guest_mode'] = 'open';
assert_same(['state' => 'open', 'method' => null], G::probe(new WP_REST_Request('GET', ['namespace' => 'wp/v2'])), '10: open');
$test_options['sfx_wpoptimizer_options']['rest_guest_mode'] = 'allowlist';

// 11. live_namespaces() drops the internal namespace and stringifies.
$test_server->ns = ['wp/v2', 123, 'sfx-guest/v1', 'a/v1'];
assert_same(['123', 'a/v1', 'wp/v2'], G::live_namespaces(), '11: live namespaces');
$test_server->ns = ['wp/v2', 'bricks/v1', 'oembed/1.0', 'sfx-guest/v1'];

// 12. remove_discovery_links() only for guests when hiding applies.
$test_removed_actions = [];
G::remove_discovery_links();
assert_same([['wp_head', 'rest_output_link_wp_head', 10], ['template_redirect', 'rest_output_link_header', 11]], $test_removed_actions, '12: links removed for guest');
$test_removed_actions = [];
$test_user_id = 5;
G::remove_discovery_links();
assert_same([], $test_removed_actions, '12: logged-in keeps links');
$test_user_id = 0;

// 13. Remaining gate/probe contracts.
$marker = new stdClass();
$test_user_id = 5;
assert_same($marker, G::gate($marker, $get, '/wp/v2/posts', $posts), '13: logged in keeps a non-null result');
$test_user_id = 0;
$test_serving_rest = false;
assert_same($marker, G::gate($marker, $get, '/wp/v2/posts', $posts), '13: not serving REST keeps a non-null result');
$test_serving_rest = true;
assert_same($marker, G::gate($marker, $get, '/sfx-guest/v1/probe', $posts), '13: probe route keeps a non-null result');
add_filter('sfx/rest_guest_is_allowed', static fn($ok, $req) => false, 10, 2);
assert_same(true, G::gate(null, new WP_REST_Request('POST'), '/bricks/v1/x', $posts) instanceof WP_Error, '13: is_allowed filter can deny');
$test_filters['sfx/rest_guest_is_allowed'] = [];
assert_same('The REST API index is not available to guests.', G::gate(null, $get, '/', ['callback' => [$test_server, 'get_index']])->get_error_message(), '13: index message');
assert_same('This REST route is not available to guests.', G::gate(null, $get, '/batch/v1', $posts)->get_error_message(), '13: unknown message');
$saved = $test_options['sfx_wpoptimizer_options'];
$test_options['sfx_wpoptimizer_options'] = ['rest_guest_mode' => 'allowlist']; // never saved -> defaults ∩ live
assert_same(null, G::gate(null, new WP_REST_Request('POST'), '/bricks/v1/load_query_page', $posts), '13: never-saved allowlist grants bricks/v1 during dispatch');
assert_same(true, G::gate(null, $get, '/wp/v2/posts', $posts) instanceof WP_Error, '13: never-saved allowlist blocks wp/v2');
$test_options['sfx_wpoptimizer_options'] = $saved + ['disable_wp_optimizer' => 1];
assert_same(['state' => 'open', 'method' => null], G::probe(new WP_REST_Request('GET', ['target' => 'index'])), '13: probe with master switch on -> open');
$test_options['sfx_wpoptimizer_options'] = $saved;
$test_options['sfx_wpoptimizer_options']['rest_guest_hide_index'] = 0;
assert_same(['state' => 'allowed', 'method' => null], G::probe(new WP_REST_Request('GET', ['target' => 'index'])), '13: probe index shown');
$test_options['sfx_wpoptimizer_options'] = $saved;

// 14. boot(): probe route always, gate only when enforcing.
$test_actions = []; $test_filters = [];
$test_options['sfx_wpoptimizer_options'] = ['rest_guest_mode' => 'open'];
G::boot();
assert_same(true, isset($test_actions['rest_api_init']), '14: probe registered in open mode');
assert_same(false, isset($test_filters['rest_dispatch_request']), '14: no gate in open mode');
$test_actions = []; $test_filters = [];
$test_options['sfx_wpoptimizer_options'] = $saved;
G::boot();
assert_same(PHP_INT_MAX, $test_filters['rest_dispatch_request'][0]['priority'] ?? null, '14: gate last');
assert_same(4, $test_filters['rest_dispatch_request'][0]['accepted_args'] ?? null, '14: gate gets route + handler');
$test_filters = [];

// 15. new_blocked(): live − seen − allowed (filtered map).
assert_same(['new/v1'], G::new_blocked(['bricks/v1', 'new/v1', 'old/v1', 'granted/v1'], ['old/v1'], ['bricks/v1' => 'all', 'granted/v1' => 'get']), '15: new blocked namespaces');
```

- [ ] **Step 2: Run** → FAIL (undefined method `classify`).

- [ ] **Step 3: Implement** — append to `RestGuestAccess`:

```php
    public static function enforcing(array $o): bool
    {
        return empty($o['disable_wp_optimizer']) && self::mode($o) !== 'open';
    }

    /** @return list<string> */
    public static function live_namespaces(): array
    {
        $out = [];
        foreach (rest_get_server()->get_namespaces() as $ns) {
            $ns = (string) $ns; // core keys namespaces in an array: "123" comes back as int
            if ($ns !== self::INTERNAL_NAMESPACE) {
                $out[] = $ns;
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /** @param list<string> $live @return array<string,string> */
    public static function effective_map(array $o, array $live): array
    {
        $rows = self::namespaces($o);
        if ($rows !== null) {
            return self::to_map($rows);
        }
        return array_intersect_key(self::DEFAULTS, array_flip($live));
    }

    /** @return array{kind:string, namespace:?string} */
    public static function classify($request, string $route, array $handler, ?array $route_options): array
    {
        $cb = $handler['callback'] ?? null;
        if (is_array($cb) && ($cb[0] ?? null) instanceof \WP_REST_Server) {
            if ($cb[1] === 'get_index') {
                return ['kind' => 'index', 'namespace' => null];
            }
            if ($cb[1] === 'get_namespace_index') {
                $param = $request['namespace'];
                if ($param !== null && $param !== '') {
                    // A request override must be a string (core reads it as the namespace to list).
                    return is_string($param)
                        ? ['kind' => 'discovery', 'namespace' => $param]
                        : ['kind' => 'unknown', 'namespace' => null];
                }
                $own = $route_options['namespace'] ?? '';
                return (is_string($own) || is_int($own)) && (string) $own !== ''
                    ? ['kind' => 'discovery', 'namespace' => (string) $own]
                    : ['kind' => 'unknown', 'namespace' => null];
            }
        }
        $ns = $route_options['namespace'] ?? '';
        if ((is_string($ns) || is_int($ns)) && (string) $ns !== '') {
            return ['kind' => 'namespace', 'namespace' => (string) $ns];
        }
        return ['kind' => 'unknown', 'namespace' => null];
    }

    /** @param array<string,string> $allowed */
    public static function decide(string $kind, ?string $ns, string $method, string $mode, array $allowed, bool $hide): bool
    {
        if ($mode === 'open') {
            return true;
        }
        if ($mode !== 'allowlist') {
            return false;
        }
        switch ($kind) {
            case 'index':
                return !$hide;
            case 'discovery':
                return !$hide && $ns !== null && isset($allowed[$ns]);
            case 'namespace':
                if ($ns === null || !isset($allowed[$ns])) {
                    return false;
                }
                return $allowed[$ns] === 'all' || in_array(strtoupper($method), ['GET', 'HEAD'], true);
            default:
                return false;
        }
    }

    /** rest_dispatch_request, priority PHP_INT_MAX: runs after authentication, validation and permission_callback. */
    public static function gate($result, $request, string $route, array $handler)
    {
        if (!wp_is_serving_rest_request() || get_current_user_id() > 0) {
            return $result;
        }
        $o = self::option();
        if (!self::enforcing($o)) {
            return $result;
        }
        if ($route === '/' . self::INTERNAL_NAMESPACE . '/probe') {
            return $result;
        }
        $server = rest_get_server();
        $options = method_exists($server, 'get_route_options') ? $server->get_route_options($route) : null;
        $c = self::classify($request, $route, $handler, is_array($options) ? $options : null);
        $allowed = self::allowed_map($o);
        $ok = (bool) apply_filters(
            'sfx/rest_guest_is_allowed',
            self::decide($c['kind'], $c['namespace'], (string) $request->get_method(), self::mode($o), $allowed, self::hide_index($o)),
            $request
        );
        return $ok ? $result : self::forbidden($c);
    }

    /** @return array<string,string> */
    /** The map enforcement uses (defaults resolved, filter applied) — also used by the probe and the notice. */
    public static function allowed_map(array $o): array
    {
        $map = self::effective_map($o, self::namespaces($o) === null ? self::live_namespaces() : []);
        $filtered = apply_filters('sfx/rest_guest_allowed_namespaces', $map);
        return is_array($filtered) ? $filtered : $map;
    }

    /** @param array{kind:string, namespace:?string} $c */
    private static function forbidden(array $c): \WP_Error
    {
        if ($c['kind'] === 'index') {
            $message = __('The REST API index is not available to guests.', 'sfxtheme');
        } elseif ($c['kind'] === 'unknown') {
            $message = __('This REST route is not available to guests.', 'sfxtheme');
        } else {
            /* translators: %s: REST namespace, e.g. wp/v2 */
            $message = sprintf(__('The REST namespace %s is not available to guests.', 'sfxtheme'), (string) $c['namespace']);
        }
        return new \WP_Error('rest_forbidden_guest', $message, ['status' => 401]);
    }

    public static function register_probe_route(): void
    {
        register_rest_route(self::INTERNAL_NAMESPACE, '/probe', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'probe'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** Reports the saved policy for one namespace (GET) or the index; never runs another route. */
    public static function probe($request)
    {
        $o = self::option();
        $live = self::live_namespaces();
        $is_index = $request->get_param('target') === 'index';
        $ns = $request->get_param('namespace');
        if (!$is_index && (!is_string($ns) || !in_array($ns, $live, true))) {
            return new \WP_Error('rest_no_route', __('No route was found matching the URL and request method.', 'sfxtheme'), ['status' => 404]);
        }
        if (!self::enforcing($o)) {
            return ['state' => 'open', 'method' => null];
        }
        $allowed = self::allowed_map($o);
        $ok = $is_index
            ? self::decide('index', null, 'GET', self::mode($o), $allowed, self::hide_index($o))
            : self::decide('namespace', $ns, 'GET', self::mode($o), $allowed, self::hide_index($o));
        return ['state' => $ok ? 'allowed' : 'blocked', 'method' => $ok && !$is_index ? ($allowed[$ns] ?? null) : null];
    }

    public static function remove_discovery_links(): void
    {
        if (get_current_user_id() > 0) {
            return;
        }
        $o = self::option();
        if (!self::enforcing($o)) {
            return;
        }
        if (self::mode($o) === 'closed' || self::hide_index($o)) {
            remove_action('wp_head', 'rest_output_link_wp_head', 10);
            remove_action('template_redirect', 'rest_output_link_header', 11);
        }
    }

    /** @param list<string> $live @param list<string> $seen @param array<string,string> $allowed @return list<string> */
    public static function new_blocked(array $live, array $seen, array $allowed): array
    {
        return array_values(array_filter($live, static fn($ns) => !in_array($ns, $seen, true) && !isset($allowed[$ns])));
    }

    /** Called on init (priority 1) by the WP Optimizer controller. */
    public static function boot(): void
    {
        add_action('rest_api_init', [self::class, 'register_probe_route']);
        if (!self::enforcing(self::option())) {
            return;
        }
        add_filter('rest_dispatch_request', [self::class, 'gate'], PHP_INT_MAX, 4);
        add_action('wp', [self::class, 'remove_discovery_links'], 0);
    }
```

- [ ] **Step 4: Run** → `PASS`; `./quality.sh` → PASS.
- [ ] **Step 5: Commit** `git commit -m "feat(wpoptimizer): REST guest gate, probe and discovery links"`

---

### Task 3: Settings fields and sanitizer

**Files:**
- Modify: `inc/WPOptimizer/Settings.php` (fields; `sanitize_options`)
- Modify: `inc/WPOptimizer/Controller.php` (remove `disable_rest_api_non_authenticated` method and its `CONTEXT_SENSITIVE_OPTIONS` entry; boot the class)
- Test: `tests/wpoptimizer-rest-guest-test.php` (append)

**Interfaces:**
- Consumes: Task 1/2.
- Produces: `RestGuestAccess::is_form_save(): bool`, `RestGuestAccess::sanitize_into(array $source, array $output, bool $form, array $stored): array`.

- [ ] **Step 1: Fields.** In `Settings::get_fields()` replace the `disable_rest_api_non_authenticated` entry with:

```php
            [
                'id'          => 'rest_guest_mode',
                'label'       => __('REST API access for guests', 'sfxtheme'),
                'description' => __('Open: WordPress default. Allowlist: guests may only call the namespaces ticked below. Closed: guests get no REST API at all. Logged-in users and application passwords are never affected.', 'sfxtheme'),
                'type'        => 'select',
                'options'     => [
                    'open'      => __('Open (WordPress default)', 'sfxtheme'),
                    'allowlist' => __('Allowlist', 'sfxtheme'),
                    'closed'    => __('Closed', 'sfxtheme'),
                ],
                'default'     => 'open',
                'group'       => 'security',
            ],
            [
                'id'          => 'rest_guest_hide_index',
                'label'       => __('Hide REST index for guests', 'sfxtheme'),
                'description' => __('Guests get 401 for /wp-json/ and the namespace index routes, and the REST discovery links are removed for them. In Closed mode this always applies.', 'sfxtheme'),
                'type'        => 'rest_hide_index',
                'default'     => 1,
                'group'       => 'security',
                'conditional' => ['field' => 'rest_guest_mode', 'operator' => 'in_array', 'value' => ['allowlist']],
            ],
            [
                'id'          => 'rest_guest_namespaces',
                'label'       => __('Namespaces guests may call', 'sfxtheme'),
                'description' => __('Unticked namespaces return 401 rest_forbidden_guest for guests. Namespaces that appear later stay blocked until you tick them.', 'sfxtheme'),
                'type'        => 'rest_namespaces',
                'default'     => null,
                'group'       => 'security',
                'wide'        => true,
                'conditional' => ['field' => 'rest_guest_mode', 'operator' => 'in_array', 'value' => ['allowlist']],
            ],
            [
                'id'          => 'rest_guest_seen',
                'label'       => '',
                'description' => '',
                'type'        => 'hidden_list',
                'default'     => [],
                'group'       => 'security',
            ],
```

and change `disable_rest_api`'s label/description to:

```php
                'label'       => __('Remove REST discovery links and oEmbed', 'sfxtheme'),
                'description' => __('Removes the REST and oEmbed discovery links, the oEmbed route and JSONP. It does not block REST requests — WordPress ignores that since 4.7. To restrict guests, use “REST API access for guests”.', 'sfxtheme'),
```

- [ ] **Step 2: Sanitizer.** Add to `RestGuestAccess`:

```php
    public static function is_form_save(): bool
    {
        return isset($_POST['option_page']) && $_POST['option_page'] === self::OPTION; // phpcs:ignore WordPress.Security.NonceVerification -- options.php verified the nonce
    }

    /** The raw posted form, read the same way on every sanitizer invocation (add_option can run it twice). */
    public static function raw_form(): array
    {
        $raw = isset($_POST[self::OPTION]) ? wp_unslash($_POST[self::OPTION]) : []; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- sanitized below
        return is_array($raw) ? $raw : [];
    }

    public static function form_complete(array $raw): bool
    {
        return ($raw['sfx_wpo_form_start'] ?? null) === '1' && ($raw['sfx_wpo_form_end'] ?? null) === '1';
    }

    /** Writes the four rest_guest_* keys into $output from $source. */
    public static function sanitize_into(array $source, array $output, bool $form, array $stored): array
    {
        $output['rest_guest_mode'] = self::mode($source);
        $rows = self::namespaces($source, $form);
        $output['rest_guest_namespaces'] = ($rows === null && $form) ? [] : $rows;
        $output['rest_guest_hide_index'] = self::hide_index($source) ? 1 : 0;
        if ($form) {
            $displayed = self::seen(['rest_guest_seen' => $source['rest_guest_displayed'] ?? []]);
            $output['rest_guest_seen'] = array_values(array_unique(array_merge(self::seen($stored), $displayed)));
        } else {
            $output['rest_guest_seen'] = self::seen($source);
        }
        return $output;
    }
```

In `Settings::sanitize_options($input)`, at the very top (the require must come first — `wpoptimizer-security-behavior-test.php` loads `Settings` without an autoloader):

```php
        if (!class_exists(classes\RestGuestAccess::class)) {
            require_once __DIR__ . '/classes/RestGuestAccess.php';
        }
        $input = is_array($input) ? $input : [];
        $is_form = classes\RestGuestAccess::is_form_save();
        if ($is_form) {
            $raw = classes\RestGuestAccess::raw_form();
            if (!classes\RestGuestAccess::form_complete($raw)) {
                add_settings_error(self::$OPTION_GROUP, 'sfx_wpo_truncated', __('Settings were not saved: the form was cut off by the server. Raise max_input_vars and save again.', 'sfxtheme'), 'error');
                return classes\RestGuestAccess::option();
            }
            $input = $raw; // never $input on a form save: repeat-safe
        }
```

In the field loop, skip the four new ids (`continue` for `rest_guest_*`), and after the loop — before the hide-login block — add:

```php
        $output = classes\RestGuestAccess::sanitize_into($input, $output, $is_form, classes\RestGuestAccess::option());
```

Also make `Settings::get()` and `Settings::get_all()` read the option through `classes\RestGuestAccess::option()` (require the class first, as above), keeping their field-default behaviour — the Controller constructor and the overview call `Settings::get()`, and a stored object would fatal there. Test: stored option `new stdClass()` → `Settings::get('disable_wp_optimizer')` returns the field default/null without error, `Settings::get_all()` → `[]`.

The hide-login early `return $output;` paths come after this line, so all paths include the new keys. Also replace the existing `$current_options = get_option('sfx_wpoptimizer_options', []);` with `$current_options = classes\RestGuestAccess::option();` (a stored object/scalar would otherwise fatal in the HideLogin array accesses) — test: stored option `new stdClass()` + a complete form save → no error, mode saved. Add a generic `select` branch to the loop for future selects:

```php
            } elseif ($field['type'] === 'select') {
                $value = isset($input[$id]) ? (string) $input[$id] : (string) $field['default'];
                $output[$id] = array_key_exists($value, $field['options'] ?? []) ? $value : (string) $field['default'];
```

- [ ] **Step 3: Controller.** In `inc/WPOptimizer/Controller.php`: delete `'disable_rest_api_non_authenticated',` from `CONTEXT_SENSITIVE_OPTIONS` and the private method `disable_rest_api_non_authenticated()`. In the constructor after the `RevisionLimiter` require block add:

```php
        require_once __DIR__ . '/classes/RestGuestAccess.php';
        // Unconditional: boot() always registers the probe route (it reports "open" while the
        // master switch is on) and checks enforcing() itself before installing the gate.
        add_action('init', [classes\RestGuestAccess::class, 'boot'], 1);
```

- [ ] **Step 4: Tests (append).** Load `Settings.php` in the test with the stubs it needs (`register_setting`, `sanitize_text_field` as identity-trim, `sanitize_title`, `add_settings_error` already stubbed) and HideLogin (`require_once …/classes/HideLogin.php`; it is loaded by `sanitize_options`). Cases:

```php
require_once dirname(__DIR__) . '/inc/WPOptimizer/classes/HideLogin.php';
require_once dirname(__DIR__) . '/inc/WPOptimizer/Settings.php';
use SFX\WPOptimizer\Settings;

// 13. Form save, complete, run twice (add_option path) -> same allowlist.
$test_options['sfx_wpoptimizer_options'] = [];
$_POST = ['option_page' => 'sfx_wpoptimizer_options', 'sfx_wpoptimizer_options' => [
    'sfx_wpo_form_start' => '1',
    'rest_guest_mode' => 'allowlist',
    'rest_guest_hide_index' => '1',
    'rest_guest_namespaces' => [
        ['namespace' => 'bricks/v1', 'allowed' => '1', 'method' => 'all'],
        ['namespace' => 'wp/v2', 'allowed' => '0', 'method' => 'get'],
    ],
    'rest_guest_displayed' => ['bricks/v1', 'wp/v2'],
    'block_author_query' => '1',
    'sfx_wpo_form_end' => '1',
]];
$first = Settings::sanitize_options($_POST['sfx_wpoptimizer_options']);
$second = Settings::sanitize_options($first);
assert_same([['namespace' => 'bricks/v1', 'method' => 'all']], $second['rest_guest_namespaces'], '13: repeat-safe allowlist');
assert_same('allowlist', $second['rest_guest_mode'], '13: mode');
assert_same(['bricks/v1', 'wp/v2'], $second['rest_guest_seen'], '13: seen = displayed');
assert_same(1, $second['block_author_query'], '13: other checkbox kept');

// 14. Truncated form saves return the complete stored option.
$stored = ['rest_guest_mode' => 'allowlist', 'rest_guest_namespaces' => [], 'block_author_query' => 1, 'hide_login' => 0];
foreach ([
    'cut before mode' => ['sfx_wpo_form_start' => '1'],
    'cut between mode and rows' => ['sfx_wpo_form_start' => '1', 'rest_guest_mode' => 'allowlist'],
    'cut after a row namespace' => ['sfx_wpo_form_start' => '1', 'rest_guest_mode' => 'allowlist', 'rest_guest_namespaces' => [['namespace' => 'wp/v2']]],
] as $label => $cut) {
    $test_options['sfx_wpoptimizer_options'] = $stored;
    $test_settings_errors = [];
    $_POST = ['option_page' => 'sfx_wpoptimizer_options', 'sfx_wpoptimizer_options' => $cut];
    assert_same($stored, Settings::sanitize_options($cut), "14: {$label} -> stored option");
    assert_same(['sfx_wpo_truncated'], $test_settings_errors, "14: {$label} -> error");
}

// 15. Form save without rows -> [] (never back to defaults).
$_POST = ['option_page' => 'sfx_wpoptimizer_options', 'sfx_wpoptimizer_options' => ['sfx_wpo_form_start' => '1', 'rest_guest_mode' => 'allowlist', 'sfx_wpo_form_end' => '1']];
assert_same([], Settings::sanitize_options([])['rest_guest_namespaces'], '15: empty form -> []');

// 16. Import / programmatic (no option_page): legacy migration, absent keys, scalar input, imported seen kept.
$_POST = [];
$imp = Settings::sanitize_options(['disable_rest_api_non_authenticated' => 1]);
assert_same('closed', $imp['rest_guest_mode'], '16: legacy import -> closed');
assert_same(null, $imp['rest_guest_namespaces'], '16: absent namespaces -> null');
assert_same(1, $imp['rest_guest_hide_index'], '16: absent hide -> 1');
assert_same(false, array_key_exists('disable_rest_api_non_authenticated', $imp), '16: legacy key dropped');
assert_same('open', Settings::sanitize_options('scalar')['rest_guest_mode'], '16: scalar import -> open');
assert_same(['x/v1'], Settings::sanitize_options(['rest_guest_seen' => ['x/v1']])['rest_guest_seen'], '16: imported seen kept');

// 17. Rows survive the real ImportExport recursive sanitizer.
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function sanitize_text_field($str) { $s = strip_tags((string) $str); $s = preg_replace('/%[a-f0-9]{2}/i', '', $s); return trim(preg_replace('/[\r\n\t ]+/', ' ', $s)); }
require_once dirname(__DIR__) . '/inc/ImportExport/Controller.php';
$ie = (new ReflectionClass(\SFX\ImportExport\Controller::class))->newInstanceWithoutConstructor();
$recursive = new ReflectionMethod(\SFX\ImportExport\Controller::class, 'sanitize_array_recursive');
if (PHP_VERSION_ID < 80100) {
    $recursive->setAccessible(true); // needed on 8.0 only; deprecated in 8.5
}
$rows = G::namespaces(['rest_guest_namespaces' => [['namespace' => 'bricks/v1', 'method' => 'all'], ['namespace' => 'oembed/1.0', 'method' => 'get'], ['namespace' => 'contact-form-7/v1', 'method' => 'all']]]);
$imported = $recursive->invoke($ie, ['rest_guest_namespaces' => $rows, 'rest_guest_seen' => ['bricks/v1', 'oembed/1.0']]);
assert_same($rows, $imported['rest_guest_namespaces'], '17: rows unchanged by ImportExport');
assert_same($rows, G::namespaces($imported), '17: normalised after import');
assert_same(['bricks/v1', 'oembed/1.0'], G::seen($imported), '17: seen unchanged by ImportExport');
```

Note for test 17: the stubs mirror core's `sanitize_key()`/`sanitize_text_field()` for these inputs; a namespace used as an array key would come back as `bricksv1`, which the assertion would catch.

Also add to the Task 3 tests:

- **Serialized form from the real markup** (move `row_inputs()` into Task 3 so the test can use it; it needs only `esc_attr`/`checked`/`selected` stubs): render `row_inputs(0, 'bricks/v1', true, 'all') . row_inputs(1, 'wp/v2', false, 'get')`, collect `name`/`value` pairs in document order with a regex over the `<input>`/`<select>` markup (a checkbox counts only when `checked`, a select contributes its `selected` option), join them as `urlencode(name)=urlencode(value)&…`, add the markers, `option_page` and `rest_guest_displayed[]`, `parse_str()` into `$_POST`, then run `Settings::sanitize_options()` twice → `[['namespace' => 'bricks/v1', 'method' => 'all']]` and hide-index as posted. Reversing the hidden/checkbox order in `row_inputs()` must make this fail.
- a form save over a **missing** option (`unset($test_options['sfx_wpoptimizer_options'])`) run twice persists the same allowlist; and seen union — stored seen `['old/v1']`, displayed `['bricks/v1']`, live also has `new/v1` → seen becomes `['old/v1', 'bricks/v1']` (`new/v1` stays unacknowledged).

- [ ] **Step 5:** `./quality.sh` → PASS (the existing `wpoptimizer-security-behavior-test.php` iterates checkbox fields and requires a Controller method for each; the new fields are not `checkbox`, and the removed field takes its method with it).
- [ ] **Step 6: Commit** `git commit -m "feat(wpoptimizer): REST guest settings, migration, truncation guard"`

---

### Task 4: Admin page — controls, table, warning, test button, notice

**Files:**
- Modify: `inc/WPOptimizer/AdminPage.php`
- Modify: `inc/WPOptimizer/classes/RestGuestAccess.php` (owner + hint helpers)

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces: `RestGuestAccess::owner(string $ns): string`, `RestGuestAccess::hint(string $ns): string`, `AdminPage::render_rest_notice(): void`.

- [ ] **Step 1: Normalised values.** In `render_page()`, after `$options = get_option(...)`:

```php
            $options = is_array($options) ? $options : [];
            $guest = \SFX\WPOptimizer\classes\RestGuestAccess::option();
            $options['rest_guest_mode'] = \SFX\WPOptimizer\classes\RestGuestAccess::mode($guest);
            $options['rest_guest_hide_index'] = \SFX\WPOptimizer\classes\RestGuestAccess::hide_index($guest) ? 1 : 0;
```

(the conditional rendering reads `$options[$dep_field]`, so it now sees the migrated mode).

- [ ] **Step 2: Markers.** Right after `settings_fields(...)` print `<input type="hidden" name="sfx_wpoptimizer_options[sfx_wpo_form_start]" value="1" />`; right before `submit_button()` print the `sfx_wpo_form_end` twin.

- [ ] **Step 3: Field controls.** In `render_field_control()` add:
  - `select`: `<select id name>` with `<option value selected()>` per `$field['options']`, all values `esc_attr`, labels `esc_html`.
  - `rest_hide_index`: hidden `0` then the checkbox (same markup as checkbox).
  - `hidden_list`: render nothing.
  - `rest_namespaces`: `echo self::render_rest_namespaces();` (Step 4).
  In the card loop: `$combine_with_next` additionally requires `empty($field['wide']) && empty($next_field['wide'])` (the hide-index and namespaces fields share a condition and would otherwise be merged into one 350px card); a field with `'wide' => true` gets card style `flex: 1 1 100%; max-width: none;` and, when conditional, its `_container` wrapper gets `flex: 1 1 100%` too; a field with empty `label` (the `hidden_list`) is skipped entirely. Browser check: the table spans the full content width.

- [ ] **Step 4: Table.** `private static function render_rest_namespaces(): string`:
  - `$o = RestGuestAccess::option(); $live = RestGuestAccess::live_namespaces(); $rows = RestGuestAccess::namespaces($o); $map = RestGuestAccess::effective_map($o, $live); $seen = RestGuestAccess::seen($o);`
  - Names: `array_map('strval', array_unique(array_merge($live, array_keys($map))))`, sorted with `SORT_STRING` — map keys like `"123"` come back as ints, and `owner()`/`hint()` take strings under strict types. Include a stored-only numeric namespace in the browser check (or in a rendering test) if one exists; otherwise rely on the cast.
  - The inputs of one row come from `RestGuestAccess::row_inputs(int $i, string $ns, bool $allowed, string $method): string` (pure; returns the hidden `namespace`, hidden `allowed=0`, checkbox `allowed=1` checked when `$allowed`, and the method `<select>`, in that order, all attributes `esc_attr`), so Task 3's serialized-form test exercises the real markup.
  - Per name, row index `$i`: columns namespace (`<code>` escaped), owner `RestGuestAccess::owner()`, hint `RestGuestAccess::hint()`, badges ("new" when `$rows !== null && !in_array($ns, $seen, true)`, "not present" when not live, "unsupported characters — allow via the sfx/rest_guest_allowed_namespaces filter" when `!supported()`), allowed (`hidden 0` + checkbox `1`, checked when `isset($map[$ns])`), method `<select>` `all` / `GET only` (selected from `$map[$ns]`, default `get` for `wp/v2`, else `all`). Supported rows post `rest_guest_namespaces[$i][namespace|allowed|method]`; unsupported rows render text only, no inputs. Every listed name posts `rest_guest_displayed[]` (hidden).
  - (moved) The Bricks warning is **not** rendered here — see Step 4b. `get_template() === 'bricks'` and (`mode === 'closed'` or (`allowlist` and `($map['bricks/v1'] ?? null) !== 'all'`)) and the master switch is off: `<div class="notice notice-warning inline"><p>` + escaped text from the spec.
  - Test button below: `<button type="button" class="button" id="sfx-rest-guest-test">` + `<table id="sfx-rest-guest-results">` + a hint paragraph (saved state; `sfx/rest_guest_is_allowed` is not simulated; page cache / password protection / CORS can change results).

- [ ] **Step 4b: Bricks warning in the mode card.** The table's card is hidden outside `allowlist`, so the warning is printed by the `select` control of `rest_guest_mode` (always visible), right below the select: when `get_template() === 'bricks'`, `RestGuestAccess::enforcing($o)`, and (`mode === 'closed'` or (`allowlist` and `(RestGuestAccess::allowed_map($o)['bricks/v1'] ?? null) !== 'all'`)) → `<div class="notice notice-warning inline"><p>` + `esc_html__('Bricks query loops, filters, pagination and popups will fail for visitors — they need bricks/v1 with all methods.', 'sfxtheme')`. Browser check: visible in `closed` and in `allowlist` without `bricks/v1`/with GET only, hidden in `open` and with the master switch on.

- [ ] **Step 5: Owner and hints** in `RestGuestAccess`:

```php
    public static function owner(string $ns): string
    {
        $server = rest_get_server();
        // get_routes('0') returns every route (core tests the argument for truthiness), so filter by the exact namespace.
        foreach ($server->get_routes($ns) as $route => $handlers) {
            $opts = $server->get_route_options($route);
            if (!is_array($opts) || (string) ($opts['namespace'] ?? '') !== $ns || !is_array($handlers)) {
                continue;
            }
            foreach ($handlers as $handler) {
                $cb = is_array($handler) ? ($handler['callback'] ?? null) : null;
                if (is_array($cb)) {
                    if (count($cb) !== 2 || !isset($cb[0], $cb[1]) || !is_string($cb[1]) || (!is_object($cb[0]) && !is_string($cb[0]))) {
                        continue;
                    }
                    if ($cb[0] instanceof \WP_REST_Server && $cb[1] === 'get_namespace_index') {
                        continue;
                    }
                }
                try {
                    if ($cb instanceof \Closure || (is_string($cb) && !str_contains($cb, '::'))) {
                        $ref = new \ReflectionFunction($cb);
                    } elseif (is_string($cb)) {
                        [$class, $method] = explode('::', $cb, 2); // one-argument form is deprecated in PHP 8.4+
                        $ref = new \ReflectionMethod($class, $method);
                    } elseif (is_array($cb)) {
                        $ref = new \ReflectionMethod($cb[0], $cb[1]);
                    } elseif (is_object($cb) && method_exists($cb, '__invoke')) {
                        $ref = new \ReflectionMethod($cb, '__invoke');
                    } else {
                        continue;
                    }
                } catch (\ReflectionException | \TypeError $e) {
                    continue;
                }
                $file = $ref->getFileName();
                if (!is_string($file)) {
                    continue;
                }
                return self::owner_of_file(wp_normalize_path($file));
            }
        }
        return '';
    }

    private static function owner_of_file(string $file): string
    {
        $plugins = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR));
        if (str_starts_with($file, $plugins)) {
            $folder = strtok(substr($file, strlen($plugins)), '/');
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            foreach (get_plugins() as $path => $data) {
                if (strtok($path, '/') === $folder) {
                    return (string) $data['Name'];
                }
            }
            return (string) $folder;
        }
        $themes = trailingslashit(wp_normalize_path(get_theme_root()));
        if (str_starts_with($file, $themes)) {
            $folder = (string) strtok(substr($file, strlen($themes)), '/');
            $theme = wp_get_theme($folder); // parent (bricks) and child are told apart by folder
            return $theme->exists() ? (string) $theme->get('Name') : $folder;
        }
        if (str_starts_with($file, trailingslashit(wp_normalize_path(ABSPATH . WPINC)))) {
            return __('WordPress', 'sfxtheme');
        }
        return '';
    }

    public static function hint(string $ns): string
    {
        $hints = [
            'bricks/v1'         => __('Bricks query loops, filters, pagination, popups, live search — needs all methods.', 'sfxtheme'),
            'oembed/1.0'        => __('Lets other sites embed your content.', 'sfxtheme'),
            'wp/v2'             => __('GET only stops guest writes in this namespace; endpoint permissions still apply as usual.', 'sfxtheme'),
            'contact-form-7/v1' => __('Contact Form 7 submissions.', 'sfxtheme'),
            'fluentform/v1'     => __('Fluent Forms submissions.', 'sfxtheme'),
            'burst/v1'          => __('Burst statistics fallback (normally tracked via its beacon).', 'sfxtheme'),
        ];
        if (isset($hints[$ns])) {
            return $hints[$ns];
        }
        return in_array($ns, self::ADMIN_NAMESPACES, true) ? __('Admin only — guests do not need it.', 'sfxtheme') : '';
    }
```

- [ ] **Step 5b: Owner tests** (append to `tests/wpoptimizer-rest-guest-test.php`, extending the server stub with `get_routes($ns)` returning all routes when `$ns` is falsy, like core, and fixture routes from two namespaces `other/v1` (closure defined in this test file) and `0` (a named function in a fixture file under a fake plugins dir)): `owner('0')` resolves the `0` namespace's callback, not `other/v1`'s; a malformed callback `[new stdClass(), 123]` and a missing method yield `''` without error.

- [ ] **Step 6: Script.** In the page's inline script: (a) `initializeConditionalFields` — when the dependency element is a `<select>`, evaluate with `dep.value` and listen to `change`; checkboxes unchanged. (b) Test button: config printed as `const sfxRestGuest = <?php echo wp_json_encode([...], JSON_HEX_TAG | JSON_HEX_AMP); ?>;` with `probe` (`rest_url('sfx-guest/v1/probe')`), the configured state per live namespace (`allowed`/`blocked`/`open`, method), the configured index state, and the translated labels. Run: disable button, `runId++`, for `target=index` and each namespace build `url + (url.includes('?') ? '&' : '?') + 'namespace=' + encodeURIComponent(ns)` (or `target=index`), `fetch(url, {credentials:'omit', cache:'no-store', redirect:'manual', signal})` with a 10 s `AbortController`, parse JSON on 200, else record status/`code`; on throw → "no response"; validate the JSON shape (`state` in open/allowed/blocked, `method` in get/all/null) else "inconclusive"; compare reported `state` **and**, for allowed namespaces, `method` with the configured values (configured = the filtered `allowed_map`, printed into the config) → "as configured" / "differs"; write rows with `textContent` only if `runId` is still current; `finally` re-enables the button.

- [ ] **Step 7: Notice.** Register in `AdminPage::register()`: `add_action('admin_notices', [self::class, 'render_rest_notice']);`. It returns early unless `current_user_can('manage_options')`, the screen id is `dashboard`, `plugins` or this page's hook, `RestGuestAccess::enforcing($o)` and `mode === 'allowlist'` and `namespaces($o) !== null`. New blocked = `RestGuestAccess::new_blocked(live_namespaces(), seen($o), allowed_map($o))` — the filtered map enforcement uses, so a filter-granted namespace is not announced. Prints `notice notice-warning` with the escaped list (unsupported names suffixed with the filter hint) and a link `admin_url('admin.php?page=' . self::$menu_slug)`.

- [ ] **Step 8: Verify in the browser** (local site): the page renders the mode select with the migrated value; switching to Allowlist shows table and hide-index; Open/Closed hide the table; saving keeps ticks; unticking `bricks/v1` shows the warning; the test button fills the results table; the notice is not exercised in the browser (it would need a new plugin or a fixture file outside a guaranteed teardown); its logic is `new_blocked()` (Task 2 test 15) and the Task 7 harness asserts it in-process. `./quality.sh` → PASS.
- [ ] **Step 9: Commit** `git commit -m "feat(wpoptimizer): REST guest admin UI, notice and guest test"`

---

### Task 5: Theme settings overview

**Files:** Modify `inc/ThemeSettingsOverview/OverviewProvider.php`; test in `tests/wpoptimizer-rest-guest-test.php` only if the provider is loadable with the existing stubs — otherwise browser check.

- [ ] **Step 1:** In `build_wp_optimizer_group()`, after collecting checkbox children for the `security` group, append one child (and count it in `$total`, and in `$enabled` when active):

```php
            if ($group_key === 'security') {
                $o = \SFX\WPOptimizer\classes\RestGuestAccess::option();
                $mode = \SFX\WPOptimizer\classes\RestGuestAccess::mode($o);
                $active = \SFX\WPOptimizer\classes\RestGuestAccess::enforcing($o);
                $labels = ['open' => __('Open', 'sfxtheme'), 'allowlist' => __('Allowlist', 'sfxtheme'), 'closed' => __('Closed', 'sfxtheme')];
                $detail = $labels[$mode];
                if (!empty($o['disable_wp_optimizer'])) {
                    $detail .= ' — ' . __('inactive: WP Optimizer disabled', 'sfxtheme');
                }
                $children[] = ['id' => 'rest_guest_mode', 'label' => __('REST API for guests', 'sfxtheme'), 'status' => $active ? 'active' : 'inactive', 'detail' => $detail];
                $total++;
                if ($active) { $enabled++; }
            }
```

(insert before `$active_count += $enabled;`; ensure `RestGuestAccess.php` is required — the provider may run where the Controller is not constructed: `require_once` it at the top of the method when the class does not exist.)

- [ ] **Step 2:** Browser check on the dashboard widget: Security shows the new item with the mode. `./quality.sh` → PASS.
- [ ] **Step 3: Commit** `git commit -m "feat(theme-settings-overview): REST guest mode item"`

---

### Task 6: Docs, translations, changelog

**Files:** `README.md`, `CHANGELOG.md`, `languages/de_DE.po`, `languages/de_DE.mo`.

- [ ] **Step 1: README** — under Security, a short "REST API access for guests" bullet, plus notes on Import/Export: with **merge**, an empty imported allowlist or seen list does not clear the existing one, a non-empty one replaces it whole (rows are not combined), and an existing mode wins over an imported legacy "REST for logged-in users only" flag; use **replace** for an exact copy; REST response caches answering before dispatch must exclude REST (varying by login is not enough); guests can tell 401 (blocked) from 404 (missing) and read `OPTIONS` metadata of a known route; "Remove REST discovery links and oEmbed" never blocked REST.
- [ ] **Step 2: CHANGELOG** — entry under the next version with the spec's text. (`release.sh` writes the version header; add under an "Unreleased" heading if that is the file's convention — check its first entries.)
- [ ] **Step 3: Translations** — add every new `sfxtheme` string with German `msgstr` to `languages/de_DE.po` (update the two changed `disable_rest_api` strings; remove the obsolete "Disable REST API for Non-Authenticated Users" entry), then `msgfmt -o languages/de_DE.mo languages/de_DE.po`. Verify: `msgfmt --check languages/de_DE.po`.
- [ ] **Step 4: Commit** `git commit -m "docs(wpoptimizer): REST guest access README, changelog, German strings"`

---

### Task 7: Live check harness

**Files:** Create `tests/support/rest-guest-live-check.php`.

- [ ] **Step 1 (teardown contract):** restoration must not go through the WP Optimizer sanitizer — it is registered when the Controller is constructed (also in this CLI boot) and would rewrite a legacy snapshot into the new schema and drop non-field keys such as `disable_wp_optimizer`. The shutdown function therefore first calls `remove_all_filters('sanitize_option_' . $name)` for every option it restores, then for each option: originally absent → `delete_option`, else `update_option($name, $snapshot)`; then re-reads each with `get_option` and prints `RESTORE MISMATCH <name>` (exit code 1) if it differs from the snapshot. Snapshots taken before any change (value **and** whether it exists): `sfx_general_options`, `sfx_wpoptimizer_options`, the Password Protection option, `default_comment_status`, `default_ping_status` (WP Optimizer's `disable_comments` handler writes both on any request once the module is on), `sfx_wpoptimizer_migrated_disable_version_numbers_off` (the Controller constructor creates it), `get_site_option('using_application_passwords', null)` (absent vs value) and `metadata_exists('user', 1, '_application_passwords')` plus its value. The application password is deleted by its UUID only (`WP_Application_Passwords::delete_application_password(1, $uuid)`); then the user meta is restored to its snapshot (deleted if it did not exist) and `using_application_passwords` to its snapshot (deleted if absent).

- [ ] **Step 1b:** Script that boots WordPress from the site root (`require` of `wp-load.php` resolved from `__DIR__ . '/../../../../../wp-load.php'`, fatal if missing), as user 1 for writes. **Before any fixture**: `register_shutdown_function` restoring `sfx_general_options`, `sfx_wpoptimizer_options`, the Password Protection option, deleting the application password it created and any fixture post — every snapshot taken first, the shutdown function declared before the first change (AGENTS.md "verification harness" rule). Then: enable the module, master switch off, `disable_application_passwords` 0, `disable_rest_api` 0, `disable_embed` 0, Password Protection off; ensure a published post (create a fixture when none); set policy `allowlist`, hide-index 1, rows `bricks/v1` all, `oembed/1.0` get; create an application password for user 1.
- [ ] **Step 2:** Fresh HTTP requests **outside WordPress' HTTP API** — `curl` via `exec()` (`curl -sk -o <tmp> -w '%{http_code}'`, response body read from the temp file, temp files registered for removal in the same teardown): the harness process booted WordPress with the site's own WP Optimizer settings, so a `pre_http_request` filter such as `block_external_http` would block `wp_remote_get` for the whole run, and changing the option does not unhook it. Assertions (exit non-zero on the first mismatch, printing what it got):
  - route check in a **fresh process** after the fixture options are saved (this process booted before `disable_embed`/`disable_rest_api` were switched off, and their hooks already removed the oEmbed route here): `exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require "<wp-load>"; echo "OEMBED_ROUTES:" . count(rest_get_server()->get_routes("oembed/1.0"));'), $out, $code)`; require `$code === 0` and a line matching `/^OEMBED_ROUTES:([1-9]\d*)$/` — anything else (a fatal's output included) fails the precondition;
  - guest `GET /wp-json/oembed/1.0/embed?url=<permalink>` → 200;
  - guest `GET /wp-json/wp/v2/posts` → 401, code `rest_forbidden_guest`;
  - guest `GET /wp-json/` → 401;
  - guest probe `bricks/v1` → `allowed`, `wp/v2` → `blocked`, `target=index` → `blocked`;
  - `GET /wp-json/wp/v2/posts` with `Authorization: Basic base64(user:apppass)` → 200;
  - notice logic in-process: with the fixture policy saved and `rest_guest_seen` set to `['bricks/v1', 'oembed/1.0']` (restored by the teardown), `RestGuestAccess::new_blocked(live_namespaces(), seen($o), allowed_map($o))` contains `wp/v2`;
  - set mode `open`; guest `GET /wp-json/wp/v2/posts` → 200.
- [ ] **Step 3:** Run it: `/Applications/MAMP/bin/php/php8.5.2/bin/php tests/support/rest-guest-live-check.php` → all lines OK; afterwards verify the options equal their snapshots (`get_option` diff printed by the script) and no application password named `sfx-rest-guest-live-check` remains.
- [ ] **Step 4: Commit** `git commit -m "test(wpoptimizer): REST guest live check harness"`

---

## Self-review notes

- Spec coverage: storage/normalisers (T1), classification/decision/gate/probe/links (T2), fields/sanitizer/migration/truncation/repeat-safety/controller (T3), admin values/table/owner/visibility/warning/test/notice (T4), overview (T5), README/changelog/i18n (T6), live check (T7).
- `rest_guest_hide_index` is type `rest_hide_index`, not `checkbox`: the existing test requires a Controller method per checkbox field and the overview counts checkboxes — both rightly skip it.
- The mode select is not a checkbox either, so `handle_options()` never looks for a `rest_guest_mode()` method.
