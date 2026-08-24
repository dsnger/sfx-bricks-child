<?php

declare(strict_types=1);

require __DIR__ . '/support/media-credits-stubs.php';

require dirname(__DIR__) . '/inc/MediaCredits/Settings.php';

use SFX\MediaCredits\Settings;

// ------------------------------------------------- Case 1: label vocabulary

assert_same(
    ['ai_generated', 'ai_edited', 'ai_assisted', 'digitally_altered'],
    array_keys(Settings::get_default_labels()),
    'Case 1a: the four slugs, in order'
);

// A filter that invents a key: the invention is dropped.
$GLOBALS['test_filter_returns']['sfx_media_credits_labels'] = static function ($labels) {
    return $labels + ['ai_hallucinated' => 'Erfunden'];
};
assert_same(
    ['ai_generated', 'ai_edited', 'ai_assisted', 'digitally_altered'],
    array_keys(Settings::get_labels()),
    'Case 1b: a filter cannot add a slug'
);

// A filter that drops keys: the defaults are restored, so no stored value
// loses its label. array_intersect_key() alone would leave two slugs blank.
$GLOBALS['test_filter_returns']['sfx_media_credits_labels'] = static function () {
    return ['ai_generated' => 'AI generated'];
};
$labels = Settings::get_labels();
assert_same('AI generated', $labels['ai_generated'], 'Case 1c: a filter can reword');
assert_same('KI-bearbeitet', $labels['ai_edited'], 'Case 1d: a dropped key falls back to its default wording');
assert_same(4, count($labels), 'Case 1e: the map keeps all four slugs');

// A filter returning nonsense: ignored.
$GLOBALS['test_filter_returns']['sfx_media_credits_labels'] = static function () {
    return 'not an array';
};
assert_same(4, count(Settings::get_labels()), 'Case 1f: a non-array filter return is ignored');

unset($GLOBALS['test_filter_returns']['sfx_media_credits_labels']);

// ---------------------------------------------------- Case 2: normalisation

$clean = Settings::normalize([
    'output_mode'        => 'overlay',
    'force_wrapper'      => '1',
    'credit_display'     => 'icon_text',
    'icon_size'          => '40',
    'fallback_copyright' => '  <b>Foto Müller</b>  ',
    'seal_ai_generated'  => '77',
]);

assert_same('overlay', $clean['output_mode'], 'Case 2a: a known output mode survives');
assert_same(true, $clean['force_wrapper'], 'Case 2b: the checkbox becomes a bool');
assert_same('icon_text', $clean['credit_display'], 'Case 2c: a known display mode survives');
assert_same(40, $clean['icon_size'], 'Case 2d: icon size becomes an int');
assert_same('Foto Müller', $clean['fallback_copyright'], 'Case 2e: the fallback is sanitized and trimmed');

$dirty = Settings::normalize([
    'output_mode'    => 'explode',
    'credit_display' => 'hologram',
    'icon_size'      => 9000,
]);

assert_same('off', $dirty['output_mode'], 'Case 2f: an unknown output mode falls back to the default');
assert_same('text', $dirty['credit_display'], 'Case 2g: an unknown display mode falls back to the default');
assert_same(128, $dirty['icon_size'], 'Case 2h: an oversized icon is clamped to the maximum');
assert_same(8, Settings::normalize(['icon_size' => 1])['icon_size'], 'Case 2i: a tiny icon is clamped to the minimum');
assert_same('', $dirty['fallback_copyright'], 'Case 2j: a missing key takes its default');

// Seals: only real images survive.
$GLOBALS['test_is_image'][77] = true;
$GLOBALS['test_is_image'][78] = false;
$seals = Settings::normalize(['seal_ai_generated' => 77, 'seal_ai_edited' => 78]);
assert_same(77, $seals['seal_ai_generated'], 'Case 2k: an image id is kept');
assert_same(0, $seals['seal_ai_edited'], 'Case 2l: a non-image id is discarded');

// ------------------------------------------------------- Case 3: read path
// The importer can write an option while the feature is disabled, in which
// case register_setting() never ran and nothing validated it. get() is the
// second gate, and it has to be as strict as the first.

$GLOBALS['test_options'][Settings::OPTION_NAME] = [
    'output_mode' => 'explode',
    'icon_size'   => 9000,
];

assert_same('off', Settings::get('output_mode'), 'Case 3a: get() rejects a stored value that is not a known mode');
assert_same(128, Settings::get('icon_size'), 'Case 3b: get() clamps a stored icon size');
assert_same('', Settings::get('fallback_copyright'), 'Case 3c: get() returns the default for an absent key');

$GLOBALS['test_options'][Settings::OPTION_NAME] = 'not an array';
assert_same('off', Settings::get('output_mode'), 'Case 3d: a corrupt option value degrades to defaults');

test_reset();

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all media-credits credit tests\n";
exit(0);
