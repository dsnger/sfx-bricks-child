<?php

namespace SFX\GeneralThemeOptions;

class AdminPage
{

  public static function register()
  {
    add_action('admin_menu', [self::class, 'add_submenu_page']);
  }

  public static function add_submenu_page(): void
  {
    add_submenu_page(
      \SFX\Options\AdminOptionPages::$menu_slug,
      __('General Options', 'sfxtheme'),
      __('General Options', 'sfxtheme'),
      'manage_options',
      'general-theme-options',
      [self::class, 'render_page']
    );
  }

  public static function render_page()
{
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
