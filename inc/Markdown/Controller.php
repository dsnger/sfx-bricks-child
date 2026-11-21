<?php

declare(strict_types=1);

namespace SFX\Markdown;

class Controller
{
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
