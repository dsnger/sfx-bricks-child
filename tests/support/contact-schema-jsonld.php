<?php
// No declare(strict_types): the harness appends this file to a generated script (`sed 1d`).
// Part of contact-schema-release-check.sh: runs inside the test site (`ddev wp eval-file -`), where
// WordPress' HTML API reads the fetched page (AGENTS.md Don'ts: no hand-written HTML regex).
// Regression cases: contact-schema-jsonld-cases.php (how to run them is in its header).
//
// sfx_release_check_verdict($html, $status, $marker, $source) returns "present ...", "absent ..." or
// "inconclusive: <reason>" for the marker telephone in the primary organization node's own telephone.
// Only the scripts of one producer are read, picked by the class its producer prints:
//   own       - the theme's company script, class sfx-contact-schema (no SEO plugin, TSF knowledge output off)
//   yoast     - Yoast's graph, class yoast-schema-graph (wordpress-seo src/presenters/schema-presenter.php)
//   rank_math - Rank Math's graph, class rank-math-schema, or rank-math-schema-pro with Pro
//               (seo-by-rank-math includes/modules/schema/class-jsonld.php)
//   tsf       - like own, and TSF's own JSON-LD script (between its indicator comments) must be on the page,
//               so a page without TSF output cannot pass as "suppressed"
// The per-page field's scripts (class sfx-page-schema) are never read. A response counts only with HTTP 200
// and a body Observations::html_document() recognises as an HTML document.

if (!function_exists('sfx_release_check_verdict')) {
    function sfx_release_check_verdict(string $html, int $status, string $marker, string $source): string
    {
        $classes = [
            'own'       => ['sfx-contact-schema'],
            'tsf'       => ['sfx-contact-schema'],
            'yoast'     => ['yoast-schema-graph'],
            'rank_math' => ['rank-math-schema', 'rank-math-schema-pro'],
        ][$source] ?? null;
        if ($classes === null) {
            return "inconclusive: unknown source {$source}";
        }
        if ($status !== 200) {
            return "inconclusive: HTTP status {$status}";
        }
        if (!class_exists(\SFX\SiteCheck\Observations::class) || !class_exists('WP_HTML_Tag_Processor')) {
            return 'inconclusive: the theme or the WordPress HTML API is not loaded';
        }
        if (\SFX\SiteCheck\Observations::html_document($html) === null) {
            return 'inconclusive: the response is not an HTML document';
        }

        $p = new WP_HTML_Tag_Processor($html);
        $nodes = [];
        $scripts = 0;
        $in_tsf = false;
        $tsf_scripts = 0;
        while ($p->next_token()) {
            if ($p->get_token_type() === '#comment') {
                $text = trim($p->get_modifiable_text());
                if (str_starts_with($text, 'The SEO Framework')) {
                    $in_tsf = true;
                } elseif (str_starts_with($text, '/ The SEO Framework')) {
                    $in_tsf = false;
                }
                continue;
            }
            if ($p->get_tag() !== 'SCRIPT' || $p->is_tag_closer()) {
                continue;
            }
            $type = $p->get_attribute('type');
            if (!is_string($type) || strtolower(trim($type)) !== 'application/ld+json') {
                continue;
            }
            if ($in_tsf) {
                $tsf_scripts++;
            }
            $ours = false;
            foreach ($classes as $class) {
                $ours = $ours || $p->has_class($class);
            }
            if (!$ours) {
                continue;
            }
            $scripts++;
            $data = json_decode($p->get_modifiable_text(), true);
            if (!is_array($data)) {
                return "inconclusive: the {$classes[0]} script {$scripts} is not valid JSON";
            }
            foreach (array_values($data) === $data ? $data : [$data] as $item) {
                if (!is_array($item)) {
                    return "inconclusive: the {$classes[0]} script {$scripts} holds a non-object item";
                }
                $nodes[] = $item;
                foreach (is_array($item['@graph'] ?? null) ? $item['@graph'] : [] as $node) {
                    if (is_array($node)) {
                        $nodes[] = $node;
                    }
                }
            }
        }
        if ($p->paused_at_incomplete_token()) {
            return 'inconclusive: the HTML ends inside a tag';
        }
        if ($source === 'tsf' && $tsf_scripts === 0) {
            return 'inconclusive: no JSON-LD script of The SEO Framework on the page';
        }

        // The organization other nodes name as their publisher (Rank Math), or the #organization node.
        $publisher_ids = [];
        foreach ($nodes as $node) {
            $ref = is_array($node['publisher'] ?? null) ? ($node['publisher']['@id'] ?? null) : null;
            if (is_string($ref)) {
                $publisher_ids[] = $ref;
            }
        }
        $organizations = array_filter($nodes, static function (array $node) use ($publisher_ids): bool {
            $id = $node['@id'] ?? null;
            if (!is_string($id) || $id === '') {
                return false;
            }
            if (in_array($id, $publisher_ids, true)) {
                return true; // its @type may be any business subtype Rank Math's Local SEO offers
            }
            $types = (array) ($node['@type'] ?? []);
            return str_ends_with($id, '#organization')
                && (in_array('Organization', $types, true) || in_array('LocalBusiness', $types, true));
        });

        $hit = false;
        foreach ($organizations as $node) {
            foreach ((array) ($node['telephone'] ?? []) as $telephone) {
                $hit = $hit || (is_string($telephone) && trim($telephone) === $marker);
            }
        }
        return sprintf('%s scripts=%d organization_nodes=%d', $hit ? 'present' : 'absent', $scripts, count($organizations));
    }
}
