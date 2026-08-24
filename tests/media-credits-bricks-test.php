<?php

declare(strict_types=1);

require __DIR__ . '/support/media-credits-stubs.php';
require __DIR__ . '/support/media-credits-bricks-stubs.php';

require dirname(__DIR__) . '/inc/MediaCredits/Settings.php';
require dirname(__DIR__) . '/inc/MediaCredits/Credit.php';
require dirname(__DIR__) . '/inc/MediaCredits/Bricks.php';

use SFX\MediaCredits\Bricks;
use SFX\MediaCredits\Credit;
use SFX\MediaCredits\Settings;

$test_thumbnail_ids   = [];
$test_current_post_id = 0;

/** Give attachment $id a copyright and an AI slug. */
function seed_attachment(int $id, string $copyright = '', string $ai = ''): void
{
    $GLOBALS['test_attachment_url'][$id] = "https://example.test/{$id}.jpg";
    $GLOBALS['test_post_meta'][$id][Credit::META_COPYRIGHT] = $copyright;
    $GLOBALS['test_post_meta'][$id][Credit::META_AI] = $ai;
    Credit::reset_cache();
}

// --------------------------------------------- Case 1: hook registration
// Priority 20 on render_tag is load-bearing, exactly as documented in
// NavMenuQuery\MenuItemTags: Bricks registers its own handler at 10 before
// our theme can hook at all, and its handler re-wraps unknown tags in braces.

Bricks::register();

$render_tag = test_registrations('bricks/dynamic_data/render_tag');
assert_same(1, count($render_tag), 'Case 1a: render_tag registered once');
assert_same(20, $render_tag[0]['priority'], 'Case 1b: at priority 20, behind Bricks own handler');
assert_same(3, $render_tag[0]['accepted_args'], 'Case 1c: with all three arguments');

$settings_hook = test_registrations('bricks/element/settings');
assert_same(2, $settings_hook[0]['accepted_args'], 'Case 1d: element settings filter takes the instance too');

$render_element = test_registrations('bricks/frontend/render_element');
assert_same(2, $render_element[0]['accepted_args'], 'Case 1e: render_element filter takes the instance too');

// --------------------------------------------- Case 2: tag recognition

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'Foto Müller', 'ai_generated');

$post = new WP_Post(['ID' => 5, 'post_type' => 'attachment']);

assert_same('Foto Müller', Bricks::render_tag('sfx_media_copyright', $post, null), 'Case 2a: the bare tag resolves');
assert_same('Foto Müller', Bricks::render_tag('{sfx_media_copyright}', $post, null), 'Case 2b: the brace-wrapped form resolves too — Bricks re-wraps unknown tags');
assert_same('KI-generiert', Bricks::render_tag('{sfx_media_ai_label}', $post, null), 'Case 2c: the label tag');
assert_contains('©&nbsp;Foto Müller', Bricks::render_tag('{sfx_media_credit}', $post, null), 'Case 2d: the composed line');

// A tag that is not ours must come back BYTE-IDENTICAL. Returning '' or a
// normalised copy destroys the value for every provider after us.
assert_same('{post_title}', Bricks::render_tag('{post_title}', $post, null), 'Case 2e: a foreign tag is passed through untouched');
assert_same('{sfx_media_nonsense}', Bricks::render_tag('{sfx_media_nonsense}', $post, null), 'Case 2f: an unknown suffix in our namespace is left alone');
assert_same(['x'], Bricks::render_tag(['x'], $post, null), 'Case 2g: a non-string tag is passed through');

// ------------------------------------------ Case 3: context resolution
// Explicit id beats loop object beats $post beats featured image.

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'From post');
seed_attachment(6, 'From loop');
seed_attachment(7, 'From explicit id');
seed_attachment(8, 'From featured image');

$post = new WP_Post(['ID' => 5, 'post_type' => 'attachment']);

assert_same('From post', Bricks::render_tag('{sfx_media_copyright}', $post, null), 'Case 3a: a $post that is an attachment');

\Bricks\Query::$looping     = true;
\Bricks\Query::$loop_object = new WP_Post(['ID' => 6, 'post_type' => 'attachment']);
assert_same('From loop', Bricks::render_tag('{sfx_media_copyright}', $post, null), 'Case 3b: the loop object beats $post');

assert_same('From explicit id', Bricks::render_tag('{sfx_media_copyright:7}', $post, null), 'Case 3c: an explicit id beats everything');

\Bricks\Query::reset();
$GLOBALS['test_thumbnail_ids'][42] = 8;
$GLOBALS['test_current_post_id']   = 42;
$page = new WP_Post(['ID' => 42, 'post_type' => 'page']);
assert_same('From featured image', Bricks::render_tag('{sfx_media_copyright}', $page, null), 'Case 3d: a non-attachment post falls back to its featured image');

$GLOBALS['test_thumbnail_ids'] = [];
assert_same('', Bricks::render_tag('{sfx_media_copyright}', $page, null), 'Case 3e: nothing resolvable renders empty, never the literal tag');

// ------------------------------------------------ Case 4: render_content

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'Foto Müller');
$post = new WP_Post(['ID' => 5, 'post_type' => 'attachment']);

$out = Bricks::render_content('Bild: {sfx_media_copyright}.', $post, null);
assert_same('Bild: Foto Müller.', $out, 'Case 4a: the tag is replaced in place');
assert_same('Nothing here.', Bricks::render_content('Nothing here.', $post, null), 'Case 4b: content without our tags is untouched');
assert_contains('{post_title}', Bricks::render_content('{post_title}', $post, null), 'Case 4c: foreign tags survive');

// Braces from stored data must not reach the page even through the tags.
seed_attachment(5, '{echo:phpinfo}');
assert_not_contains('{echo:', Bricks::render_content('{sfx_media_copyright}', $post, null), 'Case 4d: brace escaping applies to tag output too');

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all media-credits bricks tests\n";
exit(0);
