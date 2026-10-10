<?php

declare(strict_types=1);

namespace SFX\SiteCheck\Checks;

use SFX\SiteCheck\Evidence;
use SFX\SiteCheck\Fetch;
use SFX\SiteCheck\Observations;
use SFX\SiteCheck\Robots;
use SFX\SiteCheck\RunContext;
use SFX\SiteCheck\Settings;
use SFX\SiteCheck\Status;

/**
 * robots_txt (Browser), indexability, sitemap and sitemap_entries (Loopback):
 * what search engines are allowed and able to find.
 */
final class CrawlChecks
{
    /** @return list<array{url:string, target:string, kind:string}> */
    public static function candidates(string $id, RunContext $ctx): array
    {
        return $id === 'robots_txt' ? [['url' => self::robots_url(), 'target' => '/robots.txt', 'kind' => '']] : [];
    }

    public static function observe(string $id, RunContext $ctx, array $plan, array $seen, array $comparison): array
    {
        switch ($id) {
            case 'robots_txt':
                $obs = Observations::preferred($seen[$plan['issued'][0]['url'] ?? ''] ?? []);
                return self::robots_state($obs === null ? null : Observations::response($obs), $comparison);
            case 'indexability':
                return self::indexability_facts($ctx, $comparison);
            case 'sitemap':
                return self::sitemap_facts($comparison);
        }

        return self::sitemap_entries_facts($comparison);
    }

    public static function grade(string $id, array $facts, RunContext $ctx): array
    {
        $live = $ctx->profile() === 'live';
        switch ($id) {
            case 'robots_txt':
                return self::grade_robots($facts, $live);
            case 'indexability':
                return self::grade_indexability($facts, $live);
            case 'sitemap':
                return self::grade_sitemap($facts, $live);
        }

        return self::grade_sitemap_entries($facts, $ctx);
    }

    /** sitemap_entries follows at most this many sub-sitemaps. */
    public const MAX_SITEMAPS = 20;

    /** robots.txt lives at the host root, whatever path home has. */
    private static function robots_url(): string
    {
        $home = wp_parse_url(home_url('/'));
        $port = isset($home['port']) ? ':' . (int) $home['port'] : '';

        return strtolower((string) ($home['scheme'] ?? 'https')) . '://' . ($home['host'] ?? '') . $port . '/robots.txt';
    }

    /**
     * parsed | missing (404/410: everything allowed) | unknown. Rule 4 comes
     * before parsing: an HTML page or text that does not read as robots.txt
     * is unknown, and an empty file counts as "everything allowed" only when
     * the batch's comparison answered 404/410 (no soft-404 site).
     *
     * @return array{state:string, disallows_all:bool, reason:string}
     */
    private static function robots_state(?array $r, array $comparison, ?Robots &$robots = null): array
    {
        $robots = null;
        if ($r === null || $r['status'] === 0 && !$r['redirect']) {
            return ['state' => 'unknown', 'disallows_all' => false, 'reason' => __('robots.txt could not be fetched', 'sfxtheme')];
        }
        if ($r['challenge'] || $r['redirect']) {
            return ['state' => 'unknown', 'disallows_all' => false, 'reason' => $r['challenge'] ? __('a security service answered with a challenge page', 'sfxtheme') : __('robots.txt redirects; redirects are not followed', 'sfxtheme')];
        }
        if (in_array($r['status'], [404, 410], true) && (trim($r['body']) === '' || $r['truncated'])) {
            // Rule 4 first: an empty or truncated error answer proves nothing.
            return ['state' => 'unknown', 'disallows_all' => false, 'reason' => __('robots.txt answered with an empty or incomplete error page, so its absence is not established', 'sfxtheme')];
        }
        if (in_array($r['status'], [404, 410], true)) {
            $robots = Robots::parse('');
            return ['state' => 'missing', 'disallows_all' => false, 'reason' => ''];
        }
        if ($r['status'] !== 200 || $r['truncated']) {
            /* translators: %d: HTTP status code */
            return ['state' => 'unknown', 'disallows_all' => false, 'reason' => $r['status'] === 200 ? __('robots.txt is too large to read', 'sfxtheme') : sprintf(__('robots.txt answered with status %d', 'sfxtheme'), $r['status'])];
        }
        if (trim($r['body']) === '') {
            if (!Evidence::comparison_missing($comparison)) {
                return ['state' => 'unknown', 'disallows_all' => false, 'reason' => __('robots.txt is empty, and the comparison address did not answer 404, so the answer proves nothing', 'sfxtheme')];
            }
            // An empty robots.txt (ruling): no rules, everything allowed.
            $robots = Robots::parse('');
            return ['state' => 'parsed', 'disallows_all' => false, 'reason' => ''];
        }
        $parsed = Robots::parse($r['body']);
        $html = stripos($r['headers']['content-type'] ?? '', 'html') !== false || preg_match('/^\s*<(?:!doctype|html|head|body)\b/i', $r['body']) === 1;
        if ($html || !$parsed->recognised()) {
            return ['state' => 'unknown', 'disallows_all' => false, 'reason' => __('the answer is not readable as robots.txt', 'sfxtheme')];
        }
        $robots = $parsed;

        return ['state' => 'parsed', 'disallows_all' => $robots->disallows_all(), 'reason' => ''];
    }

    private static function grade_robots(array $facts, bool $live): array
    {
        $state = $facts['state'] ?? 'unknown';
        if ($state === 'unknown') {
            $f = Observations::finding('robots_txt', '/robots.txt', Status::UNKNOWN, (string) ($facts['reason'] ?? ''));
        } elseif (!empty($facts['disallows_all'])) {
            $f = Observations::finding('robots_txt', '/robots.txt', $live ? Status::RED : Status::GREEN, __('robots.txt blocks the whole site for all search engines', 'sfxtheme'));
        } else {
            $f = Observations::finding('robots_txt', '/robots.txt', $live ? Status::GREEN : Status::HINT, $state === 'missing'
                ? __('no robots.txt (everything may be crawled)', 'sfxtheme')
                : __('robots.txt does not block the whole site', 'sfxtheme'));
        }

        return Observations::result([$f], Status::UNKNOWN, 'browser', __('Groups per user agent; Allow exceptions are respected.', 'sfxtheme'));
    }

    /**
     * The home page plus the run's valid paths. Invalid stored paths are
     * skipped and named (RunContext does not filter them).
     *
     * @return array{0:list<string>, 1:list<array{target:string, reason:string}>}
     */
    public static function index_paths(RunContext $ctx): array
    {
        $paths = ['/'];
        $rejected = [];
        foreach ($ctx->indexability_paths() as $path) {
            $path = (string) $path;
            $refusal = Settings::valid_path($path) ? Fetch::refusal(home_url($path)) : 'invalid';
            if ($refusal !== '') {
                // A valid path the request guard refuses cannot be inspected (Nicht prüfbar); an invalid one is a setting to fix (Hinweis).
                $guarded = $refusal !== 'invalid' && Settings::valid_path($path);
                $reason = $guarded ? Fetch::reason_text($refusal) : __('rejected: not a valid site path; skipped', 'sfxtheme');
                $rejected[] = ['target' => Observations::short($path, Observations::TEXT), 'reason' => $reason, 'refused' => $guarded];
            } elseif (!in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return [$paths, $rejected];
    }

    private static function indexability_facts(RunContext $ctx, array $comparison): array
    {
        [$paths, $rejected] = self::index_paths($ctx);
        $robots = null;
        $robots_state = self::robots_state(Fetch::loopback('GET', self::robots_url()), $comparison, $robots)['state'];
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));

        $targets = [];
        foreach ($paths as $path) {
            $url = home_url($path);
            $r = Fetch::loopback('GET', $url);
            $t = ['target' => $path, 'kind' => 'other', 'status' => $r['status'], 'location' => '', 'noindex' => false, 'foreign_canonical' => false, 'robots_disallowed' => false];

            if ($r['error'] !== '') {
                $t['kind'] = $r['error'] === 'time_limit' ? 'time_limit' : 'error';
            } elseif ($r['challenge']) {
                $t['kind'] = 'challenge';
            } elseif ($r['redirect']) {
                [$t['kind'], $t['location']] = self::redirect_kind(trim($r['location']), (string) wp_parse_url($url, PHP_URL_PATH), $home_host);
            } elseif (in_array($r['status'], [404, 410], true)) {
                $t['kind'] = 'missing';
            } elseif (in_array($r['status'], [401, 503], true)) {
                $t['kind'] = 'closed';
            } elseif ($r['status'] === 200) {
                $html = stripos($r['headers']['content-type'] ?? '', 'html') !== false;
                if (!$html) {
                    $t['kind'] = 'other';
                } elseif (trim($r['body']) === '') {
                    $t['kind'] = 'empty';
                } elseif (!Evidence::comparison_refused($comparison)) {
                    // Rule 4: on a soft-404 site a 200 page proves nothing.
                    $t['kind'] = 'soft404';
                } elseif (!self::readable_html($r['body'], $r['truncated'])) {
                    $t['kind'] = $r['truncated'] ? 'partial' : 'other';
                } elseif (($tags = Observations::html_tags($r['body'])) === null) {
                    // No HTML parser, or the page could not be parsed: no evidence.
                    $t['kind'] = 'other';
                } else {
                    $t['kind'] = 'html';
                    $t['noindex'] = self::noindex($r['headers']['x-robots-tag'] ?? '', $tags);
                    $canonical = self::canonical($tags);
                    $t['foreign_canonical'] = $canonical !== null && $canonical !== '' && $canonical !== $home_host;
                    $t['robots_disallowed'] = $robots !== null && !$robots->allows((string) wp_parse_url($url, PHP_URL_PATH));
                }
            }
            $targets[] = $t;
        }

        return ['robots' => $robots_state, 'targets' => $targets, 'rejected' => $rejected];
    }

    /**
     * Readable as an HTML page: a complete answer with an html or head
     * element. A truncated one never is (ruling, Gate B pass 4): what lies
     * beyond the bytes read could carry noindex or a canonical link.
     */
    private static function readable_html(string $body, bool $truncated): bool
    {
        if ($truncated) {
            // Ruling (Gate B pass 4): metadata of a page read only in part is never evidence.
            return false;
        }

        return preg_match('#<(?:html|head)\b#i', $body) === 1;
    }

    /**
     * Kind of a redirect and its destination as shown. A Location with only
     * scheme and host means the root path. A Location that cannot be read as
     * an absolute URL or an absolute path is no evidence (`bad_redirect`).
     *
     * @return array{0:string, 1:string} kind, destination
     */
    private static function redirect_kind(string $location, string $own_path, string $home_host): array
    {
        $parts = $location === '' ? false : wp_parse_url($location);
        if (!is_array($parts) || (isset($parts['scheme']) && empty($parts['host'])) || (!isset($parts['host']) && $location[0] !== '/')) {
            return ['bad_redirect', ''];
        }
        $path = (string) ($parts['path'] ?? '');
        $path = $path === '' ? '/' : $path;
        $host = strtolower((string) ($parts['host'] ?? $home_host));
        $shown = $host === $home_host ? $path : strtolower((string) ($parts['scheme'] ?? 'https')) . '://' . $host . $path;
        $shown = Observations::short($shown, Observations::TEXT);
        if ($path === $own_path) {
            // Same path on another scheme or host is not "another path": no verdict.
            return ['other', $shown];
        }

        return [strpos($path, 'wp-login.php') !== false ? 'login' : 'redirect', $shown];
    }

    /** @param array{meta:list<array<string,string>>, link:list<array<string,string>>} $tags Observations::html_tags() */
    private static function noindex(string $header, array $tags): bool
    {
        if (preg_match('/\b(?:noindex|none)\b/i', $header) === 1) {
            return true;
        }
        foreach ($tags['meta'] as $a) {
            if (in_array(strtolower(trim($a['name'] ?? '')), ['robots', 'googlebot'], true)
                && preg_match('/\b(?:noindex|none)\b/i', $a['content'] ?? '') === 1) {
                return true;
            }
        }

        return false;
    }

    /** Lower-case host of the canonical link; '' for a relative one; null when there is none. */
    private static function canonical(array $tags): ?string
    {
        foreach ($tags['link'] as $a) {
            $rel = preg_split('/\s+/', strtolower(trim($a['rel'] ?? ''))) ?: [];
            if (in_array('canonical', $rel, true) && isset($a['href'])) {
                return strtolower((string) wp_parse_url(trim($a['href']), PHP_URL_HOST));
            }
        }

        return null;
    }

    private static function grade_indexability(array $facts, bool $live): array
    {
        $robots_read = in_array($facts['robots'] ?? '', ['parsed', 'missing'], true);
        $findings = [];
        foreach ($facts['targets'] ?? [] as $t) {
            [$status, $text] = self::index_status($t, $live, $robots_read);
            $findings[] = Observations::finding('indexability', (string) $t['target'], $status, $t['target'] . ' — ' . $text);
        }
        foreach ($facts['rejected'] ?? [] as $r) {
            $findings[] = Observations::finding('indexability', (string) $r['target'], empty($r['refused']) ? Status::HINT : Status::UNKNOWN, $r['target'] . ' — ' . $r['reason']);
        }

        return Observations::result($findings, Status::UNKNOWN, 'loopback', __('Loopback: the server fetched its own pages without login; redirects were not followed. robots.txt was read in the same run.', 'sfxtheme'));
    }

    /** @return array{0:string, 1:string} */
    private static function index_status(array $t, bool $live, bool $robots_read): array
    {
        switch ($t['kind']) {
            case 'html':
                if (!empty($t['noindex'])) {
                    return $live
                        ? [Status::RED, __('carries noindex', 'sfxtheme')]
                        : [Status::GREEN, __('carries noindex (expected here)', 'sfxtheme')];
                }
                if (!empty($t['foreign_canonical'])) {
                    return [Status::RED, __('the canonical link points to another host', 'sfxtheme')];
                }
                if (!$robots_read) {
                    return [Status::UNKNOWN, __('robots.txt could not be read', 'sfxtheme')];
                }
                if (!empty($t['robots_disallowed'])) {
                    return [Status::YELLOW, __('crawling blocked by robots.txt', 'sfxtheme')];
                }
                return [Status::GREEN, __('can be indexed', 'sfxtheme')];
            case 'missing':
                return [$live ? Status::RED : Status::YELLOW, __('page missing (404/410)', 'sfxtheme')];
            case 'closed':
            case 'login':
                return $live
                    ? [Status::RED, __('not publicly reachable', 'sfxtheme')]
                    : [Status::GREEN, __('not publicly reachable (expected here)', 'sfxtheme')];
            case 'redirect':
                /* translators: %s: redirect target path */
                return [Status::YELLOW, sprintf(__('redirects to %s', 'sfxtheme'), (string) ($t['location'] ?? ''))];
            case 'challenge':
                return [Status::UNKNOWN, __('a security service answered with a challenge page', 'sfxtheme')];
            case 'empty':
                return [Status::UNKNOWN, __('empty answer', 'sfxtheme')];
            case 'soft404':
                return [Status::UNKNOWN, __('the comparison address also answered normally, so this answer proves nothing', 'sfxtheme')];
            case 'bad_redirect':
                return [Status::UNKNOWN, __('redirects to an address that cannot be compared', 'sfxtheme')];
            case 'time_limit':
                return [Status::UNKNOWN, __('not checked: time limit of this run reached', 'sfxtheme')];
        }

        return [Status::UNKNOWN, __('no recognisable answer', 'sfxtheme')];
    }

    /**
     * Sitemap lines of robots.txt, then the usual addresses. Off-host and
     * PHP-named addresses are skipped, never fetched.
     *
     * `unclear` counts candidates that may be a sitemap but gave no reliable
     * answer (no answer, challenge, 401/429/5xx, empty or malformed).
     *
     * @return array{found:?array{url:string, body:string, truncated:bool, kind:string}, via:string, skipped:list<array{target:string, reason:string}>, attempts:int, errors:int, unclear:int}
     */
    private static function discover_sitemap(array $comparison): array
    {
        $out = ['found' => null, 'via' => '', 'skipped' => [], 'attempts' => 0, 'errors' => 0, 'unclear' => 0, 'time_limit' => false];
        $robots = null;
        self::robots_state(Fetch::loopback('GET', self::robots_url()), $comparison, $robots);

        $candidates = [];
        // Every Sitemap: line (bounded by the 64 KB robots.txt read), then the usual addresses.
        foreach ($robots === null ? [] : $robots->sitemaps() as $url) {
            $candidates[] = [$url, 'robots'];
        }
        foreach (['/wp-sitemap.xml', '/sitemap_index.xml', '/sitemap.xml'] as $path) {
            $candidates[] = [home_url($path), 'fallback'];
        }

        foreach ($candidates as [$url, $via]) {
            // Every candidate is guarded and every refusal kept — also after a sitemap was found.
            $refusal = Fetch::refusal($url);
            if ($refusal !== '') {
                $out['skipped'][] = ['target' => Observations::short($url, Observations::TEXT), 'reason' => Fetch::reason_text($refusal)];
                continue;
            }
            if ($out['found'] !== null || $out['time_limit']) {
                continue;
            }
            $r = Fetch::loopback('GET', $url);
            if ($r['error'] === 'time_limit') {
                $out['time_limit'] = true;
                continue;
            }
            $out['attempts']++;
            $kind = self::sitemap_kind($r, $comparison);
            if ($r['error'] !== '') {
                $out['errors']++;
            }
            if ($kind === 'unclear') {
                $out['unclear']++;
            } elseif ($kind !== '') {
                $out['found'] = ['url' => $url, 'body' => $r['body'], 'truncated' => $r['truncated'], 'kind' => $kind];
                $out['via'] = $via;
            }
        }

        return $out;
    }

    /**
     * 'urlset' or 'sitemapindex' when the answer is that sitemap document;
     * 'unclear' when it gives no reliable answer (rule 4: no answer,
     * challenge, 401/429/5xx, empty, or a sitemap root that is not
     * well-formed); '' when it is something else (blocked, missing,
     * redirected, another document). A truncated document counts by its
     * root element and is read in part. On a soft-404 site (comparison not
     * clearly refused) a 200 proves nothing (rule 4): 'unclear'.
     */
    private static function sitemap_kind(array $r, array $comparison): string
    {
        if ($r['error'] !== '' || $r['challenge']) {
            return 'unclear';
        }
        if ($r['redirect']) {
            return '';
        }
        if (in_array($r['status'], [403, 404, 410], true)) {
            // Rule 4 before rule 3: an empty or truncated blocking answer proves no absence.
            return trim($r['body']) === '' || $r['truncated'] ? 'unclear' : '';
        }
        if ($r['status'] !== 200 || trim($r['body']) === '' || !Evidence::comparison_refused($comparison)) {
            return 'unclear';
        }
        $root = Observations::xml_root($r['body']);
        if ($root !== 'urlset' && $root !== 'sitemapindex') {
            return '';
        }
        if (!$r['truncated'] && !Observations::well_formed_xml($r['body'])) {
            return 'unclear';
        }

        return $root;
    }

    private static function sitemap_facts(array $comparison): array
    {
        $d = self::discover_sitemap($comparison);

        return [
            'found'    => $d['found'] === null ? null : Observations::target_of($d['found']['url']),
            'via'      => $d['via'],
            'skipped'  => $d['skipped'],
            'attempts' => $d['attempts'],
            'errors'   => $d['errors'],
            'unclear'  => $d['unclear'],
            'time_limit' => $d['time_limit'],
        ];
    }

    private static function grade_sitemap(array $facts, bool $live): array
    {
        $note = __('Loopback: robots.txt, then /wp-sitemap.xml, /sitemap_index.xml and /sitemap.xml.', 'sfxtheme');
        $skipped = self::skipped_findings('sitemap', $facts['skipped'] ?? [], !empty($facts['found']) ? Status::HINT : Status::UNKNOWN);
        if (!empty($facts['found'])) {
            $text = ($facts['via'] ?? '') === 'robots' ? __('found, named in robots.txt', 'sfxtheme') : __('found', 'sfxtheme');
            $f = Observations::finding('sitemap', (string) $facts['found'], Status::GREEN, $facts['found'] . ' — ' . $text);
        } elseif (!empty($facts['time_limit'])) {
            $f = Observations::finding('sitemap', 'sitemap', Status::UNKNOWN, __('not checked: time limit of this run reached', 'sfxtheme'));
        } elseif (($facts['attempts'] ?? 0) > 0 && ($facts['errors'] ?? 0) === ($facts['attempts'] ?? 0)) {
            $f = Observations::finding('sitemap', 'sitemap', Status::UNKNOWN, __('no answer from the site', 'sfxtheme'));
        } elseif (($facts['unclear'] ?? 0) > 0 || $skipped !== []) {
            // A refused address could have been the sitemap: no "not found" verdict then.
            $f = Observations::finding('sitemap', 'sitemap', Status::UNKNOWN, __('not every address gave a reliable answer (challenge, error or a malformed sitemap)', 'sfxtheme'));
        } else {
            $f = Observations::finding('sitemap', 'sitemap', $live ? Status::YELLOW : Status::HINT, __('no sitemap found', 'sfxtheme'));
        }

        return Observations::result(array_merge([$f], $skipped), Status::UNKNOWN, 'loopback', $note);
    }

    /**
     * Every refused address as a finding of its own (so the saved run's
     * compaction and its overflow line apply), never only in the note.
     *
     * @param list<array{target:string, reason:string}> $skipped
     */
    private static function skipped_findings(string $check, array $skipped, string $status): array
    {
        $findings = [];
        foreach ($skipped as $s) {
            /* translators: 1: address, 2: why it was not fetched */
            $findings[] = Observations::finding($check, 'skipped:' . $s['target'], $status, sprintf(__('%1$s — not fetched: %2$s', 'sfxtheme'), $s['target'], $s['reason']));
        }

        return $findings;
    }

    private static function sitemap_entries_facts(array $comparison): array
    {
        $d = self::discover_sitemap($comparison);
        $facts = ['found' => $d['found'] !== null, 'checked' => 0, 'total' => 0, 'types' => [], 'unmatched' => 0, 'partial' => false, 'time_limit' => $d['time_limit'], 'skipped' => $d['skipped']];
        if ($d['found'] === null) {
            return $facts;
        }

        $counts = [];
        $skipped = [];
        $root = $d['found'];
        if ($root['kind'] === 'sitemapindex') {
            $children = self::locs($root['body'], $root['truncated']);
            $facts['total'] = count($children);
            $followed = 0;
            foreach ($children as $child) {
                $refusal = Fetch::refusal($child);
                if ($refusal !== '') {
                    $skipped[] = ['target' => Observations::short($child, Observations::TEXT), 'reason' => Fetch::reason_text($refusal)];
                    continue;
                }
                if ($followed >= self::MAX_SITEMAPS) {
                    continue;
                }
                $followed++;
                $r = Fetch::loopback('GET', $child);
                if ($r['error'] === 'time_limit') {
                    $facts['time_limit'] = true;
                    break;
                }
                if (!in_array(self::sitemap_kind($r, $comparison), ['urlset', 'sitemapindex'], true)) {
                    continue;
                }
                $facts['checked']++;
                $facts['partial'] = $facts['partial'] || $r['truncated'];
                self::count_entries($child, $r['body'], $counts, $facts['unmatched'], $r['truncated']);
            }
            $facts['partial'] = $facts['partial'] || $root['truncated'];
        } else {
            $facts['total'] = 1;
            $facts['checked'] = 1;
            $facts['partial'] = $root['truncated'];
            self::count_entries($root['url'], $root['body'], $counts, $facts['unmatched'], $root['truncated']);
        }

        $post_types = self::objects('post_types');
        $taxonomies = self::objects('taxonomies');
        // Every type read is kept for grading (bounded by 64 KB per sitemap and 20 sub-sitemaps).
        foreach ($counts as $type => $count) {
            [$kind, $slug] = array_pad(explode(':', $type, 2), 2, '');
            $public = $kind === 'post_type' ? !empty($post_types[$slug]->public) : ($kind === 'taxonomy' ? !empty($taxonomies[$slug]->public) : false);
            $facts['types'][] = ['id' => $type, 'count' => $count, 'public' => $public];
        }
        $facts['skipped'] = array_merge($d['skipped'], $skipped);

        return $facts;
    }

    /** @return list<string> the loc values (any namespace prefix), decoded by the XML parser */
    private static function locs(string $xml, bool $truncated = false): array
    {
        return Observations::xml_locs($xml, $truncated);
    }

    /** Adds the entries of one urlset to $counts by type; entries no rule matches go to $unmatched. */
    private static function count_entries(string $sitemap_url, string $xml, array &$counts, int &$unmatched, bool $truncated = false): void
    {
        $entries = self::locs($xml, $truncated);
        $type = self::type_from_sitemap_name($sitemap_url);
        if ($type !== null) {
            $counts[$type] = ($counts[$type] ?? 0) + count($entries);
            return;
        }
        foreach ($entries as $entry) {
            $type = self::type_from_url($entry);
            if ($type === null) {
                $unmatched++;
            } else {
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
        }
    }

    /** Core (wp-sitemap-*), Yoast and Rank Math (<type>-sitemap.xml) names. */
    private static function type_from_sitemap_name(string $url): ?string
    {
        $name = basename((string) wp_parse_url($url, PHP_URL_PATH));
        if (preg_match('/^wp-sitemap-posts-([a-z0-9_-]+)-\d+\.xml$/', $name, $m) === 1) {
            return self::type_id('post_type', $m[1]);
        }
        if (preg_match('/^wp-sitemap-taxonomies-([a-z0-9_-]+)-\d+\.xml$/', $name, $m) === 1) {
            return 'taxonomy:' . $m[1];
        }
        if (preg_match('/^wp-sitemap-users-\d+\.xml$/', $name) === 1) {
            return 'authors';
        }
        if (preg_match('/^([a-z0-9_-]+)-sitemap\d*\.xml$/', $name, $m) === 1) {
            if ($m[1] === 'author') {
                return 'authors';
            }
            if (isset(self::objects('post_types')[$m[1]])) {
                return self::type_id('post_type', $m[1]);
            }
            if (isset(self::objects('taxonomies')[$m[1]])) {
                return 'taxonomy:' . $m[1];
            }
        }

        return null;
    }

    /** By query (post_type=, attachment_id=, author=) or by the longest rewrite slug prefix. */
    private static function type_from_url(string $url): ?string
    {
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        if (isset($query['post_type']) && is_string($query['post_type'])) {
            return self::type_id('post_type', $query['post_type']);
        }
        if (isset($query['attachment_id']) || isset($query['attachment'])) {
            return 'attachments';
        }
        if (isset($query['author']) || isset($query['author_name'])) {
            return 'authors';
        }

        $home_path = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if ($home_path !== '' && strpos($path, $home_path . '/') === 0) {
            $path = substr($path, strlen($home_path));
        }
        $path = trim($path, '/') . '/';

        $author_base = isset($GLOBALS['wp_rewrite']->author_base) ? (string) $GLOBALS['wp_rewrite']->author_base : 'author';
        $best = [0, null];
        $prefixes = [[$author_base, 'authors']];
        foreach (self::objects('post_types') as $slug => $object) {
            if (!empty($object->rewrite['slug'])) {
                $prefixes[] = [(string) $object->rewrite['slug'], self::type_id('post_type', (string) $slug)];
            }
        }
        foreach (self::objects('taxonomies') as $slug => $object) {
            if (!empty($object->rewrite['slug'])) {
                $prefixes[] = [(string) $object->rewrite['slug'], 'taxonomy:' . $slug];
            }
        }
        foreach ($prefixes as [$prefix, $type]) {
            $prefix = trim($prefix, '/') . '/';
            if ($prefix !== '/' && strpos($path, $prefix) === 0 && strlen($prefix) > $best[0]) {
                $best = [strlen($prefix), $type];
            }
        }

        return $best[1];
    }

    private static function type_id(string $kind, string $slug): string
    {
        return $kind === 'post_type' && $slug === 'attachment' ? 'attachments' : $kind . ':' . $slug;
    }

    /** @return array<string, object> */
    private static function objects(string $what): array
    {
        $objects = $what === 'post_types' ? get_post_types([], 'objects') : get_taxonomies([], 'objects');

        return is_array($objects) ? $objects : [];
    }

    private static function grade_sitemap_entries(array $facts, RunContext $ctx): array
    {
        $checked = (int) ($facts['checked'] ?? 0);
        $total = (int) ($facts['total'] ?? 0);
        /* translators: 1: sitemaps checked, 2: sitemaps in total */
        $note = sprintf(__('%1$d of %2$d sitemaps checked.', 'sfxtheme'), $checked, $total);
        // Refused addresses are coverage: each its own Hinweis line (the coverage note says how many were checked).
        $skipped = self::skipped_findings('sitemap_entries', $facts['skipped'] ?? [], Status::HINT);
        if (!empty($facts['partial'])) {
            $note .= ' ' . __('Some sitemaps were longer than 64 KB and were read only in part.', 'sfxtheme');
        }
        if (empty($facts['found'])) {
            $text = empty($facts['time_limit']) ? __('no sitemap found', 'sfxtheme') : __('not checked: time limit of this run reached', 'sfxtheme');
            return Observations::result(array_merge([Observations::finding('sitemap_entries', 'sitemap', Status::UNKNOWN, $text)], $skipped), Status::UNKNOWN, 'loopback', $note);
        }

        $allow = $ctx->sitemap_allow();
        $findings = [];
        foreach ($facts['types'] ?? [] as $type) {
            $id = (string) $type['id'];
            if ((int) $type['count'] === 0 || in_array($id, $allow, true)) {
                continue;
            }
            $flagged = $id === 'post_type:bricks_template' || $id === 'attachments' || $id === 'authors' || empty($type['public']);
            if ($flagged) {
                /* translators: 1: entry type, 2: number of entries */
                $findings[] = Observations::finding('sitemap_entries', $id, Status::YELLOW, sprintf(__('%1$s: %2$d entries in the sitemap', 'sfxtheme'), $id, (int) $type['count']));
            }
        }
        if (!empty($facts['time_limit'])) {
            $findings[] = Observations::finding('sitemap_entries', 'time-limit', Status::UNKNOWN, __('not every sitemap checked: time limit of this run reached', 'sfxtheme'));
        }
        if (!empty($facts['unmatched'])) {
            /* translators: %d: number of entries */
            $findings[] = Observations::finding('sitemap_entries', 'unmatched', Status::HINT, sprintf(__('%d entries could not be assigned to a type', 'sfxtheme'), (int) $facts['unmatched']));
        }
        if ($findings === [] && $checked === 0) {
            return Observations::result(array_merge([Observations::finding('sitemap_entries', 'sitemap', Status::UNKNOWN, __('no sub-sitemap could be read', 'sfxtheme'))], $skipped), Status::UNKNOWN, 'loopback', $note);
        }
        if ($findings === [] && ($checked < $total || !empty($facts['partial']))) {
            $findings[] = Observations::finding('sitemap_entries', 'coverage', Status::HINT, __('nothing to report in the part that was checked', 'sfxtheme'));
        }

        return Observations::result(array_merge($findings, $skipped), Status::GREEN, 'loopback', $note);
    }
}
