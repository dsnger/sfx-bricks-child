<?php

declare(strict_types=1);

// Schema: which contact is the organization, and the organization entity built from the contacts.
require __DIR__ . '/support/social-bricks-stubs.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/OpeningHours.php';
require dirname(__DIR__) . '/inc/ContactInfos/FieldRegistry.php';
require dirname(__DIR__) . '/inc/ContactInfos/PostType.php';
require dirname(__DIR__) . '/inc/SocialMediaAccounts/FieldRegistry.php';
require dirname(__DIR__) . '/inc/SocialMediaAccounts/PostType.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/Settings.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/Selection.php';
require dirname(__DIR__) . '/inc/ContactInfos/Schema/GraphBuilder.php';

use SFX\ContactInfos\Schema\GraphBuilder;
use SFX\ContactInfos\Schema\Selection;
use SFX\ContactInfos\Schema\Settings;

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
function update_post_meta($post_id, $key, $value)
{
    global $test_meta;
    $test_meta[(int) $post_id][$key] = [$value];
    return true;
}
function delete_post_meta($post_id, $key)
{
    global $test_meta;
    unset($test_meta[(int) $post_id][$key]);
    return true;
}

$hours_mon = ['Monday' => ['state' => 'open', 'ranges' => [['09:00', '17:00']]]];
$hours_sun = ['Sunday' => ['state' => 'closed', 'ranges' => []]];

/** A fresh fixture: one marked main, two branches (one with hours), two contact points, one excluded branch, two accounts. */
function fixture(): void
{
    global $test_posts, $test_meta, $test_meta_single_as_array, $test_post_lists, $test_options, $test_get_posts_calls, $hours_mon, $hours_sun;
    $test_posts = $test_meta = $test_meta_single_as_array = $test_options = $test_get_posts_calls = [];
    $test_post_lists = [];
    add_contact(500, 'publish', 'HQ post title', [
        '_contact_type' => 'main', '_company' => 'ACME <b>GmbH</b>', '_street' => 'Hauptstr. 1', '_zip' => '45127',
        '_city' => 'Essen', '_country' => 'Deutschland', '_phone' => '0201 123', '_email' => 'info@acme.test',
        '_sfx_contact_schema_org' => '1',
    ]);
    add_contact(501, 'publish', 'Filiale Essen', [
        '_contact_type' => 'branch', '_street' => 'Ruhrallee 5', '_zip' => '45138', '_city' => 'Essen',
        '_phone' => '0201 555',
    ]);
    add_contact(502, 'publish', 'Filiale Bochum', [
        '_contact_type' => 'branch', '_street' => 'Kortumstr. 2', '_city' => 'Bochum', '_email' => 'bochum@acme.test',
    ]);
    add_contact(503, 'publish', 'Touristinfo', [
        '_contact_type' => 'contact_point', '_sfx_contact_schema_area' => 'Tourist information',
        '_phone' => '0201 777', '_email' => 'tourist@acme.test',
    ]);
    add_contact(504, 'publish', 'Presse', [
        '_contact_type' => 'contact_point', '_sfx_contact_schema_area' => 'Press', '_email' => 'press@acme.test',
    ]);
    add_contact(505, 'publish', 'Filiale Excluded', [
        '_contact_type' => 'branch', '_street' => 'Weg 1', '_city' => 'Dortmund', '_sfx_contact_schema_excluded' => '1',
    ]);
    // A single read of a serialized array returns the array itself, as get_post_meta() does.
    $test_meta[500]['_sfx_contact_schema_hours'] = $hours_sun;
    $test_meta[501]['_sfx_contact_schema_hours'] = $hours_mon;
    $test_meta_single_as_array = [500 => ['_sfx_contact_schema_hours'], 501 => ['_sfx_contact_schema_hours']];
    foreach ([[600, 'https://social.example/a'], [601, 'https://social.example/b']] as [$id, $url]) {
        $test_posts[$id] = sfx_make_post($id, 'sfx_social_account', 'publish', 'Account ' . $id);
        $test_meta[$id] = ['_link_url' => [$url]];
        $test_post_lists['sfx_social_account'][] = $test_posts[$id];
    }
    $test_options[Settings::OPTION] = ['enabled' => true, 'type' => 'Organization', 'chosen_once' => true];
}

function set_meta(int $id, string $key, $value): void
{
    global $test_meta;
    $test_meta[$id][$key] = [$value];
}

function add_contact(int $id, string $status, string $title, array $meta): void
{
    global $test_posts, $test_meta, $test_post_lists;
    $test_posts[$id] = sfx_make_post($id, 'sfx_contact_info', $status, $title);
    $test_meta[$id] = array_map(static fn($v) => [$v], $meta);
    $test_post_lists['sfx_contact_info'][] = $test_posts[$id];
}

function graph(string $type = 'Organization'): array
{
    return GraphBuilder::organization(Selection::resolve(), $type);
}

$address_500 = ['@type' => 'PostalAddress', 'streetAddress' => 'Hauptstr. 1', 'postalCode' => '45127', 'addressLocality' => 'Essen', 'addressCountry' => 'Deutschland'];
$expected_org = [
    '@type'        => 'Organization',
    'name'         => 'ACME GmbH',
    'address'      => $address_500,
    'telephone'    => '0201 123',
    'email'        => 'info@acme.test',
    'sameAs'       => ['https://social.example/a', 'https://social.example/b'],
    'contactPoint' => [
        ['@type' => 'ContactPoint', 'contactType' => 'Tourist information', 'telephone' => '0201 777', 'email' => 'tourist@acme.test'],
        ['@type' => 'ContactPoint', 'contactType' => 'Press', 'email' => 'press@acme.test'],
    ],
    'department'   => [
        [
            '@type' => 'LocalBusiness', 'name' => 'Filiale Essen',
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Ruhrallee 5', 'postalCode' => '45138', 'addressLocality' => 'Essen'],
            'telephone' => '0201 555',
            'openingHoursSpecification' => [['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/Monday', 'opens' => '09:00', 'closes' => '17:00']],
        ],
        [
            '@type' => 'LocalBusiness', 'name' => 'Filiale Bochum',
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Kortumstr. 2', 'addressLocality' => 'Bochum'],
            'email' => 'bochum@acme.test',
        ],
    ],
];

// Settings: missing option → defaults (C10).
fixture();
unset($test_options[Settings::OPTION]);
assert_same(['enabled' => false, 'type' => 'Organization', 'chosen_once' => false], Settings::get(), 'settings: missing option is off');
Settings::update(['enabled' => true, 'type' => 'LocalBusiness']);
assert_same(['enabled' => true, 'type' => 'LocalBusiness', 'chosen_once' => false], Settings::get(), 'settings: update merges');
Settings::update(['type' => 'Person']);
assert_same('Organization', Settings::get()['type'], 'settings: unknown type falls back');

// full_fixture_structure
fixture();
$sel = Selection::resolve();
assert_same(['status' => 'ok', 'organization_id' => 500, 'location_ids' => [501, 502], 'contact_point_ids' => [503, 504], 'unused_main_ids' => [], 'marked_ids' => [500]], $sel, 'full: selection');
assert_same(['entity' => $expected_org, 'incomplete' => [], 'downgraded' => false], graph(), 'full: entity');
assert_same(false, graph('LocalBusiness')['downgraded'], 'full: LocalBusiness with address not downgraded');
$query = $test_get_posts_calls[0] ?? [];
assert_same(-1, $query['numberposts'] ?? null, 'full: all contacts');
assert_same(['menu_order' => 'ASC', 'date' => 'ASC'], $query['orderby'] ?? null, 'full: menu order, then date');
$social_query = end($test_get_posts_calls);
assert_same('sfx_social_account', $social_query['post_type'] ?? null, 'full: social accounts queried');
assert_same('publish', $social_query['post_status'] ?? null, 'full: published accounts only');
assert_same('menu_order', $social_query['orderby'] ?? null, 'full: accounts in list order');
$lb = graph('LocalBusiness')['entity'];
assert_same('LocalBusiness', $lb['@type'], 'full: LocalBusiness type');
assert_same([['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/Sunday', 'opens' => '00:00', 'closes' => '00:00']], $lb['openingHoursSpecification'] ?? null, 'full: LocalBusiness hours');
assert_true(!isset(graph()['entity']['openingHoursSpecification']), 'full: Organization carries no hours');

// empty_values_omitted
fixture();
set_meta(500, '_email', '');
set_meta(500, '_zip', '<i></i>');
set_meta(600, '_link_url', 'javascript:alert(1)');
$entity = graph()['entity'];
assert_true(!array_key_exists('email', $entity), 'empty: no email key');
assert_same(['@type' => 'PostalAddress', 'streetAddress' => 'Hauptstr. 1', 'addressLocality' => 'Essen', 'addressCountry' => 'Deutschland'], $entity['address'], 'empty: tag-only zip omitted');
assert_same(['https://social.example/b'], $entity['sameAs'], 'empty: unusable account URL omitted');
assert_true(!in_array('', array_merge(...array_map(static fn($d) => array_values(array_filter($d, 'is_string')), $entity['department'])), true), 'empty: no empty strings in departments');
set_meta(600, '_link_url', 'https://social.example/b');
assert_same(['https://social.example/b'], graph()['entity']['sameAs'], 'sameAs: duplicate URLs listed once');
set_meta(600, '_link_url', '');
set_meta(601, '_link_url', '');
assert_true(!array_key_exists('sameAs', graph()['entity']), 'empty: no sameAs without accounts');

// malformed_stored_hours_dropped
fixture();
set_meta(500, '_sfx_contact_schema_hours', ['Monday' => ['state' => 'open', 'ranges' => [['<b>x', '17:00']]], 'Sunday' => 'junk']);
$json = json_encode(graph('LocalBusiness')['entity']);
assert_true(!str_contains($json, '<b>') && !isset(graph('LocalBusiness')['entity']['openingHoursSpecification']), 'hours: malformed stored value never passes through');

// formatted_only_contact_has_no_address
fixture();
foreach ([500, 502] as $id) {
    set_meta($id, '_street', '');
    set_meta($id, '_city', '');
    set_meta($id, '_address', 'Hauptstr. 1, 45127 Essen');
}
$result = graph();
assert_same(null, GraphBuilder::postal_address(500), 'formatted: no PostalAddress');
assert_true(!array_key_exists('address', $result['entity']), 'formatted: organization without address');
assert_same(['Filiale Essen'], array_column($result['entity']['department'], 'name'), 'formatted: location left out');
assert_same([['id' => 500, 'missing' => ['address_parts']], ['id' => 502, 'missing' => ['address_parts']]], $result['incomplete'], 'formatted: organization and location listed once each, asking for the parts');
set_meta(502, '_address', '<p></p>');
assert_same([['id' => 502, 'missing' => ['address']]], array_slice(graph()['incomplete'], 1), 'formatted: tag-only formatted address counts as none');

// location_without_address_left_out_and_listed (street without city, and no name)
fixture();
set_meta(501, '_city', '');
$test_posts[502]->post_title = '';
$result = graph();
assert_true(!array_key_exists('department', $result['entity']), 'location: no department left');
assert_same([['id' => 501, 'missing' => ['address']], ['id' => 502, 'missing' => ['name']]], $result['incomplete'], 'location: both listed');

// contact_point_without_label_or_channel_left_out
fixture();
set_meta(503, '_sfx_contact_schema_area', '');
set_meta(504, '_email', '');
$result = graph();
assert_true(!array_key_exists('contactPoint', $result['entity']), 'contact point: none left');
assert_same([['id' => 503, 'missing' => ['area']], ['id' => 504, 'missing' => ['telephone_or_email']]], $result['incomplete'], 'contact point: both listed');

// local_business_without_address_downgrades
fixture();
set_meta(500, '_city', '');
$entity = graph('LocalBusiness')['entity'];
assert_same('Organization', $entity['@type'], 'downgrade: Organization');
assert_true(!isset($entity['openingHoursSpecification']), 'downgrade: no organization hours');
assert_same(true, graph('LocalBusiness')['downgraded'], 'downgrade: flagged');
assert_same(false, graph('Organization')['downgraded'], 'downgrade: not flagged for Organization');
assert_same(2, count($entity['department']), 'downgrade: locations kept');

// Organization without a company name is not publishable.
fixture();
set_meta(500, '_company', '<b></b>');
assert_same(['entity' => [], 'incomplete' => [['id' => 500, 'missing' => ['name']]], 'downgraded' => false], graph(), 'no name: nothing published, listed');

// marked_unpublished_fails_closed
fixture();
$test_posts[500]->post_status = 'draft';
assert_same('marked_invalid', Selection::resolve()['status'], 'unpublished mark: invalid');
assert_same(0, Selection::resolve()['organization_id'], 'unpublished mark: no organization');
assert_same(['entity' => [], 'incomplete' => [], 'downgraded' => false], graph(), 'unpublished mark: nothing published');
assert_same([500], Selection::resolve()['marked_ids'], 'unpublished mark: marked contact named');
fixture();
unset($test_meta[500]['_sfx_contact_schema_org']);
set_meta(501, '_sfx_contact_schema_org', '1');
assert_same('marked_invalid', Selection::resolve()['status'], 'branch mark: invalid');
assert_same([501], Selection::resolve()['marked_ids'], 'branch mark: marked contact named');
assert_same([], graph()['entity'], 'branch mark: nothing published');

// two_marks_fail_closed (a merge import brings a second mark)
fixture();
add_contact(506, 'publish', 'Imported', ['_contact_type' => 'main', '_company' => 'Other AG', '_sfx_contact_schema_org' => '1']);
$test_post_lists['sfx_contact_info'][] = sfx_make_post(499, 'sfx_contact_info', 'trash', 'Trashed main'); // last in query order
set_meta(499, '_sfx_contact_schema_org', '1');
assert_same('multiple_marked', Selection::resolve()['status'], 'two marks: status');
assert_same([499, 500, 506], Selection::resolve()['marked_ids'], 'two marks: every marked contact named, any status, ascending');
assert_same([], graph()['entity'], 'two marks: nothing published');

// deleted_after_choice_fails_closed
fixture();
unset($test_meta[500]['_sfx_contact_schema_org']);
add_contact(506, 'publish', 'Other main', ['_contact_type' => 'main', '_company' => 'Other AG']);
assert_same('deleted_after_choice', Selection::resolve()['status'], 'deleted: status');
assert_same([], graph()['entity'], 'deleted: no silent substitution');

// unconfigured_picks_first_main_by_menu_order
fixture();
unset($test_meta[500]['_sfx_contact_schema_org']);
$test_options[Settings::OPTION]['chosen_once'] = false;
add_contact(506, 'publish', 'Second main', ['_contact_type' => 'main', '_company' => 'Other AG']);
array_unshift($test_post_lists['sfx_contact_info'], sfx_make_post(507, 'sfx_contact_info', 'draft', 'Draft main'));
$sel = Selection::resolve();
assert_same('none_marked_unconfigured', $sel['status'], 'unconfigured: status');
assert_same(500, $sel['organization_id'], 'unconfigured: first published main in query order');
assert_same([506], $sel['unused_main_ids'], 'unconfigured: other main unused');
assert_same('ACME GmbH', GraphBuilder::organization($sel, 'Organization')['entity']['name'] ?? null, 'unconfigured: published');
// A contact without a stored type counts as main, as in the admin list.
fixture();
unset($test_meta[500]['_sfx_contact_schema_org'], $test_meta[500]['_contact_type']);
$test_options[Settings::OPTION]['chosen_once'] = false;
assert_same(500, Selection::resolve()['organization_id'], 'unconfigured: untyped contact is main');
fixture();
$test_options = [];
$test_post_lists['sfx_contact_info'] = [$test_posts[501], $test_posts[503]];
assert_same('no_main', Selection::resolve()['status'], 'no main: status');
assert_same([], graph()['entity'], 'no main: nothing published');

// save_choice_leaves_exactly_one_mark
fixture();
add_contact(506, 'publish', 'Other main', ['_contact_type' => 'main', '_company' => 'Other AG', '_sfx_contact_schema_org' => '1']);
add_contact(507, 'draft', 'Draft main', ['_contact_type' => 'main', '_sfx_contact_schema_org' => '1']);
$test_options[Settings::OPTION]['chosen_once'] = false;
assert_same(true, Selection::save_choice(506, [501, 505, 999]), 'save: accepted');
$marked = array_keys(array_filter($test_meta, static fn($m) => ($m['_sfx_contact_schema_org'][0] ?? '') === '1'));
assert_same([506], $marked, 'save: exactly one mark');
assert_same(true, Settings::get()['chosen_once'], 'save: chosen once set');
$excluded = array_keys(array_filter($test_meta, static fn($m) => ($m['_sfx_contact_schema_excluded'][0] ?? '') === '1'));
assert_same([501, 505], $excluded, 'save: exclusions written, unknown id ignored');
$sel = Selection::resolve();
assert_same(['ok', 506, [502], [503, 504], [500]], [$sel['status'], $sel['organization_id'], $sel['location_ids'], $sel['contact_point_ids'], $sel['unused_main_ids']], 'save: resolves to the choice');
add_contact(509, 'draft', 'Draft branch', ['_contact_type' => 'branch', '_sfx_contact_schema_excluded' => '1']);
assert_same(true, Selection::save_choice(506, []), 'save: again, no exclusions');
assert_same([509], array_keys(array_filter($test_meta, static fn($m) => isset($m['_sfx_contact_schema_excluded']))), 'save: exclusions cleared, unpublished contact not shown on the page keeps its own');

// save_choice_rejects_non_main
fixture();
$before = $test_meta;
$before_options = $test_options;
assert_same(false, Selection::save_choice(501, []), 'reject: branch');
add_contact(507, 'draft', 'Draft main', ['_contact_type' => 'main']);
$before = $test_meta;
assert_same(false, Selection::save_choice(507, []), 'reject: unpublished main');
assert_same(false, Selection::save_choice(999, []), 'reject: unknown id');
assert_same($before, $test_meta, 'reject: no meta changed');
assert_same($before_options, $test_options, 'reject: option unchanged');

// organization_not_excludable
fixture();
set_meta(500, '_sfx_contact_schema_excluded', '1');
assert_same(500, Selection::resolve()['organization_id'], 'not excludable: stored exclusion ignored');
assert_same(true, Selection::save_choice(500, [500, 502]), 'not excludable: save');
assert_true(!isset($test_meta[500]['_sfx_contact_schema_excluded']), 'not excludable: organization never marked excluded');
assert_same(['1'], $test_meta[502]['_sfx_contact_schema_excluded'] ?? null, 'not excludable: other exclusion kept');

// other_main_contacts_listed_unused
fixture();
add_contact(506, 'publish', 'Other main', ['_contact_type' => 'main', '_company' => 'Other AG']);
add_contact(508, 'draft', 'Draft main', ['_contact_type' => 'main']);
$sel = Selection::resolve();
assert_same([506], $sel['unused_main_ids'], 'unused: published other main listed');
assert_true(!str_contains(json_encode(GraphBuilder::organization($sel, 'Organization')), 'Other AG'), 'unused: not published');

// translated_values_used (Polylang); declared last so the cases above run without it.
fixture();
$test_pll_translations = [
    'ACME <b>GmbH</b>'   => 'ACME Ltd',
    'Filiale Essen'      => 'Essen branch',
    'Tourist information' => 'Tourist <script>x</script>office',
];
function pll__($string)
{
    global $test_pll_translations;
    return $test_pll_translations[$string] ?? $string;
}
$entity = graph()['entity'];
assert_same('ACME Ltd', $entity['name'], 'translated: company');
assert_same('Essen branch', $entity['department'][0]['name'], 'translated: location title');
assert_same('Tourist office', $entity['contactPoint'][0]['contactType'], 'translated: area label, tags stripped');

global $failures;
if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}
echo "contact-schema-graph-test: PASS\n";
