<?php

declare(strict_types=1);

/**
 * SiteCheck robots.txt parser: groups per user-agent, longest-match
 * Allow/Disallow (RFC 9309). Spec: "Check catalogue" → robots_txt, indexability.
 */

require_once __DIR__ . '/../inc/SiteCheck/Robots.php';

use SFX\SiteCheck\Robots;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// Disallow everything, with an Allow exception: not "disallows all".
$r = Robots::parse("User-agent: *\nDisallow: /\nAllow: /public/\n");
assert_same(false, $r->disallows_all(), 'Disallow: / with Allow: /public/ → not disallows all');
assert_same(true, $r->allows('/public/page'), 'Allow exception wins for its path (longer match)');
assert_same(false, $r->allows('/other'), 'the rest stays disallowed');

$r = Robots::parse("User-agent: *\nDisallow: /\n");
assert_same(true, $r->disallows_all(), 'Disallow: / alone → disallows all');
assert_same(false, $r->allows('/'), 'root disallowed');

// A separate Googlebot group does not affect *.
$r = Robots::parse("User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nDisallow: /private/\n");
assert_same(false, $r->disallows_all(), 'Googlebot group does not affect *');
assert_same(true, $r->disallows_all('googlebot'), 'Googlebot group applies to Googlebot (case-insensitive)');
assert_same(false, $r->allows('/private/x'), '* group rule applies to *');
assert_same(false, $r->allows('/public/x', 'Googlebot'), 'Googlebot uses its own group');

// Only a Googlebot group: * is unrestricted.
$r = Robots::parse("User-agent: Googlebot\nDisallow: /\n");
assert_same(false, $r->disallows_all(), 'no * group → everything allowed for *');
assert_same(true, $r->allows('/x'), 'no * group → allows');

// Several user-agent lines share one group; comments and blank lines; case of field names.
$r = Robots::parse("# hello\nuser-agent: bingbot\nUSER-AGENT: *   # all\n\nDISALLOW: /   # all\n");
assert_same(true, $r->disallows_all(), 'grouped user-agents share the rules; comments ignored');
assert_same(true, $r->disallows_all('bingbot'), 'bingbot is in the same group');

// Empty Disallow allows everything.
$r = Robots::parse("User-agent: *\nDisallow:\n");
assert_same(false, $r->disallows_all(), 'empty Disallow → allows all');
assert_same(true, $r->allows('/'), 'empty Disallow → root allowed');

// Wildcards and end anchor; tie → Allow.
$r = Robots::parse("User-agent: *\nDisallow: /*.pdf$\nDisallow: /a\nAllow: /a\n");
assert_same(false, $r->allows('/files/x.pdf'), '* and $ patterns');
assert_same(true, $r->allows('/files/x.pdf?y'), '$ anchors the end');
assert_same(true, $r->allows('/a/b'), 'equal length → Allow wins');

$r = Robots::parse("User-agent: *\nDisallow: /*\n");
assert_same(true, $r->disallows_all(), 'Disallow: /* → disallows all');

// Allow: /$ only lets the root through, still not "everything disallowed".
$r = Robots::parse("User-agent: *\nDisallow: /\nAllow: /$\n");
assert_same(false, $r->disallows_all(), 'Allow: /$ → not everything disallowed');

// Rules before any user-agent line belong to no group.
$r = Robots::parse("Disallow: /\nUser-agent: *\nAllow: /\n");
assert_same(false, $r->disallows_all(), 'rules outside a group are ignored');

// Sitemap lines are global, in order, and do not end a group.
$r = Robots::parse("User-agent: *\nSitemap: https://ex.test/a.xml\nDisallow: /\nsitemap:https://ex.test/b.xml\n");
assert_same(['https://ex.test/a.xml', 'https://ex.test/b.xml'], $r->sitemaps(), 'Sitemap lines collected');
assert_same(true, $r->disallows_all(), 'Sitemap line inside a group does not end it');

// Empty file and CRLF line ends.
assert_same(false, Robots::parse('')->disallows_all(), 'empty robots.txt → allows all');
assert_same(true, Robots::parse("User-agent: *\r\nDisallow: /\r\n")->disallows_all(), 'CRLF line ends');

// A leading UTF-8 BOM is not part of the first field name.
assert_same(true, Robots::parse("\xEF\xBB\xBFUser-agent: *\nDisallow: /\n")->disallows_all(), 'BOM stripped');

// Allow rules that only reopen /wp-admin/ (WordPress' default admin-ajax line) do not reopen the site.
$r = Robots::parse("User-agent: *\nDisallow: /\nAllow: /wp-admin/admin-ajax.php\n");
assert_same(true, $r->disallows_all(), 'Disallow: / plus only a /wp-admin/ Allow → disallows all');
$r = Robots::parse("User-agent: *\nDisallow: /\nAllow: /wp-admin/admin-ajax.php\nAllow: /blog/\n");
assert_same(false, $r->disallows_all(), 'any other Allow keeps the site open');

// Gate B pass 1 (spec-3): "everything" means every path, not just the home page.
$r = Robots::parse("User-agent: *\nDisallow: /$\n");
assert_same(false, $r->disallows_all(), 'Disallow: /$ blocks only the home page → not disallows all');
assert_same(true, $r->allows('/contact/'), 'Disallow: /$ leaves /contact/ open');
foreach (["Disallow: /*", "Disallow: *", "Disallow: /*$", "Disallow: /$\nDisallow: /"] as $rule) {
    assert_same(true, Robots::parse("User-agent: *\n{$rule}\n")->disallows_all(), "{$rule} → disallows all");
}
assert_same(false, Robots::parse("User-agent: *\nDisallow: /a\n")->disallows_all(), 'Disallow: /a → not all');

// Gate B pass 1 (spec-2): only a body that reads as robots.txt counts as parsed.
assert_same(true, Robots::parse("User-agent: *\nDisallow:\n")->recognised(), 'directives → recognised');
assert_same(true, Robots::parse("# nothing here\n\n")->recognised(), 'comments only → recognised (an empty rule set)');
assert_same(true, Robots::parse("Sitemap: https://ex.test/s.xml\n")->recognised(), 'a Sitemap line alone → recognised');
assert_same(false, Robots::parse("<!DOCTYPE html><html><body>Home</body></html>")->recognised(), 'an HTML page → not recognised');
assert_same(false, Robots::parse("Page not found\nSorry: nothing here\n")->recognised(), 'unrelated text with a colon → not recognised');
assert_same(false, Robots::parse('')->recognised(), 'empty → not recognised (the caller decides)');

// Gate B pass 3: an Allow that never wins does not reopen the site.
assert_same(true, Robots::parse("User-agent: *\nDisallow: /\nAllow: /public/\nDisallow: /public/*\n")->disallows_all(), 'overridden Allow → disallows all');
assert_same(false, Robots::parse("User-agent: *\nDisallow: /\nAllow: /public/\n")->disallows_all(), 'winning Allow → not all');
assert_same(false, Robots::parse("User-agent: *\nDisallow: /\nAllow: /blog*\n")->disallows_all(), 'an Allow with a wildcard tested by its literal prefix /blog');

// Gate B pass 5: percent-encoding of unreserved characters is normalised on both sides (RFC 9309 §2.2.2, RFC 3986).
$r = Robots::parse("User-agent: *\nDisallow: /private\nDisallow: /caf%C3%A9\nDisallow: /%61dmin\nDisallow: /a%2Fb\n");
assert_same(false, $r->allows('/%70rivate/x'), '/%70rivate is /private');
assert_same(false, $r->allows('/café'), 'a literal UTF-8 path matches its encoded rule');
assert_same(false, $r->allows('/admin/'), 'an encoded unreserved rule matches the plain path');
assert_same(true, $r->allows('/a/b'), 'a reserved escape (%2F) stays distinct from /');
assert_same(false, $r->allows('/a%2fb'), 'escape case does not matter');

// Gate B pass 6: precedence uses the normalised rule length.
assert_same(false, Robots::parse("User-agent: *\nDisallow: /foo/x\nAllow: /%66%6f%6f\n")->allows('/foo/x'), 'a shorter encoded Allow loses to a longer Disallow');
assert_same(true, Robots::parse("User-agent: *\nDisallow: /%66oo\nAllow: /foo\n")->allows('/foo'), 'equal normalised rules: Allow wins the tie');
assert_same(false, Robots::parse("User-agent: *\nDisallow: /private/x\nAllow: /%70rivate\n")->allows('/private/x'), '/%70rivate is shorter than /private/x');

// Gate B pass 7: the /wp-admin/ exemption is tested on the normalised rule.
assert_same(true, Robots::parse("User-agent: *\nDisallow: /\nAllow: /%77p-admin/admin-ajax.php\n")->disallows_all(), 'an encoded /wp-admin/ Allow does not reopen the site');

echo "site-check robots: ok\n";
