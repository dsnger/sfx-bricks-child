<?php

declare(strict_types=1);

namespace SFX\EditorProse;

class Controller
{
    public function __construct()
    {
        Settings::register();
        AdminPage::register();

        add_action('enqueue_block_editor_assets', [self::class, 'enqueue_editor']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend'], 20);
    }

    public static function enqueue_editor(): void
    {
        if (!is_admin() || !function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || !method_exists($screen, 'is_block_editor') || !$screen->is_block_editor() || $screen->base !== 'post') {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            $post_id = (int) filter_input(INPUT_GET, 'post', FILTER_VALIDATE_INT);
        }
        if (!$post_id) {
            return;
        }

        $post_type = (string) get_post_type($post_id);
        $options = Settings::get();
        $bricks = defined('BRICKS_VERSION') ? (string) BRICKS_VERSION : null;
        if (!Settings::bricks_ok($bricks) || !Settings::applies_to($options, $post_type)) {
            return;
        }

        $payload = Payload::build($options, $post_type, $options['baseline'] ? self::baseline_url() : '');
        $file = get_stylesheet_directory() . '/inc/EditorProse/assets/editor-prose.js';

        wp_enqueue_script(
            'sfx-editor-prose',
            get_stylesheet_directory_uri() . '/inc/EditorProse/assets/editor-prose.js',
            [],
            (string) filemtime($file),
            true
        );
        // JSON_HEX_TAG: no literal < or > reaches the inline <script>, so neither "</script" nor
        // "<!--<script" in class CSS can end the element or confuse the HTML parser.
        wp_add_inline_script('sfx-editor-prose', 'window.sfxEditorProseConfig = ' . wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP) . ';', 'before');
    }

    public static function enqueue_frontend(): void
    {
        if (function_exists('bricks_is_builder_main') && bricks_is_builder_main()) {
            return;
        }
        if (!Settings::get()['baseline']) {
            return;
        }

        $file = get_stylesheet_directory() . '/inc/EditorProse/assets/prose.css';
        wp_enqueue_style(
            'sfx-prose',
            get_stylesheet_directory_uri() . '/inc/EditorProse/assets/prose.css',
            [], // prose.css carries its own sfx.* layer-order statement; no dependency on a handle disable_bricks_css can drop
            (string) filemtime($file)
        );
    }

    private static function baseline_url(): string
    {
        $file = get_stylesheet_directory() . '/inc/EditorProse/assets/prose.css';

        return add_query_arg('ver', (string) filemtime($file), get_stylesheet_directory_uri() . '/inc/EditorProse/assets/prose.css');
    }

    public static function get_feature_config(): array
    {
        return [
            'class' => self::class,
            'menu_slug' => AdminPage::$menu_slug,
            // Literals, so string extraction finds them. Keep in sync with AdminPage.
            'page_title' => __('Editor Prose', 'sfxtheme'),
            'description' => __('Makes the block editor show Gutenberg content like the Bricks frontend: mirrors your prose class and Bricks spacing into the editor, with an optional token-based baseline.', 'sfxtheme'),
            'activation_option_name' => 'sfx_general_options',
            'activation_option_key' => 'enable_editor_prose',
            'option_value' => true,
            'hook' => null,
            'error' => 'Missing EditorProse Controller class in theme',
        ];
    }
}
