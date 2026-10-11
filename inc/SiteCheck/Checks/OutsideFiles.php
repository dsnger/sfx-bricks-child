<?php

declare(strict_types=1);

namespace SFX\SiteCheck\Checks;

use SFX\SiteCheck\Evidence;
use SFX\SiteCheck\Fetch;
use SFX\SiteCheck\Locations;
use SFX\SiteCheck\Observations;
use SFX\SiteCheck\RunContext;
use SFX\SiteCheck\Status;

/**
 * Outside half of logs_public, backups_public, vcs_env and public_files
 * (joined into the FileChecks shape), plus dir_listing. Browser perspective.
 */
final class OutsideFiles
{
    /** @return list<array{url:string, target:string, kind:string}> */
    public static function candidates(string $id, RunContext $ctx): array
    {
        $list = [];
        $add = static function (?string $url, string $kind = '') use (&$list): void {
            if ($url !== null && $url !== '') {
                $list[] = ['url' => $url, 'target' => Observations::target_of($url), 'kind' => $kind];
            }
        };

        switch ($id) {
            case 'logs_public':
            case 'backups_public':
            case 'vcs_env':
                foreach (ServerChecks::observe($id, $ctx)['targets'] ?? [] as $t) {
                    if ($t['url'] !== null) {
                        $list[] = ['url' => (string) $t['url'], 'target' => (string) $t['target'], 'kind' => basename((string) $t['path'])];
                    }
                }
                break;
            case 'dir_listing':
                // Rule 6: a folder URL can run its index file, so each folder carries the server's verdict.
                $folder = static function (?string $url, string $dir) use (&$list): void {
                    if ($url !== null && $url !== '') {
                        $list[] = ['url' => $url, 'target' => Observations::target_of($url), 'kind' => '', 'skip' => self::folder_skip($dir)];
                    }
                };
                $loc = Locations::current();
                if ($loc['uploads'] !== null) {
                    $folder($loc['uploads']['url'], $loc['uploads']['dir']);
                    $dated = self::dated_uploads_folder($loc['uploads']['dir']);
                    if ($dated !== null) {
                        $folder(Locations::url_for($dated . '/', $loc) ?? null, $dated);
                    }
                }
                if ($loc['plugins'] !== null) {
                    $folder($loc['plugins']['url'], $loc['plugins']['dir']);
                }
                $folder(site_url('/wp-includes/'), wp_normalize_path(ABSPATH) . 'wp-includes');
                break;
            case 'public_files':
                $add(site_url('/readme.html'), 'readme');
                $add(site_url('/license.txt'), 'license');
                $add(site_url('/wp-admin/install.php'), 'install');
                break;
        }

        return $list;
    }

    public static function observe(string $id, RunContext $ctx, array $plan, array $seen, array $comparison): array
    {
        if ($id === 'dir_listing') {
            return self::dir_listing_facts($plan, $seen, $comparison);
        }
        if ($id === 'public_files') {
            return self::public_files_facts($ctx, $plan, $seen, $comparison);
        }

        return self::exposure_facts($id, $ctx, $plan, $seen, $comparison);
    }

    public static function grade(string $id, array $facts, RunContext $ctx): array
    {
        return self::grade_dir_listing($facts);
    }

    /** Directory listing markers; two different kinds make a listing. */
    private const LISTING = [
        'title'  => '#<title>\s*Index of /#i',
        'h1'     => '#<h1>\s*Index of /#i',
        'parent' => '#(?:>\s*Parent Directory\s*<|href="\.\./"|\[To Parent Directory\])#i',
        'rows'   => '#(?:href="\?C=[NMSD];O=[AD]"|<pre>\s*<a href=|src="/icons/)#i',
    ];

    /** Fetch::folder_refusal() as a reason text, '' when the folder may be requested. */
    private static function folder_skip(string $dir): string
    {
        $refusal = Fetch::folder_refusal($dir);

        return $refusal === '' ? '' : Fetch::reason_text($refusal);
    }

    /** The newest `YYYY/MM` folder in uploads, or null. */
    private static function dated_uploads_folder(string $uploads_dir): ?string
    {
        $folders = glob(rtrim($uploads_dir, '/') . '/[0-9][0-9][0-9][0-9]/[0-1][0-9]', GLOB_ONLYDIR) ?: [];
        if ($folders === []) {
            return null;
        }
        sort($folders, SORT_STRING);

        return wp_normalize_path((string) end($folders));
    }

    private static function exposure_facts(string $id, RunContext $ctx, array $plan, array $seen, array $comparison): array
    {
        $issued = array_column($plan['issued'], null, 'url');
        $server = ServerChecks::observe($id, $ctx);
        foreach ($server['targets'] as $i => $t) {
            if ($t['url'] === null) {
                continue;
            }
            $refusal = Fetch::refusal((string) $t['url']);
            if ($refusal !== '') {
                $server['targets'][$i]['url'] = null;
                $server['targets'][$i]['reason'] = Fetch::reason_text($refusal);
                continue;
            }
            if (!isset($issued[$t['url']])) {
                $server['targets'][$i]['reason'] = Observations::limit_text();
                continue;
            }
            [$outside, $group] = Observations::outside($seen[$t['url']] ?? [], $comparison, self::exposure_groups($id, (string) $t['path']));
            $server['targets'][$i]['outside'] = $outside;
            if ($outside === Evidence::SIGNATURE && $group !== null) {
                $server['targets'][$i]['outside_signature'] = $group;
            }
        }

        return $server;
    }

    /** @return list<string> */
    private static function exposure_groups(string $id, string $path): array
    {
        if ($id === 'logs_public') {
            return ['log'];
        }
        if ($id === 'backups_public') {
            return ['sql', 'archive'];
        }
        $name = basename($path);
        if ($name === 'HEAD') {
            return ['git_head'];
        }

        return $name === 'config' ? ['git_config'] : ['env'];
    }

    private static function dir_listing_facts(array $plan, array $seen, array $comparison): array
    {
        $targets = [];
        foreach ($plan['issued'] as $c) {
            $variants = $seen[$c['url']] ?? [];
            $names = null;
            foreach ($variants as $obs) {
                // A listing is a 200 page with its markers, no challenge, and the comparison
                // clearly did not serve a page — on a catch-all site the markers prove nothing (rule 4).
                $r = Observations::response($obs);
                if ($names === null && $obs['error'] === '' && $r['status'] === 200 && !$r['challenge'] && !$r['redirect']
                    && Evidence::comparison_refused($comparison) && self::is_listing($r['body'])) {
                    $names = self::listed_names($r['body']);
                }
            }
            $targets[] = [
                'target'     => $c['target'],
                'listing'    => $names !== null,
                'names'      => $names ?? [],
                'no_listing' => $names === null && self::no_listing($variants, $comparison),
            ];
        }

        return ['targets' => $targets, 'skipped' => Observations::cap_list($plan['skipped'])];
    }

    /**
     * Both fetches show positively that there is no listing: blocked,
     * a served page without markers, or an empty complete 200 — the last
     * only when the comparison answered 404/410, so the site is no soft-404
     * site (ruling: the "Silence is golden" index.php is no listing).
     */
    private static function no_listing(array $variants, array $comparison): bool
    {
        foreach (['busted', 'plain'] as $v) {
            $obs = $variants[$v] ?? null;
            if ($obs === null) {
                return false;
            }
            $r = Observations::response($obs);
            $empty = $obs['error'] === '' && $r['status'] === 200 && $r['body'] === '' && !$r['truncated'] && !$r['redirect'] && !$r['challenge']
                && Evidence::comparison_missing($comparison);
            if (!$empty && !in_array(Evidence::classify($r, $comparison), [Evidence::UNREACHABLE, Evidence::OK200], true)) {
                return false;
            }
        }

        return true;
    }

    private static function is_listing(string $body): bool
    {
        $kinds = 0;
        foreach (self::LISTING as $pattern) {
            $kinds += preg_match($pattern, $body) === 1 ? 1 : 0;
        }

        return $kinds >= 2;
    }

    /** @return list<string> up to 10 listed names */
    private static function listed_names(string $body): array
    {
        preg_match_all('#<a href="([^"?/][^"]*)"#i', $body, $m);
        $names = [];
        foreach ($m[1] as $href) {
            $name = rawurldecode(html_entity_decode($href, ENT_QUOTES));
            if ($name !== '../' && !in_array($name, $names, true)) {
                $names[] = Observations::short($name, 60);
            }
            if (count($names) >= 10) {
                break;
            }
        }

        return $names;
    }

    private static function grade_dir_listing(array $facts): array
    {
        $findings = [];
        foreach ($facts['targets'] ?? [] as $t) {
            $target = (string) $t['target'];
            if (!empty($t['listing'])) {
                $names = implode(', ', $t['names'] ?? []);
                /* translators: %s: file names shown in the listing */
                $findings[] = Observations::finding('dir_listing', $target, Status::YELLOW, $target . ' — ' . sprintf(__('directory listing is on; it shows: %s', 'sfxtheme'), $names));
            } elseif (!empty($t['no_listing'])) {
                $findings[] = Observations::finding('dir_listing', $target, Status::GREEN, $target . ' — ' . __('no directory listing', 'sfxtheme'));
            } else {
                $findings[] = Observations::finding('dir_listing', $target, Status::UNKNOWN, $target . ' — ' . __('no reliable answer from outside', 'sfxtheme'));
            }
        }
        foreach ($facts['skipped'] ?? [] as $s) {
            $findings[] = Observations::finding('dir_listing', (string) $s['target'], Status::UNKNOWN, $s['target'] . ' — ' . $s['reason']);
        }

        return Observations::result($findings, Status::UNKNOWN, 'browser', __('Fetched by your browser without login.', 'sfxtheme'));
    }

    /** A file's own content, never just a WordPress-looking page. */
    private const PUBLIC_FILE_SIGNATURES = [
        'readme'  => '#Semantic Personal Publishing Platform|<title>\s*WordPress\s*(?:&\#8250;|&rsaquo;|\x{203A})\s*ReadMe\s*</title>#iu',
        'license' => '#GNU GENERAL PUBLIC LICENSE\s+Version 2, June 1991#i',
    ];

    /**
     * The outside half of public_files, joined by target into the server
     * half (FileChecks). States: readme/license readable | blocked | unknown;
     * installer form | installed | blocked | unknown. The readme/license and
     * "already installed" markers are not sensitive, so rule 4 wins over them:
     * a marker counts only in a complete 2xx answer that is no challenge and
     * no soft-404. The setup form counts in any 2xx answer without a challenge.
     */
    private static function public_files_facts(RunContext $ctx, array $plan, array $seen, array $comparison): array
    {
        $server = ServerChecks::observe('public_files', $ctx);
        $states = [];
        foreach ($plan['issued'] as $c) {
            $variants = $seen[$c['url']] ?? [];
            $states[$c['target']] = self::public_file_state($c['kind'], $variants, $comparison);
        }
        // Not fetched (rule 6, Locations): Nicht prüfbar with the refusal reason; the installer stays listed.
        $reasons = array_column($plan['skipped'], 'reason', 'target');
        foreach ($server['targets'] as $i => $t) {
            $server['targets'][$i]['state'] = $states[$t['target']] ?? 'unknown';
            if (isset($reasons[$t['target']])) {
                $server['targets'][$i]['reason'] = (string) $reasons[$t['target']];
            }
        }
        foreach (self::candidates('public_files', $ctx) as $c) {
            if ($c['kind'] === 'install' && (isset($states[$c['target']]) || isset($reasons[$c['target']]))) {
                $server['targets'][] = ['target' => $c['target'], 'kind' => 'install', 'disk' => '', 'state' => $states[$c['target']] ?? 'unknown', 'reason' => (string) ($reasons[$c['target']] ?? '')];
            }
        }

        return $server;
    }

    private static function public_file_state(string $kind, array $variants, array $comparison): string
    {
        foreach ($variants as $obs) {
            $r = Observations::response($obs);
            $answered = $obs['error'] === '' && $r['status'] >= 200 && $r['status'] < 300 && !$r['challenge'] && !$r['redirect'];
            if (!$answered) {
                continue;
            }
            if ($kind === 'install' && preg_match('/<form[^>]*\bid=["\']setup["\']|name=["\']weblog_title["\']/i', $r['body']) === 1) {
                return 'form';
            }
            // Complete, not a soft-404 (Evidence::classify() answers OK200 only then).
            if (Evidence::classify($r, $comparison) !== Evidence::OK200) {
                continue;
            }
            if ($kind === 'install' && self::already_installed($r['body'])) {
                return 'installed';
            }
            if (isset(self::PUBLIC_FILE_SIGNATURES[$kind]) && preg_match(self::PUBLIC_FILE_SIGNATURES[$kind], $r['body']) === 1) {
                return 'readable';
            }
        }

        return Observations::outside($variants, $comparison, [])[0] === Evidence::UNREACHABLE ? 'blocked' : 'unknown';
    }

    /** install.php's "already installed" page in any language: its header, the step link to the login, no form. */
    private static function already_installed(string $body): bool
    {
        return preg_match('/<p id=["\']logo["\']/i', $body) === 1
            && preg_match('/class=["\']step["\'][^>]*>\s*<a [^>]*wp-login\.php/i', $body) === 1
            && stripos($body, '<form') === false;
    }
}
