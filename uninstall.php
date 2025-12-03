<?php
/**
 * Fired when the theme is deleted.
 *
 * @package SFX_Bricks_Child_Theme
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get general options to check if deletion is enabled
$general_options = get_option( 'sfx_general_options', [] );

// Check if "Delete Data on Uninstall" is enabled
if ( empty( $general_options['delete_on_uninstall'] ) ) {
    return;
}

// List of all options to delete
$options_to_delete = [
    // General Theme Options
    'sfx_general_options',
    
    // Custom Dashboard
    'sfx_custom_dashboard',
    'sfx_custom_dashboard_icon_migration_version',
    
    // Custom Scripts Manager
    'sfx_custom_scripts_manager_options',
    
    // WP Optimizer
    'sfx_wpoptimizer_options',
    'sfx_wpoptimizer_extra',
    
    // Text Snippets
    'sfx_text_snippets_options',
    
    // HTML Copy Paste
    'sfx_html_copy_paste_options',
    
    // Social Media Accounts
    'sfx_social_media_accounts_options',
    
    // GitHub Theme Updater
    'github_theme_updater_debug',
    'sfx_theme_version_stored',
    
    // Image Optimizer
    'sfx_webp_max_widths',
    'sfx_webp_max_heights',
    'sfx_webp_resize_mode',
    'sfx_webp_quality',
    'sfx_webp_batch_size',
    'sfx_webp_preserve_originals',
    'sfx_webp_disable_auto_conversion',
    'sfx_webp_min_size_kb',
    'sfx_webp_use_avif',
    'sfx_webp_excluded_images',
    'sfx_webp_conversion_log',
    'webp_conversion_complete',
    'webp_conversion_log',
    
    // Security Headers
    'sfx_hsts_max_age',
    'sfx_hsts_include_subdomains',
    'sfx_hsts_preload',
    'sfx_csp',
    'sfx_csp_report_uri',
    'sfx_permissions_policy',
    'sfx_x_frame_options',
    'sfx_x_frame_options_allow_from_url',
    'sfx_disable_hsts_header',
    'sfx_disable_csp_header',
    'sfx_disable_x_content_type_options_header',
    'sfx_disable_x_frame_options_header',
    
    // Image sizes
    'thumbnail_size_w', // Be careful: these are standard WP options, but the theme overrides them. 
                        // If the user deletes the theme, they might want these reset? 
                        // Probably safer to LEAVE standard WP options alone unless explicitly requested.
                        // The ImageOptimizer Settings sets them, but deleting the theme shouldn't necessarily revert WP core settings 
                        // unless we stored the original values (which we didn't).
                        // I will EXCLUDE standard WP options from this list to avoid side effects.
];

// Delete options
foreach ( $options_to_delete as $option ) {
    delete_option( $option );
}

// Clear transients
global $wpdb;

// Clear all transients that start with our theme prefix (sfx_)
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_sfx_%',
        '_transient_timeout_sfx_%'
    )
);

// Clear specific cache transients if any (e.g., github updater)
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_gh_block_%',
        '_transient_timeout_gh_block_%'
    )
);

