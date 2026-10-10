<?php

declare(strict_types=1);

require __DIR__ . '/support/data-purge-stubs.php';
require dirname(__DIR__) . '/inc/DataPurge.php';

use SFX\DataPurge;

// -------------- Case 1: nothing outside the theme's namespace may be listed
//
// A NECESSARY condition, not a sufficient one. The prefix does not prove
// ownership — plugins on this estate use `sfx_` too, which is exactly why the
// purge works from a list. What this case rules out is the opposite mistake:
// the old list in uninstall.php named `thumbnail_size_w`, a WordPress CORE
// option, right beside a comment claiming core options were excluded. It never
// mattered while the file could not run. The button can, so the rule is
// enforced rather than trusted. Whether a listed `sfx_` option really belongs
// to the theme is a judgement this test cannot make; Case 2 covers the
// declared ones, and the class docblock owns the rest.

$names = DataPurge::option_names();

assert_true($names !== [], 'Case 1a: the purge names at least one option');

foreach ($names as $name) {
    assert_true(
        strpos($name, 'sfx_') === 0 || in_array($name, DataPurge::LEGACY_OPTION_NAMES, true),
        "Case 1b: '{$name}' is either sfx_-prefixed or a declared legacy key — no foreign option may be deleted"
    );
}

assert_same(
    false,
    in_array('thumbnail_size_w', $names, true),
    'Case 1c: thumbnail_size_w is a WordPress core option and must never be purged'
);

// ---------------- Case 2: a module's own option must be on the purge list
//
// The drift guard. uninstall.php's list was written once and never revisited,
// so module after module shipped an option it never named. This walks every
// module for an `OPTION_NAME` constant — the way a module declares what it
// stores — and fails when one is missing from the purge.
//
// It cannot see options written as bare literals, which is why the class
// docblock still asks for the list to be maintained by hand. It catches the
// common case, and a red test is a better reminder than a comment nobody
// reads.

$declared = [];

$module_files = array_merge(
    glob(dirname(__DIR__) . '/inc/*.php') ?: [],
    glob(dirname(__DIR__) . '/inc/*/*.php') ?: []
);

foreach ($module_files as $file) {
    $source = (string) file_get_contents($file);

    if (preg_match_all('/OPTION_NAME\s*=\s*[\'"]([a-z0-9_]+)[\'"]/i', $source, $matches) === 0) {
        continue;
    }

    foreach ($matches[1] as $option) {
        $declared[$option] = str_replace(dirname(__DIR__) . '/', '', $file);
    }
}

assert_true($declared !== [], 'Case 2a: the scan found at least one declared OPTION_NAME');

foreach ($declared as $option => $where) {
    assert_true(
        in_array($option, $names, true),
        "Case 2b: {$where} declares '{$option}' but the purge does not name it — add it to DataPurge::OPTION_NAMES"
    );
}

// ------------------------- Case 3: run() deletes ours and only ours
//
// The foreign options here are real: sfx_animation_options belongs to the
// SFX Animations plugin, which shares the prefix. sfx_mailcatch has no owner
// anywhere in the theme's history or in any plugin, so it is left alone —
// unknown provenance is a reason not to touch something.
//
// sfx_company_logo_options is the interesting one. An earlier version of this
// test called it a plugin option and asserted it must SURVIVE. That was wrong:
// git history shows the theme's own CompanyLogo module created it and 46d83d0
// removed the module without the option. Grepping the current tree cannot tell
// a removed module's leftovers from a stranger's data.

test_reset();

$GLOBALS['test_options'] = [
    'sfx_general_options'            => ['a' => 1],
    'sfx_media_credits_options'      => ['b' => 2],
    'sfx_wpoptimizer_options'        => ['c' => 3],
    'webp_conversion_log'            => 'legacy',
    // ours, left behind by modules this theme removed
    'sfx_company_logo_options'       => ['d' => 4],
    'sfx_contact_infos_options'      => ['e' => 5],
    // not ours — a plugin that shares the prefix
    'sfx_animation_options'          => 'plugin',
    // not ours — no owner found anywhere
    'sfx_mailcatch'                  => 'unknown',
    // not ours — WordPress core
    'thumbnail_size_w'               => 150,
    'blogname'                       => 'Site',
];

DataPurge::run();

$left = array_keys($GLOBALS['test_options']);
sort($left);

assert_same(
    ['blogname', 'sfx_animation_options', 'sfx_mailcatch', 'thumbnail_size_w'],
    $left,
    'Case 3a: theme options go, including a removed module\'s leftovers; plugin, unowned and core options stay'
);

// ----------------- Case 4: the Media Credits meta only goes when asked
//
// Copyright notices and AI markings are typed by editors and can matter
// legally. They are content, no less than the Contact Infos posts the purge
// leaves alone, so the default must be to keep them.

test_reset();
DataPurge::run();

assert_same([], $GLOBALS['test_meta_deleted'], 'Case 4a: by default the attachment meta survives the purge');

test_reset();
DataPurge::run(true);

sort($GLOBALS['test_meta_deleted']);

assert_same(
    ['_sfx_media_ai', '_sfx_media_copyright', '_sfx_media_iptc_prefilled'],
    $GLOBALS['test_meta_deleted'],
    'Case 4b: asked explicitly, all three keys go — the IPTC marker with them, or a reinstall skips the prefill'
);

// ------------------------- Case 5: transients, by prefix but not by `sfx_`
//
// The first version of this swept `_transient_sfx_%`, which would have taken
// SFX Feedback's abuse rate limit (sfx_feedback_shot_rl_<user>) and its
// half-filled form state with it — the very mistake the option list exists to
// avoid, repeated one method further down. These assertions pin the narrower
// behaviour: named theme prefixes, escaped, and demonstrably NOT a bare sfx_.

test_reset();
DataPurge::run();

// The transient statements only: the table drop (Case 7) talks to the
// database too, and is not what these assertions are about.
$sql = implode("\n", array_filter(
    $GLOBALS['test_queries'],
    static fn(string $q): bool => strpos($q, '_transient_') !== false
));

assert_true(strpos($sql, '_transient_sfx\_dashboard\_sys\_%') !== false, 'Case 5a: a theme prefix is swept, with its LIKE wildcards escaped');
assert_true(strpos($sql, '_transient_timeout_sfx\_css\_vars\_%') !== false, 'Case 5b: timeout rows go with their transient');
assert_true(strpos($sql, "'_transient_sfx_%'") === false, 'Case 5c: no blanket sfx_ sweep — plugins share that prefix');
assert_true(strpos($sql, 'gh_block') === false, 'Case 5d: no gh_block_ sweep — nothing in the theme or the plugins produces one');
assert_true(strpos($sql, 'feedback') === false, 'Case 5e: no prefix reaches the Feedback plugin transients');
assert_true(
    strpos($sql, 'wp_options') !== false && strpos($sql, 'wp_postmeta') === false,
    'Case 5f: the statements touch the options table only'
);

// ---------------------- Case 6: the typed confirmation, checked strictly
//
// This is the second half of the double confirmation and the only half that
// survives a request built by hand. The browser disables the button until the
// field matches; that is convenience. This is the guard.
//
// Surrounding whitespace is forgiven because a stray space is a typing
// accident, not a change of intent. Case is NOT forgiven: the field shows the
// exact phrase, and someone typing it in a different shape has not read what
// they were asked to type.

assert_same(
    true,
    DataPurge::confirmed(DataPurge::CONFIRMATION_PHRASE),
    'Case 6a: the exact phrase confirms'
);

assert_same(
    true,
    DataPurge::confirmed('  ' . DataPurge::CONFIRMATION_PHRASE . "\t"),
    'Case 6b: surrounding whitespace is forgiven'
);

foreach (
    [
        ''                              => 'an empty field',
        ' '                             => 'whitespace alone',
        'sfx-bricks'                    => 'a prefix of the phrase',
        'sfx-bricks-child-theme'        => 'the phrase with something appended',
        'SFX-BRICKS-CHILD'              => 'the phrase in a different case',
        'yes'                           => 'a confirmation that is not the phrase',
    ] as $input => $why
) {
    assert_same(false, DataPurge::confirmed($input), "Case 6c: {$why} does not confirm");
}

// ------------------- Case 7: the Redirects tables, dropped under the lock
//
// The rules table is written only under a MySQL named lock, and the purge
// takes the same one — computed from the same formula, because DataPurge must
// not depend on the module's classes. A table counts only when it existed
// before and is gone after: DROP TABLE IF EXISTS "succeeds" on nothing.

$lock = 'sfx_redirects_' . md5(DB_NAME . 'wp_');
$both = ['wp_sfx_redirects', 'wp_sfx_redirects_404'];

assert_same(['sfx_redirects', 'sfx_redirects_404'], DataPurge::table_names(), 'Case 7a: the purge names both Redirects tables');
assert_same(46, strlen($lock), 'Case 7b: the lock name is the 46-character per-site name the module uses');

test_reset();
$GLOBALS['test_tables'] = $both;
$report = DataPurge::run();
$sql    = implode("\n", $GLOBALS['test_queries']);

assert_same(2, $report['tables'], 'Case 7c: both existing tables are dropped and counted');
assert_same(false, $report['tables_locked'], 'Case 7d: a free lock is not reported as held');
assert_same([], $GLOBALS['test_tables'], 'Case 7e: and they are gone');
assert_true(strpos($sql, "SELECT GET_LOCK('{$lock}', 5)") !== false, 'Case 7f: the drop takes the module\'s lock, 5 s timeout');
assert_true(strpos($sql, "SELECT RELEASE_LOCK('{$lock}')") !== false, 'Case 7g: and releases it');
assert_same(
    2,
    substr_count($sql, "SELECT IS_USED_LOCK('{$lock}') = CONNECTION_ID()"),
    'Case 7h: the lock is re-checked before each DROP'
);
assert_true(strpos($sql, "SHOW TABLES LIKE 'wp\_sfx\_redirects\_404'") !== false, 'Case 7i: the existence check escapes the LIKE wildcards');
assert_true(in_array('sfx_redirects_cleanup', $GLOBALS['test_cleared_hooks'], true), 'Case 7j: the cleanup cron is unscheduled');

test_reset();
$GLOBALS['test_tables'] = ['wp_sfx_redirects'];
$report = DataPurge::run();

assert_same(1, $report['tables'], 'Case 7k: a table that never existed is not counted');

test_reset();
$GLOBALS['test_tables']     = $both;
$GLOBALS['test_drop_fails'] = true;
$report = DataPurge::run();

assert_same(0, $report['tables'], 'Case 7l: a DROP that left the table behind is not counted');

// Another redirect change holds the lock: nothing is dropped, the screen is
// told why, and the settings purge still goes ahead.
test_reset();
$GLOBALS['test_tables']    = $both;
$GLOBALS['test_lock_free'] = false;
$GLOBALS['test_options']   = ['sfx_general_options' => ['a' => 1]];
$report = DataPurge::run();
$sql    = implode("\n", $GLOBALS['test_queries']);

assert_same(0, $report['tables'], 'Case 7m: without the lock no table is dropped');
assert_same(true, $report['tables_locked'], 'Case 7n: and the held lock is reported');
assert_same($both, $GLOBALS['test_tables'], 'Case 7o: both tables survive');
assert_true(strpos($sql, 'DROP TABLE') === false, 'Case 7p: no DROP was even attempted');
assert_same(1, $report['options'], 'Case 7q: the settings are purged regardless');

// The lock is lost between the two drops (a silent reconnect): the second
// table stays, the loss is reported, and the release still runs.
test_reset();
$GLOBALS['test_tables']           = $both;
$GLOBALS['test_lock_checks_left'] = 1;
$report = DataPurge::run();
$sql    = implode("\n", $GLOBALS['test_queries']);

assert_same(1, $report['tables'], 'Case 7r: the drop made before the lock was lost is counted');
assert_same(true, $report['tables_locked'], 'Case 7s: the lost lock is reported');
assert_same(['wp_sfx_redirects_404'], $GLOBALS['test_tables'], 'Case 7t: the table after the loss is not dropped');
assert_true(strpos($sql, "SELECT RELEASE_LOCK('{$lock}')") !== false, 'Case 7u: the lock is released on the early exit too');

// ---------------------- Case 8: SiteCheck's eight options are on the purge list
//
// DataPurge is a root service and keeps literal lists, so the literals are
// compared against the module's own Options namespace here.

require_once dirname(__DIR__) . '/inc/SiteCheck/Options.php';

$site_check = [];
foreach (\SFX\SiteCheck\Options::KEYS as $key) {
    $site_check[] = \SFX\SiteCheck\Options::name($key);
}

assert_same(8, count($site_check), 'Case 8a: the module owns eight options');
assert_same($site_check, DataPurge::SITE_CHECK_OPTION_NAMES, 'Case 8b: the purge constant carries exactly the module\'s option names, in order');
foreach ($site_check as $name) {
    assert_true(in_array($name, DataPurge::option_names(), true), "Case 8c: {$name} is in option_names()");
}
assert_same(count(DataPurge::option_names()), count(array_unique(DataPurge::option_names())), 'Case 8d: no option is listed twice');

// SiteCheck's options are SiteCheck\Purge::run()'s to delete (in the spec's
// order, inside the module's critical section): the generic loop skips them,
// and the report adds what that segment says it did.
test_reset();
$GLOBALS['test_options'] = array_fill_keys($site_check, 'x');
$GLOBALS['test_options']['sfx_general_options'] = ['a' => 1];
$GLOBALS['test_options']['sfx_animation_options'] = 'plugin';
\SFX\SiteCheck\Purge::$report = ['options' => 7, 'probes_failed' => ['/up/sfx-site-check/a.php'], 'busy' => false];
$report = DataPurge::run();
assert_same(1, \SFX\SiteCheck\Purge::$calls, 'Case 8e: run() calls SiteCheck\\Purge::run() once');
$left = array_keys($GLOBALS['test_options']);
sort($left);
$want = array_merge($site_check, ['sfx_animation_options']);
sort($want);
assert_same($want, $left, 'Case 8f: the generic loop leaves the SiteCheck options to SiteCheck\\Purge and still deletes the theme\'s others');
assert_same(8, $report['options'], 'Case 8g: the report adds the segment\'s count to its own');
assert_same(['/up/sfx-site-check/a.php'], $report['probes_failed'], 'Case 8h: probes that could not be deleted are reported');
assert_same(false, $report['busy'], 'Case 8i: not busy');
assert_same('sfx_site_check_probe_cleanup', \SFX\SiteCheck\Options::hook('probe_cleanup'), 'Case 8j: the cleanup hook keeps the name the module schedules');

// Busy: the module's critical section was held. Nothing is deleted, so a
// retry finds everything where it was.
test_reset();
$GLOBALS['test_options'] = ['sfx_general_options' => ['a' => 1], 'sfx_site_check_settings' => 'x'];
$GLOBALS['test_tables'] = ['wp_sfx_redirects'];
\SFX\SiteCheck\Purge::$report = ['options' => 0, 'probes_failed' => [], 'busy' => true];
$report = DataPurge::run(true);
assert_same(true, $report['busy'], 'Case 8k: busy is reported');
assert_same(['sfx_general_options', 'sfx_site_check_settings'], array_keys($GLOBALS['test_options']), 'Case 8l: busy → no option deleted');
assert_same([], $GLOBALS['test_meta_deleted'], 'Case 8m: busy → no meta deleted');
assert_same(['wp_sfx_redirects'], $GLOBALS['test_tables'], 'Case 8n: busy → no table dropped');
assert_same(0, $report['options'] + $report['tables'] + $report['transients'] + $report['meta_keys'], 'Case 8o: busy → nothing counted');

// Gate B pass 1 (spec-9, quality-2): SiteCheck options whose delete was refused
// and kept unregistered test files travel in the report; the other modules'
// data is still deleted (they do not depend on SiteCheck's run).
test_reset();
$GLOBALS['test_options'] = ['sfx_general_options' => ['a' => 1], 'sfx_site_check_manual' => 'x'];
\SFX\SiteCheck\Purge::$report = ['options' => 1, 'probes_failed' => [], 'probes_unregistered' => ['/up/sfx-site-check/u.php'], 'refused' => ['sfx_site_check_manual'], 'busy' => false];
$report = DataPurge::run();
assert_same(['sfx_site_check_manual'], $report['site_check_refused'], 'Case 8p: refused SiteCheck deletes are reported');
assert_same(['/up/sfx-site-check/u.php'], $report['probes_unregistered'], 'Case 8q: unregistered test files are reported');
assert_same(false, $report['busy'], 'Case 8r: a refused delete is no busy purge');
assert_true(!isset($GLOBALS['test_options']['sfx_general_options']), 'Case 8s: the other theme data is still deleted');
\SFX\SiteCheck\Purge::$report = ['options' => 0, 'probes_failed' => [], 'refused' => [], 'hooks_failed' => ['sfx_site_check_probe_cleanup'], 'busy' => false];
$report = DataPurge::run();
assert_same(['sfx_site_check_probe_cleanup'], $report['site_check_hooks_failed'], 'Case 8t: a cron hook that could not be unscheduled is reported');

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all data-purge tests\n";
exit(0);
