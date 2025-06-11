<?php

namespace SFX\CustomScriptsManager;

class Controller
{
  public const OPTION_NAME = 'sfx_custom_scripts_manager_options';
  private const UPLOAD_SUBDIR = 'custom-scripts';

  public function __construct()
  {
    Settings::register(self::OPTION_NAME);
    AdminPage::register();
    AssetManager::register();

    // Register script execution on init
    add_action('init', [$this, 'execute_custom_scripts']);
    add_action('update_option_' . self::OPTION_NAME, [$this, 'handle_options'], 10, 2);
  }

  private function is_option_enabled(string $option_key): bool
  {
    $options = get_option(self::OPTION_NAME, []);
    return !empty($options[$option_key]);
  }

  public function handle_options($old_value = null, $value = null): void
  {
    // Cleanup removed or changed CDN files
    $old_scripts = $old_value['custom_scripts'] ?? [];
    $new_scripts = $value['custom_scripts'] ?? [];
    $old_handles = [];
    foreach ($old_scripts as $old_script) {
      if (($old_script['script_source_type'] ?? '') === 'cdn_file') {
        $old_handles[sanitize_title($old_script['script_name'] ?? '')] = $old_script['script_cdn'] ?? '';
      }
    }
    foreach ($new_scripts as $new_script) {
      $handle = sanitize_title($new_script['script_name'] ?? '');
      if (isset($old_handles[$handle]) && $old_handles[$handle] === ($new_script['script_cdn'] ?? '')) {
        unset($old_handles[$handle]); // Still present and unchanged
      }
    }
    // Delete files for removed/changed scripts
    foreach ($old_handles as $handle => $cdn_url) {
      $this->delete_local_cdn_file($handle);
    }
  }

  /**
   * Enqueue or register custom scripts/styles from settings.
   */
  public function execute_custom_scripts()
  {
    $options = get_option(self::OPTION_NAME, []);
    $scripts = $options['custom_scripts'] ?? [];
    if ($scripts) {
      foreach ($scripts as $script) {
        $this->handle_custom_scripts($script);
      }
    }
  }

  private function handle_custom_scripts($script)
  {
    $script_type = $script['script_type'] ?? '';
    $location = $script['location'] ?? 'footer';
    $include_type = $script['include_type'] ?? 'enqueue';
    $frontend_only = !empty($script['frontend_only']);
    $source_type = $script['script_source_type'] ?? '';
    $handle = sanitize_title($script['script_name'] ?? '');

    // Determine the script source URL based on the selected source type
    if ($source_type === 'file') {
      $src = $script['script_file'] ?? '';
    } elseif ($source_type === 'cdn') {
      $src = $script['script_cdn'] ?? '';
    } elseif ($source_type === 'cdn_file') {
      $src = $this->download_cdn_script($script['script_cdn'] ?? '', $handle, $script_type);
      if (!$src) return;
    } else {
      return; // Exit if the source type is unrecognized
    }

    $in_footer = ($location === 'footer');

    // Only enqueue on frontend if frontend_only is set
    if ($frontend_only && is_admin()) {
      return;
    }

    if ($script_type === 'javascript') {
      if ($include_type === 'enqueue') {
        add_action('wp_enqueue_scripts', function () use ($handle, $src, $in_footer) {
          wp_enqueue_script($handle, $src, [], null, $in_footer);
        });
      } elseif ($include_type === 'register') {
        add_action('wp_enqueue_scripts', function () use ($handle, $src, $in_footer) {
          wp_register_script($handle, $src, [], null, $in_footer);
        });
      }
    } elseif (strtolower($script_type) === 'css') {
      if ($include_type === 'enqueue') {
        add_action('wp_enqueue_scripts', function () use ($handle, $src) {
          wp_enqueue_style($handle, $src);
        });
      } elseif ($include_type === 'register') {
        add_action('wp_enqueue_scripts', function () use ($handle, $src) {
          wp_register_style($handle, $src);
        });
      }
    }
  }

  /**
   * Download a CDN file and return the local URL, or false on failure.
   */
  private function download_cdn_script(string $cdn_url, string $handle, string $script_type): ?string
  {
    if (!filter_var($cdn_url, FILTER_VALIDATE_URL)) {
      return null;
    }
    $ext = strtolower(pathinfo(parse_url($cdn_url, PHP_URL_PATH), PATHINFO_EXTENSION));
    if ($script_type === 'javascript' && $ext !== 'js') return null;
    if (strtolower($script_type) === 'css' && $ext !== 'css') return null;
    if (!in_array($ext, ['js', 'css'], true)) return null;

    $upload_dir = wp_upload_dir();
    $dir = trailingslashit($upload_dir['basedir']) . self::UPLOAD_SUBDIR;
    if (!is_dir($dir)) {
      wp_mkdir_p($dir);
    }
    $filename = $handle . '.' . $ext;
    $local_path = trailingslashit($dir) . $filename;
    $local_url = trailingslashit($upload_dir['baseurl']) . self::UPLOAD_SUBDIR . '/' . $filename;

    // Download if not present or outdated
    $needs_download = !file_exists($local_path);
    if (!$needs_download) {
      $remote_headers = wp_remote_head($cdn_url);
      $remote_size = isset($remote_headers['headers']['content-length']) ? (int)$remote_headers['headers']['content-length'] : null;
      $local_size = filesize($local_path);
      if ($remote_size && $remote_size !== $local_size) {
        $needs_download = true;
      }
    }
    if ($needs_download) {
      $response = wp_remote_get($cdn_url, ['timeout' => 15]);
      if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return null;
      }
      $body = wp_remote_retrieve_body($response);
      if (empty($body)) return null;
      file_put_contents($local_path, $body);
    }
    return $local_url;
  }

  /**
   * Delete a local CDN file by handle.
   */
  private function delete_local_cdn_file(string $handle): void
  {
    $upload_dir = wp_upload_dir();
    $dir = trailingslashit($upload_dir['basedir']) . self::UPLOAD_SUBDIR;
    foreach (['js', 'css'] as $ext) {
      $file = trailingslashit($dir) . $handle . '.' . $ext;
      if (file_exists($file)) {
        @unlink($file);
      }
    }
  }

  public static function get_feature_config(): array
  {
    return [
      'class' => self::class,
      'menu_slug' => AdminPage::$menu_slug,
      'page_title' => AdminPage::$page_title,
      'description' => AdminPage::$description,
      'error' => 'Missing CustomScriptsManagerController class in theme',
      'hook'  => null,
    ];
  }

}