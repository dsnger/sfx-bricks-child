<?php

namespace SFX\Options\ACF;

class OptionsFooter
{
  public function __construct()
  {
    add_action('acf/init', [$this, 'add_acf_options_pages']);
    add_action('acf/init', [$this, 'register_fields']);
  }



  public function add_acf_options_pages()
  {

    // Make sure ACF is active
    if (function_exists('acf_add_options_page')) {

      acf_add_options_sub_page(array(
        'page_title'    => __('Footer Settings', 'sfxtheme'),
        'menu_title'    => __('Footer Settings', 'sfxtheme'),
        'parent_slug'   => \SFX\Options\AdminOptionPages::$menu_slug,
      ));
    }
  }


  public function register_fields()
  {
    // Your ACF field registration code here
    if (function_exists('acf_add_local_field_group')) {
      acf_add_local_field_group(array(
        'key' => 'group_66d43fe4a0ce8',
        'title' => __('Custom HTML for WP Footer', 'sfxtheme'),
        'fields' => array(
          array(
            'key' => 'field_66d44008bd432',
            'label' => __('Custom HTML for WP Footer (Frontend Only)', 'sfxtheme'),
            'name' => 'custom_footer_html_frontend',
            'type' => 'acfe_code_editor',
            'instructions' => __('Enter custom HTML (including scripts or styles) for the front-end. This HTML will be output directly in the footer section.', 'sfxtheme'),
            'required' => 0,
            'wrapper' => array(
              'width' => '',
              'class' => '',
              'id' => '',
            ),
            'default_value' => '',
            'placeholder' => '',
            'mode' => 'text/html',
            'lines' => 1,
            'indent_unit' => 4,
            'maxlength' => '',
            'rows' => 15,
            'max_rows' => '',
            'return_format' => array(
              0 => 'htmlentities',
            ),
          ),
        ),
        'location' => array(
          array(
            array(
              'param' => 'options_page',
              'operator' => '==',
              'value' => 'acf-options-footer-settings',
            ),
          ),
        ),
        'menu_order' => 1,
        'position' => 'normal',
        'style' => 'seamless',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
      ));
    }
  }
}
