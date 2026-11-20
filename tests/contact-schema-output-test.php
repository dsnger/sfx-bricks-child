<?php

declare(strict_types=1);

// Schema: mode per request, the theme's own JSON-LD entry, and the merge into Yoast's / Rank Math's entry.
// Plugin constants cannot be undefined, so every case runs in its own PHP process:
// `php tests/contact-schema-output-test.php <case>` runs one case with that case's constants.

use SFX\ContactInfos\Schema\Mode;
use SFX\ContactInfos\Schema\Output;
use SFX\ContactInfos\Schema\PluginMerge;
use SFX\ContactInfos\Schema\Settings;

/** case => the plugin constants defined in its process */
const CASES = [
    'own_output_printed'                       => [],
    'off_prints_nothing'                       => [],
    'register_front_end_only'                  => [],
    'other_plugin_beats_yoast'                 => ['SEOPRESS_VERSION', 'WPSEO_VERSION'],
    'aioseo_is_conflict'                       => ['AIOSEO_VERSION'],
    'saswp_is_conflict'                        => ['SASWP_VERSION', 'RANK_MATH_VERSION'],
    'two_of_yoast_rankmath_is_conflict'        => ['WPSEO_VERSION', 'RANK_MATH_VERSION'],
    'two_of_yoast_tsf_is_conflict'             => ['WPSEO_VERSION', 'THE_SEO_FRAMEWORK_VERSION'],
    'two_of_rankmath_tsf_is_conflict'          => ['RANK_MATH_VERSION', 'THE_SEO_FRAMEWORK_VERSION'],
    'tsf_on_suppresses'                        => ['THE_SEO_FRAMEWORK_VERSION'],
    'tsf_off_is_own'                           => ['THE_SEO_FRAMEWORK_VERSION'],
    'yoast_mode'                               => ['WPSEO_VERSION'],
    'yoast_person_mode_nothing_added'          => ['WPSEO_VERSION'],
    'yoast_merge_conflicting_values'           => ['WPSEO_VERSION'],
    'yoast_empty_theme_values_keep_plugin'     => ['WPSEO_VERSION'],
    'rank_math_company_merged_and_place_synced' => ['RANK_MATH_VERSION'],
    'rank_math_person_scalar_untouched'        => ['RANK_MATH_VERSION'],
    'rank_math_person_array_types_untouched'   => ['RANK_MATH_VERSION'],
    'rank_math_missing_publisher_untouched'    => ['RANK_MATH_VERSION'],
    'rank_math_helper_missing_untouched'       => ['RANK_MATH_VERSION'],
    'rank_math_schema_module_off'              => ['RANK_MATH_VERSION'],
    'callbacks_return_input_on_non_array_yoast' => ['WPSEO_VERSION'],
    'callbacks_return_input_on_non_array_rank_math' => ['RANK_MATH_VERSION'],
    'callbacks_return_input_when_builder_throws_yoast' => ['WPSEO_VERSION'],
    'callbacks_return_input_when_builder_throws_rank_math' => ['RANK_MATH_VERSION'],
    'yoast_malformed_entity_untouched'         => ['WPSEO_VERSION'],
    'rank_math_malformed_publisher_untouched'  => ['RANK_MATH_VERSION'],
    'mode_survives_throwing_rank_math_helper'  => ['RANK_MATH_VERSION'],
    'own_output_survives_builder_throw'        => [],
    'own_output_escapes_script_breakers'       => [],
    'extension_values_are_tag_stripped'        => ['WPSEO_VERSION'],
    'own_output_on_no_organization_prints_nothing' => [],
    'own_output_logo_fallbacks'                => [],
];

$case = $argv[1] ?? null;
if ($case === null) {
    $failed = 0;
    foreach (array_keys(CASES) as $name) {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($name) . ' 2>&1', $out, $code);
        if ($code !== 0 || end($out) !== "ok {$name}") {
            $failed++;
            echo "FAIL case {$name} (exit {$code}):\n  " . implode("\n  ", $out) . "\n";
        }
    }
    if ($failed > 0) {
        echo "Tests failed: {$failed}\n";
        exit(1);
    }
    echo 'contact-schema-output-test: PASS (' . count(CASES) . " cases)\n";
    exit(0);
}
if (!array_key_exists($case, CASES)) {
    echo "unknown case {$case}\n";
    exit(1);
}

foreach (CASES[$case] as $constant) {
    define($constant, '1.0');
}
require __DIR__ . '/support/social-bricks-stubs.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/OpeningHours.php';
require dirname(__DIR__) . '/inc/ContactInfos/FieldRegistry.php';
require dirname(__DIR__) . '/inc/ContactInfos/PostType.php';
require dirname(__DIR__) . '/inc/SocialMediaAccounts/FieldRegistry.php';
require dirname(__DIR__) . '/inc/SocialMediaAccounts/PostType.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/Settings.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/Selection.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/GraphBuilder.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/Mode.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/PluginMerge.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/Output.php';
if ($case === 'mode_survives_throwing_rank_math_helper') {
    require __DIR__ . '/support/rank-math-throwing-helper-stub.php';
} elseif ($case !== 'rank_math_helper_missing_untouched') {
    require __DIR__ . '/support/rank-math-helper-stub.php';
}

// Port of core's wp_strip_all_tags().
function wp_strip_all_tags($text, $remove_breaks = false): string
{
    $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
    $text = strip_tags($text);
    if ($remove_breaks) {
        $text = preg_replace('/[\r\n\t ]+/', ' ', $text);
    }
    return trim($text);
}
function esc_url_raw($url, $protocols = null): string
{
    $url = trim((string) $url);
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return $protocols === null || in_array($scheme, $protocols, true) ? $url : '';
}
// Core's wp_json_encode() with PHP's json_encode() semantics.
function wp_json_encode($value, $flags = 0, $depth = 512)
{
    return json_encode($value, $flags, $depth);
}
function is_admin(): bool
{
    return $GLOBALS['test_is_admin'] ?? false;
}
function home_url($path = ''): string
{
    return 'https://acme.test' . $path;
}
function get_theme_mod($name, $default = false)
{
    return $GLOBALS['test_theme_mods'][$name] ?? $default;
}
function wp_get_attachment_image_url($id, $size = 'thumbnail')
{
    return $GLOBALS['test_attachment_urls'][$id] ?? false;
}
function get_site_icon_url($size = 512, $url = '', $blog_id = 0): string
{
    return $GLOBALS['test_site_icon'] ?? '';
}

function add_contact(int $id, string $title, array $meta): void
{
    global $test_posts, $test_meta, $test_post_lists;
    $test_posts[$id] = sfx_make_post($id, 'sfx_contact_info', 'publish', $title);
    $test_meta[$id] = array_map(static fn($v) => [$v], $meta);
    $test_post_lists['sfx_contact_info'][] = $test_posts[$id];
}

/** One marked main contact, one contact point, one social account; the feature switched on. */
function fixture(string $company = 'ACME GmbH'): void
{
    global $test_posts, $test_meta, $test_post_lists, $test_options;
    $test_posts = $test_meta = $test_post_lists = $test_options = [];
    add_contact(500, 'HQ', [
        '_contact_type' => 'main', '_company' => $company, '_street' => 'Hauptstr. 1', '_zip' => '45127',
        '_city' => 'Essen', '_phone' => '0201 123', '_email' => 'info@acme.test', '_sfx_contact_schema_org' => '1',
    ]);
    add_contact(503, 'Presse', [
        '_contact_type' => 'contact_point', '_sfx_contact_schema_area' => 'Press', '_email' => 'press@acme.test',
    ]);
    $test_posts[600] = sfx_make_post(600, 'sfx_social_account', 'publish', 'Account');
    $test_meta[600] = ['_link_url' => ['https://social.example/a']];
    $test_post_lists['sfx_social_account'][] = $test_posts[600];
    $test_options[Settings::OPTION] = ['enabled' => true, 'type' => 'Organization', 'chosen_once' => true];
}

function own_output(): string
{
    ob_start();
    Output::print_own();
    return (string) ob_get_clean();
}

function assert_mode(string $mode, string $reason, array $plugins, string $label): void
{
    assert_same(['mode' => $mode, 'reason' => $reason, 'plugins' => $plugins], Mode::detect(), $label);
}

/** The theme's entity as the fixture builds it. */
function theme_address(): array
{
    return ['@type' => 'PostalAddress', 'streetAddress' => 'Hauptstr. 1', 'postalCode' => '45127', 'addressLocality' => 'Essen'];
}
function theme_contact_points(): array
{
    return [['@type' => 'ContactPoint', 'contactType' => 'Press', 'email' => 'press@acme.test']];
}

function rank_math_data(mixed $publisher_type): array
{
    return [
        'publisher' => [
            '@type' => $publisher_type, '@id' => 'https://acme.test/#organization', 'name' => 'Plugin Name',
            'url' => 'https://acme.test', 'sameAs' => ['https://x.example/'],
            'logo' => ['@type' => 'ImageObject', '@id' => 'https://acme.test/#logo'],
        ],
        'place' => ['@type' => 'Place', '@id' => 'https://acme.test/#place', 'geo' => ['@type' => 'GeoCoordinates', 'latitude' => '51.4']],
    ];
}

function case_own_output_printed(): void
{
    assert_mode('own', 'no_seo_plugin', [], 'own: mode');
    $out = own_output();
    assert_same(1, substr_count($out, '<script type="application/ld+json" class="sfx-contact-schema">'), 'own: exactly one script');
    $json = json_decode(substr($out, strlen('<script type="application/ld+json" class="sfx-contact-schema">'), -strlen("</script>\n")), true);
    assert_same([
        '@context'     => 'https://schema.org',
        '@type'        => 'Organization',
        '@id'          => 'https://acme.test/#organization',
        'name'         => 'ACME GmbH',
        'address'      => theme_address(),
        'telephone'    => '0201 123',
        'email'        => 'info@acme.test',
        'sameAs'       => ['https://social.example/a'],
        'contactPoint' => theme_contact_points(),
        'url'          => 'https://acme.test/',
    ], $json, 'own: full entity, no logo configured');
    assert_contains('"https://schema.org"', $out, 'own: slashes unescaped');
}

function case_off_prints_nothing(): void
{
    global $test_options;
    $test_options[Settings::OPTION]['enabled'] = false;
    assert_mode('off', 'disabled', [], 'off: mode');
    assert_same('', own_output(), 'off: nothing printed');
    unset($test_options[Settings::OPTION]);
    assert_mode('off', 'disabled', [], 'off: missing option is off');
}

function case_register_front_end_only(): void
{
    global $test_added_actions;
    $GLOBALS['test_is_admin'] = true;
    Output::register();
    assert_same(false, isset($test_added_actions['wp_head']), 'register: nothing in wp-admin');
    $GLOBALS['test_is_admin'] = false;
    Output::register();
    assert_same([[Output::class, 'print_own']], $test_added_actions['wp_head'] ?? null, 'register: wp_head on the front end');
}

function case_other_plugin_beats_yoast(): void
{
    assert_mode('conflict', 'other_plugin', ['SEOPress'], 'other plugin: beats Yoast');
    assert_same('', own_output(), 'other plugin: nothing printed');
    $data = ['@type' => 'Organization', '@id' => 'https://acme.test/#organization', 'name' => 'Plugin Name'];
    assert_same($data, Output::yoast_organization($data, null), 'other plugin: Yoast entry untouched');
}

function case_aioseo_is_conflict(): void
{
    assert_mode('conflict', 'other_plugin', ['All in One SEO'], 'AIOSEO: conflict');
    assert_same('', own_output(), 'AIOSEO: nothing printed');
}

function case_saswp_is_conflict(): void
{
    assert_mode('conflict', 'other_plugin', ['Schema & Structured Data for WP & AMP'], 'SASWP: beats Rank Math');
    $data = rank_math_data('Organization');
    $GLOBALS['test_rank_math_settings'] = ['titles.knowledgegraph_type' => 'company'];
    assert_same($data, Output::rank_math_json_ld($data, null), 'SASWP: Rank Math entry untouched');
}

function case_two_of_yoast_rankmath_is_conflict(): void
{
    assert_mode('conflict', 'several_seo_plugins', ['Yoast SEO', 'Rank Math'], 'Yoast + Rank Math');
    $data = ['@type' => 'Organization', '@id' => 'https://acme.test/#organization', 'name' => 'Plugin Name'];
    assert_same($data, Output::yoast_organization($data, null), 'Yoast + Rank Math: Yoast untouched');
}

function case_two_of_yoast_tsf_is_conflict(): void
{
    assert_mode('conflict', 'several_seo_plugins', ['Yoast SEO', 'The SEO Framework'], 'Yoast + TSF');
    assert_same('', own_output(), 'Yoast + TSF: nothing printed');
}

function case_two_of_rankmath_tsf_is_conflict(): void
{
    global $test_options;
    $test_options['autodescription-site-settings'] = ['knowledge_output' => 0];
    assert_mode('conflict', 'several_seo_plugins', ['Rank Math', 'The SEO Framework'], 'Rank Math + TSF');
    assert_same('', own_output(), 'Rank Math + TSF: nothing printed even with TSF knowledge output off');
}

function case_tsf_on_suppresses(): void
{
    global $test_options;
    assert_mode('tsf_suppressed', 'tsf_knowledge_output_on', ['The SEO Framework'], 'TSF: option missing is on');
    $test_options['autodescription-site-settings'] = ['other' => 1];
    assert_mode('tsf_suppressed', 'tsf_knowledge_output_on', ['The SEO Framework'], 'TSF: key missing is on');
    $test_options['autodescription-site-settings'] = ['knowledge_output' => 1];
    assert_mode('tsf_suppressed', 'tsf_knowledge_output_on', ['The SEO Framework'], 'TSF: on');
    assert_same('', own_output(), 'TSF on: nothing printed');
}

function case_tsf_off_is_own(): void
{
    global $test_options;
    foreach ([0, '0', false] as $off) {
        $test_options['autodescription-site-settings'] = ['knowledge_output' => $off];
        assert_mode('own', 'tsf_knowledge_output_off', ['The SEO Framework'], 'TSF off: own output (' . var_export($off, true) . ')');
    }
    assert_same(1, substr_count(own_output(), '<script type="application/ld+json" class="sfx-contact-schema">'), 'TSF off: printed');
}

function case_yoast_mode(): void
{
    assert_mode('yoast', 'yoast_company', ['Yoast SEO'], 'Yoast: default is company');
    assert_same('', own_output(), 'Yoast: no own output');
}

// Yoast does not call wpseo_schema_organization in person mode; the mode reason carries it to the settings page.
function case_yoast_person_mode_nothing_added(): void
{
    global $test_options;
    $test_options['wpseo_titles'] = ['company_or_person' => 'person'];
    assert_mode('yoast', 'yoast_not_company', ['Yoast SEO'], 'Yoast person: reason');
    assert_same('', own_output(), 'Yoast person: no own output');
}

function case_yoast_merge_conflicting_values(): void
{
    $data = [
        '@type'     => ['Organization', 'Place'],
        '@id'       => 'https://acme.test/#organization',
        'name'      => 'Plugin Name',
        'url'       => 'https://acme.test/',
        'logo'      => ['@type' => 'ImageObject', '@id' => 'https://acme.test/#/schema/logo/image/'],
        'image'     => ['@id' => 'https://acme.test/#/schema/logo/image/'],
        'telephone' => '0000',
        'sameAs'    => ['https://x.example/', 'https://social.example/a'],
    ];
    $merged = Output::yoast_organization($data, null);
    $expected = $data;
    $expected['name'] = 'ACME GmbH';
    $expected['telephone'] = '0201 123';
    $expected['sameAs'] = ['https://x.example/', 'https://social.example/a'];
    $expected['address'] = theme_address();
    $expected['email'] = 'info@acme.test';
    $expected['contactPoint'] = theme_contact_points();
    assert_same($expected, $merged, 'Yoast merge: theme wins, sameAs de-duplicated, @id/@type/url/logo/image unchanged');
    assert_same(false, isset($merged['openingHoursSpecification']), 'Yoast merge: no own hours (C2)');
}

function case_yoast_empty_theme_values_keep_plugin(): void
{
    $plugin = ['@type' => 'Organization', 'name' => 'Plugin Name', 'telephone' => '0000', 'sameAs' => 'https://x.example/'];
    assert_same($plugin, PluginMerge::merge($plugin, ['name' => '', 'telephone' => '', 'contactPoint' => []]), 'merge: empty theme values keep the plugin\'s');
    assert_same(['https://x.example/', 'https://y.example/'], PluginMerge::merge($plugin, ['sameAs' => ['https://y.example/', 'https://x.example/']])['sameAs'], 'merge: scalar plugin sameAs merged');
    $theme = ['@type' => 'LocalBusiness', '@id' => 'x', 'url' => 'u', 'logo' => 'l', 'image' => 'i', 'department' => [['@type' => 'LocalBusiness']]];
    assert_same($plugin + ['department' => [['@type' => 'LocalBusiness']]], PluginMerge::merge($plugin, $theme), 'merge: identity keys never taken from the theme');
}

function case_rank_math_company_merged_and_place_synced(): void
{
    $GLOBALS['test_rank_math_settings'] = ['titles.knowledgegraph_type' => 'company'];
    assert_mode('rank_math', 'rank_math_company', ['Rank Math'], 'Rank Math: company');
    $data = rank_math_data('Organization');
    $data['place']['address'] = ['@type' => 'PostalAddress', 'streetAddress' => 'Old 1'];
    $merged = Output::rank_math_json_ld($data, null);
    $expected = $data;
    $expected['publisher']['name'] = 'ACME GmbH';
    $expected['publisher']['sameAs'] = ['https://x.example/', 'https://social.example/a'];
    $expected['publisher']['address'] = theme_address();
    $expected['publisher']['telephone'] = '0201 123';
    $expected['publisher']['email'] = 'info@acme.test';
    $expected['publisher']['contactPoint'] = theme_contact_points();
    $expected['place']['address'] = theme_address();
    assert_same($expected, $merged, 'Rank Math: publisher merged, place address synced, geo kept');
    $no_place = rank_math_data(['LocalBusiness', 'Organization']);
    unset($no_place['place']);
    assert_same(false, isset(Output::rank_math_json_ld($no_place, null)['place']), 'Rank Math: no place created');
    assert_same('ACME GmbH', Output::rank_math_json_ld($no_place, null)['publisher']['name'], 'Rank Math: array type without Person merged');
}

function case_rank_math_person_scalar_untouched(): void
{
    $GLOBALS['test_rank_math_settings'] = ['titles.knowledgegraph_type' => 'company'];
    $data = rank_math_data('Person');
    assert_same($data, Output::rank_math_json_ld($data, null), 'Rank Math: Person publisher untouched');
    $GLOBALS['test_rank_math_settings'] = ['titles.knowledgegraph_type' => 'person'];
    assert_mode('rank_math', 'rank_math_not_company', ['Rank Math'], 'Rank Math: person reason');
    $data = rank_math_data('Organization');
    assert_same($data, Output::rank_math_json_ld($data, null), 'Rank Math: person setting untouched');
}

function case_rank_math_person_array_types_untouched(): void
{
    $GLOBALS['test_rank_math_settings'] = ['titles.knowledgegraph_type' => 'company'];
    foreach ([['Person', 'Organization'], ['Organization', 'Person']] as $type) {
        $data = rank_math_data($type);
        assert_same($data, Output::rank_math_json_ld($data, null), 'Rank Math: ' . implode('+', $type) . ' untouched');
    }
}

function case_rank_math_missing_publisher_untouched(): void
{
    $GLOBALS['test_rank_math_settings'] = ['titles.knowledgegraph_type' => 'company'];
    foreach ([['@context' => 'https://schema.org'], ['publisher' => false], ['publisher' => ['name' => 'x']], ['publisher' => ['@type' => 5]]] as $data) {
        assert_same($data, Output::rank_math_json_ld($data, null), 'Rank Math: unexpected publisher untouched ' . json_encode($data));
    }
}

function case_rank_math_helper_missing_untouched(): void
{
    assert_mode('rank_math', 'rank_math_not_company', ['Rank Math'], 'Rank Math helper missing: not company');
    $data = rank_math_data('Organization');
    assert_same($data, Output::rank_math_json_ld($data, null), 'Rank Math helper missing: untouched');
}

function case_rank_math_schema_module_off(): void
{
    $GLOBALS['test_rank_math_settings'] = ['titles.knowledgegraph_type' => 'company'];
    $GLOBALS['test_rank_math_modules'] = ['local-seo'];
    assert_mode('rank_math', 'rank_math_schema_off', ['Rank Math'], 'Rank Math: schema module off');
    assert_same('', own_output(), 'Rank Math schema off: no own output');
}

function case_callbacks_return_input_on_non_array_yoast(): void
{
    foreach (['string', null, 5, false] as $input) {
        assert_same($input, Output::yoast_organization($input, null), 'Yoast: non-array returned ' . var_export($input, true));
    }
}

function case_callbacks_return_input_on_non_array_rank_math(): void
{
    $GLOBALS['test_rank_math_settings'] = ['titles.knowledgegraph_type' => 'company'];
    foreach (['string', null, 5, false] as $input) {
        assert_same($input, Output::rank_math_json_ld($input, null), 'Rank Math: non-array returned ' . var_export($input, true));
    }
}

/** Arrays that are not an organization entry: returned unchanged, never filled with theme data. */
function malformed_entities(): array
{
    $id = 'https://acme.test/#organization';
    return [
        'empty'            => [],
        'list'             => [['@type' => 'Organization', '@id' => $id]],
        'list of strings'  => ['Organization', $id],
        'no @id'           => ['@type' => 'Organization', 'name' => 'Plugin'],
        'empty @id'        => ['@type' => 'Organization', '@id' => ''],
        'non-string @id'   => ['@type' => 'Organization', '@id' => 5],
        'no @type'         => ['@id' => $id, 'name' => 'Plugin'],
        'empty @type'      => ['@type' => '', '@id' => $id],
        '@type []'         => ['@type' => [], '@id' => $id],
        '@type with empty' => ['@type' => ['Organization', ''], '@id' => $id],
        '@type with int'   => ['@type' => ['Organization', 5], '@id' => $id],
        '@type assoc'      => ['@type' => ['a' => 'Organization'], '@id' => $id],
        '@type number'     => ['@type' => 5, '@id' => $id],
    ];
}

function case_yoast_malformed_entity_untouched(): void
{
    foreach (malformed_entities() as $label => $data) {
        assert_same($data, Output::yoast_organization($data, null), "Yoast: {$label} unchanged");
    }
    $valid = ['@type' => ['Organization', 'Place'], '@id' => 'https://acme.test/#organization'];
    assert_same('ACME GmbH', Output::yoast_organization($valid, null)['name'] ?? null, 'Yoast: control, a valid entry is merged');
}

function case_rank_math_malformed_publisher_untouched(): void
{
    $GLOBALS['test_rank_math_settings'] = ['titles.knowledgegraph_type' => 'company'];
    foreach (malformed_entities() as $label => $publisher) {
        $data = ['publisher' => $publisher, 'place' => ['@type' => 'Place', 'address' => 'Old']];
        assert_same($data, Output::rank_math_json_ld($data, null), "Rank Math: publisher {$label} unchanged");
    }
    assert_same('ACME GmbH', Output::rank_math_json_ld(rank_math_data('Organization'), null)['publisher']['name'], 'Rank Math: control, a valid publisher is merged');
}

// A Rank Math whose helpers throw: a safe diagnostic state, no automatic output, no extension.
function case_mode_survives_throwing_rank_math_helper(): void
{
    assert_mode('unavailable', 'plugin_read_failed', ['Rank Math'], 'throwing helper: safe state');
    assert_same('', own_output(), 'throwing helper: no own output');
    $data = rank_math_data('Organization');
    assert_same($data, Output::rank_math_json_ld($data, null), 'throwing helper: Rank Math input unchanged');
}

function throwing_translation(): void
{
    function pll__($string)
    {
        throw new RuntimeException('builder failure');
    }
}

function case_callbacks_return_input_when_builder_throws_yoast(): void
{
    throwing_translation();
    $data = ['@type' => 'Organization', '@id' => 'https://acme.test/#organization', 'name' => 'Plugin Name'];
    assert_same($data, Output::yoast_organization($data, null), 'Yoast: input returned on throw');
}

function case_callbacks_return_input_when_builder_throws_rank_math(): void
{
    $GLOBALS['test_rank_math_settings'] = ['titles.knowledgegraph_type' => 'company'];
    throwing_translation();
    $data = rank_math_data('Organization');
    assert_same($data, Output::rank_math_json_ld($data, null), 'Rank Math: input returned on throw');
}

function case_own_output_survives_builder_throw(): void
{
    throwing_translation();
    assert_same('', own_output(), 'own: nothing printed, no exception escapes');
}

function case_own_output_escapes_script_breakers(): void
{
    global $test_meta;
    $test_meta[500]['_company'] = ['1 < 2 & <!-- </script><script>alert(1)</script>'];
    $test_meta[503]['_sfx_contact_schema_area'] = ['Press </script><script>alert(2)</script>'];
    $out = own_output();
    assert_same(1, substr_count($out, '<script'), 'escape: exactly one opening script tag');
    assert_same(1, substr_count($out, '</script>'), 'escape: exactly one closing script tag');
    assert_same(1, substr_count($out, '<script type="application/ld+json" class="sfx-contact-schema">'), 'escape: the one script is the JSON-LD one');
    $body = substr($out, strlen('<script type="application/ld+json" class="sfx-contact-schema">'), -strlen("</script>\n"));
    assert_same(false, str_contains($body, '<'), 'escape: no "<" inside the JSON');
    assert_same(false, str_contains($body, '&'), 'escape: no "&" inside the JSON');
    assert_contains('1 ' . '\\' . 'u003C 2 ' . '\\' . 'u0026', $body, 'escape: surviving "<" and "&" hex-encoded');
    assert_same(false, str_contains($out, 'alert'), 'escape: script content stripped');
}

function case_extension_values_are_tag_stripped(): void
{
    global $test_meta;
    $test_meta[500]['_company'] = ['ACME <b>GmbH</b><script>alert(1)</script>'];
    $test_meta[503]['_sfx_contact_schema_area'] = ['<i>Press</i>'];
    $merged = Output::yoast_organization(['@type' => 'Organization', '@id' => 'https://acme.test/#organization', 'name' => 'Plugin'], null);
    assert_same('ACME GmbH', $merged['name'], 'extension: name stripped');
    assert_same('Press', $merged['contactPoint'][0]['contactType'], 'extension: area stripped');
    assert_same(false, str_contains(json_encode($merged), '<'), 'extension: no tag reaches the plugin');
}

function case_own_output_on_no_organization_prints_nothing(): void
{
    global $test_meta, $test_options;
    $test_meta[500]['_company'] = [''];
    assert_same('', own_output(), 'no organization: company empty');
    fixture();
    $test_meta[500]['_contact_type'] = ['branch'];
    $test_options[Settings::OPTION]['chosen_once'] = false;
    assert_same('', own_output(), 'no organization: no main contact');
}

function case_own_output_logo_fallbacks(): void
{
    $logo = static fn(): ?string => json_decode(substr(own_output(), strlen('<script type="application/ld+json" class="sfx-contact-schema">'), -strlen("</script>\n")), true)['logo'] ?? null;
    $GLOBALS['test_site_icon'] = 'https://acme.test/icon.png';
    assert_same('https://acme.test/icon.png', $logo(), 'logo: site icon without custom logo');
    $GLOBALS['test_theme_mods'] = ['custom_logo' => 42];
    $GLOBALS['test_attachment_urls'] = [42 => 'https://acme.test/logo.png'];
    assert_same('https://acme.test/logo.png', $logo(), 'logo: custom logo first');
    $GLOBALS['test_attachment_urls'] = [];
    assert_same('https://acme.test/icon.png', $logo(), 'logo: missing attachment falls back to the site icon');
    $GLOBALS['test_site_icon'] = '';
    assert_same(null, $logo(), 'logo: none');
}

fixture();
("case_{$case}")();

global $failures;
if ($failures > 0) {
    exit(1);
}
echo "ok {$case}\n";
