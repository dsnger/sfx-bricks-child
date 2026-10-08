<?php
// Manual live check for TelFormat — read-only, creates nothing. Run from the theme root:
//   "$PHP" tests/support/tel-format-live-check.php
declare(strict_types=1);

$root = realpath(__DIR__ . '/../../../../../');
if ($root === false || !is_file($root . '/wp-load.php')) {
    fwrite(STDERR, "FATAL: site root not found from " . __DIR__ . "\n");
    exit(2);
}
// A run that ends anywhere but the last line (wp_die() during bootstrap exits 0 by default) is a failure.
$completed = false;
register_shutdown_function(static function () use (&$completed): void {
    if (!$completed) {
        fwrite(STDERR, "ABORTED before completion\n");
        exit(5);
    }
});
define('WP_USE_THEMES', false);
define('DISABLE_WP_CRON', true);
require $root . '/wp-load.php';

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

// Preconditions from existing content. (Not "$page": setup_postdata() overwrites that WordPress global.)
$coaches = get_posts(['post_type' => 'coach', 'post_status' => 'publish', 'numberposts' => 3,
    'meta_key' => 'phone', 'meta_compare' => '!=', 'meta_value' => '', 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids']);
$social = (int) (get_posts(['post_type' => 'sfx_social_account', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids'])[0] ?? 0);
if (count($coaches) < 2 || $social === 0 || (string) get_post_meta($social, '_link_url', true) === '') {
    fwrite(STDERR, "PRECONDITION: need two published coaches with a phone and a published social account with a URL\n");
    $completed = true; // explicit status, not an abort
    exit(3);
}
$a = (int) $coaches[0];
$context_post = (int) (get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'exclude' => $coaches])[0] ?? 0);
$check($context_post > 0 && !in_array($context_post, $coaches, true), "0: page context {$context_post} is not a loop item");
$tel = static fn(int $id): string => \SFX\TelNormalizer::normalize_tel((string) get_post_meta($id, 'phone', true));

// 1. Real query loop rendered by Bricks, page context set to another post.
$elements = [
    ['id' => 'tfloop', 'name' => 'container', 'parent' => 0, 'children' => ['tfbtn1', 'tfbtn2'],
     'settings' => ['hasLoop' => true, 'query' => ['objectType' => 'post', 'post_type' => ['coach'],
        'post__in' => $coaches, 'orderby' => 'post__in', 'posts_per_page' => count($coaches)]]],
    ['id' => 'tfbtn1', 'name' => 'button', 'parent' => 'tfloop', 'children' => [],
     'settings' => ['text' => '{acf_phone}', 'link' => ['type' => 'external', 'url' => 'tel:{acf_phone @format:tel}']]],
    ['id' => 'tfbtn2', 'name' => 'button', 'parent' => 'tfloop', 'children' => [],
     'settings' => ['text' => 'id', 'link' => ['type' => 'external', 'url' => 'tel:{post_id @format:tel}']]],
];
$GLOBALS['post'] = get_post($context_post);
setup_postdata($GLOBALS['post']);
$html = \Bricks\Frontend::render_data($elements);
$items = preg_split('/(?=<div class="brxe-tfloop )/', $html, -1, PREG_SPLIT_NO_EMPTY);
$items = array_values(array_filter($items, static fn(string $c): bool => strpos($c, 'brxe-tfbtn1') !== false));
$check(count($items) === count($coaches), '1: one rendered item per coach (got ' . count($items) . ')');
foreach ($coaches as $i => $id) {
    $chunk = $items[$i] ?? '';
    $h1 = preg_match('/class="brxe-tfbtn1[^"]*" href="([^"]*)">(.*?)<\/a>/s', $chunk, $m1) === 1 ? $m1 : ['', '(none)', '(none)'];
    $h2 = preg_match('/class="brxe-tfbtn2[^"]*" href="([^"]*)"/', $chunk, $m2) === 1 ? $m2[1] : '(none)';
    $check($h1[1] === 'tel:' . $tel($id), "1: item {$id} phone href {$h1[1]}");
    $check($h1[2] === esc_html((string) get_post_meta($id, 'phone', true)), "1: item {$id} label as entered");
    $check($h2 === 'tel:' . $id, "1: item {$id} own post_id href {$h2} (not the page {$context_post})");
}
$check(strpos($html, '@format:tel') === false, '1: no raw tag left');

// 2. render_data path on its own, render_content in link context, and a non-ACF provider.
$expect_a = 'tel:' . $tel($a);
$rd = apply_filters('bricks/frontend/render_data', '<p>tel:{acf_phone @format:tel}</p>', get_post($a));
$check($rd === "<p>{$expect_a}</p>", '2: render_data path -> ' . $rd);
$rc = bricks_render_dynamic_data('tel:{acf_phone @format:tel}', $a, 'link');
$check($rc === $expect_a, '2: render_content link context -> ' . $rc);
$cf = bricks_render_dynamic_data('tel:{cf_phone @format:tel}', $a, 'link');
$check($cf === $expect_a, '2: cf_ tag -> ' . $cf);

// 3. Counterfactuals: each hook contributes on its own; without both, the raw tag stays.
$C = \SFX\TelFormat\Controller::class;
remove_filter('bricks/dynamic_data/render_content', [$C, 'render_content'], 9);
$rd2 = apply_filters('bricks/frontend/render_data', '<p>tel:{acf_phone @format:tel}</p>', get_post($a));
$check($rd2 === "<p>{$expect_a}</p>", '3: render_data alone resolves -> ' . $rd2);
remove_filter('bricks/frontend/render_data', [$C, 'render_data'], 9);
$raw = bricks_render_dynamic_data('tel:{acf_phone @format:tel}', $a, 'link');
$check(strpos($raw, '@format:tel') !== false, '3: without the module the raw tag stays -> ' . $raw);
add_filter('bricks/dynamic_data/render_content', [$C, 'render_content'], 9, 3);
$rc2 = bricks_render_dynamic_data('tel:{acf_phone @format:tel}', $a, 'link');
$check($rc2 === $expect_a, '3: render_content alone resolves -> ' . $rc2);
add_filter('bricks/frontend/render_data', [$C, 'render_data'], 9, 2);

// 4. Compatibility through the full pipeline: baselines from existing content, then identical without the module.
$phone_a = (string) get_post_meta($a, 'phone', true);
$cases = [
    "{acf_phone @fallback:'x' @format:tel}" => $phone_a,  // Bricks today: fallback swallows the rest
    '{acf_phone:plain @format:tel}'        => $phone_a,  // Bricks today, text context
    '{acf_phone}'                          => $phone_a,
    "{social_account:url:{$social}}"       => (string) get_post_meta($social, '_link_url', true),
    "{social_account:url:{$social} @format:tel}" => (string) get_post_meta($social, '_link_url', true), // theme parser drops it
];
$with = [];
foreach ($cases as $tag => $expected) {
    $with[$tag] = bricks_render_dynamic_data($tag, $a, 'text');
    $check($with[$tag] === $expected, "4: baseline {$tag} -> {$with[$tag]}");
}
remove_filter('bricks/dynamic_data/render_content', [$C, 'render_content'], 9);
remove_filter('bricks/frontend/render_data', [$C, 'render_data'], 9);
foreach ($cases as $tag => $expected) {
    $check(bricks_render_dynamic_data($tag, $a, 'text') === $with[$tag], "4: identical without module {$tag}");
}

echo $failures === 0 ? "tel-format-live-check: PASS\n" : "tel-format-live-check: {$failures} FAILED\n";
$completed = true;
exit($failures === 0 ? 0 : 1);
