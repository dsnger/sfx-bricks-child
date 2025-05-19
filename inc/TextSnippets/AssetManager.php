<?php

declare(strict_types=1);

namespace SFX\TextSnippets;

class AssetManager
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    public static function enqueue_admin_assets($hook): void
    {

        // More flexible hook check - will match any page containing wp-optimizer-options
        if (strpos($hook, 'sfx-text-snippets') === false) {
            return;
        }

        wp_enqueue_media();

        $theme_url = get_stylesheet_directory_uri();
        $theme_dir = get_stylesheet_directory();
        $assets_url = $theme_url . '/inc/CompanyLogo/assets/';
        $assets_dir = $theme_dir . '/inc/CompanyLogo/assets/';

        // Enqueue CSS
        if (file_exists($assets_dir . 'admin-style.css')) {
            wp_enqueue_style(
                'companylogo-admin-style',
                $assets_url . 'admin-style.css',
                [],
                filemtime($assets_dir . 'admin-style.css')
            );
        } else {
            error_log('CompanyLogo: admin-style.css not found at ' . $assets_dir);
        }

        // Enqueue JS
        if (file_exists($assets_dir . 'admin-script.js')) {
            wp_enqueue_script(
                'companylogo-admin-script',
                $assets_url . 'admin-script.js',
                ['jquery'],
                filemtime($assets_dir . 'admin-script.js'),
                true
            );
        } else {
            error_log('CompanyLogo: admin-script.js not found at ' . $assets_dir);
        }
    }
} 