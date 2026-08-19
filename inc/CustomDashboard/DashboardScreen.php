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
 * @package SFX_Bricks_Child_Theme
 */
class DashboardScreen
{
    /**
     * Whether the current request is the site (blog) dashboard.
     *
     * Returns false for network admin (`dashboard-network`) and user
     * admin (`dashboard-user`), even though both also use index.php.
     *
     * @return bool
     */
    public static function is_site_dashboard(): bool
    {
        if (!is_blog_admin()) {
            return false;
        }

        $screen = get_current_screen();
        if (is_object($screen) && isset($screen->id)) {
            return $screen->id === 'dashboard';
        }

        global $pagenow;

        return $pagenow === 'index.php';
    }
}
