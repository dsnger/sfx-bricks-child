<?php

/**
 * Content signatures (spec "Evidence rules"). Each group is name => PCRE
 * pattern, the shape Evidence::signature() takes; only the matched NAME is
 * ever kept (rule 7). `archive` patterns run on the hex of the first 512
 * bytes (Task 4's `head_hex`), all others on text.
 *
 * shell: deliberately short — the well-known one-liner shapes that run code
 * straight from a request or from an encoded blob. It is a hint for a human,
 * not a malware scanner; a miss still shows the file as "other content".
 */

defined('ABSPATH') || exit;

return [
    // "[10-Oct-2026 10:00:00 UTC] PHP Warning: …" — PHP's own error_log line.
    'log' => [
        'php_log_line' => '/^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}(?: [A-Za-z0-9_\/+-]+)?\] PHP (?:Fatal error|Warning|Notice|Parse error|Deprecated|Stack trace|Catchable fatal error|Recoverable fatal error)/m',
    ],
    'sql' => [
        'sql_dump_header' => '/^-- (?:MySQL|MariaDB) dump/m',
        'sql_create_table' => '/\bCREATE TABLE\b/',
    ],
    'archive' => [
        'zip'  => '/^504b0304/i',
        'gzip' => '/^1f8b/i',
        // "ustar" at offset 257 (514 hex digits in).
        'tar'  => '/^[0-9a-f]{514}7573746172/i',
    ],
    'git_head'   => ['git_head_ref' => '/^ref: refs\//m'],
    'git_config' => ['git_config_core' => '/^\[core\]/m'],
    'env'        => ['env_line' => '/^[A-Z][A-Z0-9_]*=\S*$/m'],
    'shell' => [
        'eval_encoded'   => '/\beval\s*\(\s*(?:base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i',
        'eval_request'   => '/\beval\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE|SERVER)\b/i',
        'assert_request' => '/\bassert\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE|SERVER)\b/i',
        'exec_request'   => '/\b(?:system|exec|shell_exec|passthru|popen|proc_open)\s*\(\s*\$_(?:GET|POST|REQUEST|COOKIE|SERVER)\b/i',
    ],
    // WordPress' own placeholder; only files of at most 64 bytes qualify.
    'silence' => [
        'silence_is_golden' => '/^<\?php\s*\/\/\s*Silence is golden\.?\s*(?:\?>)?\s*$/i',
    ],
];
