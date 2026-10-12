<?php

declare(strict_types=1);

namespace Bricks;

/** Bricks' builder permission, answered from $GLOBALS['test_can_use_builder']. */
final class Capabilities
{
    public static function current_user_can_use_builder($post_id = 0): bool
    {
        return (bool) ($GLOBALS['test_can_use_builder'] ?? false);
    }
}
