<?php
// No declare(strict_types): the harness appends this file to a generated script (`sed 1d`).
// Part of contact-schema-release-check.sh: runs inside the test site (`ddev wp eval-file -`), where
// WordPress' WP_HTML_Tag_Processor reads the fetched page (AGENTS.md Don'ts: no hand-written HTML regex).
// Returns "present ...", "absent ..." or "inconclusive: <reason>" for the marker telephone in the
// primary organization node's own telephone field.

if (!function_exists('sfx_release_check_verdict')) {
    function sfx_release_check_verdict(string $html, string $marker): string
    {
        if (!class_exists('WP_HTML_Tag_Processor')) {
            return 'inconclusive: WP_HTML_Tag_Processor is not available';
        }
        if (trim($html) === '') {
            return 'inconclusive: empty response';
        }
        $p = new WP_HTML_Tag_Processor($html);
        $nodes = [];
        $scripts = 0;
        while ($p->next_tag('script')) { // real SCRIPT elements only: comments and text are skipped, case-insensitive
            $type = $p->get_attribute('type');
            if (!is_string($type) || strtolower(trim($type)) !== 'application/ld+json') {
                continue;
            }
            $scripts++;
            $data = json_decode($p->get_modifiable_text(), true);
            if (!is_array($data)) {
                return "inconclusive: JSON-LD script {$scripts} is not valid JSON";
            }
            foreach (array_values($data) === $data ? $data : [$data] as $item) {
                if (!is_array($item)) {
                    return "inconclusive: JSON-LD script {$scripts} holds a non-object item";
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
