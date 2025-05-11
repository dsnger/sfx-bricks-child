<?php
/**
 * GitHub Theme Updater Test Script
 * 
 * This script tests the GitHub API connectivity and verifies the version comparison.
 * It will print detailed information about the theme and GitHub repository.
 * 
 * Usage: Place this file in your theme directory and access it directly in the browser.
 * Make sure to remove it after debugging is complete for security reasons.
 */

// Only allow access from localhost for security
if (!in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])) {
    die('Access denied. This script can only be accessed from localhost.');
}

// Bootstrap WordPress without loading the theme
define('WP_USE_THEMES', false);
require_once '../../../../wp-load.php';

// Disable output buffering
if (ob_get_level()) ob_end_clean();

// Headers for pretty text output
header('Content-Type: text/plain; charset=utf-8');

echo "GitHub Theme Updater Test Script\n";
echo "================================\n\n";

// Get theme information
$theme = wp_get_theme(get_stylesheet());
$theme_slug = get_stylesheet();
$theme_version = $theme->get('Version');
$theme_name = $theme->get('Name');

echo "Theme Information:\n";
echo "- Name: $theme_name\n";
echo "- Slug: $theme_slug\n";
echo "- Current Version: $theme_version\n\n";

// GitHub repository information (copied from the updater class)
$github_url = 'https://github.com/dsnger/sfx-bricks-child';
$path = parse_url($github_url, PHP_URL_PATH);
[$github_username, $github_repo] = array_slice(explode('/', trim($path, '/')), 0, 2);

echo "GitHub Repository:\n";
echo "- URL: $github_url\n";
echo "- Username: $github_username\n";
echo "- Repository: $github_repo\n\n";

// Test GitHub API connection
echo "Testing GitHub API Connection...\n";
$request_uri = sprintf('https://api.github.com/repos/%s/%s/releases/latest', $github_username, $github_repo);
echo "- Request URL: $request_uri\n";

$args = [
    'headers' => [
        'User-Agent' => 'WordPress/' . get_bloginfo('version')
    ],
    'timeout' => 10,
];

$response = wp_remote_get($request_uri, $args);

if (is_wp_error($response)) {
    echo "Error: " . $response->get_error_message() . "\n";
    die();
}

$response_code = wp_remote_retrieve_response_code($response);
echo "- Response Code: $response_code\n";

if ($response_code !== 200) {
    echo "Error: Unexpected response code\n";
    echo "Response Body: " . wp_remote_retrieve_body($response) . "\n";
    die();
}

$github_response = json_decode(wp_remote_retrieve_body($response));

echo "\nGitHub Release Information:\n";
echo "- Tag Name: " . $github_response->tag_name . "\n";
echo "- Latest Version: " . ltrim($github_response->tag_name, 'v') . "\n";
echo "- Published: " . $github_response->published_at . "\n";
echo "- Zipball URL: " . $github_response->zipball_url . "\n";

// Test version comparison
$latest_version = ltrim($github_response->tag_name, 'v');
$do_update = version_compare($latest_version, $theme_version, 'gt');

echo "\nVersion Comparison:\n";
echo "- Current Theme Version: $theme_version\n";
echo "- Latest GitHub Version: $latest_version\n";
echo "- Update Available: " . ($do_update ? "YES" : "NO") . "\n";
echo "- version_compare() Result: " . ($do_update ? "Greater Than" : "Equal or Less Than") . "\n";

echo "\nTesting WordPress Transient:\n";
$transient = get_site_transient('update_themes');
echo "- Transient exists: " . (($transient !== false) ? "YES" : "NO") . "\n";

if ($transient) {
    echo "- Theme in checked list: " . (isset($transient->checked[$theme_slug]) ? "YES" : "NO") . "\n";
    if (isset($transient->checked[$theme_slug])) {
        echo "- Checked version: " . $transient->checked[$theme_slug] . "\n";
    }
    
    echo "- Theme in response list: " . (isset($transient->response[$theme_slug]) ? "YES" : "NO") . "\n";
    if (isset($transient->response[$theme_slug])) {
        echo "- Response data: " . print_r($transient->response[$theme_slug], true) . "\n";
    }
}

// Provide next steps
echo "\nNext Steps:\n";
echo "1. If 'Update Available' is YES but no updates are showing in WordPress:\n";
echo "   - Try clearing the transient by accessing: " . admin_url('themes.php?force-check=sfx-theme-update') . "\n";
echo "2. Check if GitHub release tags follow semantic versioning (e.g., v1.2.3 or 1.2.3)\n";
echo "3. Ensure your style.css Version is correctly formatted\n";
echo "4. Consider adding a GitHub personal access token if you're hitting rate limits\n";
echo "\nNote: Remember to delete this test file after debugging for security reasons.\n"; 