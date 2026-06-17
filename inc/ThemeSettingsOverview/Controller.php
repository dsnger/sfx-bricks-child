<?php

declare(strict_types=1);

namespace SFX\ThemeSettingsOverview;

use SFX\AccessControl;

/**
 * Theme settings overview dashboard widget and feature bootstrap.
 */
final class Controller
{
    public function __construct()
    {
        add_action('wp_dashboard_setup', [self::class, 'register_dashboard_widget'], 5);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    public static function register_dashboard_widget(): void
    {
        if (! AccessControl::can_access_theme_settings()) {
            return;
        }

        if (self::is_custom_dashboard_enabled()) {
            return;
        }

        wp_add_dashboard_widget(
            'sfx_theme_settings_overview',
            __('SFX Theme Settings', 'sfxtheme'),
            [OverviewRenderer::class, 'render_native']
        );
    }

    public static function render_widget(): void
    {
        OverviewRenderer::render_custom_dashboard();
    }

    public static function enqueue_admin_assets(string $hook_suffix): void
    {
        if ($hook_suffix !== 'index.php') {
            return;
        }

        if (! AccessControl::can_access_theme_settings()) {
            return;
        }

        if (self::is_custom_dashboard_enabled()) {
            return;
        }

        $css_path = get_stylesheet_directory() . '/assets/css/backend/styles.css';
        if (! file_exists($css_path)) {
            return;
        }

        wp_enqueue_style(
            'sfx-bricks-child-admin-styles',
            get_stylesheet_directory_uri() . '/assets/css/backend/styles.css',
            [],
            (string) filemtime($css_path)
        );
    }

    public static function is_custom_dashboard_enabled(): bool
    {
        $options = get_option('sfx_custom_dashboard', []);
        if (! is_array($options)) {
            return false;
        }

        return ! empty($options['enable_custom_dashboard']);
    }

    public static function get_feature_config(): array
    {
        return [
            'class' => self::class,
            'menu_slug' => null,
            'page_title' => null,
            'description' => null,
            'error' => 'Missing ThemeSettingsOverview Controller class in theme',
            'hook' => null,
        ];
    }
}
