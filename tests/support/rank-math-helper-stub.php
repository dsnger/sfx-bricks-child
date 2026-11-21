<?php

declare(strict_types=1);

namespace RankMath;

// The two Rank Math helper calls the schema extension makes, answered from test globals.
class Helper
{
    public static function get_settings($field_id = '', $default_value = false)
    {
        return $GLOBALS['test_rank_math_settings'][$field_id] ?? $default_value;
    }

    public static function is_module_active($id, $check_registered = true)
    {
        return in_array($id, $GLOBALS['test_rank_math_modules'] ?? ['rich-snippet'], true);
    }
}
