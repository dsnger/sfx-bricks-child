<?php

declare(strict_types=1);

// Filters: none are registered by the shared stubs; a plain hook table is enough here.
$test_filter_callbacks = [];
function apply_filters(string $hook, $value, ...$args)
{
    global $test_filter_callbacks;
    foreach ($test_filter_callbacks[$hook] ?? [] as $callback) {
        $value = $callback($value, ...$args);
    }
    return $value;
}

require __DIR__ . '/support/social-bricks-stubs.php';

require dirname(__DIR__) . '/inc/ContactInfos/FieldRegistry.php';
require dirname(__DIR__) . '/inc/TelNormalizer.php';
require dirname(__DIR__) . '/inc/ContactInfos/PostType.php';
require dirname(__DIR__) . '/inc/ContactInfos/Shortcode/SC_ContactInfos.php';
require dirname(__DIR__) . '/inc/ContactInfos/Controller.php';

use SFX\ContactInfos\Controller as ContactInfosController;
use SFX\ContactInfos\Shortcode\SC_ContactInfos;
use SFX\TelNormalizer;

// 1. normalize_tel: the task's examples, empty, already clean, other shapes.
$cases = [
    '0208 207658 0'            => '+492082076580',
    '+49 (0)208 / 207658-0'    => '+492082076580',
    '0049 208 2076580'         => '+492082076580',
    ''                         => '',
    '+492082076580'            => '+492082076580',
    '+49 30 111'               => '+4930111',
    '(0208) 207658-0'          => '+492082076580',
    '2076580'                  => '2076580',
    '+49 208 + 2076580'        => '+492082076580',
    'Tel. 0208 / 207658 0'     => '+492082076580',
    '(0)208 2076580'           => '+492082076580',
    '+49 ( 0 )208 2076580'     => '+492082076580',
    "\u{00A0}+49 (0)208 2076580" => '+492082076580',
    "\u{202F}+49 208 2076580"  => '+492082076580',
    '+49 208 2076580;ext=123'  => '+492082076580;ext=123',
    '0208 2076580 ; EXT = 12'  => '+492082076580;ext=12',
    '+49 208 2076580;ext=12-34' => '+492082076580;ext=1234',
    '+49-208-207658(0)'        => '+492082076580',
    '+49 208 2076580 x123'     => '+492082076580;ext=123',
    '+49 208 2076580 ext. 12'  => '+492082076580;ext=12',
    '0208 2076580, Durchwahl 45' => '+492082076580;ext=45',
    '0208 2076580 DW 7'        => '+492082076580;ext=7',
    'auf Anfrage'              => '',
    '+49 208 2076580x123'      => '+492082076580;ext=123',
    '+49 208 2076580ext.123'   => '+492082076580;ext=123',
    '+49 208 2076580;ext=-123' => '+492082076580;ext=123',
    '+49 208 2076580;ext=.123' => '+492082076580;ext=123',
    '+49 208 2076580;ext=123;foo=9' => '+492082076580;ext=123',
    '+49 208 2076580;ext=12 Büro 3' => '+492082076580;ext=12',
    '+49 208 2076580;ext=12 34'     => '+492082076580;ext=1234',
    '+49 208 2076580;ext=12/34'     => '+492082076580;ext=1234',
    '+49 208 2076580 x 12/34'       => '+492082076580;ext=1234',
    '+49 208 2076580;ext=( 123 )'   => '+492082076580;ext=123',
    '+49 208 2076580; ext = 12-34 (Zentrale)' => '+492082076580;ext=1234',
    '+49 208 2076580;foo=9'    => '+492082076580',
    '00'                       => '',
    '00;ext=12'                => '',
    '0'                        => '',
    '0049 (0) 208 2076580'     => '+492082076580',
    '+1 (0)555 0100'           => '+15550100',
    '+49 208 2076580;ext=(123)' => '+492082076580;ext=123',
    "+49 (\u{00A0}0\u{00A0})208 2076580" => '+492082076580',
    "+49 (\u{202F}0\u{202F})208 2076580" => '+492082076580',
];
foreach ($cases as $in => $out) {
    assert_same($out, TelNormalizer::normalize_tel((string) $in), "1: normalize_tel('{$in}')");
}

// 2. Country code filter: digits only, any reasonable shape; empty falls back to 49.
$test_filter_callbacks['sfx_contact_info_default_country_code'] = [static fn() => '+43'];
assert_same('+43123456', TelNormalizer::normalize_tel('0123 456'), '2: filter "+43"');
$test_filter_callbacks['sfx_contact_info_default_country_code'] = [static fn() => 41];
assert_same('+41123456', TelNormalizer::normalize_tel('0123 456'), '2: filter 41 (int)');
$test_filter_callbacks['sfx_contact_info_default_country_code'] = [static fn() => ''];
assert_same('+49123456', TelNormalizer::normalize_tel('0123 456'), '2: empty filter -> 49');
$test_filter_callbacks = [];

// 2b. The old public method still works for code outside the theme: it delegates.
assert_same('+492082076580', SC_ContactInfos::normalize_tel('0208 207658 0'), '2b: SC_ContactInfos::normalize_tel delegates');

// Fixture contact with the three shapes from the task.
$test_posts[310] = sfx_make_post(310, 'sfx_contact_info', 'publish', 'Tel fixture');
$test_meta[310] = [
    '_phone'  => ['0208 207658 0'],
    '_mobile' => ['+49 (0)208 / 207658-0'],
    '_fax'    => ['0049 208 2076580'],
    '_email'  => ['info@example.test'],
];

$sc = new SC_ContactInfos();

// 3. Default link: cleaned href, visible text unchanged (phone and mobile).
assert_same(
    '<a href="tel:+492082076580">0208 207658 0</a>',
    $sc->render_contact_info(['field' => 'phone', 'contact_id' => '310']),
    '3: phone link href cleaned, text kept'
);
assert_same(
    '<a href="tel:+492082076580">+49 (0)208 / 207658-0</a>',
    $sc->render_contact_info(['field' => 'mobile', 'contact_id' => '310']),
    '3: mobile link href cleaned, text kept'
);
assert_same(
    '0208 207658 0',
    $sc->render_contact_info(['field' => 'phone', 'contact_id' => '310', 'link' => 'false']),
    '3: link=false keeps the raw text'
);

// 4. format=tel: the bare cleaned number for phone, mobile and fax — no link, icon or wrapper.
foreach (['phone', 'mobile', 'fax'] as $field) {
    assert_same(
        '+492082076580',
        $sc->render_contact_info(['field' => $field, 'contact_id' => '310', 'format' => 'tel', 'class' => 'x', 'tag' => 'div']),
        "4: format=tel for {$field}"
    );
}
assert_same(
    '0049 208 2076580',
    $sc->render_contact_info(['field' => 'fax', 'contact_id' => '310']),
    '4: fax without format unchanged'
);
assert_same(
    '<a href="mailto:info@example.test">info@example.test</a>',
    $sc->render_contact_info(['field' => 'email', 'contact_id' => '310', 'format' => 'tel']),
    '4: format=tel ignored for email'
);

assert_same(
    '+492082076580',
    $sc->render_contact_info(['field' => 'phone', 'contact_id' => '310', 'format' => 'tel', 'debug' => '1']),
    '4: format=tel wins over debug'
);

// 3b. A field without digits stays text, never an empty tel: link.
$test_meta[311] = ['_phone' => ['auf Anfrage']];
$test_posts[311] = sfx_make_post(311, 'sfx_contact_info', 'publish', 'No number');
assert_same('auf Anfrage', $sc->render_contact_info(['field' => 'phone', 'contact_id' => '311']), '3b: no digits -> no link');

// 4c. contact_id 0 = by type: the cache key carries the type, not 0.
$test_transients = [];
$test_transients['sfx_contact_info_type_main'] = 310;
$test_transients['sfx_contact_info_type_branch'] = 301;
$main = $sc->render_contact_info(['field' => 'phone', 'contact_id' => '0', 'type' => 'main', 'format' => 'tel']);
$branch = $sc->render_contact_info(['field' => 'phone', 'contact_id' => '0', 'type' => 'branch', 'format' => 'tel']);
assert_same('+492082076580', $main, '4c: id 0 main');
assert_same('+4989222', $branch, '4c: id 0 branch does not reuse the main cache');
assert_true(!isset($test_transients['sfx_contact_info_0_phone']), '4c: no type-less cache key');

// 4d. A tag without an ID shows a saved change at once (the field cache is per contact ID).
$test_transients = ['sfx_contact_info_type_main' => 310];
$test_meta[310]['_fax'] = ['0201 1'];
assert_same('0201 1', $sc->render_contact_info(['field' => 'fax', 'type' => 'main']), '4d: by-type value');
$test_meta[310]['_fax'] = ['0201 2'];
$sc->clear_contact_info_caches(310);
$test_transients['sfx_contact_info_type_main'] = 310;
assert_same('0201 2', $sc->render_contact_info(['field' => 'fax', 'type' => 'main']), '4d: change visible after save');
$test_meta[310]['_fax'] = ['0049 208 2076580'];
$test_transients = [];

// 4b. contact_id from a shortcode arrives as a string; numeric values keep their meaning.
assert_same('', $sc->render_contact_info(['field' => 'email', 'contact_id' => '-1']), '4b: negative id -> nothing');
assert_same('', $sc->render_contact_info(['field' => 'email', 'contact_id' => -1]), '4b: negative int id -> nothing');

// 5. Bricks tag passes @format:tel through to the shortcode.
assert_same(
    '+492082076580',
    ContactInfosController::render_bricks_dynamic_tag('{contact_info:phone:310@format:tel}', null),
    '5: Bricks tag @format:tel'
);

// 5b. Bare switches (link, wrap, debug) mean on; other bare attributes are ignored. Never a crash.
assert_same(
    '<a href="tel:+492082076580">0208 207658 0</a>',
    ContactInfosController::render_bricks_dynamic_tag('{contact_info:phone:310@link}', null),
    '5b: bare @link keeps the link'
);
foreach (['class', 'tag', 'text', 'field', 'contact_id', 'format', 'unknown'] as $bare) {
    assert_same(
        '<a href="tel:+492082076580">0208 207658 0</a>',
        ContactInfosController::render_bricks_dynamic_tag('{contact_info:phone:310@' . $bare . '}', null),
        "5b: bare @{$bare} is ignored"
    );
}
assert_same(
    '<a href="tel:+492082076580">0208 207658 0</a>',
    ContactInfosController::render_bricks_dynamic_tag('{contact_info:phone:310@link:false@link}', null),
    '5b: bare @link switches the link back on'
);
assert_same(
    '<pre>0208 207658 0</pre>',
    ContactInfosController::render_bricks_dynamic_tag('{contact_info:phone:310@debug}', null),
    '5b: bare @debug'
);
assert_contains(
    '<span',
    ContactInfosController::render_bricks_dynamic_tag('{contact_info:phone:310@wrap}', null),
    '5b: bare @wrap wraps'
);

// 5c. Both spellings render identically: the space form (shown in help and picker) and the
//     older no-space form already inserted in pages.
foreach ([
    ['{contact_info:phone:310 @format:tel}', '{contact_info:phone:310@format:tel}'],
    ['{contact_info:email:310 @link:false @wrap:true}', '{contact_info:email:310@link:false@wrap:true}'],
    ['{contact_info:phone:310 @link:false}', '{contact_info:phone:310|link=false}'],
] as [$spaced, $legacy]) {
    $a = ContactInfosController::render_bricks_dynamic_tag($spaced, null);
    assert_true($a !== '' && strpos($a, '{') === false, "5c: {$spaced} resolves");
    assert_same(ContactInfosController::render_bricks_dynamic_tag($legacy, null), $a, "5c: {$spaced} == {$legacy}");
}
assert_same('+492082076580', ContactInfosController::render_bricks_dynamic_tag('{contact_info:phone:310 @format:tel}', null), '5c: space form value');
$both = ContactInfosController::render_bricks_dynamic_tag('{contact_info:email:310 @link:false @wrap:true}', null);
assert_contains('<span', $both, '5c: @wrap:true wraps');
assert_true(strpos($both, '<a ') === false, '5c: @link:false drops the link');
assert_true(strpos(ContactInfosController::render_bricks_dynamic_tag('{contact_info:email:310 @link:false}', null), '<span') === false, '5c: without @wrap no span');
assert_contains('<a ', ContactInfosController::render_bricks_dynamic_tag('{contact_info:email:310 @wrap:true}', null), '5c: without @link:false the link stays');

// 6. The Bricks picker lists the tel: variants for phone, mobile and fax.
$names = array_column(ContactInfosController::add_bricks_dynamic_tag([]), 'name');
foreach (['phone', 'mobile', 'fax'] as $field) {
    assert_true(in_array('{contact_info:' . $field . ' @format:tel}', $names, true), "6: picker has {$field} @format:tel (space form)");
}

// 7. Explicit IDs: only published contact entries. Drafts and other post types stay hidden.
$test_transients = [];
$test_posts[320] = sfx_make_post(320, 'sfx_contact_info', 'draft', 'Draft contact');
$test_meta[320] = ['_email' => ['draft@example.test']];
$test_posts[321] = sfx_make_post(321, 'page', 'publish', 'A page');
$test_meta[321] = ['_email' => ['page@example.test']];
assert_same('', $sc->render_contact_info(['field' => 'email', 'contact_id' => '320']), '7: draft contact hidden');
assert_same('', $sc->render_contact_info(['field' => 'email', 'contact_id' => '321']), '7: other post type hidden');
assert_same('', ContactInfosController::render_bricks_dynamic_tag('{contact_info:email:320}', null), '7: draft hidden in tag');

// 7b. The address built from parts never reads a hidden entry either.
$test_meta[320] += ['_street' => ['Secret Street'], '_city' => ['Secret City']];
assert_same('', $sc->render_contact_info(['field' => 'address', 'contact_id' => '320']), '7b: draft address hidden');
assert_same('', $sc->render_contact_info(['field' => 'address', 'contact_id' => '321']), '7b: other post type address hidden');
assert_same('', $sc->render_contact_info(['field' => 'edit_lock', 'contact_id' => '310']), '7c: no arbitrary meta key');

// 8. icon_class reaches the [icon] handler intact (was "Array"; brackets must survive).
$test_icon_registered = true;
$test_shortcodes = [];
function shortcode_exists($tag)
{
    global $test_icon_registered;
    return $tag === 'icon' && $test_icon_registered;
}
function do_shortcode($content)
{
    global $test_shortcodes;
    $test_shortcodes[] = $content;
    return '<i></i>';
}
$sc->render_contact_info(['field' => 'email', 'contact_id' => '310', 'icon' => 'mail', 'icon_class' => 'a w-[16px]']);
assert_same('[icon icon="mail" pos="before" class="branch-info a w-&#91;16px&#93;"]', $test_shortcodes[0] ?? '', '8: icon shortcode, brackets as entities');
$test_icon_registered = false;
assert_same(
    '<a href="mailto:info@example.test">info@example.test</a>',
    $sc->render_contact_info(['field' => 'email', 'contact_id' => '310', 'icon' => 'mail']),
    '8: no [icon] registered -> no icon text'
);

// 8b. A write that bypasses save_post (Order screen) still clears the contact caches.
$test_transients = ['sfx_contact_info_type_main' => 310, 'sfx_contact_info_310_phone' => 'old'];
$sc->clear_on_clean_post_cache(310, $test_posts[310]);
assert_same([], $test_transients, '8b: clean_post_cache clears contact caches');
$test_transients = ['sfx_contact_info_type_main' => 310];
$sc->clear_on_clean_post_cache(321, $test_posts[321]);
assert_same(['sfx_contact_info_type_main' => 310], $test_transients, '8b: other post types untouched');

// 9. The cache holds the raw value; translation runs per request, so languages never mix.
$test_pll = [];
function pll__($value)
{
    global $test_pll;
    return $test_pll[$value] ?? $value;
}
$test_transients = [];
$test_meta[310]['_city'] = ['Essen'];
$test_pll = ['Essen' => 'Essen (DE)'];
assert_same('Essen (DE)', $sc->render_contact_info(['field' => 'city', 'contact_id' => '310']), '9: first language');
assert_same('Essen', $test_transients['sfx_contact_info_310_city'] ?? null, '9: raw value cached');
$test_pll = ['Essen' => 'Essen (EN)'];
assert_same('Essen (EN)', $sc->render_contact_info(['field' => 'city', 'contact_id' => '310']), '9: second language from cache');
$test_pll = [];

// 9b. WPML keys strings by contact ID: a by-type value is translated with the real ID,
// also when only the field cache survived.
define('ICL_SITEPRESS_VERSION', 'test');
$test_icl_names = [];
function icl_t($context, $name, $value)
{
    global $test_icl_names;
    $test_icl_names[] = $name;
    return $value;
}
$test_transients = ['sfx_contact_info_type_main' => 310];
$sc->render_contact_info(['field' => 'city', 'type' => 'main']);
unset($test_transients['sfx_contact_info_type_main']);
$test_post_lists['sfx_contact_info'] = [$test_posts[310]];
$sc->render_contact_info(['field' => 'city', 'type' => 'main']);
assert_same(['city_310', 'city_310'], $test_icl_names, '9b: WPML gets the contact ID');

// 10. The by-type lookup honours the Order field, then the newest entry.
$test_transients = [];
$test_last_query_args = [];
$sc->render_contact_info(['field' => 'phone', 'type' => 'main']);
assert_same(['menu_order' => 'ASC', 'date' => 'DESC'], $test_last_query_args['orderby'] ?? null, '10: type lookup order');

global $failures;
if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}
echo "contact-info-tel-test: PASS\n";
