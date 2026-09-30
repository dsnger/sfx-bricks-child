<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/EditorProse/Starter.php';

use SFX\EditorProse\Starter;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nexpected: " . var_export($expected, true) . "\nactual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// 1. Exact transformation of a small fixture shaped like prose.css.
$fixture = "/* header */\n@layer sfx.reset, sfx.utilities, sfx.components, sfx.theme;\n\n@layer sfx.components {\n\n  :where(.sfx-prose) {\n    color: var(--text-body, inherit);\n  }\n\n  :where(.sfx-prose) h2:not(:where(.sfx-prose [class*=\"brxe-\"], .sfx-prose [class*=\"brxe-\"] *)) {\n    color: var(--text-title, inherit);\n  }\n}\n";
$expected = "%root% {\n  color: var(--text-body, inherit);\n}\n\n"
    . "%root% h2:not(:where(%root% [class*=\"brxe-\"], %root% [class*=\"brxe-\"] *)) {\n  color: var(--text-title, inherit);\n}\n";
assert_same($expected, Starter::from_baseline($fixture), '1: exact transformation');

// 2. The real baseline file converts completely.
$source = (string) file_get_contents(__DIR__ . '/../inc/EditorProse/assets/prose.css');
$starter = Starter::from_file();
assert_same(0, substr_count($starter, 'sfx-prose'), '2: no sfx-prose left');
assert_same(0, substr_count($starter, '@layer'), '2: no @layer left');
assert_same(0, substr_count($starter, '/*'), '2: no comments');
assert_same(true, str_starts_with($starter, '%root% {'), '2: starts with the wrapper rule');
assert_same(substr_count($source, '{') - 1, substr_count($starter, '{'), '2: same rule count (minus the layer block)');

// Collect token names from source and starter, compare counts for equality.
preg_match_all('/--[a-z0-9-]+/', $source, $source_m);
preg_match_all('/--[a-z0-9-]+/', $starter, $starter_m);
$source_counts = array_count_values($source_m[0]);
$starter_counts = array_count_values($starter_m[0]);
assert_same($source_counts, $starter_counts, '2: every token name kept, same count');

foreach (array_filter(array_map('trim', explode('}', $starter))) as $chunk) {
    $prelude = trim(explode('{', $chunk)[0]);
    $prelude = trim(preg_replace('#^/\*.*?\*/#s', '', $prelude) ?? '');
    if ($prelude === '') {
        continue;
    }
    foreach (preg_split('/,(?![^(]*\))/', $prelude) as $sel) {
        assert_same(true, str_starts_with(trim($sel), '%root%'), '2: selector starts with %root%: ' . trim($sel));
    }
}

// 3. Missing file -> empty string, no warning escapes.
assert_same('', Starter::from_path(__DIR__ . '/does-not-exist.css'), '3: missing file');

echo "editor-prose-starter-test: PASS\n";
