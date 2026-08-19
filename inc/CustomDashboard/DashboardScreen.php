<?php

declare(strict_types=1);

namespace SFX\CustomDashboard;

/**
 * Identifies the per-site WordPress dashboard screen.
 *
 * Custom dashboard HTML is hooked to admin_head-index.php, which also
 * fires on the network and user dashboards. Asset enqueue keys off the
 * screen id "dashboard". This helper keeps those two paths aligned so
 * the replacement UI never renders without its stylesheet.
 *
 * handle_dashboard() also calls this on WordPress `init`, including
 * frontend previews where get_current_screen() is not loaded.
 *
 * @package SFX_Bricks_Child_Theme
 */
class DashboardScreen
{
    /**
     * Whether the current request is the site (blog) dashboard.
     *
     * Returns false on the frontend, and for network admin
     * (`dashboard-network`) and user admin (`dashboard-user`).
     *
     * @return bool
     */
    public static function is_site_dashboard(): bool
    {
        if (function_exists('is_admin') && !is_admin()) {
            return false;
        }

        if (function_exists('is_blog_admin') && !is_blog_admin()) {
            return false;
        }

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if (is_object($screen) && isset($screen->id)) {
                return $screen->id === 'dashboard';
            }
        }

        global $pagenow;

        return $pagenow === 'index.php';
    }
}
