<?php

declare(strict_types=1);

require __DIR__ . '/support/media-credits-stubs.php';

require dirname(__DIR__) . '/inc/MediaCredits/Settings.php';
require dirname(__DIR__) . '/inc/MediaCredits/Credit.php';

use SFX\MediaCredits\Settings;
use SFX\MediaCredits\Credit;

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

// ------------------------------------------------ Case 4: line composition

test_reset();
$GLOBALS['test_attachment_url'][5] = 'https://example.test/photo.jpg';

// Copyright only.
$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = 'Foto Müller';
Credit::reset_cache();
$credit = Credit::for(5);
assert_same('Foto Müller', $credit['copyright'], 'Case 4a: the stored copyright comes back raw in the parts');
assert_same('©&nbsp;Foto Müller', $credit['line'], 'Case 4b: the © prefix is added');
assert_same('', $credit['ai_label'], 'Case 4c: no AI key means no label');

// A notice that already carries its own mark is left alone, in all three
// spellings. Two © would look like a typo, not like diligence.
foreach (['© Foto Müller', '(c) Foto Müller', 'Copyright Foto Müller'] as $i => $text) {
    $GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = $text;
    Credit::reset_cache();
    assert_same(esc_html($text), Credit::for(5)['line'], "Case 4d{$i}: an existing mark is not doubled");
}

// Both parts.
$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = 'Foto Müller';
$GLOBALS['test_post_meta'][5][Credit::META_AI]        = 'ai_generated';
Credit::reset_cache();
assert_same('©&nbsp;Foto Müller&nbsp;·&nbsp;KI-generiert', Credit::for(5)['line'], 'Case 4e: both parts, joined');

// AI part only.
$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = '';
Credit::reset_cache();
assert_same('KI-generiert', Credit::for(5)['line'], 'Case 4f: the separator drops out when one part is empty');

// Neither part.
$GLOBALS['test_post_meta'][5][Credit::META_AI] = '';
Credit::reset_cache();
assert_same('', Credit::for(5)['line'], 'Case 4g: nothing stored, nothing composed');

// An unknown slug is not a label.
$GLOBALS['test_post_meta'][5][Credit::META_AI] = 'ai_hallucinated';
Credit::reset_cache();
$credit = Credit::for(5);
assert_same('', $credit['ai_key'], 'Case 4h: an unknown slug sanitizes to empty');
assert_same('', $credit['line'], 'Case 4i: and produces no line');

// ------------------------------------------------------ Case 5: the seal

test_reset();
$GLOBALS['test_attachment_url'][5] = 'https://example.test/photo.jpg';
$GLOBALS['test_post_meta'][5][Credit::META_AI] = 'ai_generated';
$GLOBALS['test_options'][Settings::OPTION_NAME] = [
    'credit_display'    => 'icon',
    'icon_size'         => 32,
    'seal_ai_generated' => 90,
];
$GLOBALS['test_is_image'][90] = true;
$GLOBALS['test_attachment_img'][90] = 'https://example.test/seal.svg';

Credit::reset_cache();
$line = Credit::for(5)['line'];
assert_contains('src="https://example.test/seal.svg"', $line, 'Case 5a: icon mode renders the seal');
assert_contains('alt="KI-generiert"', $line, 'Case 5b: in icon mode the alt carries the label');
assert_contains('width="32"', $line, 'Case 5c: the configured size is applied');
assert_not_contains('>KI-generiert', $line, 'Case 5d: icon mode prints no visible label text');

$GLOBALS['test_options'][Settings::OPTION_NAME]['credit_display'] = 'icon_text';
Credit::reset_cache();
$line = Credit::for(5)['line'];
assert_contains('alt=""', $line, 'Case 5e: with the label visible the seal alt is empty, or SR users hear it twice');
assert_contains('KI-generiert', $line, 'Case 5f: icon_text still prints the label');

// A seal whose attachment was deleted must not become a broken image.
$GLOBALS['test_attachment_img'][90] = false;
$GLOBALS['test_options'][Settings::OPTION_NAME]['credit_display'] = 'icon';
Credit::reset_cache();
assert_same('KI-generiert', Credit::for(5)['line'], 'Case 5g: a deleted seal falls back to the label text');

// ----------------------------------------------------- Case 6: the fallback

test_reset();
$GLOBALS['test_attachment_url'][5] = 'https://example.test/photo.jpg';
$GLOBALS['test_options'][Settings::OPTION_NAME] = ['fallback_copyright' => '© Kundenname'];

Credit::reset_cache();
assert_same('© Kundenname', Credit::for(5)['copyright'], 'Case 6a: the fallback fills an empty field');

$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = 'Foto Müller';
Credit::reset_cache();
assert_same('Foto Müller', Credit::for(5)['copyright'], 'Case 6b: a stored value beats the fallback');

// A deleted attachment gets nothing — not even the fallback. Bricks keeps the
// id and renders a placeholder; a credit there would name an owner who never
// took the picture.
$GLOBALS['test_attachment_url'][5] = false;
Credit::reset_cache();
$gone = Credit::for(5);
assert_same(true, is_array($gone), 'Case 6c: the return type does not change for a missing attachment');
assert_same('', $gone['line'], 'Case 6d: and every part is empty');
assert_same('', $gone['copyright'], 'Case 6e: the fallback does not leak onto a missing image');
assert_same(0, Credit::for(0)['icon_id'], 'Case 6f: id 0 is handled without a lookup');

// -------------------------------------------------- Case 7: escaping

test_reset();
$GLOBALS['test_attachment_url'][5] = 'https://example.test/photo.jpg';

$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = '<script>alert(1)</script>Foto';
Credit::reset_cache();
assert_not_contains('<script>', Credit::for(5)['line'], 'Case 7a: markup in the copyright is escaped');

// Braces are the load-bearing case: Bricks parses the finished document for
// dynamic tags, so an unescaped {echo:…} in a copyright field would execute.
$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = '{echo:phpinfo}';
Credit::reset_cache();
$line = Credit::for(5)['line'];
assert_not_contains('{echo:', $line, 'Case 7b: braces from the copyright field never reach the page');
// Exact string, not a substring: this is what catches a double-encoded
// &amp;#123; from a wrong escaping order, which a contains() check would pass.
assert_same('©&nbsp;&#123;echo:phpinfo&#125;', $line, 'Case 7c: exactly one round of entity escaping');

// A filter runs AFTER composition, so both gates have to run after it.
$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = 'Foto';

$GLOBALS['test_filter_returns']['sfx_media_credits_line'] = static function () {
    return '{post_title}';
};
Credit::reset_cache();
assert_not_contains('{post_title}', Credit::for(5)['line'], 'Case 7d: a filter cannot reintroduce a parseable tag');

$GLOBALS['test_filter_returns']['sfx_media_credits_line'] = static function () {
    return 'Foto <script>alert(1)</script><em>ok</em>';
};
Credit::reset_cache();
$line = Credit::for(5)['line'];
assert_not_contains('<script>', $line, 'Case 7e: a filter cannot inject script either');
assert_contains('<em>ok</em>', $line, 'Case 7f: but it can still add ordinary markup');

unset($GLOBALS['test_filter_returns']['sfx_media_credits_line']);

assert_same('&#123;a&#125;', Credit::escape_braces('{a}'), 'Case 7g: escape_braces is available on its own');

// ------------------------------------------------------ Case 8: memoisation

test_reset();
$GLOBALS['test_attachment_url'][5] = 'https://example.test/photo.jpg';
$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = 'First';
Credit::reset_cache();
assert_same('First', Credit::for(5)['copyright'], 'Case 8a: first read');
$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = 'Second';
assert_same('First', Credit::for(5)['copyright'], 'Case 8b: the second read comes from the per-request cache');
Credit::reset_cache();
assert_same('Second', Credit::for(5)['copyright'], 'Case 8c: reset_cache is the test seam');

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all media-credits credit tests\n";
exit(0);
