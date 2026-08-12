<?php

declare(strict_types=1);

/**
 * Bricks doubles for the NavMenuQuery suite.
 *
 * Separate file because PHP does not allow a bracketed namespace block
 * alongside non-namespaced code — the same reason sfx-namespaced-stubs.php
 * exists.
 */

namespace Bricks;

class Elements
{
    /** @var array<string, mixed> element name => whatever; only keys are read */
    public static array $elements = [];
}

class Query
{
    /** Return value for is_any_looping(): false, or an enclosing query id. */
    public static mixed $any_looping = false;

    /** Return value for is_looping(). */
    public static bool $looping = false;

    /** query id => loop object. The '' key serves the no-argument call. */
    public static array $loop_objects = [];

    public static function is_any_looping(): mixed
    {
        return self::$any_looping;
    }

    public static function is_looping($element_id = '', $query_id = ''): bool
    {
        return self::$looping;
    }

    public static function get_loop_object($query_id = '')
    {
        return self::$loop_objects[$query_id] ?? (self::$loop_objects[''] ?? null);
    }

    public static function reset(): void
    {
        self::$any_looping  = false;
        self::$looping      = false;
        self::$loop_objects = [];
    }
}
