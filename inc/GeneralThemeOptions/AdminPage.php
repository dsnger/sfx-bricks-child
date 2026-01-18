<?php

namespace SFX\GeneralThemeOptions;

class AdminPage
{

  public static $menu_slug = 'sfx-general-theme-options';
  public static $page_title = 'General Theme Options';
  public static $description = 'Enable or disable core scripts, styles, and optional CSS modules for performance and customization.';


  public static function register()
  {
    add_action('admin_menu', [self::class, 'add_submenu_page']);
    add_action('admin_head', [self::class, 'add_inline_styles']);
  }

  public static function add_submenu_page(): void
  {
    // Only register menu if user has theme settings access
    if (!\SFX\AccessControl::can_access_theme_settings()) {
      return;
    }

    add_submenu_page(
      \SFX\SFXBricksChildAdmin::$menu_slug,
      self::$page_title,
      self::$page_title,
      'manage_options',
      self::$menu_slug,
      [self::class, 'render_page'],
      1
    );
  }

  /**
   * Add inline styles for the settings page.
   */
  public static function add_inline_styles(): void
  {
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, self::$menu_slug) === false) {
      return;
    }
    ?>
    <style>
      .sfx-settings-section {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 4px;
        padding: 1px 20px 20px;
        margin-bottom: 20px;
      }
      .sfx-settings-section h2 {
        margin: 20px -20px 15px;
        padding: 12px 20px;
        background: #f6f7f7;
        border-bottom: 1px solid #c3c4c7;
        font-size: 14px;
        font-weight: 600;
      }
      .sfx-settings-section h2:first-child {
        margin-top: -1px;
        border-radius: 4px 4px 0 0;
      }
      .sfx-settings-section .form-table th {
        padding-left: 0;
        width: 200px;
      }
      .sfx-settings-section p.description {
        color: #646970;
        font-style: italic;
        margin: 0 0 15px;
      }
    </style>
    <?php
  }

  public static function render_page()
  {
    // Block direct URL access for unauthorized users
    \SFX\AccessControl::die_if_unauthorized_theme();
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('General Theme Options', 'sfxtheme'); ?></h1>
      <form method="post" action="options.php">
        <?php
        settings_fields(\SFX\GeneralThemeOptions\Settings::$OPTION_GROUP);
        
        // Render sections with custom wrapper
        self::render_sections();
        
        submit_button();
        ?>
      </form>
    </div>
    <?php
  }

  /**
   * Render settings sections with visual grouping.
   */
  private static function render_sections(): void
  {
    global $wp_settings_sections, $wp_settings_fields;
    
    $page = Settings::$OPTION_GROUP;
    
    if (!isset($wp_settings_sections[$page])) {
      return;
    }

    foreach ((array) $wp_settings_sections[$page] as $section) {
      echo '<div class="sfx-settings-section">';
      
      if ($section['title']) {
        echo '<h2>' . esc_html($section['title']) . '</h2>';
      }

      if ($section['callback']) {
        echo '<p class="description">';
        call_user_func($section['callback'], $section);
        echo '</p>';
      }

      if (!isset($wp_settings_fields) || !isset($wp_settings_fields[$page]) || !isset($wp_settings_fields[$page][$section['id']])) {
        echo '</div>';
        continue;
      }

      echo '<table class="form-table" role="presentation">';
      do_settings_fields($page, $section['id']);
      echo '</table>';
      echo '</div>';
    }
  }
}
