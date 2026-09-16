<?php

declare(strict_types=1);

/**
 * What register_meta() is asked for, and who the auth callback lets through.
 *
 * The two editor-facing fields are writable over REST; the IPTC marker is
 * not. Before this, all three carried show_in_rest => false and a write over
 * REST was accepted with 200 and silently dropped.
 */

require __DIR__ . '/support/media-credits-stubs.php';

require dirname(__DIR__) . '/inc/MediaCredits/Settings.php';
require dirname(__DIR__) . '/inc/MediaCredits/Credit.php';
require dirname(__DIR__) . '/inc/MediaCredits/MediaLibrary.php';

use SFX\MediaCredits\Credit;
use SFX\MediaCredits\MediaLibrary;

// --------------------------------------- Case 1: the two fields are in REST

test_reset();
MediaLibrary::register_meta();

foreach ([Credit::META_COPYRIGHT => 'copyright', Credit::META_AI => 'AI marking'] as $key => $label) {
    $args = test_registered_meta($key);

    assert_true(is_array($args), "Case 1: {$label} is registered at all");

    // true, or the array form carrying a schema — both mean exposed. What
    // must never be true again is a bare false.
    assert_true(($args['show_in_rest'] ?? false) !== false, "Case 1: {$label} is exposed in REST");
    assert_same('attachment', $args['object_subtype'] ?? null, "Case 1: {$label} is scoped to attachments");

    // The whole point of the change. An underscore-prefixed key is protected
    // meta, so register_meta() defaults auth_callback to __return_false;
    // show_in_rest alone would read and never write.
    assert_same(
        [MediaLibrary::class, 'can_edit_attachment'],
        $args['auth_callback'] ?? null,
        "Case 1: {$label} carries an explicit auth callback"
    );
}

// Sanitisation must survive the move: a REST write goes through
// sanitize_meta(), so these are the callbacks that police an incoming value.
assert_same(
    'sanitize_text_field',
    test_registered_meta(Credit::META_COPYRIGHT)['sanitize_callback'] ?? null,
    'Case 1: copyright is still sanitised'
);
assert_same(
    [MediaLibrary::class, 'sanitize_ai_key'],
    test_registered_meta(Credit::META_AI)['sanitize_callback'] ?? null,
    'Case 1: AI marking is still restricted to a known key'
);

// ------------------------------- Case 1b: a typo is an error, not a silent ''
// sanitize_ai_key() maps anything unrecognised to '', which over REST would
// clear the field and answer 200 — the failure mode this whole change exists
// to remove. The schema enum turns it into a 400 before sanitising runs.

$enum = test_registered_meta(Credit::META_AI)['show_in_rest']['schema']['enum'] ?? null;

assert_true(is_array($enum), 'Case 1b: the AI marking carries a schema enum');
assert_same(
    ['', 'ai_generated', 'ai_edited', 'ai_assisted', 'digitally_altered'],
    $enum,
    'Case 1b: it is the closed slug list, plus the empty value that means no marking'
);

// Whatever sanitize_ai_key() accepts, the enum must accept too — otherwise a
// value the admin form can store is one REST refuses.
foreach ($enum as $slug) {
    assert_same($slug, MediaLibrary::sanitize_ai_key($slug), "Case 1b: '{$slug}' survives sanitising");
}

// ------------------------------------- Case 2: the marker stays internal

$marker = test_registered_meta(Credit::META_IPTC_MARKER);

assert_same(false, $marker['show_in_rest'] ?? null, 'Case 2a: the IPTC marker is not exposed');
assert_same('attachment', $marker['object_subtype'] ?? null, 'Case 2b: and is scoped to attachments too');

// ---------------------------------------------- Case 3: who may write

test_reset();

// $allowed is whatever map_meta_cap() decided before the callback ran. For
// protected meta that is false, and it must not be what decides the answer —
// passing it through would restore exactly the behaviour being fixed.
test_grant_cap('edit_post', 42, true);

assert_same(
    true,
    MediaLibrary::can_edit_attachment(false, Credit::META_COPYRIGHT, 42),
    'Case 3a: a user who may edit the attachment may write the field'
);

assert_same(
    false,
    MediaLibrary::can_edit_attachment(true, Credit::META_COPYRIGHT, 43),
    'Case 3b: a user who may not edit it may not write, whatever $allowed said'
);

// REST hands the id through as a string on some routes, and a meta write with
// no object at all resolves to 0.
assert_same(
    true,
    MediaLibrary::can_edit_attachment(false, Credit::META_COPYRIGHT, '42'),
    'Case 3c: a numeric-string id is the same attachment'
);

assert_same(
    false,
    MediaLibrary::can_edit_attachment(false, Credit::META_COPYRIGHT, 0),
    'Case 3d: no attachment, no write'
);

assert_same(
    false,
    MediaLibrary::can_edit_attachment(false, Credit::META_COPYRIGHT, -1),
    'Case 3e: nor a negative id'
);

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all media-credits rest tests\n";
exit(0);
