<?php

namespace SFX;

class SFXBricksChildAdmin
{

  public static $menu_slug = 'sfx-theme-settings';

  public function __construct()
  {
    add_action('admin_menu', [self::class, 'register_admin_menu']);
  }

  public static function register_admin_menu()
  {
    if (!AccessControl::can_access_theme_settings() && !current_user_can('edit_posts')) {
      return;
    }

    add_menu_page(
      __('Global Theme Settings', 'sfxtheme'),
      __('Global Theme Settings', 'sfxtheme'),
      'edit_posts',
      self::$menu_slug,
      [self::class, 'render_theme_settings_page'],
      'dashicons-admin-generic',
      99
    );
  }

  /**
   * Slugs accessible to users with edit_posts but without full theme-settings access.
   */
  private static array $editor_visible_slugs = [
      'sfx-contact-infos',
  ];

  public static function render_theme_settings_page()
  {
    if (!AccessControl::can_access_theme_settings() && !current_user_can('edit_posts')) {
      wp_die(
        esc_html__('You do not have sufficient permissions to access this page.', 'sfxtheme'),
        esc_html__('Access Denied', 'sfxtheme'),
        ['response' => 403, 'back_link' => true]
      );
    }

    $has_full_access = AccessControl::can_access_theme_settings();

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Global Theme Settings', 'sfxtheme') . '</h1>';
    echo '<p>' . esc_html__('Welcome to the SFX Theme Options! Here you can configure some basic global settings and features for your website. Use the quick links below to jump directly to each section. Each area is designed to help you optimize, brand, and manage your site easily.', 'sfxtheme') . '</p>';

    $features = \SFX\SFXBricksChildTheme::get_registered_features();
    echo '<div style="display: flex; flex-wrap: wrap; gap: 24px; margin-top: 32px;">';
    foreach ($features as $feature) {
      if (empty($feature['menu_slug']) || empty($feature['page_title'])) {
        continue;
      }

      if (isset($feature['show_in_theme_settings']) && $feature['show_in_theme_settings'] === false) {
        continue;
      }

      if ($feature['menu_slug'] === 'sfx-custom-dashboard' && !AccessControl::can_access_dashboard_settings()) {
        continue;
      }

      if (!$has_full_access && !in_array($feature['menu_slug'], self::$editor_visible_slugs, true)) {
        continue;
      }

      $url = !empty($feature['url']) ? $feature['url'] : admin_url('admin.php?page=' . $feature['menu_slug']);

      echo '<div class="sfx-feature-card" style="flex: 1 1 33%; flex-wrap: wrap; min-width: 200px; max-width: 350px; background: #fff; border: 1px solid #e5e5e5; border-radius: 8px; padding: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">';
      echo '<h2 style="margin-top:0; font-size: 1.2em;">' . esc_html($feature['page_title']) . '</h2>';
      if (!empty($feature['description'])) {
        echo '<p style="font-size: 0.97em; color: #555;">' . esc_html($feature['description']) . '</p>';
      }
      echo '<a href="' . esc_url($url) . '" class="button button-primary">' . esc_html__('Go to', 'sfxtheme') . ' ' . esc_html($feature['page_title']) . '</a>';
      echo '</div>';
    }
    echo '</div>';

    echo '</div>';
  }
}
