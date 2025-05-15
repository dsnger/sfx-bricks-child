<?php

namespace SFX;

class SFXBricksChildTheme
{

  private $INC_DIR;
  private $ASSET_DIR;


  public function __construct()
  {

    $this->INC_DIR = get_stylesheet_directory() . '/inc/';
    $this->ASSET_DIR = get_stylesheet_directory_uri() . '/assets/';
  }


  public function init()
  {

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

      // Example for a dependency with a custom callback:
      // [
      //   'class' => '\Vendor\SpecialClass',
      //   'error' => 'Missing SpecialClass',
      //   'option_key' => 'special_option',
      //   'hook'  => 'init',
      //   'callback' => function ($class) { $instance = new $class('foo'); $instance->init(); },
      // ],

    $dependencies = [

      [
        'class' => '\SFX\Options\AdminOptionsController',
        'error' => 'Missing AdminOptionsController class in theme',
        'hook'  => null, // load immediately
        // 'callback' => function ($class) { new $class('arg1', 'arg2'); }, // Example custom callback
      ],
      [
        'class' => '\SFX\GeneralThemeOptions\Controller',
        'error' => 'Missing GeneralThemeOptionsController class in theme',
        'hook'  => null, // load immediately
      ],
      [
        'class' => '\SFX\Shortcodes\Controller',
        'error' => 'Missing ShortcodeController class in theme',
        'hook'  => 'acf/init', // load after ACF is ready
      ],
      [
        'class' => '\SFX\ImageOptimizer\Controller',
        'error' => 'Missing ImageOptimizerController class in theme',
        'option_name' => 'sfx_general_options',
        'option_key' => 'enable_image_optimizer',
        'hook'  => null, // load immediately
      ],
      [
        'class' => '\SFX\SecurityHeader\Controller',
        'error' => 'Missing SecurityHeaderController class in theme',
        'option_name' => 'sfx_general_options',
        'option_key' => 'enable_security_header',
        'hook'  => null, // load immediately
      ],
      [
        'class' => '\SFX\WPOptimizer\Controller',
        'error' => 'Missing WPOptimizerController class in theme',
        'hook'  => null, // load immediately
      ],

    ];

    foreach ($dependencies as $dep) {
      if (!class_exists($dep['class'])) {
        if (!empty($dep['error'])) {
          error_log($dep['error']);
        }
        continue;
      }

      if (!empty($dep['option_key'])) {
        if (!$this->is_option_enabled($dep['option_name'], $dep['option_key'])) {
          continue;
        }
      }

      $callback = $dep['callback'] ?? null;
      $loader = function () use ($dep, $callback) {
        if ($callback && is_callable($callback)) {
          $callback($dep['class']);
        } else {
          new $dep['class']();
        }
      };

      if (!empty($dep['hook'])) {
        add_action($dep['hook'], $loader);
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
    if (strpos($hook_suffix, 'global-theme-settings') === false && strpos($hook_suffix, 'sfx-theme-settings') === false && strpos($hook_suffix, 'wp-optimizer-options') === false) {
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

}
