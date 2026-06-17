<?php

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/../../../../');

$test_options = [];
$test_wp_query_counts = [];
$test_can_access_dashboard_settings = false;
$failures = 0;

function __($text, $domain = 'default')
{
    return $text;
}

function _n($single, $plural, $number, $domain = 'default')
{
    return $number === 1 ? $single : $plural;
}

function get_option($name, $default = false)
{
    global $test_options;

    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}

function esc_html($text): string
{
    return (string) $text;
}

function esc_attr($text): string
{
    return (string) $text;
}

function esc_url($url): string
{
    return (string) $url;
}

class WP_Query
{
    public int $found_posts = 0;

    public function __construct(array $args = [])
    {
        global $test_wp_query_counts;
        $post_type = $args['post_type'] ?? '';
        $this->found_posts = (int) ($test_wp_query_counts[$post_type] ?? 0);
    }
}

require __DIR__ . '/support/overview-general-theme-options-settings-stub.php';
require __DIR__ . '/support/overview-wpoptimizer-settings-stub.php';
require __DIR__ . '/support/overview-sfx-stubs.php';
require dirname(__DIR__) . '/inc/ThemeSettingsOverview/SecurityHeaderStatusResolver.php';
require dirname(__DIR__) . '/inc/ThemeSettingsOverview/OverviewProvider.php';

use SFX\SFXBricksChildTheme;
use SFX\ThemeSettingsOverview\OverviewProvider;

function assert_true(bool $condition, string $message): void
{
    global $failures;
    if (! $condition) {
        echo "FAIL: {$message}\n";
        $failures++;
    }
}

function assert_status(array $data, string $item_id, string $expected, string $message): void
{
    assert_true(OverviewProvider::get_item_status($data, $item_id) === $expected, $message);
}

function reset_test_state(): void
{
    global $test_options, $test_wp_query_counts, $test_can_access_dashboard_settings;
    $test_options = [];
    $test_wp_query_counts = [];
    $test_can_access_dashboard_settings = false;
}

// Fresh install defaults
reset_test_state();
$data = OverviewProvider::get_data();
assert_true(OverviewProvider::has_group($data, 'builtin_modules'), 'Built-in Modules group present');
assert_true(OverviewProvider::has_group($data, 'wp_optimizer'), 'WP Optimizer detail group present by default');
assert_true(OverviewProvider::has_group($data, 'security_headers'), 'Security Headers group present by default');
assert_true(! OverviewProvider::has_group($data, 'image_optimizer'), 'Image Optimizer detail group removed');
assert_true(! OverviewProvider::has_group($data, 'theme_bricks'), 'Theme & Bricks group removed');
assert_true(! OverviewProvider::has_group($data, 'content_features'), 'Content Features group removed');
assert_true(! OverviewProvider::has_group($data, 'utilities'), 'Utilities group removed');
assert_status($data, 'enable_wp_optimizer', 'active', 'WP Optimizer module default active');
assert_status($data, 'enable_image_optimizer', 'active', 'Image Optimizer module default active');
assert_status($data, 'enable_security_header', 'active', 'Security Header module default active');
assert_status($data, 'enable_smooth_scroll', 'inactive', 'Smooth Scroll module default inactive');

// Security headers with unset disable flags
reset_test_state();
$data = OverviewProvider::get_data();
assert_status($data, 'hsts', 'active', 'HSTS active when disable flag unset');
assert_status($data, 'csp', 'active', 'CSP active when disable flag unset');
assert_status($data, 'x_frame_options', 'active', 'X-Frame active when disable flag unset');
assert_status($data, 'x_content_type_options', 'active', 'X-Content-Type active when disable flag unset');
assert_status($data, 'permissions_policy', 'inactive', 'Permissions-Policy inactive with empty policy');

// HSTS explicitly disabled
reset_test_state();
$test_options['sfx_disable_hsts_header'] = 1;
$data = OverviewProvider::get_data();
assert_status($data, 'hsts', 'inactive', 'HSTS inactive when disable flag set');
assert_status($data, 'csp', 'active', 'CSP still active when only HSTS disabled');

// Permissions-Policy via restrict flag
reset_test_state();
$test_options['sfx_restrict_sensitive_browser_features'] = 1;
$data = OverviewProvider::get_data();
assert_status($data, 'permissions_policy', 'active', 'Permissions-Policy active when restrict flag on');

// WP Optimizer module off
reset_test_state();
$test_options['sfx_general_options'] = ['enable_wp_optimizer' => 0];
$data = OverviewProvider::get_data();
assert_true(! OverviewProvider::has_group($data, 'wp_optimizer'), 'WP Optimizer group omitted when module off');

// WP Optimizer partial
reset_test_state();
$test_options['sfx_wpoptimizer_options'] = [
    'disable_jquery' => 1,
    'jquery_to_footer' => 0,
];
$data = OverviewProvider::get_data();
$performance_status = OverviewProvider::get_item_status($data, 'wp_optimizer_performance');
assert_true($performance_status === 'partial', 'WP Optimizer performance group is partial');
$jquery_status = OverviewProvider::get_item_status($data, 'disable_jquery');
assert_true($jquery_status === 'active', 'WP Optimizer disable_jquery child is active');
$footer_status = OverviewProvider::get_item_status($data, 'jquery_to_footer');
assert_true($footer_status === 'inactive', 'WP Optimizer jquery_to_footer child is inactive');

// is_general_option_enabled fallback
reset_test_state();
assert_true(SFXBricksChildTheme::is_general_option_enabled('enable_smooth_scroll') === false, 'Smooth scroll fallback default is false');
assert_true(SFXBricksChildTheme::is_general_option_enabled('enable_wp_optimizer') === true, 'WP Optimizer fallback default is true');

if ($failures > 0) {
    echo "{$failures} test(s) failed.\n";
    exit(1);
}

echo "All theme settings overview provider tests passed.\n";
exit(0);
