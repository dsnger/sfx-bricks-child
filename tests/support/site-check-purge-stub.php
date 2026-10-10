<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * Stand-in for SiteCheck's purge segment in the DataPurge suites, which load
 * DataPurge without an autoloader. The real one is tested in
 * tests/site-check-probe-test.php; here a test sets what it reports.
 */
final class Purge
{
    /** @var array{options:int, probes_failed:list<string>, probes_unregistered?:list<string>, refused?:list<string>, busy:bool} */
    public static array $report = ['options' => 0, 'probes_failed' => [], 'busy' => false];

    public static int $calls = 0;

    public static function run(): array
    {
        self::$calls++;
        return self::$report;
    }
}
