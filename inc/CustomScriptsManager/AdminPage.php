<?php

namespace SFX\CustomScriptsManager;

class AdminPage
{

  public static $menu_slug = 'sfx-custom-scripts-manager';
  public static $page_title = 'Custom Scripts Manager';
  public static $description = 'Manage custom scripts and styles for your website.';


  public static function register()
  {
    add_action('admin_menu', [self::class, 'add_submenu_pages']);
  }

  public static function add_submenu_pages(): void
  {
    // Add main Custom Scripts page
    add_submenu_page(
      \SFX\SFXBricksChildAdmin::$menu_slug,
      __(self::$page_title, 'sfx-bricks-child'),
      __(self::$page_title, 'sfx-bricks-child'),
      'manage_options',
      'edit.php?post_type=' . PostType::$post_type
    );

  }

  public static function render_page()
  {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(self::$page_title); ?></h1>
        <p><?php echo esc_html(self::$description); ?></p>
        <p><?php esc_html_e('Use the Custom Scripts post type to manage your scripts and styles.', 'sfx-bricks-child'); ?></p>
        <a href="<?php echo esc_url(admin_url('edit.php?post_type=' . PostType::$post_type)); ?>" class="button button-primary">
            <?php esc_html_e('Manage Custom Scripts', 'sfx-bricks-child'); ?>
        </a>
    </div>
    <?php
  }
}
