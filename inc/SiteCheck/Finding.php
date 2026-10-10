<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * Finding identity (spec "Results" → Finding identity): one problem at one
 * place, `<check-id>:<target>`. Monitoring and mail compare these IDs only.
 */
final class Finding
{
    /** Query parameter the module adds to outside fetches to bypass caches. */
    public const CACHE_BUSTER = 'sfxcb';

    /**
     * @param string $target site-relative path, `user-<ID>:<grant>`, a setting
     *                       name, or `probe`
     */
    public static function id(string $check, string $target): string
    {
        return $check . ':' . self::without_cache_buster($target);
    }

    /** The URL or target without the module's cache-buster parameter; other query pairs stay. */
    public static function without_cache_buster(string $target): string
    {
        $pos = strpos($target, '?');
        if ($pos === false) {
            return $target;
        }

        $kept = array_filter(
            explode('&', substr($target, $pos + 1)),
            static fn(string $pair): bool => $pair !== '' && explode('=', $pair, 2)[0] !== self::CACHE_BUSTER
        );

        return substr($target, 0, $pos) . ($kept === [] ? '' : '?' . implode('&', $kept));
    }
}
