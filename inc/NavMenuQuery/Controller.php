<?php

declare(strict_types=1);

namespace SFX\NavMenuQuery;

/**
 * Bricks query loop over WordPress menu items.
 *
 * Opt-in: only constructed when `enable_nav_menu_query` is on in
 * `sfx_general_options`. Registers hooks and holds no logic of its own.
 */
class Controller
{
    public function __construct()
    {
        QueryType::register();
        MenuOptions::register();
        MenuItemTags::register();
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_feature_config(): array
    {
        return [
            'class'                  => self::class,
            'activation_option_name' => 'sfx_general_options',
            'activation_option_key'  => 'enable_nav_menu_query',
            'option_value'           => true,
            'hook'                   => null,
            'error'                  => 'Missing NavMenuQuery Controller class in theme',
        ];
    }
}
