<?php

declare(strict_types=1);

namespace SFX\BricksChild;

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
  private bool $debug = false; // Enable debugging
  private bool $dev_mode = false; // Development mode flag

  public function __construct()
  {
    // Extract username and repo from GitHub URL
    $path = parse_url($this->github_url, PHP_URL_PATH);
    [$this->github_username, $this->github_repo] = array_slice(explode('/', trim($path, '/')), 0, 2);

    $theme = wp_get_theme(get_stylesheet());
    $this->theme_slug = get_stylesheet();
    $this->theme_version = $theme->get('Version');
    $this->theme_name = $theme->get('Name');

    // Check if we're in development mode
    if (class_exists(Environment::class)) {
      $this->dev_mode = Environment::is_dev_mode();
      
      if ($this->debug && $this->dev_mode) {
        error_log('Theme Updater: Development mode is active, updates are disabled');
      }
    }

    if ($this->debug) {
      error_log('Theme Updater: Initialized for ' . $this->theme_name . ' (v' . $this->theme_version . ')');
    }
  }

  private function get_repository_info(): void
  {
    // Skip if dev mode is active
    if ($this->dev_mode) {
      if ($this->debug) {
        error_log('Theme Updater: Skipping repository check in development mode');
      }
      return;
    }

    if (is_null($this->github_response)) {
      $request_uri = sprintf('https://api.github.com/repos/%s/%s/releases/latest', $this->github_username, $this->github_repo);
      $args = [
        'headers' => [
          'User-Agent' => 'WordPress/' . get_bloginfo('version')
        ]
      ];
      if ($this->authorize_token) {
        $args['headers']['Authorization'] = "Bearer {$this->authorize_token}";
      }

      if ($this->debug) {
        error_log('Theme Updater: Requesting ' . $request_uri);
      }

      $response = wp_remote_get($request_uri, $args);
      if (is_wp_error($response)) {
        error_log('GitHub Theme Updater Error: ' . $response->get_error_message());
        return;
      }
      $response_code = wp_remote_retrieve_response_code($response);
      if ($response_code !== 200) {
        error_log('GitHub Theme Updater Error: Response code ' . $response_code);
        error_log('Response: ' . wp_remote_retrieve_body($response));
        return;
      }
      $this->github_response = json_decode(wp_remote_retrieve_body($response));

      if ($this->debug) {
        error_log('Theme Updater: GitHub response received. Tag: ' .
          ($this->github_response ? $this->github_response->tag_name : 'unknown'));
      }
    }
  }

  public function initialize(): void
  {
    // Skip initialization if in development mode
    if ($this->dev_mode) {
      if ($this->debug) {
        error_log('Theme Updater: Skipping initialization in development mode');
      }
      return;
    }

    add_filter('pre_set_site_transient_update_themes', [$this, 'modify_transient'], 10, 1);
    add_filter('themes_api', [$this, 'theme_popup'], 10, 3);
    add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);

    // Add action to force check for updates (useful for debugging)
    add_action('admin_init', [$this, 'force_check']);
  }

  public function force_check(): void
  {
    // Only run on specific admin action
    if (isset($_GET['force-check']) && $_GET['force-check'] === 'sfx-theme-update') {
      // Delete the transient to force a fresh check
      delete_site_transient('update_themes');
      if ($this->debug) {
        error_log('Theme Updater: Forced update check triggered');
      }
      // Redirect back to themes page
      wp_redirect(admin_url('themes.php'));
      exit;
    }
  }

  public function modify_transient($transient)
  {
    if (!isset($transient->checked)) {
      return $transient;
    }

    if ($this->debug) {
      error_log('Theme Updater: Checking for updates. Current version: ' . $this->theme_version);
    }

    $this->get_repository_info();
    if (is_null($this->github_response)) {
      if ($this->debug) {
        error_log('Theme Updater: No GitHub response available');
      }
      return $transient;
    }

    $latest_version = ltrim($this->github_response->tag_name, 'v');
    $current_version = $this->theme_version;

    if ($this->debug) {
      error_log('Theme Updater: Comparing versions - Current: ' . $current_version . ', Latest: ' . $latest_version);
    }

    $doUpdate = version_compare($latest_version, $current_version, 'gt');
    if ($doUpdate) {
      if ($this->debug) {
        error_log('Theme Updater: Update available! ' . $current_version . ' → ' . $latest_version);
      }

      $package = $this->github_response->zipball_url;
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
    } else {
      if ($this->debug) {
        error_log('Theme Updater: No update needed. Current version is latest or newer.');
      }
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

    if ($this->debug) {
      error_log('Theme Updater: Theme popup requested for ' . $this->theme_slug);
    }

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

  public function after_install($response, $hook_extra, $result)
  {
    global $wp_filesystem;
    if (!isset($hook_extra['theme']) || $hook_extra['theme'] !== $this->theme_slug) {
      return $response;
    }

    $theme_root = get_theme_root($this->theme_slug);
    $install_directory = $theme_root . '/' . $this->theme_slug;
    $extracted_folder = $result['destination'];

    if ($this->debug) {
      error_log('Theme Updater: After install process for ' . $this->theme_slug);
      error_log('Theme Updater: Extracted folder: ' . $extracted_folder);
      error_log('Theme Updater: Target install directory: ' . $install_directory);
    }

    // Remove the old theme folder if it exists
    if ($wp_filesystem->exists($install_directory)) {
      if ($this->debug) {
        error_log('Theme Updater: Removing old theme directory: ' . $install_directory);
      }
      $wp_filesystem->delete($install_directory, true);
    }

    // Move/rename the extracted folder to the correct theme folder
    if ($extracted_folder !== $install_directory) {
      if ($this->debug) {
        error_log('Theme Updater: Moving extracted folder to theme directory.');
      }
      $wp_filesystem->move($extracted_folder, $install_directory);
    } else {
      if ($this->debug) {
        error_log('Theme Updater: Extracted folder already matches theme directory.');
      }
    }

    $result['destination'] = $install_directory;

    if ($this->debug) {
      error_log('Theme Updater: Theme installed to ' . $install_directory);
    }

    return $result;
  }
}
