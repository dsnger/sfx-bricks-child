<?php

declare(strict_types=1);

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

require dirname(__DIR__) . '/inc/ContactInfos/Schema/OpeningHours.php';

use SFX\ContactInfos\Schema\OpeningHours;

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    $failures += $ok ? 0 : 1;
}

$open = static fn(array ...$r) => ['state' => 'open', 'ranges' => $r];
$prev = ['Monday' => ['state' => 'closed', 'ranges' => []]];

$r = OpeningHours::sanitize([], []);
check('unset_days_produce_nothing', OpeningHours::to_schema($r['hours']) === [] && $r['errors'] === []);

$r = OpeningHours::sanitize(['Monday' => ['state' => 'unset']], []);
check('all_unset_gives_empty_list', OpeningHours::to_schema($r['hours']) === []);

$r = OpeningHours::sanitize(['Sunday' => ['state' => 'closed']], []);
check('closed_day_is_00_00', OpeningHours::to_schema($r['hours']) === [[
    '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/Sunday', 'opens' => '00:00', 'closes' => '00:00',
]]);

$r = OpeningHours::sanitize(['Monday' => $open(['09:00', '12:00'], ['13:00', '17:00'])], []);
$s = OpeningHours::to_schema($r['hours']);
check('two_ranges_are_two_entries', $r['errors'] === [] && count($s) === 2 && $s[0] === [
    '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/Monday', 'opens' => '09:00', 'closes' => '12:00',
] && $s[1]['opens'] === '13:00');

$r = OpeningHours::sanitize(['Friday' => $open(['22:00', '02:00'])], []);
check('overnight_range_kept', $r['errors'] === [] && OpeningHours::to_schema($r['hours'])[0]['closes'] === '02:00');

$r = OpeningHours::sanitize(['Monday' => $open(['22:00', '02:00'], ['23:00', '23:30'])], $prev);
check('overnight_overlap_rejected', count($r['errors']) === 1);

$r = OpeningHours::sanitize(['Monday' => $open(['09:00', '13:00'], ['12:00', '18:00'])], $prev);
check('overlapping_ranges_rejected', count($r['errors']) === 1 && $r['hours']['Monday'] === $prev['Monday']);

$r = OpeningHours::sanitize(['Monday' => $open(['09:00', '13:00'], ['13:00', '18:00'])], []);
check('touching_ranges_allowed', $r['errors'] === []);

foreach (['25:00', '9:00', '', '09:60'] as $bad) {
    $r = OpeningHours::sanitize(['Monday' => $open([$bad, '17:00'])], $prev);
    check("malformed_time_rejected [$bad]", count($r['errors']) === 1 && str_contains($r['errors'][0], 'HH:MM') && $r['hours']['Monday'] === $prev['Monday']);
}
$r = OpeningHours::sanitize(['Monday' => $open(['', ''])], $prev);
check('open_without_range_rejected', count($r['errors']) === 1);

$r = OpeningHours::sanitize(['Monday' => $open(['09:00', '09:00'])], $prev);
check('equal_start_end_rejected', str_contains($r['errors'][0] ?? '', 'must differ'));

$r = OpeningHours::sanitize(['Monday' => $open(['08:00', '09:00'], ['10:00', '11:00'], ['12:00', '13:00'])], $prev);
check('more_than_two_ranges_rejected', count($r['errors']) === 1 && $r['errors'][0] === 'Monday: At most two time ranges per day.' && $r['hours']['Monday'] === $prev['Monday']);

$r = OpeningHours::sanitize(['Monday' => ['state' => 'bogus'], 'Tuesday' => $open(['09:00', '10:00'])], $prev);
check('invalid_day_does_not_block_others', count($r['errors']) === 1 && $r['hours']['Tuesday']['state'] === 'open');

exit($failures === 0 ? 0 : 1);
