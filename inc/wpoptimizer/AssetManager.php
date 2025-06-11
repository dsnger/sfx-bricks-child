<?php

declare(strict_types=1);

namespace SFX\WPOptimizer;

class AssetManager
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    public static function enqueue_admin_assets($hook): void
    {

        // More flexible hook check - will match any page containing wp-optimizer-options
        if (strpos($hook, 'sfx-wp-optimizer') === false) {
            return;
        }

        $theme_url = get_stylesheet_directory_uri();
        $theme_dir = get_stylesheet_directory();
        $assets_url = $theme_url . '/inc/WPOptimizer/assets/';
        $assets_dir = $theme_dir . '/inc/WPOptimizer/assets/';

        // Enqueue CSS
        if (file_exists($assets_dir . 'admin-styles.css')) {
            wp_enqueue_style(
                'WPOptimizer-admin-styles',
                $assets_url . 'admin-styles.css',
                [],
                filemtime($assets_dir . 'admin-styles.css')
            );
        } else {
            error_log('WPOptimizer: admin-styles.css not found at ' . $assets_dir);
        }

        // Enqueue JS
        if (file_exists($assets_dir . 'admin-script.js')) {
            wp_enqueue_script(
                'WPOptimizer-admin',
                $assets_url . 'admin-script.js',
                ['jquery'],
                filemtime($assets_dir . 'admin-script.js'),
                true
            );
        } else {
            error_log('WPOptimizer: admin-script.js not found at ' . $assets_dir);
        }
    }
} 