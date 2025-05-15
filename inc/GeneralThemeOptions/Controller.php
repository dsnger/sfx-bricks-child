<?php

declare(strict_types=1);

namespace SFX\GeneralThemeOptions;


class Controller
{

  public const OPTION_NAME = 'sfx_general_options';

  public function __construct()
  {

    Settings::register(self::OPTION_NAME);
    AdminPage::register();

    // Initialize the theme only after ACF is confirmed to be active
    add_action('init', [$this,'handle_options']);
    add_action('update_option_' . self::OPTION_NAME, [$this, 'handle_options'], 10, 2);
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
      // Use ACF option via class method
      $disable_bricks_js = $this->get_acf_option('disable_bricks_js', false);
      if ($disable_bricks_js) {
        wp_dequeue_script('bricks-scripts');
        wp_deregister_script('bricks-scripts');
      }
    }, 100);
  }


  private function disable_bricks_css(): void
  {
    add_action('wp_enqueue_scripts', function () {
      // Use ACF option via class method
      $disable_bricks_css = $this->get_acf_option('disable_bricks_css', false);
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


  private function is_option_enabled(string $option_key): bool
  {
    $options = get_option(self::OPTION_NAME, []);
    return !empty($options[$option_key]);
  }

}
