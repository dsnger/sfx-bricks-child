<?php

declare(strict_types=1);

/**
 * CustomDashboard's extension filter `sfx/custom_dashboard/widgets`:
 * entries reach the picker (off by default), the renderer's widget map and
 * the visibility check; malformed entries and built-in IDs are ignored.
 *
 * Run: php tests/custom-dashboard-widget-filter-test.php
 */

define('ABSPATH', dirname(__DIR__) . '/../../../../');

$stub = ['supplied' => [], 'caps' => ['read' => true], 'theme_access' => true];
$failures = 0;

eval('namespace SFX; class AccessControl { public static function can_access_theme_settings(): bool { return !empty($GLOBALS["stub"]["theme_access"]); } }');

function __($text, $domain = null)
{
    return $text;
}
function esc_html($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function apply_filters($hook, $value, ...$args)
{
    if ($hook === 'sfx/custom_dashboard/widgets') {
        return $GLOBALS['stub']['supplied'];
    }
    return $value;
}
function current_user_can($cap): bool
{
    return !empty($GLOBALS['stub']['caps'][$cap]);
}
function add_action(...$a): bool
{
    return true;
}
function add_filter(...$a): bool
{
    return true;
}

require dirname(__DIR__) . '/inc/CustomDashboard/Settings.php';
require dirname(__DIR__) . '/inc/CustomDashboard/DashboardRenderer.php';

use SFX\CustomDashboard\DashboardRenderer;
use SFX\CustomDashboard\Settings;

function assert_true(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        echo "FAIL: {$message}\n";
        $failures++;
    }
}

function renderer_call(string $method, string $widget_id)
{
    $ref = new ReflectionClass(DashboardRenderer::class);
    $obj = $ref->newInstanceWithoutConstructor();
    $m = $ref->getMethod($method);
    if (PHP_VERSION_ID < 80100) {
        $m->setAccessible(true);
    }
    ob_start();
    $result = $m->invoke($obj, $widget_id);
    $out = (string) ob_get_clean();
    return [$result, $out];
}

$ok = [
    'title' => 'Ext Box',
    'render' => static function (): void {
        echo '<p>ext-content</p>';
    },
    'can_render' => static fn (): bool => !empty($GLOBALS['stub']['ext_allowed']),
];

// Baseline: no entries, the existing list is unchanged.
$base = Settings::get_available_widgets();
assert_true(isset($base['sfx_theme_settings_overview']) && isset($base['dashboard_right_now']), 'built-in widgets listed');
assert_true(!isset($base['ext_box']), 'no external entry without the filter');

// A valid entry appears in the picker, unchecked by default.
$stub['supplied'] = ['ext_box' => $ok];
$avail = Settings::get_available_widgets();
assert_true(($avail['ext_box'] ?? '') === 'Ext Box', 'valid entry listed in the picker');
$default = null;
foreach (Settings::get_default_widgets_items() as $item) {
    if ($item['id'] === 'ext_box') {
        $default = $item;
    }
}
assert_true($default !== null && $default['enabled'] === false, 'entry is off by default');
assert_true(array_diff_key($base, $avail) === [], 'built-in entries all still present');

// Malformed entries and built-in collisions are ignored.
$stub['supplied'] = [
    'no_render' => ['title' => 'A', 'can_render' => '__return_true'],
    'no_can' => ['title' => 'B', 'render' => 'phpinfo'],
    'bad_callable' => ['title' => 'C', 'render' => 'no_such_fn_xyz', 'can_render' => 'no_such_fn_xyz'],
    'no_title' => ['render' => 'phpinfo', 'can_render' => 'phpinfo'],
    'not_array' => 'x',
    7 => $ok,
    'dashboard_right_now' => $ok,
    'sfx_theme_settings_overview' => $ok,
    'ext_ok' => $ok,
];
$avail = Settings::get_available_widgets();
foreach (['no_render', 'no_can', 'bad_callable', 'no_title', 'not_array', '7'] as $bad) {
    assert_true(!isset($avail[$bad]), "malformed entry {$bad} ignored");
}
assert_true($avail['dashboard_right_now'] === $base['dashboard_right_now'], 'built-in title not overridden by a colliding ID');
assert_true($avail['sfx_theme_settings_overview'] === $base['sfx_theme_settings_overview'], 'Theme Settings Overview entry unchanged');
assert_true(isset($avail['ext_ok']), 'valid entry beside malformed ones kept');
$stub['supplied'] = 'garbage';
assert_true(Settings::get_available_widgets() === $base, 'a non-array filter result is ignored');

// Visibility: the entry decides; a colliding built-in keeps its own gate.
$stub['supplied'] = ['ext_box' => $ok, 'dashboard_right_now' => $ok];
$stub['ext_allowed'] = false;
assert_true(renderer_call('can_render_dashboard_widget', 'ext_box')[0] === false, 'can_render false hides the entry');
$stub['ext_allowed'] = true;
assert_true(renderer_call('can_render_dashboard_widget', 'ext_box')[0] === true, 'can_render true shows the entry');
$stub['caps']['read'] = false;
assert_true(renderer_call('can_render_dashboard_widget', 'dashboard_right_now')[0] === false, 'colliding ID keeps the built-in capability gate');
$stub['caps']['read'] = true;
assert_true(renderer_call('can_render_dashboard_widget', 'sfx_theme_settings_overview')[0] === true, 'Theme Settings Overview gate unchanged');
$stub['theme_access'] = false;
assert_true(renderer_call('can_render_dashboard_widget', 'sfx_theme_settings_overview')[0] === false, 'Theme Settings Overview gate still the theme access check');
$stub['theme_access'] = true;

// Rendering: the entry's render output sits in the card; unknown or no-longer-supplied IDs render nothing.
$out = renderer_call('render_single_widget', 'ext_box')[1];
assert_true(str_contains($out, 'ext-content') && str_contains($out, 'Ext Box'), 'entry rendered inside the widget card with its title');
$stub['supplied'] = [];
assert_true(renderer_call('render_single_widget', 'ext_box')[1] === '', 'a saved ID no longer supplied renders nothing');
$stub['supplied'] = ['ext_box' => ['title' => 'X', 'render' => 'no_such_fn_xyz', 'can_render' => '__return_true']];
assert_true(renderer_call('render_single_widget', 'ext_box')[1] === '', 'a malformed entry renders nothing');

echo $failures === 0 ? "OK\n" : "{$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
