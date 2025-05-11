<?php
declare(strict_types=1);

namespace SFX\PixRefiner;

class AssetManager
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    public static function enqueue_admin_assets($hook): void
    {
        // Only load on PixRefiner admin page
        if ($hook !== 'sfx-theme-settings_page_webp-converter' && $hook !== 'toplevel_page_webp-converter') {
            return;
        }
        
        // Ensure wp-api is loaded (needed for media library interaction)
        wp_enqueue_media();
        
        $assets_url = get_stylesheet_directory_uri() . '/inc/PixRefiner/assets/';
        $assets_dir = get_stylesheet_directory() . '/inc/PixRefiner/assets/';
        
        // Enqueue JS
        if (file_exists($assets_dir . 'admin-script.js')) {
            wp_enqueue_script(
                'pixrefiner-admin',
                $assets_url . 'admin-script.js',
                ['jquery'],
                filemtime($assets_dir . 'admin-script.js'),
                true
            );
            
            // Localize script for AJAX and nonce
            wp_localize_script('pixrefiner-admin', 'PixRefinerAjax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('webp_converter_nonce'),
                'excluded_images' => \SFX\PixRefiner\Settings::get_excluded_images(),
                'debug_info' => [
                    'hook' => $hook,
                    'time' => time()
                ]
            ]);
        } else {
            // Log error if script is missing
            error_log('PixRefiner: admin-script.js not found at ' . $assets_dir);
        }
        
        // Enqueue CSS
        if (file_exists($assets_dir . 'admin-styles.css')) {
            wp_enqueue_style(
                'pixrefiner-admin-style',
                $assets_url . 'admin-styles.css',
                [],
                filemtime($assets_dir . 'admin-styles.css')
            );
        }
    }
} 