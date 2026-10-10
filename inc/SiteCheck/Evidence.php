<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * Evidence rules 2–5 and the file-exposure table (spec "Results"). Pure: it
 * judges what a fetch returned, it never fetches.
 */
final class Evidence
{
    public const SIGNATURE     = 'signature';
    public const UNREACHABLE   = 'unreachable';
    public const INDETERMINATE = 'indeterminate';
    public const OK200         = 'ok200';

    /**
     * Classifies one bounded outside fetch against the batch's comparison URL.
     *
     * Order: a signature beats everything (rule 5); a challenge page is
     * Nicht prüfbar (rule 4); a redirect is "not reachable" (rule 3); then
     * the rest of rule 4, which wins over the rest of rule 3.
     *
     * @param array{status:int, body:string, truncated:bool, redirect:bool, challenge?:bool} $response status 0 = no answer
     * @param array{status:int, redirect?:bool}                             $comparison the random missing URL
     * @param array<string,string>                                          $signatures name => PCRE pattern
     */
    public static function classify(array $response, array $comparison, array $signatures = []): string
    {
        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        if (self::signature($body, $signatures) !== null) {
            return self::SIGNATURE;
        }
        // A WAF/CDN challenge says nothing about the resource, whatever its status.
        if (!empty($response['challenge'])) {
            return self::INDETERMINATE;
        }
        // A redirect carries no evidence in its body (the browser sees none).
        if (!empty($response['redirect']) || ($status >= 300 && $status < 400)) {
            return self::UNREACHABLE;
        }
        if ($body === '' || !empty($response['truncated'])) {
            return self::INDETERMINATE;
        }
        // Rule 3's "same status as a 404/410 comparison" is covered here too.
        if (in_array($status, [403, 404, 410], true)) {
            return self::UNREACHABLE;
        }

        if ($status === 200) {
            return self::comparison_refused($comparison) ? self::OK200 : self::INDETERMINATE;
        }

        // 401, 429, 5xx, no answer and anything unexpected.
        return self::INDETERMINATE;
    }

    /**
     * Only a comparison that clearly did not serve a page (redirect or a
     * 3xx/4xx other than 401/429) rules out a soft-404 site. A comparison
     * answering 200 — or not answering usably — does not.
     *
     * @param array{status:int, redirect?:bool} $comparison
     */
    public static function comparison_refused(array $comparison): bool
    {
        $c = (int) ($comparison['status'] ?? 0);

        return !empty($comparison['redirect']) || ($c >= 300 && $c < 500 && !in_array($c, [401, 429], true));
    }

    /**
     * The comparison answered 404 or 410 (no redirect): the one case in
     * which an empty answer counts as "nothing there" (rule 3).
     */
    public static function comparison_missing(array $comparison): bool
    {
        return empty($comparison['redirect']) && in_array((int) ($comparison['status'] ?? 0), [404, 410], true);
    }

    /**
     * Name of the first signature found in the body, or null.
     *
     * @param array<string,string> $signatures name => PCRE pattern
     */
    public static function signature(string $body, array $signatures): ?string
    {
        foreach ($signatures as $name => $pattern) {
            if ($body !== '' && preg_match($pattern, $body) === 1) {
                return (string) $name;
            }
        }
        return null;
    }

    /**
     * The file-exposure table, rows top to bottom, first match wins.
     *
     * @param string $disk    present|absent|unknown (Server)
     * @param string $outside a classify() result
     */
    public static function file_exposure(string $disk, string $outside): string
    {
        if ($outside === self::SIGNATURE) {
            return Status::RED;
        }
        if ($disk === 'present' && $outside === self::UNREACHABLE) {
            return Status::YELLOW;
        }
        if ($disk === 'absent' && $outside === self::UNREACHABLE) {
            return Status::GREEN;
        }
        if ($outside === self::INDETERMINATE) {
            return Status::UNKNOWN;
        }
        if ($disk === 'present' && $outside === self::OK200) {
            return Status::YELLOW;
        }
        // absent + 200 without signature, and unknown disk with anything else.
        return Status::UNKNOWN;
    }
}
