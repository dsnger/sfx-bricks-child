<?php

declare(strict_types=1);

namespace SFX\ContactInfos;

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
        return $screen && $screen->post_type === 'sfx_contact_info';
    }

    /**
     * Enqueue admin assets with conditional loading
     */
    public static function enqueue_admin_assets(): void
    {
        // Only load assets on contact info pages
        if (!self::should_load_assets()) {
            return;
        }

        // Enqueue global backend styles
        wp_enqueue_style('sfx-backend-styles', get_stylesheet_directory_uri() . '/assets/css/backend/styles.css', [], filemtime(get_stylesheet_directory() . '/assets/css/backend/styles.css'));

        // Enqueue feature-specific styles and scripts
        $css_file = get_stylesheet_directory() . '/inc/ContactInfos/assets/admin-style.css';
        $js_file = get_stylesheet_directory() . '/inc/ContactInfos/assets/admin-script.js';

        if (file_exists($css_file)) {
            wp_enqueue_style('sfx-contact-infos-admin', get_stylesheet_directory_uri() . '/inc/ContactInfos/assets/admin-style.css', [], filemtime($css_file));
        }

        if (file_exists($js_file)) {
            wp_enqueue_script('sfx-contact-infos-admin', get_stylesheet_directory_uri() . '/inc/ContactInfos/assets/admin-script.js', ['jquery'], filemtime($js_file), true);
        }
    }
} 