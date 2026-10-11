<?php

declare(strict_types=1);

/**
 * Drive SiteCheck's Mutex against the REAL local database and object cache.
 *
 * tests/site-check-mutex-test.php pins the logic against a fake $wpdb. This
 * script asks whether the SQL holds on MySQL: the conditional write creates
 * and updates, the conditional delete deletes, a takeover fences the old
 * owner, a non-owner cannot release, and a value written by another process
 * is read fresh inside the next section. Spec: docs/superpowers/specs/
 * 2026-10-10-site-check-design.md, "Storage" → Writes.
 *
 * MANUAL. quality.sh does not run this. Run with MAMP's PHP (AGENTS.md § Local PHP):
 *
 *   /Applications/MAMP/bin/php/php8.5.2/bin/php tests/support/site-check-mutex-live-check.php
 *
 * Every option it writes carries a random prefix `sfx_site_check_h<random>_`
 * set through Options::use_harness_prefix() and asserted before the first
 * write. Order, per the AGENTS.md harness rule:
 *   1. fatal guards on where this runs — they write nothing;
 *   2. boot WordPress, switch and assert the prefix;
 *   3. snapshot the real sfx_site_check_* options;
 *   4. register the ONE shutdown teardown (deletes every option with the
 *      prefix, then compares the real options with the snapshot);
 *   5. only then write anything.
 *
 * The same file runs as the "other process" with --child (one fenced write)
 * or --purge-child (SiteCheck's Purge::run()); a child works only under the
 * prefix it is given and leaves cleanup to the parent. --child takes an
 * option KEY (one of Options::KEYS but `mutex`), never an option name: the
 * name comes from Options::name() under the harness prefix, so no argument
 * can make it write a real `sfx_site_check_*` row.
 *
 * Uploads: Purge::run() touches the probe folder (`<uploads>/sfx-site-check/`,
 * an empty one is removed). Every process that purges — the parent and
 * --purge-child — therefore runs with the `upload_dir` filter pointing the
 * uploads folder at a harness-owned temporary folder, asserted before the
 * first purge. The parent creates that folder after the teardown is
 * registered; the teardown removes it and checks that the real
 * `uploads/sfx-site-check/` (present or absent, and its listing) is unchanged.
 *
 * Save versus purge (spec "Storage" → Writes and Purge, acceptance 11): the
 * real Runs::save() and Purge::run() race on the real table. DataPurge::run()
 * is never called — it would purge every module of the real site. Purge::run()
 * takes its option AND cron hook names from Options, so under the prefix it
 * unschedules only harness-named events; the harness schedules none.
 *
 *   --fail-after-purge   exits fatally right after a purge, to show that the
 *                        teardown still runs and the real state is intact.
 *
 * The teardown compares, byte for byte, with the snapshot taken before the
 * first write: the real sfx_site_check_* options, the cron option (which holds
 * the real SiteCheck cron events) and the Redirects tables — and the real
 * probe folder as above.
 *
 * Exit: 0 all passed, 1 a check failed, 2 guard error, 3 teardown left state
 * behind or real state changed; a --fail-after-purge run ends in a PHP fatal
 * (255) and must still print "real state byte-identical: yes".
 */

const WP_LOAD   = '/Users/daniel/DEVELOPMENT/LOCALHOST/sfx-bricks-child.local/wp-load.php';
const THEME_DIR = '/Users/daniel/DEVELOPMENT/LOCALHOST/sfx-bricks-child.local/wp-content/themes/sfx-bricks-child';
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

$mode = (string) ($argv[1] ?? '');
$is_child = $mode === '--child';

define('WP_USE_THEMES', false);
// This process must not spawn WP-Cron: a spawned run would change the cron
// option while the harness compares it.
if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}
require WP_LOAD;

use SFX\SiteCheck\Mutex;
use SFX\SiteCheck\Options;
use SFX\SiteCheck\Purge;
use SFX\SiteCheck\Runs;

if (!class_exists(Mutex::class)) {
    require_once THEME_DIR . '/vendor/autoload.php';
}
foreach ([Mutex::class, Options::class, Runs::class, Purge::class] as $class) {
    $file = class_exists($class) ? (new ReflectionClass($class))->getFileName() : false;
    if (!is_string($file) || strpos($file, THEME_DIR . '/inc/SiteCheck/') !== 0) {
        fatal("{$class} is not loaded from this checkout (got " . var_export($file, true) . ').');
    }
}
if (wp_parse_url(home_url(), PHP_URL_HOST) !== SITE_HOST) {
    fatal('home_url() host is not ' . SITE_HOST . '. Refusing to run anywhere else.');
}

global $wpdb;

/** @return array{exists:bool, value:?string, autoload:?string} straight from the table */
function read_row(string $name): array
{
    global $wpdb;
    $wpdb->last_error = '';
    $row = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name));
    if ($wpdb->last_error !== '') {
        throw new RuntimeException("reading {$name} failed: {$wpdb->last_error}");
    }
    return ['exists' => $row !== null, 'value' => $row->option_value ?? null, 'autoload' => $row->autoload ?? null];
}

/** @return array<string,array{0:string,1:string}> every row whose name starts with $like_prefix */
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

/** Points the uploads folder at the harness-owned $dir for this process; fatal unless it took effect. */
function use_uploads(string $dir): void
{
    $tmp = realpath(sys_get_temp_dir());
    if ($tmp === false || strpos($dir, $tmp . '/sfx-site-check-harness-') !== 0 || !is_dir($dir) || is_link($dir)) {
        fatal('the harness uploads folder is not a harness-owned temporary folder: ' . var_export($dir, true));
    }
    add_filter('upload_dir', static function (array $uploads) use ($dir): array {
        $uploads['basedir'] = $dir;
        $uploads['path'] = $dir;
        return $uploads;
    });
    if (wp_get_upload_dir()['basedir'] !== $dir) {
        fatal('the upload_dir filter is not in effect; refusing to purge.');
    }
}

/** The real probe folder: absent, or present with its sorted listing. */
function probe_folder_state(string $dir): array
{
    clearstatcache();
    if (!file_exists($dir) && !is_link($dir)) {
        return ['present' => false];
    }
    $names = is_dir($dir) ? @scandir($dir) : false;
    return ['present' => true, 'dir' => is_dir($dir), 'names' => is_array($names) ? $names : null];
}

function use_prefix(string $prefix): void
{
    Options::use_harness_prefix($prefix);
    if (Options::name('mutex') !== $prefix . 'mutex' || Options::prefix() !== $prefix) {
        fatal('the harness prefix is not in effect; refusing to write.');
    }
}

// ---------------------------------------------------------------- child process

if ($is_child) {
    [, , $prefix, $key, $value] = $argv + [null, null, '', '', ''];
    use_prefix((string) $prefix);
    if (!in_array($key, array_diff(Options::KEYS, ['mutex']), true)) {
        fatal('--child takes an option key (' . implode(', ', array_diff(Options::KEYS, ['mutex'])) . '), got ' . var_export($key, true) . '.');
    }
    $option = Options::name((string) $key);
    if (strpos($option, (string) $prefix) !== 0 || $option === Options::DEFAULT_PREFIX . $key) {
        fatal('the derived option name is not under the harness prefix; refusing to write.');
    }
    $result = Mutex::with(static fn () => Mutex::write($option, (string) $value));
    echo $result === true ? 'CHILD_WROTE' : ($result === Mutex::Busy ? 'CHILD_BUSY' : 'CHILD_REFUSED');
    exit(0);
}
if ($mode === '--purge-child') {
    use_prefix((string) ($argv[2] ?? ''));
    use_uploads((string) ($argv[3] ?? ''));
    $result = Purge::run();
    echo $result['busy'] ? 'PURGE_BUSY' : 'PURGED';
    exit(0);
}

// ---------------------------------------------------------------- prefix, snapshot, teardown

$prefix = 'sfx_site_check_h' . bin2hex(random_bytes(6)) . '_';
use_prefix($prefix);
if (rows_with_prefix($prefix) !== []) {
    fatal("rows with the fresh prefix {$prefix} already exist.");
}

/** Everything outside the prefix the harness must leave byte-identical. */
function real_state(string $prefix): array
{
    global $wpdb;
    $tables = [];
    foreach (['sfx_redirects', 'sfx_redirects_404'] as $table) {
        $wpdb->last_error = '';
        $row = $wpdb->get_row('CHECKSUM TABLE ' . $wpdb->prefix . $table . ' EXTENDED', ARRAY_N);
        if ($wpdb->last_error !== '') {
            throw new RuntimeException("checksum of {$table} failed: {$wpdb->last_error}");
        }
        $tables[$table] = $row[1] ?? null;
    }
    return [
        'options' => array_diff_key(rows_with_prefix(Options::DEFAULT_PREFIX), rows_with_prefix($prefix)),
        'cron' => read_row('cron'),
        'tables' => $tables,
    ];
}

/** The real SiteCheck cron events, read from the cron option itself. */
function real_events(): array
{
    $events = [];
    foreach ((array) maybe_unserialize((string) read_row('cron')['value']) as $time => $hooks) {
        foreach (is_array($hooks) ? $hooks : [] as $hook => $instances) {
            if (strpos((string) $hook, Options::DEFAULT_PREFIX) === 0) {
                $events[] = $hook . '@' . $time;
            }
        }
    }
    return $events;
}

$real_before = real_state($prefix);
$events_before = real_events();
$real_probe_dir = wp_normalize_path(wp_get_upload_dir()['basedir']) . '/sfx-site-check';
$probe_before = probe_folder_state($real_probe_dir);
$uploads = realpath(sys_get_temp_dir()) . '/sfx-site-check-harness-' . bin2hex(random_bytes(6));
echo "prefix: {$prefix}\n";
echo 'real sfx_site_check_* rows before: ' . count($real_before['options']) . "\n";
echo 'real SiteCheck cron events before: ' . ($events_before === [] ? 'none' : implode(', ', $events_before)) . "\n";
echo 'Redirects tables before: ' . json_encode($real_before['tables']) . "\n";
echo "real probe folder {$real_probe_dir} before: " . json_encode($probe_before) . "\n";

register_shutdown_function(static function () use ($prefix, $real_before, $events_before, $uploads, $real_probe_dir, $probe_before): void {
    global $wpdb;
    $problems = [];
    try {
        // The harness uploads folder: only ever the temporary one named above.
        if (is_dir($uploads) && strpos($uploads, realpath(sys_get_temp_dir()) . '/sfx-site-check-harness-') === 0) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($uploads);
        }
        if (file_exists($uploads)) {
            $problems[] = "harness uploads folder left behind: {$uploads}";
        }
        $probe_after = probe_folder_state($real_probe_dir);
        if ($probe_after !== $probe_before) {
            $problems[] = 'real probe folder changed: ' . json_encode($probe_after);
        }
        echo 'teardown: harness uploads folder removed; real probe folder unchanged (' . json_encode($probe_after) . '): '
            . ($probe_after === $probe_before ? 'yes' : 'NO') . "\n";

        $names = array_keys(rows_with_prefix($prefix));
        $wpdb->last_error = '';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . '%'));
        if ($wpdb->last_error !== '') {
            $problems[] = "delete failed: {$wpdb->last_error}";
        }
        foreach ($names as $name) {
            wp_cache_delete($name, 'options');
        }
        $left = rows_with_prefix($prefix);
        if ($left !== []) {
            $problems[] = 'left behind: ' . implode(', ', array_keys($left));
        }
        $real_after = real_state($prefix);
        foreach (['options' => 'real sfx_site_check_* rows', 'cron' => 'cron option', 'tables' => 'Redirects tables'] as $key => $what) {
            if ($real_after[$key] !== $real_before[$key]) {
                $problems[] = "{$what} changed";
            }
        }
        $events_after = real_events();
        if ($events_after !== $events_before) {
            $problems[] = 'real SiteCheck cron events changed';
        }
        echo 'teardown: removed ' . count($names) . ' harness row(s); real options, cron option (SiteCheck events: '
            . ($events_after === [] ? 'none' : implode(', ', $events_after)) . ') and Redirects tables byte-identical: '
            . ($real_after === $real_before && $events_after === $events_before ? 'yes' : 'NO') . "\n";
    } catch (Throwable $e) {
        $problems[] = 'teardown error: ' . $e->getMessage();
    }
    if ($problems !== []) {
        fwrite(STDERR, 'TEARDOWN: ' . implode('; ', $problems) . "\n");
        exit(3);
    }
});

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

function purge_child(string $prefix): string
{
    global $uploads;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --purge-child ' . escapeshellarg($prefix) . ' ' . escapeshellarg($uploads) . ' 2>&1';
    return trim((string) shell_exec($cmd));
}

/** $key is an Options key; the child derives the (prefixed) option name itself. */
function child(string $prefix, string $key, string $value): string
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child '
        . escapeshellarg($prefix) . ' ' . escapeshellarg($key) . ' ' . escapeshellarg($value) . ' 2>&1';
    return trim((string) shell_exec($cmd));
}

// The harness uploads folder: created only now, after the teardown that removes it.
if (!mkdir($uploads, 0700)) {
    fatal("cannot create {$uploads}");
}
use_uploads($uploads);
echo "uploads (harness-owned): {$uploads}\n";

$mutex = Options::name('mutex');

if ($mode === '--fail-after-purge') {
    $started = Runs::start(false);
    check(is_array($started) && read_row(Options::name('manual'))['exists'], 'issued a run under the prefix');
    $purged = Purge::run();
    check($purged['busy'] === false && !read_row(Options::name('manual'))['exists'], 'purge removed the issued run');
    echo "--fail-after-purge: failing fatally now\n";
    throw new RuntimeException('deliberate fatal right after purge');
}

$one = Options::name('settings');
$two = Options::name('items');
$three = Options::name('manual');

// Write to an absent option creates it, autoload off.
$r = Mutex::with(static fn () => Mutex::write($one, ['a' => 1]));
$row = read_row($one);
check($r === true && $row['exists'] && $row['value'] === serialize(['a' => 1]), 'write creates an absent option');
check(in_array($row['autoload'], ['no', 'off'], true), "created with autoload off (got {$row['autoload']})");
check(!read_row($mutex)['exists'], 'mutex released after with()');

// Update, unchanged update, cache coherence.
$r = Mutex::with(static fn () => Mutex::write($one, ['a' => 2]));
check($r === true && read_row($one)['value'] === serialize(['a' => 2]), 'write updates an existing option');
check(get_option($one) === ['a' => 2], 'get_option sees the update in this process');
$r = Mutex::with(static fn () => Mutex::write($one, ['a' => 2]));
check($r === true, 'writing an unchanged value succeeds while held');

// A row that existed with autoload on is left autoload off (fixture under the prefix; teardown removes it).
$autoloaded = Options::name('baseline');
$on = function_exists('wp_determine_option_autoload_value') ? wp_determine_option_autoload_value($autoloaded, 'x', 'x', true) : 'yes';
$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $autoloaded, 'same', $on));
check(read_row($autoloaded)['autoload'] === $on, "fixture row created with autoload {$on}");
$r = Mutex::with(static fn () => Mutex::write($autoloaded, 'same'));
$row = read_row($autoloaded);
check($r === true && $row['value'] === 'same' && in_array($row['autoload'], ['no', 'off'], true), "write over an autoloaded row leaves autoload off (got {$row['autoload']})");

// Token-conditional delete.
$r = Mutex::with(static fn () => Mutex::delete($one));
check($r === true && !read_row($one)['exists'], 'conditional delete removes the option');
check(get_option($one, 'gone') === 'gone', 'get_option sees the delete');

// Outside the section.
check(Mutex::write($one, 'x') === false && !read_row($one)['exists'], 'write outside with() refused, nothing written');

// A value written by another process is read fresh by the next with().
Mutex::with(static fn () => Mutex::write($three, 'v1'));
check(get_option($three) === 'v1', 'value cached in this process');
$out = child($prefix, 'manual', 'v2');
check($out === 'CHILD_WROTE', "child process wrote (said {$out})");
$seen = Mutex::with(static fn () => get_option($three));
check($seen === 'v2', 'next with() reads the other process\'s value, not the cached one (saw ' . var_export($seen, true) . ')');

// Takeover fences the old owner.
Mutex::with(static fn () => Mutex::write($two, 'before'));
$inner = Mutex::with(static function () use ($wpdb, $mutex, $two, $prefix): array {
    $held = (string) read_row($mutex)['value'];
    $stale = substr($held, 0, (int) strrpos($held, ':')) . ':' . (time() - 31);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $stale, $mutex, $held));
    $start = microtime(true);
    $said = child($prefix, 'items', 'from child');
    return [
        'child'  => $said,
        'fast'   => microtime(true) - $start < 4.0,
        'write'  => Mutex::write($two, 'from old owner'),
        'delete' => Mutex::delete($two),
    ];
});
check($inner['child'] === 'CHILD_WROTE' && $inner['fast'], "child took over a 31 s old lock without waiting (said {$inner['child']})");
check($inner['write'] === false, 'old owner write refused after takeover');
check($inner['delete'] === false, 'old owner delete refused after takeover');
check(read_row($two)['value'] === 'from child', 'option holds the new owner\'s value');

// Release by a non-owner is refused; a held lock makes the next caller busy.
$other = 'otherowner' . bin2hex(random_bytes(4)) . ':' . time();
Mutex::with(static function () use ($wpdb, $mutex, $other): void {
    $held = (string) read_row($mutex)['value'];
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $other, $mutex, $held));
});
check(read_row($mutex)['value'] === $other, 'old owner\'s release left the current owner\'s lock in place');
$start = microtime(true);
$r = Mutex::with(static fn () => Mutex::write($two, 'never'));
$waited = microtime(true) - $start;
check($r === Mutex::Busy && $waited >= 5.0 && $waited < 7.0, sprintf('held lock → Busy after %.1f s', $waited));
check(read_row($two)['value'] === 'from child', 'busy caller wrote nothing');

// ---------------------------------------------------------------- save versus purge

$manual = Options::name('manual');

// The busy test above left a foreign owner's lock (a harness row) in place.
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $mutex));
check(!read_row($mutex)['exists'], 'leftover harness lock removed');

// Control: an undisturbed save lands.
$run = Runs::start(false)['run'] ?? '';
check(Runs::save($run, []) === 'saved' && is_array(get_option($manual)) && isset(get_option($manual)['saved']), 'control: start → save stores the run');

// Purge before the save: refused as "no issued run".
$run = Runs::start(false)['run'] ?? '';
check(Purge::run()['busy'] === false, 'purge ran (real Purge::run, harness prefix)');
check(!read_row($manual)['exists'], 'purge deleted the manual option');
check(Runs::save($run, []) === 'no_run', 'save after purge → refused as no issued run');
check(!read_row($manual)['exists'], 'nothing landed after purge');

// Purge after save's validity check, before its write: the purge takes the
// stalled section over, and the save's fenced write is refused.
$run = Runs::start(false)['run'] ?? '';
$said = '';
Runs::before_write(static function () use ($wpdb, $mutex, $prefix, &$said): void {
    $held = (string) read_row($mutex)['value'];
    $stale = substr($held, 0, (int) strrpos($held, ':')) . ':' . (time() - 31);
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $stale, $mutex, $held));
    $said = purge_child($prefix);
});
$outcome = Runs::save($run, []);
Runs::before_write(null);
check($said === 'PURGED', "purge in another process took the stalled section over (said {$said})");
check($outcome === 'refused', "save whose check passed before purge started → write refused (got {$outcome})");
check(!read_row($manual)['exists'], 'the refused save left nothing behind the purge');

// Purge removes an empty probe folder — here only the harness uploads folder's.
mkdir($uploads . '/sfx-site-check', 0700);
check(Purge::run()['busy'] === false && !file_exists($uploads . '/sfx-site-check'), 'purge removed the empty probe folder (harness uploads folder)');

echo $failed === 0 ? "site-check-mutex-live: PASS\n" : "site-check-mutex-live: {$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
