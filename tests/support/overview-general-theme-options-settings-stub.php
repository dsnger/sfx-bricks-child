<?php

declare(strict_types=1);

namespace SFX\GeneralThemeOptions;

class Settings
{
    public static function get_all_fields(): array
    {
        return array_merge(self::get_fields(), self::get_style_fields());
    }

    public static function get_fields(): array
    {
        return [
            ['id' => 'enable_wp_optimizer', 'default' => 1],
            ['id' => 'enable_image_optimizer', 'default' => 1],
            ['id' => 'enable_security_header', 'default' => 1],
            ['id' => 'enable_smooth_scroll', 'default' => 0],
            ['id' => 'disable_bricks_js', 'default' => 0],
            ['id' => 'disable_bricks_styles', 'default' => 0],
        ];
    }

    public static function get_style_fields(): array
    {
        return [
            ['id' => 'enable_style_content_grid', 'label' => 'Content Grid', 'default' => 1],
        ];
    }
}
