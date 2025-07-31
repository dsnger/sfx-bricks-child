<?php

declare(strict_types=1);

namespace SFX\SecurityHeader;

class AssetManager
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    public static function enqueue_admin_assets($hook): void
    {
        // Only load on SecurityHeader admin page
        if ($hook !== 'toplevel_page_security-header' && $hook !== 'global-theme-settings_page_security-header') {
            return;
        }

        // Optionally enqueue media if needed for UI
        // wp_enqueue_media();

        $theme_url = get_stylesheet_directory_uri();
        $theme_dir = get_stylesheet_directory();
        $assets_url = $theme_url . '/inc/SecurityHeader/assets/';
        $assets_dir = $theme_dir . '/inc/SecurityHeader/assets/';

        // Then enqueue module-specific styles
        if (file_exists($assets_dir . 'admin-style.css')) {
            wp_enqueue_style(
                'SecurityHeader-admin-style',
                $assets_url . 'admin-style.css',
                ['sfx-global-admin-style'],  // Depend on global styles
                filemtime($assets_dir . 'admin-style.css')
            );
        } else {
            error_log('SecurityHeader: admin-style.css not found at ' . $assets_dir);
        }

        // Enqueue JS
        if (file_exists($assets_dir . 'admin-script.js')) {
            wp_enqueue_script(
                'SecurityHeader-admin',
                $assets_url . 'admin-script.js',
                ['jquery'],
                filemtime($assets_dir . 'admin-script.js'),
                true
            );
        } else {
            error_log('SecurityHeader: admin-script.js not found at ' . $assets_dir);
        }
    }
} 