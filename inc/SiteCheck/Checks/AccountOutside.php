<?php

declare(strict_types=1);

namespace SFX\SiteCheck\Checks;

use SFX\SiteCheck\Evidence;
use SFX\SiteCheck\Fetch;
use SFX\SiteCheck\Observations;
use SFX\SiteCheck\RunContext;
use SFX\SiteCheck\Status;

/**
 * usernames_public (Browser plus Loopback for ?author=1), xmlrpc and the
 * outside half of https (Loopback).
 */
final class AccountOutside
{
    /** @return list<array{url:string, target:string, kind:string}> */
    public static function candidates(string $id, RunContext $ctx): array
    {
        if ($id !== 'usernames_public') {
            return [];
        }
        $embed = rest_url('oembed/1.0/embed');
        $list = [];
        foreach ([
            'rest'    => rest_url('wp/v2/users'),
            'sitemap' => home_url('/wp-sitemap-users-1.xml'),
            'feed'    => get_feed_link(),
            'oembed'  => $embed . (strpos($embed, '?') === false ? '?' : '&') . 'url=' . rawurlencode(home_url('/')),
        ] as $kind => $url) {
            $list[] = ['url' => $url, 'target' => Observations::target_of($url), 'kind' => $kind];
        }

        return $list;
    }

    public static function observe(string $id, RunContext $ctx, array $plan, array $seen, array $comparison): array
    {
        if ($id === 'https') {
            return array_merge(ServerChecks::observe('https', $ctx), self::https_facts());
        }

        return $id === 'xmlrpc' ? self::xmlrpc_facts($comparison) : self::usernames_facts($ctx, $plan, $seen, $comparison);
    }

    public static function grade(string $id, array $facts, RunContext $ctx): array
    {
        return $id === 'xmlrpc' ? self::grade_xmlrpc($facts) : self::grade_usernames($facts);
    }

    public const XMLRPC_BODY = '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName><params></params></methodCall>';

    /**
     * A source is `read` only when its answer is complete, has the source's
     * own format (JSON user list, sitemap, feed, oEmbed object) and the
     * comparison rules out a soft-404 site (rules 1 and 4). Names found in
     * any served answer still count as matches, read or not.
     */
    private static function usernames_facts(RunContext $ctx, array $plan, array $seen, array $comparison): array
    {
        $names = [];
        $sources = [];
        $reasons = [];
        // A source the plan did not fetch (rule 6, Locations) stays unknown, with its reason.
        foreach ($plan['skipped'] as $skip) {
            foreach (self::candidates('usernames_public', $ctx) as $c) {
                if ($c['target'] === $skip['target']) {
                    $sources[$c['kind']] = 'unknown';
                    $reasons[$c['kind']] = Observations::short((string) $skip['reason'], Observations::TEXT);
                }
            }
        }
        foreach ($plan['issued'] as $c) {
            $state = 'unknown';
            $closed = 0;
            $variants = $seen[$c['url']] ?? [];
            foreach ($variants as $obs) {
                // A challenge page is neither closed nor read (rule 4).
                if ($obs['error'] !== '' || Observations::challenge($obs['headers'], $obs['body'])) {
                    continue;
                }
                // 401 is no evidence (rule 4): neither closed nor read.
                // Rule 4 before rule 3: an empty or truncated status answer is no evidence.
                if ($obs['redirect'] || (in_array($obs['status'], [403, 404, 410], true) && $obs['body'] !== '' && !$obs['truncated'])) {
                    $closed++;
                    continue;
                }
                if ($obs['status'] === 200 && $obs['body'] !== '') {
                    if (!$obs['truncated'] && self::source_format($c['kind'], $obs['body']) && Evidence::comparison_refused($comparison)) {
                        $state = 'read';
                    }
                    foreach (self::names_in($obs['body'], $c['kind'] === 'feed', (bool) $obs['truncated']) as $name) {
                        $names[$name][$c['kind']] = true;
                    }
                }
            }
            if ($state !== 'read' && $variants !== [] && $closed === count($variants)) {
                $state = 'closed';
            }
            $sources[$c['kind']] = $state;
        }

        // `?author=1` reveals the slug in its redirect target: Loopback (rule 8),
        // judged against a comparison fetched from the same perspective (rule 2).
        $loopback_comparison = OutsideChecks::loopback_comparison('usernames_public', $ctx);
        $r = Fetch::loopback('GET', home_url('/?author=1'));
        if ($r['error'] !== '' || $r['challenge']) {
            $sources['author_redirect'] = 'unknown';
        } elseif (in_array($r['status'], [403, 404, 410], true) && $r['body'] !== '' && !$r['truncated']) {
            $sources['author_redirect'] = 'closed';
        } elseif (in_array($r['status'], [403, 404, 410], true)) {
            $sources['author_redirect'] = 'unknown';
        } elseif (!$r['redirect'] && $r['status'] !== 200) {
            $sources['author_redirect'] = 'unknown';
        } else {
            // A redirect is read from its Location; a served page only when complete and no soft-404.
            $sources['author_redirect'] = $r['redirect'] || ($r['body'] !== '' && !$r['truncated'] && Evidence::comparison_refused($loopback_comparison)) ? 'read' : 'unknown';
            foreach (self::names_in($r['location'] . "\n" . $r['body']) as $name) {
                $names[$name]['author_redirect'] = true;
            }
        }

        // Exact comparison with real logins, every name read (bounded by the 64 KB per source),
        // looked up in batches; the logins themselves stay here.
        $matches = [];
        foreach (array_chunk(array_map('strval', array_keys($names)), 500) as $chunk) {
            foreach (get_users(['login__in' => $chunk, 'fields' => ['ID', 'user_login']]) as $user) {
                $login = (string) $user->user_login;
                if (isset($names[$login]) && in_array($login, $chunk, true)) {
                    $matches[(int) $user->ID] = array_merge($matches[(int) $user->ID] ?? [], array_keys($names[$login]));
                }
            }
        }
        $hints = [];
        foreach (get_users(['number' => 1000, 'fields' => ['ID', 'user_login', 'display_name']]) as $user) {
            if (count($hints) < 20 && !isset($matches[(int) $user->ID]) && (string) $user->display_name === (string) $user->user_login) {
                $hints[] = (int) $user->ID;
            }
        }

        $list = [];
        // Every confirmed match is kept; the saved run's compaction handles display.
        foreach ($matches as $id => $found_in) {
            $list[] = ['user' => $id, 'sources' => array_values(array_unique($found_in))];
        }

        return ['sources' => $sources, 'reasons' => $reasons, 'matches' => $list, 'hints' => $hints];
    }

    /** The answer has the format its source serves. */
    private static function source_format(string $kind, string $body): bool
    {
        if ($kind === 'rest' || $kind === 'oembed') {
            // Decoded with objects kept apart from lists ({} is no list).
            $data = json_decode($body);
            if ($kind === 'oembed') {
                // An oEmbed response: an object with type and version, or its author fields.
                return $data instanceof \stdClass && ((isset($data->type, $data->version) && is_string($data->type))
                    || (isset($data->author_name) && is_string($data->author_name)) || (isset($data->author_url) && is_string($data->author_url)));
            }
            if (!is_array($data)) {
                return false;
            }
            foreach ($data as $user) {
                // A REST user: an object with an integer id and a string slug or name.
                if (!$user instanceof \stdClass || !isset($user->id) || !is_int($user->id)
                    || !((isset($user->slug) && is_string($user->slug)) || (isset($user->name) && is_string($user->name)))) {
                    return false;
                }
            }
            return true;
        }
        $root = Observations::xml_root($body);
        $roots = $kind === 'sitemap' ? ['urlset'] : ['rss', 'feed', 'rdf'];

        return in_array($root, $roots, true) && Observations::well_formed_xml($body);
    }

    /** @return list<string> candidate names in a REST, sitemap, feed or oEmbed answer */
    /** Slugs of author archive URLs in a text, under the site's own author base (and the default one). */
    private static function author_slugs(string $text): array
    {
        $bases = ['author'];
        if (isset($GLOBALS['wp_rewrite']->author_base) && is_string($GLOBALS['wp_rewrite']->author_base) && $GLOBALS['wp_rewrite']->author_base !== '') {
            $bases[] = trim($GLOBALS['wp_rewrite']->author_base, '/');
        }
        $bases = implode('|', array_map(static fn(string $b): string => preg_quote($b, '#'), array_unique($bases)));
        $slugs = [];
        if (preg_match_all('#/(?:' . $bases . ')/([^/"\'?\#<>\s\\\\]{1,100})#', $text, $m)) {
            foreach ($m[1] as $raw) {
                $slugs[] = rawurldecode($raw);
            }
        }

        return $slugs;
    }

    private static function names_in(string $text, bool $feed = false, bool $truncated = false): array
    {
        $names = [];
        if (preg_match_all('/"(?:slug|name|author_name)"\s*:\s*"((?:[^"\\\\]|\\\\.){1,200})"/', $text, $m)) {
            foreach ($m[1] as $raw) {
                $decoded = json_decode('"' . $raw . '"');
                if (is_string($decoded)) {
                    $names[] = $decoded;
                }
            }
        }
        // JSON answers (REST, oEmbed): names and author URLs from the decoded values,
        // so escaped slashes (`https:\/\/…`) hide nothing.
        $data = json_decode($text, true);
        if (is_array($data)) {
            array_walk_recursive($data, static function ($value, $key) use (&$names): void {
                if (!is_string($value) || !is_string($key)) {
                    return;
                }
                if (in_array($key, ['slug', 'name', 'author_name'], true)) {
                    $names[] = $value;
                } elseif (in_array($key, ['link', 'url', 'author_url'], true)) {
                    $names = array_merge($names, self::author_slugs($value));
                }
            });
        }
        $names = array_merge($names, self::author_slugs($text));
        if (preg_match_all('#[?&](?:amp;)?author_name=([^&"\'<>\s]{1,100})#', $text, $m)) {
            foreach ($m[1] as $raw) {
                $names[] = rawurldecode($raw);
            }
        }
        // Feeds: dc:creator and Atom author/name, read by the XML parser (namespaces, CDATA, entities; no comments).
        if ($feed) {
            foreach (Observations::feed_authors($text, $truncated) as $name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique(array_filter($names, static fn(string $n): bool => $n !== '')));
    }

    private static function grade_usernames(array $facts): array
    {
        $labels = [
            'rest'            => __('REST API user list', 'sfxtheme'),
            'sitemap'         => __('author sitemap', 'sfxtheme'),
            'feed'            => __('feed', 'sfxtheme'),
            'oembed'          => __('oEmbed', 'sfxtheme'),
            'author_redirect' => __('?author=1 redirect', 'sfxtheme'),
        ];
        $findings = [];
        foreach ($facts['matches'] ?? [] as $m) {
            $where = implode(', ', array_map(static fn($s) => $labels[$s] ?? (string) $s, $m['sources'] ?? []));
            /* translators: 1: user ID, 2: where it was found */
            $findings[] = Observations::finding('usernames_public', 'user-' . (int) $m['user'], Status::YELLOW, sprintf(__('User #%1$d: the login name is visible to guests (%2$s)', 'sfxtheme'), (int) $m['user'], $where));
        }
        foreach ($facts['sources'] ?? [] as $source => $state) {
            if ($state === 'unknown') {
                $why = (string) ($facts['reasons'][$source] ?? '');
                $findings[] = Observations::finding('usernames_public', (string) $source, Status::UNKNOWN, ($labels[$source] ?? (string) $source) . ': ' . ($why !== '' ? $why : __('no reliable answer', 'sfxtheme')));
            }
        }
        foreach ($facts['hints'] ?? [] as $id) {
            /* translators: %d: user ID */
            $findings[] = Observations::finding('usernames_public', 'user-' . (int) $id, Status::HINT, sprintf(__('User #%d: the display name equals the login name; not found publicly', 'sfxtheme'), (int) $id));
        }

        return Observations::result($findings, Status::GREEN, 'browser', __('Your browser fetched the public sources without login; the server compared what they show with the real login names, which never leave the server. ?author=1 is fetched by Loopback.', 'sfxtheme'));
    }

    /** @return array{tls:string, http_redirect:string} ok | fail | unknown | skipped */
    private static function https_facts(): array
    {
        $home = wp_parse_url(home_url('/'));
        $scheme = strtolower((string) ($home['scheme'] ?? ''));
        $host = (string) ($home['host'] ?? '');
        $path = (string) ($home['path'] ?? '/');

        $tls = 'skipped';
        if ($scheme === 'https') {
            $r = Fetch::loopback('GET', home_url('/'));
            $tls = $r['error'] === 'tls' ? 'fail' : ($r['error'] === '' ? 'ok' : 'unknown');
        }

        // The one stated exception to the fetchable-URL rule: http:// of the home host.
        $port = $scheme === 'http' && isset($home['port']) ? ':' . (int) $home['port'] : '';
        $r = Fetch::remote('GET', 'http://' . $host . $port . $path);
        if ($r['error'] !== '') {
            $redirect = 'unknown';
        } else {
            $target = wp_parse_url($r['location']);
            $redirect = $r['redirect'] && is_array($target)
                && strtolower((string) ($target['scheme'] ?? '')) === 'https'
                && strtolower((string) ($target['host'] ?? '')) === strtolower($host) ? 'ok' : 'fail';
        }

        return ['tls' => $tls, 'http_redirect' => $redirect];
    }

    /** A 200 answer counts only when the batch's comparison clearly did not serve a page (rule 4). */
    private static function xmlrpc_facts(array $comparison): array
    {
        $r = Fetch::loopback('POST', site_url('/xmlrpc.php'), self::XMLRPC_BODY);
        if ($r['error'] !== '') {
            return ['state' => 'unknown', 'status' => 0];
        }
        // A challenge says nothing about XML-RPC, whatever its status (rule 4).
        if ($r['challenge']) {
            $state = 'other';
        } elseif (in_array($r['status'], [403, 404, 405, 410], true) && $r['body'] !== '' && !$r['truncated']) {
            $state = 'blocked';
        } elseif ($r['status'] === 200 && !Evidence::comparison_refused($comparison)) {
            $state = 'other';
        } elseif ($r['status'] === 200 && strpos($r['body'], '<methodResponse') !== false && strpos($r['body'], '<fault>') !== false) {
            $state = 'fault';
        } elseif ($r['status'] === 200 && preg_match('#<methodResponse>\s*<params>.*<string>[a-z]+\.[A-Za-z.]+</string>#s', $r['body']) === 1) {
            $state = 'answers';
        } else {
            $state = 'other';
        }

        return ['state' => $state, 'status' => $r['status']];
    }

    private static function grade_xmlrpc(array $facts): array
    {
        $note = __('Loopback: one anonymous system.listMethods call to xmlrpc.php; no pingback.', 'sfxtheme');
        switch ($facts['state'] ?? '') {
            case 'answers':
                $f = Observations::finding('xmlrpc', '/xmlrpc.php', Status::YELLOW, __('XML-RPC answers anonymous requests', 'sfxtheme'));
                break;
            case 'blocked':
                /* translators: %d: HTTP status code */
                $f = Observations::finding('xmlrpc', '/xmlrpc.php', Status::GREEN, sprintf(__('XML-RPC is blocked (status %d)', 'sfxtheme'), (int) $facts['status']));
                break;
            case 'fault':
                $f = Observations::finding('xmlrpc', '/xmlrpc.php', Status::UNKNOWN, __('XML-RPC answered with an error; whether it is usable is unclear', 'sfxtheme'));
                break;
            default:
                $f = Observations::finding('xmlrpc', '/xmlrpc.php', Status::UNKNOWN, __('no recognisable answer', 'sfxtheme'));
        }

        return Observations::result([$f], Status::UNKNOWN, 'loopback', $note);
    }
}
