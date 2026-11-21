<?php

declare(strict_types=1);

namespace SFX\Markdown;

/**
 * Resolves a URL from page HTML against the canonical URL and drops unsafe schemes.
 */
class Url
{
    private const SCHEMES = [
        'link'  => ['http', 'https', 'mailto', 'tel'],
        'image' => ['http', 'https'],
    ];

    /** Returns the absolute URL, or null when it must be dropped. */
    public static function resolve(string $ref, string $base, string $kind): ?string
    {
        // Browsers ignore C0 controls and DEL anywhere ("java\tscript:"), so they must not hide a scheme.
        $ref = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $ref), ' ');

        if ($ref === '' || $ref[0] === '#') {
            // make_absolute_url() would drop the base's query (?page_id=1120#s on plain permalinks).
            $url = strtok($base, '#') . $ref;
        } else {
            $url = \WP_Http::make_absolute_url($ref, $base);
        }

        if (!preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $m)) {
            return null;
        }
        return in_array(strtolower($m[1]), self::SCHEMES[$kind] ?? [], true) ? $url : null;
    }
}
