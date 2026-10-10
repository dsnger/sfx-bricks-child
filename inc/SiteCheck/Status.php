<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * The result values of every check (spec "Results" → Status values).
 * Stored and compared as these strings; the labels are the screen's business.
 */
final class Status
{
    public const RED     = 'red';
    public const YELLOW  = 'yellow';
    public const GREEN   = 'green';
    public const HINT    = 'hint';
    public const UNKNOWN = 'unknown';

    /** Section order (spec "Sections"): the first is the most urgent. */
    public const ORDER = [self::RED, self::YELLOW, self::UNKNOWN, self::HINT, self::GREEN];

    /** The status words, the same on the page and in the dashboard box ("Hinweis", never "Notiz"). */
    public static function labels(): array
    {
        return [
            self::RED     => __('Red', 'sfxtheme'),
            self::YELLOW  => __('Yellow', 'sfxtheme'),
            self::UNKNOWN => __('Not checkable', 'sfxtheme'),
            self::HINT    => _x('Note', 'site check status', 'sfxtheme'),
            self::GREEN   => __('Green', 'sfxtheme'),
        ];
    }

    /**
     * The most urgent of the given statuses, or $empty when there are none.
     *
     * @param list<string> $statuses
     */
    public static function worst(array $statuses, string $empty): string
    {
        foreach (self::ORDER as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }
        return $empty;
    }
}
