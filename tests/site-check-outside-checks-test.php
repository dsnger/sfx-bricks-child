<?php

declare(strict_types=1);

/**
 * SiteCheck outside checks: targets, browser observations → facts, Loopback,
 * grading of every B row and the outside half of the S+B rows, and the two
 * AJAX endpoints. Spec: docs/superpowers/specs/2026-10-10-site-check-design.md,
 * "Locations", "Results" (rules 1–9), "Check catalogue", "Manual run".
 *
 * No network: wp_remote_get/post are stubs that answer from a route table and
 * record every request. Filesystem fixtures live in one temporary tree,
 * removed by the single teardown declared below before the first fixture.
 */

$tmp = sys_get_temp_dir() . '/sfx-site-check-outside-' . bin2hex(random_bytes(6));

function remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    @chmod($path, 0700);
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            remove_tree($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

// One teardown, declared before the first fixture is created.
register_shutdown_function(static function () use ($tmp): void {
    remove_tree($tmp);
});

if (!mkdir($tmp, 0700)) {
    fwrite(STDERR, "error: cannot create {$tmp}\n");
    exit(2);
}
$tmp = realpath($tmp);
if ($tmp === false || strpos($tmp, realpath(sys_get_temp_dir())) !== 0) {
    fwrite(STDERR, "error: temp dir is not under the system temp dir\n");
    exit(2);
}

foreach (['/site/wp-content/plugins', '/site/wp-content/uploads/2026/09', '/site/wp-content/uploads/2026/10', '/site/wp-content/private', '/site/wp-includes'] as $dir) {
    mkdir($tmp . $dir, 0700, true);
}
file_put_contents($tmp . '/site/wp-config.php', "<?php\n");

define('ABSPATH', $tmp . '/site/');
define('WP_CONTENT_DIR', $tmp . '/site/wp-content');
define('WP_PLUGIN_DIR', $tmp . '/site/wp-content/plugins');
// A custom debug log path: part of target discovery.
define('WP_DEBUG_LOG', $tmp . '/site/wp-content/private/custom.log');

// ---------------------------------------------------------------- stubs

function stub_reset(array $overrides = []): void
{
    $GLOBALS['stub'] = array_replace([
        'home'         => 'https://ex.test',
        'siteurl'      => 'https://ex.test',
        'options'      => ['home' => 'https://ex.test', 'siteurl' => 'https://ex.test'],
        'users'        => [],
        'access'       => true,
        'nonce'        => true,
        'routes'       => [],
        'requests'     => [],
    ], $overrides);
}
stub_reset();

// Test-only: declares the theme's access gate in its own namespace from this one-namespace file; fixed source, no input.
eval('namespace SFX; class AccessControl { public static function can_access_theme_settings(): bool { return !empty($GLOBALS["stub"]["access"]); } }');

function __($text, $domain = null)
{
    return $text;
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

function wp_normalize_path($path)
{
    $path = str_replace('\\', '/', $path);
    return preg_replace('|(?<=.)/+|', '/', $path);
}

function trailingslashit($value)
{
    return untrailingslashit($value) . '/';
}

function untrailingslashit($value)
{
    return rtrim($value, '/\\');
}

function join_url(string $base, string $path): string
{
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function home_url($path = '')
{
    return join_url($GLOBALS['stub']['home'], $path);
}

function site_url($path = '')
{
    return join_url($GLOBALS['stub']['siteurl'], $path);
}

function content_url($path = '')
{
    return 'https://ex.test/wp-content';
}

function plugins_url($path = '', $plugin = '')
{
    return 'https://ex.test/wp-content/plugins';
}

function rest_url($path = '')
{
    return 'https://ex.test/wp-json/' . ltrim($path, '/');
}

function get_feed_link($feed = '')
{
    return 'https://ex.test/feed/';
}

function get_home_path()
{
    return ABSPATH;
}

function wp_get_upload_dir()
{
    return $GLOBALS['stub']['upload'] ?? ['basedir' => WP_CONTENT_DIR . '/uploads', 'baseurl' => 'https://ex.test/wp-content/uploads', 'error' => false];
}

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['stub']['options']) ? $GLOBALS['stub']['options'][$name] : $default;
}

function wp_salt($scheme = 'auth')
{
    return 'test-salt';
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

function current_user_can($cap)
{
    return !empty($GLOBALS['stub']['access']);
}

function check_ajax_referer($action = -1, $query_arg = false, $stop = true)
{
    return !empty($GLOBALS['stub']['nonce']) && $action === 'sfx_site_check' && $query_arg === '_ajax_nonce' && $stop === false ? 1 : false;
}

final class WP_Error
{
    private $message;

    public function __construct($code = '', $message = '')
    {
        $this->message = $message;
    }

    public function get_error_message()
    {
        return $this->message;
    }
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

function http_answer(string $method, string $url, array $args)
{
    $GLOBALS['stub']['requests'][] = ['method' => $method, 'url' => $url, 'args' => $args];
    if (isset($GLOBALS['stub']['on_request'])) {
        ($GLOBALS['stub']['on_request'])();
    }
    $route = $GLOBALS['stub']['routes'][$method . ' ' . $url] ?? $GLOBALS['stub']['routes'][$url] ?? null;
    if ($route instanceof WP_Error) {
        return $route;
    }
    $route = array_replace(['status' => 404, 'headers' => [], 'body' => 'Not found'], $route ?? []);
    return ['response' => ['code' => $route['status']], 'headers' => array_change_key_case($route['headers']), 'body' => $route['body']];
}

function wp_remote_get($url, $args = [])
{
    return http_answer('GET', $url, $args);
}

function wp_remote_post($url, $args = [])
{
    return http_answer('POST', $url, $args);
}

function wp_remote_retrieve_response_code($r)
{
    return $r['response']['code'];
}

function wp_remote_retrieve_header($r, $name)
{
    return $r['headers'][strtolower($name)] ?? '';
}

function wp_remote_retrieve_body($r)
{
    return $r['body'];
}

function get_user_by($field, $value)
{
    foreach ($GLOBALS['stub']['users'] as $user) {
        // Like MySQL's default collation: case-insensitive.
        if ($field === 'login' && strtolower($user->user_login) === strtolower((string) $value)) {
            return $user;
        }
    }
    return false;
}

function get_users($args = [])
{
    return $GLOBALS['stub']['users'];
}

function user(int $id, string $login, string $display): object
{
    return (object) ['ID' => $id, 'user_login' => $login, 'display_name' => $display];
}

function get_post_types($args = [], $output = 'names')
{
    $o = static fn(string $name, bool $public, $slug) => (object) ['name' => $name, 'public' => $public, 'rewrite' => $slug === false ? false : ['slug' => $slug]];
    return [
        'post' => $o('post', true, false),
        'page' => $o('page', true, false),
        'attachment' => $o('attachment', true, false),
        'bricks_template' => $o('bricks_template', true, 'template'),
        'secret_type' => $o('secret_type', false, 'secret'),
    ];
}

function get_taxonomies($args = [], $output = 'names')
{
    return ['category' => (object) ['name' => 'category', 'public' => true, 'rewrite' => ['slug' => 'category']]];
}

foreach (['Status', 'Evidence', 'Finding', 'Locations', 'Options', 'RunContext', 'Catalogue', 'Robots', 'Settings', 'Access', 'OutsideEndpoints', 'Fetch', 'Observations', 'Probe', 'Runs'] as $class) {
    require_once __DIR__ . '/../inc/SiteCheck/' . $class . '.php';
}
foreach (['ServerChecks', 'FileChecks', 'ConfigChecks', 'AccountChecks', 'CleanupChecks', 'OutsideChecks', 'OutsideFiles', 'HeaderChecks', 'CrawlChecks', 'AccountOutside'] as $class) {
    require_once __DIR__ . '/../inc/SiteCheck/Checks/' . $class . '.php';
}

use SFX\SiteCheck\Catalogue;
use SFX\SiteCheck\Checks\AccountOutside;
use SFX\SiteCheck\Checks\FileChecks;
use SFX\SiteCheck\Checks\OutsideChecks;
use SFX\SiteCheck\Evidence;
use SFX\SiteCheck\Fetch;
use SFX\SiteCheck\OutsideEndpoints;
use SFX\SiteCheck\RunContext;
use SFX\SiteCheck\Status;

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function ctx(string $profile = 'live', array $paths = [], array $allow = []): RunContext
{
    return new RunContext('run1', $profile, $paths, $allow, '', false);
}

function statuses(array $result): array
{
    return array_column($result['findings'], 'status', 'id');
}

function put(string $path, string $content): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, $content);
}

/** One browser observation in the shape site-check.js posts. */
function obs(string $url, int $status, string $body = '', array $extra = []): array
{
    return array_replace([
        'url' => $url, 'status' => $status, 'type' => 'basic', 'headers' => [], 'body' => $body,
        'head_hex' => bin2hex(substr($body, 0, 512)), 'truncated' => false, 'redirect' => false, 'error' => '',
    ], $extra);
}

/**
 * What the browser would send for a plan: both variants per target and the
 * comparison. $answer(url) returns [status, body, extra].
 */
function browse(array $plan, callable $answer, array $comparison = [404, 'Not found']): array
{
    $list = [];
    foreach ($plan['targets'] as $t) {
        [$status, $body, $extra] = array_pad($answer($t['url']), 3, []);
        $list[] = obs($t['url'] . (strpos($t['url'], '?') === false ? '?' : '&') . 'sfxcb=abc123', $status, $body, $extra);
        $list[] = obs($t['url'], $status, $body, $extra);
    }
    if ($plan['comparison'] !== null) {
        $list[] = obs($plan['comparison'], $comparison[0], $comparison[1]);
    }
    return $list;
}

function run_browser(string $id, RunContext $c, callable $answer, array $comparison = [404, 'Not found']): array
{
    $plan = OutsideChecks::targets($id, $c);
    $facts = OutsideChecks::observe($id, $c, browse($plan, $answer, $comparison));
    return [$facts, Catalogue::grade($id, $facts, $c), $plan];
}

function run_loopback(string $id, RunContext $c): array
{
    $facts = OutsideChecks::observe($id, $c, []);
    return [$facts, Catalogue::grade($id, $facts, $c)];
}

/** Rule 6 as the test sees it: no PHP-like name anywhere in the fully decoded path, except the two allowed scripts. */
function php_request(string $url): bool
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    for ($i = 0; $i < 5; $i++) {
        $path = rawurldecode($path);
    }
    if (preg_match('/\.(?:php|pht|phar)/i', $path) !== 1) {
        return false;
    }
    return !in_array($path, ['/xmlrpc.php', '/wp-admin/install.php'], true);
}

function requested_urls(): array
{
    return array_column($GLOBALS['stub']['requests'], 'url');
}

function issue_run(string $run, array $fields = []): void
{
    $GLOBALS['stub']['options']['sfx_site_check_manual'] = ['issued' => array_replace([
        'run' => $run, 'profile' => 'live', 'indexability_paths' => [], 'sitemap_allow' => [], 'fallback_theme' => '', 'probe' => false,
    ], $fields)];
}

function post(array $fields): void
{
    $_POST = $fields;
}

$urlset = static fn(array $locs): string => '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
    . implode('', array_map(static fn($l) => '<url><loc>' . htmlspecialchars($l, ENT_XML1) . '</loc></url>', $locs)) . '</urlset>';
$index = static fn(array $locs): string => '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
    . implode('', array_map(static fn($l) => '<sitemap><loc>' . htmlspecialchars($l, ENT_XML1) . '</loc></sitemap>', $locs)) . '</sitemapindex>';

$log_line = "[10-Oct-2026 10:00:00 UTC] PHP Warning: Undefined variable \$x in /var/www/a.php on line 3\n";

// ---------------------------------------------------------------- catalogue routing

foreach (['dir_listing', 'security_headers', 'xmlrpc', 'usernames_public', 'robots_txt', 'indexability', 'sitemap', 'sitemap_entries', 'version_leaks'] as $id) {
    assert_true(OutsideChecks::grades($id), "{$id}: graded by the outside checks");
}
assert_same('S+B', Catalogue::all()['public_files']['how'], 'public_files is S+B (Task 11)');
foreach (['logs_public', 'backups_public', 'vcs_env', 'https', 'public_files'] as $id) {
    assert_true(OutsideChecks::handles($id) && !OutsideChecks::grades($id), "{$id}: outside half here, graded by its server family");
}
assert_true(!OutsideChecks::handles('debug_display'), 'a server check is not an outside check');

// ---------------------------------------------------------------- mixed S+B: logs_public, integrated through the endpoints

// Permission fixtures cannot be staged as root (root reads them anyway); those cases are skipped then.
$as_root = function_exists('posix_geteuid') && posix_geteuid() === 0;
if ($as_root) {
    echo "skip: running as root, unreadable files and folders cannot be staged\n";
}

put(WP_CONTENT_DIR . '/debug.log', 'old log');
chmod(WP_CONTENT_DIR . '/private', 0000);
issue_run('runA');
post(['run' => 'runA', 'check' => 'logs_public', '_ajax_nonce' => 'n']);
[$code, $body] = OutsideEndpoints::targets_request();
assert_same(200, $code, 'targets: 200');
$plan = $body['data'];
$urls = array_column($plan['targets'], 'url');
assert_true(in_array('https://ex.test/wp-content/debug.log', $urls, true), 'default debug.log is a target');
if ($as_root) {
    assert_true(in_array('https://ex.test/wp-content/private/custom.log', $urls, true), 'custom WP_DEBUG_LOG path is a target');
} else {
    // Gate B pass 2: below an unreadable folder the path cannot be verified (rule 6) → named, not requested.
    assert_true(!in_array('https://ex.test/wp-content/private/custom.log', $urls, true), 'custom log below an unreadable folder is not requested');
    assert_true(in_array('/wp-content/private/custom.log', array_column($plan['skipped'], 'target'), true), 'it is listed as skipped');
}
assert_true(in_array('https://ex.test/error_log', $urls, true), 'error_log in the root is a target');
assert_true(strpos((string) $plan['comparison'], 'https://ex.test/sfx-site-check-missing-') === 0, 'comparison URL under the fixed prefix (rule 2)');
assert_same($plan['comparison'], OutsideChecks::comparison_url('logs_public', new RunContext('runA', 'live', [], [], '', false)), 'comparison URL is the one issued for this run and check');
assert_true($plan['comparison'] !== OutsideChecks::comparison_url('logs_public', new RunContext('runB', 'live', [], [], '', false)), 'another run gets another comparison URL');

$answer404 = static fn(string $url): array => [404, '<html>Not found</html>'];
post(['run' => 'runA', 'check' => 'logs_public', '_ajax_nonce' => 'n', 'observations' => addslashes(json_encode(browse($plan, $answer404)))]);
[$code, $body] = OutsideEndpoints::observe_request();
chmod(WP_CONTENT_DIR . '/private', 0700);
assert_same(200, $code, 'observe: 200');
$s = statuses($body['data']['graded']);
assert_same(Status::YELLOW, $s['logs_public:/wp-content/debug.log'], 'present + blocked → Gelb');
assert_same(Status::GREEN, $s['logs_public:/error_log'], 'absent + blocked → Grün');
if (!$as_root) {
    assert_same(Status::UNKNOWN, $s['logs_public:/wp-content/private/custom.log'], 'unknown (unreadable folder) + blocked → Nicht prüfbar');
}
$saved = json_decode(json_encode($body['data']['facts']), true);
assert_same($body['data']['graded'], Catalogue::grade('logs_public', $saved, ctx()), 'the saved facts re-grade to the same result');

put(ABSPATH . 'error_log', $log_line);
post(['run' => 'runA', 'check' => 'logs_public', '_ajax_nonce' => 'n']);
$plan = OutsideEndpoints::targets_request()[1]['data'];
$answer = static fn(string $url): array => $url === 'https://ex.test/error_log' ? [200, $log_line] : [404, 'nf'];
post(['run' => 'runA', 'check' => 'logs_public', '_ajax_nonce' => 'n', 'observations' => addslashes(json_encode(browse($plan, $answer)))]);
[$code, $body] = OutsideEndpoints::observe_request();
assert_same(Status::RED, statuses($body['data']['graded'])['logs_public:/error_log'], 'public log signature → Rot');
assert_same(Status::RED, $body['data']['graded']['status'], 'the check is Rot');
$by = array_column($body['data']['facts']['targets'], null, 'target');
assert_same('log', $by['/error_log']['outside_signature'], 'signature group recorded');
assert_true(strpos(json_encode($body['data']), 'Undefined variable') === false, 'no log text in graded or facts (rule 7)');
assert_same($body['data']['graded'], Catalogue::grade('logs_public', json_decode(json_encode($body['data']['facts']), true), ctx()), 'saved run shows the same');
unlink(ABSPATH . 'error_log');

// Soft-404 site: an absent log answering 200 like the comparison → Nicht prüfbar, never Rot or Grün.
[, $r] = run_browser('logs_public', ctx(), static fn($u) => [200, '<html>Home</html>'], [200, '<html>Home</html>']);
assert_same(Status::UNKNOWN, statuses($r)['logs_public:/error_log'], 'soft-404: absent + 200 → Nicht prüfbar');

// Cache-buster vs plain: a cached copy served only without the buster still counts.
[, $r] = run_browser('logs_public', ctx(), static function (string $url) use ($log_line): array {
    return $url === 'https://ex.test/wp-content/debug.log' ? [200, $log_line] : [404, 'nf'];
});
assert_same(Status::RED, statuses($r)['logs_public:/wp-content/debug.log'], 'plain fetch showing the log → Rot even if the busted one does not');

// A challenge page is Nicht prüfbar, whatever its status.
[, $r] = run_browser('logs_public', ctx(), static fn($u) => [403, '<html><title>Just a moment...</title><script src="/cdn-cgi/challenge-platform/x.js"></script></html>', ['headers' => ['cf-mitigated' => 'challenge']]]);
assert_same(Status::UNKNOWN, statuses($r)['logs_public:/wp-content/debug.log'], 'challenge page → Nicht prüfbar, not "blocked"');
[, $r] = run_browser('logs_public', ctx(), static fn($u) => [403, 'Forbidden', ['headers' => ['cf-mitigated' => 'challenge']]]);
assert_same(Status::UNKNOWN, statuses($r)['logs_public:/wp-content/debug.log'], 'cf-mitigated: challenge header alone → Nicht prüfbar');
[, $r] = run_browser('logs_public', ctx(), static fn($u) => [403, 'x', ['headers' => ['x-amzn-waf-action' => 'captcha']]]);
assert_same(Status::UNKNOWN, statuses($r)['logs_public:/wp-content/debug.log'], 'AWS WAF captcha header → Nicht prüfbar');

// ---------------------------------------------------------------- binary: gzip, ZIP and tar > 64 KB → Gelb "öffentliches Archiv"

$noise = '';
for ($i = 0; $i < 70000; $i++) {
    $noise .= chr(($i * 31 + 7) & 0xff);
}
$tar_header = str_pad('backup/db.sql', 257, "\0") . 'ustar' . str_repeat("\0", 250);
$archives = [
    'site.zip'    => "PK\x03\x04" . substr($noise, 4),
    'site.tar.gz' => "\x1f\x8b\x08\x00" . substr($noise, 4),
    'site.tar'    => $tar_header . substr($noise, 512),
];
foreach ($archives as $name => $bytes) {
    put(ABSPATH . $name, $bytes);
}
[$facts, $r] = run_browser('backups_public', ctx(), static function (string $url) use ($archives): array {
    $name = basename((string) parse_url($url, PHP_URL_PATH));
    if (!isset($archives[$name])) {
        return [404, 'nf'];
    }
    $bytes = $archives[$name];
    // As site-check.js sends it: 64 KB decoded as text, the first 512 bytes as hex.
    return [200, mb_convert_encoding(substr($bytes, 0, 65536), 'UTF-8', 'UTF-8'), ['head_hex' => bin2hex(substr($bytes, 0, 512)), 'truncated' => true]];
});
$s = statuses($r);
foreach (array_keys($archives) as $name) {
    assert_same(Status::YELLOW, $s['backups_public:/' . $name], "{$name}: public archive → Gelb, not Rot");
}
$by = array_column($facts['targets'], null, 'target');
assert_same('archive', $by['/site.tar']['outside_signature'], 'tar recognised by ustar at offset 257');
assert_true(strpos(implode(' ', array_column($r['findings'], 'label')), 'public archive') !== false, 'label says public archive');
foreach (array_keys($archives) as $name) {
    unlink(ABSPATH . $name);
}

// ---------------------------------------------------------------- facts: ≤ 2 KB per target, no body text

$big = str_repeat('SECRETBODY lorem ipsum dolor sit amet ', 1800);
$big = substr($big, 0, 65536);
foreach (['logs_public', 'vcs_env', 'dir_listing', 'security_headers', 'version_leaks', 'robots_txt', 'public_files', 'usernames_public'] as $id) {
    $GLOBALS['stub']['routes'] = [];
    [$facts] = run_browser($id, ctx(), static fn($u) => [200, $big, ['truncated' => true, 'headers' => ['server' => 'Apache/2.4.1', 'content-security-policy' => str_repeat('x', 1000)]]]);
    $json = json_encode($facts);
    assert_true(strpos($json, 'SECRETBODY') === false, "{$id}: facts carry no body text");
    foreach ($facts['targets'] ?? [$facts] as $t) {
        assert_true(strlen(json_encode($t)) <= 2048, "{$id}: facts of one target ≤ 2 KB (got " . strlen(json_encode($t)) . ')');
    }
}

// ---------------------------------------------------------------- rule 6, table-driven

$exts = ['php', 'phtml', 'php5', 'php7', 'phar', 'pht'];
$forms = static function (string $ext): array {
    return ['/x.' . $ext, '/x.' . $ext . '.bak', '/x.' . $ext . '~', '/X.' . strtoupper($ext), '/x%2e' . $ext, '/x%252e' . $ext];
};
$all_forms = array_merge(...array_map($forms, $exts));

// (a) configured paths
$GLOBALS['stub']['requests'] = [];
$GLOBALS['stub']['routes'] = [];
$c = ctx('live', $all_forms);
$plan = OutsideChecks::targets('indexability', $c);
assert_same([], $plan['targets'], 'indexability has no browser targets');
assert_same(count($all_forms), count($plan['skipped']), 'every PHP-like configured path is listed as rejected');
OutsideChecks::observe('indexability', $c, []);
foreach (requested_urls() as $url) {
    assert_true(!php_request($url), "configured path never requested: {$url}");
}
assert_same([OutsideChecks::comparison_url('indexability', $c), 'https://ex.test/robots.txt', 'https://ex.test/'], requested_urls(), 'only the comparison URL, robots.txt and the home page were fetched');

// (b) discovered files
foreach ($exts as $ext) {
    put(ABSPATH . 'x.' . $ext . '.sql', 'x');
    put(ABSPATH . 'X.' . strtoupper($ext) . '.zip', 'x');
    put(ABSPATH . 'x%2e' . $ext . '.sql', 'x');
    put(ABSPATH . 'x.' . $ext . '~.tar', 'x');
}
$GLOBALS['stub']['requests'] = [];
$plan = OutsideChecks::targets('backups_public', ctx());
foreach (array_column($plan['targets'], 'url') as $url) {
    assert_true(!php_request($url), "discovered PHP-like file never a target: {$url}");
}
assert_true(count($plan['skipped']) >= count($exts), 'URL-encoded PHP names are listed as skipped');
OutsideChecks::observe('backups_public', ctx(), browse($plan, $answer404));
assert_same([], requested_urls(), 'the browser checks make no Loopback request');
foreach (glob(ABSPATH . '{x,X}*', GLOB_BRACE) as $file) {
    unlink($file);
}

// (c) sitemap and robots URLs
$GLOBALS['stub']['requests'] = [];
$GLOBALS['stub']['routes'] = [
    'https://ex.test/robots.txt' => ['status' => 200, 'body' => "User-agent: *\nDisallow:\n" . implode("\n", array_map(static fn($p) => 'Sitemap: https://ex.test' . $p, array_slice($all_forms, 0, 9))) . "\n"],
    'https://ex.test/wp-sitemap.xml' => ['status' => 200, 'body' => $index(array_merge(array_map(static fn($p) => 'https://ex.test' . $p, $all_forms), ['https://ex.test/wp-sitemap-posts-post-1.xml']))],
    'https://ex.test/wp-sitemap-posts-post-1.xml' => ['status' => 200, 'body' => $urlset(['https://ex.test/x.php', 'https://ex.test/hello/'])],
];
[$facts, $r] = run_loopback('sitemap_entries', ctx());
OutsideChecks::observe('sitemap', ctx(), []);
OutsideChecks::observe('indexability', ctx('live', $all_forms), []);
foreach (requested_urls() as $url) {
    assert_true(!php_request($url), "sitemap/robots URL never requested: {$url}");
}
assert_same(1, $facts['checked'], 'only the one plain sub-sitemap was followed');
assert_true(in_array('https://ex.test/wp-sitemap-posts-post-1.xml', requested_urls(), true), 'the plain sub-sitemap was requested');

// (d) the predicate itself
foreach ($all_forms as $p) {
    assert_true(!Fetch::allowed_url('https://ex.test' . $p), "allowed_url refuses {$p}");
}
assert_true(Fetch::allowed_url('https://ex.test/xmlrpc.php'), 'xmlrpc.php is allowed (rule 6)');
assert_true(Fetch::allowed_url('https://ex.test/wp-admin/install.php'), 'install.php is allowed (rule 6)');
assert_true(!Fetch::allowed_url('https://ex.test/wp-admin/install.php.bak'), 'install.php.bak is not');
assert_true(!Fetch::allowed_url('https://ex.test/wp-admin/%69nstall.php'), 'an encoded spelling of an allowed script is not');
assert_true(!Fetch::allowed_url('https://ex.test/wp-content/../wp-config.php'), 'dot segments are refused');
assert_true(Fetch::allowed_url('https://ex.test/?author=1'), 'the front end with a query is allowed');
assert_true(!Fetch::allowed_url('https://ex.test:8443/x'), 'another port is refused');
assert_true(!Fetch::allowed_url('http://ex.test/'), 'http on an https site is refused (only the https check may)');
assert_true(!Fetch::allowed_url('https://user:pw@ex.test/'), 'user info is refused');

// ---------------------------------------------------------------- targets: never PHP, never wp-config copies

put(ABSPATH . 'wp-config.php.bak', "<?php define('DB_PASSWORD','x');");
$GLOBALS['stub']['requests'] = [];
$every = [];
foreach (['logs_public', 'backups_public', 'vcs_env', 'dir_listing', 'security_headers', 'usernames_public', 'robots_txt', 'public_files', 'version_leaks', 'https', 'xmlrpc', 'indexability', 'sitemap', 'sitemap_entries'] as $id) {
    $c = ctx('live', ['/x.php', '/ok/']);
    $plan = OutsideChecks::targets($id, $c);
    foreach ($plan['targets'] as $t) {
        $every[] = $t['url'];
    }
    OutsideChecks::observe($id, $c, browse($plan, $answer404));
}
$all_urls = array_merge($every, requested_urls());
assert_true(strpos(implode(' ', $all_urls), 'wp-config') === false, 'wp-config.php.bak never appears in targets or requests');
assert_true(strpos(implode(' ', $all_urls), 'x.php') === false, 'configured /x.php never appears in targets or requests');
foreach ($all_urls as $url) {
    assert_true(!php_request($url), "no PHP outside rule 6: {$url}");
    assert_true(Fetch::allowed_url($url) || $url === 'http://ex.test/', "fetchable, or the one http:// exception: {$url}");
}
assert_true(in_array('https://ex.test/ok/', requested_urls(), true), 'a valid configured path is fetched');
unlink(ABSPATH . 'wp-config.php.bak');

// ---------------------------------------------------------------- https (Loopback)

foreach (['live', 'staging', 'private'] as $profile) {
    $GLOBALS['stub']['routes'] = [
        'https://ex.test/' => new WP_Error('http_request_failed', 'cURL error 60: SSL certificate problem: certificate has expired'),
        'http://ex.test/' => ['status' => 301, 'headers' => ['location' => 'https://ex.test/']],
    ];
    $GLOBALS['stub']['requests'] = [];
    [$facts, $r] = run_loopback('https', ctx($profile));
    assert_same(Status::RED, statuses($r)['https:tls'], "{$profile}: TLS failure → Rot");
    assert_same(Status::GREEN, statuses($r)['https:http_redirect'], "{$profile}: http → https redirect → Grün");
    assert_same(Status::GREEN, statuses($r)['https:home'], "{$profile}: server half joined");
    $non_fetchable = array_values(array_filter(requested_urls(), static fn($u) => !Fetch::allowed_url($u)));
    assert_same(['http://ex.test/'], $non_fetchable, "{$profile}: the http URL is the one non-https fetch");
    foreach ($GLOBALS['stub']['requests'] as $req) {
        assert_same(0, $req['args']['redirection'], 'Loopback never follows redirects');
        assert_same([], $req['args']['cookies'], 'Loopback sends no cookies');
    }

    $GLOBALS['stub']['routes'] = ['https://ex.test/' => ['status' => 200, 'body' => 'ok'], 'http://ex.test/' => ['status' => 200, 'body' => 'plain']];
    [, $r] = run_loopback('https', ctx($profile));
    assert_same(Status::GREEN, statuses($r)['https:tls'], "{$profile}: TLS answers → Grün");
    assert_same(Status::RED, statuses($r)['https:http_redirect'], "{$profile}: http:// not redirecting → Rot");
    assert_same(Status::RED, $r['status'], "{$profile}: any part failing → Rot");

    $GLOBALS['stub']['routes'] = ['https://ex.test/' => ['status' => 200, 'body' => 'ok'], 'http://ex.test/' => ['status' => 302, 'headers' => ['location' => 'http://ex.test/other']]];
    [, $r] = run_loopback('https', ctx($profile));
    assert_same(Status::RED, statuses($r)['https:http_redirect'], "{$profile}: redirect to another http URL → Rot");

    $GLOBALS['stub']['routes'] = ['https://ex.test/' => ['status' => 200, 'body' => 'ok'], 'http://ex.test/' => ['status' => 301, 'headers' => ['location' => 'https://ex.test/']]];
    [, $r] = run_loopback('https', ctx($profile));
    assert_same(Status::GREEN, $r['status'], "{$profile}: all parts fine → Grün");

    $GLOBALS['stub']['routes'] = ['https://ex.test/' => new WP_Error('x', 'cURL error 28: Operation timed out'), 'http://ex.test/' => new WP_Error('x', 'cURL error 7: Failed to connect')];
    [, $r] = run_loopback('https', ctx($profile));
    assert_same(Status::UNKNOWN, statuses($r)['https:tls'], "{$profile}: Loopback blocked → Nicht prüfbar, not Rot");
    assert_same(Status::UNKNOWN, statuses($r)['https:http_redirect'], "{$profile}: no http answer → Nicht prüfbar");
}

// ---------------------------------------------------------------- snapshot: targets and grading come from the run

$GLOBALS['stub']['options']['sfx_site_check_settings'] = ['indexability_paths' => ['/live-setting/'], 'sitemap_allow' => []];
issue_run('runS', ['indexability_paths' => ['/snap/', '/bad?x'], 'sitemap_allow' => ['post_type:bricks_template'], 'profile' => 'staging']);
post(['run' => 'runS', 'check' => 'indexability', '_ajax_nonce' => 'n']);
$plan = OutsideEndpoints::targets_request()[1]['data'];
assert_same(['/bad?x'], array_column($plan['skipped'], 'target'), 'an invalid stored path is listed as rejected');
$GLOBALS['stub']['requests'] = [];
$GLOBALS['stub']['routes'] = ['https://ex.test/snap/' => ['status' => 404]];
post(['run' => 'runS', 'check' => 'indexability', '_ajax_nonce' => 'n', 'observations' => '[]']);
[, $body] = OutsideEndpoints::observe_request();
assert_true(in_array('https://ex.test/snap/', requested_urls(), true), 'the run\'s path is fetched');
assert_true(!in_array('https://ex.test/live-setting/', requested_urls(), true), 'the live setting is not read');
assert_same(Status::YELLOW, statuses($body['data']['graded'])['indexability:/snap/'], 'graded with the run\'s profile (Staging: 404 → Gelb)');
assert_same(Status::HINT, statuses($body['data']['graded'])['indexability:/bad?x'], 'rejected path shown, skipped');

$GLOBALS['stub']['routes'] = [
    'https://ex.test/wp-sitemap.xml' => ['status' => 200, 'body' => $index(['https://ex.test/wp-sitemap-posts-bricks_template-1.xml'])],
    'https://ex.test/wp-sitemap-posts-bricks_template-1.xml' => ['status' => 200, 'body' => $urlset(['https://ex.test/template/a/'])],
];
post(['run' => 'runS', 'check' => 'sitemap_entries', '_ajax_nonce' => 'n', 'observations' => '[]']);
[, $body] = OutsideEndpoints::observe_request();
assert_same(Status::GREEN, $body['data']['graded']['status'], 'the run\'s allow list silences bricks_template although the live setting does not');
$GLOBALS['stub']['options']['sfx_site_check_settings'] = ['sitemap_allow' => ['post_type:bricks_template']];
assert_same(Status::YELLOW, Catalogue::grade('sitemap_entries', $body['data']['facts'], ctx('live', [], []))['status'], 'a run without the allow entry flags it, whatever the live setting says');
unset($GLOBALS['stub']['options']['sfx_site_check_settings']);

// ---------------------------------------------------------------- endpoints: access, nonce, run, check, issued URLs only

issue_run('runE');
$GLOBALS['stub']['requests'] = [];
foreach ([['access' => false, 'nonce' => true], ['access' => true, 'nonce' => false]] as $gate) {
    $GLOBALS['stub']['access'] = $gate['access'];
    $GLOBALS['stub']['nonce'] = $gate['nonce'];
    post(['run' => 'runE', 'check' => 'xmlrpc', '_ajax_nonce' => 'n', 'observations' => '[]']);
    assert_same(403, OutsideEndpoints::targets_request()[0], 'targets refused without ' . ($gate['access'] ? 'nonce' : 'access'));
    assert_same(403, OutsideEndpoints::observe_request()[0], 'observe refused without ' . ($gate['access'] ? 'nonce' : 'access'));
}
assert_same([], requested_urls(), 'a refused call makes no request');
$GLOBALS['stub']['access'] = true;
$GLOBALS['stub']['nonce'] = true;
post(['run' => 'other', 'check' => 'xmlrpc', '_ajax_nonce' => 'n']);
assert_same(409, OutsideEndpoints::targets_request()[0], 'a run that is not the issued run → refused');
post(['run' => 'runE', 'check' => 'debug_display', '_ajax_nonce' => 'n']);
assert_same(400, OutsideEndpoints::targets_request()[0], 'a server check is not served here');
post(['run' => 'runE', 'check' => '../x', '_ajax_nonce' => 'n']);
assert_same(400, OutsideEndpoints::observe_request()[0], 'an unknown check ID is refused');
unset($GLOBALS['stub']['options']['sfx_site_check_manual']);
post(['run' => 'runE', 'check' => 'xmlrpc', '_ajax_nonce' => 'n']);
assert_same(409, OutsideEndpoints::observe_request()[0], 'no issued run (e.g. after purge) → refused');

// Observations for URLs the server did not hand out are dropped.
put(ABSPATH . '.env', "DB=x\n");
$plan = OutsideChecks::targets('vcs_env', ctx());
$list = browse($plan, $answer404);
$list[] = obs('https://ex.test/.env.backup', 200, "SECRET=1\n");
$list[] = obs('https://evil.test/.env', 200, "SECRET=1\n");
$facts = OutsideChecks::observe('vcs_env', ctx(), $list);
assert_same(Status::YELLOW, statuses(Catalogue::grade('vcs_env', $facts, ctx()))['vcs_env:/.env'], 'issued .env answering 404 → Gelb; foreign observations ignored');
$facts = OutsideChecks::observe('vcs_env', ctx(), array_merge([obs('https://ex.test/.env', 200, "SECRET=1\n")], $list));
assert_same(Status::RED, statuses(Catalogue::grade('vcs_env', $facts, ctx()))['vcs_env:/.env'], '.env with KEY=value → Rot');
assert_true(strpos(json_encode($facts), 'SECRET') === false, 'no .env content in facts');
unlink(ABSPATH . '.env');

// ---------------------------------------------------------------- usernames_public

$GLOBALS['stub']['users'] = [user(2, 'maxm', 'Max M.'), user(3, 'Secret.Admin', 'Admin'), user(4, 'editor1', 'editor1')];
$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 404]];
$GLOBALS['stub']['requests'] = [];
$plan = OutsideChecks::targets('usernames_public', ctx());
assert_true(strpos(json_encode($plan), 'maxm') === false && strpos(json_encode($plan), 'Secret.Admin') === false, 'no login is sent to the browser');
[$facts, $r] = run_browser('usernames_public', ctx(), static function (string $url): array {
    if (strpos($url, '/wp-json/wp/v2/users') !== false) {
        return [200, '[{"id":2,"name":"Max M.","slug":"maxm","link":"https:\/\/ex.test\/author\/maxm\/"}]', ['headers' => ['content-type' => 'application/json']]];
    }
    return [404, 'nf'];
});
$s = statuses($r);
assert_same(Status::YELLOW, $s['usernames_public:user-2'], 'REST slug equal to a login → Gelb');
assert_same(Status::HINT, $s['usernames_public:user-4'], 'display name equal to login, not found publicly → Hinweis');
assert_true(!isset($s['usernames_public:user-3']), 'a login not found publicly is not reported');
assert_same(Status::YELLOW, $r['status'], 'check status Gelb');
$out = json_encode([$facts, $r]);
foreach (['maxm', 'Secret.Admin', 'editor1'] as $login) {
    assert_true(strpos($out, $login) === false, "login {$login} never leaves the server");
}
assert_same([OutsideChecks::comparison_url('usernames_public', ctx()), 'https://ex.test/?author=1'], requested_urls(), '?author=1 by Loopback, after its own Loopback comparison');

$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 301, 'headers' => ['location' => 'https://ex.test/author/secret-admin/']]];
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => strpos($u, 'wp/v2/users') !== false ? [200, '[{"slug":"MAXM"}]'] : [404, 'nf']);
assert_true(!isset(statuses($r)['usernames_public:user-2']), 'comparison is exact: MAXM is not maxm');
$GLOBALS['stub']['users'][] = user(5, 'secret-admin', 'S');
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => [404, 'nf']);
assert_same(Status::YELLOW, statuses($r)['usernames_public:user-5'], '?author=1 redirect revealing a login → Gelb');
$GLOBALS['stub']['users'] = [user(2, 'maxm', 'Max M.')];
$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 404]];
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => [404, 'nf']);
assert_same(Status::GREEN, $r['status'], 'every source closed, no match → Grün');
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => [0, '', ['error' => 'timeout']]);
assert_same(Status::UNKNOWN, $r['status'], 'sources without answer → Nicht prüfbar');
$feed = '<rss xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><item><dc:creator><![CDATA[maxm]]></dc:creator></item></channel></rss>';
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => strpos($u, '/feed/') !== false ? [200, $feed] : [404, 'nf']);
assert_same(Status::YELLOW, statuses($r)['usernames_public:user-2'], 'feed creator equal to a login → Gelb');
$GLOBALS['stub']['users'] = [];

// ---------------------------------------------------------------- indexability matrix, Live and Staging

$page = static fn(string $head = '') => ['status' => 200, 'headers' => ['content-type' => 'text/html; charset=UTF-8'], 'body' => "<html><head>{$head}</head><body>x</body></html>"];
$routes = [
    'https://ex.test/robots.txt' => ['status' => 200, 'body' => "User-agent: *\nDisallow: /h/\n\nUser-agent: Googlebot\nDisallow: /\n"],
    'https://ex.test/' => $page('<link rel="canonical" href="https://ex.test/">'),
    'https://ex.test/a/' => $page('<meta name="robots" content="noindex, follow">'),
    'https://ex.test/b/' => $page('<link rel="canonical" href="https://other.test/b/">'),
    'https://ex.test/c/' => ['status' => 404],
    'https://ex.test/d/' => ['status' => 401],
    'https://ex.test/e/' => ['status' => 503],
    'https://ex.test/f/' => ['status' => 302, 'headers' => ['location' => 'https://ex.test/wp-login.php?redirect_to=%2Ff%2F']],
    'https://ex.test/g/' => ['status' => 301, 'headers' => ['location' => 'https://ex.test/other/']],
    'https://ex.test/h/' => $page(),
    'https://ex.test/i/' => ['status' => 200, 'headers' => ['content-type' => 'text/html', 'x-robots-tag' => 'googlebot: noindex'], 'body' => '<html><head></head></html>'],
    'https://ex.test/j/' => ['status' => 403, 'headers' => ['cf-mitigated' => 'challenge'], 'body' => 'x'],
];
$expect = [
    // path => [live, staging]
    '/'   => [Status::GREEN, Status::GREEN],
    '/a/' => [Status::RED, Status::GREEN],
    '/b/' => [Status::RED, Status::RED],
    '/c/' => [Status::RED, Status::YELLOW],
    '/d/' => [Status::RED, Status::GREEN],
    '/e/' => [Status::RED, Status::GREEN],
    '/f/' => [Status::RED, Status::GREEN],
    '/g/' => [Status::YELLOW, Status::YELLOW],
    '/h/' => [Status::YELLOW, Status::YELLOW],
    '/i/' => [Status::RED, Status::GREEN],
    '/j/' => [Status::UNKNOWN, Status::UNKNOWN],
];
$GLOBALS['stub']['routes'] = $routes;
$paths = array_values(array_diff(array_keys($expect), ['/']));
$facts = OutsideChecks::observe('indexability', ctx('live', $paths), []);
foreach (['live' => 0, 'staging' => 1, 'private' => 1] as $profile => $col) {
    $s = statuses(Catalogue::grade('indexability', $facts, ctx($profile, $paths)));
    foreach ($expect as $path => $want) {
        assert_same($want[$col], $s['indexability:' . $path] ?? null, "indexability {$profile} {$path}");
    }
}
assert_true(strpos(json_encode($facts), 'wp-login.php') !== false, 'the login redirect target is a fact');

// robots.txt unreadable → a clean 200 page is Nicht prüfbar.
$GLOBALS['stub']['routes'] = array_replace($routes, ['https://ex.test/robots.txt' => ['status' => 500, 'body' => 'err']]);
$facts = OutsideChecks::observe('indexability', ctx('live', ['/a/', '/h/']), []);
foreach (['live', 'staging'] as $profile) {
    $s = statuses(Catalogue::grade('indexability', $facts, ctx($profile, ['/a/', '/h/'])));
    assert_same(Status::UNKNOWN, $s['indexability:/'], "{$profile}: robots unreadable → Nicht prüfbar");
    assert_same(Status::UNKNOWN, $s['indexability:/h/'], "{$profile}: robots unreadable → Nicht prüfbar, not Gelb");
}
// robots.txt missing (404) = everything allowed.
$GLOBALS['stub']['routes'] = array_replace($routes, ['https://ex.test/robots.txt' => ['status' => 404]]);
$facts = OutsideChecks::observe('indexability', ctx('live', ['/h/']), []);
assert_same(Status::GREEN, statuses(Catalogue::grade('indexability', $facts, ctx()))['indexability:/h/'], 'robots.txt 404 → everything allowed → Grün');

// ---------------------------------------------------------------- robots_txt (browser)

$robots_answer = static fn(string $txt, int $status = 200) => static fn($u) => [$status, $txt];
foreach ([['live', Status::RED], ['staging', Status::GREEN], ['private', Status::GREEN]] as [$profile, $want]) {
    [, $r] = run_browser('robots_txt', ctx($profile), $robots_answer("User-agent: *\nDisallow: /\n"));
    assert_same($want, $r['status'], "robots_txt {$profile}: everything disallowed");
}
foreach ([['live', Status::GREEN], ['staging', Status::HINT]] as [$profile, $want]) {
    [, $r] = run_browser('robots_txt', ctx($profile), $robots_answer("User-agent: *\nDisallow: /\nAllow: /public/\n"));
    assert_same($want, $r['status'], "robots_txt {$profile}: Allow exception → not everything disallowed");
    [, $r] = run_browser('robots_txt', ctx($profile), $robots_answer("User-agent: Googlebot\nDisallow: /\n"));
    assert_same($want, $r['status'], "robots_txt {$profile}: Googlebot group does not affect *");
}
[, $r] = run_browser('robots_txt', ctx(), static fn($u) => [0, '', ['error' => 'network']]);
assert_same(Status::UNKNOWN, $r['status'], 'robots_txt: fetch fails → Nicht prüfbar');
[, $r] = run_browser('robots_txt', ctx(), static fn($u) => [0, '', ['type' => 'opaqueredirect', 'redirect' => true]]);
assert_same(Status::UNKNOWN, $r['status'], 'robots_txt: redirect → Nicht prüfbar');
assert_same('https://ex.test/robots.txt', OutsideChecks::targets('robots_txt', ctx())['targets'][0]['url'], 'robots.txt at the host root');

// ---------------------------------------------------------------- security_headers

$headers_answer = static fn(array $h) => static fn($u) => [200, '<html></html>', ['headers' => $h]];
[, $r] = run_browser('security_headers', ctx(), $headers_answer(['content-security-policy-report-only' => "default-src 'self'"]));
$s = statuses($r);
assert_same(Status::YELLOW, $s['security_headers:content-security-policy'], 'Report-Only only → missing');
assert_true(strpos(implode(' ', array_column($r['findings'], 'label')), 'Report-Only') !== false, 'label explains Report-Only');
assert_same(Status::YELLOW, $s['security_headers:framing'], 'nothing for framing → Gelb');
assert_true(!in_array(Status::RED, array_values($s), true), 'never Rot');
[, $r] = run_browser('security_headers', ctx(), $headers_answer(['content-security-policy' => 'upgrade-insecure-requests;']));
$s = statuses($r);
assert_same(Status::YELLOW, $s['security_headers:content-security-policy'], 'only upgrade-insecure-requests → Gelb');
assert_true(strpos(implode(' ', array_column($r['findings'], 'label')), 'protects little') !== false, '"schützt kaum" wording');
[, $r] = run_browser('security_headers', ctx(), $headers_answer([
    'strict-transport-security' => 'max-age=31536000', 'content-security-policy' => "default-src 'self'; frame-ancestors 'none'",
    'x-content-type-options' => 'nosniff', 'referrer-policy' => 'strict-origin-when-cross-origin', 'permissions-policy' => 'camera=()',
]));
$s = statuses($r);
assert_same(Status::GREEN, $s['security_headers:framing'], 'frame-ancestors counts for framing');
assert_same(Status::HINT, $s['security_headers:content-security-policy'], 'Gate B pass 3: an enforcing CSP is Hinweis (effect not judged in detail)');
assert_same(Status::HINT, $s['security_headers:permissions-policy'], 'Gate B pass 3: a Permissions-Policy is Hinweis');
assert_same(Status::HINT, $r['status'], 'all headers set: the exact ones Grün, CSP and Permissions-Policy Hinweis → Hinweis');
[, $r] = run_browser('security_headers', ctx(), $headers_answer(['x-frame-options' => 'SAMEORIGIN', 'strict-transport-security' => 'max-age=0']));
$s = statuses($r);
assert_same(Status::GREEN, $s['security_headers:framing'], 'X-Frame-Options counts for framing');
assert_same(Status::YELLOW, $s['security_headers:strict-transport-security'], 'max-age=0 does not count');
[, $r] = run_browser('security_headers', ctx(), static fn($u) => [0, '', ['type' => 'opaqueredirect', 'redirect' => true]]);
assert_same(Status::UNKNOWN, $r['status'], 'home page redirect → Nicht prüfbar');

// ---------------------------------------------------------------- version_leaks, dir_listing

$GLOBALS['wp_version'] = '6.8.1';
$page_or_feed = static fn(array $headers) => static fn($u) => strpos($u, '/feed/') !== false
    ? [200, '<?xml version="1.0"?><rss version="2.0"><channel></channel></rss>']
    : [200, '<html><head><title>Home</title></head></html>', ['headers' => $headers]];
[, $r] = run_browser('version_leaks', ctx(), $page_or_feed(['server' => 'Apache/2.4.57 (Debian)', 'x-powered-by' => 'PHP/8.1.2']));
$s = statuses($r);
assert_same(Status::HINT, $s['version_leaks:server'] ?? null, 'server version in headers → Hinweis');
assert_same(Status::HINT, $s['version_leaks:x-powered-by'] ?? null, 'PHP version in headers → Hinweis');
assert_same(Status::HINT, $r['status'], 'headers only → Hinweis');
[, $r] = run_browser('version_leaks', ctx(), $page_or_feed(['server' => 'nginx']));
assert_same(Status::GREEN, $r['status'], 'no version anywhere → Grün');

$plan = OutsideChecks::targets('dir_listing', ctx());
assert_same(['https://ex.test/wp-content/uploads/', 'https://ex.test/wp-content/uploads/2026/10/', 'https://ex.test/wp-content/plugins/', 'https://ex.test/wp-includes/'], array_column($plan['targets'], 'url'), 'dir_listing targets: uploads, newest dated folder, plugins, wp-includes');
$listing = '<html><head><title>Index of /wp-content/uploads</title></head><body><h1>Index of /wp-content/uploads</h1><pre><a href="?C=N;O=D">Name</a> <a href="/wp-content/">Parent Directory</a> <a href="secret-backup.zip">secret-backup.zip</a></pre></body></html>';
[$facts, $r] = run_browser('dir_listing', ctx(), static fn($u) => strpos($u, '/uploads/') !== false && strpos($u, '2026') === false ? [200, $listing] : [403, 'Forbidden']);
$s = statuses($r);
assert_same(Status::YELLOW, $s['dir_listing:/wp-content/uploads/'], 'listing → Gelb');
assert_true(strpos(implode(' ', array_column($r['findings'], 'label')), 'secret-backup.zip') !== false, 'Gelb with the listed names');
assert_same(Status::GREEN, $s['dir_listing:/wp-includes/'], '403 → no listing → Grün');
$odd = str_replace('secret-backup.zip', '%FF%FEbroken.zip', $listing) . '<a href="' . str_repeat('%C3%A4', 80) . '.zip">x</a>';
[$facts] = run_browser('dir_listing', ctx(), static fn($u) => [200, $odd]);
assert_true(json_encode($facts) !== false, 'listed names with broken or cut UTF-8 still encode as JSON');
[, $r] = run_browser('dir_listing', ctx(), static fn($u) => [200, '<html><title>Index of /x</title></html>']);
assert_true(!in_array(Status::YELLOW, array_values(statuses($r)), true), 'one marker alone is not a listing');

// ---------------------------------------------------------------- public_files (Task 11: S+B, readme/license Gelb)

/** Like browse(), but each variant answers on its own: $answer(url, 'busted'|'plain'). */
function browse_variants(array $plan, callable $answer, array $comparison = [404, 'Not found']): array
{
    $list = [];
    foreach ($plan['targets'] as $t) {
        foreach (['busted', 'plain'] as $variant) {
            [$status, $body, $extra] = array_pad($answer($t['url'], $variant), 3, []);
            $url = $variant === 'busted' ? $t['url'] . (strpos($t['url'], '?') === false ? '?' : '&') . 'sfxcb=abc123' : $t['url'];
            $list[] = obs($url, $status, $body, $extra);
        }
    }
    if ($plan['comparison'] !== null) {
        $list[] = obs($plan['comparison'], $comparison[0], $comparison[1]);
    }
    return $list;
}

/** Through the joined observe endpoint; returns [facts, graded, facts re-graded after a JSON round trip (what save does)]. */
function observe_joined(string $id, callable $answer, array $comparison = [404, 'Not found']): array
{
    issue_run('runPF');
    post(['run' => 'runPF', 'check' => $id, '_ajax_nonce' => 'n']);
    [$code, $body] = OutsideEndpoints::targets_request();
    assert_same(200, $code, "{$id}: targets 200");
    post(['run' => 'runPF', 'check' => $id, '_ajax_nonce' => 'n', 'observations' => addslashes(json_encode(browse_variants($body['data'], $answer, $comparison)))]);
    [$code, $body] = OutsideEndpoints::observe_request();
    assert_same(200, $code, "{$id}: observe 200");
    $c = new RunContext('runPF', 'live', [], [], '', false);
    $saved = Catalogue::grade($id, json_decode(json_encode($body['data']['facts']), true), $c);
    assert_same($body['data']['graded'], $saved, "{$id}: the saved facts re-grade to the same result");
    return [$body['data']['facts'], $body['data']['graded']];
}

$readme = '<!DOCTYPE html><html lang="en"><head><title>WordPress &#8250; ReadMe</title></head><body><h1 id="logo"><a href="https://wordpress.org/"><img alt="WordPress" src="wp-admin/images/wordpress-logo.png" /></a></h1><p style="text-align: center">Semantic Personal Publishing Platform</p></body></html>';
$license = "WordPress - Web publishing software\n\nCopyright 2011-2026 by the contributors\n\n                    GNU GENERAL PUBLIC LICENSE\n                       Version 2, June 1991\n";
$setup_form = '<html><body class="wp-core-ui"><p id="logo">WordPress</p><form id="setup" method="post" action="install.php?step=2"><input name="weblog_title" type="text"></form></body></html>';
$installed = '<!DOCTYPE html><html lang="de-DE"><head><title>WordPress &rsaquo; Installation</title><link rel="stylesheet" id="install-css" href="https://ex.test/wp-admin/css/install.min.css" /></head><body class="wp-core-ui"><p id="logo">WordPress</p><h1>Bereits installiert</h1><p>Du hast WordPress anscheinend bereits installiert.</p><p class="step"><a href="https://ex.test/wp-login.php">Anmelden</a></p></body></html>';
$pf = static fn(array $map) => static function (string $url, string $variant) use ($map): array {
    foreach ($map as $needle => $answer) {
        if (strpos($url, $needle) !== false) {
            return $answer;
        }
    }
    return [404, 'nf'];
};
$challenge = ['headers' => ['cf-mitigated' => 'challenge']];

$fs = static fn(array $graded): array => [
    statuses($graded)['public_files:/readme.html'] ?? null,
    statuses($graded)['public_files:/license.txt'] ?? null,
    statuses($graded)['public_files:/wp-admin/install.php'] ?? null,
];

// readme.html on disk, license.txt not.
put(ABSPATH . 'readme.html', $readme);
$plan = OutsideChecks::targets('public_files', ctx());
assert_same(['https://ex.test/readme.html', 'https://ex.test/license.txt', 'https://ex.test/wp-admin/install.php'], array_column($plan['targets'], 'url'), 'public_files targets: readme, license, installer');

[$facts, $r] = observe_joined('public_files', $pf(['readme.html' => [200, $readme], 'install.php' => [200, $setup_form]]));
assert_same([Status::YELLOW, Status::GREEN, Status::RED], $fs($r), 'readable readme → Gelb; absent and blocked license → Grün; setup form → Rot');
$by = array_column($facts['targets'], null, 'target');
assert_same('present', $by['/readme.html']['disk'] ?? null, 'disk half joined by target: readme present');
assert_same('absent', $by['/license.txt']['disk'] ?? null, 'disk half joined by target: license absent');
assert_true(strpos(json_encode($facts), 'Semantic') === false, 'facts carry no body text');
$labels = implode(' ', array_column($r['findings'], 'label'));
assert_true(strpos($labels, 'remove') !== false, 'readable readme says "entfernen oder sperren"');

[, $r] = observe_joined('public_files', $pf(['readme.html' => [403, 'Forbidden'], 'install.php' => [200, $installed]]));
assert_same([Status::GREEN, Status::GREEN, Status::HINT], $fs($r), 'readme 403 on disk → Grün; "already installed" → Hinweis');
$by = array_column($r['findings'], 'label', 'id');
assert_true(strpos($by['public_files:/readme.html'], 'blocked') !== false, 'present and blocked → "liegt da, ist aber gesperrt"');

unlink(ABSPATH . 'readme.html');
[, $r] = observe_joined('public_files', $pf(['install.php' => [404, 'nf']]));
assert_same([Status::GREEN, Status::GREEN, Status::GREEN], $fs($r), 'absent and not reachable → Grün; installer not reachable → Grün');

[, $r] = observe_joined('public_files', $pf(['readme.html' => [200, $readme], 'license.txt' => [200, $license]]));
assert_same([Status::YELLOW, Status::YELLOW], array_slice($fs($r), 0, 2), 'absent on disk but its content still served (cache) → Gelb');

// Only the file's own signature counts; anything else at that address is Nicht prüfbar.
[, $r] = observe_joined('public_files', $pf([
    'readme.html' => [200, '<html><head><title>WordPress Site &#8211; Home</title></head><body>Welcome</body></html>'],
    'license.txt' => [200, 'Some other text, complete.'],
    'install.php' => [200, '<html><head><title>Shop</title></head><body>Unrelated</body></html>'],
]));
assert_same([Status::UNKNOWN, Status::UNKNOWN, Status::UNKNOWN], $fs($r), 'an unrelated complete 200, a WordPress-titled page, and an installer URL serving other HTML → Nicht prüfbar');

// Rule 4 wins over the non-sensitive markers.
[, $r] = observe_joined('public_files', $pf(['readme.html' => [403, $readme, $challenge], 'license.txt' => [503, $license], 'install.php' => [200, $installed, $challenge]]));
assert_same([Status::UNKNOWN, Status::UNKNOWN, Status::UNKNOWN], $fs($r), 'readme marker + challenge, license marker + 503, installer page + challenge → Nicht prüfbar');
[, $r] = observe_joined('public_files', $pf(['readme.html' => [200, $readme]]), [200, '<html>Home</html>']);
assert_same(Status::UNKNOWN, $fs($r)[0], 'readme marker on a soft-404 site → Nicht prüfbar');
[, $r] = observe_joined('public_files', $pf(['readme.html' => [200, $readme, ['truncated' => true]]]));
assert_same(Status::UNKNOWN, $fs($r)[0], 'a truncated readme → Nicht prüfbar');
// A variant with the readable file wins over a failed one.
[, $r] = observe_joined('public_files', static fn($u, $v) => strpos($u, 'readme.html') !== false ? ($v === 'plain' ? [200, $readme] : [0, '', ['error' => 'timeout']]) : [404, 'nf']);
assert_same(Status::YELLOW, $fs($r)[0], 'readable in one variant → Gelb');

// Disk unknown + blocked → Nicht prüfbar (grading table).
$r = Catalogue::grade('public_files', ['targets' => [['target' => '/readme.html', 'kind' => 'readme', 'disk' => 'unknown', 'state' => 'blocked']]], ctx());
assert_same(Status::UNKNOWN, $r['status'], 'unknown disk + blocked → Nicht prüfbar');

// A sensitive signature still beats a challenge (Task 2's rule stays for those).
[, $r] = run_browser('logs_public', ctx(), static fn($u) => $u === 'https://ex.test/error_log' ? [403, $log_line . '<script src="/cdn-cgi/challenge-platform/x.js"></script>', ['headers' => ['cf-mitigated' => 'challenge']]] : [404, 'nf']);
assert_same(Status::RED, statuses($r)['logs_public:/error_log'] ?? null, 'log signature + challenge → still Rot');

// ---------------------------------------------------------------- version_leaks (Task 11): the installed version in home and feed generator

$GLOBALS['wp_version'] = '6.8.1';
$GLOBALS['stub']['requests'] = [];
$plan = OutsideChecks::targets('version_leaks', ctx());
assert_same(['https://ex.test/', 'https://ex.test/feed/'], array_column($plan['targets'], 'url'), 'version_leaks: the home page and the main feed');
assert_true(strpos((string) $plan['comparison'], 'https://ex.test/sfx-site-check-missing-') === 0, 'version_leaks: plus the comparison URL');
assert_same(5, count(browse($plan, static fn($u) => [404, 'nf'])), 'five fetches in the browser batch: home and feed each plain + busted, comparison once');
assert_same(['https://ex.test/'], array_column(OutsideChecks::targets('security_headers', ctx())['targets'], 'url'), 'security_headers still fetches the home page only');

$gen = '<html><head><meta name="generator" content="WordPress 6.8.1" /></head><body>Home</body></html>';
$other_meta = '<html><head><meta name="generator" content="Elementor 6.8.1; features: e_font_icon_svg" /><meta content="WordPress 6.8.10" name="generator"></head><body>Home</body></html>';
$rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title><generator>https://wordpress.org/?v=6.8.1</generator></channel></rss>';
$atom = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><generator uri="https://wordpress.org/" version="6.8.1">WordPress</generator></feed>';
$rss_clean = '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel></rss>';
$vs = static fn(array $graded): array => [statuses($graded)['version_leaks:/'] ?? null, statuses($graded)['version_leaks:/feed/'] ?? null];
$vl = static fn($home, $feed) => static function (string $url, string $variant) use ($home, $feed): array {
    $a = strpos($url, '/feed/') !== false ? $feed : $home;
    return is_callable($a) ? $a($variant) : $a;
};

[, $r] = observe_joined('version_leaks', $vl([200, $gen], [200, $rss_clean]));
assert_same([Status::YELLOW, Status::GREEN], $vs($r), 'generator meta with the installed version → Gelb; clean feed → Grün');
assert_same([], requested_urls(), 'no fetch by the server: no asset, no discovery');
[, $r] = observe_joined('version_leaks', $vl([200, $other_meta], [200, $rss]));
assert_same([Status::GREEN, Status::YELLOW], $vs($r), 'a plugin\'s version in another meta tag is not counted; the version in the feed only → Gelb');
assert_same(Status::YELLOW, $r['status'], 'the check is Gelb');
[, $r] = observe_joined('version_leaks', $vl([200, $other_meta], [200, $atom]));
assert_same(Status::YELLOW, $vs($r)[1], 'Atom generator with the version → Gelb');
[, $r] = observe_joined('version_leaks', $vl([200, $other_meta], [404, 'nf']));
assert_same([Status::GREEN, Status::GREEN], $vs($r), 'feed 404 and no generator meta → Grün');
$labels = array_column($r['findings'], 'label', 'id');
assert_true(strpos($labels['version_leaks:/'], 'not found in the sources checked') !== false, '"in den geprüften Quellen nicht gefunden"');
assert_true(strpos($labels['version_leaks:/feed/'], 'feed not present') !== false, '"Feed nicht vorhanden"');
[, $r] = observe_joined('version_leaks', $vl([200, $other_meta], [403, 'x', $challenge]));
assert_same([Status::GREEN, Status::UNKNOWN], $vs($r), 'challenged feed → Nicht prüfbar for the feed; the home source still grades');
[, $r] = observe_joined('version_leaks', $vl([200, $other_meta], [200, $rss_clean, ['truncated' => true]]));
assert_same(Status::UNKNOWN, $vs($r)[1], 'truncated feed without a match → Nicht prüfbar');
[, $r] = observe_joined('version_leaks', $vl([200, $other_meta], [200, $rss, ['truncated' => true]]));
assert_same(Status::YELLOW, $vs($r)[1], 'truncated feed whose generator is in the bytes read → Gelb');
[, $r] = observe_joined('version_leaks', $vl([200, $other_meta], [0, '', ['type' => 'opaqueredirect', 'redirect' => true]]));
assert_same(Status::UNKNOWN, $vs($r)[1], 'redirected feed → Nicht prüfbar');
[, $r] = observe_joined('version_leaks', $vl([200, $other_meta, ['headers' => ['x-powered-by' => 'PHP/8.2.1']]], [404, 'nf']));
assert_same(Status::HINT, statuses($r)['version_leaks:x-powered-by'] ?? null, 'PHP version in a header → Hinweis');
[, $r] = observe_joined('version_leaks', $vl([200, ''], [500, $rss]));
assert_same([Status::UNKNOWN, Status::UNKNOWN], $vs($r), 'empty 200 and 5xx (even with the generator) → Nicht prüfbar');
[, $r] = observe_joined('version_leaks', $vl([503, $gen], [200, 'unparseable <<<']));
assert_same([Status::UNKNOWN, Status::UNKNOWN], $vs($r), 'generator + 503 → Nicht prüfbar; an answer that is no feed → Nicht prüfbar');
[, $r] = observe_joined('version_leaks', $vl([200, 'plain text, no page'], [200, $rss_clean]));
assert_same([Status::UNKNOWN, Status::GREEN], $vs($r), 'a home answer that is no HTML page → Nicht prüfbar; the feed still grades');

// Rule 4: soft-404 site — a no-match 200 and a generator 200 are both Nicht prüfbar.
[, $r] = observe_joined('version_leaks', $vl([200, $gen], [200, $rss_clean]), [200, '<html>Home</html>']);
assert_same([Status::UNKNOWN, Status::UNKNOWN], $vs($r), 'soft-404: generator + 200 and no-match + 200 → Nicht prüfbar');

// Variant reduction per source, both directions.
$fail = [0, '', ['error' => 'network']];
$cases = [
    'match busted, clean plain'      => [[200, $rss], [200, $rss_clean], Status::YELLOW],
    'clean busted, match plain'      => [[200, $rss_clean], [200, $rss], Status::YELLOW],
    '404 busted, match plain'        => [[404, 'nf'], [200, $rss], Status::YELLOW],
    'match busted, 404 plain'        => [[200, $rss], [404, 'nf'], Status::YELLOW],
    'match busted, failed plain'     => [[200, $rss], $fail, Status::YELLOW],
    'failed busted, match plain'     => [$fail, [200, $rss], Status::YELLOW],
    'clean busted, failed plain'     => [[200, $rss_clean], $fail, Status::UNKNOWN],
    'truncated busted, clean plain'  => [[200, $rss_clean, ['truncated' => true]], [200, $rss_clean], Status::UNKNOWN],
    'clean busted, truncated plain'  => [[200, $rss_clean], [200, $rss_clean, ['truncated' => true]], Status::UNKNOWN],
    '404 busted, clean plain'        => [[404, 'nf'], [200, $rss_clean], Status::UNKNOWN],
    'clean busted, 410 plain'        => [[200, $rss_clean], [410, 'gone'], Status::UNKNOWN],
    '404 busted, 410 plain'          => [[404, 'nf'], [410, 'gone'], Status::GREEN],
    'both clean'                     => [[200, $rss_clean], [200, $rss_clean], Status::GREEN],
    '403 both'                       => [[403, 'x'], [403, 'x'], Status::UNKNOWN],
];
foreach ($cases as $name => [$busted, $plain, $want]) {
    [, $r] = observe_joined('version_leaks', $vl([200, $other_meta], static fn($v) => $v === 'busted' ? $busted : $plain));
    assert_same($want, $vs($r)[1], "feed variants: {$name}");
    [, $r] = observe_joined('version_leaks', $vl(static fn($v) => array_replace($v === 'busted' ? $busted : $plain, [1 => str_replace([$rss, $rss_clean], [$gen, $other_meta], ($v === 'busted' ? $busted : $plain)[1])]), [404, 'nf']));
    if ($name !== '404 busted, 410 plain') {
        assert_same($want, $vs($r)[0], "home variants: {$name}");
    } else {
        assert_same(Status::UNKNOWN, $vs($r)[0], 'home 404/410 → Nicht prüfbar ("not present" is for the feed only)');
    }
}

// ---------------------------------------------------------------- xmlrpc (Loopback)

$methods = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodResponse>\n  <params>\n    <param>\n      <value>\n      <array><data>\n  <value><string>system.multicall</string></value>\n  <value><string>system.listMethods</string></value>\n</data></array>\n      </value>\n    </param>\n  </params>\n</methodResponse>\n";
$fault = static fn(int $code) => "<?xml version=\"1.0\"?>\n<methodResponse>\n  <fault>\n    <value><struct><member><name>faultCode</name><value><int>{$code}</int></value></member></struct></value>\n  </fault>\n</methodResponse>\n";
$cases = [
    [['status' => 200, 'body' => $methods], Status::YELLOW, 'answer listing methods → Gelb'],
    [['status' => 403], Status::GREEN, '403 → Grün'],
    [['status' => 404], Status::GREEN, '404 → Grün'],
    [['status' => 405], Status::GREEN, '405 → Grün'],
    [['status' => 410], Status::GREEN, '410 → Grün'],
    [['status' => 200, 'body' => $fault(-32700)], Status::UNKNOWN, 'parse error fault → Nicht prüfbar'],
    [['status' => 200, 'body' => $fault(-32500)], Status::UNKNOWN, 'internal error fault → Nicht prüfbar'],
    [['status' => 200, 'body' => $fault(12345)], Status::UNKNOWN, 'unknown fault code → Nicht prüfbar'],
    [['status' => 200, 'body' => '<html>hello</html>'], Status::UNKNOWN, 'other answer → Nicht prüfbar'],
    [['status' => 500, 'body' => 'err'], Status::UNKNOWN, '500 → Nicht prüfbar'],
    [new WP_Error('x', 'cURL error 7'), Status::UNKNOWN, 'no answer → Nicht prüfbar'],
];
foreach ($cases as [$route, $want, $label]) {
    $GLOBALS['stub']['routes'] = ['POST https://ex.test/xmlrpc.php' => $route];
    $GLOBALS['stub']['requests'] = [];
    // A caller-supplied URL or method in the observations never reaches the request.
    $junk = [obs('https://ex.test/xmlrpc.php?evil=1', 200, 'pingback.ping'), ['url' => 'https://ex.test/', 'method' => 'pingback.ping']];
    $facts = OutsideChecks::observe('xmlrpc', ctx(), $junk);
    $r = Catalogue::grade('xmlrpc', $facts, ctx());
    assert_same($want, $r['status'], "xmlrpc: {$label}");
    assert_same([['GET', OutsideChecks::comparison_url('xmlrpc', ctx())], ['POST', 'https://ex.test/xmlrpc.php']], array_map(static fn($q) => [$q['method'], $q['url']], $GLOBALS['stub']['requests']), 'xmlrpc: its comparison URL (rule 2), then exactly one request to xmlrpc.php');
    $req = $GLOBALS['stub']['requests'][1];
    assert_same('POST', $req['method'], 'xmlrpc: POST');
    assert_same('https://ex.test/xmlrpc.php', $req['url'], 'xmlrpc: fixed URL');
    assert_same(AccountOutside::XMLRPC_BODY, $req['args']['body'], 'xmlrpc: the system.listMethods body');
    assert_true(strpos($req['args']['body'], 'system.listMethods') !== false, 'xmlrpc: body calls system.listMethods');
    assert_same([], $req['args']['cookies'], 'xmlrpc: no cookies');
    $names = array_map('strtolower', array_keys($req['args']['headers']));
    assert_true(!in_array('cookie', $names, true) && !in_array('authorization', $names, true), 'xmlrpc: no Cookie or Authorization header');
    assert_same([0, 10, 65536], [$req['args']['redirection'], $req['args']['timeout'], $req['args']['limit_response_size']], 'xmlrpc: rule-9 bounds, no redirects');
}

// ---------------------------------------------------------------- sitemap (Loopback)

$sm_cases = [
    'via robots' => [[
        'https://ex.test/robots.txt' => ['status' => 200, 'body' => "Sitemap: https://ex.test/custom-map.xml\n"],
        'https://ex.test/custom-map.xml' => ['status' => 200, 'body' => $urlset(['https://ex.test/a/'])],
    ], Status::GREEN, Status::GREEN],
    'wp-sitemap only' => [['https://ex.test/wp-sitemap.xml' => ['status' => 200, 'body' => $index(['https://ex.test/wp-sitemap-posts-post-1.xml'])]], Status::GREEN, Status::GREEN],
    'sitemap_index only' => [['https://ex.test/sitemap_index.xml' => ['status' => 200, 'body' => $index([])]], Status::GREEN, Status::GREEN],
    'sitemap.xml only' => [['https://ex.test/sitemap.xml' => ['status' => 200, 'body' => $urlset([])]], Status::GREEN, Status::GREEN],
    'none' => [[], Status::YELLOW, Status::HINT],
    'html at sitemap.xml' => [['https://ex.test/sitemap.xml' => ['status' => 200, 'body' => '<html>soft 404</html>']], Status::YELLOW, Status::HINT],
];
foreach ($sm_cases as $label => [$routes, $live, $staging]) {
    $GLOBALS['stub']['routes'] = $routes;
    [$facts, $r] = run_loopback('sitemap', ctx('live'));
    assert_same($live, $r['status'], "sitemap {$label}: Live");
    assert_same($staging, Catalogue::grade('sitemap', $facts, ctx('staging'))['status'], "sitemap {$label}: Staging");
    assert_same($staging, Catalogue::grade('sitemap', $facts, ctx('private'))['status'], "sitemap {$label}: Privat");
}
$GLOBALS['stub']['routes'] = [];
[, $r] = run_loopback('sitemap', ctx());
assert_true(strpos($r['findings'][0]['label'], 'no sitemap found') !== false, '"nicht gefunden" wording, not "does not exist"');
$GLOBALS['stub']['routes'] = ['https://ex.test/robots.txt' => ['status' => 200, 'body' => "Sitemap: https://other.test/s.xml\nSitemap: https://ex.test:8443/s.xml\nSitemap: http://ex.test/s.xml\n"]];
$GLOBALS['stub']['requests'] = [];
[$facts, $r] = run_loopback('sitemap', ctx());
foreach (['https://other.test/s.xml', 'https://ex.test:8443/s.xml', 'http://ex.test/s.xml'] as $foreign) {
    assert_true(!in_array($foreign, requested_urls(), true), "{$foreign} not fetched");
    assert_true(strpos(implode(' ', array_column($r['findings'], 'label')), $foreign) !== false, "{$foreign} listed as skipped");
}
$GLOBALS['stub']['routes'] = array_fill_keys(['https://ex.test/robots.txt', 'https://ex.test/wp-sitemap.xml', 'https://ex.test/sitemap_index.xml', 'https://ex.test/sitemap.xml'], new WP_Error('x', 'cURL error 7'));
[, $r] = run_loopback('sitemap', ctx());
assert_same(Status::UNKNOWN, $r['status'], 'sitemap: no answer at all → Nicht prüfbar');

// ---------------------------------------------------------------- sitemap_entries (Loopback)

$children = ['https://ex.test/wp-sitemap-posts-bricks_template-1.xml', 'https://cdn.test/wp-sitemap-posts-post-99.xml'];
for ($i = 1; $i <= 28; $i++) {
    $children[] = 'https://ex.test/wp-sitemap-posts-post-' . $i . '.xml';
}
$routes = ['https://ex.test/wp-sitemap.xml' => ['status' => 200, 'body' => $index($children)]];
foreach ($children as $child) {
    $routes[$child] = ['status' => 200, 'body' => $urlset(['https://ex.test/x/', 'https://ex.test/y/'])];
}
$GLOBALS['stub']['routes'] = $routes;
$GLOBALS['stub']['requests'] = [];
[$facts, $r] = run_loopback('sitemap_entries', ctx());
assert_same([20, 30], [$facts['checked'], $facts['total']], '30 children → 20 followed');
assert_true(strpos($r['note'], '20 of 30') !== false, 'coverage text "20 of 30"');
assert_same(Status::YELLOW, statuses($r)['sitemap_entries:post_type:bricks_template'] ?? null, 'bricks_template → Gelb');
assert_true(!isset(statuses($r)['sitemap_entries:post_type:post']), 'public post type → nothing');
assert_true(!in_array('https://cdn.test/wp-sitemap-posts-post-99.xml', requested_urls(), true), 'foreign-host child not fetched');
assert_true(strpos(implode(' ', array_column($r['findings'], 'label')), 'https://cdn.test/wp-sitemap-posts-post-99.xml') !== false, 'foreign-host child listed as skipped');
$r = Catalogue::grade('sitemap_entries', $facts, ctx('live', [], ['post_type:bricks_template']));
assert_true(!isset(statuses($r)['sitemap_entries:post_type:bricks_template']), 'allow-listed type silenced');
assert_same(Status::HINT, $r['status'], 'nothing found in a partial check → Hinweis, not Grün');

$GLOBALS['stub']['routes'] = ['https://ex.test/sitemap.xml' => ['status' => 200, 'body' => $urlset([
    'https://ex.test/template/header/', 'https://ex.test/?attachment_id=5', 'https://ex.test/author/someone/',
    'https://ex.test/secret/thing/', 'https://ex.test/category/news/', 'https://ex.test/random-page/',
])]];
[$facts, $r] = run_loopback('sitemap_entries', ctx());
$s = statuses($r);
assert_same(Status::YELLOW, $s['sitemap_entries:post_type:bricks_template'] ?? null, 'entry under the template rewrite slug → bricks_template');
assert_same(Status::YELLOW, $s['sitemap_entries:attachments'] ?? null, 'attachment entry → Gelb');
assert_same(Status::YELLOW, $s['sitemap_entries:authors'] ?? null, 'author entry → Gelb');
assert_same(Status::YELLOW, $s['sitemap_entries:post_type:secret_type'] ?? null, 'non-public post type → Gelb');
assert_true(!isset($s['sitemap_entries:taxonomy:category']), 'public taxonomy → nothing');
assert_same(Status::HINT, $s['sitemap_entries:unmatched'] ?? null, 'unmatched entry → Hinweis "nicht zuordenbar"');
assert_true(strpos($r['note'], '1 of 1') !== false, 'coverage always shown');

$GLOBALS['stub']['routes'] = ['https://ex.test/sitemap.xml' => ['status' => 200, 'body' => $urlset(['https://ex.test/hello/'])]];
$GLOBALS['stub']['routes']['https://ex.test/wp-sitemap.xml'] = ['status' => 200, 'body' => $index(['https://ex.test/wp-sitemap-users-1.xml', 'https://ex.test/wp-sitemap-posts-post-1.xml'])];
$GLOBALS['stub']['routes']['https://ex.test/wp-sitemap-users-1.xml'] = ['status' => 200, 'body' => $urlset(['https://ex.test/author/a/'])];
$GLOBALS['stub']['routes']['https://ex.test/wp-sitemap-posts-post-1.xml'] = ['status' => 200, 'body' => $urlset(['https://ex.test/hello/'])];
[, $r] = run_loopback('sitemap_entries', ctx());
assert_same(Status::YELLOW, statuses($r)['sitemap_entries:authors'] ?? null, 'author sitemap → Gelb');
$r2 = Catalogue::grade('sitemap_entries', OutsideChecks::observe('sitemap_entries', ctx(), []), ctx('live', [], ['authors']));
assert_same(Status::GREEN, $r2['status'], 'author sitemap allow-listed, full coverage → Grün');

// ================================================================ fix round 1

// 1. Empty 200 (ruling): no listing; an empty robots.txt allows everything.
[, $r] = run_browser('dir_listing', ctx(), static fn($u) => [200, '']);
assert_same(Status::GREEN, $r['status'], 'dir_listing: empty 200 → no listing → Grün');
[, $r] = run_browser('dir_listing', ctx(), static fn($u) => [200, '', ['headers' => ['cf-mitigated' => 'challenge']]]);
assert_same(Status::UNKNOWN, $r['status'], 'dir_listing: empty 200 challenge → Nicht prüfbar');
[, $r] = run_browser('dir_listing', ctx(), static fn($u) => [200, '', ['truncated' => true]]);
assert_same(Status::UNKNOWN, $r['status'], 'dir_listing: truncated → Nicht prüfbar');
[, $r] = run_browser('robots_txt', ctx(), static fn($u) => [200, '']);
assert_same(Status::GREEN, $r['status'], 'robots_txt: empty 200 → everything allowed → Grün (Live)');
$GLOBALS['stub']['routes'] = ['https://ex.test/robots.txt' => ['status' => 200, 'body' => ''], 'https://ex.test/h/' => $page()];
$GLOBALS['stub']['routes']['https://ex.test/'] = $page();
$facts = OutsideChecks::observe('indexability', ctx('live', ['/h/']), []);
assert_same(Status::GREEN, statuses(Catalogue::grade('indexability', $facts, ctx()))['indexability:/h/'], 'indexability: empty robots.txt → read, everything allowed → Grün');

// 2. Challenges and 5xx never give a false Grün.
$GLOBALS['stub']['routes'] = ['POST https://ex.test/xmlrpc.php' => ['status' => 403, 'headers' => ['cf-mitigated' => 'challenge'], 'body' => 'x']];
$r = Catalogue::grade('xmlrpc', OutsideChecks::observe('xmlrpc', ctx(), []), ctx());
assert_same(Status::UNKNOWN, $r['status'], 'xmlrpc: challenge with 403 → Nicht prüfbar, not "blocked"');

$GLOBALS['stub']['users'] = [user(2, 'maxm', 'Max M.')];
$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 404]];
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => strpos($u, 'wp/v2/users') !== false
    ? [403, '<script src="/cdn-cgi/challenge-platform/x.js"></script>'] : [404, 'nf']);
assert_same(Status::UNKNOWN, statuses($r)['usernames_public:rest'] ?? null, 'usernames: challenge on a source → unknown, not closed');
foreach ([[500, Status::UNKNOWN], [429, Status::UNKNOWN], [403, Status::GREEN], [404, Status::GREEN]] as [$code, $want]) {
    $GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => $code, 'body' => 'x']];
    [, $r] = run_browser('usernames_public', ctx(), static fn($u) => [404, 'nf']);
    assert_same($want, $r['status'], "usernames: ?author=1 answering {$code}");
}
$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 403, 'headers' => ['cf-mitigated' => 'challenge'], 'body' => 'x']];
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => [404, 'nf']);
assert_same(Status::UNKNOWN, statuses($r)['usernames_public:author_redirect'] ?? null, 'usernames: ?author=1 challenge → unknown');
$GLOBALS['stub']['users'] = [];

[, $r] = run_browser('version_leaks', ctx(), static fn($u) => [403, 'x', ['headers' => ['cf-mitigated' => 'challenge', 'server' => 'Apache/2.4.1']]]);
assert_same(Status::UNKNOWN, $r['status'], 'version_leaks: challenge → Nicht prüfbar');
assert_true(!isset(statuses($r)['version_leaks:server']), 'version_leaks: headers of a challenge page are not counted');
[, $r] = run_browser('version_leaks', ctx(), static fn($u) => [503, 'x', ['headers' => ['server' => 'nginx']]]);
assert_same(Status::UNKNOWN, $r['status'], 'version_leaks: 503 → Nicht prüfbar, not Grün');

// 3. Time budget per observe call; robots.txt fetched once per check.
$GLOBALS['fake_now'] = 1000.0;
Fetch::use_clock(static fn(): float => $GLOBALS['fake_now']);
$GLOBALS['stub']['on_request'] = static function (): void { $GLOBALS['fake_now'] += 8.0; };
$GLOBALS['stub']['routes'] = ['https://ex.test/robots.txt' => ['status' => 404], 'https://ex.test/' => $page(), 'https://ex.test/a/' => $page(), 'https://ex.test/b/' => $page(), 'https://ex.test/c/' => $page()];
$GLOBALS['stub']['requests'] = [];
$facts = OutsideChecks::observe('indexability', ctx('live', ['/a/', '/b/', '/c/']), []);
assert_same(3, count($GLOBALS['stub']['requests']), 'the budget stops requests: comparison (8 s), robots (16 s), / (24 s), then nothing');
assert_true($GLOBALS['stub']['requests'][2]['args']['timeout'] <= 4, 'the last request may only use what is left of the budget');
$s = statuses(Catalogue::grade('indexability', $facts, ctx()));
assert_same(Status::UNKNOWN, $s['indexability:/b/'], 'target after the budget → Nicht prüfbar');
assert_true(strpos(json_encode(Catalogue::grade('indexability', $facts, ctx())), 'time limit') !== false, 'named: not checked, time limit');
assert_same(Status::GREEN, $s['indexability:/'], 'targets before the budget are graded');
assert_same(Status::UNKNOWN, $s['indexability:/a/'], 'the first target after the budget → Nicht prüfbar');
assert_same(1, count(array_filter(requested_urls(), static fn($u) => substr($u, -11) === '/robots.txt')), 'robots.txt fetched once (indexability)');

$routes = ['https://ex.test/wp-sitemap.xml' => ['status' => 200, 'body' => $index(array_map(static fn($i) => 'https://ex.test/wp-sitemap-posts-post-' . $i . '.xml', range(1, 5)))]];
foreach (range(1, 5) as $i) {
    $routes['https://ex.test/wp-sitemap-posts-post-' . $i . '.xml'] = ['status' => 200, 'body' => $urlset(['https://ex.test/x/'])];
}
$GLOBALS['stub']['routes'] = $routes;
$GLOBALS['stub']['requests'] = [];
$GLOBALS['fake_now'] = 2000.0;
[$facts, $r] = run_loopback('sitemap_entries', ctx());
assert_true($facts['checked'] < 5 && !empty($facts['time_limit']), 'sitemap_entries: the budget leaves children unchecked, flagged');
assert_same(Status::UNKNOWN, statuses($r)['sitemap_entries:time-limit'] ?? null, 'sitemap_entries: time limit → Nicht prüfbar line');
assert_same(1, count(array_filter(requested_urls(), static fn($u) => substr($u, -11) === '/robots.txt')), 'robots.txt fetched once (sitemap_entries)');
unset($GLOBALS['stub']['on_request']);
Fetch::use_clock(null);

// 5. A redirect to the same path on another scheme or host is not "another path".
$GLOBALS['stub']['routes'] = ['https://ex.test/robots.txt' => ['status' => 404], 'https://ex.test/' => $page(),
    'https://ex.test/k/' => ['status' => 301, 'headers' => ['location' => 'http://ex.test/k/']],
    'https://ex.test/m/' => ['status' => 301, 'headers' => ['location' => 'https://www.ex.test/m/']],
    'https://ex.test/n/' => ['status' => 301, 'headers' => ['location' => 'https://ex.test/n-new/']]];
$s = statuses(Catalogue::grade('indexability', OutsideChecks::observe('indexability', ctx('live', ['/k/', '/m/', '/n/']), []), ctx()));
assert_same(Status::UNKNOWN, $s['indexability:/k/'], 'same path, other scheme → Nicht prüfbar');
assert_same(Status::UNKNOWN, $s['indexability:/m/'], 'same path, other host → Nicht prüfbar');
assert_same(Status::YELLOW, $s['indexability:/n/'], 'another path → Gelb');

// 6. An SSL timeout is a timeout, not a TLS failure.
$GLOBALS['stub']['routes'] = ['https://ex.test/' => new WP_Error('x', 'cURL error 28: SSL connection timeout'), 'http://ex.test/' => ['status' => 301, 'headers' => ['location' => 'https://ex.test/']]];
[, $r] = run_loopback('https', ctx());
assert_same(Status::UNKNOWN, statuses($r)['https:tls'], 'cURL error 28 with SSL in the text → Nicht prüfbar, not Rot');

// 9. Perspective names the real one.
assert_same('loopback', $r['perspective'], 'https perspective: loopback');
[, $r] = run_browser('logs_public', ctx(), $answer404);
assert_same('browser', $r['perspective'], 'logs_public perspective: browser');

// ================================================================ Task 10: dir_listing fails closed (rule 6)

// A folder URL is requested only when the server established that the folder
// holds no PHP-like index file, or only the exact silence placeholder.
// Tested through the real targets endpoint, for every folder target.
$dir_targets = static function (): array {
    issue_run('runD');
    post(['run' => 'runD', 'check' => 'dir_listing', '_ajax_nonce' => 'n']);
    [$code, $body] = OutsideEndpoints::targets_request();
    assert_same(200, $code, 'dir_listing targets: 200');
    return [array_column($body['data']['targets'], 'url'), array_column($body['data']['skipped'], 'reason', 'target')];
};
$folders = [
    '/wp-content/uploads/'        => WP_CONTENT_DIR . '/uploads',
    '/wp-content/uploads/2026/10/' => WP_CONTENT_DIR . '/uploads/2026/10',
    '/wp-content/plugins/'        => WP_PLUGIN_DIR,
    '/wp-includes/'               => ABSPATH . 'wp-includes',
];
$silence = '<?php // Silence is golden';
foreach ($folders as $target => $dir) {
    $url = 'https://ex.test' . $target;
    [$urls] = $dir_targets();
    assert_true(in_array($url, $urls, true), "{$target}: no index file → requested");

    put($dir . '/index.html', '<html></html>');
    put($dir . '/index.php', $silence);
    [$urls] = $dir_targets();
    assert_true(in_array($url, $urls, true), "{$target}: only the silence placeholder → requested");

    foreach ([
        'index.php'     => "<?php require 'app.php';",
        'index.php5'    => $silence . "\necho 1;",
        'INDEX.PHTML'   => "<?php echo 1;",
        'index.php.bak' => "<?php // old",
    ] as $name => $content) {
        put($dir . '/' . $name, $content);
        [$urls, $skipped] = $dir_targets();
        assert_true(!in_array($url, $urls, true), "{$target}: PHP-like index {$name} → never requested");
        assert_true(strpos($skipped[$target] ?? '', 'PHP index file') !== false, "{$target}: {$name} → named reason");
        unlink($dir . '/' . $name);
        if ($name === 'index.php') {
            put($dir . '/index.php', $silence);
        }
    }

    if (!$as_root) {
        chmod($dir . '/index.php', 0000);
        [$urls, $skipped] = $dir_targets();
        chmod($dir . '/index.php', 0600);
        assert_true(!in_array($url, $urls, true), "{$target}: unreadable index file → never requested");
        assert_true(strpos($skipped[$target] ?? '', 'PHP index file') !== false, "{$target}: unreadable index → named reason");
    }
    unlink($dir . '/index.php');
    unlink($dir . '/index.html');

    if (!$as_root) {
        chmod($dir, 0000);
        [$urls, $skipped] = $dir_targets();
        chmod($dir, 0700);
        assert_true(!in_array($url, $urls, true), "{$target}: unreadable folder → never requested");
        assert_same('folder not readable; never requested', $skipped[$target] ?? null, "{$target}: unreadable folder → named reason");
    }
}
// A folder that is not there (failed stat) is never requested either.
rename(ABSPATH . 'wp-includes', ABSPATH . 'wp-includes-away');
[$urls, $skipped] = $dir_targets();
rename(ABSPATH . 'wp-includes-away', ABSPATH . 'wp-includes');
assert_true(!in_array('https://ex.test/wp-includes/', $urls, true), 'missing folder → never requested');
assert_same('folder not readable; never requested', $skipped['/wp-includes/'] ?? null, 'missing folder → named reason');
// Graded: the folder never requested is Nicht prüfbar with its reason.
put(WP_PLUGIN_DIR . '/index.php', "<?php require 'app.php';");
$GLOBALS['stub']['requests'] = [];
[, $r] = run_browser('dir_listing', ctx(), static fn($u) => [403, 'Forbidden']);
assert_same(Status::UNKNOWN, statuses($r)['dir_listing:/wp-content/plugins/'] ?? null, 'never requested → Nicht prüfbar');
assert_same(Status::GREEN, statuses($r)['dir_listing:/wp-includes/'] ?? null, 'the other folders are still graded');
unlink(WP_PLUGIN_DIR . '/index.php');

// Task 10: notes do not repeat the guidance (the guidance is shown on its own).
[, $r] = run_browser('security_headers', ctx(), static fn($u) => [200, 'x']);
$GLOBALS['stub']['routes'] = ['POST https://ex.test/xmlrpc.php' => ['status' => 403, 'body' => 'x']];
$x = Catalogue::grade('xmlrpc', OutsideChecks::observe('xmlrpc', ctx(), []), ctx());
foreach (['security_headers' => $r, 'xmlrpc' => $x] as $id => $graded) {
    assert_true(stripos($graded['note'], 'Tip:') === false && stripos($graded['note'], 'module of this theme') === false, "{$id}: the note does not repeat the tip");
    assert_true($graded['note'] !== '', "{$id}: the note still says how it was measured");
}

// Task 10: Fetch::remote() warns that it bypasses refusal().
$doc = (string) (new ReflectionMethod(Fetch::class, 'remote'))->getDocComment();
assert_true(strpos($doc, 'refusal()') !== false && strpos($doc, 'http://') !== false, 'Fetch::remote() docblock: bypasses refusal(), only for the https http:// exception');

// ================================================================ Gate B pass 1

$label_of = static fn(array $r, string $id): string => (string) (array_column($r['findings'], 'label', 'id')[$id] ?? '');
$cmp = static fn(string $id): string => OutsideChecks::comparison_url($id, ctx());

// quality-1 (BLOCKER): a URL whose path is a folder on disk runs its index
// file, so the shared request boundary refuses it unless the folder is
// verified harmless. WordPress routes that are no folder stay allowed.
$map_dir = WP_CONTENT_DIR . '/uploads/custom-map';
put($map_dir . '/index.php', "<?php echo 'ran';");
$map_url = 'https://ex.test/wp-content/uploads/custom-map/';
assert_same('dir', Fetch::refusal($map_url), 'folder with an unknown index.php → refused');
assert_same('dir', Fetch::refusal(rtrim($map_url, '/')), 'the same folder without the trailing slash → refused');
assert_same('dir', Fetch::refusal('https://ex.test/wp-content/uploads/custom%2Dmap/'), 'an encoded spelling of the folder → refused');
assert_same('', Fetch::refusal('https://ex.test/'), 'the home page (front end) stays allowed');
assert_same('', Fetch::refusal('https://ex.test/kontakt/'), 'a pretty permalink (no folder on disk) stays allowed');
assert_same('', Fetch::refusal('https://ex.test/feed/'), 'the feed stays allowed');
assert_same('', Fetch::refusal('https://ex.test/wp-json/wp/v2/users'), 'REST stays allowed');
assert_true(strpos(Fetch::reason_text('dir'), 'PHP index file') !== false, 'the folder refusal names its reason');
$GLOBALS['stub']['requests'] = [];
Fetch::loopback('GET', $map_url);
assert_same([], requested_urls(), 'Fetch::loopback sends nothing to such a folder');

$GLOBALS['stub']['routes'] = [
    'https://ex.test/robots.txt' => ['status' => 200, 'body' => "User-agent: *\nDisallow:\nSitemap: {$map_url}\n"],
    'https://ex.test/wp-sitemap.xml' => ['status' => 200, 'body' => $index([$map_url, rtrim($map_url, '/'), 'https://ex.test/wp-sitemap-posts-post-1.xml'])],
    'https://ex.test/wp-sitemap-posts-post-1.xml' => ['status' => 200, 'body' => $urlset(['https://ex.test/hello/'])],
    'https://ex.test/' => $page(),
];
$GLOBALS['stub']['requests'] = [];
[$facts, $r] = run_loopback('sitemap', ctx());
assert_same('/wp-sitemap.xml', $facts['found'], 'sitemap discovery skips the folder named in robots.txt and finds the next candidate');
assert_true(strpos(implode(' ', array_column($r['findings'], 'label')), 'custom-map') !== false, 'the skipped folder is named');
[$facts] = run_loopback('sitemap_entries', ctx());
assert_same(1, $facts['checked'], 'sitemap children: only the real sub-sitemap is followed');
$facts = OutsideChecks::observe('indexability', ctx('live', ['/wp-content/uploads/custom-map/', '/wp-content/uploads/custom-map']), []);
assert_same(['/wp-content/uploads/custom-map/', '/wp-content/uploads/custom-map'], array_column($facts['rejected'], 'target'), 'indexability: folder paths are rejected and named');
assert_true(strpos($facts['rejected'][0]['reason'], 'PHP index file') !== false, 'indexability: the rejection gives the folder reason');
foreach (requested_urls() as $url) {
    assert_true(strpos($url, 'custom-map') === false, "the folder is never requested: {$url}");
}
put($map_dir . '/index.php', '<?php // Silence is golden');
assert_same('', Fetch::refusal($map_url), 'a folder holding only the silence placeholder may be requested');
if (!$as_root) {
    chmod($map_dir, 0000);
    assert_same('dir_unreadable', Fetch::refusal($map_url), 'an unreadable folder cannot be verified → refused');
    chmod($map_dir, 0700);
}
unlink($map_dir . '/index.php');
rmdir($map_dir);
// A path no known root maps (web root not established) cannot be verified.
stub_reset(['home' => 'https://ex.test', 'siteurl' => 'https://ex.test/wp', 'options' => ['home' => 'https://ex.test', 'siteurl' => 'https://ex.test/wp']]);
assert_same('unmapped', Fetch::refusal('https://ex.test/kontakt/'), 'unmapped path → refused');
assert_same('', Fetch::refusal('https://ex.test/'), 'the home page stays allowed even then');
stub_reset();

// spec-7: every Loopback batch fetches its own comparison URL (rule 2).
$GLOBALS['stub']['routes'] = ['https://ex.test/robots.txt' => ['status' => 404], 'https://ex.test/' => $page(), 'https://ex.test/h/' => $page()];
$GLOBALS['stub']['requests'] = [];
$facts = OutsideChecks::observe('indexability', ctx('live', ['/h/']), []);
assert_true(in_array($cmp('indexability'), requested_urls(), true), 'indexability fetched its comparison URL');
assert_same(Status::GREEN, statuses(Catalogue::grade('indexability', $facts, ctx()))['indexability:/h/'], 'comparison 404 → a 200 page is graded');
$GLOBALS['stub']['routes'][$cmp('indexability')] = $page();
$facts = OutsideChecks::observe('indexability', ctx('live', ['/h/']), []);
$s = statuses(Catalogue::grade('indexability', $facts, ctx()));
assert_same(Status::UNKNOWN, $s['indexability:/h/'], 'soft-404 site (comparison 200) → a 200 page is Nicht prüfbar');
assert_same(Status::UNKNOWN, $s['indexability:/'], 'soft-404 site → the home page too');
$GLOBALS['stub']['routes'][$cmp('indexability')] = ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<html><head><meta name="robots" content="noindex"></head></html>'];
$s = statuses(Catalogue::grade('indexability', OutsideChecks::observe('indexability', ctx('live', ['/c/']), []), ctx()));
assert_same(Status::RED, $s['indexability:/c/'], 'status findings stay their own (catalogue exception): 404 → Rot');
foreach (['sitemap', 'sitemap_entries', 'xmlrpc', 'https'] as $id) {
    $GLOBALS['stub']['requests'] = [];
    OutsideChecks::observe($id, ctx(), []);
    assert_true(in_array($cmp($id), requested_urls(), true), "{$id} fetched its comparison URL");
}

// spec-1: empty, malformed or incompletely read HTML is no evidence for indexing.
$html = static fn(string $body, array $extra = []) => array_replace(['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => $body], $extra);
$GLOBALS['stub']['routes'] = [
    'https://ex.test/robots.txt' => ['status' => 404], 'https://ex.test/' => $page(),
    'https://ex.test/e1/' => $html(''),
    'https://ex.test/e2/' => $html('garbage without markup'),
    'https://ex.test/e3/' => $html(str_repeat('x', 65536) . '</head>'),
    'https://ex.test/e4/' => $html('<html><head><title>t</title></head><body>' . str_repeat('x', 70000)),
];
$s = statuses(Catalogue::grade('indexability', OutsideChecks::observe('indexability', ctx('live', ['/e1/', '/e2/', '/e3/', '/e4/']), []), ctx()));
assert_same(Status::UNKNOWN, $s['indexability:/e1/'], 'empty 200 HTML → Nicht prüfbar');
assert_same(Status::UNKNOWN, $s['indexability:/e2/'], 'unparseable 200 → Nicht prüfbar');
assert_same(Status::UNKNOWN, $s['indexability:/e3/'], 'truncated with a stray </head> but no head → Nicht prüfbar');
assert_same(Status::UNKNOWN, $s['indexability:/e4/'], 'Gate B pass 4 ruling: a truncated page is Nicht prüfbar for metadata, even after a complete head');

// quality-8: a host-only Location is the root path; unusable targets prove nothing.
$GLOBALS['stub']['routes'] = ['https://ex.test/robots.txt' => ['status' => 404], 'https://ex.test/' => $page(),
    'https://ex.test/r1/' => ['status' => 301, 'headers' => ['location' => 'https://other.test']],
    'https://ex.test/r2/' => ['status' => 301, 'headers' => ['location' => 'http://']],
    'https://ex.test/r3/' => ['status' => 301, 'headers' => ['location' => 'relative-target']]];
$r = Catalogue::grade('indexability', OutsideChecks::observe('indexability', ctx('live', ['/r1/', '/r2/', '/r3/']), []), ctx());
$s = statuses($r);
assert_same(Status::YELLOW, $s['indexability:/r1/'], 'host-only Location → redirect to / → Gelb');
assert_true(strpos($label_of($r, 'indexability:/r1/'), 'https://other.test/') !== false, 'the destination is named, with its host and /');
assert_same(Status::UNKNOWN, $s['indexability:/r2/'], 'unusable Location → Nicht prüfbar');
assert_same(Status::UNKNOWN, $s['indexability:/r3/'], 'relative Location without a path root → Nicht prüfbar');

// spec-2 / quality-4: robots.txt must read as robots.txt; empty only with a real 404 comparison.
[, $r] = run_browser('robots_txt', ctx(), static fn($u) => [200, ''], [200, '<html>soft</html>']);
assert_same(Status::UNKNOWN, $r['status'], 'robots_txt: empty 200 on a soft-404 site → Nicht prüfbar');
[, $r] = run_browser('robots_txt', ctx(), static fn($u) => [200, ''], [0, '']);
assert_same(Status::UNKNOWN, $r['status'], 'robots_txt: empty 200 without a usable comparison → Nicht prüfbar');
[, $r] = run_browser('robots_txt', ctx(), static fn($u) => [200, '<!DOCTYPE html><html><body>Home</body></html>', ['headers' => ['content-type' => 'text/html']]]);
assert_same(Status::UNKNOWN, $r['status'], 'robots_txt: an HTML page → Nicht prüfbar, not "allows everything"');
[, $r] = run_browser('robots_txt', ctx(), static fn($u) => [200, "Nothing: here\nat all"]);
assert_same(Status::UNKNOWN, $r['status'], 'robots_txt: unparseable text → Nicht prüfbar');
[, $r] = run_browser('robots_txt', ctx(), static fn($u) => [200, "User-agent: *\nDisallow: /$\n"]);
assert_same(Status::GREEN, $r['status'], 'robots_txt: Disallow: /$ is not a whole-site block (Live → Grün)');
[, $r] = run_browser('robots_txt', ctx(), static fn($u) => [200, "User-agent: *\nDisallow: /wp-admin/\n"], [200, 'soft']);
assert_same(Status::GREEN, $r['status'], 'robots_txt: a recognisable robots.txt counts on a soft-404 site (content, rule 2)');
$GLOBALS['stub']['routes'] = ['https://ex.test/robots.txt' => ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<html><body>Home</body></html>'], 'https://ex.test/' => $page()];
$s = statuses(Catalogue::grade('indexability', OutsideChecks::observe('indexability', ctx(), []), ctx()));
assert_same(Status::UNKNOWN, $s['indexability:/'], 'indexability: an HTML robots.txt is not "read"');
$GLOBALS['stub']['routes'] = ['https://ex.test/robots.txt' => ['status' => 200, 'body' => ''], 'https://ex.test/' => $page(), $cmp('indexability') => $page()];
$s = statuses(Catalogue::grade('indexability', OutsideChecks::observe('indexability', ctx(), []), ctx()));
assert_same(Status::UNKNOWN, $s['indexability:/'], 'indexability: an empty robots.txt on a soft-404 site is not "read"');

// Security headers (Gate B pass 3 ruling): CSP and Permissions-Policy present → Hinweis; exact grammar for the rest.
$hdr = static fn(array $h) => statuses(run_browser('security_headers', ctx(), static fn($u) => [200, '<html></html>', ['headers' => $h]])[1]);
$s = $hdr(['content-security-policy' => 'invalid', 'referrer-policy' => 'invalid', 'permissions-policy' => 'invalid']);
assert_same(Status::HINT, $s['security_headers:content-security-policy'], 'CSP present (whatever it says) → Hinweis, never Grün');
assert_same(Status::YELLOW, $s['security_headers:referrer-policy'], 'Referrer-Policy "invalid" → Gelb');
assert_same(Status::HINT, $s['security_headers:permissions-policy'], 'Permissions-Policy present → Hinweis, never Grün');
$s = $hdr(['referrer-policy' => 'no-referrer-when-downgrade', 'permissions-policy' => 'camera=(), camera=*']);
assert_same(Status::YELLOW, $s['security_headers:referrer-policy'], 'no-referrer-when-downgrade → Gelb (sends the full URL)');
assert_same(Status::HINT, $s['security_headers:permissions-policy'], 'an overwritten Permissions-Policy is no Grün either');
foreach (['origin', 'origin-when-cross-origin', 'unsafe-url'] as $weak) {
    assert_same(Status::YELLOW, $hdr(['referrer-policy' => $weak])['security_headers:referrer-policy'], "Referrer-Policy {$weak} → Gelb (not on the safe list)");
}
$s = $hdr(['referrer-policy' => 'bogus, strict-origin-when-cross-origin']);
assert_same(Status::GREEN, $s['security_headers:referrer-policy'], 'the last recognised Referrer-Policy token counts');
$s = $hdr(['content-security-policy' => "default-src 'self'; frame-ancestors *"]);
assert_same(Status::YELLOW, $s['security_headers:framing'], "frame-ancestors * does not protect framing");
foreach (["frame-ancestors 'none'", "frame-ancestors 'self'", "frame-ancestors 'SELF'"] as $fa) {
    assert_same(Status::GREEN, $hdr(['content-security-policy' => $fa])['security_headers:framing'], "{$fa} → Grün");
}
assert_same(Status::YELLOW, $hdr(['content-security-policy' => "frame-ancestors 'self' https://partner.example", 'x-frame-options' => 'DENY'])['security_headers:framing'], 'frame-ancestors other than none/self → Gelb, and it wins over X-Frame-Options');
// HSTS: strict RFC 6797 directives.
foreach (['max-age=31536000' => Status::GREEN, 'max-age="31536000"; includeSubDomains; preload' => Status::GREEN, 'MAX-AGE=10' => Status::GREEN,
    'max-age="31536000' => Status::YELLOW, 'max-age=1; max-age=2' => Status::YELLOW, 'includeSubDomains' => Status::YELLOW, 'max-age=abc' => Status::YELLOW,
    'max-age=0' => Status::YELLOW, 'max-age=10;;' => Status::GREEN, 'max-age = 10' => Status::GREEN, 'max-age=1 junk' => Status::YELLOW] as $value => $want) {
    assert_same($want, $hdr(['strict-transport-security' => $value])['security_headers:strict-transport-security'], "HSTS {$value}");
}

// spec-5 / quality-3: a username source counts as read only when complete, well-formed and no soft-404.
$GLOBALS['stub']['users'] = [user(2, 'maxm', 'Max M.')];
$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 404]];
$rest_only = static fn(string $body, array $extra = []) => static fn($u) => strpos($u, 'wp/v2/users') !== false ? [200, $body, $extra] : [404, 'nf'];
[, $r] = run_browser('usernames_public', ctx(), $rest_only('not json at all'));
assert_same(Status::UNKNOWN, statuses($r)['usernames_public:rest'] ?? null, 'invalid JSON → source unknown');
assert_true($r['status'] !== Status::GREEN, 'invalid JSON → never Grün');
[, $r] = run_browser('usernames_public', ctx(), $rest_only('[{"id":3,"slug":"nob', ['truncated' => true]));
assert_same(Status::UNKNOWN, statuses($r)['usernames_public:rest'] ?? null, 'truncated JSON → source unknown');
[, $r] = run_browser('usernames_public', ctx(), $rest_only('[{"id":2,"slug":"maxm","x":"', ['truncated' => true]));
assert_same(Status::YELLOW, statuses($r)['usernames_public:user-2'] ?? null, 'a login found in a truncated answer is still reported');
[, $r] = run_browser('usernames_public', ctx(), $rest_only('[]'));
assert_same(Status::GREEN, $r['status'], 'an empty, valid user list with a 404 comparison → read → Grün');
[, $r] = run_browser('usernames_public', ctx(), $rest_only('[]'), [200, 'soft']);
assert_same(Status::UNKNOWN, statuses($r)['usernames_public:rest'] ?? null, 'soft-404 site (comparison 200) → source unknown');
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => [200, '<html><body>Welcome</body></html>'], [200, 'soft']);
assert_true($r['status'] !== Status::GREEN, 'generic HTML everywhere on a soft-404 site → never Grün');
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => strpos($u, '/feed/') !== false ? [200, '<html>not a feed</html>'] : [404, 'nf']);
assert_same(Status::UNKNOWN, statuses($r)['usernames_public:feed'] ?? null, 'a feed source that is no feed → unknown');
$GLOBALS['stub']['users'] = [];

// spec-6 / quality-5: a sitemap is a well-formed sitemap document; challenges and malformed answers are Nicht prüfbar.
$sm = static function (array $routes): array {
    $GLOBALS['stub']['routes'] = $routes;
    [$facts, $r] = run_loopback('sitemap', ctx());
    [$efacts, $er] = run_loopback('sitemap_entries', ctx());
    return [$r, $efacts, $er];
};
[$r, $efacts] = $sm(['https://ex.test/sitemap.xml' => ['status' => 200, 'headers' => ['cf-mitigated' => 'challenge'], 'body' => '<urlset INVALID']]);
assert_same(Status::UNKNOWN, $r['status'], 'challenge with a <urlset tag → Nicht prüfbar, not Grün');
assert_same(0, $efacts['checked'], 'sitemap_entries: nothing counted as checked');
[$r] = $sm(['https://ex.test/sitemap.xml' => ['status' => 200, 'body' => '<html><script>var s = "<urlset>";</script></html>']]);
assert_true($r['status'] !== Status::GREEN, 'an HTML page quoting <urlset> is no sitemap');
[$r] = $sm(['https://ex.test/sitemap.xml' => ['status' => 200, 'body' => '<?xml version="1.0"?><urlset><url><loc>https://ex.test/a/</loc>']]);
assert_same(Status::UNKNOWN, $r['status'], 'a malformed complete sitemap → Nicht prüfbar');
[$r, $efacts, $er] = $sm(['https://ex.test/sitemap.xml' => ['status' => 200, 'body' => '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://ex.test/a/</loc></url>' . str_repeat('<url><loc>https://ex.test/b/</loc></url>', 2000)]]);
assert_same(Status::GREEN, $r['status'], 'a truncated sitemap whose root is a urlset is found');
assert_true(!empty($efacts['partial']), 'and read in part (coverage note)');

// spec-8 / quality-7: an empty 200 is "no listing" only when the comparison answered 404/410.
[, $r] = run_browser('dir_listing', ctx(), static fn($u) => [200, ''], [200, '']);
assert_same(Status::UNKNOWN, $r['status'], 'dir_listing: empty 200 with an empty 200 comparison → Nicht prüfbar');
[, $r] = run_browser('dir_listing', ctx(), static fn($u) => [200, ''], [0, '']);
assert_same(Status::UNKNOWN, $r['status'], 'dir_listing: empty 200 without a comparison answer → Nicht prüfbar');
[, $r] = run_browser('dir_listing', ctx(), static fn($u) => [200, ''], [410, '']);
assert_same(Status::GREEN, $r['status'], 'dir_listing: empty 200 with a 410 comparison → no listing');
[, $r] = run_browser('dir_listing', ctx(), static fn($u) => [403, $listing, ['headers' => ['cf-mitigated' => 'challenge']]]);
assert_true(!in_array(Status::YELLOW, array_values(statuses($r)), true), 'dir_listing: listing markers in a challenge answer are no listing');

// ================================================================ Gate B pass 2

// quality-1 / spec-1 (BLOCKERs): decoded exactly once, as the server does; leftover escapes refused; failed stats refused.
$pct_dir = WP_CONTENT_DIR . '/uploads/%61';
put($pct_dir . '/index.php', "<?php echo 'ran';");
assert_true(Fetch::refusal('https://ex.test/wp-content/uploads/%2561/') !== '', 'a %2561 path (literal %61 folder on the server) is refused, not mapped to /a/');
assert_same('encoding', Fetch::refusal('https://ex.test/wp-content/uploads/%2561/'), 'named as an ambiguous encoding');
assert_same('', Fetch::refusal('https://ex.test/wp-content/uploads/%61/'), '%61 is the folder "a" for the server: absent there, allowed');
$GLOBALS['stub']['routes'] = ['https://ex.test/robots.txt' => ['status' => 200, 'body' => "User-agent: *\nDisallow:\nSitemap: https://ex.test/wp-content/uploads/%2561/\n"]];
$GLOBALS['stub']['requests'] = [];
run_loopback('sitemap', ctx());
assert_true(!in_array('https://ex.test/wp-content/uploads/%2561/', requested_urls(), true), 'sitemap discovery never requests the %2561 folder');
unlink($pct_dir . '/index.php');
rmdir($pct_dir);
if (!$as_root) {
    $blocked = WP_CONTENT_DIR . '/uploads/blocked';
    put($blocked . '/child/index.php', "<?php echo 'ran';");
    chmod($blocked, 0000);
    $r1 = Fetch::refusal('https://ex.test/wp-content/uploads/blocked/child/');
    chmod($blocked, 0600);
    $r2 = Fetch::refusal('https://ex.test/wp-content/uploads/blocked/child/');
    chmod($blocked, 0700);
    assert_same('dir_unreadable', $r1, 'a folder below a chmod-0000 parent cannot be verified → refused');
    assert_same('dir_unreadable', $r2, 'a folder below a non-searchable parent cannot be verified → refused');
    assert_same('dir', Fetch::refusal('https://ex.test/wp-content/uploads/blocked/child/'), 'control: searchable again, the unknown index is seen');
    remove_tree($blocked);
}

// spec-3 / quality-10: a sitemap on a soft-404 site proves nothing (discovery and children).
$GLOBALS['stub']['routes'] = ['https://ex.test/sitemap.xml' => ['status' => 200, 'body' => $urlset(['https://ex.test/a/'])], $cmp('sitemap') => ['status' => 200, 'body' => 'soft']];
[, $r] = run_loopback('sitemap', ctx());
assert_same(Status::UNKNOWN, $r['status'], 'sitemap: comparison 200 → Nicht prüfbar, not Grün');
$GLOBALS['stub']['routes'] = ['https://ex.test/wp-sitemap.xml' => ['status' => 200, 'body' => $index(['https://ex.test/wp-sitemap-posts-post-1.xml'])], 'https://ex.test/wp-sitemap-posts-post-1.xml' => ['status' => 200, 'body' => $urlset(['https://ex.test/x/'])], $cmp('sitemap_entries') => ['status' => 0]];
$GLOBALS['stub']['routes'][$cmp('sitemap_entries')] = new WP_Error('x', 'cURL error 7');
[$facts] = run_loopback('sitemap_entries', ctx());
assert_same(0, $facts['checked'], 'sitemap_entries: without a usable comparison no sitemap counts as checked');

// spec-4: header verdicts need a reliable home-page answer (rule 4).
$good = ['strict-transport-security' => 'max-age=31536000', 'content-security-policy' => "default-src 'self'", 'x-frame-options' => 'DENY', 'x-content-type-options' => 'nosniff', 'referrer-policy' => 'no-referrer', 'permissions-policy' => 'camera=()'];
[, $r] = run_browser('security_headers', ctx(), static fn($u) => [200, '', ['headers' => $good]]);
assert_same(Status::UNKNOWN, $r['status'], 'security_headers: empty home page → Nicht prüfbar');
[, $r] = run_browser('security_headers', ctx(), static fn($u) => [200, '<html></html>', ['headers' => $good]], [200, '<html></html>']);
assert_same(Status::UNKNOWN, $r['status'], 'security_headers: soft-404 site → Nicht prüfbar');
[, $r] = run_browser('security_headers', ctx(), static fn($u) => [200, '<html></html>', ['headers' => $good]]);
assert_same(Status::HINT, $r['status'], 'control: a reliable answer with good headers → graded (Hinweis for CSP/Permissions-Policy)');

// spec-5 / quality-3: whole header values up to 8 KB; a cut value is never Grün.
$long = "default-src 'self'; img-src " . implode(' ', array_map(static fn($i) => "https://img{$i}.example.com", range(1, 40))) . "; frame-ancestors *";
assert_true(strlen($long) > 1024 && strlen($long) < 8192, 'fixture: the permissive directive lies beyond 1 KB');
[, $r] = run_browser('security_headers', ctx(), static fn($u) => [200, '<html></html>', ['headers' => ['content-security-policy' => $long, 'x-frame-options' => 'DENY']]]);
assert_same(Status::YELLOW, statuses($r)['security_headers:framing'], 'a frame-ancestors * beyond 1 KB is still seen');
[, $r] = run_browser('security_headers', ctx(), static fn($u) => [200, '<html></html>', ['headers' => ['content-security-policy' => "frame-ancestors 'none'; img-src " . str_repeat('a', 8300)]]]);
assert_same(Status::UNKNOWN, statuses($r)['security_headers:content-security-policy'], 'a value longer than 8 KB is cut → Nicht prüfbar');
assert_same(Status::UNKNOWN, statuses($r)['security_headers:framing'], 'and framing from a cut CSP is not judged either');

// spec-7 / quality-8: a clean feed verdict needs a well-formed feed.
$GLOBALS['wp_version'] = '6.8.1';
$feed_answer = static fn(string $feed) => static fn($u) => strpos($u, '/feed/') !== false ? [200, $feed] : [200, '<html><head><title>Home</title></head></html>'];
foreach (['<html><body>Documentation mentions <rss></body></html>' => 'HTML mentioning <rss>', '<rss INVALID' => 'malformed <rss', '<?xml version="1.0"?><rss version="2.0"><channel>' => 'unclosed rss'] as $feed => $what) {
    [, $r] = run_browser('version_leaks', ctx(), $feed_answer($feed));
    assert_same(Status::UNKNOWN, statuses($r)['version_leaks:/feed/'] ?? null, "version_leaks: {$what} → feed Nicht prüfbar");
}
[, $r] = run_browser('version_leaks', ctx(), $feed_answer('<?xml version="1.0"?><rss version="2.0"><channel><generator>https://wordpress.org/?v=6.8.1</generator>'));
assert_same(Status::YELLOW, statuses($r)['version_leaks:/feed/'] ?? null, 'a version match in an incomplete feed still counts');

// spec-8 / quality-6: unquoted attributes count.
$GLOBALS['stub']['routes'] = ['https://ex.test/robots.txt' => ['status' => 404], 'https://ex.test/' => $page(),
    'https://ex.test/u1/' => $html('<html><head><meta name=robots content=noindex></head></html>'),
    'https://ex.test/u2/' => $html('<html><head><link rel=canonical href=https://other.test/u2/></head></html>'),
    'https://ex.test/u3/' => $html("<html><head><META CONTENT='noindex,follow' NAME='robots'></head></html>")];
$s = statuses(Catalogue::grade('indexability', OutsideChecks::observe('indexability', ctx('live', ['/u1/', '/u2/', '/u3/']), []), ctx()));
assert_same(Status::RED, $s['indexability:/u1/'], 'unquoted noindex → Rot');
assert_same(Status::RED, $s['indexability:/u2/'], 'unquoted foreign canonical → Rot');
assert_same(Status::RED, $s['indexability:/u3/'], 'upper-case attributes in any order → Rot');

// spec-9 / spec-10 / spec-11 / quality-7 / quality-9: usernames_public evidence.
$GLOBALS['stub']['users'] = [user(2, 'maxm', 'Max M.')];
$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 401]];
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => [401, 'auth']);
assert_true($r['status'] !== Status::GREEN, 'every source 401 → never Grün');
assert_same(Status::UNKNOWN, statuses($r)['usernames_public:rest'] ?? null, '401 → source unknown');
assert_same(Status::UNKNOWN, statuses($r)['usernames_public:author_redirect'] ?? null, '?author=1 401 → unknown');
put(ABSPATH . 'feed/index.php', "<?php echo 'x';");
$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 404]];
[, $r, $plan] = run_browser('usernames_public', ctx(), static fn($u) => [404, 'nf']);
assert_true(in_array('/feed/', array_column($plan['skipped'], 'target'), true), 'fixture: the feed is refused by the directory guard');
assert_same(Status::UNKNOWN, statuses($r)['usernames_public:feed'] ?? null, 'a skipped username source stays Nicht prüfbar');
assert_true(strpos($label_of($r, 'usernames_public:feed'), 'PHP index file') !== false, 'with its reason');
[, $r] = run_browser('version_leaks', ctx(), static fn($u) => [200, '<html><head><title>Home</title></head></html>']);
assert_same(Status::UNKNOWN, statuses($r)['version_leaks:/feed/'] ?? null, 'version_leaks: the skipped feed stays Nicht prüfbar');
unlink(ABSPATH . 'feed/index.php');
rmdir(ABSPATH . 'feed');
$GLOBALS['wp_rewrite'] = (object) ['author_base' => 'autor'];
$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 301, 'headers' => ['location' => 'https://ex.test/autor/maxm/']]];
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => [404, 'nf']);
assert_same(Status::YELLOW, statuses($r)['usernames_public:user-2'] ?? null, 'a custom author base still reveals the login');
unset($GLOBALS['wp_rewrite']);
$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<html>catch-all</html>'], $cmp('usernames_public') => ['status' => 200, 'body' => 'catch-all']];
$GLOBALS['stub']['requests'] = [];
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => [404, 'nf']);
assert_true(in_array($cmp('usernames_public'), requested_urls(), true), '?author=1 has its own Loopback comparison');
assert_same(Status::UNKNOWN, statuses($r)['usernames_public:author_redirect'] ?? null, 'server-side catch-all (Loopback comparison 200) → ?author=1 unknown, though the browser comparison was 404');
$GLOBALS['stub']['users'] = [];

// quality-11: listing markers on a catch-all site prove nothing.
[, $r] = run_browser('dir_listing', ctx(), static fn($u) => [200, $listing], [200, $listing]);
assert_true(!in_array(Status::YELLOW, array_values(statuses($r)), true), 'dir_listing: the same listing at the comparison URL → no Gelb');
assert_same(Status::UNKNOWN, statuses($r)['dir_listing:/wp-content/uploads/'] ?? null, 'dir_listing: catch-all listing → Nicht prüfbar');

// ================================================================ Gate B pass 3

// HTML metadata through DOM: comments and script text are no markup; no tag-count limit; entities decoded.
$many = str_repeat('<meta name="x" content="y">', 250);
$GLOBALS['stub']['routes'] = ['https://ex.test/robots.txt' => ['status' => 404], 'https://ex.test/' => $page(),
    'https://ex.test/c1/' => $html('<html><head><!-- <meta name=robots content=noindex> --><title>t</title></head></html>'),
    'https://ex.test/c2/' => $html('<html><head>' . $many . '<meta name="robots" content="noindex"></head></html>'),
    'https://ex.test/c3/' => $html('<html><head><script>var s = "<meta name=robots content=noindex>";</script></head></html>'),
    'https://ex.test/c4/' => $html('<html><head><meta name="robots" content="no&#105;ndex"></head></html>'),
    'https://ex.test/c5/' => $html('<html><head><!-- <link rel=canonical href=https://other.test/> --></head></html>')];
$s = statuses(Catalogue::grade('indexability', OutsideChecks::observe('indexability', ctx('live', ['/c1/', '/c2/', '/c3/', '/c4/', '/c5/']), []), ctx()));
assert_same(Status::GREEN, $s['indexability:/c1/'], 'a commented-out noindex is no noindex');
assert_same(Status::RED, $s['indexability:/c2/'], 'noindex after 250 other meta tags is still seen');
assert_same(Status::GREEN, $s['indexability:/c3/'], 'a noindex inside script text is no markup');
assert_same(Status::RED, $s['indexability:/c4/'], 'an entity-encoded noindex is decoded');
assert_same(Status::GREEN, $s['indexability:/c5/'], 'a commented-out canonical is no canonical');

$GLOBALS['wp_version'] = '6.8.3';
$home_only = static fn(string $head) => static fn($u) => strpos($u, '/feed/') !== false ? [404, 'nf'] : [200, '<html><head>' . $head . '</head></html>'];
foreach ([
    '<meta name=generator content=WordPress&#32;6.8.3>' => Status::YELLOW,
    '<META NAME="Generator" CONTENT="WordPress 6.8.3">' => Status::YELLOW,
    '<meta name="generator-plugin" content="WordPress 6.8.3">' => Status::GREEN,
    '<meta data-name="generator" content="WordPress 6.8.3">' => Status::GREEN,
    '<!-- <meta name="generator" content="WordPress 6.8.3"> -->' => Status::GREEN,
] as $head => $want) {
    [, $r] = run_browser('version_leaks', ctx(), $home_only($head));
    assert_same($want, statuses($r)['version_leaks:/'] ?? null, "generator: {$head}");
}

// Rule 4 before status-only verdicts: an empty or truncated "closed"/"blocked" answer proves nothing.
$GLOBALS['stub']['users'] = [user(2, 'maxm', 'Max M.')];
$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 404, 'body' => '']];
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => [404, '']);
assert_true($r['status'] !== Status::GREEN, 'usernames: empty 404 answers → never Grün');
assert_same(Status::UNKNOWN, statuses($r)['usernames_public:author_redirect'] ?? null, '?author=1 empty 404 → unknown');
$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 404]];
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => [403, 'Forbidden', ['truncated' => true]]);
assert_same(Status::UNKNOWN, statuses($r)['usernames_public:rest'] ?? null, 'usernames: truncated 403 → unknown');
$GLOBALS['stub']['users'] = [];
$GLOBALS['stub']['routes'] = ['POST https://ex.test/xmlrpc.php' => ['status' => 403, 'body' => '']];
assert_same(Status::UNKNOWN, Catalogue::grade('xmlrpc', OutsideChecks::observe('xmlrpc', ctx(), []), ctx())['status'], 'xmlrpc: empty 403 → Nicht prüfbar');

// Robots: an Allow that never wins does not reopen the site (all profiles).
foreach ([['live', Status::RED], ['staging', Status::GREEN], ['private', Status::GREEN]] as [$profile, $want]) {
    [, $r] = run_browser('robots_txt', ctx($profile), static fn($u) => [200, "User-agent: *\nDisallow: /\nAllow: /public/\nDisallow: /public/*\n"]);
    assert_same($want, $r['status'], "robots_txt {$profile}: an overridden Allow → everything disallowed");
}

// public_files: skipped targets keep their reasons; the installer stays listed.
stub_reset(['siteurl' => 'https://wp.other.test', 'options' => ['home' => 'https://ex.test', 'siteurl' => 'https://wp.other.test']]);
[$facts, $r] = run_browser('public_files', ctx(), static fn($u) => [404, 'nf']);
$labels = array_column($r['findings'], 'label', 'id');
assert_true(isset($labels['public_files:/wp-admin/install.php']), 'the installer is listed although it was not fetched');
foreach ($labels as $id => $label) {
    assert_true(strpos($label, 'other host') !== false, "{$id}: the refusal reason is shown");
}
stub_reset();

// Directory guard: location prefixes are decoded once too (an encoded uploads base URL).
$media = dirname(ABSPATH) . '/media files';
put($media . '/custom-map/index.php', "<?php echo 'ran';");
$GLOBALS['stub']['upload'] = ['basedir' => $media, 'baseurl' => 'https://ex.test/media%20files', 'error' => false];
assert_same('dir', Fetch::refusal('https://ex.test/media%20files/custom-map/'), 'an encoded uploads prefix maps to the real uploads folder → its unknown index is seen');
$GLOBALS['stub']['routes'] = ['https://ex.test/robots.txt' => ['status' => 200, 'body' => "User-agent: *\nDisallow:\nSitemap: https://ex.test/media%20files/custom-map/\n"]];
$GLOBALS['stub']['requests'] = [];
run_loopback('sitemap', ctx());
assert_true(!in_array('https://ex.test/media%20files/custom-map/', requested_urls(), true), 'sitemap discovery never requests it');
unset($GLOBALS['stub']['upload']);
remove_tree($media);

// ================================================================ Gate B pass 4

// BLOCKER: a link whose target cannot be inspected is unknown, never absent — refused at the request boundary.
if (!$as_root) {
    $locked = dirname(ABSPATH) . '/locked';
    put($locked . '/map/index.php', "<?php echo 'ran';");
    symlink($locked . '/map', WP_CONTENT_DIR . '/uploads/linked-map');
    symlink(dirname(ABSPATH) . '/nowhere', WP_CONTENT_DIR . '/uploads/dangling');
    chmod($locked, 0000);
    $GLOBALS['stub']['requests'] = [];
    $results = [
        Fetch::refusal('https://ex.test/wp-content/uploads/linked-map/'),
        Fetch::refusal('https://ex.test/wp-content/uploads/linked-map/sub/'),
        Fetch::refusal('https://ex.test/wp-content/uploads/dangling/'),
    ];
    Fetch::loopback('GET', 'https://ex.test/wp-content/uploads/linked-map/');
    chmod($locked, 0700);
    assert_same(['dir_unreadable', 'dir_unreadable', 'dir_unreadable'], $results, 'link to an unsearchable target, a path through it, and a dangling link → refused');
    assert_same([], requested_urls(), 'nothing is sent to the linked folder');
    unlink(WP_CONTENT_DIR . '/uploads/linked-map');
    unlink(WP_CONTENT_DIR . '/uploads/dangling');
    remove_tree($locked);
}

// Framing: comma-separated CSP policies; any enforcing frame-ancestors decides.
$s = $hdr(['content-security-policy' => "default-src 'self', frame-ancestors *", 'x-frame-options' => 'DENY']);
assert_same(Status::YELLOW, $s['security_headers:framing'], 'frame-ancestors * in a second policy → Gelb, X-Frame-Options ignored');
$s = $hdr(['content-security-policy' => "frame-ancestors 'none', default-src 'self'"]);
assert_same(Status::GREEN, $s['security_headers:framing'], "frame-ancestors 'none' in the first of two policies → Grün");
$s = $hdr(['content-security-policy' => "frame-ancestors 'self', frame-ancestors *"]);
assert_same(Status::YELLOW, $s['security_headers:framing'], 'one permissive policy among several → Gelb');

// usernames_public: source formats and Atom authors.
$GLOBALS['stub']['users'] = [user(2, 'maxm', 'Max M.'), user(7, 'writer', 'W')];
$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 404]];
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => strpos($u, 'oembed') !== false ? [200, '{"error":"temporarily unavailable"}'] : [404, 'nf']);
assert_same(Status::UNKNOWN, statuses($r)['usernames_public:oembed'] ?? null, 'error-shaped JSON at oEmbed → unknown');
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => strpos($u, 'oembed') !== false ? [200, '{"version":"1.0","type":"rich","author_name":"Max M."}'] : [404, 'nf']);
assert_same(Status::GREEN, $r['status'], 'a real oEmbed answer → read');
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => strpos($u, 'wp/v2/users') !== false ? [200, '[{"foo":1}]'] : [404, 'nf']);
assert_same(Status::UNKNOWN, statuses($r)['usernames_public:rest'] ?? null, 'a list of arbitrary objects is no user list → unknown');
$atom = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><entry><author><name><![CDATA[writer]]></name></author></entry><entry><author><name>m&#97;xm</name></author></entry></feed>';
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => strpos($u, '/feed/') !== false ? [200, $atom] : [404, 'nf']);
assert_same(Status::YELLOW, statuses($r)['usernames_public:user-7'] ?? null, 'Atom author/name (CDATA) equal to a login → Gelb');
assert_same(Status::YELLOW, statuses($r)['usernames_public:user-2'] ?? null, 'Atom author/name (entity-encoded) equal to a login → Gelb');
$GLOBALS['stub']['users'] = [];

// ================================================================ Gate B pass 5

// Comparison URLs pass the same request guard; a refused comparison leaves soft-404 evidence unknown.
$cmp_dir = ABSPATH . basename((string) parse_url($cmp('security_headers'), PHP_URL_PATH));
put($cmp_dir . '/index.php', "<?php echo 'ran';");
$plan = OutsideChecks::targets('security_headers', ctx());
assert_same(null, $plan['comparison'], 'a comparison URL that is an unsafe folder is not handed to the browser');
assert_true(in_array(OutsideChecks::comparison_url('security_headers', ctx()), array_column($plan['skipped'], 'target'), true) || $plan['skipped'] !== [], 'the refused comparison is named');
[, $r] = run_browser('security_headers', ctx(), static fn($u) => [200, '<html></html>', ['headers' => ['x-frame-options' => 'DENY']]]);
assert_same(Status::UNKNOWN, $r['status'], 'without a comparison the 200 home page proves nothing → Nicht prüfbar');
remove_tree($cmp_dir);
$cmp_dir = ABSPATH . basename((string) parse_url($cmp('xmlrpc'), PHP_URL_PATH));
put($cmp_dir . '/index.php', "<?php echo 'ran';");
$GLOBALS['stub']['requests'] = [];
$GLOBALS['stub']['routes'] = ['POST https://ex.test/xmlrpc.php' => ['status' => 200, 'body' => $methods]];
$r = Catalogue::grade('xmlrpc', OutsideChecks::observe('xmlrpc', ctx(), []), ctx());
assert_true(!in_array($cmp('xmlrpc'), requested_urls(), true), 'a Loopback comparison URL that is an unsafe folder is not requested');
assert_same(Status::UNKNOWN, $r['status'], 'xmlrpc without a usable comparison → Nicht prüfbar');
remove_tree($cmp_dir);

// XML-RPC on a soft-404 site.
$GLOBALS['stub']['routes'] = ['POST https://ex.test/xmlrpc.php' => ['status' => 200, 'body' => $methods], $cmp('xmlrpc') => ['status' => 200, 'body' => 'soft']];
assert_same(Status::UNKNOWN, Catalogue::grade('xmlrpc', OutsideChecks::observe('xmlrpc', ctx(), []), ctx())['status'], 'xmlrpc 200 with a 200 comparison → Nicht prüfbar');

// Indexability: a valid configured path the request guard refuses is Nicht prüfbar, not Hinweis.
put(WP_CONTENT_DIR . '/uploads/unsafe-path/index.php', "<?php echo 'ran';");
$GLOBALS['stub']['routes'] = ['https://ex.test/robots.txt' => ['status' => 404], 'https://ex.test/' => $page()];
$s = statuses(Catalogue::grade('indexability', OutsideChecks::observe('indexability', ctx('live', ['/wp-content/uploads/unsafe-path/', '/bad?x']), []), ctx('live', ['/wp-content/uploads/unsafe-path/', '/bad?x'])));
assert_same(Status::UNKNOWN, $s['indexability:/wp-content/uploads/unsafe-path/'] ?? null, 'refused valid path → Nicht prüfbar');
assert_same(Status::HINT, $s['indexability:/bad?x'] ?? null, 'invalid configured path stays Hinweis');
remove_tree(WP_CONTENT_DIR . '/uploads/unsafe-path');

// Sitemap: an empty or truncated blocking answer is no evidence of absence.
foreach ([['status' => 404, 'body' => ''], ['status' => 403, 'body' => 'x', 'truncated' => true]] as $answer) {
    $routes = [];
    foreach (['https://ex.test/wp-sitemap.xml', 'https://ex.test/sitemap_index.xml', 'https://ex.test/sitemap.xml'] as $u) {
        $routes[$u] = isset($answer['truncated']) ? ['status' => 403, 'body' => str_repeat('x', 70000)] : $answer;
    }
    $GLOBALS['stub']['routes'] = $routes;
    [, $r] = run_loopback('sitemap', ctx());
    assert_same(Status::UNKNOWN, $r['status'], 'sitemap: ' . (isset($answer['truncated']) ? 'truncated 403' : 'empty 404') . ' everywhere → Nicht prüfbar');
}

// Namespace-prefixed sitemaps are read with the XML parser.
$ns = '<?xml version="1.0"?><sm:urlset xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9"><sm:url><sm:loc>https://ex.test/author/admin/</sm:loc></sm:url></sm:urlset>';
$GLOBALS['stub']['routes'] = ['https://ex.test/sitemap.xml' => ['status' => 200, 'body' => $ns]];
[$facts, $r] = run_loopback('sitemap_entries', ctx());
assert_same(Status::YELLOW, statuses($r)['sitemap_entries:authors'] ?? null, 'sm:loc author entry → Gelb');
$nsi = '<?xml version="1.0"?><sm:sitemapindex xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9"><sm:sitemap><sm:loc>https://ex.test/wp-sitemap-posts-post-1.xml</sm:loc></sm:sitemap></sm:sitemapindex>';
$GLOBALS['stub']['routes'] = ['https://ex.test/wp-sitemap.xml' => ['status' => 200, 'body' => $nsi], 'https://ex.test/wp-sitemap-posts-post-1.xml' => ['status' => 200, 'body' => $urlset(['https://ex.test/hello/'])]];
[$facts] = run_loopback('sitemap_entries', ctx());
assert_same([1, 1], [$facts['checked'], $facts['total']], 'a prefixed sitemap index lists its child');

// ================================================================ Gate B pass 6

// Feed generator through the XML parser: namespaces, CDATA, entities; comments never count.
$GLOBALS['wp_version'] = '6.8.3';
$feed_only = static fn(string $feed) => static fn($u) => strpos($u, '/feed/') !== false ? [200, $feed] : [200, '<html><head><title>H</title></head></html>'];
foreach ([
    '<?xml version="1.0"?><a:feed xmlns:a="http://www.w3.org/2005/Atom"><a:generator uri="https://wordpress.org/" version="6.8.3">WordPress</a:generator></a:feed>' => [Status::YELLOW, 'prefixed Atom generator'],
    '<?xml version="1.0"?><rss version="2.0"><channel><generator><![CDATA[https://wordpress.org/?v=6.8.3]]></generator></channel></rss>' => [Status::YELLOW, 'CDATA generator'],
    '<?xml version="1.0"?><rss version="2.0"><channel><generator>https://wordpress.org/?v=6&#46;8&#46;3</generator></channel></rss>' => [Status::YELLOW, 'entity-encoded generator'],
    '<?xml version="1.0"?><rss version="2.0"><channel><!-- <generator>https://wordpress.org/?v=6.8.3</generator> --><title>x</title></channel></rss>' => [Status::GREEN, 'commented generator'],
    '<?xml version="1.0"?><rss version="2.0"><channel><item><generator>https://wordpress.org/?v=6.8.3</generator></item></channel></rss>' => [Status::GREEN, 'a generator that is not the channel\'s own'],
] as $feed => [$want, $what]) {
    [, $r] = run_browser('version_leaks', ctx(), $feed_only($feed));
    assert_same($want, statuses($r)['version_leaks:/feed/'] ?? null, "feed: {$what}");
}

// HSTS: includeSubDomains and preload take no value.
foreach (['max-age=31536000; includeSubDomains=foo', 'max-age=31536000; includeSubDomains="yes"', 'max-age=31536000; includeSubDomains=""', 'max-age=31536000; preload=1'] as $value) {
    assert_same(Status::YELLOW, $hdr(['strict-transport-security' => $value])['security_headers:strict-transport-security'], "HSTS {$value} → Gelb");
}
assert_same(Status::GREEN, $hdr(['strict-transport-security' => 'max-age=31536000; includeSubDomains; preload'])['security_headers:strict-transport-security'], 'valueless includeSubDomains and preload → Grün');

// sitemap_entries: no cap on the entry types.
$types = array_map(static fn($i) => 'post_type:t' . $i, range(1, 30));
$entries = array_map(static fn($i) => 'https://ex.test/?post_type=t' . $i, range(1, 30));
$entries[] = 'https://ex.test/template/header/';
$GLOBALS['stub']['routes'] = ['https://ex.test/sitemap.xml' => ['status' => 200, 'body' => $urlset($entries)]];
$facts = OutsideChecks::observe('sitemap_entries', ctx(), []);
$r = Catalogue::grade('sitemap_entries', $facts, ctx('live', [], $types));
assert_same(Status::YELLOW, statuses($r)['sitemap_entries:post_type:bricks_template'] ?? null, 'a problematic type after 30 others is still graded');

// ================================================================ Gate B pass 7

// robots.txt 404/410: rule 4 first — only a complete, non-empty error page means "no robots.txt".
foreach ([[404, ''], [410, ''], [404, str_repeat('x', 65537)]] as [$code, $body]) {
    [, $r] = run_browser('robots_txt', ctx(), static fn($u) => [$code, $body, ['truncated' => strlen($body) > 65536]]);
    assert_same(Status::UNKNOWN, $r['status'], "robots_txt: {$code} " . ($body === '' ? 'empty' : 'truncated') . ' → Nicht prüfbar');
}
$GLOBALS['stub']['routes'] = ['https://ex.test/robots.txt' => ['status' => 404, 'body' => ''], 'https://ex.test/' => $page()];
$s = statuses(Catalogue::grade('indexability', OutsideChecks::observe('indexability', ctx(), []), ctx()));
assert_same(Status::UNKNOWN, $s['indexability:/'], 'indexability: an empty 404 robots.txt is not "read"');
[, $r] = run_browser('robots_txt', ctx(), static fn($u) => [404, '<html>Not found</html>']);
assert_same(Status::GREEN, $r['status'], 'a complete 404 error page → no robots.txt → Grün (Live)');

// Sitemap skipped addresses: every refused address is listed.
$foreign = array_map(static fn($i) => 'https://cdn.test/s' . $i . '.xml', range(1, 7));
$GLOBALS['stub']['routes'] = ['https://ex.test/wp-sitemap.xml' => ['status' => 200, 'body' => $index($foreign)]];
[$facts, $r] = run_loopback('sitemap_entries', ctx());
foreach ($foreign as $u) {
    assert_true(strpos(implode(' ', array_column($r['findings'], 'label')), $u) !== false, "skipped address named: {$u}");
}

// Feed usernames through the XML parser: prefixed Atom authors, attributed dc:creator.
$GLOBALS['stub']['users'] = [user(8, 'alice', 'A'), user(9, 'bob', 'B')];
$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 404]];
$feeds = [
    '<?xml version="1.0"?><a:feed xmlns:a="http://www.w3.org/2005/Atom"><a:entry><a:author><a:name>alice</a:name></a:author></a:entry></a:feed>' => 8,
    '<?xml version="1.0"?><rss xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><item><dc:creator xml:lang="en">bob</dc:creator></item></channel></rss>' => 9,
];
foreach ($feeds as $feed => $user_id) {
    [, $r] = run_browser('usernames_public', ctx(), static fn($u) => strpos($u, '/feed/') !== false ? [200, $feed] : [404, 'nf']);
    assert_same(Status::YELLOW, statuses($r)['usernames_public:user-' . $user_id] ?? null, "feed author found for user {$user_id}");
}
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => strpos($u, '/feed/') !== false ? [200, '<rss><channel><!-- <dc:creator>alice</dc:creator> --></channel></rss>'] : [404, 'nf']);
assert_true(!isset(statuses($r)['usernames_public:user-8']), 'a commented creator is no author');
$GLOBALS['stub']['users'] = [];

// ================================================================ Gate B pass 8

// Feed 404/410: rule 4 first — only a complete, non-empty error page means "feed not present".
foreach ([[404, '', false], [410, str_repeat('x', 100), true]] as [$code, $body, $cut]) {
    [, $r] = run_browser('version_leaks', ctx(), static fn($u) => strpos($u, '/feed/') !== false ? [$code, $body, ['truncated' => $cut]] : [200, '<html><head><title>H</title></head></html>']);
    assert_same(Status::UNKNOWN, statuses($r)['version_leaks:/feed/'] ?? null, "feed {$code} " . ($cut ? 'truncated' : 'empty') . ' → Nicht prüfbar');
}
[, $r] = run_browser('version_leaks', ctx(), static fn($u) => strpos($u, '/feed/') !== false ? [404, '<html>Not found</html>'] : [200, '<html><head><title>H</title></head></html>']);
assert_same(Status::GREEN, statuses($r)['version_leaks:/feed/'] ?? null, 'a complete 404 page → feed not present → Grün');

// oEmbed with default JSON escaping: author_url's slug is read from the decoded JSON.
$GLOBALS['stub']['users'] = [user(8, 'alice', 'A')];
$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 404]];
$oembed = json_encode(['version' => '1.0', 'type' => 'rich', 'author_name' => 'A', 'author_url' => 'https://ex.test/author/alice/']);
assert_true(strpos($oembed, '\/') !== false, 'fixture: default json_encode escapes slashes');
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => strpos($u, 'oembed') !== false ? [200, $oembed] : [404, 'nf']);
assert_same(Status::YELLOW, statuses($r)['usernames_public:user-8'] ?? null, 'oEmbed author_url slug (escaped JSON) → Gelb');
// No 200-name cap: a login after 200 other names is still found.
$slugs = array_map(static fn($i) => 'https://ex.test/author/someone' . $i . '/', range(1, 200));
$slugs[] = 'https://ex.test/author/alice/';
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => strpos($u, 'wp-sitemap-users-1.xml') !== false ? [200, $urlset($slugs)] : [404, 'nf']);
assert_same(Status::YELLOW, statuses($r)['usernames_public:user-8'] ?? null, 'the 201st name is compared too');
$GLOBALS['stub']['users'] = [];

// Sitemap discovery: every Sitemap: line is guarded; refused addresses are findings of their own, and discovery goes on past them.
$lines = implode('', array_map(static fn($i) => "Sitemap: https://cdn.test/s{$i}.xml\n", range(1, 12)));
$GLOBALS['stub']['routes'] = [
    'https://ex.test/robots.txt' => ['status' => 200, 'body' => "User-agent: *\nDisallow:\n" . $lines . "Sitemap: https://ex.test/late-map.xml\n"],
    'https://ex.test/late-map.xml' => ['status' => 200, 'body' => $urlset(['https://ex.test/a/'])],
];
[$facts, $r] = run_loopback('sitemap', ctx());
assert_same('/late-map.xml', $facts['found'], 'a sitemap after twelve refused addresses is still found');
$s = statuses($r);
foreach (range(1, 12) as $i) {
    assert_true(isset($s['sitemap:skipped:https://cdn.test/s' . $i . '.xml']), "refused address {$i} is its own finding");
}
assert_same(Status::GREEN, $s['sitemap:/late-map.xml'] ?? null, 'the found sitemap stays Grün');
$GLOBALS['stub']['routes'] = ['https://ex.test/robots.txt' => ['status' => 200, 'body' => "User-agent: *\nDisallow:\n" . $lines]];
[, $r] = run_loopback('sitemap', ctx());
assert_same(Status::UNKNOWN, $r['status'], 'nothing found but addresses refused → Nicht prüfbar, not "no sitemap"');

// ================================================================ Gate B pass 9

// No cap on confirmed matches: all are graded (the saved run's compaction handles display).
$GLOBALS['stub']['users'] = array_map(static fn($i) => user($i, 'login' . $i, 'U' . $i), range(1, 25));
$GLOBALS['stub']['routes'] = ['https://ex.test/?author=1' => ['status' => 404]];
$rest = json_encode(array_map(static fn($i) => ['id' => $i, 'slug' => 'login' . $i], range(1, 25)));
[$facts, $r] = run_browser('usernames_public', ctx(), static fn($u) => strpos($u, 'wp/v2/users') !== false ? [200, $rest] : [404, 'nf']);
assert_same(25, count($facts['matches']), 'all 25 matches are kept in the facts');
assert_same(Status::YELLOW, statuses($r)['usernames_public:user-25'] ?? null, 'the 25th match is graded');
// REST must be a JSON list of objects with an integer id and a string slug or name.
$GLOBALS['stub']['users'] = [];
foreach (['{}', '[{"id":1,"slug":[]}]', '[{"id":"1","slug":"a"}]', '[[1,2]]'] as $bad) {
    [, $r] = run_browser('usernames_public', ctx(), static fn($u) => strpos($u, 'wp/v2/users') !== false ? [200, $bad] : [404, 'nf']);
    assert_same(Status::UNKNOWN, statuses($r)['usernames_public:rest'] ?? null, "REST {$bad} → unknown");
}
[, $r] = run_browser('usernames_public', ctx(), static fn($u) => strpos($u, 'wp/v2/users') !== false ? [200, '[]'] : [404, 'nf']);
assert_same(Status::GREEN, $r['status'], 'an empty JSON list stays a valid answer');

echo "site-check outside checks: ok\n";
