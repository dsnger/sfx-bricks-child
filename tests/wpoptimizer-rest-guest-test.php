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
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function sanitize_text_field($str) { $s = strip_tags((string) $str); $s = preg_replace('/%[a-f0-9]{2}/i', '', $s); return trim(preg_replace('/[\r\n\t ]+/', ' ', $s)); }
function sanitize_title($title) { return trim(preg_replace('/[^a-z0-9_\-]+/', '-', strtolower((string) $title)), '-'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = 'default') { return esc_html($s); }
function get_page_by_path($path) { return null; }

class WP_Error
{
    public function __construct(public string $code = '', public string $message = '', public $data = null) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data() { return $this->data; }
}

require_once dirname(__DIR__) . '/inc/WPOptimizer/classes/RestGuestAccess.php';

require_once dirname(__DIR__) . '/inc/WPOptimizer/classes/HideLogin.php';
require_once dirname(__DIR__) . '/inc/WPOptimizer/Settings.php';

use SFX\WPOptimizer\classes\RestGuestAccess as G;
use SFX\WPOptimizer\Settings;

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

// 16. Form save, complete, run twice (add_option path) -> same allowlist.
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
assert_same([['namespace' => 'bricks/v1', 'method' => 'all']], $second['rest_guest_namespaces'], '16: repeat-safe allowlist');
assert_same('allowlist', $second['rest_guest_mode'], '16: mode');
assert_same(['bricks/v1', 'wp/v2'], $second['rest_guest_seen'], '16: seen = displayed');
assert_same(1, $second['block_author_query'], '16: other checkbox kept');

// 17. Truncated form saves return the complete stored option.
$stored = ['rest_guest_mode' => 'allowlist', 'rest_guest_namespaces' => [], 'block_author_query' => 1, 'hide_login' => 0];
foreach ([
    'cut before mode' => ['sfx_wpo_form_start' => '1'],
    'cut between mode and rows' => ['sfx_wpo_form_start' => '1', 'rest_guest_mode' => 'allowlist'],
    'cut after a row namespace' => ['sfx_wpo_form_start' => '1', 'rest_guest_mode' => 'allowlist', 'rest_guest_namespaces' => [['namespace' => 'wp/v2']]],
] as $label => $cut) {
    $test_options['sfx_wpoptimizer_options'] = $stored;
    $test_settings_errors = [];
    $_POST = ['option_page' => 'sfx_wpoptimizer_options', 'sfx_wpoptimizer_options' => $cut];
    assert_same($stored, Settings::sanitize_options($cut), "17: {$label} -> stored option");
    assert_same(['sfx_wpo_truncated'], $test_settings_errors, "17: {$label} -> error");
}

// 18. Form save without rows -> [] (never back to defaults).
$test_options['sfx_wpoptimizer_options'] = [];
$_POST = ['option_page' => 'sfx_wpoptimizer_options', 'sfx_wpoptimizer_options' => ['sfx_wpo_form_start' => '1', 'rest_guest_mode' => 'allowlist', 'sfx_wpo_form_end' => '1']];
assert_same([], Settings::sanitize_options([])['rest_guest_namespaces'], '18: empty form -> []');

// 19. Import / programmatic (no option_page): legacy migration, absent keys, scalar input, imported seen kept.
$_POST = [];
$imp = Settings::sanitize_options(['disable_rest_api_non_authenticated' => 1]);
assert_same('closed', $imp['rest_guest_mode'], '19: legacy import -> closed');
assert_same(null, $imp['rest_guest_namespaces'], '19: absent namespaces -> null');
assert_same(1, $imp['rest_guest_hide_index'], '19: absent hide -> 1');
assert_same(false, array_key_exists('disable_rest_api_non_authenticated', $imp), '19: legacy key dropped');
assert_same('open', Settings::sanitize_options('scalar')['rest_guest_mode'], '19: scalar import -> open');
assert_same(['x/v1'], Settings::sanitize_options(['rest_guest_seen' => ['x/v1']])['rest_guest_seen'], '19: imported seen kept');

// 20. Rows survive the real ImportExport recursive sanitizer.
require_once dirname(__DIR__) . '/inc/ImportExport/Controller.php';
$ie = (new ReflectionClass(\SFX\ImportExport\Controller::class))->newInstanceWithoutConstructor();
$recursive = new ReflectionMethod(\SFX\ImportExport\Controller::class, 'sanitize_array_recursive');
if (PHP_VERSION_ID < 80100) {
    $recursive->setAccessible(true); // needed on 8.0 only; deprecated in 8.5
}
$rows = G::namespaces(['rest_guest_namespaces' => [['namespace' => 'bricks/v1', 'method' => 'all'], ['namespace' => 'oembed/1.0', 'method' => 'get'], ['namespace' => 'contact-form-7/v1', 'method' => 'all']]]);
$imported = $recursive->invoke($ie, ['rest_guest_namespaces' => $rows, 'rest_guest_seen' => ['bricks/v1', 'oembed/1.0']]);
assert_same($rows, $imported['rest_guest_namespaces'], '20: rows unchanged by ImportExport');
assert_same($rows, G::namespaces($imported), '20: normalised after import');
assert_same(['bricks/v1', 'oembed/1.0'], G::seen($imported), '20: seen unchanged by ImportExport');

// 21. Serialized form from the real markup, run twice.
$html = G::row_inputs(0, 'bricks/v1', true, 'all') . G::row_inputs(1, 'wp/v2', false, 'get');
$pairs = [];
preg_match_all('#<input\b[^>]*>|<select\b[^>]*>.*?</select>#s', $html, $tags);
$attr = static function (string $tag, string $name): ?string {
    return preg_match('/\s' . $name . '="([^"]*)"/', $tag, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
};
foreach ($tags[0] as $tag) {
    if (strpos($tag, '<select') === 0) {
        preg_match('#<select\b[^>]*>#', $tag, $open);
        preg_match_all('#<option\b[^>]*>#', $tag, $opts);
        foreach ($opts[0] as $o) {
            if (preg_match('/\sselected\b/', $o)) {
                $pairs[] = [$attr($open[0], 'name'), $attr($o, 'value')];
            }
        }
        continue;
    }
    if ($attr($tag, 'type') === 'checkbox' && !preg_match('/\schecked\b/', $tag)) {
        continue;
    }
    $pairs[] = [$attr($tag, 'name'), $attr($tag, 'value')];
}
$query = implode('&', array_map(static fn($p) => urlencode($p[0]) . '=' . urlencode($p[1]), $pairs));
$query = 'option_page=sfx_wpoptimizer_options&' . urlencode('sfx_wpoptimizer_options[sfx_wpo_form_start]') . '=1&'
    . urlencode('sfx_wpoptimizer_options[rest_guest_mode]') . '=allowlist&'
    . urlencode('sfx_wpoptimizer_options[rest_guest_hide_index]') . '=0&'
    . $query . '&'
    . urlencode('sfx_wpoptimizer_options[rest_guest_displayed][]') . '=bricks%2Fv1&'
    . urlencode('sfx_wpoptimizer_options[rest_guest_displayed][]') . '=wp%2Fv2&'
    . urlencode('sfx_wpoptimizer_options[sfx_wpo_form_end]') . '=1';
parse_str($query, $_POST);
$test_options['sfx_wpoptimizer_options'] = [];
$one = Settings::sanitize_options($_POST['sfx_wpoptimizer_options']);
$two = Settings::sanitize_options($one);
assert_same([['namespace' => 'bricks/v1', 'method' => 'all']], $two['rest_guest_namespaces'], '21: serialized markup -> allowlist');
assert_same(0, $two['rest_guest_hide_index'], '21: hide-index as posted');

// 22. Form save over a missing option, twice; seen union leaves new namespaces unacknowledged.
unset($test_options['sfx_wpoptimizer_options']);
$a = Settings::sanitize_options($_POST['sfx_wpoptimizer_options']);
$b = Settings::sanitize_options($a);
assert_same([['namespace' => 'bricks/v1', 'method' => 'all']], $b['rest_guest_namespaces'], '22: missing option, repeat-safe');
$test_options['sfx_wpoptimizer_options'] = ['rest_guest_seen' => ['old/v1']];
$_POST = ['option_page' => 'sfx_wpoptimizer_options', 'sfx_wpoptimizer_options' => ['sfx_wpo_form_start' => '1', 'rest_guest_mode' => 'allowlist', 'rest_guest_displayed' => ['bricks/v1'], 'sfx_wpo_form_end' => '1']];
assert_same(['old/v1', 'bricks/v1'], Settings::sanitize_options([])['rest_guest_seen'], '22: seen = stored + displayed');

// 23. A stored object/scalar option never fatals Settings.
$test_options['sfx_wpoptimizer_options'] = new stdClass();
assert_same(null, Settings::get('no_such_option'), '23: get over object option');
assert_same([], Settings::get_all(), '23: get_all over object option');
$_POST = ['option_page' => 'sfx_wpoptimizer_options', 'sfx_wpoptimizer_options' => ['sfx_wpo_form_start' => '1', 'rest_guest_mode' => 'closed', 'sfx_wpo_form_end' => '1']];
assert_same('closed', Settings::sanitize_options([])['rest_guest_mode'], '23: form save over object option');
$_POST = [];

// 24. Controller dropped the legacy method; the field is gone from Settings.
assert_same(false, strpos(file_get_contents(dirname(__DIR__) . '/inc/WPOptimizer/Controller.php'), 'disable_rest_api_non_authenticated') !== false, '24: legacy name gone from Controller');
$ids = array_column(Settings::get_fields(), 'id');
assert_same(true, in_array('rest_guest_mode', $ids, true) && !in_array('disable_rest_api_non_authenticated', $ids, true), '24: fields');

echo "wpoptimizer-rest-guest-test: PASS\n";
