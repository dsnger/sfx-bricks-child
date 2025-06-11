<?php

declare(strict_types=1);

namespace SFX\CustomScriptsManager;

class AssetManager
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    public static function enqueue_admin_assets(string $hook): void
    {
        // Check for custom scripts manager settings pages
        if (strpos($hook, 'sfx-custom-scripts-manager') === false && 
            strpos($hook, 'settings_page') === false) {
            return;
        }

        wp_enqueue_media();

        $theme_url = get_stylesheet_directory_uri();
        $theme_dir = get_stylesheet_directory();
        
        // Load custom scripts specific CSS
        $assets_url = $theme_url . '/inc/CustomScriptsManager/assets/';
        $assets_dir = $theme_dir . '/inc/CustomScriptsManager/assets/';

        if (file_exists($assets_dir . 'admin-style.css')) {
            wp_enqueue_style(
                'sfx-custom-scripts-settings',
                $assets_url . 'admin-style.css',
                [], // No dependencies - global backend.css is loaded by theme
                filemtime($assets_dir . 'admin-style.css')
            );
        }

        // Enqueue JS for file upload and dynamic functionality
        if (file_exists($assets_dir . 'admin-script.js')) {
            wp_enqueue_script(
                'sfx-custom-scripts-js',
                $assets_url . 'admin-script.js',
                ['jquery', 'media-upload', 'thickbox'],
                filemtime($assets_dir . 'admin-script.js'),
                true
            );

            wp_localize_script('sfx-custom-scripts-js', 'sfxCustomScripts', [
                'confirmDelete' => __('Are you sure you want to remove this script?', 'sfxtheme'),
                'mediaUploadTitle' => __('Select Script File', 'sfxtheme'),
                'mediaUploadButton' => __('Use this file', 'sfxtheme'),
            ]);
        }
    }
}