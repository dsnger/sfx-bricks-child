<?php

/**
 * SFX Bricks Child Theme functions and definitions
 * 
 * @version 1.0.1
 * @package SFX\BricksChild
 */

defined('ABSPATH') || exit;

// Include Composer Autoloader
$composer_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
} else {
    // Fallback if composer autoload file doesn't exist
    include_once __DIR__ . '/inc/SFXBricksChildTheme.php';
    
    // Display admin notice if autoloader is missing
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo 'Error: Composer autoloader not found. Please run <code>composer install</code> in the theme root directory.';
        echo '</p></div>';
    });
}

// Initialize the theme
if (class_exists('\\SFX\\SFXBricksChildTheme')) {
    $sfx_theme = new \SFX\SFXBricksChildTheme();
    $sfx_theme->init();
}

// Ensure ACF is active before initializing the theme
add_action('after_setup_theme', function() {
    if (!class_exists('ACF')) {
        // Display admin notice if ACF is not activated
        add_action('admin_notices', function() {
            echo '<div class="error"><p>The Advanced Custom Fields (ACF) plugin is required for this theme to function. Please activate ACF.</p></div>';
        });
        return;
    }
});