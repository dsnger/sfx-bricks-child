<?php
// Regression cases for contact-schema-jsonld.php. Not part of quality.sh: the analyzer needs WordPress'
// HTML API and the theme's Observations class, so it runs inside the test site. From the repository root:
//   { cat tests/support/contact-schema-jsonld.php; sed 1d tests/support/contact-schema-jsonld-cases.php; } \
//     | (cd "$SFX_TEST_SITE" && ddev wp eval-file -)
// Prints one line per case and "CASES PASS" or "CASES FAIL: <n>"; it reads nothing from the site's data.

$marker = '+49 201 555-0101';
$doc = static fn(string $head): string => "<!DOCTYPE html>\n<html><head>{$head}</head><body><p>page</p></body></html>";
$script = static fn(string $class, array $data): string => '<script type="application/ld+json"' . ($class === '' ? '' : " class=\"{$class}\"") . '>'
    . json_encode($data, JSON_UNESCAPED_SLASHES) . '</script>';
$org = ['@context' => 'https://schema.org', '@type' => 'Organization', '@id' => 'https://acme.test/#organization', 'name' => 'Acme', 'telephone' => $marker];
$yoast_plain = $script('yoast-schema-graph', ['@context' => 'https://schema.org', '@graph' => [
    ['@type' => 'Organization', '@id' => 'https://acme.test/#organization', 'name' => 'Plugin baseline'],
    ['@type' => 'WebPage', '@id' => 'https://acme.test/', 'publisher' => ['@id' => 'https://acme.test/#organization']],
]]);
$yoast_marked = $script('yoast-schema-graph', ['@context' => 'https://schema.org', '@graph' => [
    ['@type' => 'Organization', '@id' => 'https://acme.test/#organization', 'name' => 'Acme', 'telephone' => $marker],
]]);
$rank_math = $script('rank-math-schema', ['@context' => 'https://schema.org', '@graph' => [
    ['@type' => 'Dentist', '@id' => 'https://acme.test/#place', 'telephone' => $marker],
    ['@type' => 'WebPage', '@id' => 'https://acme.test/', 'publisher' => ['@id' => 'https://acme.test/#place']],
]]);
$page_org = $script('sfx-page-schema', $org); // an administrator's per-page Organization carrying the marker
$tsf = static fn(string $inner): string => "\n<!-- The SEO Framework by Sybre Waaijer -->\n{$inner}\n<!-- / The SEO Framework by Sybre Waaijer | 1.00ms meta | 2.00ms boot -->\n";
$tsf_graph = $script('', ['@context' => 'https://schema.org', '@graph' => [
    ['@type' => 'Organization', '@id' => 'https://acme.test/#/schema/Organization', 'name' => 'TSF knowledge'],
]]);

// [label, html, status, source, expected prefix]
$cases = [
    // Reproduction 1 (pass 2): per-page Organization with the marker, plugin graph unchanged -> not delivered.
    ['yoast: per-page org with marker, plugin unchanged (switch off)', $doc($yoast_plain), 200, 'yoast', 'absent'],
    ['yoast: per-page org with marker, plugin unchanged (switch on)', $doc($yoast_plain . $page_org), 200, 'yoast', 'absent'],
    ['own: per-page org with marker, no company script', $doc($page_org), 200, 'own', 'absent'],
    ['rank_math: per-page org with marker only', $doc($page_org), 200, 'rank_math', 'absent'],
    ['own: untagged script with marker (other producer)', $doc($script('', $org)), 200, 'own', 'absent'],
    // Reproduction 2 (pass 2): a 200 response that is no HTML page -> inconclusive, never absent/suppressed.
    ['tsf: text "upstream unavailable"', 'upstream unavailable', 200, 'tsf', 'inconclusive:'],
    ['tsf: JSON error body', '{"code":"rest_error","message":"error"}', 200, 'tsf', 'inconclusive:'],
    ['own: JSON error body', '{"code":"rest_error","message":"error"}', 200, 'own', 'inconclusive:'],
    ['own: empty body', '', 200, 'own', 'inconclusive:'],
    ['own: HTTP 500 with a valid page', $doc($script('sfx-contact-schema', $org)), 500, 'own', 'inconclusive:'],
    ['own: HTTP 0 (status unreadable)', $doc($script('sfx-contact-schema', $org)), 0, 'own', 'inconclusive:'],
    ['tsf: HTML page without TSF output', $doc(''), 200, 'tsf', 'inconclusive:'],
    // Normal verdicts.
    ['tsf: TSF graph, no company script -> suppressed', $doc($tsf($tsf_graph)), 200, 'tsf', 'absent'],
    ['tsf: TSF graph and company script with marker', $doc($tsf($tsf_graph) . $script('sfx-contact-schema', $org)), 200, 'tsf', 'present'],
    ['own: company script with marker', $doc($script('sfx-contact-schema', $org) . $page_org), 200, 'own', 'present'],
    ['yoast: plugin graph with marker', $doc($yoast_marked), 200, 'yoast', 'present'],
    ['rank_math: publisher node with marker', $doc($rank_math), 200, 'rank_math', 'present'],
    ['rank_math: Pro class', $doc(str_replace('rank-math-schema', 'rank-math-schema-pro', $rank_math)), 200, 'rank_math', 'present'],
    ['own: uppercase SCRIPT', $doc('<SCRIPT TYPE="application/ld+json" CLASS="sfx-contact-schema">' . json_encode($org) . '</SCRIPT>'), 200, 'own', 'present'],
    ['own: commented-out company script', $doc('<!--' . $script('sfx-contact-schema', $org) . '-->'), 200, 'own', 'absent'],
    ['own: phone only in a contactPoint', $doc($script('sfx-contact-schema', ['@type' => 'Organization', '@id' => 'https://acme.test/#organization', 'contactPoint' => ['telephone' => $marker]])), 200, 'own', 'absent'],
    ['own: invalid JSON in the company script', $doc('<script type="application/ld+json" class="sfx-contact-schema">{oops}</script>'), 200, 'own', 'inconclusive:'],
    ['own: truncated tag', $doc($script('sfx-contact-schema', $org)) . '<div data-x="', 200, 'own', 'inconclusive:'],
];

$failed = 0;
foreach ($cases as [$label, $html, $status, $source, $want]) {
    $out = sfx_release_check_verdict($html, $status, $marker, $source);
    $ok = str_starts_with($out, $want);
    $failed += $ok ? 0 : 1;
    echo ($ok ? 'ok   ' : 'FAIL ') . "{$label}: {$out}\n";
}
echo $failed === 0 ? "CASES PASS\n" : "CASES FAIL: {$failed}\n";
