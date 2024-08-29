<?php
// Make sure ACF is active
if (function_exists('acf_add_options_page')) {

  $option_contact = acf_add_options_page(array(
    'page_title'  => __('Contact details', 'sfx'),
    'menu_title'  => __('Contact details', 'sfx'),
    'menu_slug'   => 'sfx-contact-settings',
    'capability'  => 'edit_posts',
    'autoload'    => true,
    'icon_url' => 'dashicons-building',
  ));

  acf_add_options_sub_page(array(
    'page_title'   => __('Contact', 'sfx'),
    'menu_title'   => __('Contact', 'sfx'),
    'parent_slug'  => $option_contact['menu_slug'],
    'autoload'     => false
  ));

  acf_add_options_sub_page(array(
    'page_title'   => __('Social Media', 'sfx'),
    'menu_title'   => __('Social Media Profile', 'sfx'),
    'parent_slug'  => $option_contact['menu_slug'],
    'autoload'     => false
  ));

  // ACF options pages setup (unchanged)
  acf_add_options_page(array(
    'page_title'    => __('Global Settings', 'sfx'),
    'menu_title'    => __('Global Settings', 'sfx'),
    'menu_slug'     => 'sfx-theme-settings',
    'capability'    => 'manage_options',
    'redirect'      => false,
    'position'      => '99'
  ));

  acf_add_options_sub_page(array(
    'page_title'    => __('Logo', 'sfx'),
    'menu_title'    => __('Logo', 'sfx'),
    'menu_slug'     => 'sfx-logo-settings',
    'parent_slug'   => 'sfx-theme-settings',
  ));

  acf_add_options_sub_page(array(
    'page_title'    => __('Preset Scripts', 'sfx'),
    'menu_title'    => __('Preset Scripts', 'sfx'),
    'parent_slug'   => 'sfx-theme-settings',
    'post_content'  => __('
      <p>Maybe it´s is recommanded to disable default Bricks JS.</p>
      ', 'sfx'),
  ));

  acf_add_options_sub_page(array(
    'page_title'    => __('Custom Scripts', 'sfx'),
    'menu_title'    => __('Custom Scripts', 'sfx'),
    'parent_slug'   => 'sfx-theme-settings',
  ));

  acf_add_options_sub_page(array(
    'page_title'    => __('Header Einstellungen','sfx'),
    'menu_title'    => __('Header Einstellungen','sfx'),
    'parent_slug'   => 'sfx-theme-settings',
  ));

  acf_add_options_sub_page(array(
    'page_title'    => __('Footer Einstellungen','sfx'),
    'menu_title'    => __('Footer Einstellungen','sfx'),
    'parent_slug'   => 'sfx-theme-settings',
  ));
}
