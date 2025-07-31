<?php

namespace SFX;

use Bricks\Elements;

class SFXBricksChildTheme
{

  private $INC_DIR;
  private $ASSET_DIR;

  /**
   * Global feature registry for controllers to register themselves.
   *
   * @var array<string, array>
   */
  private static array $feature_registry = [];

  /**
   * Register a feature/controller with the global registry.
   *
   * @param string $feature_key
   * @param array  $feature_data
   * @return void
   */
  public static function register_feature(string $feature_key, array $feature_data): void
  {
    self::$feature_registry[$feature_key] = $feature_data;
  }

  /**
   * Get all registered features.
   *
   * @return array<string, array>
   */
  public static function get_registered_features(): array
  {
    return self::$feature_registry;
  }

  public function __construct()
  {

    $this->INC_DIR = get_stylesheet_directory() . '/inc/';
    $this->ASSET_DIR = get_stylesheet_directory_uri() . '/assets/';
  }


  public function init()
  {

    $this->auto_register_features();

    add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

    // Load text domains
    add_action('after_setup_theme', [$this, 'load_textdomains']);

    $this->load_dependencies();

    // Centralized hook consolidation
    $this->register_consolidated_hooks();

    // Register custom elements
    add_action('init', [$this, 'register_custom_elements'], 11);

    // Add text strings to builder
    add_filter('bricks/builder/i18n', [$this, 'add_builder_text_strings']);

    // Exclude private post types from sitemaps
    $this->exclude_private_post_types_from_sitemaps();

    // Initialize global cache management
    $this->init_cache_management();
  }

  /**
   * Initialize global cache management
   */
  private function init_cache_management(): void
  {
    // Clear all theme caches on plugin/theme updates
    add_action('upgrader_process_complete', [$this, 'clear_all_theme_caches']);
    add_action('switch_theme', [$this, 'clear_all_theme_caches']);
    
    // Clear caches on WordPress core updates
    add_action('_core_updated_successfully', [$this, 'clear_all_theme_caches']);
  }

  /**
   * Clear all theme-related caches
   */
  public function clear_all_theme_caches(): void
  {
    global $wpdb;
    
    // Clear all transients that start with our theme prefix
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        '_transient_sfx_%'
      )
    );
    
    // Clear all transients that start with our theme prefix (expired)
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        '_transient_timeout_sfx_%'
      )
    );
  }

  /**
   * Register consolidated hooks with proper initialization order
   */
  private function register_consolidated_hooks(): void
  {
    // Phase 1: Core initialization (priority 5)
    add_action('init', [$this, 'init_core_features'], 5);
    
    // Phase 2: Post types and taxonomies (priority 10)
    add_action('init', [$this, 'init_post_types'], 10);
    
    // Phase 3: Settings and options (priority 15)
    add_action('init', [$this, 'init_settings'], 15);
    
    // Phase 4: Admin-specific features (priority 20)
    add_action('admin_init', [$this, 'init_admin_features'], 20);
    
    // Phase 5: Advanced features (priority 25)
    add_action('init', [$this, 'init_advanced_features'], 25);
  }

  /**
   * Initialize core features
   */
  public function init_core_features(): void
  {
    do_action('sfx_init_core_features');
  }

  /**
   * Initialize post types and taxonomies
   */
  public function init_post_types(): void
  {
    do_action('sfx_init_post_types');
  }

  /**
   * Initialize settings and options
   */
  public function init_settings(): void
  {
    do_action('sfx_init_settings');
  }

  /**
   * Initialize admin-specific features
   */
  public function init_admin_features(): void
  {
    do_action('sfx_init_admin_features');
  }

  /**
   * Initialize advanced features
   */
  public function init_advanced_features(): void
  {
    do_action('sfx_init_advanced_features');
  }


  public function load_textdomains()
  {
    load_child_theme_textdomain('sfxtheme', get_stylesheet_directory() . '/languages');
    load_theme_textdomain('parent-theme', get_template_directory() . '/languages');
  }


  private function load_dependencies()
  {

    // Initialize the main admin menu page
    new \SFX\SFXBricksChildAdmin();

    $features = self::get_registered_features();

    foreach ($features as $feature) {
      if (!class_exists($feature['class'])) {
        if (!empty($feature['error'])) {
          error_log($feature['error']);
        }
        continue;
      }

      if (!empty($feature['activation_option_key'])) {
        $option_enabled = $this->is_option_enabled($feature['activation_option_name'], $feature['activation_option_key']);
        if (!$option_enabled) {
          continue;
        }
      }

      $callback = $feature['callback'] ?? null;
      $loader = function () use ($feature, $callback) {
        if ($callback && is_callable($callback)) {
          $callback($feature['class']);
        } else {
          new $feature['class']();
        }
      };

      if (!empty($feature['hook'])) {
        add_action($feature['hook'], $loader);
      } else {
        $loader();
      }
    }
  }


  public function enqueue_scripts()
  {
    if (!bricks_is_builder_main()) {
      wp_enqueue_style('bricks-child', get_stylesheet_uri(), ['bricks-frontend'], filemtime(get_stylesheet_directory() . '/style.css'));
      wp_enqueue_style('sfx-frontend', $this->ASSET_DIR . 'css/frontend/styles.css', ['bricks-child'], filemtime(get_stylesheet_directory() . '/assets/css/frontend/styles.css'));
    } else {
      // Load builder-specific styles
      wp_enqueue_style('sfx-builder-styles', $this->ASSET_DIR . 'css/builder/styles.css', ['bricks-builder'], filemtime(get_stylesheet_directory() . '/assets/css/builder/styles.css'));
    }
  }


  public function enqueue_admin_scripts($hook_suffix)
  {
    // Only load on Global Theme Settings pages and subpages, and custom post type pages
    if (strpos($hook_suffix, 'global-theme-settings') === false && 
        strpos($hook_suffix, 'sfx-theme-settings') === false && 
        strpos($hook_suffix, 'sfx-wp-optimizer') === false &&
        strpos($hook_suffix, 'sfx_custom_script') === false &&
        strpos($hook_suffix, 'sfx_social_account') === false &&
        strpos($hook_suffix, 'sfx_contact_info') === false &&
        strpos($hook_suffix, 'security-header') === false) {
        return;
    }
    wp_enqueue_style(
      'sfx-bricks-child-admin-styles',
      $this->ASSET_DIR . 'css/backend/styles.css',
      array(),
      filemtime(get_stylesheet_directory() . '/assets/css/backend/styles.css')
    );
    // Enqueue JS to add sfx-toggle class to all checkboxes in admin
    wp_add_inline_script(
      'jquery-core',
      "jQuery(function($){ $('input[type=checkbox]').addClass('sfx-toggle'); });"
    );
  }


  public function register_custom_elements()
  {
    $elementFiles = [
      get_stylesheet_directory() . '/elements/title.php',
    ];

    foreach ($elementFiles as $file) {
      \Bricks\Elements::register_element($file);
    }
  }


  public function add_builder_text_strings($i18n)
  {
    $i18n['custom'] = esc_html__('Custom', 'bricks');
    return $i18n;
  }



  private function is_option_enabled(string $option_name, string $option_key): bool
  {
    $options = get_option($option_name, []);
    return !empty($options[$option_key]);
  }

  /**
   * Automatically discover and register all feature controllers in inc
   */
  private function auto_register_features(): void
  {
    $controller_files = glob($this->INC_DIR . '*/Controller.php');
    foreach ($controller_files as $file) {
        $relative_path = str_replace($this->INC_DIR, '', $file);
        $parts = explode('/', $relative_path);
        if (count($parts) !== 2) {
            continue;
        }
        $namespace = 'SFX\\' . $parts[0];
        $class = $namespace . '\\Controller';

        if (class_exists($class, true) && method_exists($class, 'get_feature_config')) {
            $config = $class::get_feature_config();
            $feature_key = strtolower($parts[0]);
            self::register_feature($feature_key, $config);
        }
    }
  }

  /**
   * Exclude private post types from sitemaps.
   */
  private function exclude_private_post_types_from_sitemaps(): void
  {
    // Exclude our private post types from sitemaps
    add_filter('wp_sitemaps_post_types', function($post_types) {
      $private_post_types = ['sfx_custom_script', 'sfx_contact_info', 'sfx_social_account'];
      
      foreach ($private_post_types as $post_type) {
        if (isset($post_types[$post_type])) {
          unset($post_types[$post_type]);
        }
      }
      
      return $post_types;
    });

    // Also exclude from Yoast SEO sitemaps if active
    add_filter('wpseo_sitemap_exclude_post_type', function($excluded, $post_type) {
      $private_post_types = ['sfx_custom_script', 'sfx_contact_info', 'sfx_social_account'];
      return in_array($post_type, $private_post_types) ? true : $excluded;
    }, 10, 2);

    // Exclude from other popular sitemap plugins
    add_filter('rank_math/sitemap/excluded_post_types', function($excluded) {
      return array_merge($excluded, ['sfx_custom_script', 'sfx_contact_info', 'sfx_social_account']);
    });

    // Prevent search engines from indexing these post types
    add_action('wp_head', function() {
      if (is_singular(['sfx_custom_script', 'sfx_contact_info', 'sfx_social_account'])) {
        echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
      }
    });
  }

}
