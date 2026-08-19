<?php

declare(strict_types=1);

/**
 * Pins the site-dashboard predicate used by Custom Dashboard injection
 * and asset enqueue. The HTML hooks fire on every admin index.php,
 * including /wp-admin/network/index.php (screen id dashboard-network)
 * and /wp-admin/user/index.php (dashboard-user). Styles only enqueue
 * when the screen id is exactly "dashboard". Without this guard the
 * custom dashboard HTML appears unstyled on those screens and the
 * inline hide-default-dashboard CSS breaks the native network UI.
 */

$test_is_blog_admin = true;
$test_screen = null;
$pagenow = 'index.php';

function is_blog_admin(): bool
{
    global $test_is_blog_admin;

    return $test_is_blog_admin;
}

function get_current_screen()
{
    global $test_screen;

    return $test_screen;
}

require_once __DIR__ . '/../inc/CustomDashboard/DashboardScreen.php';

use SFX\CustomDashboard\DashboardScreen;

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function screen(string $id): object
{
    return (object) ['id' => $id];
}

function reset_context(bool $is_blog_admin, ?object $screen, string $page = 'index.php'): void
{
    global $test_is_blog_admin, $test_screen, $pagenow;

    $test_is_blog_admin = $is_blog_admin;
    $test_screen = $screen;
    $pagenow = $page;
}

reset_context(true, screen('dashboard'));
assert_true(
    DashboardScreen::is_site_dashboard() === true,
    'Site dashboard (screen id dashboard) should opt in'
);

reset_context(false, screen('dashboard-network'));
assert_true(
    DashboardScreen::is_site_dashboard() === false,
    'Network dashboard must not receive the custom dashboard'
);

reset_context(false, screen('dashboard-user'));
assert_true(
    DashboardScreen::is_site_dashboard() === false,
    'User dashboard must not receive the custom dashboard'
);

reset_context(true, screen('plugins'));
assert_true(
    DashboardScreen::is_site_dashboard() === false,
    'Other site-admin screens should not match'
);

reset_context(true, null, 'index.php');
assert_true(
    DashboardScreen::is_site_dashboard() === true,
    'Site admin with no screen yet should fall back to pagenow=index.php'
);

reset_context(true, null, 'themes.php');
assert_true(
    DashboardScreen::is_site_dashboard() === false,
    'Site admin fallback should not match a non-dashboard pagenow'
);

reset_context(false, null, 'index.php');
assert_true(
    DashboardScreen::is_site_dashboard() === false,
    'Network/user admin fallback must not match pagenow=index.php'
);

echo "OK\n";
