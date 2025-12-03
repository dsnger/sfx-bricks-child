<?php

declare(strict_types=1);

namespace SFX;

/**
 * Centralized Access Control for Theme Settings
 * 
 * Two-tier permission system:
 * - SFX_THEME_ADMINS: Controls general theme settings (role or capability)
 * - SFX_THEME_DASHBOARD: Controls Custom Dashboard settings (usernames)
 * 
 * Configuration (add to wp-config.php):
 * 
 * // Theme Settings - accepts role OR capability (auto-detected)
 * define('SFX_THEME_ADMINS', 'administrator');  // or 'manage_options'
 * 
 * // Custom Dashboard Settings - specific usernames (comma-separated)
 * define('SFX_THEME_DASHBOARD', 'agency_user,agency_dev');
 * 
 * NOTE: If constants are NOT defined, access is LOCKED for everyone.
 * 
 * @package SFX_Bricks_Child_Theme
 */
class AccessControl
{
    /**
     * Check if current user can access general theme settings
     * 
     * Requires SFX_THEME_ADMINS constant to be defined with a role or capability.
     * If not defined, access is denied to everyone.
     * 
     * @return bool True if user has access to theme settings
     */
    public static function can_access_theme_settings(): bool
    {
        // Must be logged in
        if (!is_user_logged_in()) {
            return false;
        }

        // If constant not defined, lock access for everyone
        if (!defined('SFX_THEME_ADMINS') || empty(SFX_THEME_ADMINS)) {
            return false;
        }

        $value = trim(SFX_THEME_ADMINS);
        $current_user = wp_get_current_user();

        if (!$current_user || !$current_user->exists()) {
            return false;
        }

        // Auto-detect: check if it's a capability first, then role
        // Capabilities are checked via current_user_can()
        if (current_user_can($value)) {
            return true;
        }

        // Check if it's a role name
        $user_roles = (array) $current_user->roles;
        if (in_array($value, $user_roles, true)) {
            return true;
        }

        return false;
    }

    /**
     * Check if current user can access Custom Dashboard settings
     * 
     * Requires SFX_THEME_DASHBOARD constant to be defined with username(s).
     * If not defined, access is denied to everyone.
     * 
     * @return bool True if user has access to dashboard settings
     */
    public static function can_access_dashboard_settings(): bool
    {
        // Must be logged in
        if (!is_user_logged_in()) {
            return false;
        }

        // If constant not defined, lock access for everyone
        if (!defined('SFX_THEME_DASHBOARD') || empty(SFX_THEME_DASHBOARD)) {
            return false;
        }

        $current_user = wp_get_current_user();

        if (!$current_user || !$current_user->exists()) {
            return false;
        }

        // Parse comma-separated usernames
        $allowed_usernames = array_map('trim', explode(',', SFX_THEME_DASHBOARD));
        
        // Check if current user's login is in the allowed list
        if (in_array($current_user->user_login, $allowed_usernames, true)) {
            return true;
        }

        return false;
    }

    /**
     * Die with access denied message for unauthorized theme settings access
     * 
     * @return void
     */
    public static function die_if_unauthorized_theme(): void
    {
        if (!self::can_access_theme_settings()) {
            wp_die(
                esc_html__('You do not have sufficient permissions to access this page. Theme settings access is restricted.', 'sfxtheme'),
                esc_html__('Access Denied', 'sfxtheme'),
                ['response' => 403, 'back_link' => true]
            );
        }
    }

    /**
     * Die with access denied message for unauthorized dashboard settings access
     * 
     * @return void
     */
    public static function die_if_unauthorized_dashboard(): void
    {
        if (!self::can_access_dashboard_settings()) {
            wp_die(
                esc_html__('You do not have sufficient permissions to access this page. Dashboard settings access is restricted.', 'sfxtheme'),
                esc_html__('Access Denied', 'sfxtheme'),
                ['response' => 403, 'back_link' => true]
            );
        }
    }

    /**
     * Legacy method for backward compatibility
     * Now checks theme settings access
     * 
     * @return bool
     * @deprecated Use can_access_theme_settings() instead
     */
    public static function can_manage_theme_settings(): bool
    {
        return self::can_access_theme_settings();
    }

    /**
     * Legacy method for backward compatibility
     * 
     * @return void
     * @deprecated Use die_if_unauthorized_theme() instead
     */
    public static function die_if_unauthorized(): void
    {
        self::die_if_unauthorized_theme();
    }
}
