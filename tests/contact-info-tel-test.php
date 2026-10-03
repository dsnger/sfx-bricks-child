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
require dirname(__DIR__) . '/inc/ContactInfos/PostType.php';
require dirname(__DIR__) . '/inc/ContactInfos/Shortcode/SC_ContactInfos.php';
require dirname(__DIR__) . '/inc/ContactInfos/Controller.php';

use SFX\ContactInfos\Controller as ContactInfosController;
use SFX\ContactInfos\Shortcode\SC_ContactInfos;

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
    assert_same($out, SC_ContactInfos::normalize_tel((string) $in), "1: normalize_tel('{$in}')");
}

// 2. Country code filter: digits only, any reasonable shape; empty falls back to 49.
$test_filter_callbacks['sfx_contact_info_default_country_code'] = [static fn() => '+43'];
assert_same('+43123456', SC_ContactInfos::normalize_tel('0123 456'), '2: filter "+43"');
$test_filter_callbacks['sfx_contact_info_default_country_code'] = [static fn() => 41];
assert_same('+41123456', SC_ContactInfos::normalize_tel('0123 456'), '2: filter 41 (int)');
$test_filter_callbacks['sfx_contact_info_default_country_code'] = [static fn() => ''];
assert_same('+49123456', SC_ContactInfos::normalize_tel('0123 456'), '2: empty filter -> 49');
$test_filter_callbacks = [];

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

// 6. The Bricks picker lists the tel: variants for phone, mobile and fax.
$names = array_column(ContactInfosController::add_bricks_dynamic_tag([]), 'name');
foreach (['phone', 'mobile', 'fax'] as $field) {
    assert_true(in_array('{contact_info:' . $field . '@format:tel}', $names, true), "6: picker has {$field}@format:tel");
}

global $failures;
if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}
echo "contact-info-tel-test: PASS\n";
