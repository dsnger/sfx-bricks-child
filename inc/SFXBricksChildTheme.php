<?php

namespace SFX;

class SFXBricksChildTheme
{

  private $INC_DIR;
  private $ASSET_DIR;


  public function __construct()
  {

    $this->INC_DIR = get_stylesheet_directory() . '/inc/';
    $this->ASSET_DIR = get_stylesheet_directory() . '/assets/';
  }


  public function init()
  {

    add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

    // Load text domains
    add_action('after_setup_theme', [$this, 'load_textdomains']);

    $this->load_setting_pages();

    // Register custom elements
    add_action('init', [$this, 'register_custom_elements'], 11);

    // Add text strings to builder
    add_filter('bricks/builder/i18n', [$this, 'add_builder_text_strings']);
  }


  public function load_textdomains()
  {
    load_child_theme_textdomain('sfx', get_stylesheet_directory() . '/languages');
    load_theme_textdomain('parent-theme', get_template_directory() . '/languages');
  }


  private function load_setting_pages()
  {
    new \SFX\Options\AdminOptionPages();
    new \SFX\Options\ACFOptionsContact();
    new \SFX\Options\ACFOptionsSocialMedia();
    new \SFX\Options\ACFOptionsGeneral();
    new \SFX\Options\ACFOptionsLogo();
    new \SFX\Options\ACFOptionsCustomScripts();
    new \SFX\Options\ACFOptionsPresetScripts();
    new \SFX\Options\ACFOptionsHeader();
    new \SFX\Options\ACFOptionsFooter();
  }


  public function enqueue_scripts()
  {
    if (!bricks_is_builder_main()) {
      wp_enqueue_style('bricks-child', get_stylesheet_uri(), ['bricks-frontend'], filemtime(get_stylesheet_directory() . '/style.css'));
    }
  }


  public function enqueue_admin_scripts()
  {
    wp_enqueue_style(
      'child-theme-admin-styles',
      $this->ASSET_DIR . '/css/admin-styles.css',
      array(),
      filemtime($this->ASSET_DIR . '/css/admin-styles.css')
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
