# Editor Prose Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A child-theme module that makes the Gutenberg editor canvas render Bricks prose content like the frontend, plus an optional token-based baseline stylesheet.

**Architecture:** An editor script gives the canvas root the frontend wrapper's classes (`brxe-<element>` + configured prose classes, + `sfx-prose` when the baseline is on) and injects, into the canvas document only, the prose class CSS compiled and scoped by Bricks' own functions. A separate layered stylesheet `prose.css` provides the optional baseline on the frontend and in the canvas.

**Tech Stack:** PHP 8 (WordPress, Bricks 2.4 APIs), vanilla JS (no build), CSS cascade layers; tests in plain PHP and Node (`node:assert`, `node:vm`).

**Spec:** `docs/superpowers/specs/2026-09-30-editor-prose-design.md` — read it before any task; this plan implements it and does not restate its reasoning.

## Global Constraints

- **Working directory:** every path and command in this plan is relative to the theme root `wp-content/themes/sfx-bricks-child`; `cd` there first.
- **Commits:** every task commits a `WIP: …` snapshot (CLAUDE.md §5 Mechanics — a non-WIP commit closes the Gate-B cycle). The real commit is made once, after Gate B is clean (see "Finish: Gate B and closing commit").

- Bricks ≥ 2.4 for the editor mirroring: `defined('BRICKS_VERSION') && version_compare(BRICKS_VERSION, '2.4', '>=')`.
- Option `sfx_editor_prose_options`; module toggle `enable_editor_prose` in `sfx_general_options`, default `0`.
- Capability `manage_options`, nonce via Settings API (`settings_fields`) — invariant 2.
- All output escaped at the point of output — invariant 3.
- Every new user-facing string uses text domain `sfxtheme` — invariant 4.
- PSR-4 path case matches class case byte-for-byte (`inc/EditorProse/…`, namespace `SFX\EditorProse`) — invariant 1.
- No new Composer dependency; no new module-to-module coupling (ImportExport catalogue entry is by design).
- Baseline literal fallbacks are `em`/`inherit`/unitless, never `rem`.
- Layer-order statement, verbatim: `@layer sfx.reset, sfx.utilities, sfx.components, sfx.theme;`
- Filter name: `sfx_editor_prose_css` (the only filter).
- Local PHP: `/Applications/MAMP/bin/php/php8.5.2/bin/php` if `php` is not on PATH; Node via `node` or `~/.local/share/fnm/aliases/default/bin/node`.
- Quality battery: `./quality.sh` (runs every `tests/*-test.php` and `tests/*-test.mjs`).

## Review Focus

1. **A site with the module on but no class configured and baseline off** — the editor must be untouched (no script). Pinned in Task 1 (`applies_to` false) and Task 5 test check 1.
2. **A configured class name that does not exist in Bricks** (typo) — editor still gets the root classes, CSS holds only the title rule, admin page names the missing class. Pinned in Task 2 (`build` with unknown name; `missing_classes`) and Task 5 test check 5.
3. **Bricks' compiler throws mid-way** — Bricks' `Assets` statics must be restored, or the rest of the editor page's Bricks CSS breaks. Pinned in Task 2 (statics-restored assertion).
4. **The settings form submitted with every checkbox unticked** — `all_post_types` and `baseline` must become `false`, not fall back to defaults. Pinned in Task 1 (sanitize of `'0'`) and Task 5 test check 5 (hidden `0` inputs precede the checkboxes).
5. **CSS text containing `</script>` or `</style>`** — must not break out of the inline script in the admin document. Pinned in Task 5 test check 3 (the controller's real inline script, hostile CSS via the filter).

---

## File Structure

| File | Responsibility |
|---|---|
| `inc/EditorProse/Settings.php` | option schema, total sanitizer, read accessor, gate predicates |
| `inc/EditorProse/Payload.php` | builds `{classes, css, links}` from settings via Bricks APIs |
| `inc/EditorProse/Controller.php` | hooks: editor enqueue, frontend baseline enqueue; feature config |
| `inc/EditorProse/AdminPage.php` | settings screen |
| `inc/EditorProse/index.php` | silence file, as in every module dir |
| `inc/EditorProse/assets/editor-prose.js` | canvas sync (classes, style, links) |
| `inc/EditorProse/assets/prose.css` | optional baseline |
| `tests/editor-prose-settings-test.php` | Task 1 |
| `tests/editor-prose-payload-test.php` | Task 2 |
| `tests/support/editor-prose-bricks-stubs.php` | Bricks stand-ins for Task 2 |
| `tests/editor-prose-test.mjs` | Task 3 |
| `tests/editor-prose-baseline-test.mjs` | Task 4 |
| `tests/editor-prose-controller-test.php` | Task 5 |
| `tests/support/editor-prose-bricks-stubs-partial.php` | Task 2 (Bricks without `load_webfonts`) |
| Modified: `inc/DataPurge.php` (Task 1), `inc/GeneralThemeOptions/Settings.php`, `inc/ThemeSettingsOverview/OverviewProvider.php`, `tests/theme-settings-overview-provider-test.php`, `inc/ImportExport/Controller.php`, `languages/de_DE.po/.mo`, `README.md`, `AGENTS.md` | Tasks 6–7 |

---

### Task 1: Settings — schema, sanitizer, gate predicates

**Files:**
- Create: `inc/EditorProse/Settings.php`, `inc/EditorProse/index.php`
- Modify: `inc/DataPurge.php` (option list, after the `// Redirects` entries) — `tests/data-purge-test.php` scans every `OPTION_NAME` and fails until the new option is listed
- Test: `tests/editor-prose-settings-test.php`

**Interfaces:**
- Produces: `SFX\EditorProse\Settings::OPTION_NAME` (`'sfx_editor_prose_options'`), `OPTION_GROUP`, `ELEMENTS` (`['text','post-content']`), `defaults(): array`, `sanitize($raw): array`, `get(): array`, `register(): void`, `register_settings(): void`, `applies_to(array $o, string $post_type): bool`, `bricks_ok(?string $version): bool`.
- Settings array shape (always, after `sanitize`): `['classes' => list<string>, 'element' => 'text'|'post-content', 'all_post_types' => bool, 'post_types' => list<string>, 'baseline' => bool, 'title_gap' => string]`.

- [ ] **Step 1: Write the failing test**

`tests/editor-prose-settings-test.php`:

```php
<?php

declare(strict_types=1);

$test_options = [];
$registered_post_types = ['post' => true, 'page' => true, 'cpt_job' => true, 'attachment' => false];

function get_option($name, $default = false)
{
    global $test_options;
    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}
function add_action($hook, $callback, $priority = 10, $args = 1) { return true; }
function register_setting($group, $name, $args = []) { return true; }
function post_type_exists($pt) { global $registered_post_types; return array_key_exists($pt, $registered_post_types); }
function use_block_editor_for_post_type($pt) { global $registered_post_types; return !empty($registered_post_types[$pt]); }

require_once __DIR__ . '/../inc/EditorProse/Settings.php';

use SFX\EditorProse\Settings;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$d = Settings::defaults();

// 1. Defaults and name.
assert_same('sfx_editor_prose_options', Settings::OPTION_NAME, '1: option name');
assert_same(['classes' => [], 'element' => 'text', 'all_post_types' => true, 'post_types' => [], 'baseline' => false, 'title_gap' => ''], $d, '1: defaults');

// 2. Non-array input -> defaults.
assert_same($d, Settings::sanitize('garbage'), '2: string option');
assert_same($d, Settings::sanitize(null), '2: null option');

// 3. Classes: string form input is tokenized; leading dot stripped; invalid and duplicates dropped; trailing newline trimmed.
assert_same(['rich-text-content', 'article__prose', '-x'], Settings::sanitize(['classes' => ".rich-text-content, article__prose rich-text-content 1bad -x bad{"])['classes'], '3: string classes');
assert_same(['prose'], Settings::sanitize(['classes' => ["prose\n", 42, ['a'], '.']])['classes'], '3: array classes with junk');
assert_same([], Settings::sanitize(['classes' => 5])['classes'], '3: non-array non-string classes');

// 4. Element whitelist.
assert_same('post-content', Settings::sanitize(['element' => 'post-content'])['element'], '4: post-content kept');
assert_same('text', Settings::sanitize(['element' => 'heading'])['element'], '4: unknown element -> text');

// 5. Booleans: only true/1/'1' are true; form sends '0' via hidden input when unticked.
assert_same(false, Settings::sanitize(['all_post_types' => '0'])['all_post_types'], '5: all_post_types 0');
assert_same(true, Settings::sanitize(['all_post_types' => '1'])['all_post_types'], '5: all_post_types 1');
assert_same(true, Settings::sanitize([])['all_post_types'], '5: missing key -> default true');
assert_same(false, Settings::sanitize(['baseline' => 'yes'])['baseline'], '5: baseline junk -> false');
assert_same(true, Settings::sanitize(['baseline' => 1])['baseline'], '5: baseline 1');

// 6. Post types: only registered, block-editor ones; unique; non-array -> [].
assert_same(['post', 'cpt_job'], Settings::sanitize(['post_types' => ['post', 'ghost', 'attachment', 'cpt_job', 'post', 3]])['post_types'], '6: post types filtered');
assert_same([], Settings::sanitize(['post_types' => 'post'])['post_types'], '6: string post_types -> []');

// 7. title_gap.
foreach (['2rem', '0', 'clamp(1rem, 2vw, 2rem)', 'var(--gap, 1rem)', ' 1.5em '] as $ok) {
    assert_same(trim($ok), Settings::sanitize(['title_gap' => $ok])['title_gap'], "7: accepts {$ok}");
}
foreach (['1rem;color:red', '1rem}', 'a{', '<b>', 'x>y', 'a\\b', '1rem/*x*/', 'url(x)', 'URL(x)', str_repeat('1', 101), 5] as $bad) {
    assert_same('', Settings::sanitize(['title_gap' => $bad])['title_gap'], '7: rejects ' . var_export($bad, true));
}

// 8. get() sanitizes on read.
$test_options[Settings::OPTION_NAME] = ['classes' => 'a b', 'element' => 'nope', 'title_gap' => '1rem;'];
assert_same(['a', 'b'], Settings::get()['classes'], '8: get tokenizes');
assert_same('text', Settings::get()['element'], '8: get whitelists');
assert_same('', Settings::get()['title_gap'], '8: get rejects');

// 9. Gate.
$o = Settings::sanitize(['classes' => 'x']);
assert_same(true, Settings::applies_to($o, 'post'), '9: all post types');
$o = Settings::sanitize(['classes' => 'x', 'all_post_types' => '0', 'post_types' => ['page']]);
assert_same(true, Settings::applies_to($o, 'page'), '9: listed type');
assert_same(false, Settings::applies_to($o, 'post'), '9: unlisted type');
$o = Settings::sanitize(['classes' => 'x', 'all_post_types' => '0', 'post_types' => []]);
assert_same(false, Settings::applies_to($o, 'post'), '9: false + empty = nowhere');
$o = Settings::sanitize([]);
assert_same(false, Settings::applies_to($o, 'post'), '9: no classes, baseline off -> closed');
$o = Settings::sanitize(['baseline' => '1']);
assert_same(true, Settings::applies_to($o, 'post'), '9: no classes, baseline on -> open');

// 10. Bricks version.
assert_same(false, Settings::bricks_ok(null), '10: no Bricks');
assert_same(false, Settings::bricks_ok('2.3.9'), '10: 2.3.9');
assert_same(true, Settings::bricks_ok('2.4'), '10: 2.4');
assert_same(true, Settings::bricks_ok('2.4.2'), '10: 2.4.2');

echo "editor-prose-settings-test: PASS\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/editor-prose-settings-test.php`
Expected: fatal "Failed opening required …/inc/EditorProse/Settings.php".

- [ ] **Step 3: Write the implementation**

`inc/EditorProse/index.php`:

```php
<?php
// Silence is golden.
```

`inc/EditorProse/Settings.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\EditorProse;

class Settings
{
    public const OPTION_NAME = 'sfx_editor_prose_options';
    public const OPTION_GROUP = 'sfx_editor_prose_options';
    public const ELEMENTS = ['text', 'post-content'];

    public static function register(): void
    {
        add_action('sfx_init_admin_features', [self::class, 'register_settings']);
    }

    public static function register_settings(): void
    {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => self::defaults(),
        ]);
    }

    public static function defaults(): array
    {
        return [
            'classes' => [],
            'element' => 'text',
            'all_post_types' => true,
            'post_types' => [],
            'baseline' => false,
            'title_gap' => '',
        ];
    }

    /** Sanitized on every read, so imports and hand-edited values never reach the editor raw. */
    public static function get(): array
    {
        return self::sanitize(get_option(self::OPTION_NAME, self::defaults()));
    }

    /** Total: any input yields the full, typed settings array. */
    public static function sanitize($raw): array
    {
        $d = self::defaults();
        if (!is_array($raw)) {
            return $d;
        }

        return [
            'classes' => self::sanitize_classes($raw['classes'] ?? []),
            'element' => in_array($raw['element'] ?? null, self::ELEMENTS, true) ? $raw['element'] : 'text',
            'all_post_types' => array_key_exists('all_post_types', $raw) ? self::to_bool($raw['all_post_types']) : $d['all_post_types'],
            'post_types' => self::sanitize_post_types($raw['post_types'] ?? []),
            'baseline' => array_key_exists('baseline', $raw) ? self::to_bool($raw['baseline']) : $d['baseline'],
            'title_gap' => self::sanitize_title_gap($raw['title_gap'] ?? ''),
        ];
    }

    public static function applies_to(array $o, string $post_type): bool
    {
        if ($o['classes'] === [] && !$o['baseline']) {
            return false;
        }

        return $o['all_post_types'] || in_array($post_type, $o['post_types'], true);
    }

    public static function bricks_ok(?string $version): bool
    {
        return $version !== null && version_compare($version, '2.4', '>=');
    }

    private static function to_bool($v): bool
    {
        return $v === true || $v === 1 || $v === '1';
    }

    private static function sanitize_classes($v): array
    {
        if (is_string($v)) {
            $v = preg_split('/[\s,]+/', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($v)) {
            return [];
        }

        $out = [];
        foreach ($v as $c) {
            if (!is_string($c)) {
                continue;
            }
            $c = ltrim(trim($c), '.');
            if (preg_match('/^-?[_a-zA-Z][_a-zA-Z0-9-]*$/D', $c) && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    private static function sanitize_post_types($v): array
    {
        if (!is_array($v)) {
            return [];
        }

        $out = [];
        foreach ($v as $pt) {
            if (!is_string($pt) || in_array($pt, $out, true) || !post_type_exists($pt)) {
                continue;
            }
            if (function_exists('use_block_editor_for_post_type') && !use_block_editor_for_post_type($pt)) {
                continue;
            }
            $out[] = $pt;
        }

        return $out;
    }

    private static function sanitize_title_gap($v): string
    {
        if (!is_string($v)) {
            return '';
        }
        $v = trim($v);
        if ($v === '' || strlen($v) > 100 || preg_match('/[;{}<>\\\\]|\/\*|url\(/i', $v)) {
            return '';
        }

        return $v;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/editor-prose-settings-test.php`
Expected: `editor-prose-settings-test: PASS`

- [ ] **Step 5: Purge ownership**

Run: `php tests/data-purge-test.php`
Expected: FAIL "Case 2b: inc/EditorProse/Settings.php declares 'sfx_editor_prose_options' but the purge does not name it".

In `inc/DataPurge.php`, after the Redirects entries of the option list:

```php
        // Editor Prose
        'sfx_editor_prose_options',
```

Run: `./quality.sh`
Expected: exit 0.

- [ ] **Step 6: Commit**

```bash
git add inc/EditorProse/Settings.php inc/EditorProse/index.php tests/editor-prose-settings-test.php inc/DataPurge.php
git commit -m "WIP: editor-prose settings"
```

---

### Task 2: Payload — classes, compiled CSS, links

**Files:**
- Create: `inc/EditorProse/Payload.php`, `tests/support/editor-prose-bricks-stubs.php`, `tests/support/editor-prose-bricks-stubs-partial.php`
- Test: `tests/editor-prose-payload-test.php`

**Interfaces:**
- Consumes: settings array shape from Task 1.
- Produces: `SFX\EditorProse\Payload::build(array $o, string $post_type, string $baseline_url = ''): array` returning `['classes' => list<string>, 'css' => string, 'links' => list<string>]`; `compile(array $names, string $element): string`; `font_links(string $css): array`; `title_rule(string $gap): string`; `missing_classes(array $names): array`.
- Bricks APIs used (Bricks 2.4.2): `\Bricks\Database::$global_data['globalClasses']` (list of `['id','name',…]`, populated in `Database::__construct` via `get_global_data()`), `\Bricks\Assets::$global_classes_elements|$inline_css|$inline_css_breakpoints|$unique_inline_css|$inline_css_dynamic_data|$current_generating_element`, `\Bricks\Assets::generate_global_classes(string $key): ?string`, `\Bricks\Assets::load_webfonts(string $css, bool $return_html_links): ?string`, `\Bricks\Integrations\Block_Editor::scope_css_for_gutenberg(string $css): string`.

- [ ] **Step 1: Write the stubs and the failing test**

`tests/support/editor-prose-bricks-stubs.php`:

```php
<?php

declare(strict_types=1);

namespace Bricks {
    class Database
    {
        public static $global_data = [];
    }

    class Assets
    {
        public static $global_classes_elements = [];
        public static $inline_css = [];
        public static $inline_css_breakpoints = [];
        public static $unique_inline_css = [];
        public static $inline_css_dynamic_data = '';
        public static $current_generating_element = null;

        public static bool $throw = false;
        public static array $seen = [];

        public static function generate_global_classes($key = 'global_classes')
        {
            self::$seen[] = [$key, self::$global_classes_elements];
            $map = self::$global_classes_elements;
            // Dirty every static the way a real compile can, before returning or throwing.
            self::$global_classes_elements = ['dirty' => ['x']];
            self::$inline_css = ['dirty' => 'x'];
            self::$inline_css_breakpoints = ['dirty' => 'x'];
            self::$unique_inline_css = ['dirty'];
            self::$inline_css_dynamic_data = 'dirty';
            self::$current_generating_element = 'dirty';
            if (self::$throw) {
                throw new \RuntimeException('boom');
            }
            if ($map === []) {
                return null;
            }
            $css = '';
            foreach ($map as $id => $els) {
                $css .= ".{$id}.brxe-{$els[0]} { font-family: \"Inter\"; }\n";
            }
            return $css;
        }

        public static function load_webfonts($css, $return_html_links = false)
        {
            // Only answers the HTML-links mode with a font actually used in $css.
            if ($return_html_links !== true || strpos((string) $css, 'Inter') === false) {
                return null;
            }
            return '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
                . '<link rel="stylesheet" href="https://fonts.example/css?family=Inter&amp;display=swap">';
        }
    }
}

namespace Bricks\Integrations {
    class Block_Editor
    {
        public static function scope_css_for_gutenberg($css, $map = false)
        {
            return preg_replace('/^\./m', '.block-editor-iframe__body .', $css);
        }
    }
}
```

`tests/editor-prose-payload-test.php`:

```php
<?php

declare(strict_types=1);

function apply_filters($hook, $value, ...$args) { return $value; }

require_once __DIR__ . '/../inc/EditorProse/Payload.php';

use SFX\EditorProse\Payload;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function opts(array $o = []): array
{
    return array_merge(['classes' => [], 'element' => 'text', 'all_post_types' => true, 'post_types' => [], 'baseline' => false, 'title_gap' => ''], $o);
}

const TITLE_1EM = ".editor-styles-wrapper .editor-post-title { margin-block-end: 1em; }\n";

// 1. No Bricks loaded: classes still ship, CSS is only the title rule.
$p = Payload::build(opts(['classes' => ['prose'], 'title_gap' => '1em']), 'post');
assert_same(['brxe-text', 'prose'], $p['classes'], '1: classes without Bricks');
assert_same(TITLE_1EM, $p['css'], '1: title rule only');
assert_same([], $p['links'], '1: no links');
assert_same(['prose'], Payload::missing_classes(['prose']), '1: all missing without Bricks');

// 2. Bricks present but load_webfonts missing: the API counts as unavailable -> title rule only.
//    Separate process, because a class cannot lose a method once declared.
$child = sprintf(
    'function apply_filters($h, $v) { return $v; } require %s; require %s; '
    . '\Bricks\Database::$global_data["globalClasses"] = [["id" => "abc", "name" => "prose"]]; '
    . 'echo json_encode(\SFX\EditorProse\Payload::build(["classes" => ["prose"], "element" => "text", "all_post_types" => true, "post_types" => [], "baseline" => false, "title_gap" => "1em"], "post"));',
    var_export(__DIR__ . '/../inc/EditorProse/Payload.php', true),
    var_export(__DIR__ . '/support/editor-prose-bricks-stubs-partial.php', true)
);
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($child));
$partial = json_decode((string) $out, true);
assert_same(TITLE_1EM, $partial['css'] ?? null, '2: missing load_webfonts -> title rule only');

require_once __DIR__ . '/support/editor-prose-bricks-stubs.php';

\Bricks\Database::$global_data['globalClasses'] = [
    ['id' => 'abc', 'name' => 'prose'],
    ['id' => 'def', 'name' => 'second'],
    'junk',
];

assert_same('', Payload::title_rule(''), '3: empty gap -> no rule');

// 4. Compiled, scoped, one call for all classes in settings order, element from settings.
\Bricks\Assets::$seen = [];
$p = Payload::build(opts(['classes' => ['second', 'prose', 'typo'], 'element' => 'post-content']), 'page');
assert_same(['brxe-post-content', 'second', 'prose', 'typo'], $p['classes'], '4: classes');
assert_same(".block-editor-iframe__body .def.brxe-post-content { font-family: \"Inter\"; }\n.block-editor-iframe__body .abc.brxe-post-content { font-family: \"Inter\"; }\n", $p['css'], '4: compiled + scoped');
assert_same([['sfx_editor_prose', ['def' => ['post-content'], 'abc' => ['post-content']]]], \Bricks\Assets::$seen, '4: one compiler call, ordered map');
assert_same(['https://fonts.example/css?family=Inter&display=swap'], $p['links'], '4: stylesheet links only, decoded');
assert_same(['typo'], Payload::missing_classes(['second', 'prose', 'typo']), '4: missing names');

// 5. All six statics restored, after a normal call and after a throw.
function seed(): array
{
    \Bricks\Assets::$global_classes_elements = ['orig' => ['div']];
    \Bricks\Assets::$inline_css = ['keep' => 'me'];
    \Bricks\Assets::$inline_css_breakpoints = ['bp' => 'orig'];
    \Bricks\Assets::$unique_inline_css = ['orig'];
    \Bricks\Assets::$inline_css_dynamic_data = 'orig';
    \Bricks\Assets::$current_generating_element = 'orig-el';
    return snapshot_statics();
}
function snapshot_statics(): array
{
    return [
        \Bricks\Assets::$global_classes_elements,
        \Bricks\Assets::$inline_css,
        \Bricks\Assets::$inline_css_breakpoints,
        \Bricks\Assets::$unique_inline_css,
        \Bricks\Assets::$inline_css_dynamic_data,
        \Bricks\Assets::$current_generating_element,
    ];
}
$before = seed();
Payload::build(opts(['classes' => ['prose']]), 'post');
assert_same($before, snapshot_statics(), '5: all six statics restored after a normal compile');

$before = seed();
\Bricks\Assets::$throw = true;
$p = Payload::build(opts(['classes' => ['prose'], 'title_gap' => '1em', 'baseline' => true]), 'post', 'https://site.test/prose.css?ver=1');
\Bricks\Assets::$throw = false;
assert_same($before, snapshot_statics(), '5: all six statics restored after a throw');
assert_same(TITLE_1EM, $p['css'], '5: throw -> title rule only');
assert_same(['https://site.test/prose.css?ver=1'], $p['links'], '5: baseline link survives a throw');
assert_same(['brxe-text', 'prose', 'sfx-prose'], $p['classes'], '5: classes survive a throw');

// 6. Unknown only -> compiler not called, css empty.
\Bricks\Assets::$seen = [];
$p = Payload::build(opts(['classes' => ['typo']]), 'post');
assert_same([], \Bricks\Assets::$seen, '6: compiler not called for unknown names');
assert_same('', $p['css'], '6: empty css');

// 7. Baseline: link first, sfx-prose class last.
$p = Payload::build(opts(['classes' => ['prose'], 'baseline' => true]), 'post', 'https://site.test/prose.css?ver=1');
assert_same(['https://site.test/prose.css?ver=1', 'https://fonts.example/css?family=Inter&display=swap'], $p['links'], '7: baseline link first');
assert_same('sfx-prose', end($p['classes']), '7: sfx-prose added');

echo "editor-prose-payload-test: PASS\n";
```

`tests/support/editor-prose-bricks-stubs-partial.php` (Bricks with every API except `load_webfonts`):

```php
<?php

declare(strict_types=1);

namespace Bricks {
    class Database
    {
        public static $global_data = [];
    }

    class Assets
    {
        public static $global_classes_elements = [];
        public static $inline_css = [];
        public static $inline_css_breakpoints = [];
        public static $unique_inline_css = [];
        public static $inline_css_dynamic_data = '';
        public static $current_generating_element = null;

        public static function generate_global_classes($key = 'global_classes')
        {
            return ".abc.brxe-text { color: red; }\n";
        }
    }
}

namespace Bricks\Integrations {
    class Block_Editor
    {
        public static function scope_css_for_gutenberg($css, $map = false)
        {
            return $css;
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/editor-prose-payload-test.php`
Expected: fatal "Failed opening required …/inc/EditorProse/Payload.php".

- [ ] **Step 3: Write the implementation**

`inc/EditorProse/Payload.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\EditorProse;

/**
 * Builds what the editor script puts into the canvas. Bricks does the real work
 * (compiling the classes, scoping to the canvas); this only drives it the way
 * Bricks drives it for component classes (Block_Editor::generate_gutenberg_global_classes_css).
 */
class Payload
{
    /** The Assets statics Bricks saves around its own editor compile. */
    private const ASSET_STATICS = [
        'global_classes_elements',
        'inline_css',
        'inline_css_breakpoints',
        'unique_inline_css',
        'inline_css_dynamic_data',
        'current_generating_element',
    ];

    public static function build(array $o, string $post_type, string $baseline_url = ''): array
    {
        $classes = array_merge(['brxe-' . $o['element']], $o['classes']);
        $links = [];
        if ($o['baseline']) {
            $classes[] = 'sfx-prose';
            if ($baseline_url !== '') {
                $links[] = $baseline_url;
            }
        }

        $css = '';
        try {
            $compiled = $o['classes'] === [] ? '' : self::compile($o['classes'], $o['element']);
            if ($compiled !== '') {
                $fonts = self::font_links($compiled);
                $css = (string) \Bricks\Integrations\Block_Editor::scope_css_for_gutenberg($compiled);
                $links = array_merge($links, $fonts);
            }
        } catch (\Throwable $e) {
            $css = '';
        }

        $css .= self::title_rule($o['title_gap']);
        $css = (string) apply_filters('sfx_editor_prose_css', $css, $post_type);

        return ['classes' => $classes, 'css' => $css, 'links' => array_values(array_unique($links))];
    }

    public static function compile(array $names, string $element): string
    {
        if (!self::bricks_api_available()) {
            return '';
        }

        $map = [];
        $ids = self::ids_by_name();
        foreach ($names as $name) {
            if (isset($ids[$name])) {
                $map[$ids[$name]] = [$element];
            }
        }
        if ($map === []) {
            return '';
        }

        $saved = [];
        foreach (self::ASSET_STATICS as $prop) {
            $saved[$prop] = \Bricks\Assets::$$prop;
        }

        try {
            \Bricks\Assets::$global_classes_elements = $map;
            return (string) \Bricks\Assets::generate_global_classes('sfx_editor_prose');
        } finally {
            foreach ($saved as $prop => $value) {
                \Bricks\Assets::$$prop = $value;
            }
        }
    }

    /** Stylesheet hrefs Bricks would load for fonts used in $css; preconnect links ignored. */
    public static function font_links(string $css): array
    {
        $html = (string) \Bricks\Assets::load_webfonts($css, true);
        preg_match_all('/<link\b[^>]*>/i', $html, $tags);

        $out = [];
        foreach ($tags[0] as $tag) {
            if (preg_match('/\brel=["\']stylesheet["\']/i', $tag) && preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $href)) {
                $out[] = html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5);
            }
        }

        return $out;
    }

    public static function title_rule(string $gap): string
    {
        return $gap === '' ? '' : ".editor-styles-wrapper .editor-post-title { margin-block-end: {$gap}; }\n";
    }

    /** Configured names with no Bricks global class of that name (all of them when Bricks is absent). */
    public static function missing_classes(array $names): array
    {
        // Name lookup needs only Bricks' class data, not the whole compiler API.
        $ids = class_exists('Bricks\Database') && property_exists('Bricks\Database', 'global_data') ? self::ids_by_name() : [];

        return array_values(array_filter($names, static fn($n) => !isset($ids[$n])));
    }

    private static function bricks_api_available(): bool
    {
        if (!class_exists('Bricks\Database') || !class_exists('Bricks\Assets')
            || !class_exists('Bricks\Integrations\Block_Editor')
            || !method_exists('Bricks\Assets', 'generate_global_classes')
            || !method_exists('Bricks\Assets', 'load_webfonts')
            || !method_exists('Bricks\Integrations\Block_Editor', 'scope_css_for_gutenberg')
            || !property_exists('Bricks\Database', 'global_data')) {
            return false;
        }
        foreach (self::ASSET_STATICS as $prop) {
            if (!property_exists('Bricks\Assets', $prop)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,string> class name => id */
    private static function ids_by_name(): array
    {
        $out = [];
        foreach ((array) (\Bricks\Database::$global_data['globalClasses'] ?? []) as $class) {
            if (is_array($class) && is_string($class['name'] ?? null) && !empty($class['id']) && !isset($out[$class['name']])) {
                $out[$class['name']] = (string) $class['id'];
            }
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/editor-prose-payload-test.php`
Expected: `editor-prose-payload-test: PASS`

- [ ] **Step 5: Commit**

```bash
git add inc/EditorProse/Payload.php tests/support/editor-prose-bricks-stubs.php tests/support/editor-prose-bricks-stubs-partial.php tests/editor-prose-payload-test.php
git commit -m "WIP: editor-prose payload"
```

---

### Task 3: Editor script — canvas sync

**Files:**
- Create: `inc/EditorProse/assets/editor-prose.js`
- Test: `tests/editor-prose-test.mjs`

**Interfaces:**
- Consumes: `window.sfxEditorProseConfig = {classes: string[], css: string, links: string[]}` (set by Task 5 before the script).
- Produces: `window.SFXEditorProse = {LAYER_ORDER, ensureClasses(el, classes): boolean, ensureAssets(doc, css, links): boolean, start(win, config): {sync}}`. Auto-starts only when `window.sfxEditorProseConfig` exists.

- [ ] **Step 1: Write the failing test**

`tests/editor-prose-test.mjs`:

```js
/**
 * Pin the Editor Prose canvas script: classes added once and never looped on,
 * style + links inserted once per document in order, a root that mounts late is
 * picked up by the per-document observer, and a replaced iframe document is
 * handled. A wiring test against a stand-in DOM in a vm context; the browser
 * verification covers real React re-renders.
 *
 * Run: node tests/editor-prose-test.mjs
 */

import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const SCRIPT = readFileSync(join(ROOT, 'inc/EditorProse/assets/editor-prose.js'), 'utf8');

class ClassList {
  constructor() { this.set = []; this.adds = 0; }
  contains(c) { return this.set.includes(c); }
  add(c) { this.adds++; if (!this.set.includes(c)) this.set.push(c); }
}
class El {
  constructor(tag) { this.tagName = tag; this.classList = new ClassList(); this.children = []; this.attrs = {}; this.listeners = {}; this.id = ''; this.textContent = ''; }
  appendChild(c) { this.children.push(c); return c; }
  setAttribute(k, v) { this.attrs[k] = v; }
  addEventListener(t, f) { (this.listeners[t] ||= []).push(f); }
}
class Doc {
  constructor() { this.head = new El('head'); this.body = new El('body'); this.documentElement = new El('html'); this.root = null; this.frame = null; this.defaultView = null; }
  createElement(t) { return new El(t); }
  getElementById(id) { return this.head.children.find((c) => c.id === id) || null; }
  querySelector(sel) {
    if (sel === '.is-root-container') return this.root;
    if (sel === 'iframe[name="editor-canvas"]') return this.frame;
    return null;
  }
}
function makeWin(doc, observers) {
  const win = {
    document: doc,
    MutationObserver: class { constructor(cb) { this.cb = cb; } observe(target, opts) { observers.push({ target, opts, cb: this.cb }); } },
  };
  doc.defaultView = win;
  return win;
}
function load(extra = {}) {
  const observers = [];
  const doc = new Doc();
  const win = makeWin(doc, observers);
  Object.assign(win, extra);
  const ctx = createContext({ window: win, WeakSet });
  runInContext(SCRIPT, ctx);
  return { win, doc, observers, api: win.SFXEditorProse };
}

// 1. Layer order constant equals the theme's frontend declaration.
{
  const { api } = load();
  const styles = readFileSync(join(ROOT, 'assets/css/frontend/styles.css'), 'utf8');
  const first = styles.match(/@layer[^;{]+;/)[0];
  assert.equal(api.LAYER_ORDER, first, '1: layer order pinned to styles.css');
}

// 2. ensureClasses adds missing, keeps existing, reports no change when complete.
{
  const { api } = load();
  const el = new El('div');
  el.classList.add('is-root-container');
  assert.equal(api.ensureClasses(el, ['brxe-text', 'prose']), true, '2: first call changes');
  assert.deepEqual(el.classList.set, ['is-root-container', 'brxe-text', 'prose'], '2: classes added, existing kept');
  const adds = el.classList.adds;
  assert.equal(api.ensureClasses(el, ['brxe-text', 'prose']), false, '2: second call no change');
  assert.equal(el.classList.adds, adds, '2: no classList.add when complete (no observer loop)');
}

// 3. ensureAssets: style (layer order + css) first, then links in order; once per document.
{
  const { api } = load();
  const d = new Doc();
  assert.equal(api.ensureAssets(d, '.x{}', ['a.css', 'b.css']), true, '3: first insert');
  assert.equal(d.head.children.length, 3, '3: style + 2 links');
  assert.equal(d.head.children[0].id, 'sfx-editor-prose', '3: style first');
  assert.equal(d.head.children[0].textContent, api.LAYER_ORDER + '\n.x{}', '3: style text');
  assert.deepEqual(d.head.children.slice(1).map((l) => [l.rel, l.href]), [['stylesheet', 'a.css'], ['stylesheet', 'b.css']], '3: links in order');
  assert.equal(api.ensureAssets(d, '.x{}', ['a.css']), false, '3: second call no-op');
  assert.equal(d.head.children.length, 3, '3: nothing duplicated');
}

// 4. start(): no iframe -> nothing; iframe without root -> observer attached first; root mounting later is picked up.
{
  const { win, doc, observers, api } = load();
  const config = { classes: ['brxe-text', 'prose'], css: '.p{}', links: [] };
  const { sync } = api.start(win, config);
  assert.equal(observers.length, 1, '4: body observer only');
  assert.equal(observers[0].target, doc.body, '4: observes admin body');

  const frame = new El('iframe');
  const cdoc = new Doc();
  makeWin(cdoc, observers);
  frame.contentDocument = cdoc;
  doc.frame = frame;
  observers[0].cb(); // the admin-body observer notices the iframe mounting
  assert.equal(observers.length, 2, '4: canvas document observed before root exists');
  assert.equal(observers[1].target, cdoc.documentElement, '4: observes canvas documentElement');
  // JSON: the options object comes from the vm realm, so a strict deep-equal would fail on its prototype.
  assert.equal(JSON.stringify(observers[1].opts), JSON.stringify({ childList: true, subtree: true, attributes: true, attributeFilter: ['class'] }), '4: observer options');
  assert.equal(cdoc.head.children.length, 0, '4: nothing inserted without root');
  assert.equal((frame.listeners.load || []).length, 1, '4: load listener added once');

  cdoc.root = new El('div');
  observers[1].cb();
  assert.deepEqual(cdoc.root.classList.set, ['brxe-text', 'prose'], '4: late root gets classes');
  assert.equal(cdoc.head.children[0].id, 'sfx-editor-prose', '4: style inserted');
  sync();
  assert.equal(observers.length, 2, '4: no second observer for the same document');
  assert.equal((frame.listeners.load || []).length, 1, '4: no second load listener');
}

// 5. Replaced iframe document gets its own observer and assets; null contentDocument is tolerated.
{
  const { win, doc, observers, api } = load();
  const { sync } = api.start(win, { classes: ['brxe-text'], css: '', links: [] });
  const frame = new El('iframe');
  frame.contentDocument = null;
  doc.frame = frame;
  sync();
  assert.equal(observers.length, 1, '5: null document -> no observer');
  const d2 = new Doc();
  makeWin(d2, observers);
  d2.root = new El('div');
  frame.contentDocument = d2;
  frame.listeners.load[0]();
  assert.equal(observers.length, 2, '5: new document observed');
  assert.deepEqual(d2.root.classList.set, ['brxe-text'], '5: new document root classed');
  assert.equal(d2.head.children[0].id, 'sfx-editor-prose', '5: new document gets the style');

  // A second, different document in the same iframe (reload / device switch).
  const d3 = new Doc();
  makeWin(d3, observers);
  d3.root = new El('div');
  frame.contentDocument = d3;
  frame.listeners.load[0]();
  assert.equal(observers.length, 3, '5: replacement document observed');
  assert.deepEqual(d3.root.classList.set, ['brxe-text'], '5: replacement root classed');
  assert.equal(d3.head.children[0].id, 'sfx-editor-prose', '5: replacement document gets the style');
  assert.equal(d2.head.children.length, 1, '5: old document untouched by the replacement');
}

// 6. Auto-start only with config present.
{
  const { observers } = load();
  assert.equal(observers.length, 0, '6: no config -> not started');
  const started = load({ sfxEditorProseConfig: { classes: [], css: '', links: [] } });
  assert.equal(started.observers.length, 1, '6: config -> started');
}

console.log('editor-prose-test: PASS');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node tests/editor-prose-test.mjs`
Expected: `ENOENT … inc/EditorProse/assets/editor-prose.js`.

- [ ] **Step 3: Write the implementation**

`inc/EditorProse/assets/editor-prose.js`:

```js
/**
 * Editor Prose — mirror the frontend prose wrapper into the block editor canvas.
 *
 * Gives the canvas root the wrapper's classes and puts the prose CSS (compiled
 * and scoped by Bricks, see Payload.php) into the canvas document only. React
 * rewrites the root's class attribute (outline/focus/preview modes) and may
 * remount the root or replace the iframe, so one idempotent sync() runs on every
 * relevant mutation.
 */
(function (root) {
  'use strict';

  // Same statement as assets/css/frontend/styles.css; tests/editor-prose-test.mjs pins it.
  var LAYER_ORDER = '@layer sfx.reset, sfx.utilities, sfx.components, sfx.theme;';
  var STYLE_ID = 'sfx-editor-prose';

  function ensureClasses(el, classes) {
    var changed = false;
    classes.forEach(function (c) {
      if (!el.classList.contains(c)) {
        el.classList.add(c);
        changed = true;
      }
    });
    return changed;
  }

  function ensureAssets(doc, css, links) {
    if (!doc.head || doc.getElementById(STYLE_ID)) {
      return false;
    }
    var style = doc.createElement('style');
    style.id = STYLE_ID;
    style.textContent = LAYER_ORDER + '\n' + css;
    doc.head.appendChild(style);
    links.forEach(function (href) {
      var link = doc.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      link.setAttribute('data-sfx-editor-prose', '');
      doc.head.appendChild(link);
    });
    return true;
  }

  function start(win, config) {
    var doc = win.document;
    var seenFrames = new WeakSet();
    var seenDocs = new WeakSet();

    // ponytail: runs on every admin-body mutation; it is two querySelector calls, cheap enough.
    function sync() {
      var frame = doc.querySelector('iframe[name="editor-canvas"]');
      if (!frame) {
        return;
      }
      if (!seenFrames.has(frame)) {
        seenFrames.add(frame);
        frame.addEventListener('load', sync);
      }
      var cdoc = frame.contentDocument;
      if (!cdoc || !cdoc.documentElement) {
        return;
      }
      if (!seenDocs.has(cdoc)) {
        seenDocs.add(cdoc);
        var Observer = (cdoc.defaultView || win).MutationObserver;
        new Observer(sync).observe(cdoc.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
      }
      var rootEl = cdoc.querySelector('.is-root-container');
      if (!rootEl) {
        return;
      }
      ensureAssets(cdoc, config.css, config.links);
      ensureClasses(rootEl, config.classes);
    }

    new win.MutationObserver(sync).observe(doc.body, { childList: true, subtree: true });
    sync();
    return { sync: sync };
  }

  root.SFXEditorProse = { LAYER_ORDER: LAYER_ORDER, ensureClasses: ensureClasses, ensureAssets: ensureAssets, start: start };

  if (root.sfxEditorProseConfig) {
    start(root, root.sfxEditorProseConfig);
  }
})(window);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node tests/editor-prose-test.mjs`
Expected: `editor-prose-test: PASS`

- [ ] **Step 5: Commit**

```bash
git add inc/EditorProse/assets/editor-prose.js tests/editor-prose-test.mjs
git commit -m "WIP: editor-prose canvas script"
```

---

### Task 4: Baseline stylesheet `prose.css`

**Files:**
- Create: `inc/EditorProse/assets/prose.css`
- Test: `tests/editor-prose-baseline-test.mjs`

**Interfaces:**
- Produces: `inc/EditorProse/assets/prose.css`, loaded by Task 5 (frontend handle `sfx-prose`, no dependencies; canvas link). It starts with the same layer-order statement as `assets/css/frontend/styles.css`, so it does not depend on that file (which `disable_bricks_css` can drop via its `bricks-frontend` dependency).

- [ ] **Step 1: Write the failing test**

`tests/editor-prose-baseline-test.mjs`:

```js
/**
 * Pin the structural contract of the Editor Prose baseline (spec "Baseline"):
 * the sfx layer-order statement first (identical to styles.css), then exactly one
 * `@layer sfx.components` block and nothing else; every rule inside it anchored on
 * `:where(.sfx-prose)`, Bricks elements excluded from every descendant rule, no
 * nested at-rules, no !important, no rem literals, every var() with a fallback.
 * The checker is run against broken fixtures too, so it cannot pass vacuously.
 * Visual behaviour is covered by the browser verification.
 *
 * Run: node tests/editor-prose-baseline-test.mjs
 */

import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const SOURCE = readFileSync(join(ROOT, 'inc/EditorProse/assets/prose.css'), 'utf8');
const ORDER = readFileSync(join(ROOT, 'assets/css/frontend/styles.css'), 'utf8').match(/@layer[^;{]+;/)[0];
const EXCLUDE = ':not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *))';

function splitTop(list) {
  const out = []; let d = 0, cur = '';
  for (const ch of list) { if (ch === '(') d++; if (ch === ')') d--; if (ch === ',' && d === 0) { out.push(cur.trim()); cur = ''; continue; } cur += ch; }
  if (cur.trim()) out.push(cur.trim());
  return out;
}

function checkVars(text, where) {
  let i = text.indexOf('var(');
  while (i !== -1) {
    let d = 0, j = i + 3, args = '';
    for (; j < text.length; j++) {
      const c = text[j];
      if (c === '(') d++;
      if (c === ')') { d--; if (d === 0) break; }
      if (d >= 1 && !(d === 1 && c === '(')) args += c;
    }
    const parts = splitTop(args);
    if (parts.length < 2 || parts.slice(1).join(',').trim() === '') throw new Error(`var without fallback in ${where}: var(${args})`);
    checkVars(parts.slice(1).join(','), where); // any var() inside the fallback, at any position
    i = text.indexOf('var(', j + 1);
  }
}

/** Throws on the first violation; returns the rule count. */
function check(raw) {
  let css = raw.replace(/\/\*[\s\S]*?\*\//g, '').trim();
  if (!css.startsWith(ORDER)) throw new Error('layer-order statement missing or not first');
  css = css.slice(ORDER.length).trim();
  if (!css.startsWith('@layer sfx.components {')) throw new Error('second statement is not the sfx.components block');
  // Find the matching close of the layer block; nothing may follow it.
  let d = 0, end = -1;
  for (let k = 0; k < css.length; k++) { if (css[k] === '{') d++; if (css[k] === '}') { d--; if (d === 0) { end = k; break; } } }
  if (end === -1) throw new Error('unbalanced braces');
  if (css.slice(end + 1).trim() !== '') throw new Error('content outside the sfx.components block');
  const inner = css.slice('@layer sfx.components {'.length, end);

  let count = 0, pos = 0;
  while (pos < inner.length) {
    const open = inner.indexOf('{', pos);
    if (open === -1) { if (inner.slice(pos).trim() !== '') throw new Error('stray text in layer'); break; }
    const prelude = inner.slice(pos, open).trim();
    const close = inner.indexOf('}', open);
    const body = inner.slice(open + 1, close);
    if (close === -1 || body.includes('{')) throw new Error(`nested block in ${prelude}`);
    if (prelude.startsWith('@')) throw new Error(`at-rule inside the layer: ${prelude}`);
    for (const sel of splitTop(prelude)) {
      if (!sel.startsWith(':where(.sfx-prose)')) throw new Error(`selector not anchored: ${sel}`);
      if (sel !== ':where(.sfx-prose)' && !sel.replace(/::[a-z-]+$/, '').endsWith(EXCLUDE)) throw new Error(`Bricks elements not excluded: ${sel}`);
    }
    if (/!\s*important/i.test(body)) throw new Error(`!important in ${prelude}`);
    if (/\d(\.\d+)?rem\b/.test(body)) throw new Error(`rem literal in ${prelude}`);
    checkVars(body, prelude);
    count++;
    pos = close + 1;
  }
  return count;
}

// 1. The real file passes and has the expected size.
const n = check(SOURCE);
assert.ok(n >= 20, `1: rules found (${n})`);

// 2. The checker rejects each kind of violation (so a pass above means something).
const bad = {
  'rule outside the layer': SOURCE + '\nbody { color: red !important; }',
  'second layer': SOURCE + '\n@layer sfx.theme { :where(.sfx-prose) p' + EXCLUDE + ' { color: red; } }',
  'unanchored selector': SOURCE.replace(':where(.sfx-prose) hr:', 'hr:'),
  'missing exclusion': SOURCE.replace(':where(.sfx-prose) hr' + EXCLUDE, ':where(.sfx-prose) hr'),
  'important': SOURCE.replace('cursor: pointer;', 'cursor: pointer !important;'),
  'IMPORTANT spaced': SOURCE.replace('cursor: pointer;', 'cursor: pointer ! IMPORTANT;'),
  'rem literal': SOURCE.replace('padding: 0.1em 0.3em;', 'padding: 0.1rem 0.3em;'),
  'var without fallback': SOURCE.replace('var(--text-body, inherit)', 'var(--text-body)'),
  'inner var without fallback': SOURCE.replace('var(--link, var(--primary, currentColor))', 'var(--link, var(--primary))'),
  'nested at-rule': SOURCE.replace('@layer sfx.components {', '@layer sfx.components {\n@media (min-width: 1px) { :where(.sfx-prose) { color: red; } }'),
  'order statement missing': SOURCE.replace(ORDER, ''),
};
for (const [label, text] of Object.entries(bad)) {
  assert.notEqual(text, SOURCE, `2: fixture "${label}" actually changes the file`);
  assert.throws(() => check(text), `2: checker rejects "${label}"`);
}

// 3. Link rules exclude button links.
const linkPreludes = SOURCE.split('{').map((x) => x.split('}').pop().trim()).filter((p) => /\) a(:|$)/.test(p));
assert.ok(linkPreludes.length >= 2, '3: link and hover rules present');
for (const p of linkPreludes) assert.ok(p.includes(':not(.wp-block-button__link, .wp-element-button)'), `3: button links excluded in ${p}`);

console.log('editor-prose-baseline-test: PASS');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node tests/editor-prose-baseline-test.mjs`
Expected: `ENOENT … inc/EditorProse/assets/prose.css`.

- [ ] **Step 3: Write the implementation**

`inc/EditorProse/assets/prose.css` (every descendant selector ends with the exclusion; pseudo-elements after it):

```css
/* Editor Prose baseline (opt-in: class `sfx-prose` on the Bricks element that wraps
   the content). Layered on purpose: everything unlayered — Bricks theme styles, the
   site's prose class, Core Framework — wins without !important. Values read the
   site's tokens, then Core Framework's, then a literal (em/inherit, never rem:
   Bricks' default root is 62.5 %). Bricks elements inside the prose area get no
   declarations from here. Block spacing is Bricks' contextual spacing, not ours.
   The order statement repeats assets/css/frontend/styles.css so the sfx.* order is
   the same whichever file loads first (this one has no dependency on that file). */
@layer sfx.reset, sfx.utilities, sfx.components, sfx.theme;

@layer sfx.components {

  :where(.sfx-prose) {
    font-size: var(--text-m, inherit);
    line-height: var(--body-line-height, var(--line-height-m, 1.6));
    color: var(--text-body, inherit);
  }

  :where(.sfx-prose) :is(h1, h2, h3, h4, h5, h6):not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    color: var(--text-title, inherit);
    font-family: var(--heading-font-family, inherit);
    font-weight: var(--heading-font-weight, 700);
    line-height: var(--line-height-s, 1.2);
  }

  :where(.sfx-prose) a:not(.wp-block-button__link, .wp-element-button):not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    color: var(--link, var(--primary, currentColor));
    text-decoration: underline;
    text-underline-offset: 0.15em;
  }

  :where(.sfx-prose) a:hover:not(.wp-block-button__link, .wp-element-button):not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    color: var(--link-hover, var(--link, var(--primary, currentColor)));
  }

  :where(.sfx-prose) :is(strong, b):not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    font-weight: var(--bold-font-weight, 700);
  }

  :where(.sfx-prose) :is(ul, ol):not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    padding-inline-start: var(--list-indent, 1.5em);
  }

  :where(.sfx-prose) li:not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *))::marker {
    color: var(--primary, currentColor);
  }

  :where(.sfx-prose) blockquote:not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    margin-inline: 0;
    padding-inline-start: var(--quote-padding-inline, var(--space-m, 1.5em));
    border-inline-start: 3px solid var(--primary, currentColor);
    font-weight: var(--quote-font-weight, inherit);
  }

  :where(.sfx-prose) blockquote :is(cite, .wp-block-quote__citation):not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    display: block;
    font-style: normal;
    font-size: var(--caption-font-size, var(--text-s, 0.875em));
    color: var(--text-muted, var(--muted, currentColor));
  }

  :where(.sfx-prose) figure:not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    margin-inline: 0;
  }

  :where(.sfx-prose) img:not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    max-inline-size: 100%;
    block-size: auto;
  }

  :where(.sfx-prose) figcaption:not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    margin-block-start: var(--caption-gap, var(--space-2xs, 0.5em));
    font-size: var(--caption-font-size, var(--text-s, 0.875em));
    color: var(--caption-color, var(--text-muted, var(--muted, currentColor)));
  }

  :where(.sfx-prose) table:not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    inline-size: 100%;
    border-collapse: collapse;
    font-size: var(--table-font-size, var(--text-s, 0.875em));
  }

  :where(.sfx-prose) :is(th, td):not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    padding: var(--space-2xs, 0.5em);
    border-block-end: 1px solid var(--border-primary, color-mix(in srgb, currentColor 20%, transparent));
    text-align: start;
  }

  :where(.sfx-prose) th:not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    font-weight: var(--table-head-font-weight, 700);
  }

  :where(.sfx-prose) tbody tr:nth-child(2n):not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    background-color: var(--table-row-bg-alt, var(--subtle, transparent));
  }

  :where(.sfx-prose) code:not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    padding: 0.1em 0.3em;
    border-radius: var(--radius-s, 0.25em);
    background-color: var(--subtle, color-mix(in srgb, currentColor 8%, transparent));
    font-size: 0.9em;
  }

  :where(.sfx-prose) pre:not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    padding: var(--space-s, 1em);
    border-radius: var(--radius-s, 0.25em);
    background-color: var(--subtle, color-mix(in srgb, currentColor 8%, transparent));
    overflow-x: auto;
  }

  :where(.sfx-prose) pre code:not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    padding: 0;
    background: none;
  }

  :where(.sfx-prose) hr:not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    border: 0;
    border-block-start: 1px solid var(--border-primary, color-mix(in srgb, currentColor 20%, transparent));
  }

  :where(.sfx-prose) summary:not(:where(.sfx-prose [class*="brxe-"], .sfx-prose [class*="brxe-"] *)) {
    font-weight: var(--bold-font-weight, 700);
    cursor: pointer;
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node tests/editor-prose-baseline-test.mjs`
Expected: `editor-prose-baseline-test: PASS`

- [ ] **Step 5: Commit**

```bash
git add inc/EditorProse/assets/prose.css tests/editor-prose-baseline-test.mjs
git commit -m "WIP: editor-prose baseline css"
```

---

### Task 5: Controller and admin page

**Files:**
- Create: `inc/EditorProse/Controller.php`, `inc/EditorProse/AdminPage.php`
- Test: `tests/editor-prose-controller-test.php` (hooks called directly against WordPress stand-ins); the booted editor is Task 8.

**Interfaces:**
- Consumes: `Settings::get()`, `Settings::applies_to()`, `Settings::bricks_ok()` (Task 1); `Payload::build()`, `Payload::missing_classes()` (Task 2); `editor-prose.js` (Task 3); `prose.css` (Task 4).
- Produces: `SFX\EditorProse\Controller::get_feature_config(): array` (auto-discovered), handles `sfx-editor-prose` (script) and `sfx-prose` (style); `AdminPage::$menu_slug = 'sfx-editor-prose'`.

- [ ] **Step 0: Write the failing controller test**

`tests/editor-prose-controller-test.php`:

```php
<?php

declare(strict_types=1);

namespace SFX {
    class AccessControl
    {
        public static function can_access_theme_settings(): bool { return true; }
        public static function die_if_unauthorized_theme(): void {}
    }
}

namespace {
    define('BRICKS_VERSION', '2.4.2');

    $test_options = [];
    $enqueued_scripts = [];
    $inline_scripts = [];
    $enqueued_styles = [];
    $screen = null;
    $css_suffix = '';

    function get_option($name, $default = false) { global $test_options; return array_key_exists($name, $test_options) ? $test_options[$name] : $default; }
    function add_action($hook, $cb, $p = 10, $a = 1) { return true; }
    function register_setting($g, $n, $a = []) { return true; }
    function post_type_exists($pt) { return in_array($pt, ['post', 'page'], true); }
    function use_block_editor_for_post_type($pt) { return true; }
    function is_admin() { return true; }
    function get_current_screen() { global $screen; return $screen; }
    function get_the_ID() { return 7; }
    function get_post_type($id) { return 'post'; }
    function get_stylesheet_directory() { return dirname(__DIR__); }
    function get_stylesheet_directory_uri() { return 'https://site.test/wp-content/themes/sfx-bricks-child'; }
    function add_query_arg($k, $v, $url) { return $url . '?' . $k . '=' . $v; }
    function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); } // WordPress passes flags through
    function apply_filters($hook, $value, ...$args) { global $css_suffix; return $hook === 'sfx_editor_prose_css' ? $value . $css_suffix : $value; }
    function wp_enqueue_script($h, $src, $deps, $ver, $footer) { global $enqueued_scripts; $enqueued_scripts[$h] = [$src, $deps, $footer]; }
    function wp_add_inline_script($h, $js, $pos) { global $inline_scripts; $inline_scripts[$h] = [$js, $pos]; }
    function wp_enqueue_style($h, $src, $deps, $ver) { global $enqueued_styles; $enqueued_styles[$h] = [$src, $deps]; }
    function __($t, $d = null) { return $t; }
    function esc_html__($t, $d = null) { return htmlspecialchars($t); }
    function esc_html_e($t, $d = null) { echo htmlspecialchars($t); }
    function esc_html($t) { return htmlspecialchars((string) $t); }
    function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
    function settings_fields($g) { echo '<!--fields:' . $g . '-->'; }
    function submit_button() { echo '<button>save</button>'; }
    function selected($a, $b) { echo $a === $b ? ' selected' : ''; }
    function checked($c) { echo $c ? ' checked' : ''; }
    function get_post_types($args, $out) { return [(object) ['name' => 'post', 'labels' => (object) ['singular_name' => 'Post']]]; }

    require_once __DIR__ . '/../inc/EditorProse/Settings.php';
    require_once __DIR__ . '/../inc/EditorProse/Payload.php';
    require_once __DIR__ . '/../inc/EditorProse/AdminPage.php';
    require_once __DIR__ . '/../inc/EditorProse/Controller.php';

    use SFX\EditorProse\AdminPage;
    use SFX\EditorProse\Controller;

    function assert_true($cond, string $message): void
    {
        if (!$cond) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    }
    function reset_state(array $options, string $base = 'post'): void
    {
        global $test_options, $enqueued_scripts, $inline_scripts, $enqueued_styles, $screen;
        $test_options = ['sfx_editor_prose_options' => $options];
        $enqueued_scripts = $inline_scripts = $enqueued_styles = [];
        $screen = new class($base) {
            public $base;
            public function __construct($b) { $this->base = $b; }
            public function is_block_editor() { return true; }
        };
    }

    // 1. Module on, nothing configured -> editor untouched (Review Focus 1).
    reset_state([]);
    Controller::enqueue_editor();
    assert_true($enqueued_scripts === [] && $inline_scripts === [], '1: no script without classes or baseline');

    // 2. Not the post editor (widgets / site editor) -> untouched.
    reset_state(['classes' => 'prose'], 'widgets');
    Controller::enqueue_editor();
    assert_true($enqueued_scripts === [], '2: widgets screen ignored');

    // 3. Configured -> script in the footer plus config before it; CSS that tries to end the
    //    script element is serialized safely and decodes losslessly (Review Focus 5).
    reset_state(['classes' => 'prose', 'title_gap' => '1em']);
    $css_suffix = '<!--<script></script><script>alert(1)</script>&';
    Controller::enqueue_editor();
    $css_suffix = '';
    assert_true(isset($enqueued_scripts['sfx-editor-prose']) && $enqueued_scripts['sfx-editor-prose'][2] === true, '3: script enqueued in footer');
    [$js, $pos] = $inline_scripts['sfx-editor-prose'];
    assert_true($pos === 'before', '3: config before the script');
    assert_true(strpbrk($js, '<>') === false, '3: no literal < or > in the inline script');
    $json = substr($js, strlen('window.sfxEditorProseConfig = '), -1);
    $config = json_decode($json, true);
    assert_true($config['classes'] === ['brxe-text', 'prose'], '3: classes in config');
    assert_true(str_ends_with($config['css'], '<!--<script></script><script>alert(1)</script>&'), '3: css decodes losslessly');

    // 3b. Excluded post type and non-block-editor screen -> untouched.
    reset_state(['classes' => 'prose', 'all_post_types' => '0', 'post_types' => ['page']]);
    Controller::enqueue_editor();
    assert_true($enqueued_scripts === [], '3b: post type not selected');
    reset_state(['classes' => 'prose']);
    $screen = new class { public $base = 'post'; public function is_block_editor() { return false; } };
    Controller::enqueue_editor();
    assert_true($enqueued_scripts === [], '3b: classic editor screen ignored');

    // 4. Baseline: editor config carries the versioned prose.css link; frontend enqueues it without dependencies.
    reset_state(['baseline' => '1']);
    Controller::enqueue_editor();
    $config = json_decode(substr($inline_scripts['sfx-editor-prose'][0], strlen('window.sfxEditorProseConfig = '), -1), true);
    assert_true(str_starts_with($config['links'][0], 'https://site.test/wp-content/themes/sfx-bricks-child/inc/EditorProse/assets/prose.css?ver='), '4: baseline link in editor config');
    Controller::enqueue_frontend();
    assert_true(isset($enqueued_styles['sfx-prose']) && $enqueued_styles['sfx-prose'][1] === [], '4: frontend baseline enqueued without dependencies');
    reset_state(['classes' => 'prose']);
    Controller::enqueue_frontend();
    assert_true(!isset($enqueued_styles['sfx-prose']), '4: no frontend baseline when off');

    // 5. Settings page: hidden "0" inputs precede both checkboxes (Review Focus 4); missing class named (Review Focus 2).
    reset_state(['classes' => 'typo', 'baseline' => '1']);
    ob_start();
    AdminPage::render_page();
    $html = (string) ob_get_clean();
    foreach (['all_post_types', 'baseline'] as $key) {
        $hidden = strpos($html, 'type="hidden" name="sfx_editor_prose_options[' . $key . ']" value="0"');
        $box = strpos($html, 'type="checkbox" name="sfx_editor_prose_options[' . $key . ']" value="1"');
        assert_true($hidden !== false && $box !== false && $hidden < $box, "5: hidden 0 before the {$key} checkbox");
    }
    assert_true(strpos($html, 'No Bricks global class with this name: typo') !== false, '5: missing class named');
    assert_true(strpos($html, 'sfx-prose') !== false, '5: baseline hint shown');

    echo "editor-prose-controller-test: PASS\n";
}
```

Run: `php tests/editor-prose-controller-test.php`
Expected: fatal "Failed opening required …/inc/EditorProse/AdminPage.php".

- [ ] **Step 1: Write the controller**

`inc/EditorProse/Controller.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\EditorProse;

class Controller
{
    public function __construct()
    {
        Settings::register();
        AdminPage::register();

        add_action('enqueue_block_editor_assets', [self::class, 'enqueue_editor']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend'], 20);
    }

    public static function enqueue_editor(): void
    {
        if (!is_admin() || !function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || !method_exists($screen, 'is_block_editor') || !$screen->is_block_editor() || $screen->base !== 'post') {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            $post_id = (int) filter_input(INPUT_GET, 'post', FILTER_VALIDATE_INT);
        }
        if (!$post_id) {
            return;
        }

        $post_type = (string) get_post_type($post_id);
        $options = Settings::get();
        $bricks = defined('BRICKS_VERSION') ? (string) BRICKS_VERSION : null;
        if (!Settings::bricks_ok($bricks) || !Settings::applies_to($options, $post_type)) {
            return;
        }

        $payload = Payload::build($options, $post_type, $options['baseline'] ? self::baseline_url() : '');
        $file = get_stylesheet_directory() . '/inc/EditorProse/assets/editor-prose.js';

        wp_enqueue_script(
            'sfx-editor-prose',
            get_stylesheet_directory_uri() . '/inc/EditorProse/assets/editor-prose.js',
            [],
            (string) filemtime($file),
            true
        );
        // JSON_HEX_TAG: no literal < or > reaches the inline <script>, so neither "</script" nor
        // "<!--<script" in class CSS can end the element or confuse the HTML parser.
        wp_add_inline_script('sfx-editor-prose', 'window.sfxEditorProseConfig = ' . wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP) . ';', 'before');
    }

    public static function enqueue_frontend(): void
    {
        if (function_exists('bricks_is_builder_main') && bricks_is_builder_main()) {
            return;
        }
        if (!Settings::get()['baseline']) {
            return;
        }

        $file = get_stylesheet_directory() . '/inc/EditorProse/assets/prose.css';
        wp_enqueue_style(
            'sfx-prose',
            get_stylesheet_directory_uri() . '/inc/EditorProse/assets/prose.css',
            [], // prose.css carries its own sfx.* layer-order statement; no dependency on a handle disable_bricks_css can drop
            (string) filemtime($file)
        );
    }

    private static function baseline_url(): string
    {
        $file = get_stylesheet_directory() . '/inc/EditorProse/assets/prose.css';

        return add_query_arg('ver', (string) filemtime($file), get_stylesheet_directory_uri() . '/inc/EditorProse/assets/prose.css');
    }

    public static function get_feature_config(): array
    {
        return [
            'class' => self::class,
            'menu_slug' => AdminPage::$menu_slug,
            // Literals, so string extraction finds them. Keep in sync with AdminPage.
            'page_title' => __('Editor Prose', 'sfxtheme'),
            'description' => __('Makes the block editor show Gutenberg content like the Bricks frontend: mirrors your prose class and Bricks spacing into the editor, with an optional token-based baseline.', 'sfxtheme'),
            'activation_option_name' => 'sfx_general_options',
            'activation_option_key' => 'enable_editor_prose',
            'option_value' => true,
            'hook' => null,
            'error' => 'Missing EditorProse Controller class in theme',
        ];
    }
}
```

- [ ] **Step 2: Write the admin page**

`inc/EditorProse/AdminPage.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\EditorProse;

class AdminPage
{
    public static $menu_slug = 'sfx-editor-prose';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_submenu_page']);
    }

    public static function add_submenu_page(): void
    {
        if (!\SFX\AccessControl::can_access_theme_settings()) {
            return;
        }

        add_submenu_page(
            'sfx-theme-settings',
            __('Editor Prose', 'sfxtheme'),
            __('Editor Prose', 'sfxtheme'),
            'manage_options',
            self::$menu_slug,
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        \SFX\AccessControl::die_if_unauthorized_theme();

        $o = Settings::get();
        $name = Settings::OPTION_NAME;
        $post_types = array_filter(
            get_post_types(['show_ui' => true], 'objects'),
            static fn($pt) => !function_exists('use_block_editor_for_post_type') || use_block_editor_for_post_type($pt->name)
        );
        $bricks = defined('BRICKS_VERSION') ? (string) BRICKS_VERSION : null;
        $missing = Payload::missing_classes($o['classes']);
        // Same parsing as Bricks (admin.php should_enqueue_gutenberg_theme_styles).
        $theme_styles_off = class_exists('Bricks\Database') && filter_var(\Bricks\Database::get_setting('disableThemeStylesInBlockEditor'), FILTER_VALIDATE_BOOLEAN);
        ?>
        <div class="wrap sfx-editor-prose" style="padding: 0; font-size: 14px;">
            <div class="sfx-flex">
                <div class="sfx-col" style="width: 50%;">
                    <div class="sfx-card">
                        <h1 class="sfx-title"><?php esc_html_e('Editor Prose', 'sfxtheme'); ?></h1>

                        <?php if (!Settings::bricks_ok($bricks)) : ?>
                            <div class="notice notice-warning inline"><p><?php esc_html_e('Bricks 2.4 or newer is required. The editor is left unchanged until then.', 'sfxtheme'); ?></p></div>
                        <?php endif; ?>
                        <?php if ($missing !== []) : ?>
                            <div class="notice notice-warning inline"><p><?php
                                /* translators: %s: comma-separated class names */
                                printf(esc_html__('No Bricks global class with this name: %s', 'sfxtheme'), esc_html(implode(', ', $missing)));
                            ?></p></div>
                        <?php endif; ?>
                        <?php if ($theme_styles_off) : ?>
                            <div class="notice notice-warning inline"><p><?php esc_html_e('Bricks theme styles are disabled in the block editor (Bricks settings). Spacing and root font size cannot match the frontend.', 'sfxtheme'); ?></p></div>
                        <?php endif; ?>
                        <?php if ($o['baseline']) : ?>
                            <div class="notice notice-info inline"><p><?php esc_html_e('Baseline is on: add the class sfx-prose to the Bricks element that wraps the content.', 'sfxtheme'); ?></p></div>
                        <?php endif; ?>

                        <form method="post" action="options.php">
                            <?php settings_fields(Settings::OPTION_GROUP); ?>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><label for="sfx_ep_classes"><?php esc_html_e('Prose classes', 'sfxtheme'); ?></label></th>
                                    <td>
                                        <input type="text" class="regular-text" id="sfx_ep_classes" name="<?php echo esc_attr($name); ?>[classes]" value="<?php echo esc_attr(implode(' ', $o['classes'])); ?>" />
                                        <p class="description"><?php esc_html_e('Bricks global class names, separated by spaces or commas, in the order they appear on the frontend element.', 'sfxtheme'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sfx_ep_element"><?php esc_html_e('Wrapper element', 'sfxtheme'); ?></label></th>
                                    <td>
                                        <select id="sfx_ep_element" name="<?php echo esc_attr($name); ?>[element]">
                                            <option value="text" <?php selected($o['element'], 'text'); ?>><?php esc_html_e('Rich Text', 'sfxtheme'); ?></option>
                                            <option value="post-content" <?php selected($o['element'], 'post-content'); ?>><?php esc_html_e('Post Content', 'sfxtheme'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Post types', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="hidden" name="<?php echo esc_attr($name); ?>[all_post_types]" value="0" />
                                        <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[all_post_types]" value="1" <?php checked($o['all_post_types']); ?> /> <?php esc_html_e('All post types using the block editor', 'sfxtheme'); ?></label>
                                        <fieldset style="margin-top: 8px;">
                                            <?php foreach ($post_types as $pt) : ?>
                                                <label style="display: block;"><input type="checkbox" name="<?php echo esc_attr($name); ?>[post_types][]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $o['post_types'], true)); ?> /> <?php echo esc_html($pt->labels->singular_name); ?></label>
                                            <?php endforeach; ?>
                                        </fieldset>
                                        <p class="description"><?php esc_html_e('The list is used only when "All post types" is off.', 'sfxtheme'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Baseline', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="hidden" name="<?php echo esc_attr($name); ?>[baseline]" value="0" />
                                        <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[baseline]" value="1" <?php checked($o['baseline']); ?> /> <?php esc_html_e('Load the token-based prose baseline (frontend and editor)', 'sfxtheme'); ?></label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sfx_ep_title_gap"><?php esc_html_e('Gap below the title', 'sfxtheme'); ?></label></th>
                                    <td>
                                        <input type="text" id="sfx_ep_title_gap" name="<?php echo esc_attr($name); ?>[title_gap]" value="<?php echo esc_attr($o['title_gap']); ?>" placeholder="var(--space-m)" />
                                        <p class="description"><?php esc_html_e('Optional. A CSS value, e.g. 2rem or var(--space-m).', 'sfxtheme'); ?></p>
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button(); ?>
                        </form>
                    </div>
                </div>
                <div class="sfx-col" style="width: 50%;">
                    <div class="sfx-card">
                        <h2 class="sfx-section-title"><?php esc_html_e('How it works', 'sfxtheme'); ?></h2>
                        <ul class="sfx-tips-list">
                            <li><?php esc_html_e('Everything in the prose class — typography, lists, links, figures, tables — is mirrored into the editor. Design it in Bricks, in the class, not on the element.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Spacing between blocks comes from the Bricks theme style (contextual spacing).', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Not mirrored: rules that depend on elements outside the content, settings on the wrapper element itself, wrapper attributes, relative url() paths. Breakpoints follow the editor width; use the device preview.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Where a prose rule competes with a WordPress block style, a Bricks theme-style link colour or a component class, the editor can resolve it differently. Make such prose rules one class more specific. Details: theme README, "Editor Prose: authoring notes".', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Anyone who can edit Bricks global classes can put CSS into the editor of every post shown here. Only give that permission to people trusted with all drafts.', 'sfxtheme'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
```

- [ ] **Step 3: Run the controller test, lint and battery**

Run: `php tests/editor-prose-controller-test.php && ./quality.sh`
Expected: `editor-prose-controller-test: PASS`; `quality.sh` exits 0 (includes syntax lint and the PSR-4 case test).

- [ ] **Step 4: Commit**

```bash
git add inc/EditorProse/Controller.php inc/EditorProse/AdminPage.php tests/editor-prose-controller-test.php
git commit -m "WIP: editor-prose controller and admin page"
```

---

### Task 6: Wire into the theme — toggle, overview, import/export

**Files:**
- Modify: `inc/GeneralThemeOptions/Settings.php` (after the `enable_redirects` entry, ~line 88)
- Modify: `inc/ThemeSettingsOverview/OverviewProvider.php` (after `enable_redirects`, ~line 77)
- Modify: `tests/theme-settings-overview-provider-test.php` (after the Redirects block, ~line 148)
- Modify: `tests/support/overview-general-theme-options-settings-stub.php` (add the toggle to the stub's `get_fields()`)
- Modify: `inc/ImportExport/Controller.php` (settings groups ~line 306; `sanitize_option_value` ~line 1276)

**Interfaces:**
- Consumes: `SFX\EditorProse\Settings::OPTION_NAME`, `Settings::sanitize()`.

- [ ] **Step 1: Write the failing overview test**

Insert in `tests/theme-settings-overview-provider-test.php` after the Redirects block:

```php
// Editor Prose: listed, opt-in, switched on
reset_test_state();
$data = OverviewProvider::get_data();
assert_status($data, 'enable_editor_prose', 'inactive', 'Editor Prose module listed and inactive by default');
$test_options['sfx_general_options'] = ['enable_editor_prose' => 1];
$data = OverviewProvider::get_data();
assert_status($data, 'enable_editor_prose', 'active', 'Editor Prose module active when enabled');
// The test runs against a stub schema; pin the real toggle's declaration and default too.
$real_schema = (string) file_get_contents(dirname(__DIR__) . '/inc/GeneralThemeOptions/Settings.php');
assert_true(
    preg_match("/'id'\s*=>\s*'enable_editor_prose',[^\]]*'default'\s*=>\s*0,/s", $real_schema) === 1,
    'Editor Prose toggle declared in the real GeneralThemeOptions schema with default 0'
);
```

and in `tests/support/overview-general-theme-options-settings-stub.php`, `get_fields()`, after `enable_nav_menu_query`:

```php
            ['id' => 'enable_editor_prose', 'default' => 0],
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/theme-settings-overview-provider-test.php`
Expected: FAIL on "Editor Prose module listed and inactive by default".

- [ ] **Step 3: Add the toggle and the overview entry**

`inc/GeneralThemeOptions/Settings.php`, after the `enable_redirects` array:

```php
            [
                'id'          => 'enable_editor_prose',
                'label'       => __('Enable Editor Prose', 'sfxtheme'),
                'description' => __('Makes the block editor show Gutenberg content like the Bricks frontend (prose class, spacing, optional baseline).', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'general',
            ],
```

`inc/ThemeSettingsOverview/OverviewProvider.php`, after `'enable_redirects' => [...]`:

```php
            'enable_editor_prose' => [
                'label' => __('Editor Prose', 'sfxtheme'),
            ],
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php tests/theme-settings-overview-provider-test.php`
Expected: exits 0 with its PASS line.

- [ ] **Step 5: Import/export**

`inc/ImportExport/Controller.php`, in the settings groups after `'redirects' => [...]`:

```php
            // Imported through EditorProse\Settings::sanitize() (see sanitize_option_value).
            // Merge mode keeps existing values where the import is empty; use replace for an exact copy.
            'editor_prose' => [
                'label' => __('Editor Prose Settings', 'sfxtheme'),
                'description' => __('Prose classes, wrapper element, post types, baseline and title gap', 'sfxtheme'),
                'option_key' => 'sfx_editor_prose_options',
                'type' => 'single',
            ],
```

and at the top of `sanitize_option_value()`, before the `is_array` branch:

```php
        if ($option_key === \SFX\EditorProse\Settings::OPTION_NAME) {
            return \SFX\EditorProse\Settings::sanitize($value);
        }
```

- [ ] **Step 6: Battery and commit**

Run: `./quality.sh`
Expected: exit 0.

```bash
git add inc/GeneralThemeOptions/Settings.php inc/ThemeSettingsOverview/OverviewProvider.php tests/theme-settings-overview-provider-test.php tests/support/overview-general-theme-options-settings-stub.php inc/ImportExport/Controller.php
git commit -m "WIP: editor-prose toggle, overview, export"
```

---

### Task 7: Translations and documentation

**Files:**
- Modify: `languages/de_DE.po`, `languages/de_DE.mo`, `README.md`, `AGENTS.md`

- [ ] **Step 1: German strings**

List the new strings:

Run: `grep -rhoE "(__|esc_html__|esc_html_e|esc_attr__)\('([^'\\\\]|\\\\.)*', 'sfxtheme'\)" inc/EditorProse inc/GeneralThemeOptions/Settings.php inc/ThemeSettingsOverview/OverviewProvider.php inc/ImportExport/Controller.php | sort -u`

For each string not yet in `languages/de_DE.po` (check with `grep -F`), append a `msgid`/`msgstr` pair with a German translation in plain, jargon-free German. The catalogue mixes registers; phrase new strings neutrally without direct address where possible, as most module entries do. Keep the `/* translators: */` comment for the `%s` string as `#. translators: %s: comma-separated class names`.

- [ ] **Step 2: Compile and check**

Run: `msgfmt --check --statistics -o languages/de_DE.mo languages/de_DE.po`
Expected: no errors; `translated messages` count increased by the number of new strings, `0 untranslated` for them.

- [ ] **Step 3: README**

In `README.md`: add "Editor Prose" to the list of toggleable modules in the intro paragraph, and under `### Admin` add:

```markdown
- **Editor Prose** — the block editor shows Gutenberg content like the Bricks frontend: set the prose class(es) and wrapper element (Rich Text or Post Content) under Global Theme Settings → Editor Prose. Everything in the class is mirrored; spacing between blocks comes from the Bricks theme style. Optional token-based baseline (`sfx-prose` class) built on your Core Framework / Bricks variables. Requires Bricks 2.4+.
```

and a new section before `## Requirements`:

```markdown
## Editor Prose: authoring notes

- Put prose styling in the **Bricks global class**, not on the element: settings on the wrapper element itself (compiled to its ID) are not mirrored.
- Write the class CSS nested under the class (or `%root%`). Avoid braces inside `content:` strings — Bricks' editor scoper can break on them.
- **Not mirrored:** rules depending on elements outside the content (`body.single-post …`, variables set on a surrounding section); selectors on wrapper attributes other than class; relative `url()`s (they resolve against the admin URL); editor-only structure (`:last-child` next to the block appender, zoom-mode separators); class settings driven by dynamic data.
- **Breakpoints** follow the editor canvas width, not the content width. Use the editor's device preview to check tablet/mobile rules; percentage spacing and container queries also need the same content width.
- **Cascade differences you can meet:** the editor prefix makes prose rules one class stronger than on the frontend, so a prose rule that loses to a WordPress block style live (e.g. the large quote) can win in the editor; in Bricks' Post Content mode, theme-style link colours in the editor are more specific than live; against component classes Bricks also loads into the editor, the prose CSS always comes later. Where it matters, give the prose rule one more class of specificity.
- **Several prose classes:** list them in the order they have on the frontend element. With Bricks' Class Manager load order off, the frontend order is page-wide (first encounter anywhere on the page), so a class used earlier elsewhere can reorder them.
- A class reused on other element types while Bricks' class chaining is off gets element-specific rules there that the editor does not mirror.
- Import in **merge** mode keeps existing values where the import is empty; use **replace** for an exact copy of another site's settings.
- Requires Bricks theme styles in the block editor (Bricks setting). Options that remove Bricks or block CSS on the frontend only (WP Optimizer) make frontend and editor differ by design.
- **Trust:** whoever can edit Bricks global classes can put CSS into the editor of every covered post — give that permission only to people trusted with all drafts.
- **Baseline (`sfx-prose`):** layered and token-based (`--text-*`, `--space-*`, `--link`, `--caption-*`, `--table-*`, `--quote-*` …, each with a Core Framework token and a literal as fallback). Anything unlayered wins: the theme style, Core Framework, your prose class, WordPress block styles. Spacing between blocks and list items is the theme style's contextual spacing; with "remove default padding" on, list indent and quote padding are the theme style's job.
```

- [ ] **Step 4: AGENTS.md**

In `AGENTS.md`: add "editor prose mirroring" to the module list in "What this is", and change "the explicit exportable contracts of eleven other modules" to "twelve other modules".

Run: `grep -c "twelve" AGENTS.md; grep -c "eleven" AGENTS.md; ./quality.sh`
Expected: first count ≥ 1, second `0`; battery exit 0 (includes `prompt-artifact-paths-test.php`).

- [ ] **Step 5: Commit**

```bash
git add languages/de_DE.po languages/de_DE.mo README.md AGENTS.md
git commit -m "WIP: editor-prose i18n and docs"
```

---

### Task 8: Browser verification on the local site

No code; the spec's verification (spec "Testing"). Local site: `http://sfx-bricks-child.local` (MAMP). Content is prepared **by hand through wp-admin / the Bricks UI** as ordinary dev content — no script creates or deletes anything. Measurements run in the browser console (read-only `getComputedStyle` / `getBoundingClientRect`); record every result.

- [ ] **Step 1: Preconditions**

Confirm Bricks version: `grep -m1 Version ../bricks/style.css` → `2.4.x`. Enable the module (General Theme Options → Enable Editor Prose). In the Bricks theme style: contextual spacing non-zero (e.g. 1rem / 1.25rem / 2rem) and `html` font size `100%`. Bricks setting "theme styles in block editor" on.

- [ ] **Step 2: Settings-page states (Review Focus 1, 2, 4)**

a) Module on, settings empty: open a post in the editor → no `sfx-editor-prose` script in the page (`document.querySelector('script[id^="sfx-editor-prose"]') === null`).
b) Enter classes `prose-test typo`, save → notice "No Bricks global class with this name: prose-test, typo" (neither exists yet); after Step 3 creates `prose-test`, reload → the notice names only `typo`. Then remove `typo`.
c) Untick "All post types" and "Baseline", tick nothing else, save, reload → both unticked; the option in the database (`php -r` with `wp-load.php`, read-only `get_option('sfx_editor_prose_options')`) has `all_post_types => false`, `baseline => false`.

- [ ] **Step 3: Fixtures (by hand)**

1. Bricks global class `prose-test`: styles for `p`, `h2`, `ul`, `a`, `blockquote`, `table`, `figcaption`, a Google web font family **not used by the theme style or any component class** (so only this module can load it in the editor), a tablet-breakpoint `p` font size.
2. Second global class `prose-two` with a conflicting `p { color }`.
3. A Bricks single-post template: Rich Text element with classes `prose-test prose-two`, content = post content. (For Step 7: a Post Content element variant.)
4. A post with paragraphs, h2/h3, nested list, link, quote with citation, table, image with caption, a nested group, one Bricks component block.
5. Editor Prose settings: classes `prose-test prose-two`, element Rich Text, all post types, title gap `var(--space-m)`.

- [ ] **Step 4: Parity measurement**

Match the **viewport** widths: read the editor canvas width (`document.querySelector('iframe[name="editor-canvas"]').clientWidth`) and size a frontend window to exactly that width (DevTools device toolbar), above the largest breakpoint. After `document.fonts.ready` and the prose `FontFace` reporting `status === 'loaded'` in both documents, collect for every prose element (top level and `li`, `a`, `figcaption`, `blockquote`, `td`): computed `margin-block-start/end`, `padding`, `border`, `font-family`, `font-weight`, `font-size`, `line-height`, `color`, `text-decoration`, `list-style`, and the `getBoundingClientRect` gap between consecutive top-level blocks; the title → first paragraph gap equals `var(--space-m)` (first confirm the token resolves in the canvas: `getComputedStyle(canvasDocument.body).getPropertyValue('--space-m')` non-empty). Expected: identical except the documented limits. Repeat with the editor's tablet device preview vs a frontend viewport of the same canvas width (the tablet `p` font size applies in both).

- [ ] **Step 5: Robustness**

In the editor: toggle outline mode, switch to code editor and back, switch device preview. After each: canvas root keeps `brxe-text prose-test prose-two`; exactly one `#sfx-editor-prose` in the canvas head; the font `<link data-sfx-editor-prose>` elements still present. In the admin document (outside the canvas): no `#sfx-editor-prose`, no `link[data-sfx-editor-prose]`, no element carrying `prose-test`. On the frontend: no `sfx-editor-prose` script.

- [ ] **Step 6: Component block and ordering**

Compare the component block frontend vs editor (record any difference as a finding). Confirm `prose-two` vs `prose-test` `p` colour resolves the same in both.

- [ ] **Step 7: Post Content mode**

Switch the template to a Post Content element with the same classes and the setting to Post Content; repeat Step 4 once.

- [ ] **Step 8: Baseline**

Settings: baseline on, no prose classes. Template wrapper classes: `sfx-prose` only. Post content adds `strong`, inline `code`, `pre`, `hr`, a `details`/`summary`, a nested list, a table with ≥ 4 body rows, a link (hover it via DevTools `:hov`). In Bricks variables set distinctive values for the tokens the baseline reads (e.g. `--caption-color: rgb(1, 2, 3)`, `--table-row-bg-alt: rgb(4, 5, 6)`, `--link: rgb(7, 8, 9)`, `--bold-font-weight: 800`).

(No Custom HTML block: the editor renders it in its own sandbox iframe, out of the canvas' reach. Table checks use the table block and only properties WordPress' table-block CSS does not set — `font-size`, `th` weight, alternating-row background; cell padding and borders are the block style's by design.)

a) **Counterfactual:** control state = baseline setting **off** and `sfx-prose` removed from the template wrapper (so the editor script does not re-add it); then baseline on and `sfx-prose` back. Before measuring an element in the editor, confirm its `ownerDocument` is the canvas document and `closest('.sfx-prose')` is the canvas root. For each element, every property the baseline sets whose baseline value differs from the browser default and that nothing else on the test site sets (for the table block: see above) must change between the two states, on the frontend and in the editor.
b) **Expected values:** `figcaption` colour `rgb(1, 2, 3)`, even table row background `rgb(4, 5, 6)`, link colour `rgb(7, 8, 9)`, `strong` weight `800`; frontend = editor for all compared values.
c) **Fallback:** delete `--caption-color` → `figcaption` colour equals the `--text-muted` value.
d) **Precedence:** give `prose-test` a root `color` (on the class itself) and a `figcaption { color }`, add it back to the wrapper **and** to the module's classes setting (baseline stays on) → paragraph colour follows the class root colour, not `--text-body`, and `figcaption` colour is the class's, not `--caption-color`, in both. Bricks typography set on the wrapper **element** (font size) → wins on the frontend (not mirrored in the editor — documented).
e) **Exclusion:** a nested Bricks heading and the component block → DevTools "Styles" shows no rule from `prose.css`; inherited values as on the frontend.
f) **Import sanitizing:** first turn the Editor Prose module **off** (so its own Settings-API sanitizer is not registered and cannot mask the ImportExport dispatch); Import/Export → export "Editor Prose Settings"; in the JSON set `"classes": "a b{ c"` and `"title_gap": "1rem;x"`; import in replace mode → the stored option (read-only `get_option`) has `classes => ['a', 'c']` and `title_gap => ''`. Turn the module back on.

(The `disable_bricks_css` independence is pinned by the controller test — `sfx-prose` enqueued with no dependencies. The UI toggle "Disable Bricks Styling" saves `disable_bricks_styles` while the controller reads `disable_bricks_css` — a pre-existing mismatch outside this feature; report it, do not fix it here.)

- [ ] **Step 9: Record**

Write the results (pass/fail per step, any differences) into the PR description draft; any unexpected difference becomes a fix before Gate B.

---

## Finish: Gate B and closing commit

1. `./quality.sh` green.
2. Gate B per CLAUDE.md §5: Codex review of the range `merge-base(main)..HEAD` (the WIP commits) with the findings-file protocol, `reviewType: full`, both branch files; prompt names AGENTS.md, `docs/prompt-standards.md` (the diff touches AGENTS.md, a prompt artifact), this plan and the spec, and asks the standing lens "which existing statements does this diff falsify?" (README module list, AGENTS.md counts, overview test). Minimum 3 passes; fix Blocker/Major after each as a new `WIP:` commit; the final pass must be clean.
3. If a Gate-B fix changes specified behaviour, update the spec in the same WIP series.
4. Close: `git reset --soft <parent of the first WIP commit>` (the spec/plan commits stay), then one commit `feat(editor-prose): mirror Bricks prose into the block editor, optional baseline` whose body lists what was verified (Task 8 results) — the cycle is unprofiled (no story).
5. Open a pull request; do not merge to `main` (invariant 7).

## Self-Review Notes

- Spec coverage: settings (T1), gate (T1/T5), payload steps 1–6 (T2/T5), client sync (T3), baseline + loading (T4/T5), admin page status lines (T5), toggle/overview/purge/export + import sanitize dispatch (T6), README/AGENTS/i18n (T7), browser verification incl. baseline (T8).
- Missing-API degradation: "Bricks absent" (T2 check 1) and "Bricks without `load_webfonts`" in a child process (T2 check 2).
