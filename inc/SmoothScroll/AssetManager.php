<?php

declare(strict_types=1);

namespace SFX\SmoothScroll;

class AssetManager
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets'], 20);
    }

    private static function should_load_assets(): bool
    {
        $screen = get_current_screen();
        return $screen && strpos($screen->id, 'sfx-smooth-scroll') !== false;
    }

    public static function enqueue_admin_assets(): void
    {
        if (!self::should_load_assets()) {
            return;
        }

        $backend_css = get_stylesheet_directory() . '/assets/css/backend/styles.css';
        if (file_exists($backend_css)) {
            wp_enqueue_style(
                'sfx-backend-styles',
                get_stylesheet_directory_uri() . '/assets/css/backend/styles.css',
                [],
                filemtime($backend_css)
            );
        }

        $css = get_stylesheet_directory() . '/inc/SmoothScroll/assets/admin-style.css';
        if (file_exists($css)) {
            wp_enqueue_style(
                'sfx-smooth-scroll-admin',
                get_stylesheet_directory_uri() . '/inc/SmoothScroll/assets/admin-style.css',
                [],
                filemtime($css)
            );
        }
    }
}
