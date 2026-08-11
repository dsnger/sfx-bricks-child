<?php

declare(strict_types=1);

require __DIR__ . '/support/nav-menu-query-stubs.php';
require __DIR__ . '/support/nav-menu-query-bricks-stubs.php';

require dirname(__DIR__) . '/inc/NavMenuQuery/MenuOptions.php';

use SFX\NavMenuQuery\MenuOptions;

// ---------------------------------------------------------------- fixtures

$test_registered_nav_menus = [
    'primary' => 'Primary Navigation',
    'footer'  => 'Footer &amp; Legal',
];

$test_nav_menu_locations = [
    'primary' => 4,
];

$test_nav_menus = [
    (object) ['term_id' => 4, 'name' => 'Hauptmen&uuml;'],
    (object) ['term_id' => 7, 'name' => 'Footer'],
];

// ---------------------------------------------- Case 1: resolve_menu_id
// Every row of the precedence table in the spec.

assert_same(7, MenuOptions::resolve_menu_id('', 7), 'Case 1a: no location falls back to the menu id');
assert_same(0, MenuOptions::resolve_menu_id('', 0), 'Case 1b: neither set yields 0');
assert_same(4, MenuOptions::resolve_menu_id('primary', 7), 'Case 1c: an assigned location beats the stored id');
assert_same(0, MenuOptions::resolve_menu_id('footer', 7), 'Case 1d: an UNASSIGNED location yields 0, it does NOT fall back to the id');
assert_same(0, MenuOptions::resolve_menu_id('footer', 0), 'Case 1e: unassigned location, no id');
assert_same(4, MenuOptions::resolve_menu_id('primary', 0), 'Case 1f: assigned location with no id');

// ------------------------------------------------- Case 2: option lists

$locations = MenuOptions::locations();
assert_same('Primary Navigation', $locations['primary'] ?? null, 'Case 2a: location label');
assert_same('Footer & Legal', $locations['footer'] ?? null, 'Case 2b: location label is entity-decoded');

$menus = MenuOptions::menus();
assert_same('Hauptmenü', $menus['4'] ?? null, 'Case 2c: menu name is entity-decoded and keyed by string id');
assert_same('Footer', $menus['7'] ?? null, 'Case 2d: second menu present');

// ------------------------------------------- Case 3: parent_options
// A three-level menu. "Veranstaltungen" appears on two levels, so a bare
// title would be ambiguous — the path label is what disambiguates it.
// "Sehen &amp; Erleben" proves entity decoding.

$test_menu_items[4] = [
    new WP_Post(['ID' => 10, 'title' => 'Sehen &amp; Erleben', 'menu_item_parent' => '0']),
    new WP_Post(['ID' => 11, 'title' => 'Sehenswürdigkeiten', 'menu_item_parent' => '10']),
    new WP_Post(['ID' => 12, 'title' => 'Veranstaltungen',    'menu_item_parent' => '10']),
    new WP_Post(['ID' => 13, 'title' => 'Highlights',         'menu_item_parent' => '12']),
    new WP_Post(['ID' => 20, 'title' => 'Veranstaltungen',    'menu_item_parent' => '0']),
    new WP_Post(['ID' => 30, 'title' => 'Kontakt',            'menu_item_parent' => '0']),
];

$parents = MenuOptions::parent_options(4);
$keys    = array_keys($parents);

assert_same('current', $keys[0] ?? null, 'Case 3a: the relative entry comes first');
assert_contains('current item', $parents['current'], 'Case 3b: the relative entry is labelled');

assert_same(7, count($parents), 'Case 3c: relative entry plus all six items, leaves included');

assert_same('Sehen & Erleben (2)', $parents['10'] ?? null, 'Case 3d: entities decoded, direct children counted');
assert_same('Sehen & Erleben › Sehenswürdigkeiten (0)', $parents['11'] ?? null, 'Case 3e: leaf shows its path and (0)');
assert_same('Sehen & Erleben › Veranstaltungen (1)', $parents['12'] ?? null, 'Case 3f: nested Veranstaltungen carries its path');
assert_same('Veranstaltungen (0)', $parents['20'] ?? null, 'Case 3g: top-level Veranstaltungen is distinguishable from the nested one');
assert_same('Kontakt (0)', $parents['30'] ?? null, 'Case 3h: a leaf is listed, not filtered out');

// Empty and missing menus.
assert_same(['current'], array_keys(MenuOptions::parent_options(0)), 'Case 3i: menu id 0 yields only the relative entry');
$test_menu_items[99] = [];
assert_same(['current'], array_keys(MenuOptions::parent_options(99)), 'Case 3j: an empty menu yields only the relative entry');

// Case 3k: corrupt data. 41 -> 42 -> 41 is a cycle no WP UI can produce, but a
// bad import can. Without the visited guard this hangs the admin screen.
$test_menu_items[5] = [
    new WP_Post(['ID' => 41, 'title' => 'A', 'menu_item_parent' => '42']),
    new WP_Post(['ID' => 42, 'title' => 'B', 'menu_item_parent' => '41']),
];

$cyclic = MenuOptions::parent_options(5);
assert_same(3, count($cyclic), 'Case 3k: a cyclic menu still returns, relative entry plus both items');
assert_contains('A', $cyclic['41'], 'Case 3l: the cyclic item still gets a label');

// ------------------------------------------ Case 4: the AJAX endpoint
// wp_send_json_* throw SfxJsonSent instead of exiting, so each branch is
// observable. This helper runs the endpoint and returns what it sent.

function run_ajax_endpoint(array $get): SfxJsonSent
{
    $_GET = $get;

    try {
        MenuOptions::ajax_parent_options();
    } catch (SfxJsonSent $sent) {
        return $sent;
    }

    throw new RuntimeException('the endpoint returned without sending a response');
}

$test_nonce_valid      = false;
$test_current_user_can = true;
$sent = run_ajax_endpoint(['locationId' => 'primary']);
assert_same(false, $sent->success, 'Case 4a: a bad nonce is rejected');
assert_same('Invalid nonce', $sent->payload, 'Case 4b: with the translated message');

$test_nonce_valid      = true;
$test_current_user_can = false;
$sent = run_ajax_endpoint(['locationId' => 'primary']);
assert_same(false, $sent->success, 'Case 4c: an under-privileged user is rejected');
assert_same('Insufficient permissions', $sent->payload, 'Case 4d: with the translated message');

$test_current_user_can = true;

// Happy path: location 'primary' is assigned to menu 4, the six-item fixture.
$sent = run_ajax_endpoint(['locationId' => 'primary', 'menuId' => '']);
assert_same(true, $sent->success, 'Case 4e: a valid request succeeds');
assert_same(7, count($sent->payload), 'Case 4f: it returns the parent options for the resolved menu');

// Bricks sends {{control}} values as arrays for some control types.
$sent = run_ajax_endpoint(['locationId' => ['primary'], 'menuId' => '']);
assert_same(7, count($sent->payload), 'Case 4g: an array-wrapped value is unwrapped');

// A nested array survives reset() as an array. Casting it would yield "Array".
// Menu 7 gets two items so its option count (3) differs from the empty-menu
// count (1) — otherwise this assertion would pass even if the malformed
// location had become the string "Array" and resolved to no menu at all.
$test_menu_items[7] = [
    new WP_Post(['ID' => 71, 'title' => 'Impressum', 'menu_item_parent' => '0']),
    new WP_Post(['ID' => 72, 'title' => 'Datenschutz', 'menu_item_parent' => '0']),
];

$sent = run_ajax_endpoint(['locationId' => [['primary']], 'menuId' => '7']);
assert_same(true, $sent->success, 'Case 4h: a nested array does not fatal');
assert_same(
    3,
    count($sent->payload),
    'Case 4i: the malformed location is treated as empty, so menuId 7 is used — it did not become the string "Array"'
);

// One malformed parameter must not corrupt the other.
$sent = run_ajax_endpoint(['locationId' => '', 'menuId' => [['nonsense']]]);
assert_same(['current'], array_keys($sent->payload), 'Case 4j: a malformed menuId resolves to no menu, not a crash');

// Case 4k: locks wp_unslash() BEFORE sanitize_text_field(). The input is
// synthetic — WordPress' own slashing would not produce a backslash-space —
// but with realistic slashed input the two orders commute, so a fixture that
// can actually fail has to be constructed. Correct order normalises to 'x'
// and resolves the location; swapped order leaves ' x', which matches nothing.
$test_registered_nav_menus['x'] = 'Ordering probe';
$test_nav_menu_locations['x']   = 4;

$sent = run_ajax_endpoint(['locationId' => "\\ x", 'menuId' => '']);
assert_same(7, count($sent->payload), 'Case 4k: wp_unslash runs before sanitize_text_field, so the slashed slug still matches');

// An empty-array value: reset([]) is false, which is scalar, so it normalises
// to the empty string rather than being rejected. Behaviour is correct but
// incidental — this makes it explicit.
$sent = run_ajax_endpoint(['locationId' => [], 'menuId' => '']);
assert_same(['current'], array_keys($sent->payload), 'Case 4l: an empty-array value normalises to the empty string, not a crash');

$_GET = [];

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all nav-menu-query tests\n";
exit(0);
