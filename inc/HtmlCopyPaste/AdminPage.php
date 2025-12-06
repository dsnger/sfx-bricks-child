<?php

declare(strict_types=1);

namespace SFX\HtmlCopyPaste;

class AdminPage
{
    public static string $menu_slug = 'sfx-html-copy-paste';
    public static string $page_title = 'HTML Copy/Paste';
    public static string $description = '';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_admin_page']);
    }

    public static function add_admin_page(): void
    {
        // Only register menu if user has theme settings access
        if (!\SFX\AccessControl::can_access_theme_settings()) {
            return;
        }

        add_submenu_page(
            'sfx-theme-settings',
            self::$page_title,
            'HTML Copy/Paste',
            'manage_options',
            self::$menu_slug,
            [self::class, 'render_admin_page']
        );
    }

    public static function render_admin_page(): void
    {
        // Block direct URL access for unauthorized users
        \SFX\AccessControl::die_if_unauthorized_theme();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(self::$page_title); ?></h1>
            <p><?php echo esc_html(self::$description); ?></p>
            
            <div class="sfx-admin-card">
                <h2><?php esc_html_e('HTML Copy/Paste Settings', 'sfxtheme'); ?></h2>
                
                <form method="post" action="options.php">
                    <?php
                    settings_fields(Controller::OPTION_NAME);
                    do_settings_sections(Controller::OPTION_NAME);
                    submit_button();
                    ?>
                </form>
            </div>

            <div class="sfx-admin-card">
                <h2><?php esc_html_e('How to Use', 'sfxtheme'); ?></h2>
                <div class="sfx-feature-description">
                    <h3><?php esc_html_e('Direct HTML Paste', 'sfxtheme'); ?></h3>
                    <p><?php esc_html_e('Copy HTML from any source and paste it directly into Bricks Builder. The HTML will be automatically converted to Bricks Builder elements.', 'sfxtheme'); ?></p>
                    
                    <h3><?php esc_html_e('HTML Editor', 'sfxtheme'); ?></h3>
                    <p><?php esc_html_e('Use the HTML editor to modify your HTML before converting it to Bricks Builder elements. This is useful for cleaning up or modifying HTML before import.', 'sfxtheme'); ?></p>
                    
                    <h3><?php esc_html_e('Supported Elements', 'sfxtheme'); ?></h3>
                    <ul>
                        <li><?php esc_html_e('Div containers', 'sfxtheme'); ?></li>
                        <li><?php esc_html_e('Text elements (headings, paragraphs)', 'sfxtheme'); ?></li>
                        <li><?php esc_html_e('Images with proper src attributes', 'sfxtheme'); ?></li>
                        <li><?php esc_html_e('Links with href attributes', 'sfxtheme'); ?></li>
                        <li><?php esc_html_e('SVG elements', 'sfxtheme'); ?></li>
                        <li><?php esc_html_e('Custom attributes preservation', 'sfxtheme'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
} 