<?php

declare(strict_types=1);

namespace SFX\SecurityHeader;

class AdminPage
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_submenu_page']);
    }

    public static function add_submenu_page(): void
    {
        add_submenu_page(
            'sfx-theme-settings',
            __('Security Header', 'sfxtheme'),
            __('Security Header', 'sfxtheme'),
            'manage_options',
            'security-header',
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        
    }
}

