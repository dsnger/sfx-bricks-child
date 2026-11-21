<?php

declare(strict_types=1);

namespace RankMath;

// A Rank Math whose helper calls fail: the schema code must survive it (AC-5).
class Helper
{
    public static function get_settings($field_id = '', $default_value = false)
    {
        throw new \RuntimeException('plugin helper failure');
    }

    public static function is_module_active($id, $check_registered = true)
    {
        throw new \RuntimeException('plugin helper failure');
    }
}
