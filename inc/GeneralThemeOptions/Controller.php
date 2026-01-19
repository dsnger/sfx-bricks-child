<?php

declare(strict_types=1);

namespace SFX\GeneralThemeOptions;


class Controller
{

  public const OPTION_NAME = 'sfx_general_options';

  /**
   * Cache for style modules directory path.
   */
  private string $modules_dir;
  private string $modules_uri;

  public function __construct()
  {
    // Initialize paths
    $this->modules_dir = get_stylesheet_directory() . '/assets/css/frontend/modules/';
    $this->modules_uri = get_stylesheet_directory_uri() . '/assets/css/frontend/modules/';

    // Initialize components
    AdminPage::register();
    Settings::register();

    // Register hooks through consolidated system
    add_action('sfx_init_settings', [$this, 'handle_options']);

    // Enqueue optional style modules
    add_action('wp_enqueue_scripts', [$this, 'enqueue_optional_styles'], 20);
  }


  public function handle_options(): void
  {
    if ($this->is_option_enabled('disable_bricks_css')) {
      $this->disable_bricks_css();
    }

    if ($this->is_option_enabled('disable_bricks_js')) {
      $this->disable_bricks_js();
    }

    $this->handle_image_optimizer();
    $this->handle_security_header();

  }


  public function handle_image_optimizer(): void {

    if (!$this->is_option_enabled('enable_image_optimizer')) {
      \SFX\ImageOptimizer\Settings::delete_all_options();
    }

  }

  public function handle_security_header(): void {

    if (!$this->is_option_enabled('enable_security_header')) {
      \SFX\SecurityHeader\Settings::delete_all_options();
    }

  }

  public function handle_wp_optimizer(): void {

    if (!$this->is_option_enabled('enable_wp_optimizer')) {
      \SFX\WPOptimizer\Settings::delete_all_options();
    }

  }


  private function disable_bricks_js(): void
  {
    add_action('wp_enqueue_scripts', function () {
      // Check if in Bricks Builder context
      if (function_exists('bricks_is_builder') && bricks_is_builder()) {
        return;
      }
      // Use WordPress option
      $options = get_option(self::OPTION_NAME, []);
      $disable_bricks_js = !empty($options['disable_bricks_js']);
      if ($disable_bricks_js) {
        wp_dequeue_script('bricks-scripts');
        wp_deregister_script('bricks-scripts');
      }
    }, 100);
  }


  private function disable_bricks_css(): void
  {
    add_action('wp_enqueue_scripts', function () {
      // Use WordPress option
      $options = get_option(self::OPTION_NAME, []);
      $disable_bricks_css = !empty($options['disable_bricks_css']);
      if ($disable_bricks_css && !(function_exists('bricks_is_builder') && bricks_is_builder())) {
        $style_handles = [
          'bricks-frontend',
          // 'bricks-builder',
          'bricks-default-content',
          'bricks-element-posts',
          'bricks-isotope',
          'bricks-element-post-author',
          'bricks-element-post-comments',
          'bricks-element-post-navigation',
          'bricks-element-post-sharing',
          'bricks-element-post-taxonomy',
          'bricks-element-related-posts',
          'bricks-404',
          'wp-block-library',
          'classic-theme-styles',
          'global-styles',
          'bricks-admin',
        ];
        foreach ($style_handles as $handle) {
          wp_dequeue_style($handle);
          wp_deregister_style($handle);
        }
      }
    }, 100);
  }


  /**
   * Enqueue optional style modules based on settings.
   */
  public function enqueue_optional_styles(): void
  {
    // Skip in Bricks Builder
    if (function_exists('bricks_is_builder_main') && bricks_is_builder_main()) {
      return;
    }

    // Get options once (WordPress caches this)
    $options = get_option(self::OPTION_NAME, []);

    // Get style module definitions
    $style_fields = Settings::get_style_fields();

    foreach ($style_fields as $field) {
      $option_key = $field['id'];
      $file = $field['file'];
      $default = $field['default'] ?? 1;

      // Check if enabled (default is enabled)
      $is_enabled = isset($options[$option_key]) ? (bool) $options[$option_key] : (bool) $default;

      if ($is_enabled) {
        $file_path = $this->modules_dir . $file;
        
        // Only enqueue if file exists
        if (file_exists($file_path)) {
          $handle = 'sfx-style-' . str_replace('.css', '', $file);
          
          wp_enqueue_style(
            $handle,
            $this->modules_uri . $file,
            ['bricks-child'], // Depend on main child theme styles
            filemtime($file_path)
          );
        }
      }
    }
  }


  private function is_option_enabled(string $option_key): bool
  {
    $options = get_option(self::OPTION_NAME, []);
    return !empty($options[$option_key]);
  }

  
  public static function get_feature_config(): array
  {
    return [
      'class' => self::class,
      'menu_slug' => AdminPage::$menu_slug,
      'page_title' => AdminPage::$page_title,
      'description' => AdminPage::$description,
      'error' => 'Missing GeneralThemeOptionsController class in theme',
      'hook'  => null,
    ];
  }

  public static function maybe_set_default_options(): void {
    if (false === get_option(self::OPTION_NAME, false)) {
        $defaults = [];
        foreach (Settings::get_all_fields() as $field) {
            $defaults[$field['id']] = $field['default'];
        }
        add_option(self::OPTION_NAME, $defaults);
    }
  }

}
