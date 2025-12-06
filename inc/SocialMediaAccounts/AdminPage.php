<?php

declare(strict_types=1);

namespace SFX\SocialMediaAccounts;

class AdminPage
{
    public static string $menu_slug = 'sfx-social-media-accounts';
    public static string $page_title = 'Social Media Accounts';
    public static string $description = 'Manage social media accounts and profiles';

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

        // Add main Social Media Accounts page
        add_submenu_page(
            \SFX\SFXBricksChildAdmin::$menu_slug,
            __(self::$page_title, 'sfxtheme'),
            __(self::$page_title, 'sfxtheme'),
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
            <p><?php esc_html_e('Use the Social Media Accounts post type to manage your social media profiles.', 'sfxtheme'); ?></p>
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=' . PostType::$post_type)); ?>" class="button button-primary">
                <?php esc_html_e('Manage Social Media Accounts', 'sfxtheme'); ?>
            </a>
        </div>
        <?php
    }
} 