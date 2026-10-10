<?php

declare(strict_types=1);

/**
 * SiteCheck dashboard box (spec "Dashboard box", acceptance 14): reads the
 * stored run only, shows check names and counts, is absent for users
 * without access, never writes and never takes the mutex.
 *
 * Run: php tests/site-check-dashboard-box-test.php
 */

define('ABSPATH', dirname(__DIR__) . '/../../../../');

$stub = ['access' => true, 'manage' => true, 'options' => [], 'writes' => 0, 'widgets' => [], 'filters' => []];
$failures = 0;

eval('namespace SFX; class AccessControl { public static function can_access_theme_settings(): bool { return !empty($GLOBALS["stub"]["access"]); } }');

function __($text, $domain = null)
{
    return $text === 'Public log files' ? '<script>alert(1)</script>' : $text;
}
function _x($text, $context, $domain = null)
{
    $GLOBALS['stub']['contexts'][] = $context;
    return $context === 'site check status' && $text === 'Note' ? 'Hinweis' : $text;
}
function _n($single, $plural, $number, $domain = null)
{
    return $number === 1 ? $single : $plural;
}
function esc_html($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function esc_html__($text, $domain = null): string
{
    return esc_html(__($text, $domain));
}
function esc_attr($text): string
{
    return esc_html($text);
}
function esc_url($url): string
{
    return esc_html($url);
}
function admin_url($path = ''): string
{
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}
function wp_timezone(): DateTimeZone
{
    return new DateTimeZone((string) get_option('timezone_string', '') ?: 'UTC');
}
function wp_date($format, $timestamp = null, $timezone = null): string
{
    return (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone($timezone ?? wp_timezone())->format($format);
}
function get_option($name, $default = false)
{
    // The site's date settings: a non-UTC zone and a non-default format.
    $options = $GLOBALS['stub']['options'] + ['date_format' => 'd.m.Y', 'time_format' => 'H:i', 'timezone_string' => 'Europe/Berlin'];
    return array_key_exists($name, $options) ? $options[$name] : $default;
}
function update_option(...$a): bool
{
    $GLOBALS['stub']['writes']++;
    return true;
}
function add_option(...$a): bool
{
    $GLOBALS['stub']['writes']++;
    return true;
}
function delete_option(...$a): bool
{
    $GLOBALS['stub']['writes']++;
    return true;
}
function current_user_can($cap): bool
{
    return $cap === 'manage_options' && !empty($GLOBALS['stub']['manage']);
}
function wp_add_dashboard_widget($id, $title, $callback): void
{
    $GLOBALS['stub']['widgets'][$id] = [$title, $callback];
}
function add_filter($hook, $callback, $priority = 10, $args = 1): bool
{
    $GLOBALS['stub']['filters'][$hook][] = $callback;
    return true;
}
function add_action(...$a): bool
{
    return true;
}

$src = dirname(__DIR__) . '/inc/SiteCheck/';
foreach (['Options', 'Status', 'Access', 'Catalogue', 'Runs', 'AdminPage', 'DashboardBox'] as $file) {
    if (!is_file($src . $file . '.php')) {
        echo "FAIL: {$file}.php missing\n";
        exit(1);
    }
    require $src . $file . '.php';
}

use SFX\SiteCheck\DashboardBox;
use SFX\SiteCheck\Options;

function assert_true(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        echo "FAIL: {$message}\n";
        $failures++;
    }
}

function box(): string
{
    ob_start();
    DashboardBox::render();
    return (string) ob_get_clean();
}

function run(array $statuses, string $profile = 'live'): array
{
    $results = [];
    foreach ($statuses as $check => $status) {
        $results[$check] = [
            'status' => $status,
            'findings' => [['id' => 'f-' . $check, 'status' => $status, 'label' => '/wp-content/uploads/secret-' . $check . '.sql']],
            'perspective' => 'server',
            'note' => '',
        ];
    }
    // 10.10.2026 22:30 UTC: already 11.10.2026 in the site's zone.
    return ['saved' => ['run' => 'r1', 'date' => gmmktime(22, 30, 0, 10, 10, 2026), 'user' => 3, 'profile' => $profile, 'results' => $results]];
}

// Fresh site.
$out = box();
assert_true(str_contains($out, 'Not checked yet'), 'fresh site says not checked yet (German in the catalogue)');
assert_true(str_contains($out, 'tools.php?page=sfx-site-check#uebersicht'), '"Jetzt prüfen" links to the Übersicht tab');

// Stored run: counts, at most three Rot titles, Sicherheit first.
$stub['options'][Options::name('manual')] = run([
    'search_visibility' => 'red',       // go-live
    'config_copies' => 'red',           // security
    'logs_public' => 'red',             // security, title is hostile
    'phpinfo' => 'red',                 // security
    'robots_txt' => 'red',              // go-live
    'https' => 'yellow',
    'xmlrpc' => 'unknown',
    'file_editor' => 'green',
    'table_prefix' => 'hint',
]);
$stub['writes'] = 0;
$out = box();
assert_true(str_contains($out, '5'), 'five red counted');
assert_true(preg_match('/data-count="red">5</', $out) === 1, 'red count is 5');
assert_true(preg_match('/data-count="yellow">1</', $out) === 1, 'yellow count is 1');
assert_true(preg_match('/data-count="unknown">1</', $out) === 1, 'unknown count is 1');
assert_true(substr_count($out, '<li') === 3, 'at most three red titles listed');
assert_true(str_contains($out, 'Copies of wp-config.php') && str_contains($out, 'phpinfo and test scripts'), 'security reds listed');
assert_true(!str_contains($out, 'Search engine visibility'), 'go-live red not listed while three security reds exist');
assert_true(str_contains($out, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($out, '<script>'), 'check title is escaped at output');
assert_true(!str_contains($out, 'secret-') && !str_contains($out, '/wp-content') && !str_contains($out, '.sql'), 'no finding label, path or file name in the output');
assert_true(str_contains($out, '11.10.2026 00:30'), 'run date in the site\'s zone and format');
assert_true(str_contains($out, 'Live'), 'profile shown');
assert_true(!stripos($out, 'monitor'), 'no monitoring line in plan 1');
assert_true($stub['writes'] === 0, 'render wrote no option');
assert_true(!isset($GLOBALS['wpdb']), 'render never touched the mutex table');

// A check counts once, by its status: Rot with a Nicht prüfbar finding is one Rot; titles are distinct.
$stub['options'][Options::name('manual')] = run(['config_copies' => 'red']);
$runs = $stub['options'][Options::name('manual')];
$runs['saved']['results']['config_copies']['findings'][] = ['id' => 'config_copies:x', 'status' => 'unknown', 'label' => 'x'];
$runs['saved']['results']['config_copies']['findings'][] = ['id' => 'config_copies:y', 'status' => 'red', 'label' => 'y'];
$stub['options'][Options::name('manual')] = $runs;
$out = box();
assert_true(preg_match('/data-count="red">1</', $out) === 1 && preg_match('/data-count="unknown">0</', $out) === 1, 'a check with Rot and Nicht prüfbar findings counts once, as Rot');
assert_true(substr_count($out, 'Copies of wp-config.php') === 1, 'each Rot check title once');

// The status words come from one place; "Hinweis" is the site-check context of "Note".
$stub['contexts'] = [];
assert_true(\SFX\SiteCheck\Status::labels()['hint'] === 'Hinweis' && in_array('site check status', $stub['contexts'], true), 'Hinweis via _x(\'Note\', \'site check status\')');

// Go-live red appears once security reds are fewer than three.
$stub['options'][Options::name('manual')] = run(['config_copies' => 'red', 'search_visibility' => 'red']);
$out = box();
assert_true(substr_count($out, '<li') === 2 && str_contains($out, 'Search engine visibility'), 'go-live red follows security red');

// A run without reds.
$stub['options'][Options::name('manual')] = run(['https' => 'green']);
$out = box();
assert_true(substr_count($out, '<li') === 0, 'no list without reds');

// Access.
$stub['access'] = false;
assert_true(box() === '', 'no theme access: nothing rendered');
$stub['access'] = true;
$stub['manage'] = false;
assert_true(box() === '', 'no manage_options: nothing rendered');
$stub['widgets'] = [];
DashboardBox::register_native();
assert_true($stub['widgets'] === [], 'no manage_options: native widget not registered');
$stub['access'] = false;
$stub['manage'] = true;
DashboardBox::register_native();
assert_true($stub['widgets'] === [], 'no theme access: native widget not registered');
$stub['access'] = true;
DashboardBox::register_native();
assert_true(isset($stub['widgets']['sfx_site_check']) && $stub['widgets']['sfx_site_check'][0] === 'Sicherheits-Check', 'allowed user: native widget registered as sfx_site_check');

// Filter entry.
$entries = DashboardBox::widgets(['x' => 'kept']);
assert_true(($entries['x'] ?? '') === 'kept', 'filter keeps entries of other modules');
$entry = $entries['sfx_site_check'] ?? [];
assert_true(is_callable($entry['render'] ?? null) && is_callable($entry['can_render'] ?? null) && ($entry['title'] ?? '') === 'Sicherheits-Check', 'filter entry has title, render, can_render');
assert_true(($entry['can_render'])() === true, 'can_render true for an allowed user');
$stub['manage'] = false;
assert_true(($entry['can_render'])() === false, 'can_render false without manage_options');
$stub['manage'] = true;
$stub['access'] = false;
assert_true(($entry['can_render'])() === false, 'can_render false without theme access');

echo $failures === 0 ? "OK\n" : "{$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
