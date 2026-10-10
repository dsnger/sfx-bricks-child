<?php

/**
 * The prompt-artifact path list is defined once, in CLAUDE.md §5 (whose rules live in
 * .claude/review-gates.md). AGENTS.md cites it
 * and must not restate it.
 *
 * A second copy of the list is the whole defect: on PR #32 AGENTS.md's copy had
 * `.claude/`, `skills/` and `commands/` but not `plugins/`, so a change under
 * a plugin's agents directory would have skipped the prompt-standards check with nothing
 * reporting an error. `docs/prompt-standards.md` (items 8 and 11) says to cite a
 * reachable authority rather than duplicate it — this pins that.
 *
 * Run: php tests/prompt-artifact-paths-test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$agents = file_get_contents($root . '/AGENTS.md');
assert_true(is_string($agents) && $agents !== '', 'AGENTS.md must be readable');

assert_true(
    preg_match('/\*\*Prompt artifacts\*\*(.+?)\n\n/s', $agents, $m) === 1,
    'AGENTS.md must carry a "**Prompt artifacts**" paragraph'
);
$paragraph = $m[1];

// It must point at the one authority...
assert_true(
    str_contains($paragraph, 'CLAUDE.md §5'),
    'the prompt-artifact paragraph must cite CLAUDE.md §5 as the definition'
);

// ...and must not carry a competing copy of the list. Only the classifier's own
// entries count: an unrelated `docs/` reference here is not drift.
$classifier = ['.claude/', 'plugins/', 'skills/', 'commands/'];
$restated = array_values(array_filter(
    $classifier,
    static fn(string $dir): bool => str_contains($paragraph, '`' . $dir . '`')
));
assert_true(
    $restated === [],
    'the prompt-artifact paragraph must not restate the path list — found: '
        . implode(' ', $restated) . "\n"
        . '  Cite CLAUDE.md §5 instead; a second copy is what drifts.'
);

// And the authority must actually be there to read. Since dev-workflow 0.21.0 §5 in
// CLAUDE.md is a pointer, and the rules it points at live in .claude/review-gates.md.
$claude = file_get_contents($root . '/CLAUDE.md');
assert_true(is_string($claude) && $claude !== '', 'CLAUDE.md must be readable');
$start = strpos($claude, '## 5. Cross-Model Review');
assert_true($start !== false, 'CLAUDE.md must still have a "## 5. Cross-Model Review" section');
// §5 is the last heading, so it ends at the footer rule rather than at a next heading.
$ends = array_filter([strpos($claude, "\n## ", $start + 1), strpos($claude, "\n---\n", $start)]);
$pointer = substr($claude, $start, $ends === [] ? null : min($ends) - $start);
assert_true(
    str_contains($pointer, '`.claude/review-gates.md`'),
    'CLAUDE.md §5 must still point at .claude/review-gates.md'
);
$section5 = file_get_contents($root . '/.claude/review-gates.md');
assert_true(is_string($section5) && $section5 !== '', '.claude/review-gates.md must be readable');

assert_true(
    str_contains($section5, 'What counts as prose'),
    '.claude/review-gates.md must still carry the "What counts as prose" definition AGENTS.md cites'
);
foreach ($classifier as $dir) {
    assert_true(
        str_contains($section5, '`' . $dir . '`'),
        ".claude/review-gates.md's definition must still name {$dir} — it is the only copy"
    );
}

echo "All prompt-artifact citation tests passed.\n";
exit(0);

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
