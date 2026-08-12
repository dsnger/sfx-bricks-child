# Bricks Nav Menu Query — Design

**Date:** 2026-08-10
**Branch (planned):** `feat/bricks-nav-menu-query`

**Scope:**

- new `inc/NavMenuQuery/*` (4 files)
- one field in `inc/GeneralThemeOptions/Settings.php`
- one entry in `inc/ThemeSettingsOverview/OverviewProvider.php`
- one name in the module sentence at `README.md:5`
- new German strings in `languages/de_DE.po`, recompiled to `languages/de_DE.mo`
- one test plus two stub files in `tests/` — a global-namespace stub file and a `Bricks`-namespace one, because PHP forbids a bracketed namespace block alongside non-namespaced code (the same split as `social-bricks-stubs.php` / `sfx-namespaced-stubs.php`)
- new assertions in `tests/theme-settings-overview-provider-test.php` and one field in `tests/support/overview-general-theme-options-settings-stub.php`, covering the new toggle
- delete `query/example.php` (dead scaffold, loaded by nothing)

No new options are stored, so `uninstall.php` is untouched — the toggle lives inside the existing `sfx_general_options` array.

## Goal

Give Bricks a query loop over **WordPress menu items**, so a menu built in `Appearance → Menus` can drive Bricks markup directly. Bricks 2.3.10 ships `post`, `term`, `user`, `api` and `array` query types (`bricks/includes/setup.php:1125`) — none of them iterate `nav_menu_item` in a usable way.

The point is a single source of truth. Today mega-menu panels are commonly built from the page hierarchy (`post_parent`) while the mobile drilldown hangs off the WP menu — two places to maintain, which drift apart. With this query type both come from the menu, and an editor changes navigation without touching a Bricks template.

This generalises a site-specific snippet (visitessen, query type `navMenu`) into a theme feature. The snippet is the behavioural reference; nothing site-specific carries over.

**Non-goals:** rendering a menu (Bricks elements do that), a menu *element*, a walker replacement, page-hierarchy navigation, caching beyond a per-request static.

## Background — the theme's feature contract

`SFXBricksChildTheme::auto_register_features()` (`inc/SFXBricksChildTheme.php:323`) globs `inc/*/Controller.php` and registers every class exposing a static `get_feature_config()`. `load_dependencies()` (line 189) then instantiates each controller **only** if the config's `activation_option_key` is truthy inside `activation_option_name`.

`inc/SmoothScroll/` is the closest analogue: no post type, no persistence of its own, opt-in by default. It is followed here, minus its admin page — `SFXBricksChildAdmin` skips any feature whose config omits `menu_slug`/`page_title` (`inc/SFXBricksChildAdmin.php:45`), so a toggle-only feature is a supported shape, not a workaround.

`functions.php:130` scans feature directories for `PostType.php` to auto-discover CPTs. This feature has none, so it stays invisible to that scan — correct, and nothing to do.

## Background — verified Bricks facts

Every claim below was read out of Bricks 2.3.9 on disk. They are the load-bearing assumptions; if a Bricks update breaks one, this feature breaks.

Bricks updated to 2.3.10 partway through this branch's review. Every citation below was re-verified against the 2.3.10 copy on disk; the handful that had moved were corrected in place rather than left pointing at 2.3.9 line numbers.

| Fact | Location |
|---|---|
| `bricks/setup/control_options` carries `queryTypes` | `includes/setup.php:1125` |
| `bricks/query/run` — `apply_filters( 'bricks/query/run', [], $this )` | `includes/query.php:916` |
| `get_loop_object_type()` already classifies any `WP_Post` as `post` **before** firing `bricks/query/loop_object_type` | `includes/query.php:2121-2139` |
| `get_loop_builder_controls( $group )` stamps `group` onto `hasLoop` and `query` when the host passes one | `includes/elements/base.php:4086-4116` |
| Map passes the group `addresses` | `includes/elements/map.php:250` |
| `bricks/load_elements/before` fires at the start of **each** `Elements::load_elements()` call, before that call builds any element's controls | `includes/elements.php:252-254` |
| `render_tag` receives the tag with outer braces already stripped, seeded as the filter's default value | `includes/integrations/dynamic-data/providers.php:651-671` |
| `Elements::load_elements()` runs on the `wp` hook | `includes/elements.php:16` |
| `Query::$settings` **is** the element's settings array | `includes/query.php:121` |
| `objectType` is stripped from settings into `Query::$object_type` | `includes/query.php:116-119` |
| Query loop is offered on `section`, `container`, `block`, `div` | `includes/elements/container.php:88-93` |
| …and on `slider`, `accordion`, `map` | those files' `get_loop_builder_controls()` calls |
| `bricks/elements/{name}/controls` fires **after** `set_controls()` | `includes/elements/base.php:143-149` |
| `Elements::$elements` is a public static registry keyed by element name | `includes/elements.php:7` |
| `Query::is_any_looping()` returns the enclosing query ID | `includes/query.php:2433` |
| Bricks itself uses that to resolve dynamic data in a *nested, not-yet-running* query | `includes/integrations/dynamic-data/providers.php:784-792` |
| `bricks/dynamic_tags_list` is **builder-picker only** | `includes/integrations/dynamic-data/providers.php:797-801` ("allows the dynamic data providers to add their tags to the builder") |
| The content parser matches against `Providers::$tags`, built only from registered provider objects | `providers.php:222`, `providers.php:327` |
| `bricks/dynamic_data/render_content` fires regardless of that match | `providers.php:794` |
| `bricks/dynamic_data/render_tag` for single values | `providers.php:671` |
| `optionsAjax` is a real select feature | `includes/elements/wordpress.php:90`, `form.php:2146` |
| Builder AJAX nonce is `bricks-nonce-builder` | `includes/ajax.php:96` |

WP core: `_wp_menu_item_classes_by_context( &$items )` (`wp-includes/nav-menu-template.php:327`) sets `->current`, `->current_item_ancestor`, `->current_item_parent` and the `current-menu-*` classes. It is what `wp_nav_menu()` uses; `wp_get_nav_menu_items()` does **not** call it.

## Identifiers

All namespaced. No back-compat aliases — templates built on the snippet must be re-pointed by hand.

| Thing | Value |
|---|---|
| Query type key | `sfx_nav_menu` |
| Element controls | `sfxNavMenuLocation`, `sfxNavMenuId`, `sfxNavMenuParent` |
| Dynamic tags | `{sfx_menu_item_*}` |
| AJAX action | `sfx_nav_menu_parent_options` |
| Activation option key | `enable_nav_menu_query` (in `sfx_general_options`) |
| Namespace | `SFX\NavMenuQuery` |

The snippet's generic `navMenu` and `{menu_item_*}` are deliberately abandoned: both are plausible names for another plugin to claim, and a collision there fails silently (a query returning the wrong rows, or a tag resolved by someone else).

## Architecture

Four files, split by what changes together.

```
inc/NavMenuQuery/Controller.php    bootstrap, hook registration, feature config
inc/NavMenuQuery/MenuOptions.php   menu resolution (shared), select options, AJAX endpoint
inc/NavMenuQuery/QueryType.php     query type registration, element controls, run
inc/NavMenuQuery/MenuItemTags.php  tag registration and value resolution
```

`MenuOptions` is **shared infrastructure**, not builder-only: its option lists and AJAX endpoint serve the builder, but `resolve_menu_id()` is equally the render path's resolver — `QueryType::run()` calls it on every frontend request. That sharing is deliberate. A separate resolver on each side is how a builder preview and a frontend render start disagreeing about which menu a loop points at.

`QueryType` and `MenuItemTags` straddle both sides too. `QueryType` registers the query type and the element controls (builder) *and* runs the query (render); `MenuItemTags` registers the tag picker list (builder) *and* resolves tag values (render). No class here is single-sided — the split is by **subject matter**, not by builder-versus-render:

| File | Subject |
|---|---|
| `MenuOptions` | which menu, and what the selects offer |
| `QueryType` | the query type and its controls, and running it |
| `MenuItemTags` | the tag vocabulary and its values |

Each subject's builder half and render half share assumptions — a control's stored shape, a tag's name — and splitting them across files would put those assumptions in two places. The seam that matters is `MenuOptions`, because menu lookup is the one thing all three subjects need and the one place where builder and render disagreeing would be invisible.

`Controller` registers hooks and holds no logic. All other classes are static; there is no per-request state beyond one cache in `MenuItemTags`.

### Activation

`Settings::get_fields()` gains:

```php
[
    'id'          => 'enable_nav_menu_query',
    'label'       => __('Enable Menu Items query type', 'sfxtheme'),
    'description' => __('Adds a "Menu Items" query type to the Bricks query loop, so a WordPress menu can drive Bricks markup.', 'sfxtheme'),
    'type'        => 'checkbox',
    'default'     => 0,
    'group'       => 'general',
]
```

`default => 0` is what "not enabled by default" means here, matching `enable_smooth_scroll` and `enable_password_protected`. When off, `load_dependencies()` never constructs the controller, so no hook is added and the query type does not appear in the builder at all.

`OverviewProvider::build_builtin_modules_group()` gains `'enable_nav_menu_query' => ['label' => __('Menu Items query type', 'sfxtheme')]`.

German wording ("Menüpunkte") is a translation of the English source strings in `languages/`, not a hardcoded string. The snippet hardcodes German; that does not survive into a theme shipped across sites.

Every new user-facing string in this feature gets a `msgid`/`msgstr` pair in `languages/de_DE.po`, and the `.mo` is recompiled. That includes the query type label ("Menu Items" → "Menüpunkte"), all control labels, descriptions and placeholders, the relative parent entry, the nine tag labels and their group name, and the two AJAX error strings. A string added without its translation ships an English label into a German backend, so the `.po`/`.mo` update is part of the work, not a follow-up.

## Query type registration

Without this the feature is unreachable: the query type must be added to the `queryTypes` control option or it never appears in the builder's Query → Type dropdown.

```php
add_filter('bricks/setup/control_options', [QueryType::class, 'add_query_type']);

public static function add_query_type(array $control_options): array
{
    $control_options['queryTypes']['sfx_nav_menu'] = esc_html__('Menu Items', 'sfxtheme');

    return $control_options;
}
```

The array is merged into, never replaced — Bricks' own five types (`post`, `term`, `user`, `api`, `array`, `setup.php:1125`) and any other plugin's additions must survive. The label is the English source string; "Menüpunkte" comes from `de_DE.po`.

Test: given a `$control_options` array carrying the five built-in types plus one foreign key, the callback returns all of them plus `sfx_nav_menu`, and no existing value is altered.

## Builder controls

Registered on **every element that supports a query loop**, derived rather than listed:

```php
add_action('bricks/load_elements/before', [QueryType::class, 'register_element_controls']);

public static function register_element_controls(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    foreach (array_keys(\Bricks\Elements::$elements) as $name) {
        add_filter("bricks/elements/{$name}/controls", [self::class, 'add_element_controls']);
    }
}
```

`bricks/load_elements/before` (`includes/elements.php:254`) fires at the start of **each** `Elements::load_elements()` call, before that call iterates `Elements::$elements` and builds any element's controls. The primary call is on the `wp` hook (`elements.php:16`). Registering there sees the **complete** registry — an `init` priority-20 snapshot would miss any element registered later, and "any third-party element" would be a promise the code does not keep.

The action can fire more than once per request: `builder-permissions.php:28` calls `load_elements()` again on the Bricks Settings page. Hence the once-guard — without it the second firing adds a duplicate filter per element, and each element's controls would be built twice over, with the three controls overwriting themselves. Harmless in output, wasteful per element, and the kind of thing that only shows up as a slow admin screen.

`load_element()` can also be called individually without `load_elements()` running, so this registration is not guaranteed on every frontend path. That is fine: `container.php:89` gates the loop controls on `bricks_is_builder()`, so `hasLoop` does not exist on the frontend anyway. Controls only need to exist where they are edited; rendering reads stored values straight off `Query::$settings`.

The callback keys off `hasLoop` and inherits the host element's group:

```php
public static function add_element_controls(array $controls): array
{
    if (!isset($controls['hasLoop'])) {
        return $controls;
    }

    $group = $controls['query']['group'] ?? null;

    // ... build the three controls ...

    if ($group !== null) {
        foreach (['sfxNavMenuLocation', 'sfxNavMenuId', 'sfxNavMenuParent'] as $key) {
            $controls[$key]['group'] = $group;
        }
    }

    return $controls;
}
```

Copying `group` matters because `get_loop_builder_controls( $group )` (`base.php:4086-4116`) stamps a group onto both `hasLoop` and `query` when the host element passes one. Map does exactly that with `'addresses'` (`map.php:250`). Ungrouped controls would be detected on Map but rendered in a different panel from the query UI they belong to — present, but separated from the control they modify. Section, Container, Block, Div, Slider and Accordion pass no group, so `$group` is `null` there and nothing is stamped.

This works because `bricks/elements/{name}/controls` fires after `set_controls()` (`base.php:143-149`), so both `hasLoop` and `query` are already present. It covers all seven Bricks elements plus any element registered before `bricks/load_elements/before`. The snippet hardcodes `container`, `block`, `div`, missing `section`, `slider`, `accordion` and `map`.

### The three controls

```php
$controls['sfxNavMenuLocation'] = [
    'tab'         => 'content',
    'label'       => esc_html__('Menu location', 'sfxtheme'),
    'type'        => 'select',
    'options'     => MenuOptions::locations(),
    'placeholder' => esc_html__('Select a location', 'sfxtheme'),
    'description' => esc_html__('Follows whichever menu is assigned to this location.', 'sfxtheme'),
    'required'    => ['query.objectType', '=', 'sfx_nav_menu'],
];

$controls['sfxNavMenuId'] = [
    'tab'         => 'content',
    'label'       => esc_html__('Menu', 'sfxtheme'),
    'type'        => 'select',
    'options'     => MenuOptions::menus(),
    'description' => esc_html__('Only used when no location is selected.', 'sfxtheme'),
    'required'    => [
        ['query.objectType', '=', 'sfx_nav_menu'],
        ['sfxNavMenuLocation', '=', ''],
    ],
];

$controls['sfxNavMenuParent'] = [
    'tab'         => 'content',
    'label'       => esc_html__('Items below', 'sfxtheme'),
    'type'        => 'select',
    'optionsAjax' => [
        'action'     => 'sfx_nav_menu_parent_options',
        'locationId' => '{{sfxNavMenuLocation}}',
        'menuId'     => '{{sfxNavMenuId}}',
    ],
    'searchable'  => true,
    'placeholder' => esc_html__('Top level', 'sfxtheme'),
    'required'    => ['query.objectType', '=', 'sfx_nav_menu'],
];
```

`required` with the dotted path `query.objectType` is not used inside Bricks core, but it is the community pattern and is in production use in the reference snippet. The plan verifies it by hand in the builder.

### Menu resolution

One function, used by both the AJAX endpoint and the query runner, so the builder preview can never disagree with the frontend:

```php
MenuOptions::resolve_menu_id(string $location, $menu_id): int
```

The contract, stated exactly, because the precedence is the whole point:

```
if ($location !== '') {
    // location branch — $menu_id is NOT consulted, whatever it holds
    $locations = get_nav_menu_locations();
    return isset($locations[$location]) ? (int) $locations[$location] : 0;
}

return (int) $menu_id;   // 0 when unset
```

A **non-empty but unassigned** location returns `0`. It does not fall through to the stored menu ID. Selecting a location is a statement that this loop follows whatever is assigned there; if nothing is, the correct answer is "no menu", not "the menu you happened to pick earlier". Falling through would make an unassigned location render stale content that looks right and silently ignores the editor's choice.

`0` means "no menu" and every caller treats it as an empty result.

| `$location` | assigned? | `$menu_id` | result |
|---|---|---|---|
| `''` | — | `7` | `7` |
| `''` | — | `0`/unset | `0` |
| `'primary'` | yes → `4` | `7` | `4` |
| `'primary'` | no | `7` | `0` |
| `'primary'` | no | `0` | `0` |

Location is primary because a stored menu term ID is install-specific — it breaks when a template moves between sites, and when a menu is deleted and recreated. The ID select stays for menus not assigned to any location.

### The parent select

`MenuOptions::parent_options(int $menu_id): array` returns, in order:

1. `'current' => __('↑ Children of the current item', 'sfxtheme')` — the relative entry.
2. every menu item, keyed by ID, labelled with its full path and direct-child count:
   `Sehen & Erleben › Sehenswürdigkeiten (6)`, `Kontakt (0)`.

Paths are built by walking `menu_item_parent` upward, because names repeat between levels ("Veranstaltungen" can exist on level 1 and level 2) and a bare title would be ambiguous. Titles pass through `html_entity_decode( $text, ENT_QUOTES, 'UTF-8' )` — WordPress stores them escaped, so a raw label reads `Sehen &amp; Erleben`.

The upward walk carries a visited-ID guard:

```php
$seen = [];

while ($current && isset($titles[$current]) && !isset($seen[$current])) {
    $seen[$current] = true;
    array_unshift($path, $titles[$current]);
    $current = (int) $parents[$current];
}
```

A menu whose `menu_item_parent` values form a cycle is corrupt data, not something WordPress' UI can produce — but corrupt postmeta is reachable by a bad import or a direct DB edit, and without the guard the loop never terminates and takes the admin screen down with a timeout rather than a diagnosable error. "Never fatal" is meant literally, so the guard is not optional.

**All** items are listed, including leaves. The snippet lists only items that have children — an assumption that fits mega-menu panels and misleads everywhere else: an editor looking for "Kontakt" and not finding it concludes the feature is broken, and the list changes shape as the menu is edited. `(0)` states plainly why a loop will be empty. The select is `searchable`, so length is not a problem.

Empty menu, or `$menu_id === 0` → the relative entry only.

### AJAX endpoint

```php
add_action('wp_ajax_sfx_nav_menu_parent_options', [MenuOptions::class, 'ajax_parent_options']);
```

In order:

1. `check_ajax_referer('bricks-nonce-builder', 'nonce', false)` → `wp_send_json_error(__('Invalid nonce', 'sfxtheme'))`.
2. `current_user_can('edit_posts')` → `wp_send_json_error(__('Insufficient permissions', 'sfxtheme'))`.
3. Normalise both `$_GET['locationId']` and `$_GET['menuId']` through one helper.
4. `wp_send_json_success(MenuOptions::parent_options(MenuOptions::resolve_menu_id($location, $menu_id)))`.

The normaliser, applied to each value independently:

```php
private static function scalar_param(string $key): string
{
    $value = $_GET[$key] ?? '';

    // Bricks sends {{control}} values as arrays for some control types.
    if (is_array($value)) {
        $value = reset($value);
    }

    // Anything still not scalar (nested array, object) is malformed input.
    if (!is_scalar($value)) {
        return '';
    }

    return sanitize_text_field(wp_unslash((string) $value));
}
```

Three things the snippet's version does not do. `reset()` on a nested array yields another array, and casting that to string emits a notice and produces `"Array"` — so the post-unwrap `is_scalar()` check rejects it as the malformed input it is, rather than passing garbage into the resolver. `wp_unslash()` undoes the slashes WordPress adds to all superglobals, and must run *before* `sanitize_text_field()`, or a location slug containing an escaped character is sanitised in its slashed form and then never matches a real key. Both values are rejected independently, so one malformed parameter cannot corrupt the other.

The capability check is an addition to the snippet, which verifies the nonce alone. Menu structure is not a secret, but an endpoint that enumerates site structure to any authenticated user is a needless disclosure, and the check is one line.

No `wp_ajax_nopriv_` handler is registered — the builder is never available to logged-out users.

## Query execution

```php
add_filter('bricks/query/run', [QueryType::class, 'run'], 10, 2);

public static function run($results, $query)
{
    if ($query->object_type !== 'sfx_nav_menu') {
        return $results;   // untouched, including type
    }

    // ...
}
```

The guard returns **`$results` itself**, not `[]` and not a cast — `bricks/query/run` is a shared filter that every query type and every other plugin passes through, and a handler that normalises the value it was given breaks whichever handler runs after it. Bricks seeds the chain with `[]` (`query.php:916`), but nothing entitles this callback to assume that is still what is flowing. The snippet gets this right; it is written down because it is the one line whose damage is invisible in this feature's own tests.

After the guard:

1. `$menu_id = MenuOptions::resolve_menu_id($query->settings['sfxNavMenuLocation'] ?? '', $query->settings['sfxNavMenuId'] ?? 0)`. `0` → `[]`.
2. `$items = wp_get_nav_menu_items($menu_id)`. Falsy → `[]`.
3. `_wp_menu_item_classes_by_context($items)` — on the **full, unfiltered** set. Ancestor detection compares against every item; running it after filtering would leave `current_item_ancestor` wrong on exactly the items where it matters.
4. Resolve the parent (below).
5. `array_values(array_filter($items, fn($i) => (string) $i->menu_item_parent === $parent))`.

Returned values are `WP_Post` objects.

### Parent resolution

The stored value is exactly one of three things — empty, a numeric ID, or the literal `current`:

```php
$parent = (string) ($query->settings['sfxNavMenuParent'] ?? '');

if ($parent === 'current') {
    $enclosing = \Bricks\Query::is_any_looping();
    if (!$enclosing) {
        return [];
    }
    $object = \Bricks\Query::get_loop_object($enclosing);
    if (!$object instanceof \WP_Post || $object->post_type !== 'nav_menu_item') {
        return [];
    }
    $parent = (string) $object->ID;
} else {
    $parent = $parent === '' ? '0' : (string) (int) $parent;
}
```

`is_any_looping()` returns the ID of the innermost query that is *currently looping*. While an inner query is being built, that is the enclosing loop — this is precisely how Bricks resolves dynamic data for nested queries (`providers.php:784-792`), so the mechanism is Bricks' own, not a trick.

`current` with no enclosing loop returns empty rather than falling back to the top level. A silent fallback would render a plausible-looking wrong menu; empty is diagnosable.

Because `current` exists, the snippet's `bricks_render_dynamic_data()` branch is **removed**. Its only purpose was to make nesting expressible before there was a relative option, and it required the editor to know a tag by heart to type into a select. Dropping it leaves one code path and a parent value that is always one of three known shapes.

### Loop object type — deliberately not filtered

The snippet adds a `bricks/query/loop_object_type` filter forcing `'post'`. It is **not** carried over: it is a no-op.

`Query::get_loop_object_type()` classifies by class before the filter runs (`query.php:2121-2139`):

```php
if ( is_a( $object, 'WP_Post' ) ) {
    $object_type = 'post';
}
```

The loop objects here are the `WP_Post` objects returned by `wp_get_nav_menu_items()`, and nothing in this feature filters `bricks/query/loop_object`, so Bricks already classifies them as `post`. That classification is a **no-op on the ordinary in-loop path** — and it is precisely *why* `$post` is the enclosing page rather than the menu item, not what makes it the menu item. `Providers::render_content()` only swaps the loop object into `$post` when the classification is something *other than* `post`:

```php
if ( \Bricks\Query::is_looping() && \Bricks\Query::get_loop_object_type() !== 'post' ) {
    $post = get_post();
}
```

(`providers.php:770-772`). Because our items classify **as** `post`, this reassignment is skipped, so `$post` stays whatever `get_post_preserving_preview()` reconstructed from the page's own post ID a few lines above — never the item. This is exactly the defect `MenuItemTags::item_from_context()` exists to route around (see "Value resolution" below).

The classification's one genuinely load-bearing effect is elsewhere, in the nested "before query run" branch (`providers.php:784-792`), reached only while an *enclosing, not-yet-running* query is being resolved:

```php
if ( $loop_object_type === 'post' ) {
    $post = $loop_object;
}
```

There, classifying as `post` is what hands `$post` the enclosing menu item. That is the one place it matters — everywhere else, including the ordinary in-loop path above, a filter that recomputes the value Bricks just computed adds a hook to every loop iteration of every query on the site to change nothing.

This is noted rather than silently dropped so nobody ports it back in from the snippet on the assumption it was load-bearing.

## Dynamic tags

Nine tags, all prefixed `sfx_menu_item_`:

| Tag | Source | Renders as |
|---|---|---|
| `title` | `$item->title` (nav label, not the post title) | string |
| `url` | `$item->url` (resolved target) | URL |
| `id` | `$item->ID` | integer |
| `target` | `$item->target` | `_blank` or empty |
| `rel` | `$item->xfn` | string |
| `classes` | `implode(' ', $item->classes)` | includes `current-menu-item` etc. |
| `description` | `$item->description` | string |
| `is_active` | `$item->current` | `'1'` or `''` |
| `is_ancestor` | `$item->current_item_ancestor` | `'1'` or `''` |

`is_active` / `is_ancestor` render as `'1'` or empty so a Bricks condition ("is not empty") reads naturally. `classes` covers the CSS route to the same information.

Without those two, the current page cannot be marked in the menu — the single most common thing a custom menu needs and the one thing the snippet's three tags cannot express.

### Value resolution

```php
MenuItemTags::item_from_context($post): ?WP_Post
```

**The loop object wins, `$post` is the fallback.** When `\Bricks\Query::is_looping()`, the item is `\Bricks\Query::get_loop_object()` if that is a `nav_menu_item`; only otherwise is `$post` used, and only if it is a `nav_menu_item` itself.

That order is not interchangeable. The loop object is the item `QueryType::run()` decorated — it carries `->current`, `->current_item_ancestor` and the classes `_wp_menu_item_classes_by_context()` added (`QueryType.php:168`). What Bricks hands to the filter as `$post` is a plain re-fetch by ID (`providers.php:669`, `helpers.php:3768`) with none of that on it. Prefer `$post` and every context tag renders empty for every item, current or not.

The `$post` fallback is still required: on the link path Bricks calls `bricks_render_dynamic_data()` with its own post ID (`includes/elements/base.php:2524`), and outside any loop a `nav_menu_item` passed directly is all there is.

`get_loop_object()` is called with no query ID on purpose — bare, it returns the innermost currently-looping query's object, which is the right item inside a nested loop.

```php
MenuItemTags::value(?WP_Post $post, string $key): ?string
```

Resolves the item, then splits the nine tags by whether the value is *identity* or *context*:

- **`classes`, `is_active`, `is_ancestor` — never cached.** Read straight off the resolved item on every call. These are per-request context, and two objects with the same ID can legitimately disagree about them. Caching them by ID means one earlier read from an undecorated instance suppresses active state for every later read of that ID — the same defect as preferring the wrong object, only harder to see.
- **The other six (`title`, `url`, `id`, `target`, `rel`, `description`) — cached by item ID.** On first use per item, `wp_setup_nav_menu_item( clone $post )` turns `_menu_item_object_id` / `_menu_item_url` into a real `->url` and sets `->title` to the navigation label rather than the page title. The `clone` keeps the loop's `$post` unmutated. These are identity: same ID, same value, whatever the request.

Returns `null` for an unknown key or when no menu item can be resolved. Callers distinguish `null` (not ours — leave alone) from `''` (ours, empty).

### Why two resolution filters

Not redundancy. Verified in the Bricks source:

- **`bricks/dynamic_data/render_tag`** (`providers.php:671`) — single-value contexts that resolve *one whole tag* through `Providers::render_tag()`: an image or background source (`assets.php:2072`, `builder.php:1952`, `query.php:1996`), a lightbox image (`base.php:2456`), the Code element's `useDynamicData` (`builder.php:1761`), SVG, and the builder's dynamic-data preview.
- **`bricks/dynamic_data/render_content`** (`providers.php:794`) — text content. The content parser matches found tags against `Providers::$tags`, which is assembled purely from registered *provider objects* (`providers.php:222`). `bricks/dynamic_tags_list` does not feed it — the source comments it as builder-picker only (line 797). So our tags are never in `$registered_tags` and the parser will not resolve them; the `render_content` filter fires regardless (line 794) and does the substitution itself.

**A Link's `href` and a condition's operand are `render_content`, not `render_tag`.** Both go through `bricks_render_dynamic_data()` (`functions.php:286`), which is a one-line wrapper around `Providers::render_content()`:

- Link: `Element::set_link_attributes()` calls `bricks_render_dynamic_data( $link_dd_tag, $post_id, $context )` (`base.php:2524`). The `link` *context* is passed, but the *entry point* is still the content path.
- Condition: `Conditions::check()` calls `$instance->render_dynamic_data( … )` (`conditions.php:930`, `1084`, `1093`), which is `bricks_render_dynamic_data()` again (`base.php:4264-4267`).

This matters because it bounds the blast radius of a `render_tag` defect: with `render_content` working and `render_tag` inert, text, links and conditions all still resolve, and only image-ish and preview contexts break.

#### `render_tag` pass-through contract

This filter is shared by every dynamic-data provider on the site, and Bricks seeds it with the tag itself:

```php
$value = apply_filters( 'bricks/dynamic_data/render_tag', $tag, $post, $context );
```

So the incoming `$tag` doubles as "no one has resolved this yet". A callback that returns anything else for a tag it does not own — `''`, `null`, a normalised copy — destroys the value for every provider registered after it. The contract, exactly:

```php
add_filter('bricks/dynamic_data/render_tag', [self::class, 'render_tag'], 20, 3);

public static function render_tag($tag, $post, $context)
{
    if (!is_string($tag)) {
        return $tag;                     // picker array — byte-identical
    }

    $needle = $tag;

    if (strlen($needle) > 1 && $needle[0] === '{' && substr($needle, -1) === '}') {
        $needle = substr($needle, 1, -1);
    }

    if (strpos($needle, 'sfx_menu_item_') !== 0) {
        return $tag;                     // not ours — byte-identical
    }

    $key = substr($needle, strlen('sfx_menu_item_'));

    if (!in_array($key, self::KEYS, true)) {
        return $tag;                     // ours by prefix, but not a known key
    }

    $value = self::value($post, $key);   // MenuItemTags::value()

    return $value === null ? $tag : $value;   // unresolvable — hand it back
}
```

Every miss returns `$tag`, never `$needle`: whatever the previous callback produced is what the next one must see.

Three rules:

1. **Unrelated tag** → the exact incoming `$tag`, unchanged. Asserted by identity, not equality.
2. **Owned tag that cannot be resolved** — used outside a menu-item loop, so `value()` returns `null` → the exact incoming `$tag`. Not an empty string: returning `''` would suppress a later provider's answer and, in a Link URL, silently produce `href=""` instead of leaving a visibly unresolved tag the editor can see and fix.
3. **Owned tag inside the loop** → the **raw** resolved value, unescaped. The consuming control escapes for its own context; `esc_url()` here would be applied twice and corrupt the URL. This is the deliberate asymmetry with `render_content`, which does escape because it writes straight into markup.

#### Priority 20 and brace tolerance — both mandatory

`Providers::render_tag()` does strip the outer braces before firing the filter (`providers.php:651-654`), so the tag is *seeded* as `sfx_menu_item_title`. But Bricks is not merely the caller here — it is also the first callback on its own filter:

```php
add_filter( 'bricks/dynamic_data/render_tag', [ $instance, 'get_tag_value' ], 10, 3 );   // providers.php:150
```

`Providers::register()` runs at include time — `init.php:165`, reached from `bricks/functions.php:204` when the parent theme's `functions.php` loads. The child theme's `functions.php` runs *first* but only schedules `after_setup_theme` priority 1, so by the time our `Controller` constructor calls `add_filter()`, Bricks' callback is already there. At the same priority WordPress preserves registration order, so a priority-10 registration of ours can only ever run **second**.

And Bricks' handler does not pass unknown tags through untouched. `get_tag_value()` looks the tag up in `Providers::$tags`, does not find ours (see above — `dynamic_tags_list` does not feed that array), and returns:

```php
$replace_tag = apply_filters( 'bricks/dynamic_data/replace_nonexistent_tags', false );
$value       = $replace_tag ? '' : '{' . $original_tag . '}';   // providers.php:562
```

So the value reaching us is `{sfx_menu_item_title}`, braces restored.

Hence both halves:

- **Priority 10 + brace tolerance** — we still run after Bricks, which is fine, but only because the tie broke that way; nothing enforces it.
- **Priority 20 + no brace tolerance** — we receive `{sfx_menu_item_title}`, the prefix test fails at offset 0, and the tag survives into the output. This is the state the feature shipped in until it was caught: `render_tag` was inert in production while the suite, which fed it bare tags, stayed green.
- **Priority 10 + brace tolerance, ordering flipped** — we resolve first and hand Bricks a plain `Kunst & Kultur`, which it does not recognise either and re-wraps as `{Kunst & Kultur}`.
- **Priority 20 + brace tolerance** — Bricks passes our tag through wrapped, we unwrap, resolve, and return a value Bricks will not see again. This is the only combination that is correct by construction rather than by accident.

The bare form stays accepted, so the contract holds whether or not a preceding callback re-wrapped the tag. Only the outermost pair is stripped, matching what Bricks itself does.

Testing this needs an integration-shaped case: the `add_filter` stub records registrations, it does not execute filters, so the sequence is composed by hand from a local helper that models Bricks' priority-10 behaviour (`fn($tag) => '{' . $tag . '}'`) followed by our callback. A bare-tag test cannot see this defect — that is precisely how it survived eleven review passes.

Matching is exact against the nine keys. A suffixed variant such as `sfx_menu_item_title:something` — Bricks' tag-filter syntax — is **not** supported and falls through rule 2, returning unchanged. Half-supporting filter syntax by ignoring the suffix would silently drop what the editor asked for; leaving the tag visible says so.

`is_string()` guards the front — not because Bricks' own picker reaches us with an array. `Providers::render_tag()` normalises that shape away before the filter ever fires: `$tag = ! empty( $tag['name'] ) ? $tag['name'] : (string) $tag;` (`providers.php:647`), several lines before `apply_filters( 'bricks/dynamic_data/render_tag', $tag, $post, $context )` (`providers.php:671`). Within Bricks, the filter never receives an array. The guard is for a caller outside that path — anyone invoking `apply_filters('bricks/dynamic_data/render_tag', …)` directly, which is under no obligation to pre-normalise the way Bricks does.

#### Follow-up note — registering a real provider (a spike, not a decision)

Not an ADR, and not settled. Recording it so the option is not rediscovered from scratch.

Everything above exists because our tags are never in `Providers::$tags`. There is a hook that could put them there:

```php
apply_filters( 'bricks/dynamic_data/register_providers', [ 'cmb2', 'wp', 'woo', … ] );   // init.php:151
```

Two reasons not to call that the "real fix" yet:

- The Bricks source itself marks it **undocumented** — `// NOTE: bricks/dynamic_data/register_providers Undocumented (@since 1.6.2)` (`init.php:149`). An undocumented hook carries no compatibility promise across Bricks releases.
- It takes provider *slugs*, not objects, and `register_providers()` composes each slug into a class name inside Bricks' own namespace: `'Bricks\Integrations\Dynamic_Data\Providers\Provider_' . …` (`providers.php:187`). Registering through it means declaring a class in the parent theme's namespace and matching an internal naming convention and provider interface. That is a much larger coupling surface than two documented filters.

If anyone picks this up, it is a **spike**, and the question it has to answer is empirical: does a provider registered this way behave correctly on the frontend, in the builder, over AJAX (`ajax.php:3383`) and through the REST/API path (`api.php:1892`), across a Bricks upgrade? Prove that across all four contexts first; an ADR is only worth writing once there is evidence.

Until then the two-filter approach stands on documented, filter-only coupling, which is the trade the rest of this design makes everywhere else.

#### `render_content`

`render_content` guards with `strpos($content, '{sfx_menu_item_') === false` and a `value($post, 'id') === null` check before doing any work, then substitutes with `strtr()`.

Escaping in the content path: `esc_html()` for `title`, `description`, `classes`, `target`, `rel`; `esc_url()` for `url`; raw for `id`, `is_active`, `is_ancestor` (all integer-ish and generated, never user input). `render_tag` returns raw values — the consuming control escapes for its own context, and escaping a URL twice there would corrupt it.

### Builder discoverability

```php
add_filter('bricks/dynamic_tags_list', [MenuItemTags::class, 'add_tags_to_builder']);
```

Each entry: `['name' => '{sfx_menu_item_title}', 'label' => __('Title', 'sfxtheme'), 'group' => __('Menu item', 'sfxtheme')]`.

The snippet omits this, so its tags exist but appear nowhere — an editor has to have been told they exist and type them correctly. Registering them puts all nine in the tag picker under one group. This is presentation only; resolution still comes from the two filters above.

## Error handling

Every failure yields an empty loop, never a notice or a fatal:

| Condition | Result |
|---|---|
| No location and no menu ID | `[]` |
| Location selected but assigned to no menu | `[]` — the stored menu ID is *not* used as a fallback |
| Cyclic `menu_item_parent` data | path walk terminates via the visited guard |
| Malformed AJAX parameter (nested array) | treated as empty, endpoint still answers |
| Menu deleted (`wp_get_nav_menu_items` false) | `[]` |
| Parent ID not in this menu | `[]` (nothing matches the filter) |
| `current` with no enclosing loop | `[]` |
| `current` with a non-`nav_menu_item` enclosing object | `[]` |
| Tag used outside the loop | tag resolves to `null` → left as-is |
| Bad nonce / insufficient caps on AJAX | `wp_send_json_error`, select stays empty |

An empty loop in Bricks renders nothing, which is the correct outcome for a navigation block whose source has gone away.

## Testing

Following `tests/social-bricks-dynamic-data-test.php`: a standalone PHP script with hand-written stubs, run as `php tests/nav-menu-query-test.php`, asserting via the existing `assert_same` / `assert_contains` helpers. No PHPUnit — the theme has none.

`tests/support/nav-menu-query-stubs.php` stubs `wp_get_nav_menu_items`, `wp_get_nav_menus`, `get_registered_nav_menus`, `get_nav_menu_locations`, `wp_setup_nav_menu_item`, `_wp_menu_item_classes_by_context`, `esc_html`, `esc_url`, `esc_html__`, `__`, `sanitize_text_field`, `wp_unslash`, `check_ajax_referer`, `current_user_can`, `wp_send_json_success`, `wp_send_json_error`, and a minimal `Bricks\Query` double with settable `is_any_looping` / `get_loop_object` / `is_looping` returns.

`add_filter` is stubbed as a per-hook registration counter for case 1d, and `\Bricks\Elements::$elements` is set to a three-element fixture. `wp_send_json_*` throw a marker exception rather than exiting, so a test can assert which branch the AJAX handler took. `_wp_menu_item_classes_by_context` records the argument it received (for case 5) and sets `->current` / `->current_item_ancestor` from the fixture.

Fixture: a three-level menu where a title repeats across levels (to prove path labels disambiguate) and one title contains `&amp;` (to prove decoding).

Cases:

0. `add_query_type` — `sfx_nav_menu` added; the five built-in types and a foreign key all survive unaltered.
1. `resolve_menu_id` — all five rows of the precedence table above, the unassigned-location row (`'primary'` unassigned + `$menu_id = 7` → `0`) included as its own assertion.
1b. `add_element_controls` — no `hasLoop` → array returned unchanged (identity); `hasLoop` present without a group → three controls added, none carrying `group`; `hasLoop` present with `query.group = 'addresses'` → all three carry `'addresses'`.
1d. `register_element_controls` once-guard — with a three-element registry fixture and an `add_filter` stub that counts registrations per hook name, calling it **twice** yields exactly three registrations, one per element. Calling it once yields the same three (so the guard cannot pass by suppressing everything).
1c. `parent_options` path traversal terminates on a cyclic `menu_item_parent` fixture instead of hanging.
2. `parent_options` — relative entry first; all items present including leaves; `(0)` on a leaf; path label for a repeated name; entities decoded; empty menu → relative entry only.
3. `run` — unrelated `object_type` returns the given `$results` unchanged, asserted with a non-empty sentinel array so a `[]` return fails; top level (empty parent); explicit parent ID; parent from another menu → empty; deleted menu → empty.
4. `run` with `current` — resolves to the enclosing loop's item; no enclosing loop → empty; enclosing object of the wrong post type → empty.
5. `_wp_menu_item_classes_by_context` receives the **unfiltered** item set (assert the stub's captured argument count equals the full menu, not the filtered subset).
6. `value()` — all nine keys; unknown key → `null`; non-menu-item post with no loop → `null`; a decorated loop object beats an undecorated `$post` of the same ID for `classes` / `is_active` / `is_ancestor`; `$post` used when there is no loop item.
7. `render_content` — substitutes all nine; `esc_html` applied to `title`; `esc_url` to `url`; content without the prefix returned unchanged (identity, not just equal); content outside a loop returned unchanged.
7b. `render_tag` — the three contract rules, each asserted by identity where the contract says "unchanged". The inputs are **brace-wrapped**, because that is the shape Bricks' priority-10 handler delivers; bare inputs get their own cases so both forms stay covered:
   - unrelated tag (`{post_title}`) → the exact incoming `$tag`;
   - owned tag outside a menu-item loop → the exact incoming `$tag`, **not** `''`, and **not** unwrapped;
   - owned tag inside the loop → the resolved value, **raw**: assert `{sfx_menu_item_url}` returns a URL with no `esc_url()` applied and `{sfx_menu_item_title}` returns a title still containing `&`, proving the escaping asymmetry with `render_content`;
   - unknown key under the owned prefix (`{sfx_menu_item_bogus}`) → unchanged;
   - suffixed variant (`{sfx_menu_item_title:foo}`) → unchanged;
   - doubly-wrapped (`{{sfx_menu_item_title}}`) → unchanged, since only the outermost pair is stripped;
   - non-string `$tag` (array from the picker) → returned as-is without a type error;
   - the **loop-recovery** path through the filter: a non-menu-item `$post` with a loop running still resolves, and still does not claim foreign tags.
7c. `render_tag` **in sequence**, not in isolation — the case a bare-tag test cannot express. Compose a local helper modelling Bricks' priority-10 behaviour for an unknown tag (`'{' . $tag . '}'`) with our callback, then assert: an owned tag resolves; an unrelated tag comes back exactly as Bricks left it; an owned-but-unresolvable tag comes back brace-wrapped, not stripped and not `''`. Verify by deliberate break — remove only the brace tolerance, keep priority 20, and confirm this fails.
7d. Hook registration is asserted on shape, not just count: the `add_filter` stub records `callback` / `priority` / `accepted_args` per hook, so `render_tag` at priority 20 and `bricks/query/run` with `accepted_args` 2 are both locked. A per-hook counter cannot see either, which is how the priority defect survived review.
8. `ajax_parent_options` — bad nonce → error; good nonce but no `edit_posts` → error; array-wrapped `{{control}}` value unwrapped correctly; nested array → treated as empty, not `"Array"`; empty array → treated as empty, not a fatal; one malformed parameter leaves the other intact; and the unslash-before-sanitize ordering locked by a fixture **verified to fail under the swapped composition**.

   That last one needs care. Under these stubs (`sanitize_text_field` = `trim(strip_tags(…))`) the two orders **commute for most realistic input** — a plausible `o\'brien` fixture yields the same string either way and so cannot fail. A discriminating fixture is necessarily synthetic: a literal backslash-space-`x` normalises to `x` in the correct order and to `' x'` in the swapped one. Any replacement fixture must be re-verified the same way — run the suite, swap the composition, confirm the case fails, revert — before it is trusted to lock anything.

Manual verification in the builder, since no test can cover it:

- The query type appears in the Query → Type dropdown, labelled "Menüpunkte" in a German backend.
- The three controls appear on a **Block** and hide when another query type is selected — this is what validates the dotted `required` path `query.objectType`.
- On a **Map**, the three controls appear in the *same* `addresses` group as Map's own query UI, not in a separate panel. This is the case the group-copying code exists for and the one a unit test cannot see.
- On a **Slider** and an **Accordion**, they appear ungrouped alongside the query control.
- The parent select populates over AJAX and repopulates when the menu or location selection changes.
- A two-level menu renders from one outer plus one inner loop using the relative parent.
- The active item carries `current-menu-item`, and `{sfx_menu_item_is_active}` drives a Bricks condition.
- Toggling the feature off removes the query type from the dropdown.

## Out of scope

- A menu *element* — this is a query type; Bricks elements do the rendering.
- Depth beyond one level per loop. Nesting is expressed by nesting loops, which is how Bricks models hierarchy everywhere else.
- Caching beyond the per-request static. `wp_get_nav_menu_items()` is already cached by WP.
- Back-compat aliases for `navMenu` / `{menu_item_*}`.
- ACF or other third-party fields on menu items.

## Migration from the snippet

Not automated. On a site running the snippet:

1. Enable the feature, remove the snippet.
2. In each affected template, re-select the query type (`Menüpunkte` now stores `sfx_nav_menu`) and re-pick the menu — preferably by location.
3. Rewrite `{menu_item_*}` to `{sfx_menu_item_*}`.
4. Replace any parent field holding `{menu_item_id}` with the *Children of the current item* entry.

Step 4 is the only behavioural change; the rest is renaming.
