<?php

namespace SFX\Options;

class AdminOptionPages
{

  public static $menu_slug = 'sfx-theme-settings';

  public function __construct()
  {
    add_action('admin_menu', [self::class, 'register_admin_menu']);
  }

  public static function register_admin_menu()
  {
    add_menu_page(
      __('Global Theme Settings', 'sfxtheme'),
      __('Global Theme Settings', 'sfxtheme'),
      'manage_options',
      self::$menu_slug,
      [self::class, 'render_theme_settings_page'],
      'dashicons-admin-generic',
      99
    );
  }

  public static function render_theme_settings_page()
  {
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Global Theme Settings', 'sfxtheme') . '</h1>';
    
    // General introduction
    echo '<p>' . esc_html__('Welcome to the SFX Theme Options! Here you can configure some basic global settings and features for your website. Use the quick links below to jump directly to each section. Each area is designed to help you optimize, brand, and manage your site easily.', 'sfxtheme') . '</p>';

    // Submenu summaries and buttons
    $submenus = [
      [
        'title' => __('General Options', 'sfxtheme'),
        'desc'  => __('Enable or disable core scripts and styles (like jQuery, Bricks JS, Bricks styling) for performance and customization.', 'sfxtheme'),
        'slug'  => 'acf-options-general-options',
      ],
      [
        'title' => __('Logo', 'sfxtheme'),
        'desc'  => __("Upload and manage your site's logo in different variants (regular, inverted, small, etc.).", 'sfxtheme'),
        'slug'  => 'sfx-logo-settings',
      ],
      [
        'title' => __('Contact', 'sfxtheme'),
        'desc'  => __('Manage company contact details and legal information.', 'sfxtheme'),
        'slug'  => 'acf-options-contact',
      ],
      [
        'title' => __('Social Media', 'sfxtheme'),
        'desc'  => __('Add and manage links to your social media profiles, including custom icons.', 'sfxtheme'),
        'slug'  => 'acf-options-social-media-profile',
      ],
      [
        'title' => __('Header Settings', 'sfxtheme'),
        'desc'  => __('Add custom HTML to the <head> of your site, e.g., for meta tags, analytics, or ASCII art.', 'sfxtheme'),
        'slug'  => 'acf-options-header-settings',
      ],
      [
        'title' => __('Footer Settings', 'sfxtheme'),
        'desc'  => __("Add custom HTML, scripts, or styles to the site's footer.", 'sfxtheme'),
        'slug'  => 'acf-options-footer-settings',
      ],
      [
        'title' => __('Custom Scripts', 'sfxtheme'),
        'desc'  => __("Add your own JavaScript or CSS files, choose where and how they're loaded (header/footer, enqueue/register, CDN or upload).", 'sfxtheme'),
        'slug'  => 'acf-options-custom-scripts',
      ],
      [
        'title' => __('Preset Scripts', 'sfxtheme'),
        'desc'  => __('Enable/disable popular libraries (Iconify, Alpine.js, GSAP, Locomotive Scroll, AOS) and configure their options.', 'sfxtheme'),
        'slug'  => 'acf-options-preset-scripts',
      ],
      [
        'title' => __('WP Enhancements', 'sfxtheme'),
        'desc'  => __('Toggle a wide range of WordPress optimizations (disable search, comments, REST API, feeds, version numbers, etc.) for performance and security.', 'sfxtheme'),
        'slug'  => 'acf-options-wp-enhancements',
      ],
    ];

    echo '<div style="display: flex; flex-wrap: wrap; gap: 24px; margin-top: 32px;">';
    foreach ($submenus as $submenu) {
      $url = admin_url('admin.php?page=' . $submenu['slug']);
      echo '<div style="flex: 1 1 33%; flex-wrap: wrap; min-width: 200px; max-width: 350px; background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; padding: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">';
      echo '<h2 style="margin-top:0; font-size: 1.2em;">' . esc_html($submenu['title']) . '</h2>';
      echo '<p style="font-size: 0.97em; color: #555;">' . esc_html($submenu['desc']) . '</p>';
      echo '<a href="' . esc_url($url) . '" class="button button-primary" style="margin-top: 10px;">' . esc_html__('Go to', 'sfxtheme') . ' ' . esc_html($submenu['title']) . '</a>';
      echo '</div>';
    }
    echo '</div>';

    echo '</div>';
  }
}
