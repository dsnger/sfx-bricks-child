<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

/**
 * Drive the WP Optimizer guest REST policy against the REAL local site over HTTP.
 *
 * tests/wpoptimizer-rest-guest-test.php pins the decisions with stubs. This asks
 * whether the gate holds on the booted site: real requests, real plugins, a real
 * application password. Spec: docs/superpowers/specs/2026-10-02-rest-guest-access-design.md,
 * "Testing → Live check".
 *
 * MANUAL. quality.sh does not run this (its globs are tests/*-test.php and
 * tests/*-test.mjs). Run with MAMP's PHP (AGENTS.md § Local PHP):
 *
 *   /Applications/MAMP/bin/php/php8.5.2/bin/php tests/support/rest-guest-live-check.php
 *   /Applications/MAMP/bin/php/php8.5.2/bin/php tests/support/rest-guest-live-check.php --scenario=optimizer-on
 *
 * Why it is built this way (AGENTS.md Don't: "a verification harness must not
 * touch real state outside a guaranteed teardown"):
 *
 * - This process boots WordPress with SHORTINIT: $wpdb and core basics, no theme,
 *   no plugins — so no WP Optimizer code runs here before the snapshot exists (a
 *   full boot can create options and close comment defaults on wp_loaded).
 * - Every row the run can change is read raw through $wpdb before anything is
 *   written, and restored raw by the ONE shutdown teardown, which then re-reads
 *   each row and reports RESTORE MISMATCH <name> (exit 1) on any difference. Raw
 *   writes bypass the WP Optimizer sanitizer and every cache. A persistent object
 *   cache would survive a raw restore, so the harness refuses to run with one.
 * - The teardown is registered BEFORE wp-load.php, not after: WordPress registers
 *   its fatal error handler during the load, and a handler that dies first would
 *   skip any shutdown function registered after it. Until the snapshot is taken
 *   the teardown only removes temp files.
 * - The full boot (application password, live namespaces, permalink) happens in a
 *   separate worker process (rest-guest-live-worker.php), launched with a random
 *   token it must receive both as argv[1] and in SFX_REST_GUEST_RUN.
 *
 * Do not change these settings while the harness runs: the teardown restores the
 * snapshot taken at the start, so a change made meanwhile (by an admin or a
 * background job) would be overwritten.
 *
 * Single site only. On Multisite `using_application_passwords` is a sitemeta row
 * and is not snapshotted here; the harness exits with "unsupported" before
 * writing anything rather than half-restoring.
 *
 * Exit: 0 every check OK, 1 a check failed or the restore mismatched, 2 guard/fixture error.
 */

const SITE_HOST = 'sfx-bricks-child.local';

function fatal(string $message): void
{
    fwrite(STDERR, "error: {$message}\n");
    exit(2);
}

$args = array_slice($argv, 1);
if ($args !== [] && $args !== ['--scenario=optimizer-on']) {
    fatal('unknown arguments; the only option is --scenario=optimizer-on.');
}
$optimizer_on = $args === ['--scenario=optimizer-on'];

$load = __DIR__ . '/../../../../../wp-load.php';
if (!is_file($load)) {
    fatal("{$load} not found — this file must sit in wp-content/themes/<theme>/tests/support/.");
}
if (file_exists(dirname($load) . '/wp-content/object-cache.php')) {
    fatal('wp-content/object-cache.php exists: a persistent object cache would survive the raw restore. Refusing to run.');
}

// ---------------------------------------------------------------- teardown (armed before the load)

/** @var array{rows: ?array<string, ?list<array<string,string>>>, tmp: list<string>} $state */
$state = ['rows' => null, 'tmp' => []];

register_shutdown_function(static function () use (&$state): void {
    foreach ($state['tmp'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if ($state['rows'] === null) {
        return; // nothing was snapshotted, so nothing was written
    }

    $mismatch = false;
    foreach ($state['rows'] as $name => $rows) {
        [$table, $where] = row_target($name);
        if (!restore_rows($table, $where, $rows)) {
            fwrite(STDERR, "RESTORE FAILED {$name}\n");
        }
        try {
            $now = read_rows($name);
        } catch (RuntimeException $e) {
            $now = false;
        }
        if ($now !== $rows) {
            echo "RESTORE MISMATCH {$name}\n";
            $mismatch = true;
        }
    }
    if ($mismatch) {
        exit(1);
    }
    echo 'OK restored ' . count($state['rows']) . " rows byte-for-byte\n";
});

// ---------------------------------------------------------------- minimal boot

define('SHORTINIT', true);
require $load;

/** Options the run can change, plus user 1's application passwords. */
const OPTION_ROWS = [
    'sfx_general_options',
    'sfx_wpoptimizer_options',
    'sfx_password_protected_options',
    'default_comment_status',
    'default_ping_status',
    'sfx_wpoptimizer_migrated_disable_version_numbers_off',
    'using_application_passwords',
];
const USERMETA_ROW = 'usermeta:1:_application_passwords';

/** @return array{0:string, 1:array<string,mixed>} table and WHERE for a tracked row name */
function row_target(string $name): array
{
    global $wpdb;
    if ($name === USERMETA_ROW) {
        return [$wpdb->usermeta, ['user_id' => 1, 'meta_key' => '_application_passwords']];
    }
    return [$wpdb->options, ['option_name' => $name]];
}

/**
 * Every matching row with the columns that matter, straight from the table.
 * Throws on a failed read: a failed read taken for "absent" would make the
 * teardown delete a real row.
 *
 * @return list<array<string,string>>
 */
function read_rows(string $name): array
{
    global $wpdb;
    [$table, $where] = row_target($name);
    $sql = $name === USERMETA_ROW
        ? $wpdb->prepare("SELECT meta_value FROM {$table} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id", 1, '_application_passwords')
        : $wpdb->prepare("SELECT option_value, autoload FROM {$table} WHERE option_name = %s", $name);
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if ($wpdb->last_error !== '' || !is_array($rows)) {
        throw new RuntimeException("reading {$name} failed: {$wpdb->last_error}");
    }
    return $rows;
}

/** @param list<array<string,string>> $rows */
function restore_rows(string $table, array $where, array $rows): bool
{
    global $wpdb;
    $ok = $wpdb->delete($table, $where) !== false;
    foreach ($rows as $row) {
        $ok = $wpdb->insert($table, $where + $row) !== false && $ok;
    }
    return $ok;
}

/** Raw upsert, the statement add_option() uses — no sanitizer, no cache. */
function write_option(string $name, $value): void
{
    global $wpdb;
    $done = $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'auto') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
        $name,
        maybe_serialize($value)
    ));
    if ($done === false) {
        fatal("writing {$name} failed: {$wpdb->last_error}");
    }
}

function delete_option_row(string $name): void
{
    global $wpdb;
    if ($wpdb->delete($wpdb->options, ['option_name' => $name]) === false) {
        fatal("deleting {$name} failed: {$wpdb->last_error}");
    }
}

/** The current stored value as an array ([] when absent or not an array). */
function option_array(string $name): array
{
    $rows = read_rows($name);
    $value = $rows ? maybe_unserialize($rows[0]['option_value']) : [];
    return is_array($value) ? $value : [];
}

// ---------------------------------------------------------------- fatal guards (write nothing)

global $wpdb;

if (wp_using_ext_object_cache()) {
    fatal('a persistent object cache is active; the raw restore would be incomplete. Refusing to run.');
}
if (is_multisite()) {
    fatal('Multisite is unsupported: using_application_passwords lives in sitemeta, which this harness does not restore.');
}

$siteurl = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'siteurl'));
if (parse_url($siteurl, PHP_URL_HOST) !== SITE_HOST) {
    fatal('siteurl is ' . var_export($siteurl, true) . ', expected host ' . SITE_HOST . '. Refusing to run anywhere else.');
}
$siteurl = rtrim($siteurl, '/');

$login = $wpdb->get_var("SELECT user_login FROM {$wpdb->users} WHERE ID = 1");
if (!is_string($login) || $login === '') {
    fatal('user 1 does not exist.');
}

exec('command -v curl', $out, $code);
if ($code !== 0) {
    fatal('curl is required for the HTTP checks.');
}

// ---------------------------------------------------------------- snapshot (before any write)

try {
    $snapshot = [];
    foreach (OPTION_ROWS as $name) {
        $snapshot[$name] = read_rows($name);
    }
    $snapshot[USERMETA_ROW] = read_rows(USERMETA_ROW);
} catch (RuntimeException $e) {
    fatal($e->getMessage());
}
$state['rows'] = $snapshot; // from here on the teardown restores

// ---------------------------------------------------------------- checks

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "OK {$label}\n";
        return;
    }
    echo "FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    $failures++;
}

/** @return array{0:int, 1:string} status (0 when curl failed) and body */
function http(string $url, ?string $auth = null): array
{
    global $state;
    $tmp = tempnam(sys_get_temp_dir(), 'sfx-rest-guest-');
    if ($tmp === false) {
        fatal('could not create a temp file.');
    }
    $state['tmp'][] = $tmp;
    $cmd = 'curl -sk --max-time 30 -o ' . escapeshellarg($tmp) . " -w '%{http_code}'"
        . ($auth !== null ? ' -u ' . escapeshellarg($auth) : '')
        . ' ' . escapeshellarg($url);
    $out = [];
    exec($cmd, $out, $code);
    $status = $code === 0 && isset($out[0]) ? (int) $out[0] : 0;
    return [$status, (string) file_get_contents($tmp)];
}

function rest_url_for(string $path): string
{
    global $siteurl;
    return $siteurl . '/wp-json/' . ltrim($path, '/');
}

// ---------------------------------------------------------------- scenario + fixture

if ($optimizer_on) {
    $general = option_array('sfx_general_options');
    $general['enable_wp_optimizer'] = 1;
    write_option('sfx_general_options', $general);
    write_option('default_comment_status', 'open');
    delete_option_row('sfx_wpoptimizer_migrated_disable_version_numbers_off');
}

$general = option_array('sfx_general_options');
$general['enable_wp_optimizer'] = 1;
write_option('sfx_general_options', $general);

$wpo = option_array('sfx_wpoptimizer_options');
$wpo = array_merge($wpo, [
    'disable_wp_optimizer'          => 0,
    'disable_application_passwords' => 0,
    'disable_rest_api'              => 0,
    'disable_embed'                 => 0,
    'block_external_http'           => 0,
    'rest_guest_mode'               => 'allowlist',
    'rest_guest_hide_index'         => 1,
    'rest_guest_namespaces'         => [
        ['namespace' => 'bricks/v1', 'method' => 'all'],
        ['namespace' => 'oembed/1.0', 'method' => 'get'],
    ],
]);
write_option('sfx_wpoptimizer_options', $wpo);

$pp = option_array('sfx_password_protected_options');
$pp['status'] = false;
write_option('sfx_password_protected_options', $pp);

echo 'scenario: ' . ($optimizer_on ? 'optimizer-on' : 'default') . ", site {$siteurl}\n";

// ---------------------------------------------------------------- worker (full boot, fresh process)

$token = bin2hex(random_bytes(16));
$cmd = 'SFX_REST_GUEST_RUN=' . escapeshellarg($token) . ' ' . escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg(__DIR__ . '/rest-guest-live-worker.php') . ' ' . escapeshellarg($token);
$lines = [];
exec($cmd, $lines, $code);

$patterns = [
    'OEMBED_ROUTES' => '/^OEMBED_ROUTES:([1-9]\d*)$/',
    'PERMALINK'     => '#^PERMALINK:(https://' . preg_quote(SITE_HOST, '#') . '/\S*)$#',
    'APP'           => '/^APP:([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}):([A-Za-z0-9]{24})$/',
    'NEW_BLOCKED'   => '/^NEW_BLOCKED:(\[.*\])$/',
];
$markers = [];
foreach ($lines as $line) {
    $name = strstr($line, ':', true);
    if ($name !== false && isset($patterns[$name])) {
        $markers[$name][] = $line;
    } else {
        echo "worker: {$line}\n"; // never a marker line: APP carries the password
    }
}
if ($code !== 0) {
    fatal("the worker exited with {$code}.");
}
$m = [];
foreach ($patterns as $name => $pattern) {
    if (count($markers[$name] ?? []) !== 1 || !preg_match($pattern, $markers[$name][0], $match)) {
        fatal("the worker's {$name} marker is missing, repeated or malformed.");
    }
    $m[$name] = $match;
}

check("oEmbed route registered ({$m['OEMBED_ROUTES'][1]} routes)", (int) $m['OEMBED_ROUTES'][1] > 0);
$new_blocked = json_decode($m['NEW_BLOCKED'][1], true);
check('new_blocked() reports wp/v2', is_array($new_blocked) && in_array('wp/v2', $new_blocked, true), $m['NEW_BLOCKED'][1]);

$permalink = $m['PERMALINK'][1];
$auth = $login . ':' . $m['APP'][2];

// ---------------------------------------------------------------- HTTP, allowlist

[$status, $body] = http(rest_url_for('oembed/1.0/embed') . '?url=' . rawurlencode($permalink));
check('guest GET oembed/1.0/embed → 200', $status === 200, "{$status} {$body}");

[$status, $body] = http(rest_url_for('wp/v2/posts'));
$data = json_decode($body, true);
check('guest GET wp/v2/posts → 401 rest_forbidden_guest', $status === 401 && ($data['code'] ?? null) === 'rest_forbidden_guest', "{$status} {$body}");

[$status, $body] = http(rest_url_for(''));
check('guest GET / (index) → 401', $status === 401, "{$status} {$body}");

foreach ([['namespace=bricks%2Fv1', 'allowed'], ['namespace=wp%2Fv2', 'blocked'], ['target=index', 'blocked']] as [$query, $expected]) {
    [$status, $body] = http(rest_url_for('sfx-guest/v1/probe') . '?' . $query);
    $data = json_decode($body, true);
    check("guest probe {$query} → {$expected}", $status === 200 && ($data['state'] ?? null) === $expected, "{$status} {$body}");
}

[$status, $body] = http(rest_url_for('wp/v2/posts'), $auth);
check('application password GET wp/v2/posts → 200', $status === 200, (string) $status);

// ---------------------------------------------------------------- HTTP, open

$wpo = option_array('sfx_wpoptimizer_options');
$wpo['rest_guest_mode'] = 'open';
write_option('sfx_wpoptimizer_options', $wpo);

[$status, $body] = http(rest_url_for('wp/v2/posts'));
check('mode open: guest GET wp/v2/posts → 200', $status === 200, (string) $status);

echo $failures === 0 ? "checks passed\n" : "{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
