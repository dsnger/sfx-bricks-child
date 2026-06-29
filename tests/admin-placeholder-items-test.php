<?php

declare(strict_types=1);

function __($text, $domain = 'default')
{
    return $text;
}

$failures = 0;

function assert_true(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        echo "FAIL: {$message}\n";
        $failures++;
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    assert_true($expected === $actual, "{$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

require dirname(__DIR__) . '/inc/ContactInfos/FieldRegistry.php';
require dirname(__DIR__) . '/inc/SocialMediaAccounts/FieldRegistry.php';
require dirname(__DIR__) . '/inc/Admin/PlaceholderItems.php';

use SFX\Admin\PlaceholderItems;

$contact_items = PlaceholderItems::build_contact_items(99);
assert_true(count($contact_items) === 18, 'Contact items count is 18');

$first_contact = $contact_items[0];
assert_same('company', 'company', 'First contact field key is company');
assert_same('[contact_info field="company" contact_id="99"]', $first_contact['shortcode'], 'First contact shortcode');
assert_same('{contact_info:company:99}', $first_contact['bricks'], 'First contact bricks tag');

$social_items = PlaceholderItems::build_social_items(123);
assert_true(count($social_items) === 5, 'Social items count is 5 (4 scalar + html block)');

$last_social = $social_items[count($social_items) - 1];
assert_same('[social_account id="123"]', $last_social['shortcode'], 'Last social shortcode omits field=html');
assert_same('{social_account:html:123}', $last_social['bricks'], 'Last social bricks tag uses html key');

foreach (array_merge($contact_items, $social_items) as $item) {
    assert_true(strpos($item['shortcode'], '<') === false, 'Shortcode has no raw HTML');
    assert_true(strpos($item['bricks'], '<') === false, 'Bricks tag has no raw HTML');
}

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all admin-placeholder-items tests\n";
exit(0);
