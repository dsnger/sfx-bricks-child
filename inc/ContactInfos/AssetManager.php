<?php

declare(strict_types=1);

namespace SFX\ContactInfos;

class AssetManager
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    public static function enqueue_admin_assets(string $hook): void
    {
        // Check for contact infos settings pages
        if (strpos($hook, 'sfx-contact-infos') === false && 
            strpos($hook, 'settings_page') === false && 
            strpos($hook, 'options') === false) {
            return;
        }

        wp_enqueue_media();

        $theme_url = get_stylesheet_directory_uri();
        $theme_dir = get_stylesheet_directory();
        $assets_url = $theme_url . '/inc/ContactInfos/assets/';
        $assets_dir = $theme_dir . '/inc/ContactInfos/assets/';

        // Enqueue ContactInfos specific CSS (legacy branch styles)
        // Note: Shared .sfx-settings-* styles are now in global styles.css
        if (file_exists($assets_dir . 'admin-style.css')) {
            wp_enqueue_style(
                'sfx-contact-settings',
                $assets_url . 'admin-style.css',
                [], // No dependencies - global styles.css is loaded by theme
                filemtime($assets_dir . 'admin-style.css')
            );
        } else {
            error_log('SFX ContactInfos: admin-style.css not found at ' . $assets_dir);
        }

        // Enqueue JS
        if (file_exists($assets_dir . 'admin-script.js')) {
            wp_enqueue_script(
                'sfx-contact-settings-js',
                $assets_url . 'admin-script.js',
                ['jquery'],
                filemtime($assets_dir . 'admin-script.js'),
                true
            );

            // Add localized data
            wp_localize_script('sfx-contact-settings-js', 'sfxContactSettings', [
                'confirmDelete' => __('Are you sure you want to delete this branch?', 'sfxtheme'),
            ]);
        } else {
            error_log('SFX ContactInfos: admin-script.js not found at ' . $assets_dir);
        }
    }
} 