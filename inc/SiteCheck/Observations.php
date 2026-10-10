<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

use SFX\SiteCheck\Checks\OutsideChecks;
use SFX\SiteCheck\Checks\ServerChecks;

/**
 * Browser observations → evidence (spec rules 2–5, 7). Only observations for
 * URLs the plan issued are read; both variants (cache-buster and plain) are
 * classified; nothing of a body leaves this class except signature group
 * names, flags and short, valid-UTF-8 strings.
 */
final class Observations
{
    /** Longest text (URL, header value, name) kept in facts. */
    public const TEXT = 200;

    private const LIST = 5;

    /** Security headers are judged by content: kept whole up to LONG_HEADER bytes, others up to 1 KB. A longer value is cut and named in `headers_cut`. */
    public const LONG_HEADERS = ['content-security-policy', 'content-security-policy-report-only', 'permissions-policy', 'strict-transport-security', 'referrer-policy', 'x-frame-options', 'x-content-type-options'];
    public const LONG_HEADER = 8192;

    /**
     * WAF/CDN challenge markers (rule 4: a challenge is Nicht prüfbar).
     * Headers: Cloudflare `cf-mitigated: challenge`; AWS WAF
     * `x-amzn-waf-action: challenge|captcha`. Body: Cloudflare's challenge
     * platform script and its "Just a moment..." interstitial, Sucuri's
     * CloudProxy JS challenge, Imperva/Incapsula's resource script.
     */
    private const CHALLENGE_BODY = [
        '#/cdn-cgi/challenge-platform/#',
        '#window\._cf_chl_opt#',
        '#<title>\s*Just a moment\.\.\.\s*</title>#i',
        '#sucuri_cloudproxy_js#',
        '#_Incapsula_Resource#',
    ];

    /**
     * Issued observations by URL and variant (`busted` carries the cache
     * buster, `plain` does not). Anything else is dropped.
     *
     * @return array<string, array{busted?:array, plain?:array}>
     */
    public static function index_observations(array $observations, array $plan): array
    {
        $issued = [];
        foreach ($plan['issued'] as $c) {
            $issued[$c['url']] = true;
        }
        $seen = [];
        foreach ($observations as $raw) {
            if (!is_array($raw) || !isset($raw['url']) || !is_string($raw['url'])) {
                continue;
            }
            $url = $raw['url'];
            $base = Finding::without_cache_buster($url);
            $variant = $base === $url ? 'plain' : 'busted';
            $known = isset($issued[$base]) || ($variant === 'plain' && $base === $plan['comparison']);
            if (!$known || isset($seen[$base][$variant])) {
                continue;
            }
            $seen[$base][$variant] = self::clean_observation($raw);
        }

        return $seen;
    }

    /** Bounded, typed copy of one browser observation. */
    private static function clean_observation(array $raw): array
    {
        $headers = [];
        $cut = [];
        if (isset($raw['headers']) && is_array($raw['headers'])) {
            foreach (array_slice($raw['headers'], 0, 50, true) as $name => $value) {
                if (is_string($name) && is_scalar($value)) {
                    $name = strtolower($name);
                    $limit = in_array($name, self::LONG_HEADERS, true) ? self::LONG_HEADER : 1024;
                    $value = (string) $value;
                    if (strlen($value) > $limit) {
                        $cut[] = $name;
                    }
                    $headers[$name] = substr($value, 0, $limit);
                }
            }
        }
        $hex = isset($raw['head_hex']) && is_string($raw['head_hex']) ? strtolower(substr($raw['head_hex'], 0, 1024)) : '';

        return [
            'status'    => isset($raw['status']) && is_numeric($raw['status']) ? (int) $raw['status'] : 0,
            'headers'   => $headers,
            'headers_cut' => $cut,
            'body'      => isset($raw['body']) && is_string($raw['body']) ? substr($raw['body'], 0, Fetch::LIMIT) : '',
            'head_hex'  => preg_match('/^[0-9a-f]*$/', $hex) === 1 ? $hex : '',
            'truncated' => !empty($raw['truncated']),
            'redirect'  => !empty($raw['redirect']) || (isset($raw['type']) && $raw['type'] === 'opaqueredirect'),
            'error'     => isset($raw['error']) && is_string($raw['error']) ? substr($raw['error'], 0, 20) : '',
        ];
    }

    /** The shape Evidence::classify() takes. An error is "no answer". */
    public static function response(array $obs): array
    {
        if ($obs['error'] !== '') {
            return ['status' => 0, 'body' => '', 'truncated' => false, 'redirect' => false, 'challenge' => false, 'headers' => []];
        }

        return [
            'status'    => $obs['status'],
            'body'      => $obs['body'],
            'truncated' => $obs['truncated'],
            'redirect'  => $obs['redirect'],
            'challenge' => self::challenge($obs['headers'], $obs['body']),
            'headers'   => $obs['headers'],
        ];
    }

    public static function challenge(array $headers, string $body): bool
    {
        if (strtolower(trim($headers['cf-mitigated'] ?? '')) === 'challenge') {
            return true;
        }
        if (in_array(strtolower(trim($headers['x-amzn-waf-action'] ?? '')), ['challenge', 'captcha'], true)) {
            return true;
        }
        foreach (self::CHALLENGE_BODY as $pattern) {
            if (preg_match($pattern, $body) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function comparison_response(array $seen, ?string $url): array
    {
        $obs = $url === null ? null : ($seen[$url]['plain'] ?? null);
        if ($obs === null || $obs['error'] !== '') {
            return ['status' => 0];
        }

        return ['status' => $obs['status'], 'redirect' => $obs['redirect']];
    }

    /** The plain fetch (what visitors get) when it answered, else the cache-busted one. */
    public static function preferred(array $variants): ?array
    {
        foreach (['plain', 'busted'] as $v) {
            if (isset($variants[$v]) && $variants[$v]['error'] === '' && $variants[$v]['status'] > 0) {
                return $variants[$v];
            }
        }

        return $variants['plain'] ?? $variants['busted'] ?? null;
    }

    /**
     * Both variants classified; the strongest evidence wins: a signature,
     * then "served", then "no reliable answer", then "not reachable". A
     * missing variant is no reliable answer.
     *
     * @param list<string> $groups signature groups of Data/signatures.php
     * @return array{0:string, 1:?string} classify() result, signature group
     */
    public static function outside(array $variants, array $comparison, array $groups): array
    {
        $rank = [Evidence::SIGNATURE => 4, Evidence::OK200 => 3, Evidence::INDETERMINATE => 2, Evidence::UNREACHABLE => 1];
        $best = null;
        $group = null;
        foreach (['busted', 'plain'] as $v) {
            $obs = $variants[$v] ?? null;
            if ($obs === null) {
                $result = Evidence::INDETERMINATE;
            } else {
                $g = $obs['error'] === '' ? self::signature_group($obs, $groups) : null;
                if ($g !== null && ($group === null || $group === 'archive')) {
                    $group = $g;
                }
                $result = $g !== null ? Evidence::SIGNATURE : Evidence::classify(self::response($obs), $comparison);
            }
            if ($best === null || $rank[$result] > $rank[$best]) {
                $best = $result;
            }
        }

        return [$best, $group];
    }

    /** First group whose signature matches: `archive` on head_hex, the rest on the body text. */
    private static function signature_group(array $obs, array $groups): ?string
    {
        $signatures = self::signatures();
        foreach ($groups as $group) {
            $subject = $group === 'archive' ? $obs['head_hex'] : $obs['body'];
            if (isset($signatures[$group]) && Evidence::signature($subject, $signatures[$group]) !== null) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Lower-case local name of the document's first element (after an XML
     * declaration, comments, a doctype and whitespace), or null when the
     * text does not start like an XML/HTML document.
     */
    public static function xml_root(string $body): ?string
    {
        if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) {
            $body = substr($body, 3);
        }
        if (preg_match('/^\s*(?:<\?xml\b[^>]*\?>\s*)?(?:(?:<!--.*?-->|<!DOCTYPE[^>]*>|<\?[^>]*\?>)\s*)*<([A-Za-z_][\w.:-]*)/s', $body, $m) !== 1) {
            return null;
        }
        $name = strtolower($m[1]);
        $colon = strrpos($name, ':');

        return $colon === false ? $name : substr($name, $colon + 1);
    }

    /**
     * Attributes of every `meta` and `link` element of an HTML text, read by
     * libxml's HTML parser (DOMDocument): comments and script text are not
     * markup, attribute values may be quoted or not, entities are decoded,
     * names are lower-case. Input is only the bytes read (≤ 64 KB); no
     * network (LIBXML_NONET). Null when DOM is unavailable or parsing fails —
     * the caller then has no evidence.
     *
     * @return array{meta:list<array<string,string>>, link:list<array<string,string>>}|null
     */
    public static function html_tags(string $body): ?array
    {
        if (!class_exists(\DOMDocument::class) || trim($body) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // The XML declaration makes libxml read the bytes as UTF-8.
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false) {
            return null;
        }
        $out = ['meta' => [], 'link' => []];
        foreach (['meta', 'link'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $element) {
                $attrs = [];
                foreach ($element->attributes ?? [] as $attr) {
                    $name = strtolower($attr->name);
                    $attrs[$name] = $attrs[$name] ?? (string) $attr->value;
                }
                $out[$tag][] = $attrs;
            }
        }

        return $out;
    }

    /**
     * The text of every `loc` element, whatever its namespace prefix
     * (`<loc>`, `<sm:loc>`), read by expat with namespace processing (no
     * external entities, no network). A truncated document yields the
     * elements before the cut. Entities and CDATA are decoded by the parser.
     *
     * @return list<string>
     */
    public static function xml_locs(string $body, bool $truncated): array
    {
        if (!function_exists('xml_parser_create_ns')) {
            return [];
        }
        $parser = xml_parser_create_ns('UTF-8', ' ');
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        $locs = [];
        $buffer = null;
        $local = static function (string $name): string {
            $pos = strrpos($name, ' ');
            return $pos === false ? $name : substr($name, $pos + 1);
        };
        xml_set_element_handler(
            $parser,
            static function ($p, string $name) use (&$buffer, $local): void {
                if ($local($name) === 'loc') {
                    $buffer = '';
                }
            },
            static function ($p, string $name) use (&$buffer, &$locs, $local): void {
                if ($local($name) === 'loc' && $buffer !== null) {
                    $locs[] = trim($buffer);
                    $buffer = null;
                }
            }
        );
        xml_set_character_data_handler($parser, static function ($p, string $data) use (&$buffer): void {
            if ($buffer !== null) {
                $buffer .= $data;
            }
        });
        xml_parse($parser, $body, !$truncated);

        return array_values(array_filter($locs, static fn(string $l): bool => $l !== ''));
    }

    /**
     * The feed's own generator, read by expat with namespaces (no external
     * entities, no network): RSS `rss/channel/generator` (also RSS 1.0
     * `RDF/channel/generator`) or Atom `feed/generator` — text plus its
     * `version` and `uri` attributes, CDATA and entities decoded, comments
     * not read. A truncated feed yields what lies before the cut.
     *
     * @return list<string>
     */
    public static function feed_generators(string $body, bool $truncated): array
    {
        if (!function_exists('xml_parser_create_ns')) {
            return [];
        }
        $parser = xml_parser_create_ns('UTF-8', ' ');
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        $stack = [];
        $found = [];
        $buffer = null;
        $local = static function (string $name): string {
            $pos = strrpos($name, ' ');
            return strtolower($pos === false ? $name : substr($name, $pos + 1));
        };
        $own = static function (array $stack): bool {
            return in_array($stack, [['rss', 'channel', 'generator'], ['rdf', 'channel', 'generator'], ['feed', 'generator']], true);
        };
        xml_set_element_handler(
            $parser,
            static function ($p, string $name, array $attrs) use (&$stack, &$buffer, $local, $own): void {
                $stack[] = $local($name);
                if ($own($stack)) {
                    $parts = [];
                    foreach ($attrs as $key => $value) {
                        if (in_array($local((string) $key), ['version', 'uri'], true)) {
                            $parts[] = (string) $value;
                        }
                    }
                    $buffer = implode(' ', $parts) . ' ';
                }
            },
            static function ($p, string $name) use (&$stack, &$buffer, &$found, $own): void {
                if ($buffer !== null && $own($stack)) {
                    $found[] = trim($buffer);
                    $buffer = null;
                }
                array_pop($stack);
            }
        );
        xml_set_character_data_handler($parser, static function ($p, string $data) use (&$buffer): void {
            if ($buffer !== null) {
                $buffer .= $data;
            }
        });
        xml_parse($parser, $body, !$truncated);
        if ($buffer !== null) {
            $found[] = trim($buffer); // cut inside the generator: what was read counts
        }

        return $found;
    }

    /**
     * Author names a feed publishes: Dublin Core `creator` elements (any
     * prefix, attributes allowed) and Atom `author/name`, read by expat with
     * namespaces — CDATA and entities decoded, comments not read. A truncated
     * feed yields what lies before the cut.
     *
     * @return list<string>
     */
    public static function feed_authors(string $body, bool $truncated): array
    {
        if (!function_exists('xml_parser_create_ns')) {
            return [];
        }
        $parser = xml_parser_create_ns('UTF-8', ' ');
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        $stack = [];
        $names = [];
        $buffer = null;
        $wanted = static function (array $stack): bool {
            $n = count($stack);
            $last = $stack[$n - 1] ?? '';
            return $last === 'http://purl.org/dc/elements/1.1/ creator'
                || (substr($last, -5) === ' name' && substr($stack[$n - 2] ?? '', -7) === ' author')
                || ($last === 'name' && ($stack[$n - 2] ?? '') === 'author');
        };
        xml_set_element_handler(
            $parser,
            static function ($p, string $name) use (&$stack, &$buffer, $wanted): void {
                $stack[] = $name;
                if ($wanted($stack)) {
                    $buffer = '';
                }
            },
            static function ($p, string $name) use (&$stack, &$buffer, &$names, $wanted): void {
                if ($buffer !== null && $wanted($stack)) {
                    $names[] = trim($buffer);
                    $buffer = null;
                }
                array_pop($stack);
            }
        );
        xml_set_character_data_handler($parser, static function ($p, string $data) use (&$buffer): void {
            if ($buffer !== null) {
                $buffer .= $data;
            }
        });
        xml_parse($parser, $body, !$truncated);

        return array_values(array_filter($names, static fn(string $n): bool => $n !== ''));
    }

    /** Well-formed XML (expat; external entities are never loaded). False when no parser is available. */
    public static function well_formed_xml(string $body): bool
    {
        if (!function_exists('xml_parser_create')) {
            return false;
        }
        return xml_parse(xml_parser_create('UTF-8'), $body, true) === 1;
    }

    /** At most $max bytes, always valid UTF-8 (a cut or broken sequence becomes U+FFFD), so facts encode as JSON. */
    public static function short(string $text, int $max): string
    {
        $cut = strlen($text) > $max;
        $text = htmlspecialchars_decode(htmlspecialchars($cut ? substr($text, 0, $max) : $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_NOQUOTES);

        return $cut ? $text . '…' : $text;
    }

    private static function signatures(): array
    {
        static $signatures = null;
        if ($signatures === null) {
            $signatures = require __DIR__ . '/Data/signatures.php';
        }

        return $signatures;
    }

    /** Site-relative path (and query) of a URL: the finding target. */
    public static function target_of(string $url): string
    {
        $parts = wp_parse_url($url);
        $target = (string) ($parts['path'] ?? '/');
        if (isset($parts['query']) && $parts['query'] !== '') {
            $target .= '?' . $parts['query'];
        }

        return self::short(rawurldecode($target), self::TEXT);
    }

    public static function skipped_note(array $skipped): string
    {
        if ($skipped === []) {
            return '';
        }
        $items = array_map(static fn(array $s): string => $s['target'] . ' (' . $s['reason'] . ')', $skipped);

        return ' ' . __('Skipped:', 'sfxtheme') . ' ' . implode('; ', $items);
    }

    /** @return list<array{target:string, reason:string}> */
    public static function cap_list(array $list): array
    {
        return array_slice($list, 0, self::LIST);
    }

    public static function limit_text(): string
    {
        /* translators: %d: number of fetches per check */
        return sprintf(__('not checked from outside: a run fetches at most %d addresses per check', 'sfxtheme'), OutsideChecks::MAX_TARGETS);
    }

    /** @return array{id:string,status:string,label:string} */
    public static function finding(string $check, string $target, string $status, string $label): array
    {
        return ServerChecks::finding($check, $target, $status, $label);
    }

    public static function result(array $findings, string $empty, string $perspective, string $note): array
    {
        return ServerChecks::result($findings, $empty, $note, $perspective);
    }
}
