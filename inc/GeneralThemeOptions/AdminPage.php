<?php

namespace SFX\GeneralThemeOptions;

class AdminPage
{

  public static $menu_slug = 'sfx-general-theme-options';
  public static $page_title = 'General Theme Options';
  public static $description = 'Enable or disable core scripts and styles (like jQuery, Bricks JS, Bricks styling) for performance and customization.';


  public static function register()
  {
    add_action('admin_menu', [self::class, 'add_submenu_page']);
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
            do_settings_sections(\SFX\GeneralThemeOptions\Settings::$OPTION_GROUP);
            submit_button();
            ?>
        </form>
    </div>
    <?php
}
}
