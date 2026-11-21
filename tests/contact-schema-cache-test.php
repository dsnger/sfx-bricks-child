<?php

declare(strict_types=1);

// Shortcode cache lifecycle with the third contact type (spec § "Shortcode cache lifecycle").
require __DIR__ . '/support/social-bricks-stubs.php';
require dirname(__DIR__) . '/inc/ContactInfos/FieldRegistry.php';
require dirname(__DIR__) . '/inc/TelNormalizer.php';
require dirname(__DIR__) . '/inc/ContactInfos/PostType.php';
require dirname(__DIR__) . '/inc/ContactInfos/Shortcode/SC_ContactInfos.php';

use SFX\ContactInfos\PostType;
use SFX\ContactInfos\Shortcode\SC_ContactInfos;

$sc = new SC_ContactInfos();
$types = ['main', 'branch', 'contact_point'];
$seed = static function () use ($types): void {
    global $test_transients;
    $test_transients = [];
    foreach ($types as $i => $type) {
        $test_transients['sfx_contact_info_type_' . $type] = 500 + $i;
        $test_transients['sfx_contact_info_type_' . $type . '_phone'] = 'x';
    }
};
$left = static fn(): array => array_keys($GLOBALS['test_transients']);

// type_cache_cleared_for_contact_point_on_save
$seed();
$sc->clear_contact_info_caches(500);
assert_true(!in_array('sfx_contact_info_type_contact_point', $left(), true), 'type_cache_cleared_for_contact_point_on_save');

// retype_clears_all_three_type_lookups: any save clears every type lookup, so old and new type both refresh
$seed();
$sc->clear_contact_info_caches(501);
foreach ($types as $type) {
    assert_true(!in_array('sfx_contact_info_type_' . $type, $left(), true), "retype_clears_all_three_type_lookups: {$type}");
}

// manual_clear_covers_contact_point
$seed();
$test_post_lists = []; // the stub ignores fields=ids
PostType::clear_all_contact_caches();
assert_same([], $left(), 'manual_clear_covers_contact_point: lookups and type field caches all gone');

// unpublished_cached_contact_not_returned
$test_posts[510] = sfx_make_post(510, 'sfx_contact_info', 'publish', 'Old');
$test_posts[511] = sfx_make_post(511, 'sfx_contact_info', 'publish', 'New');
$test_meta[510] = ['_phone' => ['111']];
$test_meta[511] = ['_phone' => ['222']];
$test_post_lists['sfx_contact_info'] = [$test_posts[511]];
$test_transients = ['sfx_contact_info_type_contact_point' => 510];
$args = ['field' => 'phone', 'type' => 'contact_point', 'link' => 'false'];
assert_same('111', $sc->render_contact_info($args), 'cached published contact is used');
$test_posts[510]->post_status = 'draft';
$test_transients = ['sfx_contact_info_type_contact_point' => 510];
assert_same('222', $sc->render_contact_info($args), 'unpublished_cached_contact_not_returned: fresh lookup instead');
assert_same(511, $test_transients['sfx_contact_info_type_contact_point'], 'and the cache holds the fresh ID');

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}
echo "contact-schema-cache-test: PASS\n";
