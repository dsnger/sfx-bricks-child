<?php

namespace SFX\CustomScriptsManager;

class AdminPage
{

  public static $menu_slug = 'sfx-custom-scripts-manager';
  public static $page_title = 'Custom Scripts Manager';
  public static $description = 'Manage custom scripts and styles for your website.';


  public static function register()
  {
    add_action('admin_menu', [self::class, 'add_submenu_page']);
  }

  public static function add_submenu_page(): void
  {
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
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(self::$page_title); ?></h1>
        <p><?php echo esc_html(self::$description); ?></p>
        <form method="post" action="options.php">
            <?php
            settings_fields(\SFX\CustomScriptsManager\Settings::$OPTION_GROUP);
            do_settings_sections(\SFX\CustomScriptsManager\Settings::$OPTION_GROUP);
            submit_button();
            ?>
        </form>
    </div>
    <?php
}
}
