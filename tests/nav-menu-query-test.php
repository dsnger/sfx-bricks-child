<?php

declare(strict_types=1);

require __DIR__ . '/support/nav-menu-query-stubs.php';
require __DIR__ . '/support/nav-menu-query-bricks-stubs.php';

require dirname(__DIR__) . '/inc/NavMenuQuery/MenuOptions.php';
require dirname(__DIR__) . '/inc/NavMenuQuery/QueryType.php';
require dirname(__DIR__) . '/inc/NavMenuQuery/MenuItemTags.php';
require dirname(__DIR__) . '/inc/NavMenuQuery/Controller.php';

use SFX\NavMenuQuery\MenuOptions;
use SFX\NavMenuQuery\QueryType;
use SFX\NavMenuQuery\MenuItemTags;
use SFX\NavMenuQuery\Controller;

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

// ------------------------------------------- Case 5: query type + controls

// 5a: the queryTypes array is merged into, never replaced. Bricks' five plus
// a hypothetical other plugin's entry must all survive.
$control_options = [
    'queryTypes' => [
        'post'    => 'Posts',
        'term'    => 'Terms',
        'user'    => 'Users',
        'api'     => 'API',
        'array'   => 'Array',
        'wooCart' => 'Cart contents',
    ],
    'queryOrder' => ['asc' => 'Ascending'],
];

$merged = QueryType::add_query_type($control_options);

assert_same('Menu Items', $merged['queryTypes']['sfx_nav_menu'] ?? null, 'Case 5a: the query type is registered');
assert_same(7, count($merged['queryTypes']), 'Case 5b: all pre-existing query types survive');
assert_same('Cart contents', $merged['queryTypes']['wooCart'] ?? null, "Case 5c: another plugin's entry is untouched");
assert_same(['asc' => 'Ascending'], $merged['queryOrder'] ?? null, 'Case 5d: unrelated control options are untouched');

// 5e: an element with no query loop is left strictly alone.
$plain = ['someControl' => ['type' => 'text']];
assert_same($plain, QueryType::add_element_controls($plain), 'Case 5e: an element without hasLoop is returned unchanged');

// 5f: an ungrouped loop element (Section, Container, Block, Div, Slider, Accordion).
$ungrouped = QueryType::add_element_controls([
    'hasLoop' => ['type' => 'checkbox'],
    'query'   => ['type' => 'query'],
]);

assert_true(isset($ungrouped['sfxNavMenuLocation']), 'Case 5f: the location control is added');
assert_true(isset($ungrouped['sfxNavMenuId']), 'Case 5g: the menu control is added');
assert_true(isset($ungrouped['sfxNavMenuParent']), 'Case 5h: the parent control is added');
assert_same(false, isset($ungrouped['sfxNavMenuLocation']['group']), 'Case 5i: no group is invented when the host has none');
assert_same('sfx_nav_menu', $ungrouped['sfxNavMenuLocation']['required'][2] ?? null, 'Case 5j: the control is gated on the query type');

// 5k: Map puts its query UI in the 'addresses' group. Ours must follow it there.
$grouped = QueryType::add_element_controls([
    'hasLoop' => ['type' => 'checkbox', 'group' => 'addresses'],
    'query'   => ['type' => 'query', 'group' => 'addresses'],
]);

assert_same('addresses', $grouped['sfxNavMenuLocation']['group'] ?? null, 'Case 5k: the location control joins the host group');
assert_same('addresses', $grouped['sfxNavMenuId']['group'] ?? null, 'Case 5l: the menu control joins the host group');
assert_same('addresses', $grouped['sfxNavMenuParent']['group'] ?? null, 'Case 5m: the parent control joins the host group');

// 5n: the once-guard. add_filter is stubbed to record every registration.
Bricks\Elements::$elements = ['section' => [], 'block' => [], 'map' => []];
$test_filters = [];

QueryType::register_element_controls();
QueryType::register_element_controls();

assert_same(1, test_hook_count('bricks/elements/section/controls'), 'Case 5n: section registered exactly once across two calls');
assert_same(1, test_hook_count('bricks/elements/block/controls'), 'Case 5o: block registered exactly once');
assert_same(1, test_hook_count('bricks/elements/map/controls'), 'Case 5p: map registered exactly once');
assert_same(3, test_hook_total(), 'Case 5q: three registrations total — the guard suppresses the repeat, not the work');

// -------------------------------------------------- Case 6: running the query

/** Minimal stand-in for Bricks\Query as bricks/query/run receives it. */
function make_query(string $object_type, array $settings): object
{
    return new class ($object_type, $settings) {
        public string $object_type;
        public array $settings;

        public function __construct(string $object_type, array $settings)
        {
            $this->object_type = $object_type;
            $this->settings    = $settings;
        }
    };
}

// 6a: the pass-through guard. A non-empty sentinel makes a `[]` return fail.
$sentinel = ['untouched'];
assert_same(
    $sentinel,
    QueryType::run($sentinel, make_query('post', [])),
    'Case 6a: an unrelated query type gets its results back byte-identical, not []'
);

// The fixture menu (id 4) again, now with active state on item 13.
$test_menu_items[4] = [
    new WP_Post(['ID' => 10, 'title' => 'Sehen &amp; Erleben', 'menu_item_parent' => '0', 'current_item_ancestor' => true]),
    new WP_Post(['ID' => 11, 'title' => 'Sehenswürdigkeiten', 'menu_item_parent' => '10']),
    new WP_Post(['ID' => 12, 'title' => 'Veranstaltungen',    'menu_item_parent' => '10']),
    new WP_Post(['ID' => 13, 'title' => 'Highlights',         'menu_item_parent' => '12', 'current' => true]),
    new WP_Post(['ID' => 20, 'title' => 'Veranstaltungen',    'menu_item_parent' => '0']),
    new WP_Post(['ID' => 30, 'title' => 'Kontakt',            'menu_item_parent' => '0']),
];

// 6b: top level.
$top = QueryType::run([], make_query('sfx_nav_menu', ['sfxNavMenuId' => 4, 'sfxNavMenuParent' => '']));
assert_same([10, 20, 30], array_map(fn($i) => $i->ID, $top), 'Case 6b: an empty parent yields the top level');

// 6c: an explicit parent.
$children = QueryType::run([], make_query('sfx_nav_menu', ['sfxNavMenuId' => 4, 'sfxNavMenuParent' => '10']));
assert_same([11, 12], array_map(fn($i) => $i->ID, $children), 'Case 6c: an explicit parent yields its direct children');

// 6d: keys are re-indexed, so Bricks sees a list.
assert_same([0, 1], array_keys($children), 'Case 6d: results are array_values()-ed');

// 6e / 6f: nothing to show.
assert_same([], QueryType::run([], make_query('sfx_nav_menu', ['sfxNavMenuId' => 4, 'sfxNavMenuParent' => '9999'])), 'Case 6e: an unknown parent yields nothing');
assert_same([], QueryType::run([], make_query('sfx_nav_menu', ['sfxNavMenuId' => 1234, 'sfxNavMenuParent' => ''])), 'Case 6f: a deleted menu yields nothing');
assert_same([], QueryType::run([], make_query('sfx_nav_menu', ['sfxNavMenuParent' => ''])), 'Case 6g: no menu selected at all yields nothing');

// 6h: an unassigned location does NOT fall back to the stored id.
assert_same(
    [],
    QueryType::run([], make_query('sfx_nav_menu', ['sfxNavMenuLocation' => 'footer', 'sfxNavMenuId' => 4, 'sfxNavMenuParent' => ''])),
    'Case 6h: an unassigned location yields nothing, it does not fall back to sfxNavMenuId'
);

// 6i: ancestry is computed on the full menu, not the filtered subset.
$test_classes_by_context_calls = [];
QueryType::run([], make_query('sfx_nav_menu', ['sfxNavMenuId' => 4, 'sfxNavMenuParent' => '10']));
assert_same([6], $test_classes_by_context_calls, 'Case 6i: _wp_menu_item_classes_by_context saw all six items, not the two filtered ones');

// ------------------------------------- Case 6j+: the relative parent

Bricks\Query::reset();
Bricks\Query::$any_looping  = 'outer-query';
Bricks\Query::$loop_objects = ['outer-query' => $test_menu_items[4][0]]; // item 10

$relative = QueryType::run([], make_query('sfx_nav_menu', ['sfxNavMenuId' => 4, 'sfxNavMenuParent' => 'current']));
assert_same([11, 12], array_map(fn($i) => $i->ID, $relative), "Case 6j: 'current' resolves to the enclosing loop's item");

Bricks\Query::reset();
assert_same(
    [],
    QueryType::run([], make_query('sfx_nav_menu', ['sfxNavMenuId' => 4, 'sfxNavMenuParent' => 'current'])),
    "Case 6k: 'current' with no enclosing loop yields nothing rather than silently falling back to the top level"
);

Bricks\Query::$any_looping  = 'outer-query';
Bricks\Query::$loop_objects = ['outer-query' => new WP_Post(['ID' => 500, 'post_type' => 'page'])];
assert_same(
    [],
    QueryType::run([], make_query('sfx_nav_menu', ['sfxNavMenuId' => 4, 'sfxNavMenuParent' => 'current'])),
    "Case 6l: 'current' with a non-menu-item enclosing object yields nothing"
);

Bricks\Query::reset();

// ------------------------------------------------- Case 7: value resolution

MenuItemTags::reset_cache();
Bricks\Query::reset();

$item = new WP_Post([
    'ID'                    => 13,
    'title'                 => 'Kunst & Kultur',
    'url'                   => 'https://example.test/kunst kultur',
    'menu_item_parent'      => '12',
    'target'                => '_blank',
    'xfn'                   => 'noopener',
    'description'           => 'Museen und mehr',
    'classes'               => ['custom-class', 'current-menu-item'],
    'current'               => true,
    'current_item_ancestor' => false,
]);

assert_same('Kunst & Kultur', MenuItemTags::value($item, 'title'), 'Case 7a: title');
assert_same('https://example.test/kunst kultur', MenuItemTags::value($item, 'url'), 'Case 7b: url is raw, unescaped');
assert_same('13', MenuItemTags::value($item, 'id'), 'Case 7c: id');
assert_same('_blank', MenuItemTags::value($item, 'target'), 'Case 7d: target');
assert_same('noopener', MenuItemTags::value($item, 'rel'), 'Case 7e: rel comes from xfn');
assert_same('custom-class current-menu-item', MenuItemTags::value($item, 'classes'), 'Case 7f: classes are joined');
assert_same('Museen und mehr', MenuItemTags::value($item, 'description'), 'Case 7g: description');
assert_same('1', MenuItemTags::value($item, 'is_active'), 'Case 7h: is_active renders as 1');
assert_same('', MenuItemTags::value($item, 'is_ancestor'), 'Case 7i: is_ancestor renders as empty when false');

// 7i2: the flag actually set. Without this, is_ancestor had no assertion that
// could fail — '' is also what a wrongly-read flag produces, so a regression
// that never reads current_item_ancestor at all would have gone unnoticed.
// This gives is_ancestor the same protection Case 7h gives is_active.
$ancestor_item = new WP_Post([
    'ID'                    => 12,
    'title'                 => 'Veranstaltungen',
    'current_item_ancestor' => true,
]);

assert_same('1', MenuItemTags::value($ancestor_item, 'is_ancestor'), 'Case 7i2: is_ancestor renders as 1 when the flag is set on the original item');
assert_same('', MenuItemTags::value($ancestor_item, 'is_active'), 'Case 7i3: an ancestor that is not the current item is not active');

assert_same(null, MenuItemTags::value($item, 'bogus'), 'Case 7j: an unknown key yields null');

// 7k: a non-menu-item post with no loop running is not ours.
$page = new WP_Post(['ID' => 500, 'post_type' => 'page']);
assert_same(null, MenuItemTags::value($page, 'title'), 'Case 7k: a page outside a loop yields null');
assert_same(null, MenuItemTags::value(null, 'title'), 'Case 7l: no post at all yields null');

// 7m: the loop-context fallback — this is the link path, where Bricks passes
// its own post id rather than the menu item.
Bricks\Query::$looping      = true;
Bricks\Query::$loop_objects = ['' => $item];

assert_same('Kunst & Kultur', MenuItemTags::value($page, 'title'), 'Case 7m: the item is recovered from the loop when $post is not it');
assert_same($item, MenuItemTags::item_from_context($page), 'Case 7n: item_from_context returns the loop object');
assert_same($item, MenuItemTags::item_from_context($item), 'Case 7o: a menu item passed directly is used as-is');

Bricks\Query::reset();
MenuItemTags::reset_cache();

// 7p-7r: clone isolation. wp_setup_nav_menu_item() mutates whatever it is
// handed — in production it writes ->title, ->url, ->target and friends onto
// the object — so the loop's own item must never be the thing handed over, or
// the query results are rewritten underneath the loop. Asserted by object
// identity and by a marker the original must not come back carrying; the stub
// does NOT delete properties to manufacture a signal, because real WordPress
// deletes nothing (wp-includes/nav-menu.php:955 onwards).

$test_setup_nav_menu_item_args = [];

$isolation_item = new WP_Post(['ID' => 77, 'title' => 'Isolation probe']);
MenuItemTags::value($isolation_item, 'title');

assert_same(1, count($test_setup_nav_menu_item_args), 'Case 7p: wp_setup_nav_menu_item ran exactly once');
assert_true(
    $test_setup_nav_menu_item_args[0] !== $isolation_item,
    'Case 7q: it was handed a CLONE, not the resolved item itself'
);
assert_same(false, $isolation_item->sfx_prepared, 'Case 7r: so the original never received the preparation mutation');

MenuItemTags::reset_cache();

// ------------------------------------------ Case 8: render_tag contract

MenuItemTags::reset_cache();
Bricks\Query::reset();

$tag_item = new WP_Post([
    'ID'      => 13,
    'title'   => 'Kunst & Kultur',
    'url'     => 'https://example.test/kunst kultur',
    'current' => true,
]);

/**
 * Bricks' own priority-10 handler on bricks/dynamic_data/render_tag, modelled.
 *
 * Providers::get_tag_value() looks the tag up in Providers::$tags and, finding
 * nothing, hands on '{' . $original_tag . '}' (providers.php:562). Our tags are
 * never in that array — bricks/dynamic_tags_list is builder-picker only — so
 * this is the exact shape our callback receives in production.
 */
function bricks_p10_unknown_tag($tag)
{
    return is_string($tag) ? '{' . $tag . '}' : $tag;
}

/**
 * The real filter sequence: Bricks at priority 10, ours at 20.
 *
 * The add_filter stub records registrations, it does not execute filters, so
 * the chain is composed by hand. Feeding render_tag() a bare tag — as this
 * case did before the brace fix — tests a shape production never delivers.
 */
function run_render_tag_chain($tag, $post, string $context = 'text')
{
    return MenuItemTags::render_tag(bricks_p10_unknown_tag($tag), $post, $context);
}

$page = new WP_Post(['ID' => 500, 'post_type' => 'page']);

// 8-int: the sequence end to end. These three fail if either half of the fix
// is missing — priority 20 without brace tolerance, or brace tolerance at a
// priority that runs before Bricks.
assert_same(
    'Kunst & Kultur',
    run_render_tag_chain('sfx_menu_item_title', $tag_item),
    'Case 8-int1: through Bricks p10 then ours at p20, an owned tag resolves to the raw value'
);
assert_same(
    '{post_title}',
    run_render_tag_chain('post_title', $tag_item),
    "Case 8-int2: an unrelated tag comes out of the sequence exactly as Bricks left it — we do not unwrap other providers' tags"
);
assert_same(
    '{sfx_menu_item_title}',
    run_render_tag_chain('sfx_menu_item_title', $page),
    'Case 8-int3: an owned but unresolvable tag comes back brace-wrapped, exactly as Bricks left it — not stripped, not empty'
);

// Rule 1: not ours — byte-identical, asserted by identity, in the brace-wrapped
// shape production delivers.
$foreign = '{post_title}';
assert_same($foreign, MenuItemTags::render_tag($foreign, $tag_item, 'text'), 'Case 8a: an unrelated tag is returned unchanged');
assert_same('{woo_product_price}', MenuItemTags::render_tag('{woo_product_price}', null, 'text'), "Case 8b: another provider's tag is untouched");

// Rule 2: ours, but unresolvable.
assert_same(
    '{sfx_menu_item_title}',
    MenuItemTags::render_tag('{sfx_menu_item_title}', $page, 'text'),
    'Case 8c: an owned tag outside a menu-item loop returns the tag, NOT an empty string'
);
assert_same('{sfx_menu_item_bogus}', MenuItemTags::render_tag('{sfx_menu_item_bogus}', $tag_item, 'text'), 'Case 8d: an unknown key under our prefix is returned unchanged');
assert_same('{sfx_menu_item_title:foo}', MenuItemTags::render_tag('{sfx_menu_item_title:foo}', $tag_item, 'text'), 'Case 8e: Bricks filter syntax is unsupported and left visible');

// Rule 3: ours, resolvable — raw value, no escaping.
assert_same('Kunst & Kultur', MenuItemTags::render_tag('{sfx_menu_item_title}', $tag_item, 'text'), 'Case 8f: an owned tag resolves');
assert_same(
    'https://example.test/kunst kultur',
    MenuItemTags::render_tag('{sfx_menu_item_url}', $tag_item, 'link'),
    'Case 8g: the URL is RAW — esc_url() here would double-escape what the control escapes'
);
assert_contains('&', MenuItemTags::render_tag('{sfx_menu_item_title}', $tag_item, 'text'), 'Case 8h: the title keeps its raw ampersand, unlike the render_content path');
assert_same('1', MenuItemTags::render_tag('{sfx_menu_item_is_active}', $tag_item, 'text'), 'Case 8i: is_active resolves');

// The picker can hand over an array rather than a string.
$array_tag = ['name' => '{sfx_menu_item_title}'];
assert_same($array_tag, MenuItemTags::render_tag($array_tag, $tag_item, 'text'), 'Case 8j: a non-string tag is returned as-is without a type error');

// 8k-8m: the bare form stays tolerated. Nothing in Bricks guarantees a
// priority-10 callback re-wraps — a third-party provider registered before us
// could hand the tag on untouched — so both shapes must work.
assert_same('Kunst & Kultur', MenuItemTags::render_tag('sfx_menu_item_title', $tag_item, 'text'), 'Case 8k: a bare owned tag still resolves');
assert_same('post_title', MenuItemTags::render_tag('post_title', $tag_item, 'text'), 'Case 8l: a bare unrelated tag is returned unchanged');
assert_same('sfx_menu_item_title', MenuItemTags::render_tag('sfx_menu_item_title', $page, 'text'), 'Case 8m: a bare unresolvable owned tag is returned bare — the miss returns the input, it does not normalise it');

// 8n: only the outermost pair is stripped, so a doubly-wrapped tag is not ours.
assert_same('{{sfx_menu_item_title}}', MenuItemTags::render_tag('{{sfx_menu_item_title}}', $tag_item, 'text'), 'Case 8n: one brace pair only — a doubly-wrapped tag falls through unchanged');

// 8o/8p: the loop-recovery path through render_tag. Bricks passes its own post
// (base.php:2524-style callers), not the menu item, so the item has to come
// from Bricks\Query. Case 7m covers this for value(); it was never covered
// through the filter itself.
Bricks\Query::$looping      = true;
Bricks\Query::$loop_objects = ['' => $tag_item];

assert_same(
    'Kunst & Kultur',
    run_render_tag_chain('sfx_menu_item_title', $page),
    'Case 8o: with a loop running, a non-menu-item $post still resolves — the item is recovered from Bricks\\Query'
);
assert_same(
    '{post_title}',
    run_render_tag_chain('post_title', $page),
    'Case 8p: loop recovery does not make us claim tags that are not ours'
);

Bricks\Query::reset();
MenuItemTags::reset_cache();

// -------------------------------- Case 9: render_content and the tag list

MenuItemTags::reset_cache();
Bricks\Query::reset();

$content_item = new WP_Post([
    'ID'                    => 13,
    'title'                 => 'Kunst & Kultur',
    'url'                   => 'https://example.test/kunst kultur',
    'target'                => '_blank',
    'xfn'                   => 'noopener',
    'description'           => 'Museen & mehr',
    'classes'               => ['current-menu-item', 'promo&sale'],
    'current'               => true,
    'current_item_ancestor' => true,
]);

// 9a: content with none of our tags is returned untouched, by identity.
$plain_content = 'Hello {post_title} world';
assert_same($plain_content, MenuItemTags::render_content($plain_content, $content_item, 'text'), 'Case 9a: content without our prefix is returned unchanged');

// 9b: outside a loop, our tags are left visible rather than blanked.
$page = new WP_Post(['ID' => 500, 'post_type' => 'page']);
$tagged = 'Link: {sfx_menu_item_title}';
assert_same($tagged, MenuItemTags::render_content($tagged, $page, 'text'), 'Case 9b: unresolvable content is returned unchanged');

// 9c: all nine substitute.
$all = '';
foreach (MenuItemTags::KEYS as $key) {
    $all .= '[' . $key . '={sfx_menu_item_' . $key . '}]';
}

$rendered = MenuItemTags::render_content($all, $content_item, 'text');

assert_same(false, strpos($rendered, '{sfx_menu_item_'), 'Case 9c: no tag survives unsubstituted');
assert_contains('[id=13]', $rendered, 'Case 9d: id is raw');
assert_contains('[is_active=1]', $rendered, 'Case 9e: is_active is raw');
assert_contains('[is_ancestor=1]', $rendered, 'Case 9f: is_ancestor is raw');

// 9g/9h: the escaping asymmetry with render_tag.
assert_contains('Kunst &amp; Kultur', $rendered, 'Case 9g: the title is esc_html-ed here, unlike in render_tag');
assert_contains('kunst%20kultur', $rendered, 'Case 9h: the url is esc_url-ed here, unlike in render_tag');
assert_contains('Museen &amp; mehr', $rendered, 'Case 9i: the description is esc_html-ed');

// 9j: classes are user-typed in the WordPress menu screen, so an ampersand
// in a class name is real user input reaching markup, not a generated value
// like id/is_active/is_ancestor. This is the one field in the esc_html arm
// whose fixture value is actually escape-sensitive, so it is the one that
// can fail if 'classes' is ever moved into the raw arm.
assert_contains('promo&amp;sale', $rendered, 'Case 9j: a CSS class is esc_html-ed, same as title/description');

// 9k: the builder tag list.
$picker = MenuItemTags::add_tags_to_builder([['name' => '{post_title}', 'label' => 'Title', 'group' => 'Post']]);

assert_same(10, count($picker), 'Case 9k: nine tags appended to the existing list');
assert_same('{post_title}', $picker[0]['name'], 'Case 9l: the pre-existing entry is preserved');

$names = array_column($picker, 'name');
assert_true(in_array('{sfx_menu_item_title}', $names, true), 'Case 9m: the title tag is registered with braces');
assert_true(in_array('{sfx_menu_item_is_ancestor}', $names, true), 'Case 9n: the is_ancestor tag is registered');

$ours = array_values(array_filter($picker, fn($t) => strpos($t['name'], '{sfx_menu_item_') === 0));
assert_same(1, count(array_unique(array_column($ours, 'group'))), 'Case 9o: all nine share one picker group');
assert_true($ours[0]['label'] !== '', 'Case 9p: each entry carries a label');

MenuItemTags::reset_cache();

// ------------------------------------------------- Case 10: controller wiring
// add_filter/add_action are stubbed to record callback, priority and
// accepted_args per hook, so constructing the controller shows exactly what
// the feature registers — not merely how often.

$test_filters = [];

new Controller();

assert_same(1, test_hook_count('bricks/setup/control_options'), 'Case 10a: the query type is registered');
assert_same(1, test_hook_count('bricks/load_elements/before'), 'Case 10b: element control registration is hooked');
assert_same(1, test_hook_count('bricks/query/run'), 'Case 10c: the query runner is hooked');
assert_same(1, test_hook_count('wp_ajax_sfx_nav_menu_parent_options'), 'Case 10d: the AJAX endpoint is hooked');
assert_same(1, test_hook_count('bricks/dynamic_tags_list'), 'Case 10e: the builder tag list is hooked');
assert_same(1, test_hook_count('bricks/dynamic_data/render_tag'), 'Case 10f: single-value tag rendering is hooked');
assert_same(1, test_hook_count('bricks/dynamic_data/render_content'), 'Case 10g: content tag rendering is hooked');

assert_same(7, test_hook_total(), 'Case 10h: exactly seven hooks — no collaborator silently skipped, none registered twice');

// 10l: priority 20 on render_tag. Bricks' own Providers::get_tag_value() holds
// priority 10 and, registered at include time, always wins a tie — at 10 our
// callback would only ever see the brace-wrapped fallback Bricks produced, and
// before the brace fix it could not resolve anything at all.
assert_same(
    20,
    test_hook_registrations('bricks/dynamic_data/render_tag')[0]['priority'] ?? null,
    "Case 10l: render_tag runs at priority 20, after Bricks' own priority-10 handler"
);

// 10m: accepted_args on bricks/query/run. Drop the 2 and run() is called with
// $results only, fataling on the missing $query — a defect no hook count sees.
assert_same(
    2,
    test_hook_registrations('bricks/query/run')[0]['accepted_args'] ?? null,
    'Case 10m: the query runner accepts both $results and $query'
);

// 10n: every recorded callback is actually invocable — a typo'd method name
// would otherwise register fine and only fail at render time.
foreach ($test_filters as $hook => $registrations) {
    foreach ($registrations as $registration) {
        assert_true(is_callable($registration['callback']), "Case 10n: the callback registered on {$hook} is callable");
    }
}

// The feature config the theme's registry reads.
$config = Controller::get_feature_config();
assert_same('sfx_general_options', $config['activation_option_name'] ?? null, 'Case 10i: gated on the general options array');
assert_same('enable_nav_menu_query', $config['activation_option_key'] ?? null, 'Case 10j: gated on the right key');
assert_same(false, isset($config['menu_slug']), 'Case 10k: no menu_slug, so no empty settings page is created');

// ------------------------- Case 11: the reconstructed post must not win
//
// The production shape, exactly. Bricks never hands our filters the object the
// query produced: Providers::render_tag() first replaces it with
// Helpers::get_post_preserving_preview( $post_id ) (providers.php:669), which
// falls through to get_post() (helpers.php:3768) and so to
// WP_Post::get_instance() — a new object built from the cached DB row. So two
// objects share one ID: the loop's decorated item, and a reconstruction
// carrying none of the runtime state _wp_menu_item_classes_by_context() added
// during the query run (QueryType.php:168).

MenuItemTags::reset_cache();
Bricks\Query::reset();

$decorated = new WP_Post([
    'ID'                    => 13,
    'title'                 => 'Kunst & Kultur',
    'classes'               => ['menu-item', 'menu-item-type-custom', 'menu-item-object-custom', 'current-menu-item'],
    'current'               => true,
    'current_item_ancestor' => false,
]);

// What WP_Post::get_instance() yields: the same ID and the same stored
// columns, and not one runtime property. The stub's defaults are that shape.
$reconstructed = new WP_Post(['ID' => 13, 'title' => 'Kunst & Kultur']);

Bricks\Query::$looping      = true;
Bricks\Query::$loop_objects = ['' => $decorated];

assert_same($decorated, MenuItemTags::item_from_context($reconstructed), 'Case 11a: the loop object beats the reconstructed $post');
assert_same(
    'menu-item menu-item-type-custom menu-item-object-custom current-menu-item',
    MenuItemTags::value($reconstructed, 'classes'),
    'Case 11b: classes come from the decorated instance, not the reconstruction'
);
assert_same('1', MenuItemTags::value($reconstructed, 'is_active'), 'Case 11c: so does is_active');

// The ancestor half, on its own id: an item is realistically either the
// current one or an ancestor of it.
$decorated_ancestor = new WP_Post([
    'ID'                    => 12,
    'title'                 => 'Veranstaltungen',
    'classes'               => ['menu-item', 'current-menu-ancestor'],
    'current_item_ancestor' => true,
]);

Bricks\Query::$loop_objects = ['' => $decorated_ancestor];

assert_same(
    '1',
    MenuItemTags::value(new WP_Post(['ID' => 12, 'title' => 'Veranstaltungen']), 'is_ancestor'),
    'Case 11d: is_ancestor comes from the decorated instance too'
);

// The non-current item is the diagnostic that identified this defect: before
// the fix it rendered NO classes at all, which is what proved the object had
// never been through _wp_menu_item_classes_by_context() rather than merely
// failing a current-page comparison.
$decorated_plain = new WP_Post([
    'ID'      => 20,
    'title'   => 'Kontakt',
    'classes' => ['menu-item', 'menu-item-type-custom', 'menu-item-object-custom'],
]);

Bricks\Query::$loop_objects = ['' => $decorated_plain];

$plain_reconstructed = new WP_Post(['ID' => 20, 'title' => 'Kontakt']);

assert_same(
    'menu-item menu-item-type-custom menu-item-object-custom',
    MenuItemTags::value($plain_reconstructed, 'classes'),
    'Case 11e: a NON-current item still carries its standard classes'
);
assert_same('', MenuItemTags::value($plain_reconstructed, 'is_active'), 'Case 11f: and is correctly not active');

Bricks\Query::reset();
MenuItemTags::reset_cache();

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all nav-menu-query tests\n";
exit(0);
