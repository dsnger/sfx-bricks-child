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

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all media-credits iptc tests\n";
exit(0);
