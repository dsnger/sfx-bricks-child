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
      'meta_key' => '_priority',
      'orderby' => 'meta_value_num',
      'order' => 'ASC'
    ]);

    foreach ($scripts as $script_post) {
      // Check if script should be loaded based on conditional settings
      if (self::should_load_script($script_post->ID)) {
        $this->handle_custom_script($script_post);
      }
    }
  }

  private function handle_custom_script(\WP_Post $script_post)
  {
    $script_type = self::get_custom_script_config($script_post->ID, 'script_type') ?: 'javascript';
    $location = self::get_custom_script_config($script_post->ID, 'location') ?: 'footer';
    $include_type = self::get_custom_script_config($script_post->ID, 'include_type') ?: 'enqueue';
    $frontend_only = self::get_custom_script_config($script_post->ID, 'frontend_only') ?: '1';
    $source_type = self::get_custom_script_config($script_post->ID, 'script_source_type') ?: 'file';
    $dependencies = self::get_custom_script_config($script_post->ID, 'dependencies') ?: '';
    $priority = self::get_custom_script_config($script_post->ID, 'priority') ?: 10;
    $handle = sanitize_title($script_post->post_title);

    // Determine the script source URL based on the selected source type
    $src = '';
    if ($source_type === 'file') {
      $src = self::get_custom_script_config($script_post->ID, 'script_file') ?: '';
    } elseif ($source_type === 'cdn') {
      $src = self::get_custom_script_config($script_post->ID, 'script_cdn') ?: '';
    } elseif ($source_type === 'cdn_file') {
      $src = $this->download_cdn_script(
        self::get_custom_script_config($script_post->ID, 'script_cdn') ?: '',
        $handle,
        $script_type
      );
      if (!$src) return;
    } elseif ($source_type === 'inline') {
      // For inline scripts, we'll handle them differently
      $this->handle_inline_script($script_post, $script_type, $location, $include_type, $frontend_only, $priority);
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
        }, $priority);
      } elseif ($include_type === 'register') {
        add_action('wp_enqueue_scripts', function () use ($handle, $src, $deps, $in_footer) {
          wp_register_script($handle, $src, $deps, null, $in_footer);
        }, $priority);
      }
    } elseif (strtolower($script_type) === 'css') {
      if ($include_type === 'enqueue') {
        add_action('wp_enqueue_scripts', function () use ($handle, $src, $deps) {
          wp_enqueue_style($handle, $src, $deps);
        }, $priority);
      } elseif ($include_type === 'register') {
        add_action('wp_enqueue_scripts', function () use ($handle, $src, $deps) {
          wp_register_style($handle, $src, $deps);
        }, $priority);
      }
    }
  }

  /**
   * Handle inline scripts.
   */
  private function handle_inline_script(\WP_Post $script_post, string $script_type, string $location, string $include_type, string $frontend_only, int $priority): void
  {
    $script_content = self::get_custom_script_config($script_post->ID, 'script_content') ?: '';
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
        }, $priority);
      } elseif ($include_type === 'register') {
        add_action('wp_enqueue_scripts', function () use ($handle, $script_content, $in_footer) {
          wp_register_script($handle, '', [], null, $in_footer);
          wp_add_inline_script($handle, $script_content);
        }, $priority);
      }
    } elseif (strtolower($script_type) === 'css') {
      if ($include_type === 'enqueue') {
        add_action('wp_enqueue_scripts', function () use ($handle, $script_content) {
          wp_enqueue_style($handle, '');
          wp_add_inline_style($handle, $script_content);
        }, $priority);
      } elseif ($include_type === 'register') {
        add_action('wp_enqueue_scripts', function () use ($handle, $script_content) {
          wp_register_style($handle, '');
          wp_add_inline_style($handle, $script_content);
        }, $priority);
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

  /**
   * Get a custom script configuration value.
   *
   * @param int    $post_id The post ID.
   * @param string $key     The configuration key.
   * @return mixed          The configuration value, or null if not found.
   */
  public static function get_custom_script_config(int $post_id, string $key) {
    return get_post_meta($post_id, '_' . $key, true);
  }

  /**
   * Check if a script should be loaded on the current page based on conditional settings.
   *
   * @param int $script_post_id The script post ID.
   * @return bool Whether the script should be loaded.
   */
  public static function should_load_script(int $script_post_id): bool {
    $include_posts = get_post_meta($script_post_id, '_include_posts', true) ?: [];
    $include_pages = get_post_meta($script_post_id, '_include_pages', true) ?: [];
    $exclude_posts = get_post_meta($script_post_id, '_exclude_posts', true) ?: [];
    $exclude_pages = get_post_meta($script_post_id, '_exclude_pages', true) ?: [];
    
    // If no conditions are set, load everywhere
    if (empty($include_posts) && empty($include_pages) && empty($exclude_posts) && empty($exclude_pages)) {
      return true;
    }
    
    $current_post_id = get_the_ID();
    $should_load = true;
    
    // Check include posts
    if (!empty($include_posts)) {
      $should_load = in_array($current_post_id, $include_posts);
    }
    
    // Check include pages
    if (!empty($include_pages) && $should_load) {
      $current_page_type = self::get_current_page_type();
      $should_load = in_array($current_page_type, $include_pages);
    }
    
    // Check exclude posts
    if (!empty($exclude_posts) && $should_load) {
      $should_load = !in_array($current_post_id, $exclude_posts);
    }
    
    // Check exclude pages
    if (!empty($exclude_pages) && $should_load) {
      $current_page_type = self::get_current_page_type();
      $should_load = !in_array($current_page_type, $exclude_pages);
    }
    
    return $should_load;
  }

  /**
   * Get the current page type for conditional loading.
   *
   * @return string The current page type.
   */
  public static function get_current_page_type(): string {
    if (is_front_page()) {
      return 'front_page';
    }
    
    if (is_home()) {
      return 'home';
    }
    
    if (is_single()) {
      $post_type = get_post_type();
      if ($post_type === 'post') {
        return 'single';
      }
      return 'single_' . $post_type;
    }
    
    if (is_page()) {
      return 'page';
    }
    
    if (is_archive()) {
      $post_type = get_post_type();
      if ($post_type) {
        return 'archive_' . $post_type;
      }
      return 'archive';
    }
    
    if (is_search()) {
      return 'search';
    }
    
    if (is_404()) {
      return '404';
    }
    
    return '';
  }

}