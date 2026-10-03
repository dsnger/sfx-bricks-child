<?php

/**
 * Help tabs of the Contact Infos and Social Media Accounts screens: content stays in step
 * with the field registries and the shortcode defaults, examples are present, output is
 * escaped, and the tabs only attach to the module's own list and edit screens.
 *
 * Run: php tests/contact-social-help-tab-test.php
 */

declare(strict_types=1);

require __DIR__ . '/support/social-bricks-stubs.php';

require dirname(__DIR__) . '/inc/ContactInfos/FieldRegistry.php';
require dirname(__DIR__) . '/inc/ContactInfos/PostType.php';
require dirname(__DIR__) . '/inc/ContactInfos/Shortcode/SC_ContactInfos.php';
require dirname(__DIR__) . '/inc/ContactInfos/HelpTab.php';
require dirname(__DIR__) . '/inc/SocialMediaAccounts/FieldRegistry.php';
require dirname(__DIR__) . '/inc/SocialMediaAccounts/PostType.php';
require dirname(__DIR__) . '/inc/SocialMediaAccounts/HelpTab.php';

use SFX\ContactInfos\FieldRegistry as ContactFieldRegistry;
use SFX\ContactInfos\HelpTab as ContactHelpTab;
use SFX\ContactInfos\Shortcode\SC_ContactInfos;
use SFX\SocialMediaAccounts\FieldRegistry as SocialFieldRegistry;
use SFX\SocialMediaAccounts\HelpTab as SocialHelpTab;

if (!class_exists('WP_Screen')) {
    class WP_Screen
    {
        public string $post_type = '';
        public string $base = '';
        public array $tabs = [];
        public string $sidebar = '';

        public function add_help_tab(array $args): void
        {
            $this->tabs[$args['id']] = $args;
        }

        public function set_help_sidebar(string $content): void
        {
            $this->sidebar = $content;
        }
    }
}

function help_screen(string $post_type, string $base): WP_Screen
{
    $screen = new WP_Screen();
    $screen->post_type = $post_type;
    $screen->base = $base;
    return $screen;
}

/** @return array<string, string> tab id => content */
function help_tabs_by_id(array $tabs): array
{
    $by_id = [];
    foreach ($tabs as $tab) {
        assert_true(isset($tab['id'], $tab['title'], $tab['content']), 'tab has id, title, content');
        assert_true($tab['title'] !== '' && $tab['content'] !== '', "tab {$tab['id']} is not empty");
        $by_id[$tab['id']] = $tab['content'];
    }
    return $by_id;
}

// Case 1 — Contact Infos returns its four tabs.
$contact = help_tabs_by_id(ContactHelpTab::get_tabs());
assert_same(
    ['sfx-contact-info-shortcode', 'sfx-contact-info-attributes', 'sfx-contact-info-bricks', 'sfx-contact-info-examples'],
    array_keys($contact),
    'Case 1: contact tab ids'
);

// Case 2 — every contact field key is in the field table.
foreach (array_keys(ContactFieldRegistry::get_fields()) as $key) {
    assert_contains("<code>{$key}</code>", $contact['sfx-contact-info-shortcode'], "Case 2: contact field {$key} listed");
}

// Case 3 — every [contact_info] default attribute is in the attribute table, with a description.
assert_true(count(SC_ContactInfos::DEFAULT_ATTS) > 0, 'Case 3: defaults readable');
foreach (array_keys(SC_ContactInfos::DEFAULT_ATTS) as $key) {
    $found = preg_match('#<tr><td><code>' . preg_quote($key, '#') . '</code></td>(.*?)</tr>#', $contact['sfx-contact-info-attributes'], $row);
    assert_true($found === 1, "Case 3: attribute {$key} listed");
    assert_true($found === 1 && substr($row[1], -9) !== '<td></td>', "Case 3: attribute {$key} has a description");
}

// Case 4 — key contact examples, copy-ready (only HTML-escaped, nothing else changed).
foreach ([
    'tel:{contact_info:phone@format:tel}',
    'mailto:{contact_info:email@link:false}',
    '{contact_info:phone:123}',
    '[contact_info field=&quot;email&quot; text=&quot;Write to us&quot;]',
    'sfx_contact_info_default_country_code',
] as $needle) {
    assert_contains($needle, implode('', $contact), "Case 4: contact example {$needle}");
}

// Case 5 — Social Media Accounts returns its three tabs and every field key.
$social = help_tabs_by_id(SocialHelpTab::get_tabs());
assert_same(
    ['sfx-social-account-shortcodes', 'sfx-social-account-bricks', 'sfx-social-account-examples'],
    array_keys($social),
    'Case 5: social tab ids'
);
foreach (array_keys(SocialFieldRegistry::get_fields()) as $key) {
    assert_contains("<code>{$key}</code>", $social['sfx-social-account-shortcodes'], "Case 5: social field {$key} listed");
}
foreach (['{social_accounts}', '{social_account:url:123}', '{social_account:url}', '[social_account id=&quot;123&quot; field=&quot;url&quot;]'] as $needle) {
    assert_contains($needle, implode('', $social), "Case 5: social example {$needle}");
}

// Case 5b — both social shortcodes list their accepted attributes, and every row is described.
$social_tables = array_slice(explode('<table', $social['sfx-social-account-shortcodes']), 1);
$described = static function (string $table): array {
    // Body rows whose last cell is non-empty; one row never reaches into the next.
    preg_match_all('#<tr><td><code>([a-z_]+)</code></td>(?:(?!</tr>).)*<td>(?:(?!</td>).)+</td></tr>#', $table, $m);
    return $m[1];
};
assert_same(['class', 'style', 'size', 'target'], $described($social_tables[0] ?? ''), 'Case 5b: [social_accounts] attributes');
assert_same(['id', 'field', 'class', 'size', 'target'], $described($social_tables[1] ?? ''), 'Case 5b: [social_account] attributes');
assert_same(array_keys(SocialFieldRegistry::get_fields()), $described($social_tables[2] ?? ''), 'Case 5b: every social field described');

// Case 6 — hostile translations come out escaped, in labels, prose and headers.
$test_gettext = [
    'Phone'       => '<script>alert(1)</script>',
    'URL'         => '<script>alert(2)</script>',
    'Output'      => '<b onmouseover=x>',
    'Bricks tags' => '<i>',
];
$all = implode('', array_column(ContactHelpTab::get_tabs(), 'content'))
    . implode('', array_column(SocialHelpTab::get_tabs(), 'content'))
    . ContactHelpTab::get_sidebar() . SocialHelpTab::get_sidebar();
assert_true(strpos($all, '<script') === false, 'Case 6: no raw <script>');
assert_true(strpos($all, '<b onmouseover') === false, 'Case 6: no raw header markup');
assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $all, 'Case 6: contact label escaped');
assert_contains('&lt;script&gt;alert(2)&lt;/script&gt;', $all, 'Case 6: social label escaped');
$test_gettext = [];

// Case 7 — tabs attach only to the module's own list (edit) and edit (post) screens.
foreach (['edit', 'post'] as $base) {
    $screen = help_screen('sfx_contact_info', $base);
    ContactHelpTab::add_to_screen($screen);
    assert_same(4, count($screen->tabs), "Case 7: contact tabs on {$base}");
    assert_true($screen->sidebar !== '', "Case 7: contact sidebar on {$base}");
    SocialHelpTab::add_to_screen($screen);
    assert_same(4, count($screen->tabs), "Case 7: social tabs stay off the contact {$base} screen");

    $screen = help_screen('sfx_social_account', $base);
    SocialHelpTab::add_to_screen($screen);
    assert_same(3, count($screen->tabs), "Case 7: social tabs on {$base}");
}
foreach ([['sfx_contact_info', 'options'], ['post', 'post'], ['', 'dashboard']] as [$post_type, $base]) {
    $screen = help_screen($post_type, $base);
    ContactHelpTab::add_to_screen($screen);
    SocialHelpTab::add_to_screen($screen);
    assert_same(0, count($screen->tabs), "Case 7: no tabs on {$post_type}/{$base}");
}
ContactHelpTab::add_to_screen(null);
SocialHelpTab::add_to_screen(null);

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all contact-social-help-tab tests\n";
exit(0);
