<?php

declare(strict_types=1);

namespace SFX\Admin;

final class AssetManager
{
    private const ALLOWED_POST_TYPES = ['sfx_contact_info', 'sfx_social_account'];

    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue'], 20);
    }

    public static function enqueue(string $hook_suffix): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (
            !$screen
            || $screen->base !== 'edit'
            || !in_array($screen->post_type, self::ALLOWED_POST_TYPES, true)
        ) {
            return;
        }

        $js = get_stylesheet_directory() . '/assets/js/admin-placeholder-copy.js';
        if (!file_exists($js)) {
            return;
        }

        wp_enqueue_script(
            'sfx-admin-placeholder-copy',
            get_stylesheet_directory_uri() . '/assets/js/admin-placeholder-copy.js',
            [],
            (string) filemtime($js),
            true
        );
    }
}
