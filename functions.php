<?php

/**
 * SFX Bricks Child Theme functions and definitions
 * 
 * @version 0.2.7
 * @package SFX\BricksChild
 */

defined('ABSPATH') || exit;


define('SFX_THEME_VERSION', '0.2.7');

// Include Composer Autoloader
$composer_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
} else {
    // Fallback if composer autoload file doesn't exist
    include_once __DIR__ . '/inc/SFXBricksChildTheme.php';

    // Simple namespace-based autoloader as fallback
    spl_autoload_register(function ($class) {
        // Only handle our own namespaces
        if (strpos($class, 'SFX\\') !== 0) {
            return;
        }

        // Convert namespace separators to directory separators
        $file = str_replace('\\', DIRECTORY_SEPARATOR, $class);
        // Remove namespace prefix and add theme directory
        $file = get_stylesheet_directory() . '/inc/' . substr($file, 4) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    });

    // Display admin notice if autoloader is missing
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo 'Error: Composer autoloader not found. Please run <code>composer install</code> in the theme root directory.';
        echo '</p></div>';
    });
}

// Initialize GitHub Updater
require_once __DIR__ . '/inc/GithubThemeUpdater.php';
if (is_admin()) {
    $updater = new \SFX\BricksChild\GitHubThemeUpdater();
    $updater->initialize();
}

if (!defined('ABSPATH')) {
    exit;
}

// Initialize the theme
if (class_exists('\\SFX\\SFXBricksChildTheme')) {
    $sfx_theme = new \SFX\SFXBricksChildTheme();
    $sfx_theme->init();
}


// Ensure ACF is active before initializing the theme
add_action('after_setup_theme', function () {
    if (!class_exists('ACF')) {
        // Display admin notice if ACF is not activated
        add_action('admin_notices', function () {
            echo '<div class="error"><p>The Advanced Custom Fields (ACF) plugin is required for this theme to function. Please activate ACF.</p></div>';
        });
        return;
    }
});



