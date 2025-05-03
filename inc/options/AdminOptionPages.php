<?php

namespace SFX\Options;

class AdminOptionPages
{

  public static $menu_slug = 'sfx-theme-settings';

  public function __construct()
  {
    add_action('acf/init', [$this, 'add_acf_options_pages']);
  }

  public function add_acf_options_pages()
  {

    // Make sure ACF is active
    if (function_exists('acf_add_options_page')) {

      // ACF options parent page setup
      acf_add_options_page(array(
        'page_title'    => __('Global Theme Settings', 'sfxtheme'),
        'menu_title'    => __('Global Theme Settings', 'sfxtheme'),
        'menu_slug'     => self::$menu_slug,
        'capability'    => 'manage_options',
        'redirect'      => false,
        'position'      => '99'
      ));

    }
  }
}
