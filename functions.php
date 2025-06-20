<?php

defined('ABSPATH') || exit;

// Include Composer Autoloader
$composer_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
} else {
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


// Load environment handler
require_once get_stylesheet_directory() . '/inc/Environment.php';

// Load theme functionality
require_once get_stylesheet_directory() . '/inc/SFXBricksChildTheme.php';

$sfx_child_theme = new \SFX\SFXBricksChildTheme();
$sfx_child_theme->init();

// Initialize theme updater
require_once get_stylesheet_directory() . '/inc/GithubThemeUpdater.php';
$updater = new \SFX\GitHubThemeUpdater();

// Debug: Show development mode status
if (is_admin() && current_user_can('manage_options') && \SFX\Environment::is_dev_mode()) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-info"><p>';
        echo '<strong>SFX Theme Updater Status:</strong> ';
        echo 'Development Mode (updater disabled unless ?debug_updater=1)';
        echo '</p></div>';
    });
}

// Only initialize in production OR when specifically debugging the updater
if (!\SFX\Environment::is_dev_mode() || (defined('WP_DEBUG') && WP_DEBUG && isset($_GET['debug_updater']))) {
    $updater->initialize();
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



