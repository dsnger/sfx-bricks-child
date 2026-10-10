<?php

/**
 * PHP branches and the end of their upstream security support (php_version).
 *
 * Source: https://www.php.net/supported-versions.php (8.2–8.5) and
 * https://www.php.net/eol.php (7.0–8.1), both read on 2026-10-10. Update the
 * table and `checked` together; a branch missing here grades Nicht prüfbar.
 */

defined('ABSPATH') || exit;

return [
    'source'  => 'https://www.php.net/supported-versions.php',
    'checked' => '2026-10-10',
    'security_until' => [
        '7.0' => '2019-01-10',
        '7.1' => '2019-12-01',
        '7.2' => '2020-11-30',
        '7.3' => '2021-12-06',
        '7.4' => '2022-11-28',
        '8.0' => '2023-11-26',
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    ],
];
