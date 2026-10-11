<?php

/**
 * Every `sfxtheme` string in the files the Site Check feature touched has a
 * non-empty German translation in languages/de_DE.po.
 *
 * Scans inc/SiteCheck/** plus the five existing files the feature edited,
 * through every gettext helper (__, _e, esc_html__, esc_html_e, esc_attr__,
 * esc_attr_e, _x, _n, _nx, esc_html_x, esc_attr_x). The first argument(s) must
 * be string literals (joined with `.` allowed); a call whose text is built at
 * run time cannot be checked here and is listed, not skipped silently.
 * JS gets its strings only via wp_localize_script from PHP, so this covers JS.
 *
 * Run: php tests/site-check-translations-test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

function t_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

// helper => [positions of msgid args, position of context arg or null, plural position or null]
$helpers = [
    '__' => [0, null, null], '_e' => [0, null, null],
    'esc_html__' => [0, null, null], 'esc_html_e' => [0, null, null],
    'esc_attr__' => [0, null, null], 'esc_attr_e' => [0, null, null],
    '_x' => [0, 1, null], 'esc_html_x' => [0, 1, null], 'esc_attr_x' => [0, 1, null],
    '_n' => [0, null, 1],
    '_nx' => [0, 3, 1],
];
// The domain is the last argument: 1 + highest used position, except _n (3) and _nx (4).
$domain_pos = [
    '__' => 1, '_e' => 1, 'esc_html__' => 1, 'esc_html_e' => 1, 'esc_attr__' => 1, 'esc_attr_e' => 1,
    '_x' => 2, 'esc_html_x' => 2, 'esc_attr_x' => 2, '_n' => 3, '_nx' => 4,
];

/** @return array<int, list<array{string,string}>> args as token lists */
function t_split_args(array $tokens, int $open): array
{
    $depth = 0;
    $args = [[]];
    for ($i = $open, $n = count($tokens); $i < $n; $i++) {
        $tok = $tokens[$i];
        $text = is_array($tok) ? $tok[1] : $tok;
        if ($text === '(' || $text === '[' || $text === '{') {
            $depth++;
            if ($depth === 1) {
                continue;
            }
        } elseif ($text === ')' || $text === ']' || $text === '}') {
            $depth--;
            if ($depth === 0) {
                return $args;
            }
        } elseif ($text === ',' && $depth === 1) {
            $args[] = [];
            continue;
        }
        if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $args[count($args) - 1][] = $tok;
    }
    return $args;
}

/** Literal value of an argument made of string literals joined by `.`; null if anything else. */
function t_literal(array $arg): ?string
{
    if ($arg === []) {
        return null;
    }
    $value = '';
    $expect_string = true;
    foreach ($arg as $tok) {
        if ($expect_string) {
            if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }
            $raw = $tok[1];
            $body = substr($raw, 1, -1);
            $value .= $raw[0] === "'"
                ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body)
                : stripcslashes($body);
        } elseif ($tok !== '.') {
            return null;
        }
        $expect_string = !$expect_string;
    }
    return $expect_string ? null : $value;
}

/** @return array{found: array<string,string>, dynamic: list<string>} key => "file:line" */
function t_scan(string $file, string $root, array $helpers, array $domain_pos): array
{
    $tokens = token_get_all((string) file_get_contents($file));
    $found = [];
    $dynamic = [];
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || $tok[0] !== T_STRING || !isset($helpers[$tok[1]])) {
            continue;
        }
        $prev = $tokens[$i - 1] ?? null;
        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
            continue;
        }
        $j = $i + 1;
        while (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (($tokens[$j] ?? null) !== '(') {
            continue;
        }
        $name = $tok[1];
        $args = t_split_args($tokens, $j);
        $where = substr($file, strlen($root) + 1) . ':' . $tok[2];
        $domain = t_literal($args[$domain_pos[$name]] ?? []);
        if ($domain !== 'sfxtheme') {
            continue; // another text domain (WordPress core strings) is not ours
        }
        [$id_pos, $ctx_pos, $plural_pos] = $helpers[$name];
        $msgid = t_literal($args[$id_pos] ?? []);
        $ctx = $ctx_pos === null ? '' : t_literal($args[$ctx_pos] ?? []);
        $plural = $plural_pos === null ? '' : t_literal($args[$plural_pos] ?? []);
        if ($msgid === null || $ctx === null || $plural === null) {
            $dynamic[] = $where . ' ' . $name . '()';
            continue;
        }
        $found[($ctx === '' ? '' : $ctx . "\x04") . $msgid . ($plural_pos === null ? '' : "\x00" . $plural)] = $where;
    }
    return ['found' => $found, 'dynamic' => $dynamic];
}

/** @return array<string, list<string>> key => msgstr list; fuzzy entries are left out (msgfmt drops them) */
function t_parse_po(string $path): array
{
    $entries = [];
    $cur = null;
    $field = null;
    $flush = static function () use (&$cur, &$entries): void {
        if ($cur !== null && isset($cur['id']) && $cur['id'] !== '' && empty($cur['fuzzy'])) {
            $key = (isset($cur['ctx']) ? $cur['ctx'] . "\x04" : '') . $cur['id']
                . (isset($cur['plural']) ? "\x00" . $cur['plural'] : '');
            $entries[$key] = $cur['str'] ?? [];
        }
        $cur = null;
    };
    $unquote = static fn(string $s): string => stripcslashes(substr(trim($s), 1, -1));
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (trim($line) === '') {
            $flush();
            $field = null;
            continue;
        }
        if ($line[0] === '#') {
            if (strpos($line, '#,') === 0 && strpos($line, 'fuzzy') !== false) {
                $cur ??= [];
                $cur['fuzzy'] = true;
            }
            continue;
        }
        if (preg_match('/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(".*")$/', $line, $m)) {
            if ($m[1] === 'msgid' && isset($cur['id'])) {
                $flush();
            }
            $cur ??= [];
            $value = $unquote($m[3]);
            if ($m[1] === 'msgctxt') {
                $cur['ctx'] = $value;
                $field = ['ctx'];
            } elseif ($m[1] === 'msgid') {
                $cur['id'] = $value;
                $field = ['id'];
            } elseif ($m[1] === 'msgid_plural') {
                $cur['plural'] = $value;
                $field = ['plural'];
            } else {
                $idx = (int) ($m[2] ?? 0);
                $cur['str'][$idx] = $value;
                $field = ['str', $idx];
            }
        } elseif ($line[0] === '"' && $field !== null && $cur !== null) {
            $value = $unquote($line);
            if (count($field) === 2) {
                $cur[$field[0]][$field[1]] .= $value;
            } else {
                $cur[$field[0]] .= $value;
            }
        }
    }
    $flush();
    return $entries;
}

// ---- files in scope
$files = [
    'inc/CustomDashboard/DashboardRenderer.php',
    'inc/CustomDashboard/Settings.php',
    'inc/DataPurge.php',
    'inc/GeneralThemeOptions/AdminPage.php',
    'inc/GeneralThemeOptions/Settings.php',
    'inc/ImportExport/Controller.php',
    'inc/ThemeSettingsOverview/OverviewProvider.php',
];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/inc/SiteCheck', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $info) {
    if ($info->isFile() && $info->getExtension() === 'php') {
        $files[] = substr($info->getPathname(), strlen($root) + 1);
    }
}
sort($files);
foreach ($files as $rel) {
    if (!is_file($root . '/' . $rel)) {
        t_fail("scoped file missing: {$rel}");
    }
}

$po = t_parse_po($root . '/languages/de_DE.po');
if ($po === []) {
    t_fail('de_DE.po parsed to no entries');
}

$total = 0;
$missing = [];
$dynamic = [];
foreach ($files as $rel) {
    $scan = t_scan($root . '/' . $rel, $root, $helpers, $domain_pos);
    $dynamic = array_merge($dynamic, $scan['dynamic']);
    foreach ($scan['found'] as $key => $where) {
        $total++;
        $strs = $po[$key] ?? null;
        $needed = strpos($key, "\x00") !== false ? 2 : 1;
        $ok = $strs !== null && count(array_filter($strs, static fn($s) => trim((string) $s) !== '')) >= $needed;
        if (!$ok) {
            $missing[] = $where . '  ' . str_replace(["\x04", "\x00"], [' | ctx ', ' | plural '], $key);
        }
    }
}

if ($total < 300) {
    t_fail("scan found only {$total} strings; the extractor is broken");
}
if ($dynamic !== []) {
    t_fail("sfxtheme calls with a non-literal text (cannot be checked, make them literal):\n  " . implode("\n  ", $dynamic));
}
if ($missing !== []) {
    t_fail(count($missing) . " of {$total} strings lack a German msgstr:\n  " . implode("\n  ", $missing));
}

// Spec: the status word for information is "Hinweis" everywhere; CustomDashboard's own "Note" keeps its translation.
if (($po["site check status\x04Note"][0] ?? '') !== 'Hinweis') {
    t_fail('_x(\'Note\', \'site check status\') must translate to "Hinweis"');
}
if (($po['Note'][0] ?? '') !== 'Notiz') {
    t_fail('the plain "Note" (CustomDashboard) must keep "Notiz"');
}

echo "PASS: all {$total} sfxtheme strings in " . count($files) . " files have a German translation\n";
