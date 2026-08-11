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

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all nav-menu-query tests\n";
exit(0);
