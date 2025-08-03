<?php

namespace SFX\CustomScriptsManager;

class Controller
{
  public const OPTION_NAME = 'sfx_custom_scripts_manager_options';
  private const UPLOAD_SUBDIR = 'custom-scripts';

  public function __construct()
  {
    // Initialize components
    AdminPage::register();
    AssetManager::register();
    PostType::init();

    // Register hooks through consolidated system
    add_action('sfx_init_advanced_features', [$this, 'execute_custom_scripts']);
    
    // Clear caches when custom scripts are updated
    add_action('save_post_sfx_custom_script', [$this, 'clear_custom_script_caches']);
    add_action('delete_post', [$this, 'clear_custom_script_caches']);
  }

  /**
   * Clear custom script caches when posts are updated
   * 
   * @param int $post_id
   */
  public function clear_custom_script_caches(int $post_id): void
  {
    $post_type = get_post_type($post_id);
    if ($post_type !== 'sfx_custom_script') {
      return;
    }
    
    // Clear all custom script caches
    global $wpdb;
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        '_transient_sfx_custom_scripts_%'
      )
    );
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
   * Enqueue or register custom scripts/styles from post type with caching.
   */
  public function execute_custom_scripts()
  {
    // Create cache key based on current page context
    $cache_key = 'sfx_custom_scripts_' . md5(serialize([
      'post_id' => get_the_ID(),
      'page_type' => self::get_current_page_type(),
      'is_admin' => is_admin(),
      'is_frontend' => !is_admin()
    ]));
    
    $cached_scripts = get_transient($cache_key);
    
    if ($cached_scripts !== false) {
      // Execute cached script configurations
      foreach ($cached_scripts as $script_config) {
        $this->handle_cached_script($script_config);
      }
      return;
    }

    // Get all published custom scripts
    $scripts = get_posts([
      'post_type' => 'sfx_custom_script',
      'post_status' => 'publish',
      'numberposts' => -1,
      'meta_key' => '_priority',
      'orderby' => 'meta_value_num',
      'order' => 'ASC'
    ]);

    $script_configs = [];
    
    foreach ($scripts as $script_post) {
      // Check if script should be loaded based on conditional settings
      if (self::should_load_script($script_post->ID)) {
        $script_config = $this->build_script_config($script_post);
        if ($script_config) {
          $script_configs[] = $script_config;
          $this->handle_custom_script($script_post);
        }
      }
    }
    
    // Cache the script configurations for 30 minutes
    set_transient($cache_key, $script_configs, 30 * MINUTE_IN_SECONDS);
  }

  /**
   * Build script configuration for caching
   * 
   * @param \WP_Post $script_post
   * @return array|null
   */
  private function build_script_config(\WP_Post $script_post): ?array
  {
    $script_type = self::get_custom_script_config($script_post->ID, 'script_type') ?: 'javascript';
    $location = self::get_custom_script_config($script_post->ID, 'location') ?: 'footer';
    $include_type = self::get_custom_script_config($script_post->ID, 'include_type') ?: 'enqueue';
    $frontend_only = self::get_custom_script_config($script_post->ID, 'frontend_only') ?: '1';
    $source_type = self::get_custom_script_config($script_post->ID, 'script_source_type') ?: 'file';
    $dependencies = self::get_custom_script_config($script_post->ID, 'dependencies') ?: '';
    $priority = self::get_custom_script_config($script_post->ID, 'priority') ?: 10;
    $handle = sanitize_title($script_post->post_title);

    // Determine the script source URL
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
      if (!$src) return null;
    } elseif ($source_type === 'inline') {
      $src = self::get_custom_script_config($script_post->ID, 'script_content') ?: '';
    }

    if (empty($src)) {
      return null;
    }

    return [
      'handle' => $handle,
      'src' => $src,
      'script_type' => $script_type,
      'location' => $location,
      'include_type' => $include_type,
      'frontend_only' => $frontend_only,
      'dependencies' => $dependencies,
      'priority' => $priority,
      'source_type' => $source_type
    ];
  }

  /**
   * Handle cached script configuration
   * 
   * @param array $script_config
   */
  private function handle_cached_script(array $script_config): void
  {
    $in_footer = ($script_config['location'] === 'footer');
    $deps = !empty($script_config['dependencies']) ? array_map('trim', explode(',', $script_config['dependencies'])) : [];

    // Only enqueue on frontend if frontend_only is set
    if ($script_config['frontend_only'] === '1' && is_admin()) {
      return;
    }

    if ($script_config['script_type'] === 'javascript') {
      if ($script_config['include_type'] === 'enqueue') {
        add_action('wp_enqueue_scripts', function () use ($script_config, $deps, $in_footer) {
          wp_enqueue_script($script_config['handle'], $script_config['src'], $deps, null, $in_footer);
        }, $script_config['priority']);
      } elseif ($script_config['include_type'] === 'register') {
        add_action('wp_enqueue_scripts', function () use ($script_config, $deps, $in_footer) {
          wp_register_script($script_config['handle'], $script_config['src'], $deps, null, $in_footer);
        }, $script_config['priority']);
      }
    } elseif (strtolower($script_config['script_type']) === 'css') {
      if ($script_config['include_type'] === 'enqueue') {
        add_action('wp_enqueue_scripts', function () use ($script_config, $deps) {
          wp_enqueue_style($script_config['handle'], $script_config['src'], $deps);
        }, $script_config['priority']);
      } elseif ($script_config['include_type'] === 'register') {
        add_action('wp_enqueue_scripts', function () use ($script_config, $deps) {
          wp_register_style($script_config['handle'], $script_config['src'], $deps);
        }, $script_config['priority']);
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
      'url' => admin_url('edit.php?post_type=' . PostType::$post_type),
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