<?php

declare(strict_types=1);

namespace SFX\ContactInfos;

class AdminPage
{
  public static string $menu_slug = 'sfx-contact-infos';
    public static string $page_title = 'Company Informations / Branches';
    public static string $description = 'Manage company informations and branches';
  
  public static function register(): void
  {
        add_action('admin_menu', [self::class, 'add_submenu_pages']);
    }

    public static function add_submenu_pages(): void
    {
        // Only register menu if user has theme settings access
        if (!\SFX\AccessControl::can_access_theme_settings()) {
            return;
        }

        // Add main Contact Information page
        add_submenu_page(
            \SFX\SFXBricksChildAdmin::$menu_slug,
            __(self::$page_title, 'sfx-bricks-child'),
            __(self::$page_title, 'sfx-bricks-child'),
            'manage_options',
            'edit.php?post_type=' . PostType::$post_type
        );
    }

    public static function render_page(): void
  {
    ?>
        <div class="wrap">
      <h1><?php echo esc_html(self::$page_title); ?></h1>
      <p><?php echo esc_html(self::$description); ?></p>
            <p><?php esc_html_e('Use the Contact Information post type to manage your company details and contact informations.', 'sfx-bricks-child'); ?></p>
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=' . PostType::$post_type)); ?>" class="button button-primary">
                <?php esc_html_e('Manage Contact Informations', 'sfx-bricks-child'); ?>
            </a>
        </div>
    <?php
  }
}