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

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all media-credits iptc tests\n";
exit(0);
