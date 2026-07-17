<?php

declare(strict_types=1);

require __DIR__ . '/support/social-bricks-stubs.php';

require dirname(__DIR__) . '/inc/ContactInfos/FieldRegistry.php';
require dirname(__DIR__) . '/inc/ContactInfos/PostType.php';
require dirname(__DIR__) . '/inc/ContactInfos/Shortcode/SC_ContactInfos.php';
require dirname(__DIR__) . '/inc/ContactInfos/Controller.php';
require dirname(__DIR__) . '/inc/SocialMediaAccounts/FieldRegistry.php';
require dirname(__DIR__) . '/inc/SocialMediaAccounts/PostType.php';
require dirname(__DIR__) . '/inc/SocialMediaAccounts/Shortcode/SC_SocialAccounts.php';
require dirname(__DIR__) . '/inc/SocialMediaAccounts/Controller.php';

use SFX\ContactInfos\Controller as ContactInfosController;
use SFX\SocialMediaAccounts\Controller as SocialMediaAccountsController;
use SFX\SocialMediaAccounts\FieldRegistry as SocialFieldRegistry;
use SFX\SocialMediaAccounts\Shortcode\SC_SocialAccounts;

$sc = new SC_SocialAccounts();

// Case 8 — contact regression (run first)
$contact_result = ContactInfosController::render_bricks_dynamic_tag('{contact_info:email:99}', null);
assert_contains('billing@example.test', $contact_result, 'Case 8: contact email tag');

// Case 1 — social HTML shortcode
$html = $sc->render_single_account(['id' => '123']);
assert_contains('https://social.example/ig', $html, 'Case 1: HTML contains URL');
assert_contains('<img', $html, 'Case 1: HTML contains image');

// Cases 2–4 — scalar fields
run_social_account_field_case('Case 2: field=url', function (SC_SocialAccounts $sc): void {
    $actual = $sc->render_account_field(['id' => '123', 'field' => 'url']);
    assert_same('https://social.example/ig', $actual, 'Case 2: scalar URL');
});

run_social_account_field_case('Case 3: field=icon', function (SC_SocialAccounts $sc): void {
    $actual = $sc->render_account_field(['id' => '123', 'field' => 'icon']);
    assert_same('https://cdn.example/icon.svg', $actual, 'Case 3: scalar icon');
});

run_social_account_field_case('Case 4: field=title', function (SC_SocialAccounts $sc): void {
    $actual = $sc->render_account_field(['id' => '123', 'field' => 'title']);
    assert_same('Instagram', $actual, 'Case 4: scalar title');
});

// Case 9 — meta normalization via HTML path
$html124 = $sc->render_single_account(['id' => '124']);
assert_contains('https://array-meta.example/x', $html124, 'Case 9: array meta normalized in HTML');

// Cases 10–14 — validation
run_social_account_field_case('Case 10: missing id', function (SC_SocialAccounts $sc): void {
    assert_same('', $sc->render_account_field(['field' => 'url']), 'Case 10');
});

run_social_account_field_case('Case 11: wrong post type', function (SC_SocialAccounts $sc): void {
    assert_same('', $sc->render_account_field(['id' => '200', 'field' => 'url']), 'Case 11');
});

run_social_account_field_case('Case 12: draft post', function (SC_SocialAccounts $sc): void {
    assert_same('', $sc->render_account_field(['id' => '201', 'field' => 'url']), 'Case 12');
});

run_social_account_field_case('Case 13: invalid field', function (SC_SocialAccounts $sc): void {
    assert_same('', $sc->render_account_field(['id' => '123', 'field' => 'not_a_field']), 'Case 13');
});

run_social_account_field_case('Case 14: unknown id', function (SC_SocialAccounts $sc): void {
    assert_same('', $sc->render_account_field(['id' => '999', 'field' => 'url']), 'Case 14');
});

// Cases 5–7 — social Bricks
run_social_bricks_case('Case 5: {social_account:url:123}', 'render_bricks_dynamic_tag', function (): void {
    $actual = SocialMediaAccountsController::render_bricks_dynamic_tag('{social_account:url:123}', null);
    assert_same('https://social.example/ig', $actual, 'Case 5: Bricks URL tag');
});

run_social_bricks_case('Case 6: {social_account:url}', 'render_bricks_dynamic_tag', function (): void {
    $actual = SocialMediaAccountsController::render_bricks_dynamic_tag('{social_account:url}', null);
    assert_same('', $actual, 'Case 6: ID-less tag returns empty');
});

run_social_bricks_case('Case 7: {social_accounts}', 'render_bricks_dynamic_content', function (): void {
    $actual = SocialMediaAccountsController::render_bricks_dynamic_content('X {social_accounts} Y');
    assert_contains('social-accounts', $actual, 'Case 7: Bricks list tag');
});

run_social_bricks_case('Case 16: attribute-context title escaping', 'render_bricks_dynamic_content', function (): void {
    $actual = SocialMediaAccountsController::render_bricks_dynamic_content(
        'title="{social_account:title:125}"',
        null,
        'attribute'
    );

    assert_same('title="ACME &quot;Social&quot; &amp; Co"', $actual, 'Case 16: title is escaped for attributes');
});

run_social_account_field_case('Case 17: HTML sanitization and target fallback', function (SC_SocialAccounts $sc): void {
    $actual = $sc->render_single_account(['id' => '126', 'class' => 'one" two', 'target' => 'popup']);

    assert_same('', $sc->render_account_field(['id' => '127', 'field' => 'url']), 'Case 17: invalid scalar URL rejected');
    assert_contains('class="social-account social-account-126 social-account-medium one&quot; two"', $actual, 'Case 17: custom classes escaped');
    assert_contains('href="https://safe.example/profile"', $actual, 'Case 17: safe URL rendered');
    assert_contains('target="_blank"', $actual, 'Case 17: invalid target falls back');
    assert_contains('title="Follow &quot;Us&quot; &amp; Co"', $actual, 'Case 17: link title escaped');
    assert_contains('alt="Unsafe &quot;Account&quot; &amp; Co"', $actual, 'Case 17: image alt escaped');
});

run_social_account_field_case('Case 18: cache generation invalidates old output', function (SC_SocialAccounts $sc): void {
    global $test_transients, $test_options;
    $list_cache_keys = static function () use (&$test_transients): array {
        return array_filter(array_keys($test_transients), static function (string $key): bool {
            return strpos($key, 'sfx_social_accounts_') === 0;
        });
    };

    $initial_keys = $list_cache_keys();
    $before = $sc->render_all_accounts(['class' => 'cache-case']);
    $after_first_render_keys = $list_cache_keys();
    $sc->clear_social_account_caches(123);
    $after = $sc->render_all_accounts(['class' => 'cache-case']);
    $after_second_render_keys = $list_cache_keys();

    assert_same($before, $after, 'Case 18: generation change preserves rendered output');

    assert_true((int) ($test_options['sfx_social_accounts_cache_gen'] ?? 0) > 0, 'Case 18: cache generation option increments');
    assert_same(1, count(array_diff($after_first_render_keys, $initial_keys)), 'Case 18: first render creates one list cache key');
    assert_same(1, count(array_diff($after_second_render_keys, $after_first_render_keys)), 'Case 18: generation change creates a distinct list cache key');
});

// Case 15 — Bricks tag list generation
run_social_bricks_case('Case 15: add_bricks_dynamic_tag', 'add_bricks_dynamic_tag', function (): void {
    $tags = SocialMediaAccountsController::add_bricks_dynamic_tag([]);
    $names = array_column($tags, 'name');

    assert_true(in_array('{social_accounts}', $names, true), 'Case 15: list tag registered');

    foreach (SocialFieldRegistry::get_fields() as $field => $label) {
        $expected = '{social_account:' . $field . ':123}';
        assert_true(in_array($expected, $names, true), "Case 15: per-field tag for {$field}");
    }

    $url_tag = null;
    foreach ($tags as $tag) {
        if (($tag['name'] ?? '') === '{social_account:url:123}') {
            $url_tag = $tag;
            break;
        }
    }
    assert_true($url_tag !== null, 'Case 15: url tag entry exists');
    assert_contains('Instagram', (string) ($url_tag['label'] ?? ''), 'Case 15: tag label includes account title');
});

// Cases 19–25 — loop context fallback for ID-less tags
run_social_bricks_case('Case 19: ID-less tag resolves from $post context', 'render_bricks_dynamic_tag', function (): void {
    global $test_posts;
    $actual = SocialMediaAccountsController::render_bricks_dynamic_tag('{social_account:url}', $test_posts[123]);
    assert_same('https://social.example/ig', $actual, 'Case 19: resolves against contextual social account');
});

run_social_bricks_case('Case 20: ID-less tag on a different post type', 'render_bricks_dynamic_tag', function (): void {
    global $test_posts;
    $actual = SocialMediaAccountsController::render_bricks_dynamic_tag('{social_account:url}', $test_posts[200]);
    assert_same('', $actual, 'Case 20: non-social context does not resolve');
});

run_social_bricks_case('Case 21: ID-less tag on a draft account', 'render_bricks_dynamic_tag', function (): void {
    global $test_posts;
    $actual = SocialMediaAccountsController::render_bricks_dynamic_tag('{social_account:url}', $test_posts[201]);
    assert_same('', $actual, 'Case 21: draft context does not resolve');
});

run_social_bricks_case('Case 22: explicit ID wins over context', 'render_bricks_dynamic_tag', function (): void {
    global $test_posts;
    $actual = SocialMediaAccountsController::render_bricks_dynamic_tag('{social_account:url:123}', $test_posts[124]);
    assert_same('https://social.example/ig', $actual, 'Case 22: explicit ID is not overridden by context');
});

run_social_bricks_case('Case 23: ID-less tag falls back to get_the_ID()', 'render_bricks_dynamic_tag', function (): void {
    global $test_current_post_id;
    $test_current_post_id = 124;
    $actual = SocialMediaAccountsController::render_bricks_dynamic_tag('{social_account:url}', null);
    $test_current_post_id = 0;
    assert_same('https://array-meta.example/x', $actual, 'Case 23: null $post falls back to current post');
});

run_social_bricks_case('Case 24: {social_accounts} not swallowed by context', 'render_bricks_dynamic_content', function (): void {
    global $test_posts;
    $actual = SocialMediaAccountsController::render_bricks_dynamic_content('X {social_accounts} Y', $test_posts[123]);
    assert_contains('social-accounts', $actual, 'Case 24: render-all tag keeps its meaning in context');
});

run_social_bricks_case('Case 25: ID-less tag resolves per loop item via render_content', 'render_bricks_dynamic_content', function (): void {
    global $test_posts;
    $actual = SocialMediaAccountsController::render_bricks_dynamic_content(
        'style="--icon: url({social_account:icon})"',
        $test_posts[123]
    );
    assert_same('style="--icon: url(https://cdn.example/icon.svg)"', $actual, 'Case 25: icon resolves for the loop item');
});

run_social_bricks_case('Case 26: explicit :0 must not fall back to context', 'render_bricks_dynamic_tag', function (): void {
    global $test_posts, $test_current_post_id;
    $test_current_post_id = 123;
    // An explicitly supplied ID is honoured even when invalid: {social_account:url:0}
    // returned '' before the loop-context fallback existed and must keep doing so.
    $actual = SocialMediaAccountsController::render_bricks_dynamic_tag('{social_account:url:0}', $test_posts[123]);
    $test_current_post_id = 0;
    assert_same('', $actual, 'Case 26: explicit zero ID stays empty');
});

run_social_bricks_case('Case 27: unusable $post context does not fall back globally', 'render_bricks_dynamic_tag', function (): void {
    global $test_current_post_id;
    $test_current_post_id = 123;
    // Context was supplied but is not a post: do not guess via get_the_ID().
    $actual = SocialMediaAccountsController::render_bricks_dynamic_tag('{social_account:url}', new \stdClass());
    $test_current_post_id = 0;
    assert_same('', $actual, 'Case 27: garbage context stays empty');
});

run_social_bricks_case('Case 28: numeric-ish context does not alias a post ID', 'render_bricks_dynamic_tag', function (): void {
    // "123.9" / "1.23e2" must not truncate into post 123. Bricks only ever hands these
    // filters a WP_Post or null, so anything else is not a context we resolve.
    assert_same('', SocialMediaAccountsController::render_bricks_dynamic_tag('{social_account:url}', '123.9'), 'Case 28: decimal string');
    assert_same('', SocialMediaAccountsController::render_bricks_dynamic_tag('{social_account:url}', '1.23e2'), 'Case 28: scientific notation');
    assert_same('', SocialMediaAccountsController::render_bricks_dynamic_tag('{social_account:url}', -123), 'Case 28: negative int');
});

// Case 29 — Bricks post type dropdown exposure (defect 1)
run_social_bricks_case('Case 29: bricks/registered_post_types_args', 'allow_bricks_post_type_selection', function (): void {
    $args = SocialMediaAccountsController::allow_bricks_post_type_selection(['public' => true]);
    $exposed = get_post_types($args);

    assert_true(isset($exposed['sfx_social_account']), 'Case 29: social account CPT is exposed');
    assert_true(isset($exposed['post']), 'Case 29: public post type still exposed');
    assert_true(isset($exposed['page']), 'Case 29: public page type still exposed');
    assert_true(!isset($exposed['sfx_custom_script']), 'Case 29: sfx_custom_script NOT newly exposed');
    assert_true(!isset($exposed['sfx_contact_info']), 'Case 29: sfx_contact_info NOT newly exposed');
    assert_true(!isset($exposed['bricks_template']), 'Case 29: bricks template CPT NOT newly exposed');
});

run_social_bricks_case('Case 30: post type args filter respects upstream args', 'allow_bricks_post_type_selection', function (): void {
    // An upstream filter that widens to every post type must stay widened, minus nothing.
    $args = SocialMediaAccountsController::allow_bricks_post_type_selection([]);
    $exposed = get_post_types($args);

    assert_true(isset($exposed['sfx_custom_script']), 'Case 30: upstream widening is preserved');
    assert_true(isset($exposed['sfx_social_account']), 'Case 30: social account still present');
});

// Case 31 — the real registration: order is editable AND the CPT stays non-public.
// Asserts the args actually handed to register_post_type(), not a helper echoing them back.
\SFX\SocialMediaAccounts\PostType::register_post_type();

global $test_registered_post_types;
$social_cpt_args = $test_registered_post_types['sfx_social_account'] ?? [];

assert_true(
    in_array('page-attributes', $social_cpt_args['supports'] ?? [], true),
    'Case 31: page-attributes is registered, so menu_order is editable'
);
assert_same(false, $social_cpt_args['public'] ?? null, 'Case 31: CPT stays non-public');
assert_same(false, $social_cpt_args['publicly_queryable'] ?? null, 'Case 31: CPT stays non-publicly-queryable');
assert_same(false, $social_cpt_args['query_var'] ?? null, 'Case 31: query_var stays disabled');

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all social-bricks-dynamic-data tests\n";
exit(0);
