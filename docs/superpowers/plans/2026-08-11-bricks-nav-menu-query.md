# Bricks Nav Menu Query Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an opt-in child-theme feature giving Bricks Builder a query loop over WordPress menu items, so a menu in `Appearance → Menus` can drive Bricks markup directly.

**Architecture:** A feature directory `inc/NavMenuQuery/` auto-discovered by the theme's existing feature registry, gated behind a `default => 0` toggle in General Theme Options. Three static classes split by subject matter — `MenuOptions` (which menu, what the selects offer), `QueryType` (the query type, its controls, running it), `MenuItemTags` (the tag vocabulary and its values) — plus a `Controller` that only wires hooks.

**Tech Stack:** PHP 8.0+, WordPress, Bricks Builder 2.3.9 (parent theme), Composer PSR-4 autoloading (`SFX\` → `inc/`), plain-PHP test scripts with hand-written stubs (no PHPUnit).

**Spec:** `docs/superpowers/specs/2026-08-10-bricks-nav-menu-query-design.md` — read it before starting. This plan implements it; where the plan is silent, the spec governs.

## Global Constraints

- **PHP `>=8.0`** (`composer.json:12`). `match`, constructor promotion, `?->` are available. Do not use PHP 8.1+ features (`readonly`, enums, `never`).
- **Every file under `inc/NavMenuQuery/`** starts with `<?php`, a blank line, `declare(strict_types=1);`, a blank line, then `namespace SFX\NavMenuQuery;`. Test files and stubs do not — they are global-namespace, except the `Bricks` doubles.
- **Text domain is `sfxtheme`** for every user-facing string, without exception.
- **Namespaced identifiers only** — query type `sfx_nav_menu`, controls `sfxNavMenu*`, tags `{sfx_menu_item_*}`, AJAX action `sfx_nav_menu_parent_options`, option key `enable_nav_menu_query`. No `navMenu`, no `{menu_item_*}`, no back-compat aliases.
- **Nothing site-specific.** No hardcoded menu names, IDs, locations, or German strings in PHP. German lives only in `languages/de_DE.po`.
- **All failures yield an empty loop.** Never a notice, never a fatal, never a fallback that renders plausible-but-wrong content.
- **Tests are run with `php tests/nav-menu-query-test.php`** from the theme root. Exit code 0 and a final `PASS:` line means green.
- **Commit after every task.** Conventional-commit prefixes (`feat:`, `test:`, `docs:`, `chore:`).
- **Branch:** `feat/bricks-nav-menu-query`, cut from `main`.

## File Structure

| File | Responsibility |
|---|---|
| `inc/NavMenuQuery/Controller.php` | Bootstrap. Calls each class's `register()`. Exposes `get_feature_config()`. No logic. |
| `inc/NavMenuQuery/MenuOptions.php` | Menu resolution (shared by builder and render), select option lists, path labels, AJAX endpoint. |
| `inc/NavMenuQuery/QueryType.php` | Query type registration, element controls, query execution, parent resolution. |
| `inc/NavMenuQuery/MenuItemTags.php` | Tag vocabulary, value resolution with per-request cache, both render filters, builder tag list. |
| `inc/GeneralThemeOptions/Settings.php` | +1 toggle field (modify). |
| `inc/ThemeSettingsOverview/OverviewProvider.php` | +1 status row (modify). |
| `README.md` | +1 module name in the sentence at line 5 (modify). |
| `languages/de_DE.po` / `.mo` | German for every new string (modify + recompile). |
| `tests/nav-menu-query-test.php` | The suite. Grows one case group per task. |
| `tests/support/nav-menu-query-stubs.php` | Global-namespace stubs, fixture state, assertion helpers. |
| `tests/support/nav-menu-query-bricks-stubs.php` | `Bricks\Elements` and `Bricks\Query` doubles. Separate file because PHP forbids a bracketed namespace block alongside non-namespaced code — same reason `tests/support/sfx-namespaced-stubs.php` exists. |
| `query/example.php` | **Deleted.** Dead scaffold (`'Broke Example Query'`), loaded by nothing. |

---

### Task 1: Feature skeleton and activation toggle

The feature must be discoverable, off by default, and visible in the settings UI before any behaviour exists. Nothing else can be tested until the module loads.

**Files:**
- Create: `inc/NavMenuQuery/Controller.php`
- Modify: `inc/GeneralThemeOptions/Settings.php` (add one entry to `get_fields()`)
- Modify: `inc/ThemeSettingsOverview/OverviewProvider.php` (add one entry to `build_builtin_modules_group()`)
- Modify: `tests/support/overview-general-theme-options-settings-stub.php` (add the field so the stub matches the real settings)
- Modify: `tests/theme-settings-overview-provider-test.php` (assert the new module's default and enabled states)
- Modify: `README.md:5`
- Delete: `query/example.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `SFX\NavMenuQuery\Controller::get_feature_config(): array`. The class declares **no constructor** in this task — Task 10 adds one. `new Controller()` works either way, so the feature is loadable now and does nothing, which is exactly right until its collaborators exist.

**Context you need:** `SFXBricksChildTheme::auto_register_features()` (`inc/SFXBricksChildTheme.php:323`) globs `inc/*/Controller.php` and calls the static `get_feature_config()` on each. `load_dependencies()` (line 188) then does `new $feature['class']()` — but only when `is_option_enabled($config['activation_option_name'], $config['activation_option_key'])` is true. Copy the config shape from `inc/SmoothScroll/Controller.php:53-66`, minus `menu_slug` / `page_title` / `description`: `SFXBricksChildAdmin` skips features without those (`inc/SFXBricksChildAdmin.php:45`), which is how a toggle-only feature avoids getting an empty settings page.

- [ ] **Step 1: Create the controller**

`inc/NavMenuQuery/Controller.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\NavMenuQuery;

/**
 * Bricks query loop over WordPress menu items.
 *
 * Opt-in: only constructed when `enable_nav_menu_query` is on in
 * `sfx_general_options`. Registers hooks and holds no logic of its own.
 */
class Controller
{
    /**
     * @return array<string, mixed>
     */
    public static function get_feature_config(): array
    {
        return [
            'class'                  => self::class,
            'activation_option_name' => 'sfx_general_options',
            'activation_option_key'  => 'enable_nav_menu_query',
            'option_value'           => true,
            'hook'                   => null,
            'error'                  => 'Missing NavMenuQuery Controller class in theme',
        ];
    }
}
```

No `menu_slug`, `page_title` or `description` keys — their absence is what keeps this feature off the settings-overview card list at `SFXBricksChildAdmin.php:45`.

- [ ] **Step 2: Add the activation toggle**

In `inc/GeneralThemeOptions/Settings.php`, inside the array returned by `get_fields()`, directly after the `enable_smooth_scroll` entry:

```php
            [
                'id'          => 'enable_nav_menu_query',
                'label'       => __('Enable Menu Items query type', 'sfxtheme'),
                'description' => __('Adds a "Menu Items" query type to the Bricks query loop, so a WordPress menu can drive Bricks markup.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'general',
            ],
```

`'default' => 0` is the whole of "not enabled by default" — matching `enable_smooth_scroll` and `enable_password_protected`.

- [ ] **Step 3: Add the overview row**

In `inc/ThemeSettingsOverview/OverviewProvider.php`, in the `$modules` array inside `build_builtin_modules_group()`, after the `enable_smooth_scroll` entry:

```php
            'enable_nav_menu_query' => [
                'label' => __('Menu Items query type', 'sfxtheme'),
            ],
```

- [ ] **Step 4: Update the README module sentence**

`README.md:5` currently reads:

```
Most features are managed under **Global Theme Settings** in wp-admin. WP Optimizer, Image Optimizer, Security Header, Smooth Scroll, and Password Protection can be enabled or disabled in **General Theme Options**.
```

Replace with:

```
Most features are managed under **Global Theme Settings** in wp-admin. WP Optimizer, Image Optimizer, Security Header, Smooth Scroll, Password Protection, and the Menu Items query type can be enabled or disabled in **General Theme Options**.
```

- [ ] **Step 5: Delete the dead scaffold**

```bash
git rm query/example.php
```

Confirm first that nothing loads it — this must print no results:

```bash
grep -rn "query/example\|bl_setup_query_controls\|bl_maybe_run_new_query" --include='*.php' . | grep -v '^./query/example.php'
```

If that grep prints anything, stop and report it rather than deleting.

- [ ] **Step 6: Write the failing activation assertions**

The overview suite has its own copy of the settings fields, so it must learn about the new toggle or it proves nothing about it. Add to `tests/theme-settings-overview-provider-test.php`, directly after the existing `enable_smooth_scroll` line in the "Fresh install defaults" block (line 105):

```php
assert_status($data, 'enable_nav_menu_query', 'inactive', 'Menu Items query type module default inactive');
```

and add a new block after the existing "WP Optimizer module off" block:

```php
// Menu Items query type, switched on
reset_test_state();
$test_options['sfx_general_options'] = ['enable_nav_menu_query' => 1];
$data = OverviewProvider::get_data();
assert_status($data, 'enable_nav_menu_query', 'active', 'Menu Items query type module active when enabled');
```

- [ ] **Step 7: Run the overview test to verify it fails**

```bash
php tests/theme-settings-overview-provider-test.php
```

Expected: two failures reported and exit 1 — the stub's `get_fields()` does not yet carry `enable_nav_menu_query`, so `get_item_status()` cannot find the row.

- [ ] **Step 8: Teach the stub about the new field**

In `tests/support/overview-general-theme-options-settings-stub.php`, add to the array returned by `get_fields()`, after the `enable_smooth_scroll` line:

```php
            ['id' => 'enable_nav_menu_query', 'default' => 0],
```

The stub mirrors the real `Settings::get_fields()`; `'default' => 0` must match what Step 2 wrote, or the test asserts a default the code does not have.

- [ ] **Step 9: Run the overview test to verify it passes**

```bash
php tests/theme-settings-overview-provider-test.php
```

Expected: exit 0 and `All theme settings overview provider tests passed.` — note this suite does **not** print a `PASS:` line; that convention belongs to the newer tests.

- [ ] **Step 10: Verify PHP syntax**

```bash
php -l inc/NavMenuQuery/Controller.php && php -l inc/GeneralThemeOptions/Settings.php && php -l inc/ThemeSettingsOverview/OverviewProvider.php
```

Expected: `No syntax errors detected` three times.

- [ ] **Step 11: Commit**

```bash
git add inc/NavMenuQuery/Controller.php inc/GeneralThemeOptions/Settings.php inc/ThemeSettingsOverview/OverviewProvider.php README.md tests/theme-settings-overview-provider-test.php tests/support/overview-general-theme-options-settings-stub.php
git rm --cached query/example.php 2>/dev/null; git add -u query/
git commit -m "feat(nav-menu-query): add opt-in feature skeleton and toggle"
```

---

### Task 2: Test harness and menu resolution

The resolver is the feature's shared seam — both the AJAX endpoint and the query runner call it, and a disagreement between them is invisible in the UI. It gets tested first, and building it creates the harness every later task extends.

**Files:**
- Create: `tests/support/nav-menu-query-stubs.php`
- Create: `tests/support/nav-menu-query-bricks-stubs.php`
- Create: `tests/nav-menu-query-test.php`
- Create: `inc/NavMenuQuery/MenuOptions.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `MenuOptions::RELATIVE_PARENT` — the string constant `'current'`.
  - `MenuOptions::resolve_menu_id(string $location, $menu_id): int`
  - `MenuOptions::locations(): array` — `slug => label`
  - `MenuOptions::menus(): array` — `'term_id' => name`
  - Test globals other tasks reuse: `$test_registered_nav_menus`, `$test_nav_menu_locations`, `$test_nav_menus`, `$test_menu_items`, `$test_classes_by_context_calls`, `$test_current_user_can`, `$test_nonce_valid`, `$test_filters`, and the exception class `SfxJsonSent`.

- [ ] **Step 1: Create the global stub file**

`tests/support/nav-menu-query-stubs.php`. This is the complete stub set for every task in the plan — later tasks add fixture *data*, not new stub functions.

```php
<?php

declare(strict_types=1);

/**
 * Stubs for the NavMenuQuery suite.
 *
 * Global-namespace WordPress functions, mutable fixture state, and the
 * assertion helpers. The Bricks doubles live in a sibling file because PHP
 * forbids a bracketed namespace block alongside non-namespaced code.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}

$failures = 0;

// ------------------------------------------------------------- assertions

function assert_true(bool $condition, string $message): void
{
    global $failures;

    if (!$condition) {
        echo "FAIL: {$message}\n";
        $failures++;
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    assert_true(
        $expected === $actual,
        "{$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
    );
}

function assert_contains(string $needle, string $haystack, string $message): void
{
    assert_true(strpos($haystack, $needle) !== false, "{$message} (needle '{$needle}' not found)");
}

// ---------------------------------------------------------- fixture state

$test_registered_nav_menus   = [];   // slug => label
$test_nav_menu_locations     = [];   // slug => term_id
$test_nav_menus              = [];   // list of objects with ->term_id, ->name
$test_menu_items             = [];   // menu_id => list<WP_Post>|false
$test_classes_by_context_calls = []; // item counts captured per call
$test_current_user_can       = true;
$test_nonce_valid            = true;
$test_filters                = [];   // hook name => registration count

// ----------------------------------------------------------------- WP_Post

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = 'nav_menu_item';
        public string $title = '';
        public string $url = '';
        public string $menu_item_parent = '0';
        public string $target = '';
        public string $xfn = '';
        public string $description = '';
        public array $classes = [];
        public bool $current = false;
        public bool $current_item_ancestor = false;

        /** @param array<string, mixed> $props */
        public function __construct(array $props = [])
        {
            foreach ($props as $key => $value) {
                $this->$key = $value;
            }
        }
    }
}

// ----------------------------------------------------------- WP functions

function get_registered_nav_menus(): array
{
    global $test_registered_nav_menus;

    return $test_registered_nav_menus;
}

function get_nav_menu_locations(): array
{
    global $test_nav_menu_locations;

    return $test_nav_menu_locations;
}

function wp_get_nav_menus(): array
{
    global $test_nav_menus;

    return $test_nav_menus;
}

function wp_get_nav_menu_items($menu_id)
{
    global $test_menu_items;

    return $test_menu_items[(int) $menu_id] ?? false;
}

/** Mirrors WP: mutates by reference, and records what it was handed. */
function _wp_menu_item_classes_by_context(&$menu_items): void
{
    global $test_classes_by_context_calls;

    $test_classes_by_context_calls[] = count($menu_items);

    foreach ($menu_items as $item) {
        if ($item->current) {
            $item->classes[] = 'current-menu-item';
        }
        if ($item->current_item_ancestor) {
            $item->classes[] = 'current-menu-ancestor';
        }
    }
}

/** Real WP resolves ->url and ->title here; fixtures already carry both. */
function wp_setup_nav_menu_item($item)
{
    return $item;
}

function __($text, $domain = 'default')
{
    return $text;
}

function esc_html__($text, $domain = 'default')
{
    return (string) $text;
}

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_url($url)
{
    return str_replace(' ', '%20', (string) $url);
}

function sanitize_text_field($str)
{
    return trim(strip_tags((string) $str));
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

function check_ajax_referer($action, $query_arg = false, $stop = true)
{
    global $test_nonce_valid;

    return $test_nonce_valid;
}

function current_user_can($capability)
{
    global $test_current_user_can;

    return $test_current_user_can;
}

/** wp_send_json_* exit in production; here they unwind so a test can assert. */
class SfxJsonSent extends \Exception
{
    public bool $success;
    public mixed $payload;

    public function __construct(bool $success, mixed $payload)
    {
        parent::__construct('json sent');

        $this->success = $success;
        $this->payload = $payload;
    }
}

function wp_send_json_success($payload = null): void
{
    throw new SfxJsonSent(true, $payload);
}

function wp_send_json_error($payload = null): void
{
    throw new SfxJsonSent(false, $payload);
}

/** Counts registrations per hook so the once-guard can be asserted. */
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1): bool
{
    global $test_filters;

    $test_filters[$hook] = ($test_filters[$hook] ?? 0) + 1;

    return true;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1): bool
{
    return add_filter($hook, $callback, $priority, $accepted_args);
}
```

- [ ] **Step 2: Create the Bricks stub file**

`tests/support/nav-menu-query-bricks-stubs.php`:

```php
<?php

declare(strict_types=1);

/**
 * Bricks doubles for the NavMenuQuery suite.
 *
 * Separate file because PHP does not allow a bracketed namespace block
 * alongside non-namespaced code — the same reason sfx-namespaced-stubs.php
 * exists.
 */

namespace Bricks;

class Elements
{
    /** @var array<string, mixed> element name => whatever; only keys are read */
    public static array $elements = [];
}

class Query
{
    /** Return value for is_any_looping(): false, or an enclosing query id. */
    public static mixed $any_looping = false;

    /** Return value for is_looping(). */
    public static bool $looping = false;

    /** query id => loop object. The '' key serves the no-argument call. */
    public static array $loop_objects = [];

    public static function is_any_looping(): mixed
    {
        return self::$any_looping;
    }

    public static function is_looping($element_id = '', $query_id = ''): bool
    {
        return self::$looping;
    }

    public static function get_loop_object($query_id = '')
    {
        return self::$loop_objects[$query_id] ?? (self::$loop_objects[''] ?? null);
    }

    public static function reset(): void
    {
        self::$any_looping  = false;
        self::$looping      = false;
        self::$loop_objects = [];
    }
}
```

- [ ] **Step 3: Write the failing test**

Create `tests/nav-menu-query-test.php`:

```php
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
```

Case 1d is the important one — it is the behaviour a careless implementation gets wrong, and the spec argues for it explicitly.

- [ ] **Step 4: Run the test to verify it fails**

```bash
php tests/nav-menu-query-test.php
```

Expected: a PHP fatal — `Failed to open stream` for `inc/NavMenuQuery/MenuOptions.php`, because the class does not exist yet.

- [ ] **Step 5: Write the implementation**

`inc/NavMenuQuery/MenuOptions.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\NavMenuQuery;

/**
 * Which menu a loop points at, and what the builder's selects offer.
 *
 * Shared by both sides: the builder calls the option lists and the AJAX
 * endpoint, the render path calls resolve_menu_id(). One resolver, so a
 * builder preview and a frontend render cannot disagree about the menu.
 */
class MenuOptions
{
    /** Stored parent value meaning "children of the enclosing loop's item". */
    public const RELATIVE_PARENT = 'current';

    /**
     * Registered theme locations, slug => label.
     *
     * @return array<string, string>
     */
    public static function locations(): array
    {
        $options = [];

        foreach (get_registered_nav_menus() as $slug => $label) {
            $options[(string) $slug] = self::plain_text($label);
        }

        return $options;
    }

    /**
     * Registered menus, term id (as string) => name.
     *
     * @return array<string, string>
     */
    public static function menus(): array
    {
        $options = [];

        foreach (wp_get_nav_menus() as $menu) {
            $options[(string) $menu->term_id] = self::plain_text($menu->name);
        }

        return $options;
    }

    /**
     * Resolve the menu a loop points at.
     *
     * A non-empty location NEVER falls through to $menu_id: selecting a
     * location says "follow whatever is assigned there", so an unassigned
     * location means no menu, not the id the editor happened to pick before.
     *
     * @param mixed $menu_id
     */
    public static function resolve_menu_id(string $location, $menu_id): int
    {
        if ($location !== '') {
            $locations = get_nav_menu_locations();

            return isset($locations[$location]) ? (int) $locations[$location] : 0;
        }

        return (int) $menu_id;
    }

    /** WordPress stores menu and term titles HTML-escaped. */
    private static function plain_text($text): string
    {
        return html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
php tests/nav-menu-query-test.php
```

Expected: `PASS: all nav-menu-query tests`, exit 0.

- [ ] **Step 7: Commit**

```bash
git add tests/nav-menu-query-test.php tests/support/nav-menu-query-stubs.php tests/support/nav-menu-query-bricks-stubs.php inc/NavMenuQuery/MenuOptions.php
git commit -m "feat(nav-menu-query): resolve the target menu from location or id"
```

---

### Task 3: Parent select options

The parent select is the feature's main editor-facing surface. It must list every item (not only those with children), disambiguate repeated names by path, decode entities, and never hang on corrupt data.

**Files:**
- Modify: `inc/NavMenuQuery/MenuOptions.php` (add `parent_options()` and `path_label()`)
- Modify: `tests/nav-menu-query-test.php` (append Case 3 group before the epilogue)

**Interfaces:**
- Consumes: `MenuOptions::RELATIVE_PARENT`, `MenuOptions::plain_text()` (private, same class).
- Produces: `MenuOptions::parent_options(int $menu_id): array` — ordered `array<string, string>`, first entry always `'current' => '↑ Children of the current item'`, then one entry per item keyed by its ID as a string.

**Context you need:** menu items are flat `WP_Post` objects carrying `menu_item_parent` (a string, `'0'` at top level). Labels must read `Sehen & Erleben › Sehenswürdigkeiten (6)` — full path, then direct-child count in parentheses. Leaves get `(0)`, which is how an editor learns why their loop will be empty.

- [ ] **Step 1: Write the failing test**

Append to `tests/nav-menu-query-test.php`, immediately before the `// ------- epilogue` block:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php tests/nav-menu-query-test.php
```

Expected: `PHP Fatal error: Uncaught Error: Call to undefined method SFX\NavMenuQuery\MenuOptions::parent_options()`.

- [ ] **Step 3: Write the implementation**

Add to `inc/NavMenuQuery/MenuOptions.php`, after `resolve_menu_id()`:

```php
    /**
     * Options for the "Items below" select.
     *
     * Every item is listed, leaves included: hiding leaves makes an editor
     * who cannot find "Kontakt" conclude the feature is broken, and makes the
     * list change shape as the menu is edited. The trailing (n) says how many
     * direct children a choice has, so (0) explains an empty loop up front.
     *
     * @return array<string, string>
     */
    public static function parent_options(int $menu_id): array
    {
        $options = [self::RELATIVE_PARENT => __('↑ Children of the current item', 'sfxtheme')];

        $items = $menu_id > 0 ? wp_get_nav_menu_items($menu_id) : [];

        if (!$items) {
            return $options;
        }

        $titles  = [];
        $parents = [];
        $counts  = [];

        foreach ($items as $item) {
            $id           = (int) $item->ID;
            $titles[$id]  = self::plain_text($item->title);
            $parents[$id] = (int) $item->menu_item_parent;
        }

        foreach ($parents as $parent) {
            if ($parent) {
                $counts[$parent] = ($counts[$parent] ?? 0) + 1;
            }
        }

        foreach ($titles as $id => $title) {
            $options[(string) $id] = sprintf(
                '%s (%d)',
                self::path_label($id, $titles, $parents),
                $counts[$id] ?? 0
            );
        }

        return $options;
    }

    /**
     * Full path to an item, e.g. "Sehen & Erleben › Sehenswürdigkeiten".
     *
     * Names repeat between levels, so a bare title is ambiguous. The visited
     * guard exists because corrupt postmeta can form a parent cycle — rare,
     * but without it the walk never terminates and the admin screen times out
     * instead of failing diagnosably.
     *
     * @param array<int, string> $titles
     * @param array<int, int>    $parents
     */
    private static function path_label(int $id, array $titles, array $parents): string
    {
        $path    = [];
        $seen    = [];
        $current = $id;

        while ($current && isset($titles[$current]) && !isset($seen[$current])) {
            $seen[$current] = true;
            array_unshift($path, $titles[$current]);
            $current = (int) ($parents[$current] ?? 0);
        }

        return implode(' › ', $path);
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php tests/nav-menu-query-test.php
```

Expected: `PASS: all nav-menu-query tests`, exit 0. If Case 3k hangs instead of failing, the visited guard is missing — kill it with Ctrl-C and re-check the `!isset($seen[$current])` condition.

- [ ] **Step 5: Commit**

```bash
git add inc/NavMenuQuery/MenuOptions.php tests/nav-menu-query-test.php
git commit -m "feat(nav-menu-query): build parent select options with path labels"
```

---

### Task 4: AJAX endpoint

The parent select is populated over AJAX because its contents depend on another control's value. This is the feature's only externally reachable entry point, so it gets a nonce check, a capability check, and defensive input normalisation.

**Files:**
- Modify: `inc/NavMenuQuery/MenuOptions.php` (add `register()`, `ajax_parent_options()`, `scalar_param()`)
- Modify: `tests/nav-menu-query-test.php` (append Case 4 group)

**Interfaces:**
- Consumes: `MenuOptions::resolve_menu_id()`, `MenuOptions::parent_options()`.
- Produces: `MenuOptions::register(): void` — called by `Controller` in Task 10. `MenuOptions::ajax_parent_options(): void`.

**Context you need:** Bricks' builder nonce is `bricks-nonce-builder` (`bricks/includes/ajax.php:96`). Bricks sends `{{control}}` placeholder values as arrays for some control types, so each parameter must be unwrapped. Three input hazards, all handled by one helper: `reset()` on a *nested* array yields another array (casting that to string emits a notice and yields the literal `"Array"`); `wp_unslash()` must run *before* `sanitize_text_field()`, or a slug containing an escaped character is sanitised while still slashed and then never matches a real key; and each parameter is normalised independently so one malformed value cannot corrupt the other.

- [ ] **Step 1: Write the failing test**

Append to `tests/nav-menu-query-test.php`, before the epilogue:

```php
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

// Case 4k locks wp_unslash() running BEFORE sanitize_text_field().
//
// The fixture is synthetic — WordPress' own slashing never produces a
// backslash-space — and it has to be. With realistically slashed input the two
// orders commute under these stubs (`sanitize_text_field` = trim+strip_tags
// never touches backslashes), so an "o\'brien"-style fixture yields the same
// string either way and cannot fail. Backslash-space-x does diverge: the
// correct order normalises to 'x' and matches the location; the swapped order
// leaves ' x', which matches nothing and resolves to no menu.
//
// Verified: correct order → 7 options, swapped order → 1. Any replacement
// fixture must be re-verified the same way before it is trusted.
$test_registered_nav_menus['x'] = 'Ordering probe';
$test_nav_menu_locations['x']   = 4;

$sent = run_ajax_endpoint(['locationId' => "\\ x", 'menuId' => '']);
assert_same(7, count($sent->payload), 'Case 4k: wp_unslash runs before sanitize_text_field, so the slashed slug still matches');

// Case 4l: an empty array unwraps to false, which is scalar, so it casts to ''.
$sent = run_ajax_endpoint(['locationId' => [], 'menuId' => '']);
assert_same(['current'], array_keys($sent->payload), 'Case 4l: an empty-array value normalises to the empty string, not a crash');

$_GET = [];
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php tests/nav-menu-query-test.php
```

Expected: `PHP Fatal error: Uncaught Error: Call to undefined method SFX\NavMenuQuery\MenuOptions::ajax_parent_options()`.

- [ ] **Step 3: Write the implementation**

Add to `inc/NavMenuQuery/MenuOptions.php`. Put `register()` first in the class body (right after the constant), and the two AJAX methods at the end:

```php
    public static function register(): void
    {
        add_action('wp_ajax_sfx_nav_menu_parent_options', [self::class, 'ajax_parent_options']);
    }
```

```php
    /**
     * Feed the "Items below" select.
     *
     * No wp_ajax_nopriv_ counterpart: the builder is never available to
     * logged-out users. The capability check is not about secrecy — menu
     * structure is not sensitive — but enumerating it to any authenticated
     * user is a needless disclosure, and the check is one line.
     */
    public static function ajax_parent_options(): void
    {
        if (!check_ajax_referer('bricks-nonce-builder', 'nonce', false)) {
            wp_send_json_error(__('Invalid nonce', 'sfxtheme'));
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Insufficient permissions', 'sfxtheme'));
        }

        $location = self::scalar_param('locationId');
        $menu_id  = self::scalar_param('menuId');

        wp_send_json_success(self::parent_options(self::resolve_menu_id($location, $menu_id)));
    }

    /**
     * Read one request parameter safely.
     *
     * Bricks sends {{control}} values as arrays for some control types, so a
     * single unwrap is expected. Anything still non-scalar after that is
     * malformed — casting it would emit a notice and produce the literal
     * "Array". wp_unslash() must precede sanitize_text_field(), or a value
     * containing an escaped character is sanitised while still slashed and
     * never matches a real key.
     */
    private static function scalar_param(string $key): string
    {
        $value = $_GET[$key] ?? '';

        if (is_array($value)) {
            $value = reset($value);
        }

        if (!is_scalar($value)) {
            return '';
        }

        return sanitize_text_field(wp_unslash((string) $value));
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php tests/nav-menu-query-test.php
```

Expected: `PASS: all nav-menu-query tests`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add inc/NavMenuQuery/MenuOptions.php tests/nav-menu-query-test.php
git commit -m "feat(nav-menu-query): add the parent-options AJAX endpoint"
```

---

### Task 5: Query type and element controls

Registering the query type is what makes the feature reachable at all. The element controls must reach every loop-capable element — including the three the reference snippet missed — and must sit in the same control group as the query UI they modify.

**Files:**
- Create: `inc/NavMenuQuery/QueryType.php`
- Modify: `tests/nav-menu-query-test.php` (append Case 5 group and a new `require`)

**Interfaces:**
- Consumes: `MenuOptions::locations()`, `MenuOptions::menus()`.
- Produces:
  - `QueryType::OBJECT_TYPE` — the string constant `'sfx_nav_menu'`.
  - `QueryType::add_query_type(array $control_options): array`
  - `QueryType::register_element_controls(): void`
  - `QueryType::add_element_controls(array $controls): array`

**Context you need:** three verified Bricks facts drive this task.

1. `bricks/elements/{name}/controls` fires *after* `set_controls()` (`base.php:143-149`), so `hasLoop` and `query` are already in the array when the callback runs. Keying off `isset($controls['hasLoop'])` is therefore a reliable test for "this element supports a query loop".
2. `get_loop_builder_controls( $group )` (`base.php:4086-4116`) stamps a `group` onto both `hasLoop` and `query` when the host element passes one. Map passes `'addresses'` (`map.php:250`); Section, Container, Block, Div, Slider and Accordion pass nothing. Copying `query.group` is what keeps the three controls in the same panel as the query UI on Map.
3. `bricks/load_elements/before` fires at the start of *each* `Elements::load_elements()` call (`elements.php:252-254`), the primary one on `wp` (`elements.php:16`). Registering there sees the complete element registry. It can fire twice in a request — `builder-permissions.php:28` calls `load_elements()` again on the Bricks Settings page — hence the once-guard.

- [ ] **Step 1: Write the failing test**

First add the require at the top of `tests/nav-menu-query-test.php`, after the `MenuOptions.php` require:

```php
require dirname(__DIR__) . '/inc/NavMenuQuery/QueryType.php';
```

and the import beside the existing one:

```php
use SFX\NavMenuQuery\QueryType;
```

Then append before the epilogue:

```php
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

// 5n: the once-guard. add_filter is stubbed as a per-hook counter.
Bricks\Elements::$elements = ['section' => [], 'block' => [], 'map' => []];
$test_filters = [];

QueryType::register_element_controls();
QueryType::register_element_controls();

assert_same(1, $test_filters['bricks/elements/section/controls'] ?? 0, 'Case 5n: section registered exactly once across two calls');
assert_same(1, $test_filters['bricks/elements/block/controls'] ?? 0, 'Case 5o: block registered exactly once');
assert_same(1, $test_filters['bricks/elements/map/controls'] ?? 0, 'Case 5p: map registered exactly once');
assert_same(3, array_sum($test_filters), 'Case 5q: three registrations total — the guard suppresses the repeat, not the work');
```

Case 5q is what stops the guard from passing by suppressing everything.

- [ ] **Step 2: Run the test to verify it fails**

```bash
php tests/nav-menu-query-test.php
```

Expected: a fatal — `Failed to open stream` for `inc/NavMenuQuery/QueryType.php`.

- [ ] **Step 3: Write the implementation**

`inc/NavMenuQuery/QueryType.php` — the query-execution half arrives in Task 6, so `register()` is written here without the `bricks/query/run` line and completed there.

```php
<?php

declare(strict_types=1);

namespace SFX\NavMenuQuery;

/**
 * The "Menu Items" query type: its registration, its element controls, and
 * running it.
 */
class QueryType
{
    /** The value stored in a Bricks query's objectType. */
    public const OBJECT_TYPE = 'sfx_nav_menu';

    private const CONTROL_KEYS = ['sfxNavMenuLocation', 'sfxNavMenuId', 'sfxNavMenuParent'];

    public static function register(): void
    {
        add_filter('bricks/setup/control_options', [self::class, 'add_query_type']);
        add_action('bricks/load_elements/before', [self::class, 'register_element_controls']);
    }

    /**
     * Offer the query type in the builder's Query → Type dropdown.
     *
     * Merged into, never replaced: Bricks' own five types and any other
     * plugin's additions have to survive.
     *
     * @param array<string, mixed> $control_options
     * @return array<string, mixed>
     */
    public static function add_query_type(array $control_options): array
    {
        $control_options['queryTypes'][self::OBJECT_TYPE] = esc_html__('Menu Items', 'sfxtheme');

        return $control_options;
    }

    /**
     * Attach the controls filter to every registered element.
     *
     * Runs on bricks/load_elements/before, which fires at the start of each
     * Elements::load_elements() call — so the registry is complete, including
     * elements registered after init. The guard is because that action can
     * fire twice per request (builder-permissions.php:28 loads elements again
     * for the Bricks Settings page); without it every element would get a
     * duplicate filter and build its controls twice over.
     */
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

    /**
     * Add the three controls to any element that supports a query loop.
     *
     * hasLoop is the marker: bricks/elements/{name}/controls fires after
     * set_controls(), so a loop-capable element already carries it. This
     * covers all seven Bricks elements plus any third-party element that
     * opts into the loop builder.
     *
     * @param array<string, mixed> $controls
     * @return array<string, mixed>
     */
    public static function add_element_controls(array $controls): array
    {
        if (!isset($controls['hasLoop'])) {
            return $controls;
        }

        $controls['sfxNavMenuLocation'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Menu location', 'sfxtheme'),
            'type'        => 'select',
            'options'     => MenuOptions::locations(),
            'placeholder' => esc_html__('Select a location', 'sfxtheme'),
            'description' => esc_html__('Follows whichever menu is assigned to this location.', 'sfxtheme'),
            'required'    => ['query.objectType', '=', self::OBJECT_TYPE],
        ];

        $controls['sfxNavMenuId'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Menu', 'sfxtheme'),
            'type'        => 'select',
            'options'     => MenuOptions::menus(),
            'description' => esc_html__('Only used when no location is selected.', 'sfxtheme'),
            'required'    => [
                ['query.objectType', '=', self::OBJECT_TYPE],
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
            'required'    => ['query.objectType', '=', self::OBJECT_TYPE],
        ];

        // Map puts its query UI in the 'addresses' group (map.php:250). Ours
        // has to follow, or it renders in a different panel from the control
        // it modifies. Elements that pass no group leave this null.
        $group = $controls['query']['group'] ?? null;

        if ($group !== null) {
            foreach (self::CONTROL_KEYS as $key) {
                $controls[$key]['group'] = $group;
            }
        }

        return $controls;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php tests/nav-menu-query-test.php
```

Expected: `PASS: all nav-menu-query tests`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add inc/NavMenuQuery/QueryType.php tests/nav-menu-query-test.php
git commit -m "feat(nav-menu-query): register the query type and its element controls"
```

---

### Task 6: Query execution and parent resolution

This is the query itself, including the relative-parent mode that makes a generic two-level menu possible from one outer plus one inner loop.

**Files:**
- Modify: `inc/NavMenuQuery/QueryType.php` (add the `bricks/query/run` line to `register()`, add `run()` and `resolve_parent()`)
- Modify: `tests/nav-menu-query-test.php` (append Case 6 group)

**Interfaces:**
- Consumes: `MenuOptions::resolve_menu_id()`, `MenuOptions::RELATIVE_PARENT`, `\Bricks\Query::is_any_looping()`, `\Bricks\Query::get_loop_object()`.
- Produces: `QueryType::run($results, $query)` — returns `$results` untouched for other query types, otherwise a `list<WP_Post>`.

**Context you need:** three things that are easy to get subtly wrong.

1. **The guard returns `$results` itself**, not `[]`. `bricks/query/run` is a shared filter every query type passes through; a handler that normalises the value it was handed breaks whichever handler runs after it. Bricks seeds the chain with `[]` (`query.php:916`), but nothing entitles this callback to assume that is still what is flowing.
2. **`_wp_menu_item_classes_by_context()` runs on the full, unfiltered set.** It compares each item against every other to work out ancestry; running it after filtering leaves `current_item_ancestor` wrong on exactly the items where it matters. WP core calls it from `wp_nav_menu()`, never from `wp_get_nav_menu_items()`, so this feature must call it itself.
3. **`Query::is_any_looping()` returns the *enclosing* query's id** while an inner query is being built. That is not a trick — it is precisely how Bricks resolves dynamic data for nested queries (`providers.php:358-366`).

`$query->settings` is the element's settings array (`query.php:121`), and `objectType` has already been moved out of it into `$query->object_type` (`query.php:116-119`).

**Do not add a `bricks/query/loop_object_type` filter.** The reference snippet has one forcing `'post'`, and it is a no-op: `Query::get_loop_object_type()` already classifies any `WP_Post` as `post` *before* firing that filter (`query.php:2121-2139`), and these loop objects are `WP_Post`. Adding it would put a hook on every loop iteration of every query on the site to change nothing. This is called out because it looks load-bearing in the snippet and is not.

- [ ] **Step 1: Write the failing test**

Append before the epilogue:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php tests/nav-menu-query-test.php
```

Expected: `PHP Fatal error: Uncaught Error: Call to undefined method SFX\NavMenuQuery\QueryType::run()`.

- [ ] **Step 3: Write the implementation**

In `inc/NavMenuQuery/QueryType.php`, add the run filter to `register()`:

```php
    public static function register(): void
    {
        add_filter('bricks/setup/control_options', [self::class, 'add_query_type']);
        add_action('bricks/load_elements/before', [self::class, 'register_element_controls']);
        add_filter('bricks/query/run', [self::class, 'run'], 10, 2);
    }
```

and append these two methods to the class:

```php
    /**
     * Run the menu-items query.
     *
     * @param mixed  $results
     * @param object $query   Bricks\Query
     * @return mixed list<\WP_Post> for this query type, $results otherwise
     */
    public static function run($results, $query)
    {
        // Hand back exactly what we were given. bricks/query/run is shared by
        // every query type and every plugin; normalising the value here would
        // break whichever handler runs next.
        if ($query->object_type !== self::OBJECT_TYPE) {
            return $results;
        }

        $menu_id = MenuOptions::resolve_menu_id(
            (string) ($query->settings['sfxNavMenuLocation'] ?? ''),
            $query->settings['sfxNavMenuId'] ?? 0
        );

        if ($menu_id <= 0) {
            return [];
        }

        $items = wp_get_nav_menu_items($menu_id);

        if (!$items) {
            return [];
        }

        // On the FULL set: ancestry is computed by comparing every item
        // against every other, so filtering first would leave
        // current_item_ancestor wrong exactly where it matters. WP calls this
        // from wp_nav_menu(), never from wp_get_nav_menu_items().
        _wp_menu_item_classes_by_context($items);

        $parent = self::resolve_parent((string) ($query->settings['sfxNavMenuParent'] ?? ''));

        if ($parent === null) {
            return [];
        }

        return array_values(array_filter(
            $items,
            static fn($item) => (string) $item->menu_item_parent === $parent
        ));
    }

    /**
     * Turn the stored parent value into an id to filter on.
     *
     * The stored value is always one of three shapes: empty (top level), a
     * numeric id, or the literal 'current'.
     *
     * @return string|null the parent id, or null meaning "return nothing"
     */
    private static function resolve_parent(string $stored): ?string
    {
        if ($stored !== MenuOptions::RELATIVE_PARENT) {
            return $stored === '' ? '0' : (string) (int) $stored;
        }

        // While a nested query is being built, is_any_looping() returns the
        // ENCLOSING query's id — the same mechanism Bricks uses to resolve
        // dynamic data in nested queries (providers.php:358-366).
        $enclosing = \Bricks\Query::is_any_looping();

        if (!$enclosing) {
            return null;
        }

        $object = \Bricks\Query::get_loop_object($enclosing);

        if (!$object instanceof \WP_Post || $object->post_type !== 'nav_menu_item') {
            return null;
        }

        return (string) $object->ID;
    }
```

Returning `null` rather than falling back to `'0'` is deliberate: a silent fallback would render a plausible-looking wrong menu, while an empty loop is diagnosable.

- [ ] **Step 4: Run the test to verify it passes**

```bash
php tests/nav-menu-query-test.php
```

Expected: `PASS: all nav-menu-query tests`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add inc/NavMenuQuery/QueryType.php tests/nav-menu-query-test.php
git commit -m "feat(nav-menu-query): run the query and resolve relative parents"
```

---

### Task 7: Menu item value resolution

The tags need one place that turns a loop object into values. Two subtleties: the item often has to be recovered from the loop context rather than the passed `$post`, and the active-state properties live on the *original* item, not on the prepared clone.

**Files:**
- Create: `inc/NavMenuQuery/MenuItemTags.php`
- Modify: `tests/nav-menu-query-test.php` (append Case 7 group and a new `require`)

**Interfaces:**
- Consumes: `\Bricks\Query::is_looping()`, `\Bricks\Query::get_loop_object()`.
- Produces:
  - `MenuItemTags::PREFIX` — the string constant `'sfx_menu_item_'`.
  - `MenuItemTags::KEYS` — the nine key names, in display order.
  - `MenuItemTags::item_from_context($post): ?\WP_Post`
  - `MenuItemTags::value($post, string $key): ?string`
  - `MenuItemTags::reset_cache(): void` — test seam for the per-request static.

**Context you need:** on the link path Bricks calls `bricks_render_dynamic_data()` with its own post id (`base.php:2524`), so `$post` is *not* the menu item and the tag would otherwise survive verbatim into the `href`. Hence the loop-context fallback.

`wp_setup_nav_menu_item()` resolves `_menu_item_object_id` / `_menu_item_url` into a real `->url` and sets `->title` to the navigation label rather than the page title. It is called on a **clone** so the loop's `$post` is not mutated. But `classes`, `current` and `current_item_ancestor` were set on the original item by `_wp_menu_item_classes_by_context()` in Task 6 and must be read from **`$item`**, not from the prepared clone.

`value()` returns `null` for "not resolvable / not mine" and `''` for "mine, empty". Callers depend on that distinction.

- [ ] **Step 1: Write the failing test**

Add the require at the top of the test file:

```php
require dirname(__DIR__) . '/inc/NavMenuQuery/MenuItemTags.php';
```

and the import:

```php
use SFX\NavMenuQuery\MenuItemTags;
```

Then append before the epilogue:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php tests/nav-menu-query-test.php
```

Expected: a fatal — `Failed to open stream` for `inc/NavMenuQuery/MenuItemTags.php`.

- [ ] **Step 3: Write the implementation**

`inc/NavMenuQuery/MenuItemTags.php` — the two render filters and the builder tag list arrive in Tasks 8 and 9.

```php
<?php

declare(strict_types=1);

namespace SFX\NavMenuQuery;

/**
 * The {sfx_menu_item_*} tag vocabulary and its values.
 */
class MenuItemTags
{
    public const PREFIX = 'sfx_menu_item_';

    /** @var list<string> the nine tag keys, in display order */
    public const KEYS = [
        'title',
        'url',
        'id',
        'target',
        'rel',
        'classes',
        'description',
        'is_active',
        'is_ancestor',
    ];

    /** @var array<int, array<string, string>> per-request cache, keyed by item id */
    private static array $cache = [];

    /**
     * Find the menu item the current context refers to.
     *
     * The fallback is not optional: on the link path Bricks calls
     * bricks_render_dynamic_data() with its own post id (base.php:2524), so
     * $post is not the menu item and the tag would survive into the href.
     *
     * @param mixed $post
     */
    public static function item_from_context($post): ?\WP_Post
    {
        if ($post instanceof \WP_Post && $post->post_type === 'nav_menu_item') {
            return $post;
        }

        if (class_exists('Bricks\Query') && \Bricks\Query::is_looping()) {
            $loop_object = \Bricks\Query::get_loop_object();

            if ($loop_object instanceof \WP_Post && $loop_object->post_type === 'nav_menu_item') {
                return $loop_object;
            }
        }

        return null;
    }

    /**
     * One menu item value, raw and unescaped.
     *
     * null means "not resolvable, or not one of ours" — callers must leave the
     * tag alone. An empty string means "ours, and empty".
     *
     * @param mixed $post
     */
    public static function value($post, string $key): ?string
    {
        if (!in_array($key, self::KEYS, true)) {
            return null;
        }

        $item = self::item_from_context($post);

        if (!$item instanceof \WP_Post) {
            return null;
        }

        $id = (int) $item->ID;

        if (!isset(self::$cache[$id])) {
            // clone so the loop's $post is not mutated. wp_setup_nav_menu_item
            // resolves ->url from _menu_item_object_id / _menu_item_url and
            // sets ->title to the nav label rather than the page title.
            $prepared = wp_setup_nav_menu_item(clone $item);

            // Active state and classes were set on the ORIGINAL item by
            // _wp_menu_item_classes_by_context() during the query run, so they
            // are read from $item, not from the prepared clone.
            self::$cache[$id] = [
                'title'       => (string) ($prepared->title ?? ''),
                'url'         => (string) ($prepared->url ?? ''),
                'id'          => (string) $id,
                'target'      => (string) ($prepared->target ?? ''),
                'rel'         => (string) ($prepared->xfn ?? ''),
                'classes'     => implode(' ', array_filter((array) ($item->classes ?? []))),
                'description' => (string) ($prepared->description ?? ''),
                'is_active'   => !empty($item->current) ? '1' : '',
                'is_ancestor' => !empty($item->current_item_ancestor) ? '1' : '',
            ];
        }

        return self::$cache[$id][$key];
    }

    /** Test seam for the per-request static cache. */
    public static function reset_cache(): void
    {
        self::$cache = [];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php tests/nav-menu-query-test.php
```

Expected: `PASS: all nav-menu-query tests`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add inc/NavMenuQuery/MenuItemTags.php tests/nav-menu-query-test.php
git commit -m "feat(nav-menu-query): resolve menu item values for dynamic tags"
```

---

### Task 8: The `render_tag` pass-through contract

`bricks/dynamic_data/render_tag` is shared by every dynamic-data provider on the site, and Bricks seeds it with the tag itself. Returning anything but the incoming `$tag` for a tag this feature does not own destroys the value for every provider downstream.

**Files:**
- Modify: `inc/NavMenuQuery/MenuItemTags.php` (add `register()` and `render_tag()`)
- Modify: `tests/nav-menu-query-test.php` (append Case 8 group)

**Interfaces:**
- Consumes: `MenuItemTags::value()`, `MenuItemTags::PREFIX`, `MenuItemTags::KEYS`.
- Produces: `MenuItemTags::register(): void` (completed in Task 9), `MenuItemTags::render_tag($tag, $post, $context)`.

**Context you need:** Bricks strips the outer braces before firing the filter (`providers.php:651-654`), so the tag arrives as `sfx_menu_item_title`, never `{sfx_menu_item_title}`. The dynamic-data picker can pass an array rather than a string (`providers.php:647`), so the callback must not assume a string.

The three rules:

1. **Unrelated tag** → the exact incoming `$tag`.
2. **Owned tag that cannot be resolved** → the exact incoming `$tag`, *not* `''`. Returning `''` would suppress a later provider's answer and, in a Link URL, silently produce `href=""` instead of leaving a visibly unresolved tag the editor can see and fix.
3. **Owned tag inside the loop** → the **raw** resolved value. The consuming control escapes for its own context; `esc_url()` here would be applied twice and corrupt the URL. This is the deliberate asymmetry with `render_content` (Task 9), which *does* escape because it writes straight into markup.

Matching is exact against the nine keys. A suffixed variant like `sfx_menu_item_title:foo` — Bricks' tag-filter syntax — is not supported and falls through rule 2. Half-supporting it by ignoring the suffix would silently drop what the editor asked for.

- [ ] **Step 1: Write the failing test**

Append before the epilogue:

```php
// ------------------------------------------ Case 8: render_tag contract

MenuItemTags::reset_cache();
Bricks\Query::reset();

$tag_item = new WP_Post([
    'ID'      => 13,
    'title'   => 'Kunst & Kultur',
    'url'     => 'https://example.test/kunst kultur',
    'current' => true,
]);

// Rule 1: not ours — byte-identical, asserted by identity.
$foreign = 'post_title';
assert_same($foreign, MenuItemTags::render_tag($foreign, $tag_item, 'text'), 'Case 8a: an unrelated tag is returned unchanged');
assert_same('woo_product_price', MenuItemTags::render_tag('woo_product_price', null, 'text'), "Case 8b: another provider's tag is untouched");

// Rule 2: ours, but unresolvable.
$page = new WP_Post(['ID' => 500, 'post_type' => 'page']);
assert_same(
    'sfx_menu_item_title',
    MenuItemTags::render_tag('sfx_menu_item_title', $page, 'text'),
    'Case 8c: an owned tag outside a menu-item loop returns the tag, NOT an empty string'
);
assert_same('sfx_menu_item_bogus', MenuItemTags::render_tag('sfx_menu_item_bogus', $tag_item, 'text'), 'Case 8d: an unknown key under our prefix is returned unchanged');
assert_same('sfx_menu_item_title:foo', MenuItemTags::render_tag('sfx_menu_item_title:foo', $tag_item, 'text'), 'Case 8e: Bricks filter syntax is unsupported and left visible');

// Rule 3: ours, resolvable — raw value, no escaping.
assert_same('Kunst & Kultur', MenuItemTags::render_tag('sfx_menu_item_title', $tag_item, 'text'), 'Case 8f: an owned tag resolves');
assert_same(
    'https://example.test/kunst kultur',
    MenuItemTags::render_tag('sfx_menu_item_url', $tag_item, 'link'),
    'Case 8g: the URL is RAW — esc_url() here would double-escape what the control escapes'
);
assert_contains('&', MenuItemTags::render_tag('sfx_menu_item_title', $tag_item, 'text'), 'Case 8h: the title keeps its raw ampersand, unlike the render_content path');
assert_same('1', MenuItemTags::render_tag('sfx_menu_item_is_active', $tag_item, 'text'), 'Case 8i: is_active resolves');

// The picker can hand over an array rather than a string.
$array_tag = ['name' => '{sfx_menu_item_title}'];
assert_same($array_tag, MenuItemTags::render_tag($array_tag, $tag_item, 'text'), 'Case 8j: a non-string tag is returned as-is without a type error');

MenuItemTags::reset_cache();
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php tests/nav-menu-query-test.php
```

Expected: `PHP Fatal error: Uncaught Error: Call to undefined method SFX\NavMenuQuery\MenuItemTags::render_tag()`.

- [ ] **Step 3: Write the implementation**

Add to `inc/NavMenuQuery/MenuItemTags.php` — `register()` right after the `$cache` property, `render_tag()` after `value()`:

```php
    public static function register(): void
    {
        add_filter('bricks/dynamic_data/render_tag', [self::class, 'render_tag'], 10, 3);
    }
```

```php
    /**
     * Resolve a tag in a single-value context: a Link URL, an image source,
     * a condition operand.
     *
     * This filter is shared by every dynamic-data provider and Bricks seeds it
     * with the tag itself, so the incoming $tag doubles as "nobody has
     * resolved this yet". Returning anything else for a tag we do not own —
     * '', null, a normalised copy — destroys the value for every provider
     * after us.
     *
     * Values come back RAW. The consuming control escapes for its own context;
     * escaping here would double-escape. render_content() is the opposite,
     * because it writes straight into markup.
     *
     * @param mixed $tag  already stripped of its outer braces by Bricks
     * @param mixed $post
     * @param mixed $context
     * @return mixed
     */
    public static function render_tag($tag, $post, $context)
    {
        // The picker can hand over an array (providers.php:647).
        if (!is_string($tag) || strpos($tag, self::PREFIX) !== 0) {
            return $tag;
        }

        $key = substr($tag, strlen(self::PREFIX));

        // Exact match only. A suffixed variant (Bricks' tag-filter syntax) is
        // unsupported; ignoring the suffix would silently drop what the editor
        // asked for, so it falls through and stays visible instead.
        if (!in_array($key, self::KEYS, true)) {
            return $tag;
        }

        $value = self::value($post, $key);

        return $value === null ? $tag : $value;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php tests/nav-menu-query-test.php
```

Expected: `PASS: all nav-menu-query tests`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add inc/NavMenuQuery/MenuItemTags.php tests/nav-menu-query-test.php
git commit -m "feat(nav-menu-query): resolve menu item tags in single-value contexts"
```

---

### Task 9: Content rendering and builder tag list

Text content needs its own filter — not redundancy with Task 8, but a consequence of how Bricks' content parser decides what to resolve. And the tags need to appear in the builder's picker, which the reference snippet never did.

**Files:**
- Modify: `inc/NavMenuQuery/MenuItemTags.php` (extend `register()`, add `labels()`, `add_tags_to_builder()`, `render_content()`)
- Modify: `tests/nav-menu-query-test.php` (append Case 9 group)

**Interfaces:**
- Consumes: `MenuItemTags::value()`, `MenuItemTags::KEYS`, `MenuItemTags::PREFIX`.
- Produces: `MenuItemTags::labels(): array` (key => translated label), `MenuItemTags::add_tags_to_builder(array $tags): array`, `MenuItemTags::render_content($content, $post, $context)`.

**Context you need:** why *two* filters is not duplication. Bricks' content parser matches the tags it finds against `Providers::$tags`, which is assembled purely from registered provider *objects* (`providers.php:222`, used at `providers.php:327`). `bricks/dynamic_tags_list` does **not** feed that list — the source comments it as builder-picker only (`providers.php:797`). So these tags never enter `$registered_tags` and the parser will not resolve them. But `bricks/dynamic_data/render_content` fires regardless (`providers.php:368`), so the substitution has to happen here.

Escaping here is the **opposite** of Task 8, because this writes straight into markup: `esc_html()` for `title`, `description`, `classes`, `target`, `rel`; `esc_url()` for `url`; raw for `id`, `is_active`, `is_ancestor` (all generated and integer-ish, never user input).

- [ ] **Step 1: Write the failing test**

Append before the epilogue:

```php
// -------------------------------- Case 9: render_content and the tag picker

MenuItemTags::reset_cache();
Bricks\Query::reset();

$content_item = new WP_Post([
    'ID'                    => 13,
    'title'                 => 'Kunst & Kultur',
    'url'                   => 'https://example.test/kunst kultur',
    'target'                => '_blank',
    'xfn'                   => 'noopener',
    'description'           => 'Museen & mehr',
    'classes'               => ['current-menu-item'],
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

// 9j: the builder tag list.
$picker = MenuItemTags::add_tags_to_builder([['name' => '{post_title}', 'label' => 'Title', 'group' => 'Post']]);

assert_same(10, count($picker), 'Case 9j: nine tags appended to the existing list');
assert_same('{post_title}', $picker[0]['name'], 'Case 9k: the pre-existing entry is preserved');

$names = array_column($picker, 'name');
assert_true(in_array('{sfx_menu_item_title}', $names, true), 'Case 9l: the title tag is registered with braces');
assert_true(in_array('{sfx_menu_item_is_ancestor}', $names, true), 'Case 9m: the is_ancestor tag is registered');

$ours = array_values(array_filter($picker, fn($t) => strpos($t['name'], '{sfx_menu_item_') === 0));
assert_same(1, count(array_unique(array_column($ours, 'group'))), 'Case 9n: all nine share one picker group');
assert_true($ours[0]['label'] !== '', 'Case 9o: each entry carries a label');

MenuItemTags::reset_cache();
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php tests/nav-menu-query-test.php
```

Expected: `PHP Fatal error: Uncaught Error: Call to undefined method SFX\NavMenuQuery\MenuItemTags::render_content()`.

- [ ] **Step 3: Write the implementation**

Extend `register()` in `inc/NavMenuQuery/MenuItemTags.php`:

```php
    public static function register(): void
    {
        add_filter('bricks/dynamic_tags_list', [self::class, 'add_tags_to_builder']);
        add_filter('bricks/dynamic_data/render_tag', [self::class, 'render_tag'], 10, 3);
        add_filter('bricks/dynamic_data/render_content', [self::class, 'render_content'], 10, 3);
    }
```

and append these three methods:

```php
    /**
     * Human labels for the picker, keyed by tag key.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'title'       => __('Title', 'sfxtheme'),
            'url'         => __('URL', 'sfxtheme'),
            'id'          => __('ID', 'sfxtheme'),
            'target'      => __('Link target', 'sfxtheme'),
            'rel'         => __('Link relation', 'sfxtheme'),
            'classes'     => __('CSS classes', 'sfxtheme'),
            'description' => __('Description', 'sfxtheme'),
            'is_active'   => __('Is current page', 'sfxtheme'),
            'is_ancestor' => __('Is ancestor of current page', 'sfxtheme'),
        ];
    }

    /**
     * Put the nine tags in the builder's tag picker.
     *
     * Presentation only — bricks/dynamic_tags_list is builder-facing
     * (providers.php:797) and does not feed the content parser. Resolution
     * still comes from render_tag() and render_content().
     *
     * @param array<int, array<string, string>> $tags
     * @return array<int, array<string, string>>
     */
    public static function add_tags_to_builder(array $tags): array
    {
        $group = __('Menu item', 'sfxtheme');

        foreach (self::labels() as $key => $label) {
            $tags[] = [
                'name'  => '{' . self::PREFIX . $key . '}',
                'label' => $label,
                'group' => $group,
            ];
        }

        return $tags;
    }

    /**
     * Resolve tags inside text content.
     *
     * Not a duplicate of render_tag(). Bricks' content parser only resolves
     * tags present in Providers::$tags, which is built from registered
     * provider objects (providers.php:222, 327) — bricks/dynamic_tags_list
     * does not feed it. So the parser will never resolve these tags, but this
     * filter fires regardless (providers.php:368) and does the work itself.
     *
     * Values ARE escaped here, unlike render_tag(), because this writes
     * straight into markup.
     *
     * @param mixed $content
     * @param mixed $post
     * @param mixed $context
     * @return mixed
     */
    public static function render_content($content, $post, $context)
    {
        if (!is_string($content) || strpos($content, '{' . self::PREFIX) === false) {
            return $content;
        }

        if (self::value($post, 'id') === null) {
            return $content;
        }

        $replacements = [];

        foreach (self::KEYS as $key) {
            $value = (string) self::value($post, $key);

            $replacements['{' . self::PREFIX . $key . '}'] = match ($key) {
                'url' => esc_url($value),
                'id', 'is_active', 'is_ancestor' => $value,
                default => esc_html($value),
            };
        }

        return strtr($content, $replacements);
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php tests/nav-menu-query-test.php
```

Expected: `PASS: all nav-menu-query tests`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add inc/NavMenuQuery/MenuItemTags.php tests/nav-menu-query-test.php
git commit -m "feat(nav-menu-query): substitute menu item tags in content and the picker"
```

---

### Task 10: Wire the controller

Every collaborator exists and is tested. This is the line that makes the feature live.

**Files:**
- Modify: `inc/NavMenuQuery/Controller.php` (add the constructor)
- Modify: `tests/nav-menu-query-test.php` (append Case 10 group and a new `require`)

**Interfaces:**
- Consumes: `QueryType::register()`, `MenuOptions::register()`, `MenuItemTags::register()`.
- Produces: a fully hooked feature.

**Context you need:** `load_dependencies()` runs on `after_setup_theme` priority 1 (`functions.php:60-64`). Every hook registered here fires later — `bricks/setup/control_options` is consumed by `Setup::init_control_options()` on `init` priority 99 (`bricks/includes/setup.php:59`), `bricks/load_elements/before` on `wp`, the AJAX action on an admin-ajax request. No ordering problem.

Composer uses PSR-4 (`composer.json` `autoload.psr-4`, `SFX\` → `inc/`), so new class files are picked up without regenerating the autoloader. `functions.php` also has a PSR-4 fallback autoloader for installs without `vendor/`.

- [ ] **Step 1: Write the failing test**

Wiring is code, so it gets a test. A missing or misspelled `register()` call would otherwise survive every suite and only surface during manual verification in Task 12.

Add the require at the top of `tests/nav-menu-query-test.php`:

```php
require dirname(__DIR__) . '/inc/NavMenuQuery/Controller.php';
```

and the import:

```php
use SFX\NavMenuQuery\Controller;
```

Then append before the epilogue:

```php
// ------------------------------------------------- Case 10: controller wiring
// add_filter/add_action are stubbed as per-hook counters, so constructing the
// controller shows exactly which hooks the feature registers.

$test_filters = [];

new Controller();

assert_same(1, $test_filters['bricks/setup/control_options'] ?? 0, 'Case 10a: the query type is registered');
assert_same(1, $test_filters['bricks/load_elements/before'] ?? 0, 'Case 10b: element control registration is hooked');
assert_same(1, $test_filters['bricks/query/run'] ?? 0, 'Case 10c: the query runner is hooked');
assert_same(1, $test_filters['wp_ajax_sfx_nav_menu_parent_options'] ?? 0, 'Case 10d: the AJAX endpoint is hooked');
assert_same(1, $test_filters['bricks/dynamic_tags_list'] ?? 0, 'Case 10e: the builder tag list is hooked');
assert_same(1, $test_filters['bricks/dynamic_data/render_tag'] ?? 0, 'Case 10f: single-value tag rendering is hooked');
assert_same(1, $test_filters['bricks/dynamic_data/render_content'] ?? 0, 'Case 10g: content tag rendering is hooked');

assert_same(7, array_sum($test_filters), 'Case 10h: exactly seven hooks — no collaborator silently skipped, none registered twice');

// The feature config the theme's registry reads.
$config = Controller::get_feature_config();
assert_same('sfx_general_options', $config['activation_option_name'] ?? null, 'Case 10i: gated on the general options array');
assert_same('enable_nav_menu_query', $config['activation_option_key'] ?? null, 'Case 10j: gated on the right key');
assert_same(false, isset($config['menu_slug']), 'Case 10k: no menu_slug, so no empty settings page is created');
```

Case 10h is the one that matters: asserting each hook individually would still pass if a fourth collaborator were added and forgotten, and the total catches a duplicated `register()` call.

- [ ] **Step 2: Run the test to verify it fails**

```bash
php tests/nav-menu-query-test.php
```

Expected: seven `FAIL:` lines (10a-10g each got `0`), plus 10h reporting `0`, and exit 1 — `Controller` has no constructor yet, so nothing is registered.

- [ ] **Step 3: Add the constructor**

Add to `inc/NavMenuQuery/Controller.php`, above `get_feature_config()`:

```php
    public function __construct()
    {
        QueryType::register();
        MenuOptions::register();
        MenuItemTags::register();
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php -l inc/NavMenuQuery/Controller.php && php tests/nav-menu-query-test.php
```

Expected: `No syntax errors detected`, then `PASS: all nav-menu-query tests`, exit 0.

- [ ] **Step 5: Run every test in the repo**

```bash
failed=0
for t in tests/*-test.php; do
  echo "== $t"
  php "$t" || failed=1
done
exit "$failed"
```

Expected: exit status 0. Check it explicitly with `echo $?` — a plain `php "$t" || echo ...` loop leaves a zero status behind and would report success while a suite was failing. This is the first point at which the new feature could affect existing behaviour, so every suite runs, not just the new one.

- [ ] **Step 6: Commit**

```bash
git add inc/NavMenuQuery/Controller.php tests/nav-menu-query-test.php
git commit -m "feat(nav-menu-query): wire the feature's hooks"
```

---

### Task 11: German translations

Every string the feature shows is currently English. A string added without its translation ships an English label into a German backend.

**Files:**
- Modify: `languages/de_DE.po`
- Modify: `languages/de_DE.mo` (recompiled, binary)

**Interfaces:**
- Consumes: the source strings written in Tasks 1, 3, 4, 5 and 9.
- Produces: nothing other code calls.

**Context you need:** the theme loads its text domain via `load_child_theme_textdomain('sfxtheme', get_stylesheet_directory() . '/languages')` (`inc/SFXBricksChildTheme.php:183`). `de_DE.po` is an existing catalogue — append to it, never rewrite it.

- [ ] **Step 1: Record the baseline count**

```bash
msgfmt --statistics languages/de_DE.po -o /dev/null
```

Write the number down. Step 5 compares against it rather than against a figure hardcoded in this plan, which would go stale the moment anything else adds a string.

- [ ] **Step 2: Collect every new source string**

```bash
grep -rhoE "(__|esc_html__)\('([^']|\\\\')+', *'sfxtheme'\)" inc/NavMenuQuery/ \
  | sed -E "s/^(__|esc_html__)\('(.*)', *'sfxtheme'\)$/\2/" | sort -u
```

Cross-check against the two strings added outside the feature directory in Tasks 1 and 3 — the toggle label and description in `inc/GeneralThemeOptions/Settings.php`, and the overview label in `inc/ThemeSettingsOverview/OverviewProvider.php`. Every one of them needs an entry.

- [ ] **Step 3: Append the entries to `languages/de_DE.po`**

```po
msgid "Enable Menu Items query type"
msgstr "Menüpunkte-Abfragetyp aktivieren"

msgid "Adds a \"Menu Items\" query type to the Bricks query loop, so a WordPress menu can drive Bricks markup."
msgstr "Fügt der Bricks-Abfrageschleife den Abfragetyp „Menüpunkte“ hinzu, sodass ein WordPress-Menü das Bricks-Markup steuern kann."

msgid "Menu Items query type"
msgstr "Menüpunkte-Abfragetyp"

msgid "Menu Items"
msgstr "Menüpunkte"

msgid "Menu location"
msgstr "Menü-Position"

msgid "Select a location"
msgstr "Position wählen"

msgid "Follows whichever menu is assigned to this location."
msgstr "Folgt dem Menü, das dieser Position zugewiesen ist."

msgid "Menu"
msgstr "Menü"

msgid "Only used when no location is selected."
msgstr "Wird nur verwendet, wenn keine Position gewählt ist."

msgid "Items below"
msgstr "Punkte unter"

msgid "Top level"
msgstr "Oberste Ebene"

msgid "↑ Children of the current item"
msgstr "↑ Unterpunkte des aktuellen Eintrags"

msgid "Invalid nonce"
msgstr "Ungültiger Nonce"

msgid "Insufficient permissions"
msgstr "Unzureichende Berechtigungen"

msgid "Menu item"
msgstr "Menüpunkt"

msgid "Title"
msgstr "Titel"

msgid "URL"
msgstr "URL"

msgid "ID"
msgstr "ID"

msgid "Link target"
msgstr "Linkziel"

msgid "Link relation"
msgstr "Linkbeziehung"

msgid "CSS classes"
msgstr "CSS-Klassen"

msgid "Description"
msgstr "Beschreibung"

msgid "Is current page"
msgstr "Ist aktuelle Seite"

msgid "Is ancestor of current page"
msgstr "Ist übergeordnet zur aktuellen Seite"
```

Before appending each entry, check it is not already present — several of these (`Title`, `URL`, `ID`, `Description`) are common and may already exist. A duplicate `msgid` makes `msgfmt` fail:

```bash
for s in "Title" "URL" "ID" "Description" "Menu" "Link target" "Link relation" "CSS classes"; do
  printf '%-22s ' "$s"
  grep -c "^msgid \"$s\"\$" languages/de_DE.po
done
```

Any line printing `1` or more already has an entry — skip that one rather than adding a second.

- [ ] **Step 4: Recompile the `.mo`**

```bash
msgfmt languages/de_DE.po -o languages/de_DE.mo && echo "compiled"
```

Expected: `compiled`, no warnings. If `msgfmt` is unavailable, install gettext (`brew install gettext`) — do not hand-edit the binary and do not skip this step, because WordPress reads the `.mo`, not the `.po`.

- [ ] **Step 5: Verify the catalogue is well-formed and grew**

```bash
msgfmt --check --statistics languages/de_DE.po -o /dev/null
```

Expected: no error output, and a translated-message count **higher than the Step 1 baseline** by the number of entries you actually appended (fewer than 24 if some already existed). A duplicate-`msgid` error here means Step 3's check was skipped for some string. A count equal to the baseline means nothing was appended.

- [ ] **Step 6: Commit**

```bash
git add languages/de_DE.po languages/de_DE.mo
git commit -m "i18n(nav-menu-query): add German translations"
```

---

### Task 12: Manual builder verification

Everything a unit test cannot see: whether Bricks actually accepts the dotted `required` path, whether the AJAX select populates, whether Map's grouping lands right, and whether a nested loop renders a real two-level menu.

**Files:** none — this task changes no code. If a check fails, fix the responsible file and re-run its task's test before repeating the check.

**Interfaces:**
- Consumes: the whole feature.
- Produces: a verified feature, and a note in the plan of anything that did not hold.

**Setup:** in `Appearance → Menus`, build a menu with at least two top-level items where one has two or more children and one child has a child of its own. Assign it to a theme location. Enable the feature at *Global Theme Settings → General Theme Options → Enable Menu Items query type*.

- [ ] **Step 1: The query type is reachable**

Open any template in Bricks. Add a Block, tick **Query loop**, open the query popup.

Expected: **Menüpunkte** appears in the Type dropdown (German backend) or **Menu Items** (English). If it is missing, the feature is off, or `bricks/setup/control_options` is not firing — check Task 1's toggle and Task 5's `register()`.

- [ ] **Step 2: The controls appear and are gated**

With Type = Menüpunkte, look at the element's Content tab.

Expected: **Menü-Position**, **Menü** and **Punkte unter** are all visible. Switch Type to Posts.

Expected: all three disappear. **This is the check that validates the dotted `required` path `query.objectType`** — the one construction in this feature that could not be confirmed against Bricks source. If the controls stay visible, the dotted path is not supported; report it rather than working around it, because the fix changes the control design.

- [ ] **Step 3: The location/menu interplay**

Select a location.

Expected: the **Menü** select hides (it is gated on `sfxNavMenuLocation` being empty). Clear the location.

Expected: **Menü** reappears.

- [ ] **Step 4: The parent select populates over AJAX**

Open **Punkte unter**.

Expected: the first entry is *↑ Unterpunkte des aktuellen Eintrags*, followed by every menu item labelled with its path and child count — including leaves showing `(0)`. Change the selected menu or location.

Expected: the list repopulates for the new menu. If it stays empty, open the browser devtools Network tab and check the `sfx_nav_menu_parent_options` admin-ajax response for an error payload.

- [ ] **Step 5: Map's control group**

Add a **Map** element, tick Query loop, set Type = Menüpunkte.

Expected: the three controls appear in the **same group as Map's own query UI** (`addresses`), not in a separate panel. This is the single reason `add_element_controls()` copies `query.group`, and the only place it is observable.

- [ ] **Step 6: Slider and Accordion**

Repeat on a **Slider** and an **Accordion**.

Expected: the controls appear next to the query control, ungrouped. Together with Steps 2 and 5 this confirms the derived element coverage reaches all seven loop-capable elements, not the three the reference snippet handled.

- [ ] **Step 7: A real two-level menu**

Outer Block: query loop, Menüpunkte, your location, parent = *Top level*. Inside it, a Text element using `{sfx_menu_item_title}` and a Link using `{sfx_menu_item_url}`.

Inner Block, nested inside the outer one: query loop, Menüpunkte, same location, parent = *↑ Unterpunkte des aktuellen Eintrags*. Inside it, the same two tags.

Expected on the frontend: every top-level item renders, and under each, exactly its own children — not the same child list repeated. Check the `href` values are real URLs and not literal `{sfx_menu_item_url}` text; a literal tag in the `href` means the loop-context fallback in `item_from_context()` is not working.

- [ ] **Step 8: Active state**

Navigate to a page that is in the menu.

Expected: that item's rendered classes include `current-menu-item`. Add a Bricks condition on the outer Block's child using `{sfx_menu_item_is_active}` "is not empty".

Expected: it matches only the current item.

- [ ] **Step 9: The toggle really disables**

Turn *Enable Menu Items query type* off. Reload the builder.

Expected: **Menüpunkte** is gone from the Type dropdown. Turn it back on.

- [ ] **Step 10: Record the outcome**

If every step passed, note it in the PR description, calling out Step 2 explicitly — it is the one assumption in the spec that source reading could not settle.

If any step failed, do not paper over it: report which step, what you saw, and stop for a decision.

---

## Finishing

After Task 12, the branch is complete. Use `superpowers:finishing-a-development-branch` to decide how to integrate.

Before opening a PR:

```bash
failed=0
for t in tests/*-test.php; do
  php "$t" || failed=1
done
for f in inc/NavMenuQuery/*.php; do
  php -l "$f" || failed=1
done
echo "aggregate status: $failed"
git log --oneline main..HEAD
```

Expected: `aggregate status: 0`, and the log shows eleven commits (Tasks 1-11; Task 12 adds none).

The accumulator is not decoration. `php "$t" || echo "FAILED: $t"` leaves the *echo's* exit status behind, so the loop finishes green while a suite is red — the failure scrolls past in a wall of output and the branch looks ready.

The PR description should carry the spec's *Migration from the snippet* section verbatim — anyone enabling this on a site running the visitessen snippet needs those four steps, and step 4 (replacing a tag in the parent field with the *Children of the current item* entry) is the only behavioural change among them.
