<?php

// Check if Composer's autoloader exists
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Fallback error message if Composer dependencies are not installed
    add_action('admin_notices', function() {
        echo '<div class="error"><p>Composer dependencies are missing. Please run "composer install" in the theme directory.</p></div>';
    });
    return;
}

// Initialize the theme
$sfxTheme = new SFX\SFXBricksChildTheme();
$sfxTheme->init();