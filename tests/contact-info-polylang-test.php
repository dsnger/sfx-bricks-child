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
use SFX\ContactInfos\PostType;
use SFX\ContactInfos\Shortcode\SC_ContactInfos;

$test_get_posts_calls = [];

// Fixtures.
$test_posts[410] = sfx_make_post(410, 'sfx_contact_info', 'publish', '<img src=x onerror=alert(1)>');
$test_meta[410] = [
    '_phone'   => ['0201 123'],
    '_address' => ['<p>Hauptstr. 1</p>'],
    '_opening' => ['Mo–Fr 9–17'],
    '_country' => ['Deutschland'],
    '_zip'     => ['0'],
    '_fax'     => [''],
];
$test_posts[411] = sfx_make_post(411, 'sfx_contact_info', 'publish', 'Two rows');
$test_meta[411] = ['_city' => ['Essen', 'Bochum']];
$test_posts[420] = sfx_make_post(420, 'sfx_contact_info', 'publish', 'Composed');
$test_meta[420] = [
    '_street'  => ['Hauptstr. 1'],
    '_zip'     => ['45127'],
    '_city'    => ['Essen'],
    '_country' => ['Deutschland'],
    '_address' => [''],
    '_phone'   => ['0201 123'],
];
$test_posts[421] = sfx_make_post(421, 'sfx_contact_info', 'publish', 'Empty row');
$test_meta[421] = ['_city' => ['', 'Essen']];
$test_posts[422] = sfx_make_post(422, 'sfx_contact_info', 'publish', 'Zip only');
$test_meta[422] = ['_zip' => ['45127'], '_country' => ['Deutschland']];
$test_post_lists['sfx_contact_info'] = [410, 411, 421];

$sc = new SC_ContactInfos();

// 0. Without Polylang (or WPML) output is byte-identical to main (values captured there).
assert_same('Hauptstr. 1<br>45127 Essen<br>Deutschland', $sc->render_contact_info(['field' => 'address', 'contact_id' => '420']), '0: composed address');
assert_same('Deutschland', $sc->render_contact_info(['field' => 'address', 'contact_id' => '422']), '0: zip without city');
assert_same('Essen', $sc->render_contact_info(['field' => 'address', 'contact_id' => '421']), '0: empty meta row dropped');
assert_same('+49201123', $sc->render_contact_info(['field' => 'phone', 'contact_id' => '420', 'format' => 'tel']), '0: format=tel');

// 0a. The registration runs on every admin load.
PostType::init();
assert_true(
    in_array([PostType::class, 'register_translatable_strings'], $test_added_actions['admin_init'] ?? [], true),
    '0a: hooked on admin_init'
);

// 0b. Without Polylang the admin callback queries nothing.
PostType::register_translatable_strings();
assert_same([], $test_get_posts_calls, '0b: no query without Polylang');

// 0c. WPML is no longer consulted.
define('ICL_SITEPRESS_VERSION', 'test');
$test_icl_names = [];
if (!function_exists('icl_t')) {
    function icl_t($context, $name, $value)
    {
        global $test_icl_names;
        $test_icl_names[] = $name;
        return 'WPML ' . $value;
    }
}
$test_transients = [];
assert_same('Essen', $sc->render_contact_info(['field' => 'city', 'contact_id' => '420']), '0c: original printed');
assert_same([], $test_icl_names, '0c: icl_t never called');

// Polylang from here on; declared at runtime so the cases above run without it.
$test_pll_registered = [];
$test_pll_translations = [];
if (!function_exists('pll_register_string')) {
    function pll_register_string($name, $string, $context = 'Polylang', $multiline = false)
    {
        global $test_pll_registered;
        $test_pll_registered[] = [$name, $string, $context, $multiline];
    }
    function pll__($string)
    {
        global $test_pll_translations;
        return $test_pll_translations[$string] ?? $string;
    }
}

// 1. Registration: every non-empty field of every published contact, safe labels only.
PostType::register_translatable_strings();
$query = end($test_get_posts_calls);
assert_same('sfx_contact_info', $query['post_type'] ?? null, '1: queries contacts');
assert_same('publish', $query['post_status'] ?? null, '1: published only');
assert_same(-1, $query['numberposts'] ?? null, '1: no five-post default');
$expected = [
    ['Phone #410', '0201 123', 'Contact Information', false],
    ['ZIP Code #410', '0', 'Contact Information', false],
    ['Country #410', 'Deutschland', 'Contact Information', false],
    ['Full Address #410', '<p>Hauptstr. 1</p>', 'Contact Information', true],
    ['Opening Hours #410', 'Mo–Fr 9–17', 'Contact Information', true],
    ['City #411', 'Essen, Bochum', 'Contact Information', false],
    ['City #421', ', Essen', 'Contact Information', false],
];
$sort = static function (array $rows): array {
    usort($rows, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
    return $rows;
};
assert_same($sort($expected), $sort($test_pll_registered), '1: registered set');
foreach ($test_pll_registered as [$name, , $context]) {
    assert_true(!str_contains($name . $context, '<img'), "1: no title markup in '{$name}'");
}

// 2. Output goes through the translation, on every path.
$test_transients = [];
$test_pll_translations = [
    '0201 123'           => '+1 555 0100',
    'Deutschland'        => 'Germany',
    'Essen'              => 'Essen (EN)',
    '<p>Hauptstr. 1</p>' => '<p>1 Main St</p>',
    'Essen, Bochum'      => 'Essen and Bochum',
];
$phone = $sc->render_contact_info(['field' => 'phone', 'contact_id' => '410']);
assert_contains('+1 555 0100', $phone, '2: phone translated');
assert_contains('href="tel:+15550100"', $phone, '2: link from the translated number');
assert_same('+15550100', $sc->render_contact_info(['field' => 'phone', 'contact_id' => '410', 'format' => 'tel']), '2: format=tel');
assert_same('+15550100', ContactInfosController::render_bricks_dynamic_tag('{contact_info:phone:410 @format:tel}', null), '2: Bricks tag');
assert_same('Germany', $sc->render_contact_info(['field' => 'country', 'contact_id' => '410']), '2: country');
assert_contains('1 Main St', $sc->render_contact_info(['field' => 'address', 'contact_id' => '410']), '2: stored address');
assert_contains('Mo–Fr 9–17', $sc->render_contact_info(['field' => 'opening', 'contact_id' => '410']), '2: untranslated stays');
assert_same('Essen and Bochum', $sc->render_contact_info(['field' => 'city', 'contact_id' => '411']), '2: multi-row original');
assert_same('Hauptstr. 1<br>45127 Essen (EN)<br>Germany', $sc->render_contact_info(['field' => 'address', 'contact_id' => '420']), '2: composed address');
assert_same('Essen', $sc->render_contact_info(['field' => 'address', 'contact_id' => '421']), '2: empty row, untranslated, as on main');
$test_pll_translations[', Essen'] = 'Essen (EN)';
assert_same('Essen (EN)', $sc->render_contact_info(['field' => 'city', 'contact_id' => '421']), '2: empty row, field');
assert_same('Essen (EN)', $sc->render_contact_info(['field' => 'address', 'contact_id' => '421']), '2: empty row, composed');

global $failures;
if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}
echo "contact-info-polylang-test: PASS\n";
