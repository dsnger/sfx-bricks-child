<?php

declare(strict_types=1);

namespace SFX;

use Parsedown;

if (!defined('ABSPATH')) {
  exit;
}

class GitHubThemeUpdater
{
  private string $theme_slug;
  private string $theme_version;
  private string $theme_name;
  private string $github_url = 'https://github.com/dsnger/sfx-bricks-child'; // Replace with your GitHub repo URL
  private ?object $github_response = null;
  private ?string $github_username = null;
  private ?string $github_repo = null;
  private ?string $authorize_token = null;
  private bool $debug = false;

  public function __construct()
  {
    // Extract username and repo from GitHub URL
    $path = parse_url($this->github_url, PHP_URL_PATH);
    [$this->github_username, $this->github_repo] = array_slice(explode('/', trim($path, '/')), 0, 2);

    $theme = wp_get_theme(get_stylesheet());
    $this->theme_slug = get_stylesheet();

    // Enhanced version detection with fallbacks
    $this->theme_version = $this->get_reliable_theme_version($theme);
    $this->theme_name = $theme->get('Name');

    // Enhanced debugging for version detection
    $this->debug_log('=== THEME VERSION DEBUGGING ===');
    $this->debug_log('Theme slug: ' . $this->theme_slug);
    $this->debug_log('Final theme version: ' . $this->theme_version);
    $this->debug_log('=== END VERSION DEBUGGING ===');

    $this->debug_log('Initialized for ' . $this->theme_name . ' (v' . $this->theme_version . ')');
    $this->debug_log('GitHub URL: ' . $this->github_url);
    $this->debug_log('GitHub User/Repo: ' . $this->github_username . '/' . $this->github_repo);
  }

  /**
   * Get reliable theme version with multiple fallback methods
   */
  private function get_reliable_theme_version(\WP_Theme $theme): string
  {
    $version_sources = [];

    // Method 1: WordPress theme object (cached)
    $wp_version = $theme->get('Version');
    $version_sources['wp_theme_object'] = $wp_version;
    $this->debug_log('Method 1 - WP Theme Object: ' . $wp_version);

    // Method 2: Direct style.css file reading (uncached)
    $style_css_path = get_stylesheet_directory() . '/style.css';
    if (file_exists($style_css_path)) {
      $style_contents = file_get_contents($style_css_path);
      if (preg_match('/Version:\s*(.+)/i', $style_contents, $matches)) {
        $file_version = trim($matches[1]);
        $version_sources['style_css_file'] = $file_version;
        $this->debug_log('Method 2 - Direct style.css: ' . $file_version);
      }
    }

    // Method 3: Fresh theme object (bypass potential caching)
    $fresh_theme = wp_get_theme(get_stylesheet());
    if (method_exists($fresh_theme, 'cache_delete')) {
      $fresh_theme->cache_delete();
    }
    $fresh_version = $fresh_theme->get('Version');
    $version_sources['fresh_wp_theme'] = $fresh_version;
    $this->debug_log('Method 3 - Fresh WP Theme: ' . $fresh_version);

    // Analyze version consistency
    $unique_versions = array_unique(array_values($version_sources));

    if (count($unique_versions) === 1) {
      $this->debug_log('✓ All version sources agree: ' . $unique_versions[0]);
      return $unique_versions[0];
    } else {
      $this->debug_log('⚠ Version mismatch detected across sources:');
      foreach ($version_sources as $source => $version) {
        $this->debug_log("  - {$source}: {$version}");
      }

      // Prioritize direct file reading over cached WordPress data
      if (isset($version_sources['style_css_file'])) {
        $this->debug_log('→ Using style.css file version as most reliable: ' . $version_sources['style_css_file']);
        return $version_sources['style_css_file'];
      }

      // Fallback to WordPress theme object
      $this->debug_log('→ Falling back to WordPress theme object: ' . $wp_version);
      return $wp_version;
    }
  }

  /**
   * Enhanced debug logging with multiple output methods
   */
  private function debug_log(string $message): void
  {
    if (!$this->debug) {
      return;
    }

    $formatted_message = 'Theme Updater: ' . $message;

    // Try multiple logging methods
    error_log($formatted_message);

    // Also store in option for easier viewing
    $debug_log = get_option('github_theme_updater_debug', []);
    $debug_log[] = [
      'time' => current_time('mysql'),
      'message' => $message
    ];

    // Keep only last 50 entries
    $debug_log = array_slice($debug_log, -50);
    update_option('github_theme_updater_debug', $debug_log);

    // For admin users, also try console log
    if (is_admin() && current_user_can('manage_options')) {
      add_action('admin_footer', function () use ($formatted_message) {
        echo "<script>console.log('" . esc_js($formatted_message) . "');</script>";
      });
    }
  }

  /**
   * Get debug logs for display
   */
  public function get_debug_logs(): array
  {
    return get_option('github_theme_updater_debug', []);
  }

  /**
   * Clear debug logs
   */
  public function clear_debug_logs(): void
  {
    delete_option('github_theme_updater_debug');
    $this->debug_log('Debug logs cleared');
  }

  private function get_repository_info(): void
  {
    if (is_null($this->github_response)) {
      $request_uri = sprintf('https://api.github.com/repos/%s/%s/releases/latest', $this->github_username, $this->github_repo);
      $args = [
        'headers' => [
          'User-Agent' => 'WordPress/' . get_bloginfo('version')
        ],
        'timeout' => 30
      ];
      if ($this->authorize_token) {
        $args['headers']['Authorization'] = "Bearer {$this->authorize_token}";
      }

      $this->debug_log('Requesting: ' . $request_uri);

      $response = wp_remote_get($request_uri, $args);
      if (is_wp_error($response)) {
        $error_message = 'GitHub API Error: ' . $response->get_error_message();
        $this->debug_log($error_message);
        error_log($error_message);
        return;
      }

      $response_code = wp_remote_retrieve_response_code($response);
      $response_body = wp_remote_retrieve_body($response);

      $this->debug_log('Response code: ' . $response_code);

      if ($response_code !== 200) {
        $error_message = 'GitHub API Error: Response code ' . $response_code;
        $this->debug_log($error_message);
        $this->debug_log('Response body: ' . substr($response_body, 0, 500) . '...');
        error_log($error_message . ' - Response: ' . $response_body);
        return;
      }

      $this->github_response = json_decode($response_body);

      if (empty($this->github_response->tag_name)) {
        $error_message = 'GitHub API Error: tag_name missing in response';
        $this->debug_log($error_message);
        $this->debug_log('Full response: ' . print_r($this->github_response, true));
        error_log($error_message . ' - Full response: ' . print_r($this->github_response, true));
        return;
      }

      $this->debug_log('GitHub response received. Tag: ' . $this->github_response->tag_name);
    }
  }

  public function initialize(): void
  {
    $this->debug_log('Initializing hooks');

    add_filter('pre_set_site_transient_update_themes', [$this, 'modify_transient'], 10, 1);
    add_filter('themes_api', [$this, 'theme_popup'], 10, 3);

    // Add action to force check for updates (useful for debugging)
    add_action('admin_init', [$this, 'force_check']);

    // Add admin menu for debugging
    add_action('admin_menu', [$this, 'add_debug_menu']);

    // Add admin notice for updates
    add_action('admin_notices', [$this, 'update_admin_notice']);
  }

  /**
   * Add debug menu to admin
   */
  public function add_debug_menu(): void
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    add_theme_page(
      'Theme Updater Debug',
      'Theme Updater Debug',
      'manage_options',
      'theme-updater-debug',
      [$this, 'debug_page']
    );
  }

  /**
   * Debug page content
   */
  public function debug_page(): void
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    // Handle actions
    if (isset($_GET['action'])) {
      switch ($_GET['action']) {
        case 'force_check':
          delete_site_transient('update_themes');
          $this->debug_log('Manual force check triggered from debug page');
          echo '<div class="notice notice-success"><p>Update check forced!</p></div>';
          break;
        case 'clear_logs':
          $this->clear_debug_logs();
          echo '<div class="notice notice-success"><p>Debug logs cleared!</p></div>';
          break;
      }
    }

    echo '<div class="wrap">';
    echo '<h1>Theme Updater Debug</h1>';

    // Current status
    echo '<h2>Current Status</h2>';
    echo '<table class="form-table">';
    echo '<tr><th>Theme Name</th><td>' . esc_html($this->theme_name) . '</td></tr>';
    echo '<tr><th>Current Version</th><td>' . esc_html($this->theme_version) . '</td></tr>';
    echo '<tr><th>GitHub URL</th><td><a href="' . esc_url($this->github_url) . '" target="_blank">' . esc_html($this->github_url) . '</a></td></tr>';
    echo '<tr><th>GitHub Username</th><td>' . esc_html($this->github_username) . '</td></tr>';
    echo '<tr><th>GitHub Repo</th><td>' . esc_html($this->github_repo) . '</td></tr>';
    echo '<tr><th>Debug Mode</th><td>' . ($this->debug ? 'Enabled' : 'Disabled') . '</td></tr>';
    echo '</table>';

    // Action buttons
    echo '<h2>Actions</h2>';
    echo '<p>';
    echo '<a href="' . add_query_arg('action', 'force_check') . '" class="button button-primary">Force Update Check</a> ';
    echo '<a href="' . add_query_arg('action', 'clear_logs') . '" class="button">Clear Debug Logs</a>';
    echo '</p>';

    // Test GitHub connection
    echo '<h2>GitHub Connection Test</h2>';
    $this->github_response = null; // Reset to force fresh request
    $this->get_repository_info();

    if ($this->github_response) {
      echo '<div class="notice notice-success"><p><strong>✓ Successfully connected to GitHub!</strong></p></div>';
      echo '<p><strong>Latest Release:</strong> ' . esc_html($this->github_response->tag_name) . '</p>';
      echo '<p><strong>Published:</strong> ' . esc_html($this->github_response->published_at) . '</p>';

      // Version comparison analysis
      $latest_version = ltrim($this->github_response->tag_name, 'v');
      $current_version = $this->theme_version;
      $comparison = version_compare($latest_version, $current_version, 'gt');

      echo '<h3>Version Analysis</h3>';
      echo '<table class="form-table">';
      echo '<tr><th>Current Theme Version</th><td>' . esc_html($current_version) . '</td></tr>';
      echo '<tr><th>Latest GitHub Version</th><td>' . esc_html($latest_version) . '</td></tr>';
      echo '<tr><th>Update Available?</th><td>';
      if ($comparison) {
        echo '<span style="color: green; font-weight: bold;">✓ YES - Update from ' . esc_html($current_version) . ' to ' . esc_html($latest_version) . '</span>';
      } else {
        echo '<span style="color: orange; font-weight: bold;">✗ NO - Current (' . esc_html($current_version) . ') is same or newer than GitHub (' . esc_html($latest_version) . ')</span>';
      }
      echo '</td></tr>';
      echo '</table>';

      // Recommendation
      if (!$comparison) {
        echo '<div class="notice notice-warning">';
        echo '<p><strong>Recommendation:</strong> ';
        if (version_compare($current_version, $latest_version, 'gt')) {
          echo 'Your local version is newer than GitHub. Consider creating a new release with version <code>v' . esc_html($current_version) . '</code> or higher.';
        } else {
          echo 'Versions are identical. No update needed.';
        }
        echo '</p></div>';
      }
    } else {
      echo '<div class="notice notice-error"><p><strong>✗ Failed to connect to GitHub</strong></p></div>';
    }

    // Debug logs
    echo '<h2>Debug Logs</h2>';
    $logs = $this->get_debug_logs();
    if (empty($logs)) {
      echo '<p>No debug logs available.</p>';
    } else {
      echo '<div style="background: #f1f1f1; padding: 10px; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px;">';
      foreach (array_reverse($logs) as $log) {
        echo '<div><strong>' . esc_html($log['time']) . ':</strong> ' . esc_html($log['message']) . '</div>';
      }
      echo '</div>';
    }

    echo '</div>';
  }

  public function force_check(): void
  {
    // Only run on specific admin action
    if (isset($_GET['force-check']) && $_GET['force-check'] === 'sfx-theme-update') {
      // Delete the transient to force a fresh check
      delete_site_transient('update_themes');
      $this->debug_log('Forced update check triggered via URL parameter');
      // Redirect back to themes page
      wp_redirect(admin_url('themes.php?updated=force-check'));
      exit;
    }
  }

  /**
   * Show admin notice for available updates
   */
  public function update_admin_notice(): void
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    // Check if we have an update available
    $transient = get_site_transient('update_themes');
    if (isset($transient->response[$this->theme_slug])) {
      $update_data = $transient->response[$this->theme_slug];
      echo '<div class="notice notice-info">';
      echo '<p><strong>Theme Update Available:</strong> ';
      echo esc_html($this->theme_name) . ' version ' . esc_html($update_data['new_version']) . ' is available. ';
      echo '<a href="' . admin_url('themes.php') . '">Update now</a></p>';
      echo '</div>';
    }

    // Show success message after force check
    if (isset($_GET['updated']) && $_GET['updated'] === 'force-check') {
      echo '<div class="notice notice-success is-dismissible">';
      echo '<p>Theme update check completed successfully!</p>';
      echo '</div>';
    }
  }

  public function modify_transient($transient)
  {
    if (!isset($transient->checked)) {
      $this->debug_log('No checked themes in transient');
      return $transient;
    }

    if (!isset($transient->checked[$this->theme_slug])) {
      $this->debug_log('Theme slug ' . $this->theme_slug . ' not found in checked themes');
      return $transient;
    }

    $this->debug_log('Checking for updates. Current version: ' . $this->theme_version);

    $this->get_repository_info();
    if (is_null($this->github_response)) {
      $this->debug_log('No GitHub response available - cannot check for updates');
      return $transient;
    }

    $latest_version = ltrim($this->github_response->tag_name, 'v');
    $current_version = $this->theme_version;

    $this->debug_log('Comparing versions - Current: ' . $current_version . ', Latest: ' . $latest_version);

    $doUpdate = version_compare($latest_version, $current_version, 'gt');
    if ($doUpdate) {
      $this->debug_log('Update available! ' . $current_version . ' → ' . $latest_version);

      $package = $this->github_response->zipball_url;
      // Use custom release asset (zip) if available
      if (!empty($this->github_response->assets) && is_array($this->github_response->assets)) {
        foreach ($this->github_response->assets as $asset) {
          if (isset($asset->name) && str_ends_with($asset->name, '.zip') && isset($asset->browser_download_url)) {
            $package = $asset->browser_download_url;
            $this->debug_log('Using custom asset: ' . $asset->name);
            break;
          }
        }
      }
      if ($this->authorize_token) {
        $package = add_query_arg(['access_token' => $this->authorize_token], $package);
      }
      $obj = [
        'theme' => $this->theme_slug,
        'new_version' => $latest_version,
        'url' => $this->github_url,
        'package' => $package,
      ];
      $transient->response[$this->theme_slug] = $obj;
      $this->debug_log('Update added to transient');
    } else {
      $this->debug_log('No update needed. Current version is latest or newer');
    }
    return $transient;
  }

  public function theme_popup($result, $action, $args)
  {
    if ($action !== 'theme_information') {
      return $result;
    }
    if (!isset($args->slug) || $args->slug !== $this->theme_slug) {
      return $result;
    }

    $this->debug_log('Theme popup requested for ' . $this->theme_slug);

    $this->get_repository_info();
    if (is_null($this->github_response)) {
      return $result;
    }

    $theme = wp_get_theme($this->theme_slug);
    $release_body = $this->github_response->body;

    // If Parsedown class exists, convert markdown to HTML
    if (class_exists('\Parsedown')) {
      $parsedown = new \Parsedown();
      $release_body = $parsedown->text($release_body);
    }

    $info = [
      'name' => $theme->get('Name'),
      'slug' => $this->theme_slug,
      'version' => ltrim($this->github_response->tag_name, 'v'),
      'author' => $theme->get('Author'),
      'author_profile' => $theme->get('AuthorURI'),
      'last_updated' => $this->github_response->published_at,
      'homepage' => $theme->get('ThemeURI'),
      'short_description' => $theme->get('Description'),
      'sections' => [
        'description' => $theme->get('Description'),
        'changelog' => $release_body,
      ],
      'download_link' => $this->github_response->zipball_url,
      'tested' => '6.7.1',
    ];
    return (object) $info;
  }
}
