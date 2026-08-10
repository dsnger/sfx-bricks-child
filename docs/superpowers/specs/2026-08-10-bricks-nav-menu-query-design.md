# Bricks Nav Menu Query — Design

**Date:** 2026-08-10
**Branch (planned):** `feat/bricks-nav-menu-query`

**Scope:**

- new `inc/NavMenuQuery/*` (4 files)
- one field in `inc/GeneralThemeOptions/Settings.php`
- one entry in `inc/ThemeSettingsOverview/OverviewProvider.php`
- one test plus one stub file in `tests/`
- delete `query/example.php` (dead scaffold, loaded by nothing)

No new options are stored, so `uninstall.php` is untouched — the toggle lives inside the existing `sfx_general_options` array.

## Goal

Give Bricks a query loop over **WordPress menu items**, so a menu built in `Appearance → Menus` can drive Bricks markup directly. Bricks 2.3.9 ships `post`, `term`, `user`, `api` and `array` query types (`bricks/includes/setup.php:1125`) — none of them iterate `nav_menu_item` in a usable way.

The point is a single source of truth. Today mega-menu panels are commonly built from the page hierarchy (`post_parent`) while the mobile drilldown hangs off the WP menu — two places to maintain, which drift apart. With this query type both come from the menu, and an editor changes navigation without touching a Bricks template.

This generalises a site-specific snippet (visitessen, query type `navMenu`) into a theme feature. The snippet is the behavioural reference; nothing site-specific carries over.

**Non-goals:** rendering a menu (Bricks elements do that), a menu *element*, a walker replacement, page-hierarchy navigation, caching beyond a per-request static.

## Background — the theme's feature contract

`SFXBricksChildTheme::auto_register_features()` (`inc/SFXBricksChildTheme.php:323`) globs `inc/*/Controller.php` and registers every class exposing a static `get_feature_config()`. `load_dependencies()` (line 188) then instantiates each controller **only** if the config's `activation_option_key` is truthy inside `activation_option_name`.

`inc/SmoothScroll/` is the closest analogue: no post type, no persistence of its own, opt-in by default. It is followed here, minus its admin page — `SFXBricksChildAdmin` skips any feature whose config omits `menu_slug`/`page_title` (`inc/SFXBricksChildAdmin.php:45`), so a toggle-only feature is a supported shape, not a workaround.

`functions.php:130` scans feature directories for `PostType.php` to auto-discover CPTs. This feature has none, so it stays invisible to that scan — correct, and nothing to do.

## Background — verified Bricks facts

Every claim below was read out of Bricks 2.3.9 on disk. They are the load-bearing assumptions; if a Bricks update breaks one, this feature breaks.

| Fact | Location |
|---|---|
| `bricks/setup/control_options` carries `queryTypes` | `includes/setup.php:1125` |
| `bricks/query/run` — `apply_filters( 'bricks/query/run', [], $this )` | `includes/query.php:916` |
| `bricks/query/loop_object_type` exists, 3 args | `includes/query.php:2139` |
| `Query::$settings` **is** the element's settings array | `includes/query.php:121` |
| `objectType` is stripped from settings into `Query::$object_type` | `includes/query.php:116-119` |
| Query loop is offered on `section`, `container`, `block`, `div` | `includes/elements/container.php:88-93` |
| …and on `slider`, `accordion`, `map` | those files' `get_loop_builder_controls()` calls |
| `bricks/elements/{name}/controls` fires **after** `set_controls()` | `includes/elements/base.php:143-149` |
| `Elements::$elements` is a public static registry keyed by element name | `includes/elements.php:7` |
| `Query::is_any_looping()` returns the enclosing query ID | `includes/query.php:2433` |
| Bricks itself uses that to resolve dynamic data in a *nested, not-yet-running* query | `includes/integrations/dynamic-data/providers.php:358-366` |
| `bricks/dynamic_tags_list` is **builder-picker only** | `includes/integrations/dynamic-data/providers.php:797-801` ("allows the dynamic data providers to add their tags to the builder") |
| The content parser matches against `Providers::$tags`, built only from registered provider objects | `providers.php:222`, `providers.php:327` |
| `bricks/dynamic_data/render_content` fires regardless of that match | `providers.php:368` |
| `bricks/dynamic_data/render_tag` for single values | `providers.php:671` |
| `optionsAjax` is a real select feature | `includes/elements/wordpress.php:90`, `form.php:2122` |
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
inc/NavMenuQuery/MenuOptions.php   what the builder selects show; AJAX endpoint
inc/NavMenuQuery/QueryType.php     query type, element controls, run, loop object type
inc/NavMenuQuery/MenuItemTags.php  tag registration and value resolution
```

`MenuOptions` is builder-side only (admin/AJAX); `QueryType` and `MenuItemTags` are render-side. That is the seam — it keeps the AJAX/label logic, the part with the most branching and the easiest to unit-test, out of the render path.

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

## Builder controls

Registered on **every element that supports a query loop**, derived rather than listed:

```php
add_action('init', function () {
    foreach (array_keys(\Bricks\Elements::$elements) as $name) {
        add_filter("bricks/elements/{$name}/controls", [QueryType::class, 'add_element_controls']);
    }
}, 20);
```

and inside the callback:

```php
if (!isset($controls['hasLoop'])) {
    return $controls;
}
```

This works because `bricks/elements/{name}/controls` fires after `set_controls()` (base.php:149), so `hasLoop` is already present for loop-capable elements. It covers all seven Bricks elements plus any third-party element that opts into the loop builder — and it is less code than maintaining a list. The snippet hardcodes `container`, `block`, `div`, missing `section`, `slider`, `accordion` and `map`.

`container.php:89` gates the loop controls on `bricks_is_builder()`, so `hasLoop` is absent on the frontend. That is fine: controls only need to exist where they are edited. The frontend reads the stored values straight off `Query::$settings`.

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

1. `$location` non-empty → `get_nav_menu_locations()[$location]` if set.
2. otherwise `(int) $menu_id`.
3. otherwise `0`.

Returning `0` means "no menu", and every caller treats that as an empty result. Location wins when both are set: it is the portable choice, and silently preferring the stale ID would be the surprising behaviour.

Location is primary because a stored menu term ID is install-specific — it breaks when a template moves between sites, and when a menu is deleted and recreated. The ID select stays for menus not assigned to any location.

### The parent select

`MenuOptions::parent_options(int $menu_id): array` returns, in order:

1. `'current' => __('↑ Children of the current item', 'sfxtheme')` — the relative entry.
2. every menu item, keyed by ID, labelled with its full path and direct-child count:
   `Sehen & Erleben › Sehenswürdigkeiten (6)`, `Kontakt (0)`.

Paths are built by walking `menu_item_parent` upward, because names repeat between levels ("Veranstaltungen" can exist on level 1 and level 2) and a bare title would be ambiguous. Titles pass through `html_entity_decode( $text, ENT_QUOTES, 'UTF-8' )` — WordPress stores them escaped, so a raw label reads `Sehen &amp; Erleben`.

**All** items are listed, including leaves. The snippet lists only items that have children — an assumption that fits mega-menu panels and misleads everywhere else: an editor looking for "Kontakt" and not finding it concludes the feature is broken, and the list changes shape as the menu is edited. `(0)` states plainly why a loop will be empty. The select is `searchable`, so length is not a problem.

Empty menu, or `$menu_id === 0` → the relative entry only.

### AJAX endpoint

```php
add_action('wp_ajax_sfx_nav_menu_parent_options', [MenuOptions::class, 'ajax_parent_options']);
```

In order:

1. `check_ajax_referer('bricks-nonce-builder', 'nonce', false)` → `wp_send_json_error('Invalid nonce')`.
2. `current_user_can('edit_posts')` → `wp_send_json_error('Insufficient permissions')`.
3. Read `$_GET['locationId']` and `$_GET['menuId']`; Bricks sends `{{control}}` values as arrays for some control types, so `is_array($v) && $v = reset($v)` for both; then `sanitize_text_field()`.
4. `wp_send_json_success(MenuOptions::parent_options(MenuOptions::resolve_menu_id($location, $menu_id)))`.

The capability check is an addition to the snippet, which verifies the nonce alone. Menu structure is not a secret, but an endpoint that enumerates site structure to any authenticated user is a needless disclosure, and the check is one line.

No `wp_ajax_nopriv_` handler is registered — the builder is never available to logged-out users.

## Query execution

```php
add_filter('bricks/query/run', [QueryType::class, 'run'], 10, 2);
```

Early return unless `$query->object_type === 'sfx_nav_menu'`. Then:

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

`is_any_looping()` returns the ID of the innermost query that is *currently looping*. While an inner query is being built, that is the enclosing loop — this is precisely how Bricks resolves dynamic data for nested queries (`providers.php:358-366`), so the mechanism is Bricks' own, not a trick.

`current` with no enclosing loop returns empty rather than falling back to the top level. A silent fallback would render a plausible-looking wrong menu; empty is diagnosable.

Because `current` exists, the snippet's `bricks_render_dynamic_data()` branch is **removed**. Its only purpose was to make nesting expressible before there was a relative option, and it required the editor to know a tag by heart to type into a select. Dropping it leaves one code path and a parent value that is always one of three known shapes.

### Loop object type

```php
add_filter('bricks/query/loop_object_type', function ($object_type, $object, $query_id) {
    return \Bricks\Query::get_query_object_type($query_id) === 'sfx_nav_menu' ? 'post' : $object_type;
}, 10, 3);
```

Menu items *are* posts (`nav_menu_item`), so declaring `post` makes Bricks put the right `$post` into the dynamic-data context — which is what makes `{post_title}`-style tags and the tags below resolve inside the loop.

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

Returns `$post` when it is a `nav_menu_item`; otherwise falls back to `\Bricks\Query::get_loop_object()` when `\Bricks\Query::is_looping()`. The fallback is required: on the link path Bricks calls `bricks_render_dynamic_data()` with its own post ID (`includes/elements/base.php:2524`), so `$post` is not the menu item and the tag would survive verbatim into the `href`.

```php
MenuItemTags::value(?WP_Post $post, string $key): ?string
```

Resolves the item, then on first use per item runs `wp_setup_nav_menu_item( clone $post )` and caches all nine values in a static array keyed by item ID. `wp_setup_nav_menu_item()` is what turns `_menu_item_object_id` / `_menu_item_url` into a real `->url` and sets `->title` to the navigation label rather than the page title. The `clone` keeps the loop's `$post` unmutated.

The active-state properties are set by step 3 of the query run and survive on the object, so `value()` reads them off the resolved item.

Returns `null` for an unknown key or when no menu item can be resolved. Callers distinguish `null` (not ours — leave alone) from `''` (ours, empty).

### Why two resolution filters

Not redundancy. Verified in the Bricks source:

- **`bricks/dynamic_data/render_tag`** (`providers.php:671`) — single-value contexts: a Link's URL, an image source, a condition's operand.
- **`bricks/dynamic_data/render_content`** (`providers.php:368`) — text content. The content parser matches found tags against `Providers::$tags`, which is assembled purely from registered *provider objects* (`providers.php:222`). `bricks/dynamic_tags_list` does not feed it — the source comments it as builder-picker only (line 797). So our tags are never in `$registered_tags` and the parser will not resolve them; the `render_content` filter fires regardless (line 368) and does the substitution itself.

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
| Location assigned to no menu | `[]` |
| Menu deleted (`wp_get_nav_menu_items` false) | `[]` |
| Parent ID not in this menu | `[]` (nothing matches the filter) |
| `current` with no enclosing loop | `[]` |
| `current` with a non-`nav_menu_item` enclosing object | `[]` |
| Tag used outside the loop | tag resolves to `null` → left as-is |
| Bad nonce / insufficient caps on AJAX | `wp_send_json_error`, select stays empty |

An empty loop in Bricks renders nothing, which is the correct outcome for a navigation block whose source has gone away.

## Testing

Following `tests/social-bricks-dynamic-data-test.php`: a standalone PHP script with hand-written stubs, run as `php tests/nav-menu-query-test.php`, asserting via the existing `assert_same` / `assert_contains` helpers. No PHPUnit — the theme has none.

`tests/support/nav-menu-query-stubs.php` stubs `wp_get_nav_menu_items`, `wp_get_nav_menus`, `get_registered_nav_menus`, `get_nav_menu_locations`, `wp_setup_nav_menu_item`, `_wp_menu_item_classes_by_context`, `esc_html`, `esc_url`, `__`, and a minimal `Bricks\Query` double with settable `is_any_looping` / `get_loop_object` / `is_looping` returns.

Fixture: a three-level menu where a title repeats across levels (to prove path labels disambiguate) and one title contains `&amp;` (to prove decoding).

Cases:

1. `resolve_menu_id` — location set; location unassigned; ID fallback; location beats ID; neither → `0`.
2. `parent_options` — relative entry first; all items present including leaves; `(0)` on a leaf; path label for a repeated name; entities decoded; empty menu → relative entry only.
3. `run` — top level (empty parent); explicit parent ID; parent from another menu → empty; deleted menu → empty.
4. `run` with `current` — resolves to the enclosing loop's item; no enclosing loop → empty; enclosing object of the wrong post type → empty.
5. `_wp_menu_item_classes_by_context` receives the **unfiltered** item set (assert the stub's captured argument count equals the full menu, not the filtered subset).
6. `value()` — all nine keys; unknown key → `null`; non-menu-item post with no loop → `null`; loop-context fallback returns the item.
7. `render_content` — substitutes all nine; `esc_html` applied to `title`; `esc_url` to `url`; content without the prefix returned unchanged (identity, not just equal); content outside a loop returned unchanged.
8. `ajax_parent_options` — bad nonce → error; good nonce but no `edit_posts` → error; array-wrapped `{{control}}` value unwrapped correctly.

Manual verification in the builder, since no test can cover it: the query type appears in the dropdown; the three controls appear on a Block and on a Slider and hide when another query type is selected (this is what validates the `query.objectType` dotted `required` path); the parent select populates over AJAX and repopulates when the menu selection changes; a two-level menu renders from one outer plus one inner loop with the relative parent; the active item carries `current-menu-item`; toggling the feature off removes the query type.

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
