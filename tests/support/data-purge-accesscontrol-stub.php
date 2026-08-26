<?php

declare(strict_types=1);

namespace SFX;

/**
 * The theme's access gate, reduced to a switch the handler suite can flip.
 * Throws where production would end the request, so a test sees which gate
 * stopped it.
 */
class AccessControl
{
    public static function die_if_unauthorized_theme(): void
    {
        if (empty($GLOBALS['test_theme_access'])) {
            throw new \Stopped('theme-access');
        }
    }
}
