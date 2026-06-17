<?php

declare(strict_types=1);

namespace SFX;

class SFXBricksChildTheme
{
    public static function is_general_option_enabled(string $option_key): bool
    {
        $options = get_option('sfx_general_options', []);
        if (isset($options[$option_key])) {
            return ! empty($options[$option_key]);
        }

        if (class_exists('\\SFX\\GeneralThemeOptions\\Settings')) {
            foreach (\SFX\GeneralThemeOptions\Settings::get_all_fields() as $field) {
                if (($field['id'] ?? '') === $option_key) {
                    return isset($field['default']) ? (bool) $field['default'] : false;
                }
            }
        }

        return false;
    }
}

class AccessControl
{
    public static function can_access_theme_settings(): bool
    {
        global $test_can_access_theme_settings;

        return (bool) $test_can_access_theme_settings;
    }
}
