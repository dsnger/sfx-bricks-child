<?php

declare(strict_types=1);

namespace SFX\WPOptimizer;

class Settings
{
    public static function get_fields(): array
    {
        return [
            ['id' => 'disable_jquery', 'label' => 'Disable jQuery', 'type' => 'checkbox', 'default' => 1, 'group' => 'performance'],
            ['id' => 'jquery_to_footer', 'label' => 'jQuery to footer', 'type' => 'checkbox', 'default' => 0, 'group' => 'performance'],
            ['id' => 'disable_search', 'label' => 'Disable search', 'type' => 'checkbox', 'default' => 0, 'group' => 'frontend'],
        ];
    }

    public static function get(string $key, $default = null)
    {
        $options = get_option('sfx_wpoptimizer_options', []);
        if (isset($options[$key])) {
            return $options[$key];
        }
        if ($default !== null) {
            return $default;
        }
        foreach (self::get_fields() as $field) {
            if ($field['id'] === $key) {
                return $field['default'] ?? null;
            }
        }

        return null;
    }
}
