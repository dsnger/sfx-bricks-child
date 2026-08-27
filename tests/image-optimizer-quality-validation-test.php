<?php

/**
 * Assert an unusable stored quality can never reach the encoder.
 *
 * ImportExport sanitises sfx_webp_quality with absint(), which makes it a
 * non-negative integer without bounding it, and
 * Settings::migrate_legacy_options copies a legacy webp_quality across
 * unexamined. Neither is changed by this fix and neither calls anything here:
 * both reach the rule only through the pre_update_option_sfx_webp_quality
 * filter, and only while ImageOptimizer is enabled to register it. Earlier
 * versions could also already have stored anything, so the read path validates
 * whatever it finds.
 *
 * Why it matters: the lossless threshold is quality >= 100, so a stored 250
 * silently selected lossless rather than "even better quality", and lossless
 * measured 834,772 bytes in 1.94s against 87,622 bytes in 0.74s at quality 80.
 *
 * Settings has no imports and no load-time side effects, so it loads here with
 * a get_option() stub standing in for WordPress.
 *
 * Run: php tests/image-optimizer-quality-validation-test.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/ImageOptimizer/Constants.php';

// Stand-in for WordPress. Defined in the global namespace, which is where
// Settings' unqualified get_option() call resolves from.
$GLOBALS['sfx_test_options'] = [];

function get_option(string $name, $default = false)
{
    return array_key_exists($name, $GLOBALS['sfx_test_options'])
        ? $GLOBALS['sfx_test_options'][$name]
        : $default;
}

require_once dirname(__DIR__) . '/inc/ImageOptimizer/Settings.php';

use SFX\ImageOptimizer\Constants;
use SFX\ImageOptimizer\Settings;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf(
            "FAIL: %s\n  expected: %s\n  actual:   %s\n",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
        exit(1);
    }
}

function with_stored_quality($value): int
{
    $GLOBALS['sfx_test_options']['sfx_webp_quality'] = $value;

    return Settings::get_quality();
}

// Pin the constants the assertions below depend on, so a change to them
// surfaces here rather than silently redefining what this test proves.
assert_same(80, Constants::DEFAULT_QUALITY, 'DEFAULT_QUALITY is 80 -- update this test if that changes');
assert_same(1, Constants::MIN_QUALITY, 'MIN_QUALITY is 1 -- update this test if that changes');
assert_same(100, Constants::MAX_QUALITY, 'MAX_QUALITY is 100 -- update this test if that changes');

// No stored value at all: the default must survive untouched, so the guard
// cannot be masking a wrong default.
unset($GLOBALS['sfx_test_options']['sfx_webp_quality']);
assert_same(Constants::DEFAULT_QUALITY, Settings::get_quality(), 'absent option must yield DEFAULT_QUALITY');

// The regression: an import or legacy migration can store any value. Above the
// ceiling this silently meant lossless before the guard.
assert_same(Constants::DEFAULT_QUALITY, with_stored_quality(250), 'stored 250 must fall back to DEFAULT_QUALITY');
assert_same(Constants::DEFAULT_QUALITY, with_stored_quality(101), 'stored 101 must fall back to DEFAULT_QUALITY');

// Crucially, NOT to the nearest bound: 100 is the lossless threshold, so
// clamping would leave the site on the slow, large encode this guards against.
assert_same(false, with_stored_quality(250) === Constants::MAX_QUALITY, 'out-of-range must not become MAX_QUALITY');
assert_same(false, with_stored_quality(250) >= 100, 'out-of-range must not land on the lossless threshold');

// The mirror case: the UI cannot produce 0, an import can, and 0 is the worst
// possible quality rather than a sensible floor.
assert_same(Constants::DEFAULT_QUALITY, with_stored_quality(0), 'stored 0 must fall back to DEFAULT_QUALITY');
assert_same(Constants::DEFAULT_QUALITY, with_stored_quality(-5), 'stored negative must fall back to DEFAULT_QUALITY');

// The bounds themselves are valid and must pass through, not be rejected by an
// off-by-one. A deliberate 100 from the UI still means lossless.
assert_same(Constants::MAX_QUALITY, with_stored_quality(Constants::MAX_QUALITY), 'MAX_QUALITY is valid and passes through');
assert_same(Constants::MIN_QUALITY, with_stored_quality(Constants::MIN_QUALITY), 'MIN_QUALITY is valid and passes through');

// In-range values must be untouched, including the string forms WordPress
// returns for options that round-tripped through the database.
assert_same(80, with_stored_quality(80), 'in-range 80 is unchanged');
assert_same(85, with_stored_quality('85'), 'in-range numeric string is cast and unchanged');
assert_same(Constants::DEFAULT_QUALITY, with_stored_quality('250'), 'out-of-range numeric string is cast then rejected');

// Return type must stay int for callers that compare against the lossless
// threshold with >=.
assert_same(true, is_int(with_stored_quality('85')), 'get_quality must return int, not string');

// The shared helper, asserted directly. Nothing calls it across a module
// boundary -- ImportExport reaches it only through the WordPress option filter
// -- but both the read path and that filter resolve to this one rule, so the
// reader and the write boundary cannot disagree about what a valid quality is.
assert_same(Constants::DEFAULT_QUALITY, Settings::sanitize_quality(250), 'helper rejects out-of-range');
assert_same(Constants::DEFAULT_QUALITY, Settings::sanitize_quality(0), 'helper rejects zero');
assert_same(85, Settings::sanitize_quality('85'), 'helper casts and accepts in-range strings');
assert_same(Constants::MAX_QUALITY, Settings::sanitize_quality(100), 'helper accepts the lossless boundary');

// --- The write boundary --------------------------------------------------
//
// The rule is enforced on write by a WordPress filter,
// pre_update_option_sfx_webp_quality, registered in ImageOptimizer\Controller.
// It covers writers this module does not call: ImportExport sanitises this key
// with absint(), which does not bound it, and AGENTS.md keeps ImportExport a
// catalogue of option names rather than a caller of other feature modules.
//
// These assert the callback for the shapes those writers can deliver. The
// registration itself, and its order against the legacy migration, are
// exercised separately at the end of this file.
//
// The read-path assertions above remain non-redundant: the filter is absent
// while the feature is disabled, is not reached by add_option or direct SQL,
// and never saw rows written before it existed.

$writer_shapes = [
    // [what a writer can hand update_option, what must be stored]
    [250,                        Constants::DEFAULT_QUALITY, 'absint() output above the range'],
    [0,                          Constants::DEFAULT_QUALITY, 'absint() output below the range'],
    ['250',                      Constants::DEFAULT_QUALITY, 'numeric string above the range'],
    [[250],                      Constants::DEFAULT_QUALITY, 'array left by a recursive sanitiser'],
    [[],                         Constants::DEFAULT_QUALITY, 'empty array'],
    [true,                       Constants::DEFAULT_QUALITY, 'boolean'],
    [null,                       Constants::DEFAULT_QUALITY, 'null'],
    ['85junk',                   Constants::DEFAULT_QUALITY, 'junk string that casts into range'],
    [85.7,                       Constants::DEFAULT_QUALITY, 'fractional float'],
    [json_decode('85.0', true),  Constants::DEFAULT_QUALITY, 'whole-number float, as JSON delivers it'],
    [json_decode('85', true),    85,                         'JSON integer'],
    [85,                         85,                         'in-range integer'],
    ['85',                       85,                         'in-range numeric string'],
    [' 85 ',                     85,                         'whitespace-padded integer string'],
    [100,                        Constants::MAX_QUALITY,     'the lossless boundary'],
];

foreach ($writer_shapes as [$written, $expected, $label]) {
    assert_same($expected, Settings::sanitize_quality($written), "write boundary: $label");
    assert_same(true, is_int(Settings::sanitize_quality($written)), "write boundary returns int: $label");
}

// --- The load-bearing registration order, behaviourally --------------------
//
// Controller must register the filter BEFORE calling
// Settings::migrate_legacy_options(), which writes a legacy webp_quality with
// update_option. Registered after, the migration writes an unvalidated value on
// the one request that performs it -- a defect that shipped in an earlier
// revision of this change while every other assertion here stayed green.
//
// This constructs the real Controller against a small fake of the options and
// hook APIs. Settings needs only get_option/update_option/delete_option/__, and
// AdminPage, Ajax and AssetManager only register hooks, so the fake is small
// enough to be worth it: unlike a source-order check it also proves the filter
// is reachable, that the registered callback is the right one, and that it
// actually rejects the value.

$GLOBALS['sfx_hooks'] = [];

function add_filter(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['sfx_hooks'][$hook][] = $callback;

    return true;
}

function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
{
    return add_filter($hook, $callback, $priority, $accepted_args);
}

function apply_filters(string $hook, $value)
{
    foreach ($GLOBALS['sfx_hooks'][$hook] ?? [] as $callback) {
        $value = $callback($value);
    }

    return $value;
}

// add_option deliberately does NOT apply pre_update_option_{$option}, because
// WordPress's does not either. update_option filters first and hands the
// filtered value down, so modelling the split is what proves the creation path
// stores a validated value rather than the raw one.
function add_option(string $name, $value, $deprecated = '', $autoload = null): bool
{
    if (array_key_exists($name, $GLOBALS['sfx_test_options'])) {
        return false;
    }

    $GLOBALS['sfx_test_options'][$name] = $value;

    return true;
}

// Mirrors wp-includes/option.php: filter, bail if unchanged, then delegate to
// add_option when the row does not exist yet.
function update_option(string $name, $value, $autoload = null): bool
{
    $old_value = $GLOBALS['sfx_test_options'][$name] ?? false;
    $value = apply_filters("pre_update_option_{$name}", $value);

    if ($value === $old_value) {
        return false;
    }

    if (!array_key_exists($name, $GLOBALS['sfx_test_options'])) {
        return add_option($name, $value);
    }

    $GLOBALS['sfx_test_options'][$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    unset($GLOBALS['sfx_test_options'][$name]);

    return true;
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

require_once dirname(__DIR__) . '/inc/ImageOptimizer/AdminPage.php';
require_once dirname(__DIR__) . '/inc/ImageOptimizer/Ajax.php';
require_once dirname(__DIR__) . '/inc/ImageOptimizer/AssetManager.php';
require_once dirname(__DIR__) . '/inc/ImageOptimizer/Controller.php';

// A site mid-upgrade: the legacy key holds a value no bounded writer produces,
// the new key does not exist yet, and the migration has not run.
$GLOBALS['sfx_test_options'] = ['webp_quality' => 250];

// The new key must be absent, so the migration below takes WordPress's
// option-CREATION path rather than its update path. That branch is the one a
// review flagged as bypassing the filter; update_option applies the filter and
// passes the filtered value to add_option, so it does not.
assert_same(false, array_key_exists('sfx_webp_quality', $GLOBALS['sfx_test_options']), 'the new key must not exist yet');

new \SFX\ImageOptimizer\Controller();

// The migration ran during construction. If the filter were registered after
// it -- as it once was -- 250 would be sitting in the option right now.
assert_same(
    Constants::DEFAULT_QUALITY,
    $GLOBALS['sfx_test_options']['sfx_webp_quality'] ?? null,
    'the legacy migration must not be able to create an out-of-range quality'
);

// Same value, now via the UPDATE path, since the row exists from here on.
update_option('sfx_webp_quality', 250);
assert_same(Constants::DEFAULT_QUALITY, $GLOBALS['sfx_test_options']['sfx_webp_quality'], 'the update path rejects out-of-range too');

// And the filter stays in force for every later write, not just the migration.
update_option('sfx_webp_quality', 250);
assert_same(Constants::DEFAULT_QUALITY, $GLOBALS['sfx_test_options']['sfx_webp_quality'], 'a later out-of-range write is rejected');

update_option('sfx_webp_quality', 85);
assert_same(85, $GLOBALS['sfx_test_options']['sfx_webp_quality'], 'a later in-range write passes through');

update_option('sfx_webp_quality', [250]);
assert_same(Constants::DEFAULT_QUALITY, $GLOBALS['sfx_test_options']['sfx_webp_quality'], 'a later malformed write is rejected');

echo "OK\n";
