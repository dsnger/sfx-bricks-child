<?php
declare(strict_types=1);

namespace SFX\BricksChild;

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

  public function __construct()
  {
    // Extract username and repo from GitHub URL
    $path = parse_url($this->github_url, PHP_URL_PATH);
    [$this->github_username, $this->github_repo] = array_slice(explode('/', trim($path, '/')), 0, 2);

    $theme = wp_get_theme(get_stylesheet());
    $this->theme_slug = get_stylesheet();
    $this->theme_version = $theme->get('Version');
    $this->theme_name = $theme->get('Name');
  }

  private function get_repository_info(): void
  {
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
      $response = wp_remote_get($request_uri, $args);
      if (is_wp_error($response)) {
        error_log('GitHub Theme Updater Error: ' . $response->get_error_message());
        return;
      }
      $response_code = wp_remote_retrieve_response_code($response);
      if ($response_code !== 200) {
        error_log('GitHub Theme Updater Error: Response code ' . $response_code);
        return;
      }
      $this->github_response = json_decode(wp_remote_retrieve_body($response));
    }
  }

  public function initialize(): void
  {
    add_filter('pre_set_site_transient_update_themes', [$this, 'modify_transient'], 10, 1);
    add_filter('themes_api', [$this, 'theme_popup'], 10, 3);
    add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
  }

  public function modify_transient($transient)
  {
    if (!isset($transient->checked)) {
      return $transient;
    }
    $this->get_repository_info();
    if (is_null($this->github_response)) {
      return $transient;
    }
    $latest_version = ltrim($this->github_response->tag_name, 'v');
    $current_version = $transient->checked[$this->theme_slug] ?? null;
    if (!$current_version) {
      return $transient;
    }
    $doUpdate = version_compare($latest_version, $current_version, 'gt');
    if ($doUpdate) {
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
    $this->get_repository_info();
    if (is_null($this->github_response)) {
      return $result;
    }
    $theme = wp_get_theme($this->theme_slug);
    $info = [
      'name' => $theme->get('Name'),
      'slug' => $this->theme_slug,
      'version' => $this->github_response->tag_name,
      'author' => $theme->get('Author'),
      'author_profile' => $theme->get('AuthorURI'),
      'last_updated' => $this->github_response->published_at,
      'homepage' => $theme->get('ThemeURI'),
      'short_description' => $theme->get('Description'),
      'sections' => [
        'description' => $theme->get('Description'),
        'changelog' => nl2br($this->github_response->body),
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
    $install_directory = get_theme_root($this->theme_slug) . '/' . $this->theme_slug;
    $wp_filesystem->move($result['destination'], $install_directory);
    $result['destination'] = $install_directory;
    return $result;
  }
}
