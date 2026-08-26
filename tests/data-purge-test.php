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

$sql = implode("\n", $GLOBALS['test_queries']);

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

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all data-purge tests\n";
exit(0);
