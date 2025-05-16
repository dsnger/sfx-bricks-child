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

    // Register custom elements
    add_action('init', [$this, 'register_custom_elements'], 11);

    // Add text strings to builder
    add_filter('bricks/builder/i18n', [$this, 'add_builder_text_strings']);
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
      wp_enqueue_style('sfx-frontend', $this->ASSET_DIR . 'css/frontend.css', ['bricks-child'], filemtime(get_stylesheet_directory() . '/assets/css/frontend.css'));
    } else {
      // Load builder-specific styles
      wp_enqueue_style('sfx-builder-styles', $this->ASSET_DIR . 'css/builder/styles.css', ['bricks-builder'], filemtime(get_stylesheet_directory() . '/assets/css/builder/styles.css'));
    }
  }


  public function enqueue_admin_scripts($hook_suffix)
  {
    // Only load on Global Theme Settings pages and subpages
    if (strpos($hook_suffix, 'global-theme-settings') === false && strpos($hook_suffix, 'sfx-theme-settings') === false && strpos($hook_suffix, 'sfx-wp-optimizer') === false) {
        return;
    }
    wp_enqueue_style(
      'sfx-bricks-child-admin-styles',
      $this->ASSET_DIR . 'css/backend.css',
      array(),
      filemtime(get_stylesheet_directory() . '/assets/css/backend.css')
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

}
