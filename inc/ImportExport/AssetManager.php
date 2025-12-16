<?php

declare(strict_types=1);

namespace SFX\ImportExport;

/**
 * Asset Manager for ImportExport feature.
 * 
 * Handles enqueueing of admin scripts and styles.
 * 
 * @package SFX\ImportExport
 */
class AssetManager
{
    /**
     * Register asset hooks.
     */
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    /**
     * Enqueue admin scripts and styles.
     * 
     * @param string $hook_suffix Current admin page hook suffix.
     */
    public static function enqueue_admin_assets(string $hook_suffix): void
    {
        // Only load on our admin page
        if (strpos($hook_suffix, AdminPage::$menu_slug) === false) {
            return;
        }

        $assets_url = get_stylesheet_directory_uri() . '/inc/ImportExport/assets/';
        $assets_path = get_stylesheet_directory() . '/inc/ImportExport/assets/';

        // Enqueue styles - depends on global backend styles
        wp_enqueue_style(
            'sfx-import-export-admin',
            $assets_url . 'admin-style.css',
            ['sfx-bricks-child-admin-styles'], // Dependency on global backend styles
            file_exists($assets_path . 'admin-style.css') 
                ? filemtime($assets_path . 'admin-style.css') 
                : '1.0.0'
        );

        // Enqueue scripts
        wp_enqueue_script(
            'sfx-import-export-admin',
            $assets_url . 'admin-script.js',
            ['jquery'],
            file_exists($assets_path . 'admin-script.js') 
                ? filemtime($assets_path . 'admin-script.js') 
                : '1.0.0',
            true
        );

        // Localize script with AJAX data and shared constants
        wp_localize_script('sfx-import-export-admin', 'sfxImportExport', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'exportNonce' => wp_create_nonce('sfx_export_settings_nonce'),
            'importNonce' => wp_create_nonce('sfx_import_settings_nonce'),
            'dashboardFieldGroups' => Controller::get_dashboard_field_groups(),
            'settingsLabels' => Controller::get_settings_labels(),
            'postTypeLabels' => Controller::get_exportable_post_types(),
            'strings' => [
                'confirmReplace' => __('This will REPLACE all existing data for the selected items. This action cannot be undone. Are you sure?', 'sfxtheme'),
                'confirmMerge' => __('This will MERGE imported data with existing data. Existing items will be kept. Continue?', 'sfxtheme'),
                'exportSuccess' => __('Export completed successfully!', 'sfxtheme'),
                'exportError' => __('Export failed. Please try again.', 'sfxtheme'),
                'importSuccess' => __('Import completed successfully!', 'sfxtheme'),
                'importError' => __('Import failed. Please check the file and try again.', 'sfxtheme'),
                'noSelection' => __('Please select at least one item to export/import.', 'sfxtheme'),
                'invalidFile' => __('Please select a valid JSON file.', 'sfxtheme'),
                'fileTooLarge' => __('File is too large. Maximum size is 2MB.', 'sfxtheme'),
                'processing' => __('Processing...', 'sfxtheme'),
            ],
        ]);
    }
}

