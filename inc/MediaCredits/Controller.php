<?php

declare(strict_types=1);

namespace SFX\MediaCredits;

/**
 * Copyright notices and AI markings on media attachments.
 *
 * Opt-in: only constructed when `enable_media_credits` is on in
 * `sfx_general_options`. Registers hooks and holds no logic of its own.
 */
class Controller
{
    public function __construct()
    {
        Settings::register();
        AdminPage::register();
        MediaLibrary::register();
        Bricks::register();
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_feature_config(): array
    {
        return [
            'class'                  => self::class,
            'menu_slug'              => AdminPage::$menu_slug,
            'page_title'             => AdminPage::$page_title,
            'description'            => AdminPage::$description,
            'activation_option_name' => 'sfx_general_options',
            'activation_option_key'  => 'enable_media_credits',
            'option_value'           => true,
            'hook'                   => null,
            'error'                  => 'Missing MediaCredits Controller class in theme',
        ];
    }
}
