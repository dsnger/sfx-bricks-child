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

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all social-bricks-dynamic-data tests\n";
exit(0);
