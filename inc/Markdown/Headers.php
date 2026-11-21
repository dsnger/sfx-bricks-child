<?php

declare(strict_types=1);

namespace SFX\Markdown;

class Headers
{
    /** Writes one Vary header holding every token already sent plus $token. */
    public static function merge_vary(string $token): void
    {
        header('Vary: ' . self::merged_vary(headers_list(), $token));
    }

    /** @param string[] $headers_list as returned by headers_list() */
    public static function merged_vary(array $headers_list, string $token): string
    {
        $tokens = [];
        foreach ($headers_list as $line) {
            $pair = explode(':', $line, 2);
            if (count($pair) === 2 && strtolower(trim($pair[0])) === 'vary') {
                foreach (explode(',', $pair[1]) as $t) {
                    $t = trim($t);
                    if ($t !== '') {
                        $tokens[strtolower($t)] ??= $t;
                    }
                }
            }
        }
        $tokens[strtolower($token)] ??= $token;

        return implode(', ', $tokens);
    }
}
