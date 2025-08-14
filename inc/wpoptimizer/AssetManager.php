<?php

declare(strict_types=1);

namespace SFX\WPOptimizer;

class AssetManager
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets'], 20);
    }

    /**
     * Check if assets should be loaded for current screen
     * 
     * @return bool
     */
    private static function should_load_assets(): bool
    {
        $screen = get_current_screen();
        return $screen && strpos($screen->id, 'sfx-wp-optimizer') !== false;
    }

    /**
     * Check if we're on a content order page
     * 
     * @return bool
     */
    private static function is_content_order_page(): bool
    {
        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }

        // Check if we're on a custom order page
        return strpos($screen->id, 'custom-order-') !== false;
    }

    /**
     * Enqueue admin assets with conditional loading
     */
    public static function enqueue_admin_assets(): void
    {
        // Load assets on WP optimizer pages
        if (self::should_load_assets()) {
            // Enqueue global backend styles
            wp_enqueue_style('sfx-backend-styles', get_stylesheet_directory_uri() . '/assets/css/backend/styles.css', [], filemtime(get_stylesheet_directory() . '/assets/css/backend/styles.css'));

            // Enqueue feature-specific styles and scripts
            $css_file = get_stylesheet_directory() . '/inc/WPOptimizer/assets/admin-styles.css';
            $js_file = get_stylesheet_directory() . '/inc/WPOptimizer/assets/admin-script.js';

            if (file_exists($css_file)) {
                wp_enqueue_style('sfx-wp-optimizer-admin', get_stylesheet_directory_uri() . '/inc/WPOptimizer/assets/admin-styles.css', [], filemtime($css_file));
            }

            if (file_exists($js_file)) {
                wp_enqueue_script('sfx-wp-optimizer-admin', get_stylesheet_directory_uri() . '/inc/WPOptimizer/assets/admin-script.js', ['jquery'], filemtime($js_file), true);
            }
        }

        // Load content order assets on content order pages
        if (self::is_content_order_page()) {
            // Ensure jQuery UI core is loaded first
            wp_enqueue_script('jquery-ui-core');

            // Enqueue jQuery UI sortable (required for nestedSortable)
            wp_enqueue_script('jquery-ui-sortable');

            // Enqueue jQuery UI mouse (required for sortable)
            wp_enqueue_script('jquery-ui-mouse');

            // Enqueue jQuery UI widget (required for sortable)
            wp_enqueue_script('jquery-ui-widget');

            // Enqueue nestedSortable library
            $nested_sortable_file = get_stylesheet_directory() . '/inc/WPOptimizer/assets/jquery.mjs.nestedSortable.js';
            if (file_exists($nested_sortable_file)) {
                wp_enqueue_script('sfx-nested-sortable', get_stylesheet_directory_uri() . '/inc/WPOptimizer/assets/jquery.mjs.nestedSortable.js', ['jquery', 'jquery-ui-core', 'jquery-ui-widget', 'jquery-ui-mouse', 'jquery-ui-sortable'], filemtime($nested_sortable_file), true);
            }

            // Enqueue content order specific assets
            $content_order_css = get_stylesheet_directory() . '/inc/WPOptimizer/assets/content-order.css';
            $content_order_js = get_stylesheet_directory() . '/inc/WPOptimizer/assets/content-order.js';

            if (file_exists($content_order_css)) {
                wp_enqueue_style('sfx-content-order', get_stylesheet_directory_uri() . '/inc/WPOptimizer/assets/content-order.css', [], filemtime($content_order_css));
            }

            if (file_exists($content_order_js)) {
                wp_enqueue_script('sfx-content-order', get_stylesheet_directory_uri() . '/inc/WPOptimizer/assets/content-order.js', ['jquery', 'jquery-ui-core', 'jquery-ui-widget', 'jquery-ui-mouse', 'jquery-ui-sortable', 'sfx-nested-sortable'], filemtime($content_order_js), true);

                // Localize script with necessary data
                wp_localize_script('sfx-content-order', 'sfxContentOrder', [
                    'nonce' => wp_create_nonce('order_sorting_nonce'),
                    'ajaxurl' => admin_url('admin-ajax.php')
                ]);
            }
        }
    }
}
