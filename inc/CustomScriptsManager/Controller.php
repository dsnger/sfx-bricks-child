<?php

namespace SFX\CustomScriptsManager;

class Controller
{
  public const OPTION_NAME = 'sfx_custom_scripts_manager_options';
  private const UPLOAD_SUBDIR = 'custom-scripts';

  public function __construct()
  {
    // Settings::register(self::OPTION_NAME); // No longer needed with post type
    AdminPage::register();
    AssetManager::register();
    PostType::init();

    // Register script execution on init
    add_action('init', [$this, 'execute_custom_scripts']);
    add_action('save_post_sfx_custom_script', [$this, 'handle_script_save'], 10, 2);
    add_action('delete_post', [$this, 'handle_script_delete'], 10, 1);
  }

  /**
   * Handle script save/update.
   */
  public function handle_script_save(int $post_id, \WP_Post $post): void
  {
    // Only process if this is our custom script post type
    if ($post->post_type !== 'sfx_custom_script') {
      return;
    }

    // Cleanup old CDN files if script source type changed
    $old_script_source_type = get_post_meta($post_id, '_script_source_type', true);
    $new_script_source_type = $_POST['script_source_type'] ?? '';
    
    if ($old_script_source_type === 'cdn_file' && $new_script_source_type !== 'cdn_file') {
      $handle = sanitize_title($post->post_title);
      $this->delete_local_cdn_file($handle);
    }
  }

  /**
   * Handle script deletion.
   */
  public function handle_script_delete(int $post_id): void
  {
    $post = get_post($post_id);
    if ($post && $post->post_type === 'sfx_custom_script') {
      $script_source_type = get_post_meta($post_id, '_script_source_type', true);
      if ($script_source_type === 'cdn_file') {
        $handle = sanitize_title($post->post_title);
        $this->delete_local_cdn_file($handle);
      }
    }
  }

  /**
   * Enqueue or register custom scripts/styles from post type.
   */
  public function execute_custom_scripts()
  {
    // Get all published custom scripts
    $scripts = get_posts([
      'post_type' => 'sfx_custom_script',
      'post_status' => 'publish',
      'numberposts' => -1,
      'orderby' => 'menu_order',
      'order' => 'ASC'
    ]);

    foreach ($scripts as $script_post) {
      // Check if script should be loaded based on conditional settings
      if (sfx_should_load_script($script_post->ID)) {
        $this->handle_custom_script($script_post);
      }
    }
  }

  private function handle_custom_script(\WP_Post $script_post)
  {
    $script_type = sfx_get_custom_script_config($script_post->ID, 'script_type') ?: 'javascript';
    $location = sfx_get_custom_script_config($script_post->ID, 'location') ?: 'footer';
    $include_type = sfx_get_custom_script_config($script_post->ID, 'include_type') ?: 'enqueue';
    $frontend_only = sfx_get_custom_script_config($script_post->ID, 'frontend_only') ?: '1';
    $source_type = sfx_get_custom_script_config($script_post->ID, 'script_source_type') ?: 'file';
    $dependencies = sfx_get_custom_script_config($script_post->ID, 'dependencies') ?: '';
    $handle = sanitize_title($script_post->post_title);

    // Determine the script source URL based on the selected source type
    $src = '';
    if ($source_type === 'file') {
      $src = sfx_get_custom_script_config($script_post->ID, 'script_file') ?: '';
    } elseif ($source_type === 'cdn') {
      $src = sfx_get_custom_script_config($script_post->ID, 'script_cdn') ?: '';
    } elseif ($source_type === 'cdn_file') {
      $src = $this->download_cdn_script(
        sfx_get_custom_script_config($script_post->ID, 'script_cdn') ?: '',
        $handle,
        $script_type
      );
      if (!$src) return;
    } elseif ($source_type === 'inline') {
      // For inline scripts, we'll handle them differently
      $this->handle_inline_script($script_post, $script_type, $location, $include_type, $frontend_only);
      return;
    } else {
      return; // Exit if the source type is unrecognized
    }

    if (empty($src)) {
      return; // Exit if no source URL
    }

    $in_footer = ($location === 'footer');
    $deps = !empty($dependencies) ? array_map('trim', explode(',', $dependencies)) : [];

    // Only enqueue on frontend if frontend_only is set
    if ($frontend_only === '1' && is_admin()) {
      return;
    }

    if ($script_type === 'javascript') {
      if ($include_type === 'enqueue') {
        add_action('wp_enqueue_scripts', function () use ($handle, $src, $deps, $in_footer) {
          wp_enqueue_script($handle, $src, $deps, null, $in_footer);
        });
      } elseif ($include_type === 'register') {
        add_action('wp_enqueue_scripts', function () use ($handle, $src, $deps, $in_footer) {
          wp_register_script($handle, $src, $deps, null, $in_footer);
        });
      }
    } elseif (strtolower($script_type) === 'css') {
      if ($include_type === 'enqueue') {
        add_action('wp_enqueue_scripts', function () use ($handle, $src, $deps) {
          wp_enqueue_style($handle, $src, $deps);
        });
      } elseif ($include_type === 'register') {
        add_action('wp_enqueue_scripts', function () use ($handle, $src, $deps) {
          wp_register_style($handle, $src, $deps);
        });
      }
    }
  }

  /**
   * Handle inline scripts.
   */
  private function handle_inline_script(\WP_Post $script_post, string $script_type, string $location, string $include_type, string $frontend_only): void
  {
    $script_content = sfx_get_custom_script_config($script_post->ID, 'script_content') ?: '';
    if (empty($script_content)) {
      return;
    }

    $handle = sanitize_title($script_post->post_title);
    $in_footer = ($location === 'footer');

    // Only enqueue on frontend if frontend_only is set
    if ($frontend_only === '1' && is_admin()) {
      return;
    }

    if ($script_type === 'javascript') {
      if ($include_type === 'enqueue') {
        add_action('wp_enqueue_scripts', function () use ($handle, $script_content, $in_footer) {
          wp_enqueue_script($handle, '', [], null, $in_footer);
          wp_add_inline_script($handle, $script_content);
        });
      } elseif ($include_type === 'register') {
        add_action('wp_enqueue_scripts', function () use ($handle, $script_content, $in_footer) {
          wp_register_script($handle, '', [], null, $in_footer);
          wp_add_inline_script($handle, $script_content);
        });
      }
    } elseif (strtolower($script_type) === 'css') {
      if ($include_type === 'enqueue') {
        add_action('wp_enqueue_scripts', function () use ($handle, $script_content) {
          wp_enqueue_style($handle, '');
          wp_add_inline_style($handle, $script_content);
        });
      } elseif ($include_type === 'register') {
        add_action('wp_enqueue_scripts', function () use ($handle, $script_content) {
          wp_register_style($handle, '');
          wp_add_inline_style($handle, $script_content);
        });
      }
    }
  }

  /**
   * Download a CDN file and return the local URL, or null on failure.
   */
  private function download_cdn_script(string $cdn_url, string $handle, string $script_type): ?string
  {
    if (empty($cdn_url) || !filter_var($cdn_url, FILTER_VALIDATE_URL)) {
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
      if (!is_wp_error($remote_headers)) {
        $remote_size = isset($remote_headers['headers']['content-length']) ? (int)$remote_headers['headers']['content-length'] : null;
        $local_size = filesize($local_path);
        if ($remote_size && $remote_size !== $local_size) {
          $needs_download = true;
        }
      }
    }
    
    if ($needs_download) {
      $response = wp_remote_get($cdn_url, ['timeout' => 15]);
      if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return null;
      }
      $body = wp_remote_retrieve_body($response);
      if (empty($body)) return null;
      
      $result = file_put_contents($local_path, $body);
      if ($result === false) {
        return null;
      }
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