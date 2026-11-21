<?php

declare(strict_types=1);

namespace SFX\Markdown;

class Controller
{
    public function __construct()
    {
        Settings::register();

        if (!Request::parser_available()) {
            return; // D10: inert front end below WP 6.7.
        }
        add_filter('exit_on_http_head', [self::class, 'exit_on_http_head']);
        add_action('wp_head', [self::class, 'print_head_link']);
        add_action('template_redirect', [self::class, 'add_vary'], PHP_INT_MAX);
    }

    /** A HEAD request for Markdown gets the GET response's headers (spec § Markdown response). */
    public static function exit_on_http_head($exit)
    {
        return Request::is_eligible() && Request::wants_markdown() ? false : $exit;
    }

    public static function print_head_link(): void
    {
        $url = Request::is_eligible() ? Request::markdown_url() : null;
        if ($url !== null) {
            echo '<link rel="alternate" type="text/markdown" href="' . esc_url($url) . '" />' . "\n";
        }
    }

    public static function add_vary(): void
    {
        if (!headers_sent() && Request::is_eligible()) {
            Headers::merge_vary('Accept');
        }
    }

    public static function get_feature_config(): array
    {
        return [
            'class' => self::class,
            'menu_slug' => 'sfx-markdown',
            'page_title' => __('Markdown for AI agents', 'sfxtheme'),
            'description' => __('Serves a Markdown version of your pages to AI agents that ask for it, and an llms.txt index.', 'sfxtheme'),
            'activation_option_name' => 'sfx_general_options',
            'activation_option_key' => 'enable_markdown',
            'option_value' => true,
            'hook' => null,
            'error' => 'Missing Markdown Controller class in theme',
        ];
    }
}
