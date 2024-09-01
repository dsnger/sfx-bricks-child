<?php

namespace SFX\Options;

class ACFOptionsHeader
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
        'page_title'    => __('Header Settings', 'sfx'),
        'menu_title'    => __('Header Settings', 'sfx'),
        'parent_slug'   => AdminOptionPages::$menu_slug,
      ));
    }
  }


  public function register_fields()
  {
    // Your ACF field registration code here
    if (function_exists('acf_add_local_field_group')) {
      acf_add_local_field_group(array(
        'key' => 'group_66d4407c674a4',
        'title' => __('Custom HTML for WP Head', 'sfx'),
        'fields' => array(
          array(
            'key' => 'field_66d4408620f39',
            'label' => __('Custom HTML for WP Head (Frontend Only)', 'sfx'),
            'name' => 'custom_header_html',
            'type' => 'acfe_code_editor',
            'instructions' => __('Enter custom HTML for the front-end. This HTML will be applied site-wide and will appear in the head section. <br><a href="https://patorjk.com/software/taag/#p=display&f=ANSI%20Regular&t=Hello%20World" target="_blank">Text to ASCI ART</a>. If you want to add this ASCII art, don\'t forget to comment it like this: <code>&lt;!-- ASCII art --&gt;</code>.', 'sfx'),
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
              'value' => 'acf-options-header-settings',
            ),
          ),
        ),
        'menu_order' => 0,
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
