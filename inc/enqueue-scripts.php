<?php

/**
 * Register/enqueue custom scripts and styles
 */
add_action( 'wp_enqueue_scripts', function() {
	// Enqueue your files on the canvas & frontend, not the builder panel. Otherwise custom CSS might affect builder)
	if ( ! bricks_is_builder_main() ) {
		wp_enqueue_style( 'bricks-child', get_stylesheet_uri(), ['bricks-frontend'], filemtime( get_stylesheet_directory() . '/style.css' ) );
	}
} );


/**
 * Add admin styles from child theme using an anonymous function
 */
add_action('admin_enqueue_scripts', function() {
  wp_enqueue_style(
      'child-theme-admin-styles',
      get_stylesheet_directory_uri() . '/assets/css/admin-styles.css',
      array(),
      filemtime(get_stylesheet_directory() . '/assets/css/admin-styles.css')
  );
});