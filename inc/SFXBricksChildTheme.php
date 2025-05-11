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
    $dependencies = [
      [
        'class' => '\SFX\Options\AdminOptionsController',
        'error' => 'Missing AdminOptionsController class in theme',
      ],
      [
        'class' => '\SFX\WPOptimizer\WPOptimizerController',
        'error' => 'Missing WPOptimizerController class in theme',
      ],
    ];
    foreach ($dependencies as $dep) {
      if (class_exists($dep['class'])) {
        new $dep['class']();
      } elseif (!empty($dep['error'])) {
        error_log($dep['error']);
      }
    }
    // PixRefinerController: call static init() if class exists
    if (class_exists('SFX\\PixRefiner\\PixRefinerController')) {
      \SFX\PixRefiner\PixRefinerController::init();
    } else {
      error_log('Missing PixRefinerController class in theme');
    }
    // Delay ShortcodeController until 'init' to ensure ACF is loaded
    if (class_exists('\SFX\Shortcodes\ShortcodeController')) {
      add_action('init', function () {
        new \SFX\Shortcodes\ShortcodeController();
      });
    } else {
      error_log('Missing ShortcodeController class in theme');
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


  public function enqueue_admin_scripts()
  {
    wp_enqueue_style(
      'sfx-bricks-child-admin-styles',
      $this->ASSET_DIR . 'css/backend.css',
      array(),
      filemtime(get_stylesheet_directory() . '/assets/css/backend.css')
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
}
