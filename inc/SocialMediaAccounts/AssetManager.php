<?php

declare(strict_types=1);

namespace SFX\SocialMediaAccounts;

class AssetManager
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets'], 20);
    }

    public static function enqueue_admin_assets(string $hook): void
    {
        // Check if we're on the correct post type pages
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'sfx_social_account') {
            return;
        }

        // Enqueue media for file upload
        wp_enqueue_media();

        // Enqueue local Select2
        $theme_url = get_stylesheet_directory_uri();
        $theme_dir = get_stylesheet_directory();

        // Select2 CSS
        $select2_css_path = $theme_dir . '/assets/css/backend/select2.min.css';
        if (file_exists($select2_css_path)) {
            wp_enqueue_style(
                'sfx-select2',
                $theme_url . '/assets/css/backend/select2.min.css',
                [],
                filemtime($select2_css_path)
            );
        }

        // Select2 JS
        $select2_js_path = $theme_dir . '/assets/js/backend/select2.min.js';
        if (file_exists($select2_js_path)) {
            wp_enqueue_script(
                'select2',
                $theme_url . '/assets/js/backend/select2.min.js',
                ['jquery'],
                filemtime($select2_js_path),
                true
            );
        }

        // Load global backend styles
        $global_css_path = $theme_dir . '/assets/css/backend/styles.css';
        if (file_exists($global_css_path)) {
            wp_enqueue_style(
                'sfx-backend-styles',
                $theme_url . '/assets/css/backend/styles.css',
                [],
                filemtime($global_css_path)
            );
        }

        // Load custom social media accounts specific CSS
        $assets_url = $theme_url . '/inc/SocialMediaAccounts/assets/';
        $assets_dir = $theme_dir . '/inc/SocialMediaAccounts/assets/';

        if (file_exists($assets_dir . 'admin-style.css')) {
            wp_enqueue_style(
                'sfx-social-media-accounts-settings',
                $assets_url . 'admin-style.css',
                ['sfx-select2', 'sfx-backend-styles'], // Depend on select2 and global styles
                filemtime($assets_dir . 'admin-style.css')
            );
        }

        // Enqueue JS for file upload and dynamic functionality
        if (file_exists($assets_dir . 'admin-script.js')) {
            wp_enqueue_script(
                'sfx-social-media-accounts-js',
                $assets_url . 'admin-script.js',
                ['jquery', 'media-upload', 'thickbox', 'select2'],
                filemtime($assets_dir . 'admin-script.js'),
                true
            );

            wp_localize_script('sfx-social-media-accounts-js', 'sfxSocialMediaAccounts', [
                'confirmDelete' => __('Are you sure you want to remove this social account?', 'sfxtheme'),
                'mediaUploadTitle' => __('Select Icon Image', 'sfxtheme'),
                'mediaUploadButton' => __('Use this image', 'sfxtheme'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sfx_social_media_accounts_nonce'),
            ]);
        }
    }
} 