<?php

declare(strict_types=1);

namespace SFX\SiteCheck\Checks;

use SFX\SiteCheck\Evidence;
use SFX\SiteCheck\Observations;
use SFX\SiteCheck\RunContext;
use SFX\SiteCheck\Status;

/**
 * security_headers: the home page's response headers as the browser received
 * them; content is judged, not presence. version_leaks: the installed
 * WordPress version in the home page's generator meta tag and the main
 * feed's generator element, and server/PHP versions in the home page headers.
 */
final class HeaderChecks
{
    /** @return list<array{url:string, target:string, kind:string}> */
    public static function candidates(string $id, RunContext $ctx): array
    {
        $home = [['url' => home_url('/'), 'target' => '/', 'kind' => 'home']];
        if ($id === 'security_headers') {
            return $home;
        }
        $feed = (string) get_feed_link();

        return array_merge($home, [['url' => $feed, 'target' => Observations::target_of($feed), 'kind' => 'feed']]);
    }

    public static function observe(string $id, RunContext $ctx, array $plan, array $seen, array $comparison): array
    {
        return $id === 'security_headers' ? self::security_header_facts($plan, $seen, $comparison) : self::version_facts($ctx, $plan, $seen, $comparison);
    }

    public static function grade(string $id, array $facts, RunContext $ctx): array
    {
        return $id === 'security_headers' ? self::grade_security_headers($facts) : self::grade_version_leaks($facts);
    }

    private static function page_state(?array $obs, bool $feed = false): array
    {
        if ($obs === null) {
            return ['state' => 'unknown', 'reason' => __('not fetched', 'sfxtheme')];
        }
        $r = Observations::response($obs);
        if ($obs['error'] !== '') {
            return ['state' => 'unknown', 'reason' => $obs['error'] === 'timeout' ? __('no answer within 10 seconds', 'sfxtheme') : __('the request failed', 'sfxtheme')];
        }
        if ($r['challenge']) {
            return ['state' => 'unknown', 'reason' => __('a security service answered with a challenge page', 'sfxtheme')];
        }
        if ($r['redirect']) {
            return ['state' => 'unknown', 'reason' => $feed ? __('the feed redirects; redirects are not followed', 'sfxtheme') : __('the home page redirects; redirects are not followed', 'sfxtheme')];
        }
        if ($r['status'] < 200 || $r['status'] >= 300) {
            return ['state' => 'unknown', 'reason' => $feed
                /* translators: %d: HTTP status code */
                ? sprintf(__('the feed answered with status %d', 'sfxtheme'), $r['status'])
                /* translators: %d: HTTP status code */
                : sprintf(__('the home page answered with status %d', 'sfxtheme'), $r['status'])];
        }

        return ['state' => 'ok', 'reason' => ''];
    }

    /**
     * Judged here, from the whole header values (up to 8 KB), so the facts
     * stay small: per line a state — ok | missing | report_only |
     * upgrade_only | present | weak | invalid | cut — and a short detail
     * (the offending value). Rule 4 first: the home page must answer with a
     * non-empty body while the comparison clearly did not serve a page; a
     * header value cut at the bound is `cut`, never ok.
     */
    private static function security_header_facts(array $plan, array $seen, array $comparison): array
    {
        $obs = Observations::preferred($seen[$plan['issued'][0]['url'] ?? ''] ?? []);
        $facts = self::page_state($obs) + ['lines' => []];
        if ($facts['state'] === 'ok' && trim((string) $obs['body']) === '') {
            $facts = ['state' => 'unknown', 'reason' => __('empty answer', 'sfxtheme'), 'lines' => []];
        } elseif ($facts['state'] === 'ok' && !Evidence::comparison_refused($comparison)) {
            $facts = ['state' => 'unknown', 'reason' => __('the comparison address also answered normally, so this answer proves nothing', 'sfxtheme'), 'lines' => []];
        }
        if ($facts['state'] === 'ok') {
            $h = $obs['headers'];
            $cut = $obs['headers_cut'] ?? [];
            $get = static fn(string $name): string => (string) ($h[$name] ?? '');
            $whole = static fn(string ...$names): bool => array_intersect($names, $cut) === [];
            $csp = $get('content-security-policy');
            $facts['lines'] = [
                'strict-transport-security' => $whole('strict-transport-security') ? self::judge_hsts($get('strict-transport-security')) : self::line('cut'),
                'content-security-policy'   => $whole('content-security-policy', 'content-security-policy-report-only') ? self::judge_csp($csp, isset($h['content-security-policy-report-only'])) : self::line('cut'),
                'framing'                   => $whole('x-frame-options', 'content-security-policy') ? self::judge_framing($get('x-frame-options'), $csp) : self::line('cut'),
                'x-content-type-options'    => $whole('x-content-type-options') ? self::judge_nosniff($get('x-content-type-options')) : self::line('cut'),
                'referrer-policy'           => $whole('referrer-policy') ? self::judge_referrer($get('referrer-policy')) : self::line('cut'),
                'permissions-policy'        => $whole('permissions-policy') ? self::judge_permissions($get('permissions-policy')) : self::line('cut'),
            ];
        }

        return $facts;
    }

    /** Referrer-Policy tokens browsers know; the last known one counts. */
    private const REFERRER = ['no-referrer', 'no-referrer-when-downgrade', 'origin', 'origin-when-cross-origin', 'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'unsafe-url'];

    /** The tokens that keep the full URL from other sites. */
    private const REFERRER_SAFE = ['no-referrer', 'same-origin', 'strict-origin', 'strict-origin-when-cross-origin'];

    /** @return array{0:string, 1:string} state, detail */
    private static function line(string $state, string $detail = ''): array
    {
        return [$state, Observations::short($detail, 100)];
    }

    /**
     * HSTS after RFC 6797 §6.1: directives `name [= token | quoted-string]`
     * separated by `;`, names case-insensitive and not repeated, exactly one
     * `max-age` with a digits-only value. Anything malformed is ignored by
     * browsers, so it is `invalid` here; `max-age=0` is `weak`.
     */
    private static function judge_hsts(string $value): array
    {
        if (trim($value) === '') {
            return self::line('missing');
        }
        $token = '[!#$%&\'*+.^_`|~0-9A-Za-z-]+';
        $directives = [];
        $offset = 0;
        $length = strlen($value);
        while ($offset < $length) {
            if (preg_match('/\G[ \t]*(?:(' . $token . ')(?:[ \t]*=[ \t]*(?:(' . $token . ')|"((?:[^"\\\\]|\\\\.)*)"))?)?[ \t]*(;|$)/', $value, $m, PREG_UNMATCHED_AS_NULL, $offset) !== 1 || $m[0] === '') {
                return self::line('invalid', $value);
            }
            $offset += strlen($m[0]);
            if (($m[1] ?? null) !== null) {
                $name = strtolower($m[1]);
                if (array_key_exists($name, $directives)) {
                    return self::line('invalid', $value);
                }
                // null = no value given; includeSubDomains and preload must not have one.
                $directives[$name] = $m[2] ?? ($m[3] !== null ? stripslashes($m[3]) : null);
                if (in_array($name, ['includesubdomains', 'preload'], true) && $directives[$name] !== null) {
                    return self::line('invalid', $value);
                }
            }
            if (($m[4] ?? '') === '') {
                break;
            }
        }
        $age = $directives['max-age'] ?? null;
        if (!is_string($age) || preg_match('/^\d+$/', $age) !== 1) {
            return self::line('invalid', $value);
        }

        return (int) $age > 0 ? self::line('ok') : self::line('weak', $value);
    }

    /**
     * The policies of a Content-Security-Policy header: a comma separates
     * policies (CSP3 policy list), each enforced on its own.
     *
     * @return list<array<string, list<string>>>
     */
    private static function csp_policies(string $csp): array
    {
        $out = [];
        foreach (explode(',', $csp) as $policy) {
            $directives = self::csp_directives($policy);
            if ($directives !== []) {
                $out[] = $directives;
            }
        }

        return $out;
    }

    /** @return array<string, list<string>> CSP directive => values of one policy; the first of a repeated directive wins */
    private static function csp_directives(string $csp): array
    {
        $out = [];
        foreach (explode(';', $csp) as $part) {
            $tokens = preg_split('/\s+/', trim($part), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($tokens !== []) {
                $name = strtolower(array_shift($tokens));
                $out[$name] = $out[$name] ?? $tokens;
            }
        }

        return $out;
    }

    /**
     * CSP is not graded in detail (ruling, Gate B pass 3): an enforcing
     * policy is `present` (Hinweis); none, only Report-Only, or only
     * upgrade-insecure-requests / block-all-mixed-content → Gelb.
     */
    private static function judge_csp(string $csp, bool $report_only): array
    {
        $names = [];
        foreach (self::csp_policies($csp) as $policy) {
            $names = array_merge($names, array_keys($policy));
        }
        if ($names === []) {
            return self::line($report_only ? 'report_only' : 'missing');
        }

        return array_diff($names, ['upgrade-insecure-requests', 'block-all-mixed-content']) === [] ? self::line('upgrade_only') : self::line('present');
    }

    /**
     * An enforcing `frame-ancestors` wins over X-Frame-Options (browsers then
     * ignore the latter); only exactly `'none'` or `'self'` counts. Without
     * it: X-Frame-Options DENY or SAMEORIGIN.
     */
    private static function judge_framing(string $xfo, string $csp): array
    {
        // Every enforcing policy with frame-ancestors applies; XFO counts only when none has it.
        $seen = false;
        foreach (self::csp_policies($csp) as $policy) {
            $ancestors = $policy['frame-ancestors'] ?? null;
            if ($ancestors === null) {
                continue;
            }
            $seen = true;
            if (!in_array(strtolower(implode(' ', $ancestors)), ["'none'", "'self'"], true)) {
                return self::line('weak', 'frame-ancestors ' . implode(' ', $ancestors));
            }
        }
        if ($seen) {
            return self::line('ok');
        }
        $xfo = strtolower(trim($xfo));
        if (in_array($xfo, ['deny', 'sameorigin'], true)) {
            return self::line('ok');
        }

        return $xfo === '' ? self::line('missing') : self::line('invalid', 'X-Frame-Options ' . $xfo);
    }

    private static function judge_nosniff(string $value): array
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return self::line('missing');
        }

        return $value === 'nosniff' ? self::line('ok') : self::line('invalid', $value);
    }

    /** The last recognised token wins (as browsers read a list); only REFERRER_SAFE is ok. */
    private static function judge_referrer(string $value): array
    {
        if (trim($value) === '') {
            return self::line('missing');
        }
        $last = null;
        foreach (explode(',', strtolower($value)) as $token) {
            $token = trim($token);
            if (in_array($token, self::REFERRER, true)) {
                $last = $token;
            }
        }
        if ($last === null) {
            return self::line('invalid', $value);
        }

        return in_array($last, self::REFERRER_SAFE, true) ? self::line('ok') : self::line('weak', $last);
    }

    /** Permissions-Policy is not graded in detail (ruling): present → Hinweis, missing → Gelb. */
    private static function judge_permissions(string $value): array
    {
        return trim($value) === '' ? self::line('missing') : self::line('present');
    }

    private static function grade_security_headers(array $facts): array
    {
        $note = __('Judged by content, on the home page as your browser received it.', 'sfxtheme');
        if (($facts['state'] ?? '') !== 'ok') {
            $f = Observations::finding('security_headers', '/', Status::UNKNOWN, __('Home page', 'sfxtheme') . ': ' . (string) ($facts['reason'] ?? ''));
            return Observations::result([$f], Status::UNKNOWN, 'browser', $note);
        }

        $names = [
            'strict-transport-security' => 'Strict-Transport-Security (HSTS)',
            'content-security-policy'   => 'Content-Security-Policy',
            'framing'                   => 'X-Frame-Options / frame-ancestors',
            'x-content-type-options'    => 'X-Content-Type-Options',
            'referrer-policy'           => 'Referrer-Policy',
            'permissions-policy'        => 'Permissions-Policy',
        ];
        $findings = [];
        foreach ($names as $key => $name) {
            [$state, $detail] = array_pad((array) ($facts['lines'][$key] ?? ['missing']), 2, '');
            $detail = (string) $detail;
            switch ($state) {
                case 'ok':
                    $line = [Status::GREEN, $key === 'framing' ? __('set (X-Frame-Options or CSP frame-ancestors)', 'sfxtheme') : __('set', 'sfxtheme')];
                    break;
                case 'report_only':
                    $line = [Status::YELLOW, __('missing (a Report-Only policy does not protect)', 'sfxtheme')];
                    break;
                case 'upgrade_only':
                    $line = [Status::YELLOW, __('present, protects little (only upgrade-insecure-requests)', 'sfxtheme')];
                    break;
                case 'weak':
                    $line = [Status::YELLOW, $key === 'strict-transport-security'
                        ? __('present, but without a positive max-age', 'sfxtheme')
                        /* translators: %s: the header value that protects little */
                        : sprintf(__('present, protects little (%s)', 'sfxtheme'), $detail)];
                    break;
                case 'present':
                    $line = [Status::HINT, __('present — its effect is not judged in detail', 'sfxtheme')];
                    break;
                case 'cut':
                    $line = [Status::UNKNOWN, __('too long to read completely; not judged', 'sfxtheme')];
                    break;
                case 'invalid':
                    /* translators: %s: the value that is not recognised */
                    $line = [Status::YELLOW, sprintf(__('present, but not recognised: %s', 'sfxtheme'), $detail)];
                    break;
                default:
                    $line = [Status::YELLOW, __('missing', 'sfxtheme')];
            }
            $findings[] = Observations::finding('security_headers', $key, $line[0], $name . ': ' . $line[1]);
        }

        return Observations::result($findings, Status::GREEN, 'browser', $note);
    }

    /**
     * Per source (home, feed) one state: found | clean | absent (feed only:
     * both variants 404/410) | unknown with a reason. Rule 4 wins over the
     * generator marker: a match counts only in a 2xx answer that is no
     * challenge, no redirect and no soft-404 — truncation alone does not
     * void a match within the bytes read. "clean" needs both variants read
     * completely without a match. Header leaks come from the home page.
     */
    private static function version_facts(RunContext $ctx, array $plan, array $seen, array $comparison): array
    {
        $version = (string) ($GLOBALS['wp_version'] ?? '');
        $facts = ['sources' => [], 'leaks' => []];
        // A source the plan did not fetch (rule 6, Locations) stays Nicht prüfbar, with its reason.
        foreach ($plan['skipped'] as $skip) {
            foreach (self::candidates('version_leaks', $ctx) as $c) {
                if ($c['target'] === $skip['target']) {
                    $facts['sources'][] = ['target' => $c['target'], 'kind' => $c['kind'], 'state' => 'unknown', 'reason' => (string) $skip['reason']];
                }
            }
        }
        foreach ($plan['issued'] as $c) {
            $variants = $seen[$c['url']] ?? [];
            $facts['sources'][] = ['target' => $c['target'], 'kind' => $c['kind']] + self::source_state($c['kind'], $variants, $comparison, $version);
            if ($c['kind'] !== 'home') {
                continue;
            }
            $obs = Observations::preferred($variants);
            // Only a normal answer counts: a challenge or error page may come from another server.
            if (self::page_state($obs)['state'] === 'ok') {
                foreach (['server', 'x-powered-by', 'x-aspnet-version'] as $name) {
                    $value = (string) ($obs['headers'][$name] ?? '');
                    if (preg_match('/\d+\.\d+/', $value) === 1) {
                        $facts['leaks'][$name] = Observations::short($value, 100);
                    }
                }
            }
        }

        return $facts;
    }

    /** @return array{state:string, reason:string} */
    private static function source_state(string $kind, array $variants, array $comparison, string $version): array
    {
        $states = [];
        $reason = '';
        foreach (['busted', 'plain'] as $v) {
            [$state, $why] = self::variant_state($kind, $variants[$v] ?? null, $comparison, $version);
            $states[] = $state;
            if ($reason === '' && $why !== '') {
                $reason = $why;
            }
        }
        if (in_array('found', $states, true)) {
            return ['state' => 'found', 'reason' => ''];
        }
        if ($states === ['clean', 'clean']) {
            return ['state' => 'clean', 'reason' => ''];
        }
        if ($kind === 'feed' && $states === ['gone', 'gone']) {
            return ['state' => 'absent', 'reason' => ''];
        }

        return ['state' => 'unknown', 'reason' => $reason !== '' ? $reason : __('the two fetches did not agree', 'sfxtheme')];
    }

    /** @return array{0:string, 1:string} found | clean | gone | failed, and the reason for failed */
    private static function variant_state(string $kind, ?array $obs, array $comparison, string $version): array
    {
        $state = self::page_state($obs, $kind === 'feed');
        if ($state['state'] !== 'ok') {
            $r = $obs === null ? null : Observations::response($obs);
            // Rule 4 before the status: only a complete, non-empty error page means "not present".
            $gone = $r !== null && !$r['challenge'] && !$r['redirect'] && in_array($r['status'], [404, 410], true)
                && trim($r['body']) !== '' && !$r['truncated'];
            return [$gone ? 'gone' : 'failed', $gone ? '' : $state['reason']];
        }
        $r = Observations::response($obs);
        if ($r['body'] === '') {
            return ['failed', __('empty answer', 'sfxtheme')];
        }
        // Soft-404 (rule 4): judged as if complete, so a truncated answer still counts its match.
        if (Evidence::classify(['truncated' => false] + $r, $comparison) !== Evidence::OK200) {
            return ['failed', __('the comparison address also answered normally, so this answer proves nothing', 'sfxtheme')];
        }
        if ($version !== '' && self::shows_version($kind, $r['body'], $version, $r['truncated'])) {
            return ['found', ''];
        }
        if ($r['truncated']) {
            return ['failed', __('too large to read completely, no version in the part read', 'sfxtheme')];
        }
        // A feed is clean only as a complete, well-formed feed document (rule 4: unparseable).
        $parsed = $kind === 'feed'
            ? in_array(Observations::xml_root($r['body']), ['rss', 'feed', 'rdf'], true) && Observations::well_formed_xml($r['body'])
            : preg_match('/<(?:html|head)\b/i', $r['body']) === 1 && Observations::html_tags($r['body']) !== null;

        return $parsed ? ['clean', ''] : ['failed', $kind === 'feed' ? __('the answer is not a feed', 'sfxtheme') : __('the answer is not an HTML page', 'sfxtheme')];
    }

    /**
     * The installed version in WordPress' own generator: a `<meta name="generator">`
     * whose content starts with "WordPress <version>" (home), or a `<generator>`
     * element naming WordPress with that version (RSS `?v=`, Atom `version=""`).
     */
    private static function shows_version(string $kind, string $body, string $version, bool $truncated = false): bool
    {
        $exact = '(?<![0-9.])' . preg_quote($version, '/') . '(?![0-9]|\.[0-9])';
        if ($kind === 'feed') {
            foreach (Observations::feed_generators($body, $truncated) as $generator) {
                if (stripos($generator, 'wordpress') !== false && preg_match('/' . $exact . '/', $generator) === 1) {
                    return true;
                }
            }
            return false;
        }
        // Home: the parsed generator meta tag (exact name, entities decoded, comments ignored).
        foreach (Observations::html_tags($body)['meta'] ?? [] as $a) {
            if (strtolower(trim($a['name'] ?? '')) === 'generator'
                && preg_match('/^\s*WordPress\s+' . $exact . '/i', $a['content'] ?? '') === 1) {
                return true;
            }
        }

        return false;
    }

    private static function grade_version_leaks(array $facts): array
    {
        $note = __('The home page and the main feed as your browser received them. Only WordPress\' own generator tags count; asset ?ver= parameters are not checked.', 'sfxtheme');
        $findings = [];
        foreach ($facts['sources'] ?? [] as $s) {
            $target = (string) ($s['target'] ?? '');
            $where = ($s['kind'] ?? '') === 'feed' ? __('generator element of the feed', 'sfxtheme') : __('generator meta tag of the home page', 'sfxtheme');
            switch ($s['state'] ?? '') {
                case 'found':
                    /* translators: %s: where the version was found */
                    $findings[] = Observations::finding('version_leaks', $target, Status::YELLOW, $target . ' — ' . sprintf(__('shows the installed WordPress version (%s)', 'sfxtheme'), $where));
                    break;
                case 'clean':
                    $findings[] = Observations::finding('version_leaks', $target, Status::GREEN, $target . ' — ' . __('WordPress version not found in the sources checked', 'sfxtheme'));
                    break;
                case 'absent':
                    $findings[] = Observations::finding('version_leaks', $target, Status::GREEN, $target . ' — ' . __('feed not present', 'sfxtheme'));
                    break;
                default:
                    $findings[] = Observations::finding('version_leaks', $target, Status::UNKNOWN, $target . ' — ' . (string) ($s['reason'] ?? ''));
            }
        }
        foreach ($facts['leaks'] ?? [] as $name => $value) {
            /* translators: 1: header name, 2: header value */
            $findings[] = Observations::finding('version_leaks', (string) $name, Status::HINT, sprintf(__('%1$s reveals a version: %2$s', 'sfxtheme'), $name, $value));
        }

        return Observations::result($findings, Status::UNKNOWN, 'browser', $note);
    }
}
