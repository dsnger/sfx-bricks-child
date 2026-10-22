<?php

/**
 * bin/plugin.conf must pass the checks both canonical scripts run before anything else.
 *
 * Gate B cycle fi2i4pzg3s, pass 1, finding 1: a SHIPPED entry outside STD-REL-06
 * (wpml-config.xml) made bin/release.sh and bin/test-instance.sh exit 1 at startup,
 * and nothing in the battery ran either script, so it would have surfaced only at release.
 * Called without arguments, each script reads the config and then stops at its usage
 * line with exit 2; a config it rejects ends in exit 1 before that.
 *
 * Run: php tests/release-config-test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach (['bin/release.sh', 'bin/test-instance.sh'] as $script) {
    $output = [];
    $exit_code = 0;
    exec('bash ' . escapeshellarg($root . '/' . $script) . ' 2>&1', $output, $exit_code);
    $text = implode("\n", $output);
    assert_true(
        $exit_code === 2 && str_contains($text, 'usage:'),
        "{$script} rejected bin/plugin.conf before reaching its usage line (exit {$exit_code}): {$text}"
    );
}

echo "All release config tests passed.\n";
exit(0);
