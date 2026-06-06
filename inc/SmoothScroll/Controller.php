<?php

declare(strict_types=1);

namespace SFX\SmoothScroll;

class Controller
{
    public function __construct()
    {
        Settings::register();
        AdminPage::register();
        AssetManager::register();

        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend'], 20);
    }

    public function enqueue_frontend(): void
    {
        if (function_exists('bricks_is_builder_main') && bricks_is_builder_main()) {
            return;
        }

        $lenis_path = get_stylesheet_directory() . '/assets/libs/lenis/lenis.min.js';
        if (!file_exists($lenis_path)) {
            return;
        }

        $init = get_stylesheet_directory() . '/inc/SmoothScroll/assets/smooth-scroll.js';
        if (!file_exists($init)) {
            return;
        }

        wp_enqueue_script(
            'sfx-lenis',
            get_stylesheet_directory_uri() . '/assets/libs/lenis/lenis.min.js',
            [],
            '1.3.23',
            true
        );

        wp_enqueue_script(
            'sfx-smooth-scroll',
            get_stylesheet_directory_uri() . '/inc/SmoothScroll/assets/smooth-scroll.js',
            ['sfx-lenis'],
            (string) filemtime($init),
            true
        );

        wp_localize_script('sfx-smooth-scroll', 'sfxSmoothScroll', Settings::get_config_for_js());
    }

    public static function get_feature_config(): array
    {
        return [
            'class' => self::class,
            'menu_slug' => AdminPage::$menu_slug,
            'page_title' => AdminPage::$page_title,
            'description' => AdminPage::$description,
            'activation_option_name' => 'sfx_general_options',
            'activation_option_key' => 'enable_smooth_scroll',
            'option_value' => true,
            'hook' => null,
            'error' => 'Missing SmoothScrollController class in theme',
        ];
    }
}
