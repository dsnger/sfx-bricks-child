<?php

declare(strict_types=1);

/**
 * One live run of the Sicherheits-Check against the REAL local site: the
 * server checks and the uploads probe as an administrator, then every
 * endpoint and the dashboard box as an editor (who must be refused).
 *
 * MANUAL. quality.sh does not run this. Run with MAMP's PHP (AGENTS.md § Local PHP):
 *
 *   /Applications/MAMP/bin/php/php8.5.2/bin/php tests/support/site-check-live-check.php
 *
 * Isolation, per the AGENTS.md harness rule. In this order:
 *   1. fatal guards on where this runs — they write nothing;
 *   2. boot WordPress, assert it is this site, switch every SiteCheck option
 *      and cron hook name to a random `sfx_site_check_h<random>_` prefix
 *      (Options::use_harness_prefix) so the real options are never written;
 *   3. snapshot the real state (real sfx_site_check_* rows, real
 *      uploads/sfx-site-check/ folder, cron option, the Redirects settings
 *      row and the Redirects tables' checksums);
 *   4. register the ONE shutdown teardown;
 *   5. only then change anything: Redirects' 404 logging is switched off (the
 *      outside fetches are real requests to this site and would log 404s);
 *      the teardown restores the settings row byte for byte and checks the
 *      Redirects tables are unchanged. Then the first fixture: a fresh uploads/sfx-site-check-harness-<random>/
 *      folder, which an `upload_dir` filter makes the uploads folder for this
 *      process. The probe therefore writes and fetches inside it.
 *
 * Teardown removes that folder and the prefixed rows. It never calls
 * Probe::cleanup()/teardown() or DataPurge::run(), and removes no probe entry
 * that does not carry a run ID issued by this process. It then compares the
 * real state with the snapshot.
 *
 * Exit: 0 all passed, 1 a check failed, 2 guard error, 3 teardown left state
 * behind or real state changed.
 */

const WP_LOAD   = '/Users/daniel/DEVELOPMENT/LOCALHOST/sfx-bricks-child.local/wp-load.php';
const THEME_DIR = '/Users/daniel/DEVELOPMENT/LOCALHOST/sfx-bricks-child.local/wp-content/themes/sfx-bricks-child';
const SITE_ROOT = '/Users/daniel/DEVELOPMENT/LOCALHOST/sfx-bricks-child.local/';
const SITE_HOST = 'sfx-bricks-child.local';

function fatal(string $message): void
{
    fwrite(STDERR, "error: {$message}\n");
    exit(2);
}

// ---------------------------------------------------------------- fatal guards (write nothing)

if (realpath(__DIR__ . '/../..') !== THEME_DIR) {
    fatal('this script must run from ' . THEME_DIR . ', found ' . var_export(realpath(__DIR__ . '/../..'), true) . '.');
}
if (!is_file(WP_LOAD)) {
    fatal(WP_LOAD . ' not found.');
}

define('WP_USE_THEMES', false);
// A spawned WP-Cron run would change the cron option while the harness compares it.
if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}
require WP_LOAD;

// ABSPATH must be this site's, whatever WP_LOAD says.
if (!defined('ABSPATH') || realpath(ABSPATH) !== realpath(SITE_ROOT)) {
    fatal('ABSPATH is ' . (defined('ABSPATH') ? ABSPATH : 'undefined') . ', not ' . SITE_ROOT . '. Refusing to run anywhere else.');
}
if (wp_parse_url(home_url(), PHP_URL_HOST) !== SITE_HOST) {
    fatal('home_url() host is not ' . SITE_HOST . '. Refusing to run anywhere else.');
}

use SFX\SiteCheck\Access;
use SFX\SiteCheck\Catalogue;
use SFX\SiteCheck\Checks\OutsideChecks;
use SFX\SiteCheck\DashboardBox;
use SFX\SiteCheck\Options;
use SFX\SiteCheck\OutsideEndpoints;
use SFX\SiteCheck\Runs;
use SFX\SiteCheck\Status;

if (!class_exists(Runs::class)) {
    require_once THEME_DIR . '/vendor/autoload.php';
}
foreach ([Runs::class, Options::class, OutsideEndpoints::class, DashboardBox::class, Access::class] as $class) {
    $file = class_exists($class) ? (new ReflectionClass($class))->getFileName() : false;
    if (!is_string($file) || strpos($file, THEME_DIR . '/inc/SiteCheck/') !== 0) {
        fatal("{$class} is not loaded from this checkout (got " . var_export($file, true) . ').');
    }
}

global $wpdb;

$prefix = 'sfx_site_check_h' . bin2hex(random_bytes(6)) . '_';
Options::use_harness_prefix($prefix);
if (Options::name('probes') !== $prefix . 'probes' || Options::hook('probe_cleanup') !== $prefix . 'probe_cleanup') {
    fatal('the harness prefix is not in effect; refusing to write.');
}

// ---------------------------------------------------------------- state helpers

/** @return array<string,array{0:string,1:string}> every option row whose name starts with $prefix */
function rows_with_prefix(string $prefix): array
{
    global $wpdb;
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
        $wpdb->esc_like($prefix) . '%'
    ));
    if ($wpdb->last_error !== '') {
        throw new RuntimeException("listing {$prefix}* failed: {$wpdb->last_error}");
    }
    $out = [];
    foreach ($rows as $row) {
        $out[$row->option_name] = [$row->option_value, $row->autoload];
    }
    return $out;
}

/** Name, size, mtime and hash of every entry below $dir; 'absent' when there is none. */
function folder_state(string $dir)
{
    if (!file_exists($dir)) {
        return 'absent';
    }
    $state = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $info) {
        $relative = substr($info->getPathname(), strlen($dir));
        $state[$relative] = $info->isFile() ? [$info->getSize(), $info->getMTime(), md5_file($info->getPathname())] : 'dir';
    }
    ksort($state);
    return $state;
}

function remove_tree(string $dir): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $info) {
        $info->isDir() && !$info->isLink() ? rmdir($info->getPathname()) : unlink($info->getPathname());
    }
    rmdir($dir);
}

// The real uploads folder, read BEFORE any filter exists.
$real_uploads = wp_get_upload_dir();
if (!is_array($real_uploads) || empty($real_uploads['basedir']) || !empty($real_uploads['error'])) {
    fatal('the real uploads folder could not be determined.');
}
$real_basedir = rtrim((string) $real_uploads['basedir'], '/');
if (strpos(realpath($real_basedir) . '/', realpath(SITE_ROOT) . '/') !== 0) {
    fatal("uploads folder {$real_basedir} is outside the site root.");
}
$harness_name = 'sfx-site-check-harness-' . bin2hex(random_bytes(6));
$harness_dir = $real_basedir . '/' . $harness_name;
if (file_exists($harness_dir) || rows_with_prefix($prefix) !== []) {
    fatal('the fresh harness folder or prefix already exists.');
}

function real_state(string $basedir): array
{
    return [
        'options' => rows_with_prefix(Options::DEFAULT_PREFIX),
        'cron' => rows_with_prefix('cron')['cron'] ?? null,
        'probe_folder' => folder_state($basedir . '/sfx-site-check'),
    ];
}

/** Checksums of the Redirects tables: the harness's loopback requests must not change them. */
function redirects_tables(): array
{
    global $wpdb;
    $out = [];
    foreach (['sfx_redirects', 'sfx_redirects_404'] as $table) {
        $wpdb->last_error = '';
        $row = $wpdb->get_row('CHECKSUM TABLE ' . $wpdb->prefix . $table . ' EXTENDED', ARRAY_N);
        if ($wpdb->last_error !== '') {
            throw new RuntimeException("checksum of {$table} failed: {$wpdb->last_error}");
        }
        $out[$table] = $row[1] ?? null;
    }
    return $out;
}

/** The raw Redirects settings row (value, autoload), or null when absent. */
function redirects_option_row(): ?array
{
    global $wpdb;
    $wpdb->last_error = '';
    $row = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", 'sfx_redirects_options'), ARRAY_N);
    if ($wpdb->last_error !== '') {
        throw new RuntimeException("reading sfx_redirects_options failed: {$wpdb->last_error}");
    }
    return $row === null ? null : [$row[0], $row[1]];
}

// Redirects' 404 log: the comparison URLs and other outside fetches are real requests to this site.
// The original settings row and the tables are recorded here; the teardown restores the row and
// checks the tables. Logging is switched off only after the teardown is registered (below).
$redirects_row_before = redirects_option_row();
$redirects_tables_before = redirects_tables();
echo 'Redirects settings row before: ' . ($redirects_row_before === null ? 'absent' : 'present') . '; tables: ' . json_encode($redirects_tables_before) . "\n";

$run_ids = []; // every run ID this process issued
$real_before = real_state($real_basedir);
// The harness rows share the real prefix's leading characters? No: 'sfx_site_check_h...' differs from
// 'sfx_site_check_' only by what follows, so the real prefix LIKE also matches harness rows. Subtract them.
$strip = static fn(array $s): array => ['options' => array_diff_key($s['options'], rows_with_prefix($GLOBALS['prefix']))] + $s;
$real_before = $strip($real_before);
$real_probes_before = rows_with_prefix('sfx_site_check_probes')['sfx_site_check_probes'] ?? null;

echo "prefix: {$prefix}\n";
echo "harness folder: {$harness_dir}\n";
echo 'real sfx_site_check_* rows before: ' . count($real_before['options']) . "\n";
echo 'real uploads/sfx-site-check/ before: ' . ($real_before['probe_folder'] === 'absent' ? 'absent' : count($real_before['probe_folder']) . ' entries') . "\n";
echo 'real probes option before: ' . ($real_probes_before === null ? 'absent' : 'present') . "\n";

register_shutdown_function(static function () use ($prefix, $harness_dir, $harness_name, $real_basedir, $real_before, $real_probes_before, &$run_ids, $strip, $redirects_row_before, $redirects_tables_before): void {
    global $wpdb;
    $problems = [];
    // Redirects: restore the settings row exactly as it was, then check the tables are untouched.
    try {
        if ($redirects_row_before === null) {
            $wpdb->delete($wpdb->options, ['option_name' => 'sfx_redirects_options']);
        } else {
            $wpdb->update($wpdb->options, ['option_value' => $redirects_row_before[0], 'autoload' => $redirects_row_before[1]], ['option_name' => 'sfx_redirects_options']);
        }
        wp_cache_delete('sfx_redirects_options', 'options');
        wp_cache_delete('alloptions', 'options');
        $row_ok = redirects_option_row() === $redirects_row_before;
        $tables_ok = redirects_tables() === $redirects_tables_before;
        if (!$row_ok) {
            $problems[] = 'Redirects settings row not restored';
        }
        if (!$tables_ok) {
            $problems[] = 'Redirects tables changed';
        }
        echo 'teardown: Redirects settings row restored: ' . ($row_ok ? 'yes' : 'NO') . '; Redirects tables unchanged: ' . ($tables_ok ? 'yes' : 'NO') . "\n";
    } catch (Throwable $e) {
        $problems[] = 'Redirects restore error: ' . $e->getMessage();
    }
    $fatal = error_get_last();
    if ($fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, "FATAL: {$fatal['message']}\n");
    }
    try {
        // Only probe entries carrying a run ID of this process may be in the harness's probes option.
        $probes_row = rows_with_prefix($prefix . 'probes')[$prefix . 'probes'] ?? null;
        $foreign = 0;
        if ($probes_row !== null) {
            foreach ((array) maybe_unserialize($probes_row[0]) as $entry) {
                if (!is_array($entry) || !in_array($entry['run'] ?? null, $run_ids, true)) {
                    $foreign++;
                }
            }
        }
        if ($foreign > 0) {
            $problems[] = "{$foreign} probe entr(y/ies) in the harness option do not carry this process's run ID";
        }

        // The harness folder: guarded by name and location, then removed whole.
        $removed_folder = 'none';
        if (file_exists($harness_dir)) {
            if (strpos(basename($harness_dir), 'sfx-site-check-harness-') === 0 && dirname($harness_dir) === $real_basedir && !is_link($harness_dir)) {
                remove_tree($harness_dir);
                $removed_folder = 'removed';
            } else {
                $problems[] = 'harness folder failed its location guard; not removed';
            }
        }
        if (file_exists($harness_dir)) {
            $problems[] = 'harness folder still exists';
        }
        $strays = glob($real_basedir . '/sfx-site-check-harness-*') ?: [];
        $strays = array_filter($strays, static fn(string $p): bool => basename($p) === $harness_name);
        if ($strays !== []) {
            $problems[] = 'harness folder left behind';
        }

        $names = array_keys(rows_with_prefix($prefix));
        $wpdb->last_error = '';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . '%'));
        if ($wpdb->last_error !== '') {
            $problems[] = "delete failed: {$wpdb->last_error}";
        }
        foreach ($names as $name) {
            wp_cache_delete($name, 'options');
        }
        if (rows_with_prefix($prefix) !== []) {
            $problems[] = 'prefixed rows left behind';
        }

        $after = $strip(real_state($real_basedir));
        $probes_after = rows_with_prefix('sfx_site_check_probes')['sfx_site_check_probes'] ?? null;
        $same = ['options' => $after['options'] === $real_before['options'], 'cron' => $after['cron'] === $real_before['cron'], 'probe_folder' => $after['probe_folder'] === $real_before['probe_folder']];
        foreach (['options' => 'real sfx_site_check_* rows', 'cron' => 'cron option', 'probe_folder' => 'real uploads/sfx-site-check/ folder'] as $key => $what) {
            if (!$same[$key]) {
                $problems[] = "{$what} changed";
            }
        }
        if ($probes_after !== $real_probes_before) {
            $problems[] = 'real probes option changed';
        }
        echo "teardown: harness folder {$removed_folder}, " . count($names) . ' prefixed row(s) removed; real options, cron option, uploads/sfx-site-check/ and probes option unchanged: '
            . ($same === ['options' => true, 'cron' => true, 'probe_folder' => true] && $probes_after === $real_probes_before ? 'yes' : 'NO') . "\n";
    } catch (Throwable $e) {
        $problems[] = 'teardown error: ' . $e->getMessage();
    }
    if ($problems !== []) {
        fwrite(STDERR, 'TEARDOWN: ' . implode('; ', $problems) . "\n");
        exit(3);
    }
});

// ---------------------------------------------------------------- Redirects: no 404 logging while the harness fetches

$redirects_options = get_option('sfx_redirects_options', []);
update_option('sfx_redirects_options', array_merge(is_array($redirects_options) ? $redirects_options : [], ['log_404' => false]));
wp_cache_delete('sfx_redirects_options', 'options');
$check_off = get_option('sfx_redirects_options', []);
if (!is_array($check_off) || ($check_off['log_404'] ?? true) !== false) {
    fatal('could not switch Redirects 404 logging off; refusing to fetch.');
}
echo "Redirects 404 logging switched off for the run (restored by the teardown)\n";

// ---------------------------------------------------------------- first fixture: the harness uploads folder

if (!mkdir($harness_dir, 0755)) {
    fatal("could not create {$harness_dir}.");
}
add_filter('upload_dir', static function (array $dirs) use ($harness_name): array {
    $dirs['basedir'] = $dirs['basedir'] . '/' . $harness_name;
    $dirs['baseurl'] = $dirs['baseurl'] . '/' . $harness_name;
    $dirs['path']    = $dirs['basedir'];
    $dirs['url']     = $dirs['baseurl'];
    $dirs['subdir']  = '';
    return $dirs;
});
// The local site's certificate is not in PHP's CA bundle, so Fetch's verified loopback fails with `tls`
// and the probe could never give a definite answer. Accept it for the harness folder's URLs only; every
// other request keeps verification, so the https check still reports what a real run sees here.
// It also records every probe-folder URL requested, so the check below can see where the probe ran
// (an empty probe folder is removed after the run, so the folder itself is no evidence).
$GLOBALS['probe_urls'] = [];
add_filter('http_request_args', static function (array $args, string $url) use ($harness_name): array {
    if (strpos($url, '/' . $harness_name . '/') !== false) {
        $args['sslverify'] = false;
    }
    if (strpos($url, '/sfx-site-check/') !== false) {
        $GLOBALS['probe_urls'][] = $url;
    }
    return $args;
}, 10, 2);
$redirected = wp_get_upload_dir();
if (rtrim((string) ($redirected['basedir'] ?? ''), '/') !== $harness_dir) {
    fatal('the uploads redirect is not in effect (basedir ' . var_export($redirected['basedir'] ?? null, true) . '); refusing to run the probe.');
}
echo 'uploads redirected to: ' . $redirected['basedir'] . "\n\n";

// ---------------------------------------------------------------- checks

$failed = 0;
function check(bool $ok, string $label): void
{
    global $failed;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failed++;
    }
}

/** Runs the box's native registration for the current user and reports whether the widget landed. */
function native_widget_registered(): bool
{
    global $wp_meta_boxes;
    foreach (['class-wp-screen', 'screen', 'template', 'dashboard'] as $file) {
        require_once ABSPATH . "wp-admin/includes/{$file}.php";
    }
    set_current_screen('dashboard');
    $wp_meta_boxes = [];
    DashboardBox::register_native();
    return isset($wp_meta_boxes['dashboard']['normal']['core'][DashboardBox::ID]);
}

/** The box's output; a throwable inside it is reported, not lost in the buffer. */
function render_box(): string
{
    ob_start();
    try {
        DashboardBox::render();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    return (string) ob_get_clean();
}

/** Posts as the current user with a fresh nonce for that user. */
function post(array $fields): void
{
    $_POST = $fields + ['_ajax_nonce' => wp_create_nonce(Access::NONCE)];
    $_REQUEST = $_POST;
}

$admin = null;
foreach (get_users(['role' => 'administrator']) as $user) {
    wp_set_current_user($user->ID);
    if (Access::allowed()) {
        $admin = $user;
        break;
    }
}
if ($admin === null) {
    fatal('no existing administrator passes Access::allowed(); creating accounts is not allowed here.');
}
$editors = get_users(['role' => 'editor', 'number' => 1]);
if ($editors === []) {
    fatal('no existing editor user; the harness creates no account.');
}
$editor = $editors[0];
echo "admin: user #{$admin->ID}; editor: user #{$editor->ID} (both existing, none created)\n\n";

// ---- as administrator
wp_set_current_user($admin->ID);
check(Access::allowed(), 'administrator passes Access::allowed()');

post(['probe' => '1']);
[$code, $body] = Runs::start_request();
check($code === 200 && ($body['success'] ?? false) === true, "start (with the probe ticked) answers 200 (got {$code})");
$run = (string) ($body['data']['run'] ?? '');
$issued_checks = (array) ($body['data']['checks'] ?? []);
$run_ids[] = $run;
check(preg_match('/^[0-9a-f]{32}$/', $run) === 1 && count($issued_checks) === count(Catalogue::all()), 'the run is issued with every catalogue check (' . count($issued_checks) . ')');

$results = [];
$probe_graded = null;
$probe_observation = null;
foreach (Catalogue::all() as $id => $row) {
    if ($row['how'] === 'S') {
        post(['run' => $run, 'check' => $id]);
        [$code, $body] = Runs::server_request();
        $ok = $code === 200 && ($body['success'] ?? false) === true && isset($body['data']['token']);
        check($ok, "server check {$id}: HTTP {$code}, status " . ($body['data']['graded']['status'] ?? '-'));
        if ($ok) {
            $results[$id] = ['token' => $body['data']['token']];
            if ($id === 'php_in_uploads') {
                $probe_graded = $body['data']['graded'];
                $probe_observation = $body['data']['observation'];
            }
        }
    } elseif (OutsideChecks::handles($id)) {
        // The browser half is not run here (the browser smoke does that); the endpoints are.
        post(['run' => $run, 'check' => $id]);
        [$code, $body] = OutsideEndpoints::targets_request();
        $targets_ok = $code === 200 && ($body['success'] ?? false) === true;
        post(['run' => $run, 'check' => $id, 'observations' => '[]']);
        [$code2, $body2] = OutsideEndpoints::observe_request();
        $observe_ok = $code2 === 200 && ($body2['success'] ?? false) === true && isset($body2['data']['token']);
        check($targets_ok && $observe_ok, "outside check {$id}: targets {$code}, observe {$code2}, status " . ($body2['data']['graded']['status'] ?? '-'));
        if ($observe_ok && $row['how'] === 'B') {
            $results[$id] = ['token' => $body2['data']['token']];
        }
    }
}

check($probe_graded !== null, 'php_in_uploads was observed');
$step = (string) ($probe_observation['step'] ?? 'missing');
$probe_status = (string) ($probe_graded['status'] ?? '');
echo "     probe step: " . var_export($step, true) . ', status: ' . $probe_status . ', note: ' . (string) ($probe_graded['note'] ?? '') . "\n";
foreach ((array) ($probe_graded['findings'] ?? []) as $finding) {
    echo '     finding: [' . $finding['status'] . '] ' . $finding['label'] . "\n";
}
check(in_array($probe_status, [Status::RED, Status::GREEN, Status::YELLOW], true), 'php_in_uploads gives a definite result (red, yellow or green; not Nicht prüfbar)');
$probe_file_gone = glob($harness_dir . '/sfx-site-check/*.php') === [];
check($probe_file_gone, 'the probe removed its own file inside the harness folder');
$probe_urls = $GLOBALS['probe_urls'];
$inside = $probe_urls !== [] && array_filter($probe_urls, static fn(string $u): bool => strpos($u, '/' . $harness_name . '/sfx-site-check/') === false) === [];
check($inside || $step === 'location', 'the probe worked inside the harness folder, not the real one (' . implode(', ', $probe_urls) . ')');
check(!file_exists($harness_dir . '/sfx-site-check'), 'the probe removed its then empty folder');

post(['run' => $run, 'results' => wp_json_encode($results)]);
[$code, $body] = Runs::save_request();
check($code === 200 && ($body['success'] ?? false) === true, "save answers 200 (got {$code}" . ($code === 200 ? '' : ', ' . json_encode($body)) . ')');
$last = Runs::last();
check($last !== null && $last['run'] === $run && count($last['results']) === count(Catalogue::all()), 'the saved run holds a result for every check');
$counts = [];
foreach ((array) ($last['results'] ?? []) as $r) {
    $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
}
ksort($counts);
echo '     saved result counts: ' . json_encode($counts) . "\n";

post(['item' => 'contact_form', 'done' => '1']);
[$code, $body] = Runs::items_request();
check($code === 200 && isset(Runs::items()['contact_form']), 'items: ticking an item is stored');
post(['reset' => '1']);
[$code, $body] = Runs::items_request();
check($code === 200 && Runs::items() === [], 'items: reset clears them');

$box = render_box();
check(native_widget_registered() === true, 'the native dashboard widget is registered for the administrator (control for the editor check)');
check(strpos($box, 'sfx-site-check-box') !== false && strpos($box, 'data-count') !== false, 'the dashboard box shows the saved run to the administrator');

// ---- as editor
$manual_before = rows_with_prefix($prefix)[Options::name('manual')] ?? null;
$probes_before = rows_with_prefix($prefix)[Options::name('probes')] ?? null;
$items_before = rows_with_prefix($prefix)[Options::name('items')] ?? null;
wp_set_current_user($editor->ID);
check(!Access::allowed(), 'editor does not pass Access::allowed()');

$refusals = [
    'start' => [static fn() => Runs::start_request(), ['probe' => '1']],
    'server' => [static fn() => Runs::server_request(), ['run' => $run, 'check' => 'php_in_uploads']],
    'save' => [static fn() => Runs::save_request(), ['run' => $run, 'results' => '{}']],
    'items' => [static fn() => Runs::items_request(), ['item' => 'contact_form', 'done' => '1']],
    'targets' => [static fn() => OutsideEndpoints::targets_request(), ['run' => $run, 'check' => 'robots_txt']],
    'observe' => [static fn() => OutsideEndpoints::observe_request(), ['run' => $run, 'check' => 'robots_txt', 'observations' => '[]']],
];
foreach ($refusals as $name => [$call, $fields]) {
    post($fields); // a VALID nonce for the editor: the capability gate alone must refuse
    [$code] = $call();
    check($code === 403, "editor is refused by the {$name} endpoint with a valid nonce (HTTP {$code})");
}

$editor_box = render_box();
check($editor_box === '', 'editor sees no box (render prints nothing)');
$widgets = DashboardBox::widgets([]);
check(isset($widgets[DashboardBox::ID]) && call_user_func($widgets[DashboardBox::ID]['can_render']) === false, 'the Custom Dashboard entry exists, but its can_render is false for the editor');
check(native_widget_registered() === false, 'the native dashboard widget is not registered for the editor');

$rows_after = rows_with_prefix($prefix);
check(($rows_after[Options::name('manual')] ?? null) === $manual_before
    && ($rows_after[Options::name('probes')] ?? null) === $probes_before
    && ($rows_after[Options::name('items')] ?? null) === $items_before, 'the refused requests wrote nothing');

wp_set_current_user(0);
echo "\n" . ($failed === 0 ? 'ALL PASSED' : "{$failed} CHECK(S) FAILED") . "\n";
exit($failed === 0 ? 0 : 1);
