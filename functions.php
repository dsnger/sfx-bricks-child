<?php

/**
 * SFX Bricks Child Theme Functions
 *
 * @package SFX_Bricks_Child_Theme
 * @version 1.0.1
 */

defined('ABSPATH') || exit;

// Include Composer Autoloader (RECOMMENDED APPROACH)
$composer_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
} else {
    // Clean PSR-4 fallback autoloader (WordPress best practice)
    spl_autoload_register(function ($class) {
        // Only handle our own namespaces
        if (strpos($class, 'SFX\\') !== 0) {
            return;
        }

        // PSR-4 compliant autoloader
        $prefix = 'SFX\\';
        $base_dir = get_stylesheet_directory() . '/inc/';

        // Remove the namespace prefix
        $relative_class = substr($class, strlen($prefix));

        // Replace namespace separators with directory separators
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        // Load the file if it exists
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
$environment_file = get_stylesheet_directory() . '/inc/Environment.php';
if (file_exists($environment_file)) {
    require_once $environment_file;
}

// Load theme functionality
require_once get_stylesheet_directory() . '/inc/SFXBricksChildTheme.php';

// Initialize theme after WordPress is ready
add_action('after_setup_theme', function () {
    $sfx_child_theme = new \SFX\SFXBricksChildTheme();
    $sfx_child_theme->init();
}, 1); // Priority 1 to run early but after WordPress core setup

// Initialize theme updater
require_once get_stylesheet_directory() . '/inc/GithubThemeUpdater.php';
$updater = new \SFX\GitHubThemeUpdater();

// Debug: Show development mode status
if (is_admin() && current_user_can('manage_options') && class_exists('SFX\\Environment') && \SFX\Environment::is_dev_mode()) {
    add_action('admin_notices', function () {
        $current_screen = get_current_screen();
        if (!$current_screen) {
            return;
        }

        // Dynamic detection of theme-related pages
        $is_theme_page = sfx_is_theme_related_page($current_screen);

        if ($is_theme_page) {
            echo '<div class="notice notice-info"><p>';
            echo '<strong>SFX Theme Status:</strong> ';
            echo 'Development Mode - No auto-updates';
            echo '</p></div>';
        }
    });
}

/**
 * Check if current screen is theme-related
 * 
 * @param \WP_Screen $screen
 * @return bool
 */
function sfx_is_theme_related_page($screen): bool
{
    // Theme settings pages (dynamic detection using prefix)
    if (strpos($screen->id, 'sfx-') === 0 || strpos($screen->id, 'theme-settings_page_sfx-') === 0) {
        return true;
    }

    // Auto-discover theme post types (no hardcoding needed)
    $theme_post_types = sfx_get_theme_post_types();
    if (in_array($screen->post_type, $theme_post_types)) {
        return true;
    }

    // Theme updater debug page
    if ($screen->id === 'theme-settings_page_theme-updater-debug') {
        return true;
    }

    return false;
}

/**
 * Get all theme post types dynamically
 * 
 * @return array
 */
function sfx_get_theme_post_types(): array
{
    // Auto-discover post types by scanning the inc directory
    $post_types = [];
    $inc_dir = get_stylesheet_directory() . '/inc/';

    if (is_dir($inc_dir)) {
        $directories = glob($inc_dir . '*', GLOB_ONLYDIR);

        foreach ($directories as $dir) {
            $post_type_file = $dir . '/PostType.php';
            if (file_exists($post_type_file)) {
                // Extract post type name from directory
                $dir_name = basename($dir);
                $post_type = 'sfx_' . str_replace('-', '_', $dir_name);
                $post_types[] = $post_type;
            }
        }
    }

    return $post_types;
}

// Only initialize in production OR when specifically debugging the updater
if ((!class_exists('SFX\\Environment') || !\SFX\Environment::is_dev_mode()) || (defined('WP_DEBUG') && WP_DEBUG && isset($_GET['debug_updater']))) {
    $updater->initialize();
}


add_filter('sfx_custom_dashboard_stats', function($stats) {
    
    $stats['pending'] = [
        'label'      => 'Pending Posts',
        'query_type' => 'wp_count_posts',
        'post_type'  => 'post',
        'status'     => 'pending',
    ];
    return $stats;
});