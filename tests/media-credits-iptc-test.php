<?php

declare(strict_types=1);

require __DIR__ . '/support/media-credits-stubs.php';

require dirname(__DIR__) . '/inc/MediaCredits/Settings.php';
require dirname(__DIR__) . '/inc/MediaCredits/Credit.php';
require dirname(__DIR__) . '/inc/MediaCredits/MediaLibrary.php';

use SFX\MediaCredits\Credit;
use SFX\MediaCredits\MediaLibrary;

// ------------------------------------------------ Case 1: reading the field

assert_same('Agentur Nord', MediaLibrary::iptc_copyright(['copyright' => 'Agentur Nord']), 'Case 1a: copyright wins');
assert_same('Foto Müller', MediaLibrary::iptc_copyright(['credit' => 'Foto Müller']), 'Case 1b: credit is the fallback');
assert_same('Agentur Nord', MediaLibrary::iptc_copyright(['copyright' => 'Agentur Nord', 'credit' => 'Foto Müller']), 'Case 1c: copyright beats credit');
assert_same('', MediaLibrary::iptc_copyright([]), 'Case 1d: nothing there, nothing returned');
assert_same('', MediaLibrary::iptc_copyright(['copyright' => '   ']), 'Case 1e: whitespace is nothing');

// ------------------------------------------------------ Case 2: first upload

test_reset();
$meta = ['image_meta' => ['copyright' => 'Agentur Nord']];

MediaLibrary::prefill_iptc($meta, 11);

assert_same('Agentur Nord', get_post_meta(11, Credit::META_COPYRIGHT, true), 'Case 2a: the field is prefilled on upload');
assert_same('1', get_post_meta(11, Credit::META_IPTC_MARKER, true), 'Case 2b: and the marker records that it happened');

// ------------------------------- Case 3: regeneration must not resurrect it
// wp_generate_attachment_metadata fires again on regeneration, with context
// 'update' (wp-admin/includes/image.php:185) instead of 'create' (:750).
// Two independent guards, and the test proves each one alone.

update_post_meta(11, Credit::META_COPYRIGHT, '');
MediaLibrary::prefill_iptc($meta, 11, 'update');

assert_same('', get_post_meta(11, Credit::META_COPYRIGHT, true), 'Case 3a: context update writes nothing');

MediaLibrary::prefill_iptc($meta, 11, 'create');

assert_same('', get_post_meta(11, Credit::META_COPYRIGHT, true), 'Case 3b: and even a second create is stopped by the marker');

// The context guard alone, on an attachment that has never been seen. This is
// the mass-backfill case: switch the feature on, regenerate thumbnails site
// wide, and every older image with IPTC data would be written at once.
test_reset();
MediaLibrary::prefill_iptc(['image_meta' => ['copyright' => 'Agentur Nord']], 20, 'update');

assert_same('', get_post_meta(20, Credit::META_COPYRIGHT, true), 'Case 3c: an unseen attachment is not backfilled on regeneration');
assert_same('', get_post_meta(20, Credit::META_IPTC_MARKER, true), 'Case 3d: and no marker is written, so a real upload can still prefill');

// ---------------------------------------- Case 4: an editor's value is safe

test_reset();
update_post_meta(12, Credit::META_COPYRIGHT, 'Foto Müller');
MediaLibrary::prefill_iptc(['image_meta' => ['copyright' => 'Agentur Nord']], 12);

assert_same('Foto Müller', get_post_meta(12, Credit::META_COPYRIGHT, true), 'Case 4a: prefill never overwrites an existing value');

// ------------------------------------------------- Case 5: non-images, junk

test_reset();
MediaLibrary::prefill_iptc(['sizes' => []], 13);
assert_same('', get_post_meta(13, Credit::META_COPYRIGHT, true), 'Case 5a: no image_meta, no write');

$passthrough = ['image_meta' => ['copyright' => 'X']];
assert_same($passthrough, MediaLibrary::prefill_iptc($passthrough, 14), 'Case 5b: the filter returns its input unchanged');
assert_same('not an array', MediaLibrary::prefill_iptc('not an array', 15), 'Case 5c: a non-array metadata value passes straight through');
MediaLibrary::prefill_iptc(['image_meta' => ['copyright' => 'X']], 0);
assert_same('', get_post_meta(0, Credit::META_COPYRIGHT, true), 'Case 5d: id 0 writes nothing');

// ---------------------------------------------- Case 6: ai key sanitisation

assert_same('ai_generated', MediaLibrary::sanitize_ai_key('ai_generated'), 'Case 6a: a known slug survives');
assert_same('', MediaLibrary::sanitize_ai_key('ai_hallucinated'), 'Case 6b: an unknown slug becomes no marking');
assert_same('', MediaLibrary::sanitize_ai_key(''), 'Case 6c: empty stays empty');

// ------------------------------------------- Case 7: the list filter query

test_reset();

assert_same([], MediaLibrary::filter_meta_query(''), 'Case 7a: no filter selected, no meta query');
assert_same([], MediaLibrary::filter_meta_query('nonsense'), 'Case 7b: an unrecognised value is ignored, not guessed at');

$no_copyright = MediaLibrary::filter_meta_query('no_copyright');
assert_same('OR', $no_copyright['relation'] ?? null, 'Case 7c: "without copyright" needs both the absent and the empty case');
assert_same(Credit::META_COPYRIGHT, $no_copyright[0]['key'] ?? null, 'Case 7d: it queries the copyright key');
assert_same('NOT EXISTS', $no_copyright[0]['compare'] ?? null, 'Case 7e: rows that never had the meta');
assert_same('', $no_copyright[1]['value'] ?? null, 'Case 7f: and rows where it was cleared');

$any_ai = MediaLibrary::filter_meta_query('any_ai');
assert_same(Credit::META_AI, $any_ai[0]['key'] ?? null, 'Case 7g: "with AI marking" queries the AI key');
assert_same('!=', $any_ai[0]['compare'] ?? null, 'Case 7h: any non-empty slug counts');

$one = MediaLibrary::filter_meta_query('ai_generated');
assert_same('ai_generated', $one[0]['value'] ?? null, 'Case 7i: a single slug filters on that slug');
assert_same([], MediaLibrary::filter_meta_query('ai_hallucinated'), 'Case 7j: a slug outside the vocabulary is not a filter');

// ------------------------------------------ Case 8: filter_query()'s scope
//
// pre_get_posts fires for every query in the request, so each guard is
// pinned on its own: everything else about the call stays "would pass"
// while only the one guard under test is made to fail. If any single guard
// were dropped or reordered, its case here — and only its case — fails.

test_reset();

// Guard 1: not is_admin() rejects on its own.
$test_is_admin = false;
$pagenow = 'upload.php';
$_GET[MediaLibrary::FILTER_PARAM] = 'no_copyright';
$query = new Test_WP_Query(['post_type' => 'attachment'], true);
MediaLibrary::filter_query($query);
assert_same('', $query->get('meta_query'), 'Case 8a: not is_admin() rejects, meta_query stays untouched');
$test_is_admin = true;
unset($_GET[MediaLibrary::FILTER_PARAM]);
$pagenow = null;

// Guard 2: $pagenow must be exactly 'upload.php', not any other admin screen.
$pagenow = 'edit.php';
$_GET[MediaLibrary::FILTER_PARAM] = 'no_copyright';
$query = new Test_WP_Query(['post_type' => 'attachment'], true);
MediaLibrary::filter_query($query);
assert_same('', $query->get('meta_query'), 'Case 8b: the wrong $pagenow rejects, meta_query stays untouched');
unset($_GET[MediaLibrary::FILTER_PARAM]);
$pagenow = null;

// Guard 3: a secondary query on the right screen is still left alone.
$pagenow = 'upload.php';
$_GET[MediaLibrary::FILTER_PARAM] = 'no_copyright';
$query = new Test_WP_Query(['post_type' => 'attachment'], false);
MediaLibrary::filter_query($query);
assert_same('', $query->get('meta_query'), 'Case 8c: not the main query rejects, meta_query stays untouched');
unset($_GET[MediaLibrary::FILTER_PARAM]);
$pagenow = null;

// Guard 4a: a main query for a different post type is left alone.
$pagenow = 'upload.php';
$_GET[MediaLibrary::FILTER_PARAM] = 'no_copyright';
$query = new Test_WP_Query(['post_type' => 'post'], true);
MediaLibrary::filter_query($query);
assert_same('', $query->get('meta_query'), 'Case 8d: post_type=post rejects, meta_query stays untouched');
unset($_GET[MediaLibrary::FILTER_PARAM]);
$pagenow = null;

// Guard 4b: an empty post_type must NOT be accepted as attachment either —
// that would let this touch any other main query that happened to run on
// the upload.php screen.
$pagenow = 'upload.php';
$_GET[MediaLibrary::FILTER_PARAM] = 'no_copyright';
$query = new Test_WP_Query(['post_type' => ''], true);
MediaLibrary::filter_query($query);
assert_same('', $query->get('meta_query'), 'Case 8e: an empty post_type is not treated as attachment');
unset($_GET[MediaLibrary::FILTER_PARAM]);
$pagenow = null;

// Guard 5: every scope guard passes, but the value itself is not ours.
$pagenow = 'upload.php';
$_GET[MediaLibrary::FILTER_PARAM] = 'nonsense';
$query = new Test_WP_Query(['post_type' => 'attachment'], true);
MediaLibrary::filter_query($query);
assert_same('', $query->get('meta_query'), 'Case 8f: an unrecognised filter value sets no meta_query');
unset($_GET[MediaLibrary::FILTER_PARAM]);
$pagenow = null;

// Happy path: every guard passes and the value is ours — meta_query is set.
$pagenow = 'upload.php';
$_GET[MediaLibrary::FILTER_PARAM] = 'no_copyright';
$query = new Test_WP_Query(['post_type' => 'attachment'], true);
MediaLibrary::filter_query($query);
assert_same(
    MediaLibrary::filter_meta_query('no_copyright'),
    $query->get('meta_query'),
    'Case 8g: a recognised value on the right screen sets the meta_query'
);
unset($_GET[MediaLibrary::FILTER_PARAM]);
$pagenow = null;

// Combine, not replace: another plugin's meta_query must survive intact,
// wrapped together with ours under a new top-level AND — never discarded.
$existing = ['key' => '_some_other_plugin_flag', 'value' => '1', 'compare' => '='];
$pagenow = 'upload.php';
$_GET[MediaLibrary::FILTER_PARAM] = 'any_ai';
$query = new Test_WP_Query(['post_type' => 'attachment', 'meta_query' => $existing], true);
MediaLibrary::filter_query($query);
$combined = $query->get('meta_query');
assert_same('AND', $combined['relation'] ?? null, 'Case 8h: an existing meta_query is combined under AND, not discarded');
assert_same($existing, $combined[0] ?? null, 'Case 8i: the existing clause survives intact');
assert_same(MediaLibrary::filter_meta_query('any_ai'), $combined[1] ?? null, 'Case 8j: our clause is appended alongside it');
unset($_GET[MediaLibrary::FILTER_PARAM]);
$pagenow = null;

// ------------------------------------------- Case 9: the iptc_value filter

test_reset();
$GLOBALS['test_filter_returns']['sfx_media_credits_iptc_value'] = static function ($value, $args) {
    return 'Filtered Notice';
};
MediaLibrary::prefill_iptc(['image_meta' => ['copyright' => 'Agentur Nord']], 30);
assert_same('Filtered Notice', get_post_meta(30, Credit::META_COPYRIGHT, true), 'Case 9a: iptc_value filter rewrites the value before it is written');
unset($GLOBALS['test_filter_returns']['sfx_media_credits_iptc_value']);

// Suppression: an empty return skips the write, but the marker still records
// "we looked" — that is what it means, not "we wrote".
test_reset();
$GLOBALS['test_filter_returns']['sfx_media_credits_iptc_value'] = static function () {
    return '';
};
MediaLibrary::prefill_iptc(['image_meta' => ['copyright' => 'Agentur Nord']], 31);
assert_same('', get_post_meta(31, Credit::META_COPYRIGHT, true), 'Case 9b: a filter returning empty suppresses the write');
assert_same('1', get_post_meta(31, Credit::META_IPTC_MARKER, true), 'Case 9c: but the one-shot marker is still set');
unset($GLOBALS['test_filter_returns']['sfx_media_credits_iptc_value']);

// sanitize_text_field() runs on the filtered value, then the existing
// wp_slash() still runs on the way into update_post_meta().
test_reset();
$GLOBALS['test_filter_returns']['sfx_media_credits_iptc_value'] = static function () {
    return "  <b>O'Brien</b>  ";
};
MediaLibrary::prefill_iptc(['image_meta' => ['copyright' => 'Agentur Nord']], 32);
assert_same(addslashes("O'Brien"), get_post_meta(32, Credit::META_COPYRIGHT, true), 'Case 9d: sanitize_text_field runs on the filtered value, then wp_slash on the way into the write');
unset($GLOBALS['test_filter_returns']['sfx_media_credits_iptc_value']);

// The filter receives the value iptc_copyright() already picked, the full
// image_meta array, and the attachment id.
test_reset();
$captured = null;
$GLOBALS['test_filter_returns']['sfx_media_credits_iptc_value'] = static function ($value, $args) use (&$captured) {
    $captured = [$value, $args];
    return $value;
};
MediaLibrary::prefill_iptc(['image_meta' => ['copyright' => 'Agentur Nord', 'credit' => 'ignored']], 33);
assert_same('Agentur Nord', $captured[0] ?? null, 'Case 9e: the filter receives the value iptc_copyright() already picked');
assert_same(['copyright' => 'Agentur Nord', 'credit' => 'ignored'], $captured[1][0] ?? null, 'Case 9f: and the full image_meta array');
assert_same(33, $captured[1][1] ?? null, 'Case 9g: and the attachment id');
unset($GLOBALS['test_filter_returns']['sfx_media_credits_iptc_value']);

// ------------------------------------- Case 10: saved fires once from save()

test_reset();
update_post_meta(40, Credit::META_AI, 'ai_generated');
MediaLibrary::save(['ID' => 40], [MediaLibrary::FIELD_COPYRIGHT => 'Foto Müller']);
$calls = test_actions('sfx_media_credits_saved');
assert_same(1, count($calls), 'Case 10a: saved fires exactly once when only copyright is submitted');
assert_same([40, 'Foto Müller', 'ai_generated', 'save'], $calls[0] ?? null, 'Case 10b: arguments carry the post-write copyright and the current (untouched) AI value, context save');

test_reset();
update_post_meta(41, Credit::META_COPYRIGHT, 'Existing Notice');
MediaLibrary::save(['ID' => 41], [MediaLibrary::FIELD_AI => 'ai_edited']);
$calls = test_actions('sfx_media_credits_saved');
assert_same(1, count($calls), 'Case 10c: saved fires exactly once when only the AI field is submitted');
assert_same([41, 'Existing Notice', 'ai_edited', 'save'], $calls[0] ?? null, 'Case 10d: arguments carry the current (untouched) copyright and the post-write AI value');

// The load-bearing case: both fields submitted fires ONCE, not once per field.
test_reset();
MediaLibrary::save(['ID' => 42], [
    MediaLibrary::FIELD_COPYRIGHT => 'Both Notice',
    MediaLibrary::FIELD_AI        => 'ai_assisted',
]);
$calls = test_actions('sfx_media_credits_saved');
assert_same(1, count($calls), 'Case 10e: saved fires ONCE for a two-field save, not once per field');
assert_same([42, 'Both Notice', 'ai_assisted', 'save'], $calls[0] ?? null, 'Case 10f: arguments carry both post-write values');

test_reset();
MediaLibrary::save(['ID' => 43], ['some_unrelated_key' => 'x']);
assert_same([], test_actions('sfx_media_credits_saved'), 'Case 10g: saved does not fire when neither field is in the payload');

// ------------------------------ Case 11: saved fires only after a real IPTC write

test_reset();
MediaLibrary::prefill_iptc(['image_meta' => ['copyright' => 'Agentur Nord']], 50);
$calls = test_actions('sfx_media_credits_saved');
assert_same(1, count($calls), 'Case 11a: saved fires exactly once after an actual IPTC copyright write');
assert_same([50, 'Agentur Nord', '', 'iptc'], $calls[0] ?? null, 'Case 11b: arguments carry the post-write copyright, the current AI value, context iptc');

test_reset();
MediaLibrary::prefill_iptc(['image_meta' => ['copyright' => 'Agentur Nord']], 51, 'update');
assert_same([], test_actions('sfx_media_credits_saved'), 'Case 11c: the context guard returning early does not fire saved');

test_reset();
MediaLibrary::prefill_iptc(['image_meta' => ['copyright' => 'Agentur Nord']], 52);
$before = count(test_actions('sfx_media_credits_saved'));
MediaLibrary::prefill_iptc(['image_meta' => ['copyright' => 'Second Notice']], 52);
assert_same($before, count(test_actions('sfx_media_credits_saved')), 'Case 11d: the marker guard on a second create does not fire saved again');

test_reset();
MediaLibrary::prefill_iptc('not an array', 53);
assert_same([], test_actions('sfx_media_credits_saved'), 'Case 11e: a non-array metadata value does not fire saved');

test_reset();
MediaLibrary::prefill_iptc(['sizes' => []], 54);
assert_same([], test_actions('sfx_media_credits_saved'), 'Case 11f: metadata with no image_meta does not fire saved');

test_reset();
MediaLibrary::prefill_iptc(['image_meta' => []], 55);
assert_same([], test_actions('sfx_media_credits_saved'), 'Case 11g: an empty IPTC value does not fire saved');

test_reset();
update_post_meta(56, Credit::META_COPYRIGHT, 'Editor Wrote This');
MediaLibrary::prefill_iptc(['image_meta' => ['copyright' => 'Agentur Nord']], 56);
assert_same([], test_actions('sfx_media_credits_saved'), 'Case 11h: a field the editor already filled does not fire saved');

test_reset();
MediaLibrary::prefill_iptc(['image_meta' => ['copyright' => 'Agentur Nord']], 0);
assert_same([], test_actions('sfx_media_credits_saved'), 'Case 11i: id 0 does not fire saved');

// A filter suppressing the write means no write happened, so saved must not fire.
test_reset();
$GLOBALS['test_filter_returns']['sfx_media_credits_iptc_value'] = static function () {
    return '';
};
MediaLibrary::prefill_iptc(['image_meta' => ['copyright' => 'Agentur Nord']], 57);
assert_same([], test_actions('sfx_media_credits_saved'), 'Case 11j: a filter suppressing the write means saved does not fire');
unset($GLOBALS['test_filter_returns']['sfx_media_credits_iptc_value']);

// --------------------------- Case 12: reset_cache() runs before the action

test_reset();
$GLOBALS['test_attachment_url'][60] = 'https://example.test/img.jpg';
update_post_meta(60, Credit::META_COPYRIGHT, 'Old Notice');
Credit::for(60); // seed the per-request cache with the pre-save value

$seen = null;
add_action('sfx_media_credits_saved', static function ($id, $copyright, $ai_key, $context) use (&$seen) {
    $seen = Credit::for($id);
}, 10, 4);

MediaLibrary::save(['ID' => 60], [MediaLibrary::FIELD_COPYRIGHT => 'New Notice']);

assert_same('New Notice', $seen['copyright'] ?? null, 'Case 12a: a listener calling Credit::for() inside the action sees the NEW value, not the pre-save cache');

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all media-credits iptc tests\n";
exit(0);
