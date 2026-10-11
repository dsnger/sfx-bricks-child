<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * Switches of this theme's own modules that settle a check (owner ruling
 * 2026-10-11: the check must know what the WP Optimizer already does, rather
 * than suggest it again). Read through WP Optimizer's public Settings::get(),
 * so its defaults apply; outside evidence still wins over a switch.
 */
final class ThemeSwitches
{
    /** @var callable|null @internal test seam: fn(string $key): bool */
    public static $probe = null;

    /** WP Optimizer's switch $key is in force: module on, not paused, switch on. */
    public static function wp_optimizer(string $key): bool
    {
        if (self::$probe !== null) {
            return (bool) (self::$probe)($key);
        }
        if (!class_exists(\SFX\WPOptimizer\Settings::class) || !class_exists(\SFX\SFXBricksChildTheme::class)
            || !\SFX\SFXBricksChildTheme::is_general_option_enabled('enable_wp_optimizer')) {
            return false;
        }

        return empty(\SFX\WPOptimizer\Settings::get('disable_wp_optimizer')) && !empty(\SFX\WPOptimizer\Settings::get($key));
    }
}
