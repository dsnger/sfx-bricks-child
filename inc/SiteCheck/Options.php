<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * The one place SiteCheck option and cron hook names come from.
 *
 * A live harness switches the prefix to a random `sfx_site_check_h<random>_`
 * before its first write, so it never touches the site's real options or
 * cron events. Nothing outside tests/support/ may call use_harness_prefix().
 */
final class Options
{
    public const DEFAULT_PREFIX = 'sfx_site_check_';

    /** Every option key the module owns (spec "Storage"). */
    public const KEYS = ['settings', 'baseline', 'manual', 'items', 'monitor', 'lock', 'probes', 'mutex'];

    private static string $prefix = self::DEFAULT_PREFIX;

    public static function name(string $key): string
    {
        return self::$prefix . $key;
    }

    public static function hook(string $key): string
    {
        return self::$prefix . $key;
    }

    public static function prefix(): string
    {
        return self::$prefix;
    }

    /** For live harnesses only. */
    public static function use_harness_prefix(string $prefix): void
    {
        if (preg_match('/^sfx_site_check_h[a-z0-9]{6,}_$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('Harness prefix must look like sfx_site_check_h<random>_.');
        }
        self::$prefix = $prefix;
    }
}
