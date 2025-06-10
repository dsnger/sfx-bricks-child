<?php
declare(strict_types=1);

namespace SFX\ImageOptimizer;

class AssetManager
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    public static function enqueue_admin_assets($hook): void
    {
        // Only load on ImageOptimizer admin page
        if ($hook !== 'toplevel_page_sfx-image-optimizer' && $hook !== 'global-theme-settings_page_sfx-image-optimizer') {
            return;
        }

        // Optionally enqueue media if needed for UI
        // wp_enqueue_media();

        $assets_url = get_stylesheet_directory_uri() . '/inc/ImageOptimizer/assets/';
        $assets_dir = get_stylesheet_directory() . '/inc/ImageOptimizer/assets/';

        // Enqueue JS
        if (file_exists($assets_dir . 'admin-script.js')) {
            wp_enqueue_script(
                'ImageOptimizer-admin',
                $assets_url . 'admin-script.js',
                ['jquery'],
                filemtime($assets_dir . 'admin-script.js'),
                true
            );
            
            // Localize script for AJAX and nonce
            wp_localize_script('ImageOptimizer-admin', 'ImageOptimizerAjax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('webp_converter_nonce'),
                'excluded_images' => \SFX\ImageOptimizer\Settings::get_excluded_images(),
                'debug_info' => [
                    'hook' => $hook,
                    'time' => time()
                ]
            ]);
        } else {
            error_log('ImageOptimizer: admin-script.js not found at ' . $assets_dir);
        }
        
        // Enqueue CSS
        if (file_exists($assets_dir . 'admin-style.css')) {
            wp_enqueue_style(
                'ImageOptimizer-admin-style',
                $assets_url . 'admin-style.css',
                [],
                filemtime($assets_dir . 'admin-style.css')
            );
        } else {
            error_log('ImageOptimizer: admin-style.css not found at ' . $assets_dir);
        }
    }
} 