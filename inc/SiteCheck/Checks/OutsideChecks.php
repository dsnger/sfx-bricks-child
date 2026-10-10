<?php

declare(strict_types=1);

namespace SFX\SiteCheck\Checks;

use SFX\SiteCheck\Fetch;
use SFX\SiteCheck\Observations;
use SFX\SiteCheck\RunContext;

/**
 * Routing of the outside checks (B rows, and the outside half of the S+B
 * rows) to their families. Holds the plan of a browser check: what is
 * fetched, what is skipped, and the comparison URL.
 *
 * Perspectives (spec rule 8):
 * - Browser: logs_public, backups_public, vcs_env, dir_listing,
 *   security_headers, usernames_public (plus one Loopback request for
 *   `?author=1`), robots_txt, public_files, version_leaks. targets() names the
 *   URLs; the browser fetches them (assets/site-check.js) and observe() turns
 *   what it saw into facts.
 * - Loopback: https (TLS, http → https), xmlrpc, indexability, sitemap,
 *   sitemap_entries. These need a redirect target or follow discovered URLs.
 *   Each Loopback batch fetches its own comparison URL first (rule 2).
 *
 * observe() returns FACTS — never body text; grade() reads facts and the run
 * snapshot only. The S+B rows keep their server shape (FileChecks,
 * ConfigChecks) and are graded there.
 */
final class OutsideChecks
{
    /** Outside fetches per check and run; further targets stay Nicht prüfbar, named. */
    public const MAX_TARGETS = 20;

    private const FAMILIES = [
        'logs_public'      => OutsideFiles::class,
        'backups_public'   => OutsideFiles::class,
        'vcs_env'          => OutsideFiles::class,
        'dir_listing'      => OutsideFiles::class,
        'public_files'     => OutsideFiles::class,
        'security_headers' => HeaderChecks::class,
        'version_leaks'    => HeaderChecks::class,
        'robots_txt'       => CrawlChecks::class,
        'indexability'     => CrawlChecks::class,
        'sitemap'          => CrawlChecks::class,
        'sitemap_entries'  => CrawlChecks::class,
        'usernames_public' => AccountOutside::class,
        'xmlrpc'           => AccountOutside::class,
        'https'            => AccountOutside::class,
    ];
    private const BROWSER = ['logs_public', 'backups_public', 'vcs_env', 'dir_listing', 'security_headers', 'usernames_public', 'robots_txt', 'public_files', 'version_leaks'];
    /** S+B rows: observe() adds the outside half, the server family grades. */
    private const JOINED = ['logs_public', 'backups_public', 'vcs_env', 'https', 'public_files'];

    public static function handles(string $id): bool
    {
        return isset(self::FAMILIES[$id]);
    }

    /** Pure B rows, graded here; the S+B rows are graded by their server family. */
    public static function grades(string $id): bool
    {
        return self::handles($id) && !in_array($id, self::JOINED, true);
    }

    /**
     * What the browser fetches for this check and run. Loopback checks have
     * no browser targets. `skipped` names what is not fetched and why.
     *
     * @return array{targets:list<array{url:string}>, comparison:?string, skipped:list<array{target:string, reason:string}>}
     */
    public static function targets(string $id, RunContext $ctx): array
    {
        $plan = self::plan($id, $ctx);

        return [
            'targets'    => array_map(static fn(array $c): array => ['url' => $c['url']], $plan['issued']),
            'comparison' => $plan['comparison'],
            'skipped'    => $plan['skipped'],
        ];
    }

    /**
     * The comparison response of a Loopback batch (rule 2): {status, redirect};
     * status 0 when it gave no answer or the request guard refused the URL
     * (Fetch::loopback() sends nothing then) — no soft-404 evidence either way.
     *
     * @return array{status:int, redirect:bool}
     */
    public static function loopback_comparison(string $id, RunContext $ctx): array
    {
        $c = Fetch::loopback('GET', self::comparison_url($id, $ctx));

        return $c['error'] !== '' ? ['status' => 0, 'redirect' => false] : ['status' => (int) $c['status'], 'redirect' => (bool) $c['redirect']];
    }

    /** The random-looking but issued comparison URL of a check in a run (rule 2). */
    public static function comparison_url(string $id, RunContext $ctx): string
    {
        $token = substr(hash_hmac('sha256', $ctx->run() . '|' . $id, wp_salt('nonce')), 0, 16);

        return home_url('/sfx-site-check-missing-' . $token);
    }

    /**
     * Turns the browser's observations (ignored for Loopback checks) into
     * facts. Observations for URLs this check did not issue in this run are
     * dropped unread.
     *
     * @param list<mixed> $observations UNTRUSTED
     */
    public static function observe(string $id, RunContext $ctx, array $observations): array
    {
        if (!self::handles($id)) {
            throw new \InvalidArgumentException('Not an outside check: ' . $id);
        }
        Fetch::start_budget();
        $plan = self::plan($id, $ctx);
        if (in_array($id, self::BROWSER, true)) {
            $seen = Observations::index_observations($observations, $plan);
            $comparison = Observations::comparison_response($seen, $plan['comparison']);
        } else {
            // Rule 2 for a Loopback batch: its own comparison, fetched first.
            $seen = [];
            $comparison = self::loopback_comparison($id, $ctx);
        }

        return (self::FAMILIES[$id])::observe($id, $ctx, $plan, $seen, $comparison);
    }

    /**
     * @return array{status:string, findings:list<array{id:string,status:string,label:string}>, perspective:string, note:string}
     */
    public static function grade(string $id, array $facts, RunContext $ctx): array
    {
        if (!self::grades($id)) {
            throw new \InvalidArgumentException('Not graded by the outside checks: ' . $id);
        }

        return (self::FAMILIES[$id])::grade($id, $facts, $ctx);
    }

    /**
     * Candidates of a browser check, split into issued (fetched) and skipped.
     *
     * @return array{issued:list<array{url:string, target:string, kind:string}>, comparison:?string, skipped:list<array{target:string, reason:string}>}
     */
    private static function plan(string $id, RunContext $ctx): array
    {
        $plan = ['issued' => [], 'comparison' => null, 'skipped' => []];
        if ($id === 'indexability') {
            $plan['skipped'] = CrawlChecks::index_paths($ctx)[1];
        }
        if (!in_array($id, self::BROWSER, true)) {
            return $plan;
        }

        foreach ((self::FAMILIES[$id])::candidates($id, $ctx) as $c) {
            $refusal = Fetch::refusal($c['url']);
            if (($c['skip'] ?? '') !== '') {
                // The server refused this candidate itself (dir_listing, rule 6).
                $plan['skipped'][] = ['target' => $c['target'], 'reason' => $c['skip']];
            } elseif ($refusal !== '') {
                $plan['skipped'][] = ['target' => $c['target'], 'reason' => Fetch::reason_text($refusal)];
            } elseif (count($plan['issued']) >= self::MAX_TARGETS) {
                $plan['skipped'][] = ['target' => $c['target'], 'reason' => Observations::limit_text()];
            } else {
                $plan['issued'][] = $c;
            }
        }
        if ($plan['issued'] !== []) {
            // The comparison passes the same request guard (rule 6); refused → no soft-404 evidence.
            $url = self::comparison_url($id, $ctx);
            $refusal = Fetch::refusal($url);
            if ($refusal === '') {
                $plan['comparison'] = $url;
            } else {
                $plan['skipped'][] = ['target' => Observations::target_of($url), 'reason' => Fetch::reason_text($refusal)];
            }
        }

        return $plan;
    }
}
