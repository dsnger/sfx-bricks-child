<?php

declare(strict_types=1);

/**
 * handle_dashboard() runs on WordPress `init` (sfx_init_settings), including
 * frontend previews. get_current_screen() lives in wp-admin/includes/screen.php
 * and is not loaded there. Calling it fatals:
 * "Call to undefined function SFX\CustomDashboard\get_current_screen()".
 *
 * Frontend home also uses pagenow=index.php, so the admin fallback must not
 * treat a front request as the site dashboard even if is_blog_admin() is true.
 */

$test_is_admin = false;
$test_is_blog_admin = true;
$pagenow = 'index.php';

function is_admin(): bool
{
    global $test_is_admin;

    return $test_is_admin;
}

function is_blog_admin(): bool
{
    global $test_is_blog_admin;

    return $test_is_blog_admin;
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

assert_true(
    function_exists('get_current_screen') === false,
    'This fixture must not define get_current_screen()'
);

assert_true(
    DashboardScreen::is_site_dashboard() === false,
    'Frontend init (preview/home) must not fatal or match the site dashboard'
);

$test_is_admin = true;
$test_is_blog_admin = true;
$pagenow = 'index.php';

assert_true(
    DashboardScreen::is_site_dashboard() === true,
    'Site admin before current_screen exists should still match pagenow=index.php'
);

echo "OK\n";
