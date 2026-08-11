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

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all nav-menu-query tests\n";
exit(0);
