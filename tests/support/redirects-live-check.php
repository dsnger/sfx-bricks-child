<?php

declare(strict_types=1);

/**
 * Drive the Redirects module against the REAL local site over HTTP.
 *
 * tests/redirects-*-test.php pin the pure decisions with stubs. This script
 * asks whether the wiring holds on a booted WordPress with Bricks: that a rule
 * really answers before core's guesses, that a 410 on a Bricks page does not
 * render the page, that hits and 404s are written, and that a rename creates
 * (and flattens) auto rules. Spec: docs/superpowers/specs/2026-09-29-redirects-design.md,
 * "Testing → Live".
 *
 * MANUAL. quality.sh does not run this. Run with MAMP's PHP (AGENTS.md § Local PHP):
 *
 *   /Applications/MAMP/bin/php/php8.5.2/bin/php tests/support/redirects-live-check.php
 *
 * Order, per the AGENTS.md harness rule:
 *   1. boot WordPress (a CLI boot fires no admin_init, so nothing is installed
 *      or scheduled on the module's behalf — the snapshot sees the pre-run state);
 *   2. fatal guards — they decide WHERE this runs and write nothing;
 *   3. snapshot everything the run may change;
 *   4. register the ONE shutdown teardown;
 *   5. only then write anything.
 *
 * Exit: 0 all passed, 1 a check failed, 2 guard/fixture error, 3 teardown left state behind.
 */

// ---------------------------------------------------------------- bootstrap

const WP_LOAD   = '/Users/daniel/DEVELOPMENT/LOCALHOST/sfx-bricks-child.local/wp-load.php';
const SITE_HOST = 'sfx-bricks-child.local';
const CRON_HOOK = 'sfx_redirects_cleanup';

if (!is_file(WP_LOAD)) {
    fwrite(STDERR, 'error: ' . WP_LOAD . " not found.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require WP_LOAD;

use SFX\Redirects\Controller;
use SFX\Redirects\Repository;
use SFX\Redirects\Rule;
use SFX\Redirects\Settings;

function fatal(string $message): never
{
    fwrite(STDERR, "error: {$message}\n");
    exit(2);
}

/**
 * A failed SHOW TABLES is not "absent": it throws, so the guard stops and the
 * teardown records the step as not verified instead of dropping or skipping on
 * a guess.
 */
function table_exists(string $table): bool
{
    global $wpdb;

    $wpdb->last_error = '';
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    if ($wpdb->last_error !== '') {
        throw new RuntimeException("SHOW TABLES for {$table} failed: {$wpdb->last_error}");
    }

    return $found === $table;
}

/**
 * An option straight from the table, bypassing the object cache (delete_option
 * primes "notoptions" even when its DELETE failed). Throws on a failed read.
 *
 * @return array{exists:bool, value:mixed}
 */
function read_option_row(string $name): array
{
    global $wpdb;

    $wpdb->last_error = '';
    $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    if ($wpdb->last_error !== '') {
        throw new RuntimeException("reading option {$name} failed: {$wpdb->last_error}");
    }

    return ['exists' => $row !== null, 'value' => $row !== null ? maybe_unserialize($row->option_value) : null];
}

/** This hook's events from the stored cron array: [timestamp => schedule|false]. */
function read_cron_events(): array
{
    $cron = read_option_row('cron')['value'];
    $events = [];
    foreach (is_array($cron) ? $cron : [] as $timestamp => $hooks) {
        if (is_array($hooks) && isset($hooks[CRON_HOOK]) && is_array($hooks[CRON_HOOK])) {
            foreach ($hooks[CRON_HOOK] as $event) {
                $events[(int) $timestamp] = $event['schedule'] ?? false;
            }
        }
    }
    ksort($events);

    return $events;
}

/** Throws on a failed query, so a cleanup step can never read an error as success. */
function db_ok($result, string $what)
{
    global $wpdb;

    if ($result === false || $wpdb->last_error !== '') {
        throw new RuntimeException("{$what} failed: {$wpdb->last_error}");
    }

    return $result;
}

// ---------------------------------------------------------------- fatal guards (write nothing)

if (!class_exists(Repository::class) || !class_exists(Rule::class) || !class_exists(Controller::class)) {
    fatal('the SFX\\Redirects classes are not autoloadable — wrong checkout or missing vendor/.');
}

$home_host = wp_parse_url(home_url(), PHP_URL_HOST);
if ($home_host !== SITE_HOST) {
    fatal('home_url() host is ' . var_export($home_host, true) . ', expected ' . SITE_HOST . '. Refusing to run anywhere else.');
}

if (!function_exists('curl_init')) {
    fatal('the PHP curl extension is required for the HTTP checks.');
}

if ((string) get_option('permalink_structure') === '') {
    fatal('plain permalinks: the slug-change check needs path permalinks (the monitor skips ?page_id= links by design).');
}

if (!defined('BRICKS_DB_PAGE_CONTENT') || !defined('BRICKS_DB_EDITOR_MODE') || !defined('BRICKS_DB_TEMPLATE_TYPE')) {
    fatal('Bricks constants are not defined — is Bricks the parent theme?');
}

// A registered sanitiser would rewrite both our write and the teardown's
// restore, so "restored exactly" could not be promised. admin_init registers
// it; a CLI boot should not have.
if (has_filter('sanitize_option_sfx_general_options') || has_filter('sanitize_option_' . Settings::OPTION_NAME)) {
    fatal('a sanitize_option filter is registered for an option this run writes; the restore would not be exact.');
}

// No real rule may intercept a harness URL, and no real 404 row may be removed
// by a cleanup this run triggers: both tables must be absent or empty.
$tables = [Repository::table(), Repository::log_table()];
$tables_existed = [];
foreach ($tables as $table) {
    try {
        $tables_existed[$table] = table_exists($table);
    } catch (RuntimeException $e) {
        fatal($e->getMessage());
    }
    if (!$tables_existed[$table]) {
        continue;
    }
    $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    if ($wpdb->last_error !== '' || $count === null) {
        fatal("could not count rows in {$table}: {$wpdb->last_error}");
    }
    if ((int) $count !== 0) {
        fatal("{$table} holds {$count} row(s). The harness only runs against absent or empty tables.");
    }
}

$admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
if (!$admins) {
    fatal('no administrator on this site to act as.');
}
$admin = $admins[0];

// ---------------------------------------------------------------- snapshot

// Read straight from the table with an error check: get_option() returns its
// default both for "absent" and for "the read failed", and a failed read taken
// for absence would make the teardown delete a real option.
$snapshot_options = [];
try {
    foreach (['sfx_general_options', Settings::OPTION_NAME, 'sfx_redirects_db_version'] as $name) {
        $snapshot_options[$name] = read_option_row($name);
    }
    // Checked read of the stored cron array: wp_next_scheduled() answers false
    // for "no event" and for "could not read the option" alike.
    $snapshot_cron = read_cron_events();
} catch (RuntimeException $e) {
    fatal($e->getMessage());
}

$hex    = bin2hex(random_bytes(6));
$marker = 'sfx-harness-' . $hex;   // lowercase hex: slugs are lowercased by sanitize_title()

echo "marker {$marker}, acting as {$admin->user_login}\n";

// ---------------------------------------------------------------- the ONE teardown

register_shutdown_function(static function () use ($marker, $tables_existed, $snapshot_options, $snapshot_cron): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    global $wpdb;
    $left = [];
    echo "\nteardown\n";

    $step = static function (string $what, callable $fn) use (&$left): void {
        try {
            $fn();
        } catch (\Throwable $e) {
            $left[] = "{$what}: " . $e->getMessage();
        }
    };

    $like = '%' . $wpdb->esc_like($marker) . '%';

    // Marker rows (only matters for tables that stay).
    $step('rules/404 rows', static function () use ($wpdb, $like, &$left): void {
        foreach ([Repository::table() => ['source', 'target'], Repository::log_table() => ['path']] as $table => $cols) {
            if (!table_exists($table)) {
                continue;
            }
            $where = implode(' OR ', array_map(static fn(string $c): string => "`{$c}` LIKE %s", $cols));
            $args  = array_fill(0, count($cols), $like);
            $wpdb->last_error = '';
            $n = db_ok($wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE {$where}", ...$args)), "DELETE in {$table}");
            echo "  deleted " . (int) $n . " marker row(s) from {$table}\n";
            $wpdb->last_error = '';
            $still = (int) db_ok($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where}", ...$args)), "COUNT in {$table}");
            if ($still !== 0) {
                $left[] = "{$still} marker row(s) still in {$table}";
            }
        }
    });

    // Test posts: every harness slug starts with the marker, so the slug finds a
    // post even when a fatal hit before its id was returned.
    $step('posts', static function () use ($wpdb, $marker, &$left): void {
        $find = static function () use ($wpdb, $marker): array {
            $wpdb->last_error = '';
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE %s",
                $wpdb->esc_like($marker) . '%'
            ));
            return array_map('intval', db_ok($ids, 'marker post lookup'));
        };
        foreach ($find() as $id) {
            $post = get_post($id);
            if ($post && str_starts_with($post->post_name, $marker)) {
                // also removes revisions and _wp_old_slug meta
                if (!wp_delete_post($id, true)) {
                    throw new RuntimeException("wp_delete_post({$id}) failed");
                }
                echo "  force-deleted post {$id} ({$post->post_name})\n";
            }
        }
        $still = count($find());
        if ($still !== 0) {
            $left[] = "{$still} marker post(s) still present";
        }
    });

    // Options, exactly as they were.
    foreach ($snapshot_options as $name => $snap) {
        $step("option {$name}", static function () use ($name, $snap, &$left): void {
            // Verified against the table, not the cache.
            if ($snap['exists']) {
                update_option($name, $snap['value']);
                $now = read_option_row($name);
                $ok  = $now['exists'] && $now['value'] == $snap['value'];
                echo "  restored option {$name}\n";
            } else {
                delete_option($name);
                $ok = !read_option_row($name)['exists'];
                echo "  deleted option {$name} (absent before)\n";
            }
            if (!$ok) {
                $left[] = "option {$name} does not match its snapshot";
            }
        });
    }

    // Tables the run created.
    foreach ($tables_existed as $table => $existed) {
        if ($existed) {
            continue;
        }
        $step("table {$table}", static function () use ($wpdb, $table, &$left): void {
            $wpdb->last_error = '';
            db_ok($wpdb->query("DROP TABLE IF EXISTS `{$table}`"), "DROP {$table}");
            echo "  dropped {$table} (absent before)\n";
            if (table_exists($table)) {
                $left[] = "table {$table} still exists";
            }
        });
    }

    // Cron, as it was.
    // Also when an event only moved: a due one may have run during the HTTP checks.
    $step('cron', static function () use ($snapshot_cron, &$left): void {
        if (read_cron_events() === $snapshot_cron) {
            echo "  cron " . CRON_HOOK . " unchanged\n";
            return;
        }
        wp_clear_scheduled_hook(CRON_HOOK);
        foreach ($snapshot_cron as $timestamp => $schedule) {
            if ($schedule === false) {
                wp_schedule_single_event($timestamp, CRON_HOOK);
            } else {
                wp_schedule_event($timestamp, $schedule, CRON_HOOK);
            }
        }
        echo "  restored " . CRON_HOOK . " to its snapshot (" . count($snapshot_cron) . " event(s))\n";
        if (read_cron_events() !== $snapshot_cron) {
            $left[] = 'cron state does not match its snapshot';
        }
    });

    if ($left !== []) {
        fwrite(STDERR, "  TEARDOWN INCOMPLETE:\n  - " . implode("\n  - ", $left) . "\n");
        exit(3);
    }
    echo "  teardown complete\n";
});

// ---------------------------------------------------------------- helpers

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "  PASS {$label}\n";
        return;
    }
    echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    $failures++;
}

/**
 * GET (not HEAD: template-loader exits on HEAD before rendering), never
 * following redirects.
 *
 * @return array{status:int, headers:array<string,string>, body:string, error:string}
 */
function http_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET        => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache'],
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => curl_error($ch)];
    }
    $size   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    $blocks  = array_values(array_filter(explode("\r\n\r\n", substr($raw, 0, $size)), 'strlen'));
    $headers = [];
    foreach (explode("\r\n", (string) end($blocks)) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => (string) substr($raw, $size), 'error' => ''];
}

function describe(array $r): string
{
    return $r['error'] !== ''
        ? 'curl: ' . $r['error']
        : 'status ' . $r['status'] . ', Location ' . var_export($r['headers']['location'] ?? null, true)
            . ', X-Redirect-By ' . var_export($r['headers']['x-redirect-by'] ?? null, true);
}

/** A redirect from THIS module: status, exact Location, and our X-Redirect-By (not core's guesses). */
function check_redirect(string $label, array $r, int $status, string $location): void
{
    check(
        $label,
        $r['status'] === $status
            && ($r['headers']['location'] ?? null) === $location
            && ($r['headers']['x-redirect-by'] ?? null) === 'SFX Redirects',
        describe($r) . " (expected {$status} → {$location})"
    );
}

function add_rule(array $input): int
{
    $validated = Rule::validate($input + ['match_type' => 'exact', 'enabled' => true, 'note' => 'sfx live harness']);
    if ($validated['errors'] !== []) {
        fatal('fixture rule ' . $input['source'] . ' invalid: ' . implode('; ', $validated['errors']));
    }
    $saved = Repository::save($validated['rule'], 0, 'manual');
    if (($saved['status'] ?? '') !== 'created') {
        fatal('fixture rule ' . $input['source'] . ' not saved: ' . ($saved['status'] ?? '?') . ' ' . ($saved['message'] ?? ''));
    }

    return (int) $saved['id'];
}

function hits(int $id): ?int
{
    $got = Repository::get($id);

    return $got['status'] === 'found' ? $got['row']['hits'] : null;
}

function insert_page(array $args, string $what): int
{
    $id = wp_insert_post($args + ['post_type' => 'page', 'post_status' => 'publish'], true);
    if (is_wp_error($id) || (int) $id <= 0) {
        fatal("could not create {$what}: " . (is_wp_error($id) ? $id->get_error_message() : 'returned 0'));
    }
    if (get_post((int) $id)->post_name !== $args['post_name']) {
        fatal("{$what} slug was rewritten to " . get_post((int) $id)->post_name . '; teardown relies on it.');
    }

    return (int) $id;
}

function rename_page(int $id, string $slug): void
{
    $r = wp_update_post(['ID' => $id, 'post_name' => $slug], true);
    if (is_wp_error($r) || (int) $r !== $id || get_post($id)->post_name !== $slug) {
        fatal("rename of page {$id} to {$slug} failed" . (is_wp_error($r) ? ': ' . $r->get_error_message() : ''));
    }
}

$home      = rtrim(home_url(), '/');
$home_path = (string) wp_parse_url(home_url(), PHP_URL_PATH);
$rel       = static fn(string $permalink): string => Rule::home_relative((string) wp_parse_url($permalink, PHP_URL_PATH), $home_path);

// ---------------------------------------------------------------- enable + install (first writes)

wp_set_current_user($admin->ID);   // Bricks refuses CLI writes to its page meta without a user

$general = is_array($snapshot_options['sfx_general_options']['value']) ? $snapshot_options['sfx_general_options']['value'] : [];
$general['enable_redirects'] = 1;
update_option('sfx_general_options', $general);
// Deterministic run: logging and the slug monitor on, whatever was stored.
update_option(Settings::OPTION_NAME, Settings::defaults());

echo "\nschema\n";
Repository::maybe_install();
check('Repository::maybe_install() leaves the module ready()', Repository::ready(), 'install error: ' . Repository::install_error());
check('both tables exist', table_exists(Repository::table()) && table_exists(Repository::log_table()));
if (!Repository::ready()) {
    exit(1);
}

// The module was disabled at boot, so its Controller never registered the slug
// monitor in THIS process (HTTP requests load it themselves). Register only
// that hook — constructing the Controller would wire admin hooks for nothing.
if (has_action('post_updated', [Controller::class, 'on_post_updated']) === false) {
    add_action('post_updated', [Controller::class, 'on_post_updated'], 10, 3);
}

// ---------------------------------------------------------------- fixtures

$page_marker_text = 'SFX harness Bricks marker ' . $hex;
$gone_id = insert_page([
    'post_title'  => 'SFX harness gone ' . $hex,
    'post_name'   => $marker . '-gone',
    'post_author' => $admin->ID,
    'post_content' => '',
], 'the Bricks page');
update_post_meta($gone_id, BRICKS_DB_EDITOR_MODE, 'bricks');
update_post_meta($gone_id, BRICKS_DB_TEMPLATE_TYPE, 'content');
update_post_meta($gone_id, BRICKS_DB_PAGE_CONTENT, [[
    'id'       => 'abcdef',
    'name'     => 'heading',
    'parent'   => 0,
    'children' => [],
    'settings' => ['text' => $page_marker_text],
]]);
$stored = get_post_meta($gone_id, BRICKS_DB_PAGE_CONTENT, true);
if (!is_array($stored) || ($stored[0]['settings']['text'] ?? null) !== $page_marker_text) {
    fatal('Bricks content was not stored on the fixture page (current user / Bricks guard?).');
}
$gone_url  = get_permalink($gone_id);
$gone_path = $rel($gone_url);

$a    = "/{$marker}/a";
$b    = "/{$marker}/b";
$temp = "/{$marker}/temp";

// ---------------------------------------------------------------- control: the page renders before any rule

echo "\ncontrol\n";
$r = http_get($gone_url);
check('the Bricks page renders 200 with its marker text before the 410 rule exists',
    $r['status'] === 200 && str_contains($r['body'], $page_marker_text),
    describe($r) . ', marker ' . (str_contains($r['body'], $page_marker_text) ? 'present' : 'absent'));

// ---------------------------------------------------------------- rules

$id_a     = add_rule(['source' => $a, 'target' => $b, 'status_code' => '301']);
$id_regex = add_rule([
    'source'      => '^/' . $marker . '/blog/(.*)$',
    'match_type'  => 'regex',
    'target'      => '/' . $marker . '/news/$1',
    'status_code' => '301',
]);
$id_gone  = add_rule(['source' => $gone_path, 'target' => '', 'status_code' => '410']);
$id_temp  = add_rule(['source' => $temp, 'target' => $b, 'status_code' => '302']);

// ---------------------------------------------------------------- HTTP checks

echo "\nredirects\n";
check_redirect('exact 301: A → B', http_get($home . $a), 301, $home . $b);
check_redirect('exact 301 passes the query through: A?x=1 → B?x=1', http_get($home . $a . '?x=1'), 301, $home . $b . '?x=1');

$r = http_get($home . '/' . $marker . '/blog/' . rawurlencode('über'));
check_redirect('regex 301: captured Unicode segment inserted once-encoded', $r, 301, $home . '/' . $marker . '/news/%c3%bcber');
check('regex target is not double-encoded (no %25)', !str_contains($r['headers']['location'] ?? '', '%25'), describe($r));

$r = http_get($home . $temp . '?z=2&q=1');
check_redirect('302 passes the raw query through in sent order', $r, 302, $home . $b . '?z=2&q=1');
$cc = strtolower($r['headers']['cache-control'] ?? '');
check('302 sends nocache headers', str_contains($cc, 'no-store') || str_contains($cc, 'no-cache'), 'Cache-Control ' . var_export($r['headers']['cache-control'] ?? null, true));

echo "\n410\n";
$r = http_get($gone_url);
check('410 rule on the Bricks page answers 410', $r['status'] === 410, describe($r));
check('410 body does not contain the page content', !str_contains($r['body'], $page_marker_text), 'marker text found in the 410 body');

echo "\nhit counters\n";
check('A counted 2 hits', hits($id_a) === 2, 'hits ' . var_export(hits($id_a), true));
check('regex rule counted 1 hit', hits($id_regex) === 1, 'hits ' . var_export(hits($id_regex), true));
check('410 rule counted 1 hit', hits($id_gone) === 1, 'hits ' . var_export(hits($id_gone), true));
check('302 rule counted 1 hit', hits($id_temp) === 1, 'hits ' . var_export(hits($id_temp), true));

echo "\n404 log\n";
$missing = '/' . $marker . '/missing-' . bin2hex(random_bytes(4));
$r = http_get($home . $missing);
check('a random marker URL answers 404', $r['status'] === 404, describe($r));
$log = Repository::list_404($marker, 'path', 'ASC', 1, 100);
$paths = $log === null ? null : array_column($log['items'], 'hits', 'path');
check('the 404 is logged once under its canonical path',
    is_array($paths) && ($paths[Rule::canonical_path($missing)] ?? null) === 1,
    'log rows ' . var_export($paths, true));
check('the 410 was not logged as a 404',
    is_array($paths) && !array_key_exists(Rule::canonical_path($gone_path), $paths),
    'log rows ' . var_export($paths, true));

echo "\nslug change\n";
$s1 = $marker . '-one';
$s2 = $marker . '-two';
$s3 = $marker . '-three';
$ren_id = insert_page([
    'post_title'  => 'SFX harness rename ' . $hex,
    'post_name'   => $s1,
    'post_author' => $admin->ID,
    'post_content' => 'rename fixture',
], 'the rename page');

$old1_url = get_permalink($ren_id);
rename_page($ren_id, $s2);
$old2_url = get_permalink($ren_id);
rename_page($ren_id, $s3);
$now_url  = get_permalink($ren_id);

$old1   = Rule::canonical_path($rel($old1_url));
$old2   = Rule::canonical_path($rel($old2_url));
$target = $rel($now_url);   // as WordPress writes it, trailing slash kept

$list  = Repository::list_rules($marker, 'source', 'ASC', 1, 100);
$rules = [];
foreach ($list['items'] ?? [] as $row) {
    $rules[$row['source']] = $row;
}
$is_auto = static fn(?array $row): bool => $row !== null
    && $row['match_type'] === 'exact' && $row['origin'] === 'auto'
    && $row['status_code'] === 301 && $row['enabled'] === true && $row['target'] === $target;

check("auto rule {$old1} → {$target} (chain flattened)", $is_auto($rules[$old1] ?? null), var_export($rules[$old1] ?? null, true) . ' — see error_log if missing');
check("auto rule {$old2} → {$target}", $is_auto($rules[$old2] ?? null), var_export($rules[$old2] ?? null, true));
check('no rule at the current address', !isset($rules[Rule::canonical_path($target)]));

check_redirect('GET old1 → 301 to the current permalink', http_get($old1_url), 301, $now_url);

// ---------------------------------------------------------------- result

echo "\n";
if ($failures > 0) {
    echo "redirects live check: {$failures} failed\n";
    exit(1);
}
echo "PASS: redirects live check\n";
exit(0);
