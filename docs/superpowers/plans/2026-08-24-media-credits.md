# Media Credits Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every media attachment a copyright notice and an AI/alteration label, and get them onto the page from a Bricks Image element.

**Architecture:** A standard `inc/<Module>/` feature module, off by default, registering nothing when off. All credit composition happens in one function, `Credit::for()`. Bricks integration works primarily by rewriting the Image element's own `captionCustom` setting before it renders — no HTML surgery except for the optional overlay mode.

**Tech Stack:** PHP 8.0+, WordPress, Bricks 2.3.x (parent theme), no new dependencies. Tests are plain PHP assert scripts run with `php tests/<file>.php`.

**Spec:** `docs/superpowers/specs/2026-08-24-media-credits-design.md` — read it alongside this plan. The plan implements it; the spec argues for it and cites the Bricks and WordPress source lines every mechanism rests on.

**Branch:** `feature/image-credit-fields` (already checked out).

## Global Constraints

- PHP 8.0+, `declare(strict_types=1)` at the top of every new PHP file, `namespace SFX\MediaCredits`.
- Every user-facing string goes through `__()` / `esc_html__()` with text domain `sfxtheme`.
- Meta keys, verbatim: `_sfx_media_copyright`, `_sfx_media_ai`, `_sfx_media_iptc_prefilled`.
- Option name, verbatim: `sfx_media_credits_options`. Feature toggle key: `enable_media_credits` inside `sfx_general_options`.
- AI label slugs, verbatim and closed: `ai_generated`, `ai_edited`, `ai_assisted`, `digitally_altered`. The empty string means "no marking".
- Filter names, verbatim: `sfx_media_credits_labels`, `sfx_media_credits_line`.
- CSS marker class, verbatim: `sfx-credit`. Overlay adds `sfx-credit--overlay`. Opt-out class: `no-credit`.
- **Escaping boundary:** every string this module puts into page content passes `Credit::escape_braces()` as its final operation. No exceptions, including tag values and filter output.
- Never delete attachment meta outside `uninstall.php`. Switching the feature off must not touch stored data.
- Follow the existing module shape: `Controller::get_feature_config()`, `AdminPage::register()`, `Settings::register()` on `sfx_init_admin_features`.

---

## File Structure

**Created:**

| File | Responsibility |
|---|---|
| `inc/MediaCredits/Controller.php` | Registers the other classes, exposes `get_feature_config()`. No logic. |
| `inc/MediaCredits/Settings.php` | Option schema, defaults, label vocabulary, normalisation used on both write and read. |
| `inc/MediaCredits/Credit.php` | The one place a credit line is composed. Brace escaping lives here. |
| `inc/MediaCredits/MediaLibrary.php` | Attachment fields, save, IPTC prefill, list column, list filter. |
| `inc/MediaCredits/Bricks.php` | Settings-time tag substitution, caption auto-output, overlay injection, dynamic tags, `data-sfx-ai`. |
| `inc/MediaCredits/AdminPage.php` | Settings page markup, seal uploader fields, tips card. |
| `inc/MediaCredits/assets/media-credits.css` | Overlay positioning and contrast. Enqueued only in overlay mode. |
| `inc/MediaCredits/assets/media-credits-admin.js` | Media-frame wiring for the four seal fields. |
| `inc/MediaCredits/index.php` | `<?php // Silence is golden.` — matches every other module directory. |
| `tests/media-credits-credit-test.php` | Settings normalisation, label map, line composition, escaping. |
| `tests/media-credits-iptc-test.php` | The one-shot IPTC prefill. |
| `tests/media-credits-bricks-test.php` | Tag resolution, substitution, caption composition, overlay injection, dedup. |
| `tests/support/media-credits-stubs.php` | WordPress doubles + assertion helpers for this suite. |
| `tests/support/media-credits-bricks-stubs.php` | `Bricks\Query` and element doubles (separate file: PHP forbids a bracketed namespace beside non-namespaced code). |

**Modified:**

| File | Change |
|---|---|
| `inc/GeneralThemeOptions/Settings.php` | One `enable_media_credits` checkbox in the `general` group. |
| `inc/ThemeSettingsOverview/OverviewProvider.php` | One entry in `build_builtin_modules_group()`. |
| `inc/ImportExport/Controller.php` | One settings group; rename the `dashboard_subset` type to `subset` accepting both. |
| `uninstall.php` | One option name, three `delete_post_meta_by_key()` calls. |

**Why this split:** `Credit.php` is separate from `Bricks.php` because it is the only file the other three consumers share, and it must be testable without any Bricks double. `MediaLibrary.php` and `Bricks.php` never call each other — admin and frontend are independent halves that happen to read the same meta.

---

### Task 1: Settings and the label vocabulary

**Files:**
- Create: `inc/MediaCredits/Settings.php`, `inc/MediaCredits/index.php`
- Create: `tests/media-credits-credit-test.php`, `tests/support/media-credits-stubs.php`
- Test: `tests/media-credits-credit-test.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Settings::OPTION_NAME`, `Settings::OPTION_GROUP`, `Settings::OUTPUT_MODES`, `Settings::CREDIT_DISPLAYS`, `Settings::ICON_SIZE_MIN`, `Settings::ICON_SIZE_MAX`, `Settings::get_default_labels(): array`, `Settings::get_labels(): array`, `Settings::get_defaults(): array`, `Settings::normalize(array $raw): array`, `Settings::sanitize_options($input): array`, `Settings::get(string $key): mixed`, `Settings::register(): void`, `Settings::register_settings(): void`.

- [ ] **Step 1: Write the stub harness**

Create `tests/support/media-credits-stubs.php`. It carries the assertion helpers and the WordPress doubles this suite needs. Fixture state is global so a test file can rewrite it between cases.

```php
<?php

declare(strict_types=1);

/**
 * Stubs for the MediaCredits suite.
 *
 * Global-namespace WordPress doubles, mutable fixture state and the assertion
 * helpers. Bricks doubles live in a sibling file because PHP forbids a
 * bracketed namespace block alongside non-namespaced code.
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

function assert_not_contains(string $needle, string $haystack, string $message): void
{
    assert_true(strpos($haystack, $needle) === false, "{$message} (needle '{$needle}' unexpectedly found)");
}

// ---------------------------------------------------------- fixture state

$test_options        = [];   // option name => value
$test_post_meta      = [];   // post id => [meta key => value]
$test_attachment_url = [];   // attachment id => url|false
$test_attachment_img = [];   // attachment id => image url|false
$test_is_image       = [];   // attachment id => bool
$test_filter_returns = [];   // filter name => callable(mixed $value, array $args): mixed
$test_filters        = [];   // hook name => list of registrations

// ------------------------------------------------------ WordPress doubles

function __($text, $domain = 'default')
{
    return $text;
}

function esc_html__($text, $domain = 'default')
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_url($url)
{
    return str_replace(['"', '<', '>'], '', (string) $url);
}

function wp_kses_post($content)
{
    // Enough for our contract: strip script tags, leave everything else,
    // and in particular leave braces alone — the real wp_kses_post() does
    // not touch them either, which is exactly why escape_braces() exists.
    return preg_replace('#<script.*?</script>#is', '', (string) $content);
}

function sanitize_text_field($str)
{
    return trim(strip_tags((string) $str));
}

function absint($value)
{
    return abs((int) $value);
}

function get_option($name, $default = false)
{
    global $test_options;

    return $test_options[$name] ?? $default;
}

function update_option($name, $value)
{
    global $test_options;

    $test_options[$name] = $value;

    return true;
}

function get_post_meta($post_id, $key = '', $single = false)
{
    global $test_post_meta;

    $value = $test_post_meta[$post_id][$key] ?? '';

    return $single ? $value : [$value];
}

function update_post_meta($post_id, $key, $value)
{
    global $test_post_meta;

    $test_post_meta[$post_id][$key] = $value;

    return true;
}

function wp_get_attachment_url($id)
{
    global $test_attachment_url;

    return $test_attachment_url[$id] ?? false;
}

function wp_get_attachment_image_url($id, $size = 'thumbnail')
{
    global $test_attachment_img;

    return $test_attachment_img[$id] ?? false;
}

function wp_attachment_is_image($id)
{
    global $test_is_image;

    return $test_is_image[$id] ?? false;
}

function apply_filters($hook, $value, ...$args)
{
    global $test_filter_returns;

    if (isset($test_filter_returns[$hook])) {
        return ($test_filter_returns[$hook])($value, $args);
    }

    return $value;
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1): bool
{
    global $test_filters;

    $test_filters[$hook][] = [
        'callback'      => $callback,
        'priority'      => $priority,
        'accepted_args' => $accepted_args,
    ];

    return true;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1): bool
{
    return add_filter($hook, $callback, $priority, $accepted_args);
}

/** Registrations recorded for one hook. */
function test_registrations(string $hook): array
{
    global $test_filters;

    return $test_filters[$hook] ?? [];
}

function test_reset(): void
{
    global $test_options, $test_post_meta, $test_attachment_url, $test_attachment_img,
           $test_is_image, $test_filter_returns, $test_filters;

    $test_options        = [];
    $test_post_meta      = [];
    $test_attachment_url = [];
    $test_attachment_img = [];
    $test_is_image       = [];
    $test_filter_returns = [];
    $test_filters        = [];
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/media-credits-credit-test.php` with the settings cases only. `Credit` cases arrive in Task 2.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/support/media-credits-stubs.php';

require dirname(__DIR__) . '/inc/MediaCredits/Settings.php';

use SFX\MediaCredits\Settings;

// ------------------------------------------------- Case 1: label vocabulary

assert_same(
    ['ai_generated', 'ai_edited', 'ai_assisted', 'digitally_altered'],
    array_keys(Settings::get_default_labels()),
    'Case 1a: the four slugs, in order'
);

// A filter that invents a key: the invention is dropped.
$GLOBALS['test_filter_returns']['sfx_media_credits_labels'] = static function ($labels) {
    return $labels + ['ai_hallucinated' => 'Erfunden'];
};
assert_same(
    ['ai_generated', 'ai_edited', 'ai_assisted', 'digitally_altered'],
    array_keys(Settings::get_labels()),
    'Case 1b: a filter cannot add a slug'
);

// A filter that drops keys: the defaults are restored, so no stored value
// loses its label. array_intersect_key() alone would leave two slugs blank.
$GLOBALS['test_filter_returns']['sfx_media_credits_labels'] = static function () {
    return ['ai_generated' => 'AI generated'];
};
$labels = Settings::get_labels();
assert_same('AI generated', $labels['ai_generated'], 'Case 1c: a filter can reword');
assert_same('KI-bearbeitet', $labels['ai_edited'], 'Case 1d: a dropped key falls back to its default wording');
assert_same(4, count($labels), 'Case 1e: the map keeps all four slugs');

// A filter returning nonsense: ignored.
$GLOBALS['test_filter_returns']['sfx_media_credits_labels'] = static function () {
    return 'not an array';
};
assert_same(4, count(Settings::get_labels()), 'Case 1f: a non-array filter return is ignored');

unset($GLOBALS['test_filter_returns']['sfx_media_credits_labels']);

// ---------------------------------------------------- Case 2: normalisation

$clean = Settings::normalize([
    'output_mode'        => 'overlay',
    'force_wrapper'      => '1',
    'credit_display'     => 'icon_text',
    'icon_size'          => '40',
    'fallback_copyright' => '  <b>Foto Müller</b>  ',
    'seal_ai_generated'  => '77',
]);

assert_same('overlay', $clean['output_mode'], 'Case 2a: a known output mode survives');
assert_same(true, $clean['force_wrapper'], 'Case 2b: the checkbox becomes a bool');
assert_same('icon_text', $clean['credit_display'], 'Case 2c: a known display mode survives');
assert_same(40, $clean['icon_size'], 'Case 2d: icon size becomes an int');
assert_same('Foto Müller', $clean['fallback_copyright'], 'Case 2e: the fallback is sanitized and trimmed');

$dirty = Settings::normalize([
    'output_mode'    => 'explode',
    'credit_display' => 'hologram',
    'icon_size'      => 9000,
]);

assert_same('off', $dirty['output_mode'], 'Case 2f: an unknown output mode falls back to the default');
assert_same('text', $dirty['credit_display'], 'Case 2g: an unknown display mode falls back to the default');
assert_same(128, $dirty['icon_size'], 'Case 2h: an oversized icon is clamped to the maximum');
assert_same(8, Settings::normalize(['icon_size' => 1])['icon_size'], 'Case 2i: a tiny icon is clamped to the minimum');
assert_same('', $dirty['fallback_copyright'], 'Case 2j: a missing key takes its default');

// Seals: only real images survive.
$GLOBALS['test_is_image'][77] = true;
$GLOBALS['test_is_image'][78] = false;
$seals = Settings::normalize(['seal_ai_generated' => 77, 'seal_ai_edited' => 78]);
assert_same(77, $seals['seal_ai_generated'], 'Case 2k: an image id is kept');
assert_same(0, $seals['seal_ai_edited'], 'Case 2l: a non-image id is discarded');

// ------------------------------------------------------- Case 3: read path
// The importer can write an option while the feature is disabled, in which
// case register_setting() never ran and nothing validated it. get() is the
// second gate, and it has to be as strict as the first.

$GLOBALS['test_options'][Settings::OPTION_NAME] = [
    'output_mode' => 'explode',
    'icon_size'   => 9000,
];

assert_same('off', Settings::get('output_mode'), 'Case 3a: get() rejects a stored value that is not a known mode');
assert_same(128, Settings::get('icon_size'), 'Case 3b: get() clamps a stored icon size');
assert_same('', Settings::get('fallback_copyright'), 'Case 3c: get() returns the default for an absent key');

$GLOBALS['test_options'][Settings::OPTION_NAME] = 'not an array';
assert_same('off', Settings::get('output_mode'), 'Case 3d: a corrupt option value degrades to defaults');

test_reset();

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all media-credits credit tests\n";
exit(0);
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php tests/media-credits-credit-test.php`
Expected: a PHP fatal error, `Failed opening required '.../inc/MediaCredits/Settings.php'`.

- [ ] **Step 4: Write the implementation**

Create `inc/MediaCredits/index.php`:

```php
<?php
// Silence is golden.
```

Create `inc/MediaCredits/Settings.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\MediaCredits;

/**
 * Option schema for the Media Credits module.
 *
 * Normalisation is deliberately one function used from two directions. On
 * write it is the registered sanitize callback; on read it runs again, because
 * an import performed while the feature was disabled never reached
 * register_setting() and therefore never reached the callback.
 */
class Settings
{
    public const OPTION_NAME  = 'sfx_media_credits_options';
    public const OPTION_GROUP = 'sfx_media_credits_options';

    public const OUTPUT_MODES     = ['off', 'caption', 'overlay'];
    public const CREDIT_DISPLAYS  = ['text', 'icon', 'icon_text'];
    public const ICON_SIZE_MIN    = 8;
    public const ICON_SIZE_MAX    = 128;
    public const ICON_SIZE_DEFAULT = 24;

    public static function register(): void
    {
        add_action('sfx_init_admin_features', [self::class, 'register_settings']);
    }

    public static function register_settings(): void
    {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            'type'              => 'array',
            'sanitize_callback' => [self::class, 'sanitize_options'],
            'default'           => self::get_defaults(),
        ]);
    }

    /**
     * The closed slug list with its default wording.
     *
     * @return array<string, string>
     */
    public static function get_default_labels(): array
    {
        return [
            'ai_generated'      => __('KI-generiert', 'sfxtheme'),
            'ai_edited'         => __('KI-bearbeitet', 'sfxtheme'),
            'ai_assisted'       => __('KI-unterstützt', 'sfxtheme'),
            'digitally_altered' => __('Digital verändert', 'sfxtheme'),
        ];
    }

    /**
     * The label map after the site filter, with the slug set enforced in both
     * directions: the intersection drops keys a filter invented, the merge
     * restores keys a filter dropped. Wording is negotiable, keys are not —
     * a slug without a label is an image that silently loses its disclosure.
     *
     * @return array<string, string>
     */
    public static function get_labels(): array
    {
        $defaults = self::get_default_labels();
        $filtered = apply_filters('sfx_media_credits_labels', $defaults);

        if (!is_array($filtered)) {
            return $defaults;
        }

        return array_merge($defaults, array_intersect_key($filtered, $defaults));
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_defaults(): array
    {
        $defaults = [
            'output_mode'        => 'off',
            'force_wrapper'      => false,
            'credit_display'     => 'text',
            'icon_size'          => self::ICON_SIZE_DEFAULT,
            'fallback_copyright' => '',
        ];

        foreach (array_keys(self::get_default_labels()) as $slug) {
            $defaults['seal_' . $slug] = 0;
        }

        return $defaults;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $defaults = self::get_defaults();

        $mode = (string) ($raw['output_mode'] ?? '');
        $display = (string) ($raw['credit_display'] ?? '');

        $clean = [
            'output_mode'        => in_array($mode, self::OUTPUT_MODES, true) ? $mode : $defaults['output_mode'],
            'force_wrapper'      => !empty($raw['force_wrapper']),
            'credit_display'     => in_array($display, self::CREDIT_DISPLAYS, true) ? $display : $defaults['credit_display'],
            'icon_size'          => max(self::ICON_SIZE_MIN, min(self::ICON_SIZE_MAX, absint($raw['icon_size'] ?? self::ICON_SIZE_DEFAULT))),
            'fallback_copyright' => sanitize_text_field((string) ($raw['fallback_copyright'] ?? '')),
        ];

        foreach (array_keys(self::get_default_labels()) as $slug) {
            $id = absint($raw['seal_' . $slug] ?? 0);
            $clean['seal_' . $slug] = ($id > 0 && wp_attachment_is_image($id)) ? $id : 0;
        }

        return $clean;
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public static function sanitize_options($input): array
    {
        return self::normalize(is_array($input) ? $input : []);
    }

    /**
     * One validated option value.
     *
     * @return mixed
     */
    public static function get(string $key)
    {
        $stored = get_option(self::OPTION_NAME, []);
        $clean  = self::normalize(is_array($stored) ? $stored : []);

        return $clean[$key] ?? null;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php tests/media-credits-credit-test.php`
Expected: `PASS: all media-credits credit tests`

`register_setting()` is not stubbed and is not called by any test — only `register_settings()` would call it, and no test invokes that. If the run fails on it, the test is calling something it should not.

- [ ] **Step 6: Commit**

```bash
git add inc/MediaCredits/Settings.php inc/MediaCredits/index.php tests/media-credits-credit-test.php tests/support/media-credits-stubs.php
git commit -m "feat(media-credits): option schema and label vocabulary"
```

---

### Task 2: `Credit::for()` — the one place a credit is composed

**Files:**
- Create: `inc/MediaCredits/Credit.php`
- Modify: `tests/media-credits-credit-test.php` (insert the new cases **before** the `// ---- epilogue` block)
- Test: `tests/media-credits-credit-test.php`

**Interfaces:**
- Consumes: `Settings::get_labels()`, `Settings::get()`.
- Produces: `Credit::META_COPYRIGHT`, `Credit::META_AI`, `Credit::META_IPTC_MARKER`, `Credit::escape_braces(string): string`, `Credit::for(int): array` with keys `copyright|ai_key|ai_label|icon_id|line`, `Credit::with_copyright_prefix(string): string`, `Credit::reset_cache(): void`.

- [ ] **Step 1: Write the failing test**

Add to `tests/media-credits-credit-test.php`. Add the require and the `use` at the top of the file alongside the existing ones:

```php
require dirname(__DIR__) . '/inc/MediaCredits/Credit.php';

use SFX\MediaCredits\Credit;
```

Then insert these cases before the epilogue:

```php
// ------------------------------------------------ Case 4: line composition

test_reset();
$GLOBALS['test_attachment_url'][5] = 'https://example.test/photo.jpg';

// Copyright only.
$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = 'Foto Müller';
Credit::reset_cache();
$credit = Credit::for(5);
assert_same('Foto Müller', $credit['copyright'], 'Case 4a: the stored copyright comes back raw in the parts');
assert_same('©&nbsp;Foto Müller', $credit['line'], 'Case 4b: the © prefix is added');
assert_same('', $credit['ai_label'], 'Case 4c: no AI key means no label');

// A notice that already carries its own mark is left alone, in all three
// spellings. Two © would look like a typo, not like diligence.
foreach (['© Foto Müller', '(c) Foto Müller', 'Copyright Foto Müller'] as $i => $text) {
    $GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = $text;
    Credit::reset_cache();
    assert_same(esc_html($text), Credit::for(5)['line'], "Case 4d{$i}: an existing mark is not doubled");
}

// Both parts.
$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = 'Foto Müller';
$GLOBALS['test_post_meta'][5][Credit::META_AI]        = 'ai_generated';
Credit::reset_cache();
assert_same('©&nbsp;Foto Müller&nbsp;·&nbsp;KI-generiert', Credit::for(5)['line'], 'Case 4e: both parts, joined');

// AI part only.
$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = '';
Credit::reset_cache();
assert_same('KI-generiert', Credit::for(5)['line'], 'Case 4f: the separator drops out when one part is empty');

// Neither part.
$GLOBALS['test_post_meta'][5][Credit::META_AI] = '';
Credit::reset_cache();
assert_same('', Credit::for(5)['line'], 'Case 4g: nothing stored, nothing composed');

// An unknown slug is not a label.
$GLOBALS['test_post_meta'][5][Credit::META_AI] = 'ai_hallucinated';
Credit::reset_cache();
$credit = Credit::for(5);
assert_same('', $credit['ai_key'], 'Case 4h: an unknown slug sanitizes to empty');
assert_same('', $credit['line'], 'Case 4i: and produces no line');

// ------------------------------------------------------ Case 5: the seal

test_reset();
$GLOBALS['test_attachment_url'][5] = 'https://example.test/photo.jpg';
$GLOBALS['test_post_meta'][5][Credit::META_AI] = 'ai_generated';
$GLOBALS['test_options'][Settings::OPTION_NAME] = [
    'credit_display'    => 'icon',
    'icon_size'         => 32,
    'seal_ai_generated' => 90,
];
$GLOBALS['test_is_image'][90] = true;
$GLOBALS['test_attachment_img'][90] = 'https://example.test/seal.svg';

Credit::reset_cache();
$line = Credit::for(5)['line'];
assert_contains('src="https://example.test/seal.svg"', $line, 'Case 5a: icon mode renders the seal');
assert_contains('alt="KI-generiert"', $line, 'Case 5b: in icon mode the alt carries the label');
assert_contains('width="32"', $line, 'Case 5c: the configured size is applied');
assert_not_contains('>KI-generiert', $line, 'Case 5d: icon mode prints no visible label text');

$GLOBALS['test_options'][Settings::OPTION_NAME]['credit_display'] = 'icon_text';
Credit::reset_cache();
$line = Credit::for(5)['line'];
assert_contains('alt=""', $line, 'Case 5e: with the label visible the seal alt is empty, or SR users hear it twice');
assert_contains('KI-generiert', $line, 'Case 5f: icon_text still prints the label');

// A seal whose attachment was deleted must not become a broken image.
$GLOBALS['test_attachment_img'][90] = false;
$GLOBALS['test_options'][Settings::OPTION_NAME]['credit_display'] = 'icon';
Credit::reset_cache();
assert_same('KI-generiert', Credit::for(5)['line'], 'Case 5g: a deleted seal falls back to the label text');

// ----------------------------------------------------- Case 6: the fallback

test_reset();
$GLOBALS['test_attachment_url'][5] = 'https://example.test/photo.jpg';
$GLOBALS['test_options'][Settings::OPTION_NAME] = ['fallback_copyright' => '© Kundenname'];

Credit::reset_cache();
assert_same('© Kundenname', Credit::for(5)['copyright'], 'Case 6a: the fallback fills an empty field');

$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = 'Foto Müller';
Credit::reset_cache();
assert_same('Foto Müller', Credit::for(5)['copyright'], 'Case 6b: a stored value beats the fallback');

// A deleted attachment gets nothing — not even the fallback. Bricks keeps the
// id and renders a placeholder; a credit there would name an owner who never
// took the picture.
$GLOBALS['test_attachment_url'][5] = false;
Credit::reset_cache();
$gone = Credit::for(5);
assert_same(true, is_array($gone), 'Case 6c: the return type does not change for a missing attachment');
assert_same('', $gone['line'], 'Case 6d: and every part is empty');
assert_same('', $gone['copyright'], 'Case 6e: the fallback does not leak onto a missing image');
assert_same(0, Credit::for(0)['icon_id'], 'Case 6f: id 0 is handled without a lookup');

// -------------------------------------------------- Case 7: escaping

test_reset();
$GLOBALS['test_attachment_url'][5] = 'https://example.test/photo.jpg';

$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = '<script>alert(1)</script>Foto';
Credit::reset_cache();
assert_not_contains('<script>', Credit::for(5)['line'], 'Case 7a: markup in the copyright is escaped');

// Braces are the load-bearing case: Bricks parses the finished document for
// dynamic tags, so an unescaped {echo:…} in a copyright field would execute.
$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = '{echo:phpinfo}';
Credit::reset_cache();
$line = Credit::for(5)['line'];
assert_not_contains('{echo:', $line, 'Case 7b: braces from the copyright field never reach the page');
assert_contains('&#123;echo:phpinfo&#125;', $line, 'Case 7c: they are entity-escaped instead');

// A filter runs AFTER composition, so escaping has to run after the filter.
$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = 'Foto';
$GLOBALS['test_filter_returns']['sfx_media_credits_line'] = static function () {
    return '{post_title}';
};
Credit::reset_cache();
assert_not_contains('{post_title}', Credit::for(5)['line'], 'Case 7d: a filter cannot reintroduce a parseable tag');
unset($GLOBALS['test_filter_returns']['sfx_media_credits_line']);

assert_same('&#123;a&#125;', Credit::escape_braces('{a}'), 'Case 7e: escape_braces is available on its own');

// ------------------------------------------------------ Case 8: memoisation

test_reset();
$GLOBALS['test_attachment_url'][5] = 'https://example.test/photo.jpg';
$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = 'First';
Credit::reset_cache();
assert_same('First', Credit::for(5)['copyright'], 'Case 8a: first read');
$GLOBALS['test_post_meta'][5][Credit::META_COPYRIGHT] = 'Second';
assert_same('First', Credit::for(5)['copyright'], 'Case 8b: the second read comes from the per-request cache');
Credit::reset_cache();
assert_same('Second', Credit::for(5)['copyright'], 'Case 8c: reset_cache is the test seam');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/media-credits-credit-test.php`
Expected: fatal error on the missing `inc/MediaCredits/Credit.php`.

- [ ] **Step 3: Write the implementation**

Create `inc/MediaCredits/Credit.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\MediaCredits;

/**
 * The single source of truth for a composed credit.
 *
 * Everything that renders a credit — the Bricks tags, caption auto-output,
 * overlay auto-output — goes through for(). The media library column is the
 * one deliberate exception: it reports what an editor stored, which is not the
 * same question.
 */
class Credit
{
    public const META_COPYRIGHT   = '_sfx_media_copyright';
    public const META_AI          = '_sfx_media_ai';
    public const META_IPTC_MARKER = '_sfx_media_iptc_prefilled';

    /** @var array<int, array<string, mixed>> per-request memoisation, keyed by attachment id */
    private static array $cache = [];

    /**
     * Neutralise Bricks' dynamic-data delimiters.
     *
     * Bricks parses the whole assembled document for {tags} after every
     * element has rendered (frontend.php:947). Anything we emit is part of
     * that document, and a copyright notice is free text typed by anyone who
     * can upload media — so `{post_title}` would silently become the page
     * title, and `{echo:…}` would reach Bricks' echo tag. wp_kses_post() does
     * not help: braces are not HTML.
     */
    public static function escape_braces(string $value): string
    {
        return strtr($value, ['{' => '&#123;', '}' => '&#125;']);
    }

    /**
     * @return array{copyright: string, ai_key: string, ai_label: string, icon_id: int, line: string}
     */
    public static function for(int $attachment_id): array
    {
        $empty = ['copyright' => '', 'ai_key' => '', 'ai_label' => '', 'icon_id' => 0, 'line' => ''];

        if ($attachment_id <= 0) {
            return $empty;
        }

        if (array_key_exists($attachment_id, self::$cache)) {
            return self::$cache[$attachment_id];
        }

        // A stored id whose file is gone. Bricks keeps the id and renders a
        // placeholder (image.php:839-848); a confident copyright line under an
        // error placeholder names an owner who never took the picture.
        if (!wp_get_attachment_url($attachment_id)) {
            return self::$cache[$attachment_id] = $empty;
        }

        $labels = Settings::get_labels();

        $copyright = trim((string) get_post_meta($attachment_id, self::META_COPYRIGHT, true));
        if ($copyright === '') {
            $copyright = trim((string) Settings::get('fallback_copyright'));
        }

        $ai_key = (string) get_post_meta($attachment_id, self::META_AI, true);
        if (!isset($labels[$ai_key])) {
            $ai_key = '';
        }

        $ai_label = $ai_key === '' ? '' : $labels[$ai_key];
        $icon_id  = $ai_key === '' ? 0 : (int) Settings::get('seal_' . $ai_key);

        $parts = [
            'copyright' => $copyright,
            'ai_key'    => $ai_key,
            'ai_label'  => $ai_label,
            'icon_id'   => $icon_id,
        ];

        $line = self::compose($copyright, $ai_key, $ai_label, $icon_id);

        if ($line !== '') {
            $line = (string) apply_filters('sfx_media_credits_line', $line, $attachment_id, $parts);
            // Order is the point: kses first so a filter cannot add script,
            // braces last so a filter cannot add a dynamic-data tag either.
            $line = self::escape_braces(wp_kses_post($line));
        }

        return self::$cache[$attachment_id] = $parts + ['line' => $line];
    }

    /**
     * The copyright fragment, escaped, with `©` prepended unless the editor
     * already wrote one.
     */
    public static function with_copyright_prefix(string $text): string
    {
        $escaped = esc_html($text);

        if (preg_match('/^\s*(©|\(c\)|copyright)/i', $text) === 1) {
            return $escaped;
        }

        return '©&nbsp;' . $escaped;
    }

    private static function compose(string $copyright, string $ai_key, string $ai_label, int $icon_id): string
    {
        $bits = [];

        if ($copyright !== '') {
            $bits[] = self::with_copyright_prefix($copyright);
        }

        $ai = self::ai_part($ai_key, $ai_label, $icon_id);
        if ($ai !== '') {
            $bits[] = $ai;
        }

        return implode('&nbsp;·&nbsp;', $bits);
    }

    private static function ai_part(string $ai_key, string $ai_label, int $icon_id): string
    {
        if ($ai_key === '') {
            return '';
        }

        $display = (string) Settings::get('credit_display');
        $url     = $icon_id > 0 ? wp_get_attachment_image_url($icon_id, 'full') : false;

        // Text mode, or a seal whose attachment was deleted: the label alone.
        // Never a broken image where a disclosure should be.
        if ($display === 'text' || !$url) {
            return esc_html($ai_label);
        }

        $size = (int) Settings::get('icon_size');
        $alt  = $display === 'icon' ? $ai_label : '';

        $img = sprintf(
            '<img src="%s" alt="%s" width="%d" height="%d" class="sfx-credit__seal">',
            esc_url((string) $url),
            esc_attr($alt),
            $size,
            $size
        );

        return $display === 'icon' ? $img : $img . '&nbsp;' . esc_html($ai_label);
    }

    /** Test seam for the per-request cache. */
    public static function reset_cache(): void
    {
        self::$cache = [];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/media-credits-credit-test.php`
Expected: `PASS: all media-credits credit tests`

- [ ] **Step 5: Commit**

```bash
git add inc/MediaCredits/Credit.php tests/media-credits-credit-test.php
git commit -m "feat(media-credits): compose credit lines from attachment meta"
```

---

### Task 3: Attachment fields in the media library

**Files:**
- Create: `inc/MediaCredits/MediaLibrary.php`
- Test: `tests/media-credits-iptc-test.php` (created in Task 4; this task's behaviour is covered there and by the manual check in Task 12)

**Interfaces:**
- Consumes: `Credit::META_COPYRIGHT`, `Credit::META_AI`, `Settings::get_labels()`.
- Produces: `MediaLibrary::register(): void`, `MediaLibrary::fields(array $form_fields, $post): array`, `MediaLibrary::save(array $post, array $attachment): array`, `MediaLibrary::sanitize_ai_key(string $key): string`.

- [ ] **Step 1: Write the implementation**

There is no test-first step for the two field callbacks: they are WordPress form plumbing whose behaviour is the rendered admin form, verified in Task 12. `sanitize_ai_key()` is the one piece of logic, and Task 4's test covers it.

Create `inc/MediaCredits/MediaLibrary.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\MediaCredits;

/**
 * Everything the module does inside wp-admin: the two attachment fields, the
 * list column, the list filter and the one-shot IPTC prefill.
 */
class MediaLibrary
{
    public const FIELD_COPYRIGHT = 'sfx_media_copyright';
    public const FIELD_AI        = 'sfx_media_ai';

    public static function register(): void
    {
        add_filter('attachment_fields_to_edit', [self::class, 'fields'], 10, 2);
        add_filter('attachment_fields_to_save', [self::class, 'save'], 10, 2);
    }

    /**
     * Both fields, for every attachment type. The AI Act covers video and
     * audio too, and a MIME check would only add a way to be wrong.
     *
     * @param array<string, mixed> $form_fields
     * @param mixed $post
     * @return array<string, mixed>
     */
    public static function fields(array $form_fields, $post): array
    {
        $id = isset($post->ID) ? (int) $post->ID : 0;

        $form_fields[self::FIELD_COPYRIGHT] = [
            'label' => __('Copyright', 'sfxtheme'),
            'input' => 'text',
            'value' => (string) get_post_meta($id, Credit::META_COPYRIGHT, true),
            'helps' => __('Free text, e.g. © Photographer or an agency notice.', 'sfxtheme'),
        ];

        $current = (string) get_post_meta($id, Credit::META_AI, true);
        $options = '<option value="">' . esc_html__('No marking', 'sfxtheme') . '</option>';

        foreach (Settings::get_labels() as $slug => $label) {
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($slug),
                selected($current, $slug, false),
                esc_html($label)
            );
        }

        $form_fields[self::FIELD_AI] = [
            'label' => __('AI marking', 'sfxtheme'),
            'input' => 'html',
            'html'  => sprintf(
                '<select name="attachments[%1$d][%2$s]" id="attachments-%1$d-%2$s">%3$s</select>',
                $id,
                esc_attr(self::FIELD_AI),
                $options
            ),
            'helps' => __('How this file was produced or altered.', 'sfxtheme'),
        ];

        return $form_fields;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $attachment
     * @return array<string, mixed>
     */
    public static function save(array $post, array $attachment): array
    {
        $id = isset($post['ID']) ? (int) $post['ID'] : 0;

        if ($id <= 0) {
            return $post;
        }

        if (array_key_exists(self::FIELD_COPYRIGHT, $attachment)) {
            update_post_meta(
                $id,
                Credit::META_COPYRIGHT,
                sanitize_text_field((string) $attachment[self::FIELD_COPYRIGHT])
            );
        }

        if (array_key_exists(self::FIELD_AI, $attachment)) {
            update_post_meta(
                $id,
                Credit::META_AI,
                self::sanitize_ai_key((string) $attachment[self::FIELD_AI])
            );
        }

        return $post;
    }

    /**
     * Anything outside the closed slug list stores as "no marking". A value we
     * do not recognise must never survive into output as a label we cannot
     * render.
     */
    public static function sanitize_ai_key(string $key): string
    {
        return isset(Settings::get_labels()[$key]) ? $key : '';
    }
}
```

- [ ] **Step 2: Check it parses**

Run: `php -l inc/MediaCredits/MediaLibrary.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add inc/MediaCredits/MediaLibrary.php
git commit -m "feat(media-credits): copyright and AI marking fields on attachments"
```

---

### Task 4: The one-shot IPTC prefill

**Files:**
- Modify: `inc/MediaCredits/MediaLibrary.php`
- Create: `tests/media-credits-iptc-test.php`
- Test: `tests/media-credits-iptc-test.php`

**Interfaces:**
- Consumes: `Credit::META_COPYRIGHT`, `Credit::META_IPTC_MARKER`, `MediaLibrary::sanitize_ai_key()`.
- Produces: `MediaLibrary::prefill_iptc($metadata, $attachment_id)`, `MediaLibrary::iptc_copyright(array $image_meta): string`.

- [ ] **Step 1: Write the failing test**

Create `tests/media-credits-iptc-test.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/support/media-credits-stubs.php';

require dirname(__DIR__) . '/inc/MediaCredits/Settings.php';
require dirname(__DIR__) . '/inc/MediaCredits/Credit.php';
require dirname(__DIR__) . '/inc/MediaCredits/MediaLibrary.php';

use SFX\MediaCredits\Credit;
use SFX\MediaCredits\MediaLibrary;

// ------------------------------------------------ Case 1: reading the field

assert_same('Agentur Nord', MediaLibrary::iptc_copyright(['copyright' => 'Agentur Nord']), 'Case 1a: copyright wins');
assert_same('Foto Müller', MediaLibrary::iptc_copyright(['credit' => 'Foto Müller']), 'Case 1b: credit is the fallback');
assert_same('Agentur Nord', MediaLibrary::iptc_copyright(['copyright' => 'Agentur Nord', 'credit' => 'Foto Müller']), 'Case 1c: copyright beats credit');
assert_same('', MediaLibrary::iptc_copyright([]), 'Case 1d: nothing there, nothing returned');
assert_same('', MediaLibrary::iptc_copyright(['copyright' => '   ']), 'Case 1e: whitespace is nothing');

// ------------------------------------------------------ Case 2: first upload

test_reset();
$meta = ['image_meta' => ['copyright' => 'Agentur Nord']];

MediaLibrary::prefill_iptc($meta, 11);

assert_same('Agentur Nord', get_post_meta(11, Credit::META_COPYRIGHT, true), 'Case 2a: the field is prefilled on upload');
assert_same('1', get_post_meta(11, Credit::META_IPTC_MARKER, true), 'Case 2b: and the marker records that it happened');

// ------------------------------- Case 3: regeneration must not resurrect it
// wp_generate_attachment_metadata fires again on thumbnail regeneration
// (wp-admin/includes/image.php:184-188). "Write it if the field is empty"
// would undo an editorial decision every time someone regenerates.

update_post_meta(11, Credit::META_COPYRIGHT, '');
MediaLibrary::prefill_iptc($meta, 11);

assert_same('', get_post_meta(11, Credit::META_COPYRIGHT, true), 'Case 3a: a deliberately cleared field stays cleared');

// ---------------------------------------- Case 4: an editor's value is safe

test_reset();
update_post_meta(12, Credit::META_COPYRIGHT, 'Foto Müller');
MediaLibrary::prefill_iptc(['image_meta' => ['copyright' => 'Agentur Nord']], 12);

assert_same('Foto Müller', get_post_meta(12, Credit::META_COPYRIGHT, true), 'Case 4a: prefill never overwrites an existing value');

// ------------------------------------------------- Case 5: non-images, junk

test_reset();
MediaLibrary::prefill_iptc(['sizes' => []], 13);
assert_same('', get_post_meta(13, Credit::META_COPYRIGHT, true), 'Case 5a: no image_meta, no write');

$passthrough = ['image_meta' => ['copyright' => 'X']];
assert_same($passthrough, MediaLibrary::prefill_iptc($passthrough, 14), 'Case 5b: the filter returns its input unchanged');
assert_same('not an array', MediaLibrary::prefill_iptc('not an array', 15), 'Case 5c: a non-array metadata value passes straight through');
assert_same('', get_post_meta(0, Credit::META_COPYRIGHT, true), 'Case 5d: id 0 writes nothing');

// ---------------------------------------------- Case 6: ai key sanitisation

assert_same('ai_generated', MediaLibrary::sanitize_ai_key('ai_generated'), 'Case 6a: a known slug survives');
assert_same('', MediaLibrary::sanitize_ai_key('ai_hallucinated'), 'Case 6b: an unknown slug becomes no marking');
assert_same('', MediaLibrary::sanitize_ai_key(''), 'Case 6c: empty stays empty');

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all media-credits iptc tests\n";
exit(0);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/media-credits-iptc-test.php`
Expected: `FAIL` lines for cases 1a onwards, or a fatal on the undefined method `iptc_copyright`.

- [ ] **Step 3: Write the implementation**

Add to `inc/MediaCredits/MediaLibrary.php`. Extend `register()`:

```php
        add_filter('wp_generate_attachment_metadata', [self::class, 'prefill_iptc'], 10, 2);
```

And add the two methods:

```php
    /**
     * Prefill the copyright field from the IPTC data WordPress already parsed.
     *
     * Exactly once per attachment, recorded in a marker meta. The hook also
     * fires on metadata regeneration (wp-admin/includes/image.php:184-188), so
     * an "if the field is empty" test would resurrect a value an editor
     * deliberately cleared every time someone regenerates thumbnails.
     *
     * @param mixed $metadata
     * @param mixed $attachment_id
     * @return mixed the metadata, untouched — this is a read-only passenger on the filter
     */
    public static function prefill_iptc($metadata, $attachment_id)
    {
        if (!is_array($metadata)) {
            return $metadata;
        }

        $id = (int) $attachment_id;

        if ($id <= 0) {
            return $metadata;
        }

        if ((string) get_post_meta($id, Credit::META_IPTC_MARKER, true) !== '') {
            return $metadata;
        }

        // The marker is set on the first run whether or not anything was
        // found. "We have looked" is the fact being recorded, not "we wrote".
        update_post_meta($id, Credit::META_IPTC_MARKER, '1');

        $value = self::iptc_copyright(is_array($metadata['image_meta'] ?? null) ? $metadata['image_meta'] : []);

        if ($value === '') {
            return $metadata;
        }

        if (trim((string) get_post_meta($id, Credit::META_COPYRIGHT, true)) !== '') {
            return $metadata;
        }

        update_post_meta($id, Credit::META_COPYRIGHT, $value);

        return $metadata;
    }

    /**
     * IPTC `copyright`, else `credit`. Both are already parsed by
     * wp_read_image_metadata(); this module owns no parser of its own.
     *
     * @param array<string, mixed> $image_meta
     */
    public static function iptc_copyright(array $image_meta): string
    {
        foreach (['copyright', 'credit'] as $key) {
            $value = sanitize_text_field((string) ($image_meta[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/media-credits-iptc-test.php`
Expected: `PASS: all media-credits iptc tests`

- [ ] **Step 5: Commit**

```bash
git add inc/MediaCredits/MediaLibrary.php tests/media-credits-iptc-test.php
git commit -m "feat(media-credits): one-shot IPTC copyright prefill"
```

---

### Task 5: Media library column and filter

**Files:**
- Modify: `inc/MediaCredits/MediaLibrary.php`
- Modify: `tests/media-credits-iptc-test.php` (insert before the epilogue — that file covers `MediaLibrary` as a whole, the IPTC name reflects its most important invariant)
- Test: `tests/media-credits-iptc-test.php`

**Interfaces:**
- Consumes: `Credit::META_COPYRIGHT`, `Credit::META_AI`, `Settings::get_labels()`, `Settings::get('fallback_copyright')`.
- Produces: `MediaLibrary::FILTER_PARAM`, `MediaLibrary::columns(array): array`, `MediaLibrary::column(string, $id): void`, `MediaLibrary::filter_dropdown(): void`, `MediaLibrary::filter_query($query): void`, `MediaLibrary::filter_meta_query(string $value): array`.

- [ ] **Step 1: Write the failing test**

Insert before the epilogue of `tests/media-credits-iptc-test.php`:

```php
// ------------------------------------------- Case 7: the list filter query

test_reset();

assert_same([], MediaLibrary::filter_meta_query(''), 'Case 7a: no filter selected, no meta query');
assert_same([], MediaLibrary::filter_meta_query('nonsense'), 'Case 7b: an unrecognised value is ignored, not guessed at');

$no_copyright = MediaLibrary::filter_meta_query('no_copyright');
assert_same('OR', $no_copyright['relation'] ?? null, 'Case 7c: "without copyright" needs both the absent and the empty case');
assert_same(Credit::META_COPYRIGHT, $no_copyright[0]['key'] ?? null, 'Case 7d: it queries the copyright key');
assert_same('NOT EXISTS', $no_copyright[0]['compare'] ?? null, 'Case 7e: rows that never had the meta');
assert_same('', $no_copyright[1]['value'] ?? null, 'Case 7f: and rows where it was cleared');

$any_ai = MediaLibrary::filter_meta_query('any_ai');
assert_same(Credit::META_AI, $any_ai[0]['key'] ?? null, 'Case 7g: "with AI marking" queries the AI key');
assert_same('!=', $any_ai[0]['compare'] ?? null, 'Case 7h: any non-empty slug counts');

$one = MediaLibrary::filter_meta_query('ai_generated');
assert_same('ai_generated', $one[0]['value'] ?? null, 'Case 7i: a single slug filters on that slug');
assert_same([], MediaLibrary::filter_meta_query('ai_hallucinated'), 'Case 7j: a slug outside the vocabulary is not a filter');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/media-credits-iptc-test.php`
Expected: fatal error, `Call to undefined method ...::filter_meta_query()`.

- [ ] **Step 3: Write the implementation**

Extend `register()` in `inc/MediaCredits/MediaLibrary.php`:

```php
        add_filter('manage_media_columns', [self::class, 'columns']);
        add_action('manage_media_custom_column', [self::class, 'column'], 10, 2);
        add_action('restrict_manage_posts', [self::class, 'filter_dropdown']);
        add_action('pre_get_posts', [self::class, 'filter_query']);
```

Add the constant next to the existing ones:

```php
    public const FILTER_PARAM = 'sfx_media_credit_filter';
```

And the methods:

```php
    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function columns(array $columns): array
    {
        $columns['sfx_media_credit'] = __('Credit', 'sfxtheme');

        return $columns;
    }

    /**
     * The column reports STORED state, deliberately not Credit::for().
     *
     * Its job is to show what an editor entered, and a value that exists only
     * because of the global fallback has to look different from one that does
     * not — otherwise the column shows a copyright for the very rows the
     * "without copyright" filter below calls empty.
     *
     * @param mixed $id
     */
    public static function column(string $column, $id): void
    {
        if ($column !== 'sfx_media_credit') {
            return;
        }

        $id        = (int) $id;
        $copyright = trim((string) get_post_meta($id, Credit::META_COPYRIGHT, true));
        $ai_key    = (string) get_post_meta($id, Credit::META_AI, true);
        $labels    = Settings::get_labels();

        if ($copyright !== '') {
            echo '<div>' . esc_html($copyright) . '</div>';
        } else {
            $fallback = trim((string) Settings::get('fallback_copyright'));

            if ($fallback !== '') {
                printf(
                    '<div style="opacity:.6"><em>%s</em></div>',
                    esc_html(sprintf(
                        /* translators: %s: the site-wide fallback copyright notice */
                        __('%s (fallback)', 'sfxtheme'),
                        $fallback
                    ))
                );
            }
        }

        if (isset($labels[$ai_key])) {
            echo '<div><strong>' . esc_html($labels[$ai_key]) . '</strong></div>';
        }
    }

    public static function filter_dropdown(): void
    {
        global $pagenow;

        if ($pagenow !== 'upload.php') {
            return;
        }

        $current = isset($_GET[self::FILTER_PARAM])
            ? sanitize_text_field(wp_unslash($_GET[self::FILTER_PARAM]))
            : '';

        $choices = [
            ''              => __('All credits', 'sfxtheme'),
            'no_copyright'  => __('Without copyright', 'sfxtheme'),
            'any_ai'        => __('With AI marking', 'sfxtheme'),
        ];

        foreach (Settings::get_labels() as $slug => $label) {
            $choices[$slug] = $label;
        }

        echo '<select name="' . esc_attr(self::FILTER_PARAM) . '">';

        foreach ($choices as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($current, $value, false),
                esc_html($label)
            );
        }

        echo '</select>';
    }

    /**
     * pre_get_posts fires for every query in the request, so the scope
     * contract comes first and the work second.
     *
     * Note $pagenow, not get_current_screen()->id: WP_Screen strips the .php
     * suffix (class-wp-screen.php:235-237), so a screen comparison against
     * 'upload.php' never matches and the filter would silently do nothing.
     *
     * @param mixed $query
     */
    public static function filter_query($query): void
    {
        global $pagenow;

        if (!is_admin() || $pagenow !== 'upload.php') {
            return;
        }

        if (!is_object($query) || !method_exists($query, 'is_main_query') || !$query->is_main_query()) {
            return;
        }

        $post_type = $query->get('post_type');

        if ($post_type !== '' && $post_type !== 'attachment') {
            return;
        }

        $value = isset($_GET[self::FILTER_PARAM])
            ? sanitize_text_field(wp_unslash($_GET[self::FILTER_PARAM]))
            : '';

        $meta_query = self::filter_meta_query($value);

        if ($meta_query === []) {
            return;
        }

        $query->set('meta_query', $meta_query);
    }

    /**
     * The meta query for one filter value, or [] for "not one of ours".
     *
     * @return array<mixed>
     */
    public static function filter_meta_query(string $value): array
    {
        if ($value === 'no_copyright') {
            // Two ways to have no copyright: never written, or cleared.
            return [
                'relation' => 'OR',
                ['key' => Credit::META_COPYRIGHT, 'compare' => 'NOT EXISTS'],
                ['key' => Credit::META_COPYRIGHT, 'value' => '', 'compare' => '='],
            ];
        }

        if ($value === 'any_ai') {
            return [
                ['key' => Credit::META_AI, 'value' => '', 'compare' => '!='],
            ];
        }

        if ($value !== '' && isset(Settings::get_labels()[$value])) {
            return [
                ['key' => Credit::META_AI, 'value' => $value, 'compare' => '='],
            ];
        }

        return [];
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/media-credits-iptc-test.php`
Expected: `PASS: all media-credits iptc tests`

- [ ] **Step 5: Commit**

```bash
git add inc/MediaCredits/MediaLibrary.php tests/media-credits-iptc-test.php
git commit -m "feat(media-credits): media library credit column and filter"
```

---

### Task 6: Bricks dynamic tags

**Files:**
- Create: `inc/MediaCredits/Bricks.php`
- Create: `tests/media-credits-bricks-test.php`, `tests/support/media-credits-bricks-stubs.php`
- Test: `tests/media-credits-bricks-test.php`

**Interfaces:**
- Consumes: `Credit::for()`, `Credit::escape_braces()`.
- Produces: `Bricks::PREFIX`, `Bricks::KEYS`, `Bricks::MARKER_CLASS`, `Bricks::register(): void`, `Bricks::add_tags_to_builder(array): array`, `Bricks::render_tag($tag, $post, $context)`, `Bricks::render_content($content, $post, $context)`, `Bricks::raw_value($post, string $key, int $explicit_id = 0): ?string`, `Bricks::resolve_id($post): int`.

- [ ] **Step 1: Write the Bricks doubles**

Create `tests/support/media-credits-bricks-stubs.php`:

```php
<?php

declare(strict_types=1);

/**
 * Bricks doubles for the MediaCredits suite.
 *
 * Separate file because PHP does not allow a bracketed namespace block
 * alongside non-namespaced code.
 */

namespace Bricks {
    class Query
    {
        public static bool $looping = false;

        /** @var mixed returned by get_loop_object() */
        public static $loop_object = null;

        public static function is_looping($id = ''): bool
        {
            return self::$looping;
        }

        public static function get_loop_object($id = '')
        {
            return self::$loop_object;
        }

        public static function reset(): void
        {
            self::$looping     = false;
            self::$loop_object = null;
        }
    }
}

namespace {
    /**
     * Stand-in for a Bricks element instance: the three things our filters
     * touch, and the one method they call.
     */
    class Test_Bricks_Element
    {
        public string $name = 'image';

        /** @var array<string, mixed> */
        public array $settings = [];

        public string $tag = 'figure';

        /** @var array<string, mixed>|null what get_normalized_image_settings() returns */
        public ?array $normalized = null;

        /**
         * @param array<string, mixed> $settings
         * @param array<string, mixed>|null $normalized
         */
        public function __construct(array $settings = [], ?array $normalized = null, string $name = 'image')
        {
            $this->settings   = $settings;
            $this->normalized = $normalized;
            $this->name       = $name;
        }

        /**
         * Mirrors Bricks: numeric dynamic results become an id, everything
         * else leaves id at 0 (image.php:738-760).
         *
         * @param array<string, mixed> $settings
         * @return array<string, mixed>|null
         */
        public function get_normalized_image_settings($settings)
        {
            return $this->normalized;
        }
    }

    function get_post_thumbnail_id($post = null)
    {
        global $test_thumbnail_ids;

        $id = is_object($post) ? ($post->ID ?? 0) : (int) $post;

        return $test_thumbnail_ids[$id] ?? 0;
    }

    function get_the_ID()
    {
        global $test_current_post_id;

        return $test_current_post_id ?? 0;
    }

    if (!class_exists('WP_Post')) {
        class WP_Post
        {
            public int $ID = 0;
            public string $post_type = 'post';

            /** @param array<string, mixed> $props */
            public function __construct(array $props = [])
            {
                foreach ($props as $key => $value) {
                    $this->$key = $value;
                }
            }
        }
    }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/media-credits-bricks-test.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/support/media-credits-stubs.php';
require __DIR__ . '/support/media-credits-bricks-stubs.php';

require dirname(__DIR__) . '/inc/MediaCredits/Settings.php';
require dirname(__DIR__) . '/inc/MediaCredits/Credit.php';
require dirname(__DIR__) . '/inc/MediaCredits/Bricks.php';

use SFX\MediaCredits\Bricks;
use SFX\MediaCredits\Credit;
use SFX\MediaCredits\Settings;

$test_thumbnail_ids   = [];
$test_current_post_id = 0;

/** Give attachment $id a copyright and an AI slug. */
function seed_attachment(int $id, string $copyright = '', string $ai = ''): void
{
    $GLOBALS['test_attachment_url'][$id] = "https://example.test/{$id}.jpg";
    $GLOBALS['test_post_meta'][$id][Credit::META_COPYRIGHT] = $copyright;
    $GLOBALS['test_post_meta'][$id][Credit::META_AI] = $ai;
    Credit::reset_cache();
}

// --------------------------------------------- Case 1: hook registration
// Priority 20 on render_tag is load-bearing, exactly as documented in
// NavMenuQuery\MenuItemTags: Bricks registers its own handler at 10 before
// our theme can hook at all, and its handler re-wraps unknown tags in braces.

Bricks::register();

$render_tag = test_registrations('bricks/dynamic_data/render_tag');
assert_same(1, count($render_tag), 'Case 1a: render_tag registered once');
assert_same(20, $render_tag[0]['priority'], 'Case 1b: at priority 20, behind Bricks own handler');
assert_same(3, $render_tag[0]['accepted_args'], 'Case 1c: with all three arguments');

$settings_hook = test_registrations('bricks/element/settings');
assert_same(2, $settings_hook[0]['accepted_args'], 'Case 1d: element settings filter takes the instance too');

$render_element = test_registrations('bricks/frontend/render_element');
assert_same(2, $render_element[0]['accepted_args'], 'Case 1e: render_element filter takes the instance too');

// --------------------------------------------- Case 2: tag recognition

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'Foto Müller', 'ai_generated');

$post = new WP_Post(['ID' => 5, 'post_type' => 'attachment']);

assert_same('Foto Müller', Bricks::render_tag('sfx_media_copyright', $post, null), 'Case 2a: the bare tag resolves');
assert_same('Foto Müller', Bricks::render_tag('{sfx_media_copyright}', $post, null), 'Case 2b: the brace-wrapped form resolves too — Bricks re-wraps unknown tags');
assert_same('KI-generiert', Bricks::render_tag('{sfx_media_ai_label}', $post, null), 'Case 2c: the label tag');
assert_contains('©&nbsp;Foto Müller', Bricks::render_tag('{sfx_media_credit}', $post, null), 'Case 2d: the composed line');

// A tag that is not ours must come back BYTE-IDENTICAL. Returning '' or a
// normalised copy destroys the value for every provider after us.
assert_same('{post_title}', Bricks::render_tag('{post_title}', $post, null), 'Case 2e: a foreign tag is passed through untouched');
assert_same('{sfx_media_nonsense}', Bricks::render_tag('{sfx_media_nonsense}', $post, null), 'Case 2f: an unknown suffix in our namespace is left alone');
assert_same(['x'], Bricks::render_tag(['x'], $post, null), 'Case 2g: a non-string tag is passed through');

// ------------------------------------------ Case 3: context resolution
// Explicit id beats loop object beats $post beats featured image.

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'From post');
seed_attachment(6, 'From loop');
seed_attachment(7, 'From explicit id');
seed_attachment(8, 'From featured image');

$post = new WP_Post(['ID' => 5, 'post_type' => 'attachment']);

assert_same('From post', Bricks::render_tag('{sfx_media_copyright}', $post, null), 'Case 3a: a $post that is an attachment');

\Bricks\Query::$looping     = true;
\Bricks\Query::$loop_object = new WP_Post(['ID' => 6, 'post_type' => 'attachment']);
assert_same('From loop', Bricks::render_tag('{sfx_media_copyright}', $post, null), 'Case 3b: the loop object beats $post');

assert_same('From explicit id', Bricks::render_tag('{sfx_media_copyright:7}', $post, null), 'Case 3c: an explicit id beats everything');

\Bricks\Query::reset();
$GLOBALS['test_thumbnail_ids'][42] = 8;
$GLOBALS['test_current_post_id']   = 42;
$page = new WP_Post(['ID' => 42, 'post_type' => 'page']);
assert_same('From featured image', Bricks::render_tag('{sfx_media_copyright}', $page, null), 'Case 3d: a non-attachment post falls back to its featured image');

$GLOBALS['test_thumbnail_ids'] = [];
assert_same('', Bricks::render_tag('{sfx_media_copyright}', $page, null), 'Case 3e: nothing resolvable renders empty, never the literal tag');

// ------------------------------------------------ Case 4: render_content

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'Foto Müller');
$post = new WP_Post(['ID' => 5, 'post_type' => 'attachment']);

$out = Bricks::render_content('Bild: {sfx_media_copyright}.', $post, null);
assert_same('Bild: Foto Müller.', $out, 'Case 4a: the tag is replaced in place');
assert_same('Nothing here.', Bricks::render_content('Nothing here.', $post, null), 'Case 4b: content without our tags is untouched');
assert_contains('{post_title}', Bricks::render_content('{post_title}', $post, null), 'Case 4c: foreign tags survive');

// Braces from stored data must not reach the page even through the tags.
seed_attachment(5, '{echo:phpinfo}');
assert_not_contains('{echo:', Bricks::render_content('{sfx_media_copyright}', $post, null), 'Case 4d: brace escaping applies to tag output too');

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all media-credits bricks tests\n";
exit(0);
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php tests/media-credits-bricks-test.php`
Expected: fatal error on the missing `inc/MediaCredits/Bricks.php`.

- [ ] **Step 4: Write the implementation**

Create `inc/MediaCredits/Bricks.php` with the registration and the tag half. The element-settings and render-element callbacks land in Tasks 7 and 8; register them now and stub the bodies as pass-throughs so the registration assertions hold.

```php
<?php

declare(strict_types=1);

namespace SFX\MediaCredits;

/**
 * Bricks integration.
 *
 * Three mechanisms, only the last of which touches rendered HTML:
 *   1. tag substitution inside the Image element's own captionCustom setting
 *   2. caption auto-output, written as a setting
 *   3. overlay auto-output, injected into an existing wrapper
 */
class Bricks
{
    public const PREFIX = 'sfx_media_';

    /** @var list<string> */
    public const KEYS = ['copyright', 'ai_label', 'credit'];

    public const MARKER_CLASS = 'sfx-credit';

    public static function register(): void
    {
        add_filter('bricks/element/settings', [self::class, 'element_settings'], 10, 2);
        add_filter('bricks/frontend/render_element', [self::class, 'render_element'], 10, 2);
        add_filter('wp_get_attachment_image_attributes', [self::class, 'image_attributes'], 10, 2);

        add_filter('bricks/dynamic_tags_list', [self::class, 'add_tags_to_builder']);

        // Priority 20 is load-bearing, not a preference. Bricks occupies
        // priority 10 and registers at include time, before our
        // after_setup_theme hook can fire — so a priority-10 registration of
        // ours always runs second at that priority anyway. Its handler does
        // not know our tags and hands them on re-wrapped as '{tag}'
        // (providers.php:562), which is why the parser below tolerates braces.
        add_filter('bricks/dynamic_data/render_tag', [self::class, 'render_tag'], 20, 3);

        add_filter('bricks/dynamic_data/render_content', [self::class, 'render_content'], 10, 3);
    }

    /**
     * @param array<string, mixed> $tags
     * @return array<string, mixed>
     */
    public static function add_tags_to_builder(array $tags): array
    {
        $group  = __('Media Credits', 'sfxtheme');
        $labels = [
            'copyright' => __('Copyright notice', 'sfxtheme'),
            'ai_label'  => __('AI marking', 'sfxtheme'),
            'credit'    => __('Credit line', 'sfxtheme'),
        ];

        foreach (self::KEYS as $key) {
            $tags[] = [
                'name'  => '{' . self::PREFIX . $key . '}',
                'label' => $labels[$key],
                'group' => $group,
            ];
        }

        return $tags;
    }

    /**
     * Resolve a tag in a single-value context.
     *
     * Bricks seeds this filter with the tag itself, so the incoming $tag
     * doubles as "nobody has resolved this yet". Anything we return for a tag
     * we do not own — '', null, a normalised copy — destroys the value for
     * every provider after us, so every miss returns the ORIGINAL $tag.
     *
     * @param mixed $tag
     * @param mixed $post
     * @param mixed $context
     * @return mixed
     */
    public static function render_tag($tag, $post, $context)
    {
        if (!is_string($tag)) {
            return $tag;
        }

        $needle = $tag;

        // One pair only — Bricks strips just the outermost pair too.
        if (strlen($needle) > 1 && $needle[0] === '{' && substr($needle, -1) === '}') {
            $needle = substr($needle, 1, -1);
        }

        $parsed = self::parse($needle);

        if ($parsed === null) {
            return $tag;
        }

        $value = self::raw_value($post, $parsed['key'], $parsed['id']);

        return $value === null ? $tag : $value;
    }

    /**
     * Resolve every one of our tags inside a block of content.
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

        $pattern = '/\{' . preg_quote(self::PREFIX, '/') . '(' . implode('|', self::KEYS) . ')(?::(\d+))?\}/';

        return preg_replace_callback(
            $pattern,
            static function (array $m) use ($post): string {
                $value = self::raw_value($post, $m[1], isset($m[2]) ? (int) $m[2] : 0);

                if ($value === null) {
                    return $m[0];
                }

                // The composed line is HTML by contract; the two text values
                // are not, and this context writes straight into markup.
                return $m[1] === 'credit' ? $value : esc_html($value);
            },
            $content
        );
    }

    /**
     * One tag's value, or null for "not one of ours".
     *
     * Text values come back raw for their consuming control to escape, as in
     * NavMenuQuery — but always brace-escaped, because that is not an escaping
     * choice, it is the boundary that stops stored text becoming a Bricks tag.
     */
    public static function raw_value($post, string $key, int $explicit_id = 0): ?string
    {
        if (!in_array($key, self::KEYS, true)) {
            return null;
        }

        $id = $explicit_id > 0 ? $explicit_id : self::resolve_id($post);

        if ($id <= 0) {
            return '';
        }

        $credit = Credit::for($id);

        switch ($key) {
            case 'copyright':
                return Credit::escape_braces($credit['copyright']);
            case 'ai_label':
                return Credit::escape_braces($credit['ai_label']);
            case 'credit':
                return $credit['line'];
        }

        return null;
    }

    /**
     * Attachment context, first hit wins: Bricks loop object, the global
     * $post, then the current post's featured image.
     *
     * Inside an image element the tag has already been substituted by
     * element_settings(), so this list is never consulted there.
     */
    public static function resolve_id($post): int
    {
        if (class_exists('Bricks\Query') && \Bricks\Query::is_looping()) {
            $loop_object = \Bricks\Query::get_loop_object();

            if ($loop_object instanceof \WP_Post && $loop_object->post_type === 'attachment') {
                return (int) $loop_object->ID;
            }
        }

        if ($post instanceof \WP_Post && $post->post_type === 'attachment') {
            return (int) $post->ID;
        }

        $post_id = $post instanceof \WP_Post ? (int) $post->ID : (int) get_the_ID();

        return $post_id > 0 ? (int) get_post_thumbnail_id($post_id) : 0;
    }

    /**
     * @return array{key: string, id: int}|null
     */
    private static function parse(string $needle): ?array
    {
        if (strpos($needle, self::PREFIX) !== 0) {
            return null;
        }

        $rest = substr($needle, strlen(self::PREFIX));
        $id   = 0;

        if (strpos($rest, ':') !== false) {
            [$rest, $suffix] = explode(':', $rest, 2);

            if ($suffix === '' || !ctype_digit($suffix)) {
                return null;
            }

            $id = (int) $suffix;
        }

        return in_array($rest, self::KEYS, true) ? ['key' => $rest, 'id' => $id] : null;
    }

    /**
     * @param array<string, mixed> $settings
     * @param mixed $element
     * @return array<string, mixed>
     */
    public static function element_settings($settings, $element)
    {
        return $settings; // Task 7 replaces this body.
    }

    /**
     * @param mixed $html
     * @param mixed $element
     * @return mixed
     */
    public static function render_element($html, $element)
    {
        return $html; // Task 8 replaces this body.
    }

    /**
     * @param mixed $attr
     * @param mixed $attachment
     * @return mixed
     */
    public static function image_attributes($attr, $attachment)
    {
        return $attr; // Task 8 replaces this body.
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php tests/media-credits-bricks-test.php`
Expected: `PASS: all media-credits bricks tests`

- [ ] **Step 6: Commit**

```bash
git add inc/MediaCredits/Bricks.php tests/media-credits-bricks-test.php tests/support/media-credits-bricks-stubs.php
git commit -m "feat(media-credits): Bricks dynamic tags for copyright and AI marking"
```

---

### Task 7: Settings-time substitution and caption auto-output

**Files:**
- Modify: `inc/MediaCredits/Bricks.php`
- Modify: `tests/media-credits-bricks-test.php` (insert before the epilogue), `tests/support/media-credits-bricks-stubs.php` (one more double)
- Test: `tests/media-credits-bricks-test.php`

**Interfaces:**
- Consumes: `Credit::for()`, `Settings::get()`.
- Produces: `Bricks::element_settings($settings, $element)`, `Bricks::substitute(string $text, int $id): string`, `Bricks::effective_caption(array $settings, int $image_id, string $default_type = 'attachment'): string`, `Bricks::has_marker(string $html): bool`, `Bricks::has_no_credit_class(array $settings): bool`, `Bricks::image_id_from_element($element, array $settings): int`.

- [ ] **Step 1: Add the `get_post()` double**

In `tests/support/media-credits-bricks-stubs.php`, inside the global namespace block, add:

```php
    function get_post($id = null)
    {
        global $test_posts;

        return $test_posts[(int) $id] ?? null;
    }
```

and declare `$test_posts = [];` next to the other fixture globals at the top of `tests/media-credits-bricks-test.php`.

- [ ] **Step 2: Write the failing test**

Insert before the epilogue of `tests/media-credits-bricks-test.php`:

```php
// ------------------------------------------ Case 5: the effective caption
// This mirrors Bricks' own branch order (image.php:794-810). The third branch
// is the one that is easy to miss: type 'custom' with an EMPTY field still
// falls through to the attachment caption.

test_reset();
$GLOBALS['test_posts'][5] = new WP_Post(['ID' => 5, 'post_excerpt' => 'Attachment caption']);

assert_same('', Bricks::effective_caption(['caption' => 'none'], 5), 'Case 5a: type none renders nothing');
assert_same('Mine', Bricks::effective_caption(['caption' => 'custom', 'captionCustom' => 'Mine'], 5), 'Case 5b: a non-empty custom caption');
assert_same('Attachment caption', Bricks::effective_caption(['caption' => 'custom', 'captionCustom' => ''], 5), 'Case 5c: an EMPTY custom caption falls through to the attachment');
assert_same('Attachment caption', Bricks::effective_caption(['caption' => 'attachment'], 5), 'Case 5d: type attachment');
assert_same('Attachment caption', Bricks::effective_caption([], 5), 'Case 5e: an unset control defaults to attachment, not to nothing');
assert_same('', Bricks::effective_caption([], 5, 'none'), 'Case 5f: the theme-style default is honoured when passed');
assert_same('', Bricks::effective_caption(['caption' => 'attachment'], 0), 'Case 5g: no image, no caption');

// ------------------------------------------------ Case 6: marker matching

assert_same(true, Bricks::has_marker('<figure><span class="sfx-credit">x</span></figure>'), 'Case 6a: the marker is found');
assert_same(true, Bricks::has_marker('<span class="sfx-credit sfx-credit--overlay">x</span>'), 'Case 6b: found among other classes');
assert_same(false, Bricks::has_marker('<span class="sfx-credit-note">x</span>'), 'Case 6c: a longer class is NOT the marker');
assert_same(false, Bricks::has_marker('the words sfx-credit in prose'), 'Case 6d: prose is not a class attribute');
assert_same(false, Bricks::has_marker(''), 'Case 6e: empty is empty');

assert_same(true, Bricks::has_no_credit_class(['_cssClasses' => 'foo no-credit bar']), 'Case 6f: no-credit as one token among many');
assert_same(false, Bricks::has_no_credit_class(['_cssClasses' => 'no-credit-card']), 'Case 6g: a longer class does not suppress');
assert_same(false, Bricks::has_no_credit_class([]), 'Case 6h: no classes, no suppression');

// -------------------------------------------- Case 7: tag substitution

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'Foto Müller', 'ai_generated');

$element = new Test_Bricks_Element(
    ['caption' => 'custom', 'captionCustom' => 'Vorher {sfx_media_credit} nachher'],
    ['id' => 5]
);

$out = Bricks::element_settings($element->settings, $element);
assert_contains('Foto Müller', $out['captionCustom'], 'Case 7a: the tag is replaced with the credit');
assert_contains('class="sfx-credit"', $out['captionCustom'], 'Case 7b: wrapped in the marker, so auto-output can see it');
assert_not_contains('{sfx_media_credit}', $out['captionCustom'], 'Case 7c: nothing of the tag is left');
assert_contains('Vorher', $out['captionCustom'], 'Case 7d: surrounding text survives');

// Only captionCustom. altText is rendered into an attribute and _cssClasses
// was consumed before this filter ran (base.php:2908, :2929).
$element = new Test_Bricks_Element(
    ['altText' => '{sfx_media_credit}', '_cssClasses' => '{sfx_media_credit}'],
    ['id' => 5]
);
$out = Bricks::element_settings($element->settings, $element);
assert_same('{sfx_media_credit}', $out['altText'], 'Case 7e: altText is left alone');
assert_same('{sfx_media_credit}', $out['_cssClasses'], 'Case 7f: _cssClasses is left alone');

// An explicit id inside an image element still wins.
seed_attachment(9, 'Anderes Bild');
$element = new Test_Bricks_Element(
    ['caption' => 'custom', 'captionCustom' => '{sfx_media_copyright:9}'],
    ['id' => 5]
);
$out = Bricks::element_settings($element->settings, $element);
assert_contains('Anderes Bild', $out['captionCustom'], 'Case 7g: an explicit id beats the element image');

// A non-image element is not ours.
$other = new Test_Bricks_Element(['captionCustom' => '{sfx_media_credit}'], ['id' => 5], 'heading');
assert_same('{sfx_media_credit}', Bricks::element_settings($other->settings, $other)['captionCustom'], 'Case 7h: only the image element is touched');

// ------------------------------------------- Case 8: caption auto-output

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'Foto Müller');
$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'caption'];
$GLOBALS['test_posts'][5] = new WP_Post(['ID' => 5, 'post_excerpt' => 'Attachment caption']);

$element = new Test_Bricks_Element([], ['id' => 5]);
$out = Bricks::element_settings($element->settings, $element);

assert_same('custom', $out['caption'], 'Case 8a: the caption type is switched to custom');
assert_contains('Attachment caption', $out['captionCustom'], 'Case 8b: the existing attachment caption is preserved');
assert_contains('Foto Müller', $out['captionCustom'], 'Case 8c: and the credit is added');
assert_contains('class="sfx-credit"', $out['captionCustom'], 'Case 8d: marked, so overlay mode would not add it twice');

// No existing caption: the credit stands alone, with no stray separator.
$GLOBALS['test_posts'][5] = new WP_Post(['ID' => 5, 'post_excerpt' => '']);
Credit::reset_cache();
$element = new Test_Bricks_Element([], ['id' => 5]);
$out = Bricks::element_settings($element->settings, $element);
assert_not_contains('<br>', $out['captionCustom'], 'Case 8e: no separator when there was no caption');

// Dedup: a hand-placed tag must not be doubled by auto-output.
$element = new Test_Bricks_Element(
    ['caption' => 'custom', 'captionCustom' => '{sfx_media_credit}'],
    ['id' => 5]
);
$out = Bricks::element_settings($element->settings, $element);
assert_same(1, substr_count($out['captionCustom'], 'class="sfx-credit"'), 'Case 8f: exactly one credit, not two');

// Dedup tests the EFFECTIVE caption, not the raw setting. A marker sitting in
// a captionCustom Bricks will not render (type attachment) must NOT suppress
// the auto-credit — that would ship the image with no disclosure at all.
$GLOBALS['test_posts'][5] = new WP_Post(['ID' => 5, 'post_excerpt' => '']);
Credit::reset_cache();
$element = new Test_Bricks_Element(
    ['caption' => 'attachment', 'captionCustom' => '<span class="sfx-credit">stale</span>'],
    ['id' => 5]
);
$out = Bricks::element_settings($element->settings, $element);
assert_contains('Foto Müller', $out['captionCustom'], 'Case 8g: a marker in an unrendered caption does not suppress the credit');

// no-credit opts the element out.
$element = new Test_Bricks_Element(['_cssClasses' => 'no-credit'], ['id' => 5]);
$out = Bricks::element_settings($element->settings, $element);
assert_same(false, isset($out['caption']), 'Case 8h: no-credit suppresses caption auto-output');

// Nothing stored, nothing added.
test_reset();
seed_attachment(6, '', '');
$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'caption'];
$GLOBALS['test_posts'][6] = new WP_Post(['ID' => 6, 'post_excerpt' => '']);
$element = new Test_Bricks_Element([], ['id' => 6]);
assert_same(false, isset(Bricks::element_settings($element->settings, $element)['caption']), 'Case 8i: an image with no credit data is untouched');

// ------------------------------------------------ Case 9: force_wrapper

test_reset();
seed_attachment(5, 'Foto Müller');
$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'overlay', 'force_wrapper' => 1];

$element = new Test_Bricks_Element([], ['id' => 5]);
assert_same('figure', Bricks::element_settings($element->settings, $element)['tag'] ?? null, 'Case 9a: an element with no tag gets one, which is what flips has_html_tag');

$element = new Test_Bricks_Element(['tag' => 'div'], ['id' => 5]);
assert_same('div', Bricks::element_settings($element->settings, $element)['tag'], 'Case 9b: a tag the user chose is never overwritten');

$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'caption', 'force_wrapper' => 1];
$element = new Test_Bricks_Element([], ['id' => 5]);
assert_same(false, isset(Bricks::element_settings($element->settings, $element)['tag']), 'Case 9c: force_wrapper is an overlay-mode setting only');
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php tests/media-credits-bricks-test.php`
Expected: fatal error, `Call to undefined method ...::effective_caption()`.

- [ ] **Step 4: Write the implementation**

Replace the `element_settings()` stub in `inc/MediaCredits/Bricks.php` and add the helpers:

```php
    /**
     * Mechanisms 1 and 2, in a fixed order because both write captionCustom.
     *
     * This filter fires inside Element::init() immediately before render()
     * (base.php:2948), which is the only moment where the element and our tag
     * are in the same room: captionCustom is never passed through
     * render_dynamic_data() (image.php:805-806), so a tag left in it survives
     * into the page and is resolved much later, against the wrong context.
     *
     * @param mixed $settings
     * @param mixed $element
     * @return mixed
     */
    public static function element_settings($settings, $element)
    {
        if (!is_array($settings) || !is_object($element) || ($element->name ?? '') !== 'image') {
            return $settings;
        }

        $id = self::image_id_from_element($element, $settings);

        if ($id <= 0) {
            return $settings;
        }

        // 1 · substitute our tags where the editor placed them
        $caption_custom = (string) ($settings['captionCustom'] ?? '');

        if (strpos($caption_custom, '{' . self::PREFIX) !== false) {
            $settings['captionCustom'] = self::substitute($caption_custom, $id);
        }

        if (self::has_no_credit_class($settings)) {
            return $settings;
        }

        $mode = (string) Settings::get('output_mode');

        if ($mode === 'overlay') {
            // The overlay needs a wrapper to attach to. Setting the key is
            // what flips $has_html_tag (image.php:822); the tag NAME comes
            // from the constructor and is already 'figure' for this element
            // (image.php:10), so an element that chose 'div' keeps it.
            if (Settings::get('force_wrapper') && !isset($settings['tag']) && Credit::for($id)['line'] !== '') {
                $settings['tag'] = 'figure';
            }

            return $settings;
        }

        if ($mode !== 'caption') {
            return $settings;
        }

        // 2 · caption auto-output, written as a setting rather than injected
        $default_type = is_array($element->theme_styles ?? null) && !empty($element->theme_styles['caption'])
            ? (string) $element->theme_styles['caption']
            : 'attachment';

        $effective = self::effective_caption($settings, $id, $default_type);

        // Tested against the EFFECTIVE caption on purpose: a marker sitting in
        // a captionCustom that Bricks is not going to render would otherwise
        // suppress the disclosure entirely.
        if (self::has_marker($effective)) {
            return $settings;
        }

        $line = Credit::for($id)['line'];

        if ($line === '') {
            return $settings;
        }

        $credit = '<span class="' . self::MARKER_CLASS . '">' . $line . '</span>';

        $settings['caption']       = 'custom';
        $settings['captionCustom'] = $effective === '' ? $credit : $effective . '<br>' . $credit;

        return $settings;
    }

    /**
     * Replace our tags in one string, each wrapped in the marker span.
     */
    public static function substitute(string $text, int $id): string
    {
        $pattern = '/\{' . preg_quote(self::PREFIX, '/') . '(' . implode('|', self::KEYS) . ')(?::(\d+))?\}/';

        return (string) preg_replace_callback(
            $pattern,
            static function (array $m) use ($id): string {
                $target = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $id;
                $credit = Credit::for($target);

                if ($m[1] === 'credit') {
                    $value = $credit['line'];
                } else {
                    $raw   = $m[1] === 'copyright' ? $credit['copyright'] : $credit['ai_label'];
                    $value = Credit::escape_braces(esc_html($raw));
                }

                if ($value === '') {
                    return '';
                }

                return '<span class="' . self::MARKER_CLASS . '">' . $value . '</span>';
            },
            $text
        );
    }

    /**
     * What Bricks will actually render as the caption.
     *
     * Reproduces image.php:794-810 branch for branch. The third branch is the
     * subtle one: type 'custom' with an EMPTY field still falls through to the
     * attachment caption, so treating "custom" as "captionCustom" would let us
     * overwrite a caption the editor never touched.
     *
     * @param array<string, mixed> $settings
     */
    public static function effective_caption(array $settings, int $image_id, string $default_type = 'attachment'): string
    {
        $type = isset($settings['caption']) ? (string) $settings['caption'] : $default_type;

        if ($type === 'none') {
            return '';
        }

        if ($type === 'custom' && trim((string) ($settings['captionCustom'] ?? '')) !== '') {
            return trim((string) $settings['captionCustom']);
        }

        if ($image_id <= 0) {
            return '';
        }

        $attachment = get_post($image_id);

        return $attachment ? (string) ($attachment->post_excerpt ?? '') : '';
    }

    /**
     * Is our marker class present as a class-attribute token?
     *
     * A bare substring test would also fire on the words in prose and on
     * `sfx-credit-note`, and suppressing a disclosure by accident is the
     * expensive direction of this mistake.
     */
    public static function has_marker(string $html): bool
    {
        $class = preg_quote(self::MARKER_CLASS, '/');

        return preg_match('/class\s*=\s*(["\'])(?:[^"\']*\s)?' . $class . '(?:\s[^"\']*)?\1/', $html) === 1;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function has_no_credit_class(array $settings): bool
    {
        $classes = trim((string) ($settings['_cssClasses'] ?? ''));

        if ($classes === '') {
            return false;
        }

        $tokens = preg_split('/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return in_array('no-credit', $tokens, true);
    }

    /**
     * The element's attachment id, through Bricks' own resolver so a dynamic
     * image source is honoured. A provider returning a URL rather than an id
     * leaves id at 0 (image.php:738-760) — those images get no credit, which
     * the spec accepts.
     *
     * @param mixed $element
     * @param array<string, mixed> $settings
     */
    public static function image_id_from_element($element, array $settings): int
    {
        if (!is_object($element) || !method_exists($element, 'get_normalized_image_settings')) {
            return 0;
        }

        $image = $element->get_normalized_image_settings($settings);

        return is_array($image) && !empty($image['id']) ? (int) $image['id'] : 0;
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php tests/media-credits-bricks-test.php`
Expected: `PASS: all media-credits bricks tests`

- [ ] **Step 6: Commit**

```bash
git add inc/MediaCredits/Bricks.php tests/media-credits-bricks-test.php tests/support/media-credits-bricks-stubs.php
git commit -m "feat(media-credits): resolve tags and compose captions in element settings"
```

---

### Task 8: Overlay injection, the machine-readable attribute, and the stylesheet

**Files:**
- Modify: `inc/MediaCredits/Bricks.php`
- Create: `inc/MediaCredits/assets/media-credits.css`
- Modify: `tests/media-credits-bricks-test.php` (insert before the epilogue)
- Test: `tests/media-credits-bricks-test.php`

**Interfaces:**
- Consumes: `Credit::for()`, `Settings::get('output_mode')`, `Bricks::has_marker()`, `Bricks::has_no_credit_class()`, `Bricks::image_id_from_element()`.
- Produces: `Bricks::render_element($html, $element)`, `Bricks::inject_overlay(string $html, string $line): string`, `Bricks::root_tag(string $html): string`, `Bricks::image_attributes($attr, $attachment)`.

- [ ] **Step 1: Write the failing test**

Insert before the epilogue of `tests/media-credits-bricks-test.php`:

```php
// ------------------------------------------------- Case 10: root detection

assert_same('figure', Bricks::root_tag('<figure class="x"><img></figure>'), 'Case 10a: figure root');
assert_same('div', Bricks::root_tag("\n  <div><img></div>"), 'Case 10b: leading whitespace is skipped');
assert_same('img', Bricks::root_tag('<img src="x">'), 'Case 10c: a bare image root');
assert_same('', Bricks::root_tag('no markup at all'), 'Case 10d: no tag, no root');

// ------------------------------------------------ Case 11: overlay injection

$line = '©&nbsp;Foto';

$out = Bricks::inject_overlay('<figure class="x"><img src="a"></figure>', $line);
assert_contains('sfx-credit--overlay', $out, 'Case 11a: the overlay is inserted');
assert_same(true, strpos($out, 'sfx-credit--overlay') < strpos($out, '</figure>'), 'Case 11b: inside the wrapper, before its closing tag');

$out = Bricks::inject_overlay('<section><img src="a"></section>', $line);
assert_contains('sfx-credit--overlay', $out, 'Case 11c: a custom root tag also takes the overlay');

// The three roots that must be left alone. Wrapping them would change the
// layout of a page nobody asked us to change.
foreach (['<img src="a">', '<picture><img src="a"></picture>', '<a href="#"><img src="a"></a>'] as $i => $html) {
    assert_same($html, Bricks::inject_overlay($html, $line), "Case 11d{$i}: no wrapper, no injection");
}

// ------------------------------------------- Case 12: render_element gate

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'Foto Müller');
$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'overlay'];

$element = new Test_Bricks_Element(['caption' => 'none'], ['id' => 5]);
$html    = '<figure><img src="a"></figure>';

assert_contains('Foto Müller', Bricks::render_element($html, $element), 'Case 12a: overlay mode injects the credit');

$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'caption'];
assert_same($html, Bricks::render_element($html, $element), 'Case 12b: caption mode does not inject HTML');

$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'off'];
assert_same($html, Bricks::render_element($html, $element), 'Case 12c: off means off');

$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'overlay'];
$optout = new Test_Bricks_Element(['_cssClasses' => 'no-credit'], ['id' => 5]);
assert_same($html, Bricks::render_element($html, $optout), 'Case 12d: no-credit opts out');

$already = '<figure><span class="sfx-credit">done</span><img src="a"></figure>';
assert_same($already, Bricks::render_element($already, $element), 'Case 12e: an element that already carries a credit gets no second one');

$heading = new Test_Bricks_Element([], ['id' => 5], 'heading');
assert_same($html, Bricks::render_element($html, $heading), 'Case 12f: only image elements');

// ------------------------------------------ Case 13: data-sfx-ai attribute

test_reset();
seed_attachment(5, '', 'ai_generated');
$attachment = new WP_Post(['ID' => 5, 'post_type' => 'attachment']);

$attr = Bricks::image_attributes(['src' => 'x'], $attachment);
assert_same('ai_generated', $attr['data-sfx-ai'] ?? null, 'Case 13a: the slug is exposed on the img');

seed_attachment(6, 'Foto', '');
$attr = Bricks::image_attributes(['src' => 'x'], new WP_Post(['ID' => 6, 'post_type' => 'attachment']));
assert_same(false, isset($attr['data-sfx-ai']), 'Case 13b: no AI marking, no attribute');
assert_same('not an array', Bricks::image_attributes('not an array', $attachment), 'Case 13c: a non-array passes through');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/media-credits-bricks-test.php`
Expected: fatal error, `Call to undefined method ...::root_tag()`.

- [ ] **Step 3: Write the implementation**

Replace the `render_element()` and `image_attributes()` stubs in `inc/MediaCredits/Bricks.php`:

```php
    /**
     * Overlay auto-output — the only place this module writes HTML.
     *
     * Only fires on the frontend render path: Ajax::render_element() calls
     * init() directly and never applies this filter (ajax.php:885-891), so
     * while editing, an overlay appears on a full canvas load and not on the
     * single element being re-rendered. Mechanisms 1 and 2 live inside init()
     * and have no such gap, which is why caption mode is the recommended one.
     *
     * @param mixed $html
     * @param mixed $element
     * @return mixed
     */
    public static function render_element($html, $element)
    {
        if (!is_string($html) || $html === '' || !is_object($element) || ($element->name ?? '') !== 'image') {
            return $html;
        }

        if ((string) Settings::get('output_mode') !== 'overlay') {
            return $html;
        }

        $settings = is_array($element->settings ?? null) ? $element->settings : [];

        if (self::has_no_credit_class($settings) || self::has_marker($html)) {
            return $html;
        }

        $id = self::image_id_from_element($element, $settings);

        if ($id <= 0) {
            return $html;
        }

        $line = Credit::for($id)['line'];

        return $line === '' ? $html : self::inject_overlay($html, $line);
    }

    /**
     * Insert the overlay before the root element's closing tag — and only when
     * there is a root element to insert into. Bricks renders no wrapper at all
     * unless a caption, overlay, gradient or tag is set (image.php:822), and
     * wrapping the bare `<img>` ourselves would move a box in someone's
     * layout for a credit they may not even have configured.
     */
    public static function inject_overlay(string $html, string $line): string
    {
        $root = self::root_tag($html);

        if ($root === '' || in_array($root, ['img', 'picture', 'a'], true)) {
            return $html;
        }

        $closing = '</' . $root . '>';
        $pos     = strrpos($html, $closing);

        if ($pos === false) {
            return $html;
        }

        $span = '<span class="' . self::MARKER_CLASS . ' ' . self::MARKER_CLASS . '--overlay">' . $line . '</span>';

        return substr($html, 0, $pos) . $span . substr($html, $pos);
    }

    public static function root_tag(string $html): string
    {
        return preg_match('/^\s*<([a-z0-9-]+)/i', $html, $m) === 1 ? strtolower($m[1]) : '';
    }

    /**
     * The machine-readable half: a data attribute on every image WordPress
     * renders through wp_get_attachment_image(), which is how Bricks emits the
     * Image element (image.php:1153).
     *
     * Not a provenance marking in the sense of AI Act Art. 50(2) — it does not
     * survive the file leaving the page. It is the cheap part that helps.
     *
     * @param mixed $attr
     * @param mixed $attachment
     * @return mixed
     */
    public static function image_attributes($attr, $attachment)
    {
        if (!is_array($attr)) {
            return $attr;
        }

        $id = is_object($attachment) ? (int) ($attachment->ID ?? 0) : (int) $attachment;

        if ($id <= 0) {
            return $attr;
        }

        $ai_key = Credit::for($id)['ai_key'];

        if ($ai_key !== '') {
            $attr['data-sfx-ai'] = esc_attr($ai_key);
        }

        return $attr;
    }
```

Create `inc/MediaCredits/assets/media-credits.css`:

```css
/* Media Credits — overlay mode only. Loaded when output_mode is 'overlay'. */

.sfx-credit--overlay {
  position: absolute;
  inset-block-end: 0;
  inset-inline-end: 0;
  padding: 0.25em 0.5em;
  background: rgb(0 0 0 / 0.55);
  color: #fff;
  font-size: 0.75rem;
  line-height: 1.3;
  text-align: end;
}

/* The backing is not decoration: credit text sits on arbitrary photography,
   and without it no colour choice can meet WCAG AA contrast. */

.sfx-credit__seal {
  display: inline-block;
  vertical-align: text-bottom;
}

/* Position the parent without touching markup we did not create. Any root tag
   qualifies, because the behaviour table allows any. Without :has() the credit
   renders in flow instead of overlaid — degraded, not broken. */
*:has(> .sfx-credit--overlay) {
  position: relative;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/media-credits-bricks-test.php`
Expected: `PASS: all media-credits bricks tests`

- [ ] **Step 5: Commit**

```bash
git add inc/MediaCredits/Bricks.php inc/MediaCredits/assets/media-credits.css tests/media-credits-bricks-test.php
git commit -m "feat(media-credits): overlay output and machine-readable AI attribute"
```

---

### Task 9: Settings page and the seal uploader

**Files:**
- Create: `inc/MediaCredits/AdminPage.php`, `inc/MediaCredits/assets/media-credits-admin.js`
- Test: none automated — this is admin form markup, verified in Task 12

**Interfaces:**
- Consumes: `Settings::OPTION_NAME`, `Settings::OPTION_GROUP`, `Settings::get_defaults()`, `Settings::get_labels()`, `Settings::OUTPUT_MODES`, `Settings::CREDIT_DISPLAYS`.
- Produces: `AdminPage::$menu_slug`, `AdminPage::$page_title`, `AdminPage::$description`, `AdminPage::register(): void`, `AdminPage::add_submenu_page(): void`, `AdminPage::render_page(): void`, `AdminPage::enqueue(string $hook): void`.

- [ ] **Step 1: Write the page**

Create `inc/MediaCredits/AdminPage.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\MediaCredits;

class AdminPage
{
    public static string $menu_slug  = 'sfx-media-credits';
    public static string $page_title = 'Media Credits';
    public static string $description = 'Copyright notices and AI markings on media, with optional automatic output in Bricks image elements.';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_submenu_page']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function add_submenu_page(): void
    {
        if (!\SFX\AccessControl::can_access_theme_settings()) {
            return;
        }

        add_submenu_page(
            'sfx-theme-settings',
            self::$page_title,
            self::$page_title,
            'manage_options',
            self::$menu_slug,
            [self::class, 'render_page']
        );
    }

    public static function enqueue(string $hook): void
    {
        if (strpos($hook, self::$menu_slug) === false) {
            return;
        }

        wp_enqueue_media();

        $path = get_stylesheet_directory() . '/inc/MediaCredits/assets/media-credits-admin.js';

        if (!file_exists($path)) {
            return;
        }

        wp_enqueue_script(
            'sfx-media-credits-admin',
            get_stylesheet_directory_uri() . '/inc/MediaCredits/assets/media-credits-admin.js',
            ['jquery'],
            (string) filemtime($path),
            true
        );

        wp_localize_script('sfx-media-credits-admin', 'sfxMediaCredits', [
            'frameTitle' => __('Choose a seal image', 'sfxtheme'),
            'frameButton' => __('Use this image', 'sfxtheme'),
        ]);
    }

    public static function render_page(): void
    {
        \SFX\AccessControl::die_if_unauthorized_theme();

        $defaults = Settings::get_defaults();
        $options  = wp_parse_args(get_option(Settings::OPTION_NAME, []), $defaults);
        $labels   = Settings::get_labels();
        ?>
        <div class="wrap sfx-media-credits" style="padding: 0; font-size: 14px;">
            <div class="sfx-flex">
                <div class="sfx-col" style="width: 50%;">
                    <div class="sfx-card">
                        <h1 class="sfx-title"><?php esc_html_e('Media Credits', 'sfxtheme'); ?></h1>
                        <form method="post" action="options.php">
                            <?php settings_fields(Settings::OPTION_GROUP); ?>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><label for="sfx-mc-output-mode"><?php esc_html_e('Automatic output', 'sfxtheme'); ?></label></th>
                                    <td>
                                        <select id="sfx-mc-output-mode" name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[output_mode]">
                                            <?php
                                            $mode_labels = [
                                                'off'     => __('Off — place {sfx_media_credit} yourself', 'sfxtheme'),
                                                'caption' => __('Caption (recommended)', 'sfxtheme'),
                                                'overlay' => __('Overlay on the image', 'sfxtheme'),
                                            ];
                                            foreach (Settings::OUTPUT_MODES as $mode) {
                                                printf(
                                                    '<option value="%s"%s>%s</option>',
                                                    esc_attr($mode),
                                                    selected($options['output_mode'], $mode, false),
                                                    esc_html($mode_labels[$mode])
                                                );
                                            }
                                            ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Force a wrapper', 'sfxtheme'); ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[force_wrapper]" value="1" <?php checked(!empty($options['force_wrapper'])); ?>>
                                            <?php esc_html_e('Overlay mode only: give image elements that have no HTML tag a figure wrapper to attach the credit to.', 'sfxtheme'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sfx-mc-display"><?php esc_html_e('AI marking style', 'sfxtheme'); ?></label></th>
                                    <td>
                                        <select id="sfx-mc-display" name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[credit_display]">
                                            <?php
                                            $display_labels = [
                                                'text'      => __('Text only', 'sfxtheme'),
                                                'icon'      => __('Seal only', 'sfxtheme'),
                                                'icon_text' => __('Seal and text', 'sfxtheme'),
                                            ];
                                            foreach (Settings::CREDIT_DISPLAYS as $display) {
                                                printf(
                                                    '<option value="%s"%s>%s</option>',
                                                    esc_attr($display),
                                                    selected($options['credit_display'], $display, false),
                                                    esc_html($display_labels[$display])
                                                );
                                            }
                                            ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sfx-mc-icon-size"><?php esc_html_e('Seal size (px)', 'sfxtheme'); ?></label></th>
                                    <td>
                                        <input type="number" id="sfx-mc-icon-size" name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[icon_size]"
                                               value="<?php echo esc_attr((string) $options['icon_size']); ?>"
                                               min="<?php echo esc_attr((string) Settings::ICON_SIZE_MIN); ?>"
                                               max="<?php echo esc_attr((string) Settings::ICON_SIZE_MAX); ?>" step="1">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="sfx-mc-fallback"><?php esc_html_e('Fallback copyright', 'sfxtheme'); ?></label></th>
                                    <td>
                                        <input type="text" class="regular-text" id="sfx-mc-fallback"
                                               name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[fallback_copyright]"
                                               value="<?php echo esc_attr((string) $options['fallback_copyright']); ?>">
                                        <p class="description"><?php esc_html_e('Used when an attachment has no copyright of its own. Careful: this gives EVERY image a credit, logos and icons included. Leave empty unless the site owns its imagery.', 'sfxtheme'); ?></p>
                                    </td>
                                </tr>
                            </table>

                            <h2 class="sfx-section-title"><?php esc_html_e('AI seals', 'sfxtheme'); ?></h2>
                            <table class="form-table" role="presentation">
                                <?php foreach ($labels as $slug => $label) :
                                    $field = 'seal_' . $slug;
                                    $id    = (int) ($options[$field] ?? 0);
                                    $url   = $id > 0 ? wp_get_attachment_image_url($id, 'thumbnail') : '';
                                    ?>
                                    <tr>
                                        <th scope="row"><?php echo esc_html($label); ?></th>
                                        <td class="sfx-mc-seal" data-field="<?php echo esc_attr($field); ?>">
                                            <input type="hidden" name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[<?php echo esc_attr($field); ?>]"
                                                   value="<?php echo esc_attr((string) $id); ?>" class="sfx-mc-seal-input">
                                            <img src="<?php echo esc_url($url); ?>" alt="<?php echo esc_attr($label); ?>"
                                                 class="sfx-mc-seal-preview" style="max-width:48px;height:auto;vertical-align:middle;<?php echo $url ? '' : 'display:none;'; ?>">
                                            <button type="button" class="button sfx-mc-seal-choose"><?php esc_html_e('Choose image', 'sfxtheme'); ?></button>
                                            <button type="button" class="button-link sfx-mc-seal-remove" <?php echo $url ? '' : 'style="display:none;"'; ?>><?php esc_html_e('Remove', 'sfxtheme'); ?></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>

                            <?php submit_button(); ?>
                        </form>
                    </div>
                </div>
                <div class="sfx-col" style="width: 50%; min-height: 100vh;">
                    <div class="sfx-card">
                        <h2 class="sfx-section-title"><?php esc_html_e('How output works', 'sfxtheme'); ?></h2>
                        <ul class="sfx-tips-list">
                            <li><?php esc_html_e('Place {sfx_media_credit} in an image element\'s Custom caption to control exactly where the credit appears. This works everywhere and is unaffected by the limits below.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Caption mode is the reliable automatic mode: it writes the credit into the element\'s own caption, so Bricks renders valid markup and styles it with its own caption controls.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Overlay mode needs something to attach to. Set the image element\'s HTML tag to figure, or switch on "Force a wrapper" above.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Overlay cannot attach to a responsive image using Sources that has neither a link nor a caption — Bricks makes that a picture element. Use caption mode for those.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('While editing, an overlay may only appear after the canvas reloads. Bricks does not run the output filter when it re-renders a single element.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Add the CSS class no-credit to an image element to exclude it from automatic output.', 'sfxtheme'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
```

- [ ] **Step 2: Write the uploader script**

Create `inc/MediaCredits/assets/media-credits-admin.js`:

```js
/* Media Credits — seal picker. One media frame per field, reused. */
(function ($) {
    'use strict';

    $(document).on('click', '.sfx-mc-seal-choose', function (event) {
        event.preventDefault();

        var $cell = $(this).closest('.sfx-mc-seal');
        var frame = $cell.data('frame');

        if (!frame) {
            frame = wp.media({
                title: (window.sfxMediaCredits && window.sfxMediaCredits.frameTitle) || 'Choose an image',
                button: { text: (window.sfxMediaCredits && window.sfxMediaCredits.frameButton) || 'Use this image' },
                library: { type: 'image' },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                var url = (attachment.sizes && attachment.sizes.thumbnail)
                    ? attachment.sizes.thumbnail.url
                    : attachment.url;

                $cell.find('.sfx-mc-seal-input').val(attachment.id);
                $cell.find('.sfx-mc-seal-preview').attr('src', url).show();
                $cell.find('.sfx-mc-seal-remove').show();
            });

            $cell.data('frame', frame);
        }

        frame.open();
    });

    // Without this a chosen seal could never be unchosen.
    $(document).on('click', '.sfx-mc-seal-remove', function (event) {
        event.preventDefault();

        var $cell = $(this).closest('.sfx-mc-seal');

        $cell.find('.sfx-mc-seal-input').val('0');
        $cell.find('.sfx-mc-seal-preview').attr('src', '').hide();
        $(this).hide();
    });
}(jQuery));
```

- [ ] **Step 3: Check both files parse**

Run: `php -l inc/MediaCredits/AdminPage.php && node --check inc/MediaCredits/assets/media-credits-admin.js`
Expected: `No syntax errors detected`, and no output from `node --check`. If `node` is unavailable, skip the second half and rely on Task 12's browser check.

- [ ] **Step 4: Commit**

```bash
git add inc/MediaCredits/AdminPage.php inc/MediaCredits/assets/media-credits-admin.js
git commit -m "feat(media-credits): settings page with seal uploader"
```

---

### Task 10: Controller, feature toggle, settings overview

**Files:**
- Create: `inc/MediaCredits/Controller.php`
- Modify: `inc/MediaCredits/MediaLibrary.php` (register the meta), `inc/GeneralThemeOptions/Settings.php`, `inc/ThemeSettingsOverview/OverviewProvider.php`
- Test: `php tests/psr4-path-case-test.php` plus the full suite

**Interfaces:**
- Consumes: every class above.
- Produces: `Controller::get_feature_config(): array`, `Controller::enqueue_frontend(): void`, `MediaLibrary::register_meta(): void`.

- [ ] **Step 1: Write the controller**

Create `inc/MediaCredits/Controller.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\MediaCredits;

/**
 * Copyright notices and AI markings on media attachments.
 *
 * Opt-in: only constructed when `enable_media_credits` is on in
 * `sfx_general_options`. Registers hooks and holds no logic of its own.
 */
class Controller
{
    public function __construct()
    {
        Settings::register();
        AdminPage::register();
        MediaLibrary::register();
        Bricks::register();

        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend'], 20);
    }

    /**
     * The overlay stylesheet, and only in overlay mode. Caption mode renders
     * through Bricks' own caption and needs nothing from us.
     */
    public function enqueue_frontend(): void
    {
        if ((string) Settings::get('output_mode') !== 'overlay') {
            return;
        }

        $path = get_stylesheet_directory() . '/inc/MediaCredits/assets/media-credits.css';

        if (!file_exists($path)) {
            return;
        }

        wp_enqueue_style(
            'sfx-media-credits',
            get_stylesheet_directory_uri() . '/inc/MediaCredits/assets/media-credits.css',
            [],
            (string) filemtime($path)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_feature_config(): array
    {
        return [
            'class'                  => self::class,
            'menu_slug'              => AdminPage::$menu_slug,
            'page_title'             => AdminPage::$page_title,
            'description'            => AdminPage::$description,
            'activation_option_name' => 'sfx_general_options',
            'activation_option_key'  => 'enable_media_credits',
            'option_value'           => true,
            'hook'                   => null,
            'error'                  => 'Missing MediaCredits Controller class in theme',
        ];
    }
}
```

- [ ] **Step 2: Register the meta**

In `inc/MediaCredits/MediaLibrary.php`, add to `register()`:

```php
        add_action('init', [self::class, 'register_meta']);
```

and the method:

```php
    /**
     * Underscore-prefixed so the keys stay out of the Custom Fields box, and
     * out of REST: this is not a public API, it is two fields and a marker.
     */
    public static function register_meta(): void
    {
        register_meta('post', Credit::META_COPYRIGHT, [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_meta('post', Credit::META_AI, [
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => [self::class, 'sanitize_ai_key'],
        ]);

        register_meta('post', Credit::META_IPTC_MARKER, [
            'type'         => 'string',
            'single'       => true,
            'show_in_rest' => false,
        ]);
    }
```

- [ ] **Step 3: Add the feature toggle**

In `inc/GeneralThemeOptions/Settings.php`, add after the `enable_nav_menu_query` entry:

```php
            [
                'id'          => 'enable_media_credits',
                'label'       => __('Enable Media Credits', 'sfxtheme'),
                'description' => __('Adds a copyright notice and an AI marking to every media attachment, usable in Bricks image elements.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'general',
            ],
```

- [ ] **Step 4: Add the overview entry**

In `inc/ThemeSettingsOverview/OverviewProvider.php`, in `build_builtin_modules_group()`, after `enable_nav_menu_query`:

```php
            'enable_media_credits' => [
                'label' => __('Media Credits', 'sfxtheme'),
            ],
```

- [ ] **Step 5: Do NOT add a `handle_*()` to `GeneralThemeOptions\Controller`**

This is a deliberate omission, not a forgotten step. `ImageOptimizer`, `SecurityHeader`, `SmoothScroll` and `WPOptimizer` each have a `handle_*()` there that calls `Settings::delete_all_options()` when their toggle is off. This module must not. Seal assignments and the fallback notice are configuration a site should get back when it re-enables the feature, and the attachment meta is content that must survive regardless. `PasswordProtected` and `NavMenuQuery` already set that precedent.

If you find yourself adding one because "the other modules have it", re-read the Meta lifecycle section of the spec.

- [ ] **Step 6: Run the structural tests**

Run: `php tests/psr4-path-case-test.php && php tests/theme-settings-overview-provider-test.php`
Expected: both print their PASS line. The PSR-4 test walks `inc/` and would catch a namespace that does not match its directory.

- [ ] **Step 7: Commit**

```bash
git add inc/MediaCredits/Controller.php inc/MediaCredits/MediaLibrary.php inc/GeneralThemeOptions/Settings.php inc/ThemeSettingsOverview/OverviewProvider.php
git commit -m "feat(media-credits): register the feature and its toggle"
```

---

### Task 11: Import/export and uninstall

**Files:**
- Modify: `inc/ImportExport/Controller.php`, `uninstall.php`
- Modify: `tests/media-credits-credit-test.php` (insert before the epilogue)
- Test: `tests/media-credits-credit-test.php`

**Interfaces:**
- Consumes: `Settings::OPTION_NAME`, `Credit::META_*`.
- Produces: a `media_credits` entry in `ImportExport\Controller::get_settings_groups()`; a `subset` group type accepted alongside `dashboard_subset`.

- [ ] **Step 1: Write the failing test**

Insert before the epilogue of `tests/media-credits-credit-test.php`:

```php
// -------------------------------------- Case 9: what travels in an export
// Seal ids are attachment ids. On another site the same number points at some
// other image, which would then be presented as an AI seal. They stay home.

$export_group = null;

foreach (file(dirname(__DIR__) . '/inc/ImportExport/Controller.php') as $line) {
    if (strpos($line, "'media_credits'") !== false) {
        $export_group = true;
        break;
    }
}

assert_same(true, $export_group, 'Case 9a: the module has an export group');

$import_export = file_get_contents(dirname(__DIR__) . '/inc/ImportExport/Controller.php');

assert_contains("'option_key'  => 'sfx_media_credits_options'", $import_export, 'Case 9b: it exports our option');
assert_contains("'type'        => 'subset'", $import_export, 'Case 9c: as a field subset');
assert_not_contains("'seal_ai_generated'", $import_export, 'Case 9d: no seal id is listed as exportable');
assert_contains("['subset', 'dashboard_subset']", $import_export, 'Case 9e: both type spellings are accepted, so the dashboard groups keep working');

$uninstall = file_get_contents(dirname(__DIR__) . '/uninstall.php');

assert_contains("'sfx_media_credits_options'", $uninstall, 'Case 9f: the option is purged');
assert_contains(Credit::META_COPYRIGHT, $uninstall, 'Case 9g: the copyright meta is purged');
assert_contains(Credit::META_AI, $uninstall, 'Case 9h: the AI meta is purged');
assert_contains(Credit::META_IPTC_MARKER, $uninstall, 'Case 9i: the IPTC marker goes too, or a reinstall skips the prefill');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/media-credits-credit-test.php`
Expected: `FAIL: Case 9a: the module has an export group` and the rest of the block.

- [ ] **Step 3: Rename the subset type**

In `inc/ImportExport/Controller.php` there are exactly two comparisons against the group type (in `collect_settings_data()` and in the import branch). Change both from:

```php
                } elseif ($group['type'] === 'dashboard_subset') {
```

to:

```php
                } elseif (in_array($group['type'], ['subset', 'dashboard_subset'], true)) {
```

The mechanism is already fully generic — subset-of-one-option, nothing dashboard-specific in either branch. The eight dashboard groups keep their existing `dashboard_subset` type and keep working; new modules use the honest name.

- [ ] **Step 4: Add the export group**

In `get_settings_groups()`, after the `smooth_scroll_options` entry:

```php
            'media_credits' => [
                'label' => __('Media Credits Settings', 'sfxtheme'),
                'description' => __('Copyright and AI-labelling output settings', 'sfxtheme'),
                'option_key'  => 'sfx_media_credits_options',
                'type'        => 'subset',
                'fields'      => ['output_mode', 'force_wrapper', 'credit_display', 'icon_size', 'fallback_copyright'],
            ],
            // NOTE: the seal_* attachment ids are deliberately NOT exported.
            // They are meaningful only on the site that stored them; on the
            // target site the same id resolves to whatever image happens to
            // hold it, which would then be presented as an AI seal. The subset
            // importer merges named fields into the existing option, so the
            // target site's own seals survive an import untouched.
```

- [ ] **Step 5: Extend the uninstall list**

In `uninstall.php`, add to `$options_to_delete` after the Smooth Scroll entry:

```php
    // Media Credits
    'sfx_media_credits_options',
```

and after the `foreach` that deletes the options, before the transient purge:

```php
// Media Credits attachment meta. Content, not configuration — deleted only
// here, on the explicit delete_on_uninstall opt-in, never when the feature is
// merely switched off.
//
// NOTE: WordPress does not execute a theme's uninstall.php at all — the
// convention is plugin-only (wp-admin/includes/plugin.php:1284, :1317-1327),
// and delete_theme() never includes it. This block is correct and currently
// inert, like every other purge in this file.
foreach (['_sfx_media_copyright', '_sfx_media_ai', '_sfx_media_iptc_prefilled'] as $meta_key) {
    delete_post_meta_by_key($meta_key);
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php tests/media-credits-credit-test.php`
Expected: `PASS: all media-credits credit tests`

- [ ] **Step 7: Commit**

```bash
git add inc/ImportExport/Controller.php uninstall.php tests/media-credits-credit-test.php
git commit -m "feat(media-credits): dock into import/export and uninstall"
```

---

### Task 12: Full suite and manual verification

**Files:** none — this task changes nothing, it proves the rest.

- [ ] **Step 1: Run every test**

Run: `for f in tests/*-test.php; do printf "%s: " "$f"; php "$f" 2>&1 | tail -1; done`
Expected: one line per file, none containing `FAIL` or `Tests failed`, and three new `PASS: all media-credits …` lines among them.

- [ ] **Step 2: Lint every new file**

Run: `for f in inc/MediaCredits/*.php; do php -l "$f"; done`
Expected: `No syntax errors detected` for each.

- [ ] **Step 3: Switch the feature on**

In wp-admin → SFX Theme Settings → General, tick **Enable Media Credits**, save. Expected: a *Media Credits* entry appears in the theme settings menu and in the settings overview.

- [ ] **Step 4: Check the attachment fields**

Open an image in the media library, both in the grid modal and on the full edit screen. Expected: a **Copyright** text field and an **AI marking** select with five options (*No marking* plus the four labels). Enter a copyright and pick *KI-generiert*, save, reload — both values persist.

- [ ] **Step 5: Check the list column and filter**

Go to the media library in **list** view. Expected: a *Credit* column showing what you entered, and a filter dropdown. Filter by *Without copyright* and by *KI-generiert* — the result set changes accordingly. Set a fallback copyright in the settings and reload: the column shows it greyed and marked as a fallback, and the *Without copyright* filter still lists that row.

- [ ] **Step 6: Check the IPTC prefill**

Upload an image that carries an IPTC copyright field. Expected: the Copyright field is prefilled. Then clear the field, save, and regenerate thumbnails for that attachment (any regeneration plugin, or re-upload metadata). Expected: **the field stays empty.** This is the data-loss guard; if the value comes back, the marker is not being written or not being read.

- [ ] **Step 7: Check the tag in a Bricks caption**

In Bricks, add an Image element, pick the attachment from step 4, set *Caption type* to **Custom** and enter `{sfx_media_credit}`. Expected on the frontend: a `<figcaption>` containing the credit — copyright, separator, AI label — and no literal `{sfx_media_credit}` anywhere.

- [ ] **Step 8: Check caption auto-output**

Set *Automatic output* to **Caption**. Add a second Image element with no caption configured, using an attachment that has a copyright. Expected: the credit appears as a caption without touching the element. On the element from step 7, expected: **exactly one** credit, not two — that is the dedup rule.

- [ ] **Step 9: Check overlay mode and its limits**

Set *Automatic output* to **Overlay**. Expected: on an image element whose *HTML tag* is `figure`, the credit sits over the bottom-right corner with a dark backing. On an element with no HTML tag and *Force a wrapper* off, expected: **nothing appears and the layout does not move.** Switch *Force a wrapper* on and reload: the credit appears. Add the class `no-credit` to an element: its credit disappears while `data-sfx-ai` stays on the `<img>` (check in devtools).

- [ ] **Step 10: Check the seal**

Upload a small badge image, assign it to *KI-generiert* in the settings, set the style to **Seal only** and reload the frontend. Expected: the badge renders at the configured size with the label as its `alt`. Switch to **Seal and text**: the badge's `alt` is now empty and the label is visible as text. Press *Remove* on the seal field, save: the field returns to no seal and the frontend falls back to the label text.

- [ ] **Step 11: Check the escaping boundary**

Set an attachment's copyright to `{echo:phpinfo}` and view a page showing it. Expected: the literal text `{echo:phpinfo}` on screen. If a PHP info dump or an empty caption appears instead, the brace escaping is not reaching that path — stop and fix before going further.

- [ ] **Step 12: Check export and import**

Theme settings → Import/Export: export with *Media Credits Settings* selected. Expected: the JSON contains `output_mode` and `fallback_copyright` and **no** `seal_*` key. Import the file back and confirm the eight dashboard groups still export and import as before — that is the regression risk of the type rename.

- [ ] **Step 13: Switch the feature off**

Untick **Enable Media Credits**, save, then check an attachment's meta in the database (or re-enable and reload the edit screen). Expected: the copyright and AI values are **still there**. Switching off hides the feature; it must never discard content.

- [ ] **Step 14: Record the results**

Report which steps passed and which did not, quoting what you actually saw. Any step you did not run is reported as not run — not as passed.
