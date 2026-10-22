<?php

declare(strict_types=1);

// WPML is not available here (owner decision D12): these hooks follow WPML's documented signatures.
$test_hooks = [];
function apply_filters(string $hook, $value, ...$args)
{
    global $test_hooks;
    foreach ($test_hooks[$hook] ?? [] as $callback) {
        $value = $callback($value, ...$args);
    }
    return $value;
}
function do_action(string $hook, ...$args): void
{
    global $test_hooks;
    foreach ($test_hooks[$hook] ?? [] as $callback) {
        $callback(...$args);
    }
}
function has_action(string $hook, $callback = false)
{
    global $test_hooks;
    return !empty($test_hooks[$hook]);
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

const CTX = 'sfx-bricks-child contact infos';
$test_get_posts_calls = [];

// Fixtures, as in contact-info-polylang-test.php.
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
$test_post_lists['sfx_contact_info'] = [410, 411, 421];

$sc = new SC_ContactInfos();

// w0. Nothing listens (no WPML, or WPML without String Translation): no query, output as on main.
PostType::register_translatable_strings();
assert_same([], $test_get_posts_calls, 'w0: no query without a listener');
assert_same('Hauptstr. 1<br>45127 Essen<br>Deutschland', $sc->render_contact_info(['field' => 'address', 'contact_id' => '420']), 'w0: composed address');
assert_same('+49201123', $sc->render_contact_info(['field' => 'phone', 'contact_id' => '420', 'format' => 'tel']), 'w0: format=tel');

// WPML listeners.
$test_wpml_registered = [];
$test_wpml_translations = [
    CTX . '|phone #410|0201 123'    => '+1 555 0100',
    CTX . '|country #410|Deutschland' => 'Germany',
    CTX . '|city #420|Essen'        => 'Essen (EN)',
    CTX . '|country #420|Deutschland' => 'Germany',
];
$test_hooks['wpml_register_single_string'][] = static function (...$args): void {
    global $test_wpml_registered;
    $test_wpml_registered[] = $args;
};
$test_hooks['wpml_default_language'][] = static fn($empty) => 'de';
$test_hooks['wpml_translate_single_string'][] = static function ($value, $context, $name) {
    global $test_wpml_translations;
    return $test_wpml_translations["{$context}|{$name}|{$value}"] ?? $value;
};

$expected = [
    [CTX, 'address #410', '<p>Hauptstr. 1</p>', false, 'de'],
    [CTX, 'city #411', 'Essen, Bochum', false, 'de'],
    [CTX, 'city #421', ', Essen', false, 'de'],
    [CTX, 'country #410', 'Deutschland', false, 'de'],
    [CTX, 'opening #410', 'Mo–Fr 9–17', false, 'de'],
    [CTX, 'phone #410', '0201 123', false, 'de'],
];
$sort = static function (array $rows): array {
    usort($rows, static fn(array $a, array $b): int => strcmp($a[1], $b[1]));
    return $rows;
};

// The same expectations with an English and a German admin: name and context never follow gettext.
foreach (['identity' => [], 'german admin' => ['Phone' => 'Telefon', 'Country' => 'Land', 'Contact Information' => 'Kontaktinformationen']] as $label => $gettext) {
    $test_gettext = $gettext;
    $test_transients = [];
    $test_wpml_registered = [];

    // w1. Registration.
    PostType::register_translatable_strings();
    $query = end($test_get_posts_calls);
    assert_same('publish', $query['post_status'] ?? null, "w1 ({$label}): published only");
    assert_same(-1, $query['numberposts'] ?? null, "w1 ({$label}): no five-post default");
    assert_same($sort($expected), $sort($test_wpml_registered), "w1 ({$label}): registered set");

    // w2. Output.
    $phone = $sc->render_contact_info(['field' => 'phone', 'contact_id' => '410']);
    assert_contains('+1 555 0100', $phone, "w2 ({$label}): phone translated");
    assert_contains('href="tel:+15550100"', $phone, "w2 ({$label}): link from the translated number");
    assert_same('+15550100', $sc->render_contact_info(['field' => 'phone', 'contact_id' => '410', 'format' => 'tel']), "w2 ({$label}): format=tel");
    assert_same('+15550100', ContactInfosController::render_bricks_dynamic_tag('{contact_info:phone:410 @format:tel}', null), "w2 ({$label}): Bricks tag");
    assert_same('Germany', $sc->render_contact_info(['field' => 'country', 'contact_id' => '410']), "w2 ({$label}): country");
    assert_same('Hauptstr. 1<br>45127 Essen (EN)<br>Germany', $sc->render_contact_info(['field' => 'address', 'contact_id' => '420']), "w2 ({$label}): composed address");
    assert_contains('Mo–Fr 9–17', $sc->render_contact_info(['field' => 'opening', 'contact_id' => '410']), "w2 ({$label}): untranslated stays");
}

global $failures;
if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}
echo "contact-info-wpml-test: PASS\n";
