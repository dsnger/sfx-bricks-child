<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * A robots.txt parser after RFC 9309: rules belong to groups of user-agent
 * lines, a crawler uses the groups naming it (else the `*` groups), and the
 * longest matching Allow/Disallow pattern decides (Allow on a tie). `Sitemap:`
 * lines are global. Pure: it never fetches.
 */
final class Robots
{
    /** @var array<string, list<array{allow:bool, pattern:string}>> lower-case agent => rules */
    private array $groups = [];
    /** @var list<string> */
    private array $sitemaps = [];
    private bool $recognised = false;

    /** Fields of robots.txt as crawlers use them; a body without any reads as something else. */
    private const FIELDS = ['user-agent', 'allow', 'disallow', 'sitemap', 'crawl-delay', 'host', 'clean-param', 'request-rate', 'visit-time', 'content-signal'];

    public static function parse(string $txt): self
    {
        $robots = new self();
        $agents = [];
        $in_rules = false;

        if (strncmp($txt, "\xEF\xBB\xBF", 3) === 0) {
            $txt = substr($txt, 3);
        }
        $comments = 0;
        $other = 0;
        $fields = 0;
        foreach (preg_split('/\r\n|\r|\n/', $txt) ?: [] as $line) {
            if (trim($line) !== '' && ltrim($line)[0] === '#') {
                $comments++;
            }
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '') {
                continue;
            }
            if (strpos($line, ':') === false) {
                $other++;
                continue;
            }
            [$field, $value] = explode(':', $line, 2);
            $field = strtolower(trim($field));
            $value = trim($value);
            if (in_array($field, self::FIELDS, true)) {
                $fields++;
            } else {
                $other++;
            }

            if ($field === 'sitemap') {
                if ($value !== '') {
                    $robots->sitemaps[] = $value;
                }
                continue;
            }
            if ($field === 'user-agent') {
                if ($in_rules) {
                    $agents = [];
                    $in_rules = false;
                }
                $agent = strtolower($value);
                $agents[] = $agent;
                $robots->groups[$agent] = $robots->groups[$agent] ?? [];
                continue;
            }
            if ($field !== 'allow' && $field !== 'disallow') {
                continue;
            }
            $in_rules = true;
            // An empty value is no rule; rules before any user-agent line belong to nobody.
            if ($value === '' || $agents === []) {
                continue;
            }
            foreach ($agents as $agent) {
                $robots->groups[$agent][] = ['allow' => $field === 'allow', 'pattern' => $value];
            }
        }
        $robots->recognised = $fields > 0 || ($comments > 0 && $other === 0);

        return $robots;
    }

    /**
     * The body reads as robots.txt: at least one robots field, or comments
     * only. An HTML page or unrelated text does not (rule 4: unparseable).
     */
    public function recognised(): bool
    {
        return $this->recognised;
    }

    /** Whether the crawler may fetch this path (with query, if any). */
    public function allows(string $path, string $agent = '*'): bool
    {
        $best = null;
        foreach ($this->rules($agent) as $rule) {
            if (!self::matches($rule['pattern'], $path)) {
                continue;
            }
            // Specificity in the same normalised octet form the match uses.
            $length = strlen(self::normalise($rule['pattern']));
            if ($best === null || $length > $best[0] || ($length === $best[0] && $rule['allow'])) {
                $best = [$length, $rule['allow']];
            }
        }

        return $best === null || $best[1];
    }

    /**
     * Every path is disallowed: a Disallow rule matches every path (`/`,
     * `/*`, `*`; `/$` matches only the home page), the root is disallowed,
     * and no Allow rule wins for the literal prefix it names. An Allow that only reaches into
     * /wp-admin/ (WordPress' default admin-ajax.php line) does not reopen
     * the site.
     */
    public function disallows_all(string $agent = '*'): bool
    {
        if ($this->allows('/', $agent)) {
            return false;
        }
        $covers_all = false;
        foreach ($this->rules($agent) as $rule) {
            if (!$rule['allow'] && preg_match('#^/?\**(?:(?<=\*)\$)?$#', $rule['pattern']) === 1 && $rule['pattern'] !== '') {
                $covers_all = true;
            }
        }
        if (!$covers_all) {
            return false;
        }
        // An Allow reopens the site only where it actually wins: test its literal prefix.
        foreach ($this->rules($agent) as $rule) {
            if (!$rule['allow'] || strpos(self::normalise($rule['pattern']), '/wp-admin/') === 0) {
                continue;
            }
            $prefix = (string) preg_replace('/[*$].*$/s', '', $rule['pattern']);
            $prefix = $prefix === '' || $prefix[0] !== '/' ? '/' . $prefix : $prefix;
            if ($this->allows($prefix, $agent)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> the `Sitemap:` values, in order */
    public function sitemaps(): array
    {
        return $this->sitemaps;
    }

    /** @return list<array{allow:bool, pattern:string}> */
    private function rules(string $agent): array
    {
        $agent = strtolower($agent);
        if ($agent !== '*' && isset($this->groups[$agent])) {
            return $this->groups[$agent];
        }

        return $this->groups['*'] ?? [];
    }

    /**
     * One octet form for rule and path (RFC 9309 §2.2.2, RFC 3986 §6.2.2):
     * escapes of unreserved characters decoded, other escapes upper-cased,
     * bytes outside ASCII percent-encoded. Reserved escapes (%2F) stay escapes.
     */
    private static function normalise(string $value): string
    {
        $value = (string) preg_replace_callback('/%([0-9A-Fa-f]{2})/', static function (array $m): string {
            $char = chr((int) hexdec($m[1]));
            return preg_match('/^[A-Za-z0-9._~-]$/', $char) === 1 ? $char : '%' . strtoupper($m[1]);
        }, $value);

        return (string) preg_replace_callback('/[\x80-\xFF]/', static fn(array $m): string => '%' . strtoupper(bin2hex($m[0])), $value);
    }

    /** `*` matches any run of characters, a final `$` anchors the end. */
    private static function matches(string $pattern, string $path): bool
    {
        $pattern = self::normalise($pattern);
        $path = self::normalise($path);
        $anchored = substr($pattern, -1) === '$';
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . ($anchored ? '$' : '') . '#';

        return preg_match($regex, $path) === 1;
    }
}
