<?php 
// Load child theme translations
function sfx_theme_load_textdomain() {
  load_child_theme_textdomain( 'sfx', get_stylesheet_directory() . '/languages' );
}
add_action( 'after_setup_theme', 'sfx_theme_load_textdomain' );

// Example of using the child theme's text domain
function sfx_theme_custom_function() {
  echo esc_html__( 'This text is translatable', 'sfx' );
}

// Optionally, you can also load the parent theme's text domain if needed
function sfx_theme_load_parent_textdomain() {
  load_theme_textdomain( 'parent-theme', get_template_directory() . '/languages' );
}
add_action( 'after_setup_theme', 'sfx_theme_load_parent_textdomain' );


require_once get_stylesheet_directory() . '/inc/settings-page.php';
require_once get_stylesheet_directory() . '/inc/enqueue-scripts.php';

require_once get_stylesheet_directory() . '/inc/acf-options-contact.php';
require_once get_stylesheet_directory() . '/inc/acf-options-social-media.php';

require_once get_stylesheet_directory() . '/inc/acf-options-general.php';
require_once get_stylesheet_directory() . '/inc/acf-options-logo.php';
require_once get_stylesheet_directory() . '/inc/acf-options-scripts-custom.php';
require_once get_stylesheet_directory() . '/inc/acf-options-scripts-preset.php';


/**
 * Register custom elements
 * https://academy.bricksbuilder.io/article/create-your-own-elements/
 */
add_action( 'init', function() {
  $element_files = [
    __DIR__ . '/elements/title.php',
  ];

  foreach ( $element_files as $file ) {
    \Bricks\Elements::register_element( $file );
  }
}, 11 );


/**
 * Add text strings to builder
 */
add_filter( 'bricks/builder/i18n', function( $i18n ) {
  // For element category 'custom'
  $i18n['custom'] = esc_html__( 'Custom', 'bricks' );

  return $i18n;
} );

