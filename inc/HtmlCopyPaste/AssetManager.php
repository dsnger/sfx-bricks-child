<?php

declare(strict_types=1);

namespace SFX\HtmlCopyPaste;

class AssetManager
{
    public static function register(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_builder_assets']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    public static function enqueue_builder_assets(): void
    {
        // Check if we're in Bricks Builder context
        $is_bricks_function_exists = function_exists('bricks_is_builder');
        $is_bricks_builder = $is_bricks_function_exists ? bricks_is_builder() : false;
        $current_url = $_SERVER['REQUEST_URI'] ?? '';
        $is_admin = is_admin();
        
        // Only load in Bricks Builder context (not for end users on frontend):
        // 1. We're in Bricks Builder, OR
        // 2. We're in admin and URL contains bricks params, OR
        // 3. We detect Bricks Builder query parameters
        $should_load = false;
        
        if ($is_bricks_function_exists && $is_bricks_builder) {
            $should_load = true;
        } elseif (isset($_GET['bricks']) && $_GET['bricks'] === 'run') {
            $should_load = true;
        } elseif (strpos($current_url, 'bricks=run') !== false) {
            $should_load = true;
        } elseif ($is_admin && (strpos($current_url, 'bricks') !== false)) {
            $should_load = true;
        }
        
        if (!$should_load) {
            return;
        }

        // Check if feature is enabled
        $defaults = [
            'enable_html_copy_paste' => '0',
            'enable_editor_mode' => '0',
            'preserve_custom_attributes' => '0',
            'auto_convert_images' => '0',
            'auto_convert_links' => '0',
        ];
        
        $options = get_option(Controller::OPTION_NAME, $defaults);
        
        if (empty($options['enable_html_copy_paste'])) {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style(
            'sfx-html-copy-paste-builder',
            get_stylesheet_directory_uri() . '/inc/HtmlCopyPaste/assets/builder.css',
            [],
            filemtime(get_stylesheet_directory() . '/inc/HtmlCopyPaste/assets/builder.css')
        );

        // Enqueue JavaScript
        wp_enqueue_script(
            'sfx-html-copy-paste-builder',
            get_stylesheet_directory_uri() . '/inc/HtmlCopyPaste/assets/builder.js',
            ['jquery'],
            filemtime(get_stylesheet_directory() . '/inc/HtmlCopyPaste/assets/builder.js'),
            true
        );

        // Localize script with settings
        wp_localize_script('sfx-html-copy-paste-builder', 'sfxHtmlCopyPaste', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sfx_html_copy_paste_nonce'),
            'settings' => $options,
            'strings' => [
                'pasteHtml' => __('Paste HTML', 'sfx-bricks-child'),
                'pasteHtmlEditor' => __('Paste HTML with Editor', 'sfx-bricks-child'),
                'insert' => __('Insert', 'sfx-bricks-child'),
                'close' => __('Close', 'sfx-bricks-child'),
                'clipboardNotAllowed' => __('Clipboard not allowed', 'sfx-bricks-child'),
                'convertingHtml' => __('Converting HTML...', 'sfx-bricks-child'),
                'htmlConverted' => __('HTML converted successfully', 'sfx-bricks-child'),
                'errorConverting' => __('Error converting HTML', 'sfx-bricks-child'),
            ]
        ]);
    }

    public static function enqueue_admin_assets(string $hook_suffix): void
    {
        // Only load on our admin page
        if ($hook_suffix !== 'sfx-theme-settings_page_' . AdminPage::$menu_slug) {
            return;
        }

        // Enqueue admin CSS
        wp_enqueue_style(
            'sfx-html-copy-paste-admin',
            get_stylesheet_directory_uri() . '/inc/HtmlCopyPaste/assets/admin.css',
            [],
            filemtime(get_stylesheet_directory() . '/inc/HtmlCopyPaste/assets/admin.css')
        );

        // Enqueue admin JavaScript
        wp_enqueue_script(
            'sfx-html-copy-paste-admin',
            get_stylesheet_directory_uri() . '/inc/HtmlCopyPaste/assets/admin.js',
            ['jquery'],
            filemtime(get_stylesheet_directory() . '/inc/HtmlCopyPaste/assets/admin.js'),
            true
        );
    }
} 