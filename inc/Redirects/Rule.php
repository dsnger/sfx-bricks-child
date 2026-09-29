<?php

declare(strict_types=1);

namespace SFX\Redirects;

/**
 * Every redirect decision that can be made without WordPress state.
 *
 * Pure on purpose: no $wpdb, no options, no hooks, no current user. The only
 * WordPress functions used are wp_parse_url, wp_sanitize_redirect and __,
 * which the stubbed test copies or delegates. That keeps canonical forms,
 * validation, matching, loop checks and CSV mapping testable in one place, and
 * means Repository, Controller and AdminPage can only ever agree with each
 * other, because none of them re-implements any of it.
 */
final class Rule
{
    public const STATUS_CODES = [301, 302, 307, 308, 410];
    public const MATCH_TYPES = ['exact', 'regex'];
    public const MAX_SOURCE_BYTES = 255;
    public const MAX_TARGET_BYTES = 2000;
    public const MAX_NOTE_BYTES = 255;
    public const MAX_REGEX_SUBJECT = 1024;
    public const MAX_ENABLED_REGEX = 200;

    // ponytail: the regex loop gives up after 50 ms in total; remaining rules
    // count as "no match". Together with the 200-rule cap this bounds the cost
    // an unlucky pattern set can add to a request.
    private const REGEX_BUDGET_SECONDS = 0.05;
    private const PCRE_BACKTRACK_LIMIT = '100000';
    private const PCRE_RECURSION_LIMIT = '10000';

    /** $1–$9 only; "$10" is $1 followed by a literal 0. Nine groups are enough. */
    private const PLACEHOLDER = '/\$([1-9])/';

    /**
     * Stand-in for placeholders when validating a template target. The real
     * template never passes wp_sanitize_redirect, which strips "$".
     */
    private const SENTINEL = 'x';

    private const CSV_COLUMNS = ['source', 'target', 'status_code', 'match_type', 'enabled', 'note'];

    /**
     * Carries the header width inside the map, so csv_record() can reject a
     * record with more fields than the header without the caller having to
     * pass the width separately. Not a valid column name (names are matched
     * against CSV_COLUMNS), so it cannot collide.
     */
    private const CSV_WIDTH_KEY = '#columns';

    private const CSV_BOOL = [
        '1'     => true,
        'yes'   => true,
        'true'  => true,
        '0'     => false,
        'no'    => false,
        'false' => false,
    ];

    /** Leading characters a spreadsheet may execute as a formula; "'" so the escape itself round-trips. */
    private const CSV_TRIGGERS = ['=', '+', '-', '@', "\t", "\r", "\n", "'", "\u{FF1D}", "\u{FF0B}", "\u{FF0D}", "\u{FF20}"];

    // ---------------------------------------------------------------- canonical forms

    /**
     * The one coordinate system every source and request is compared in.
     *
     * Decode once, drop empty and "." segments, resolve ".." (never above root),
     * re-encode each segment, lowercase ASCII. Decode-then-encode makes it
     * idempotent, and re-encoding keeps a decoded "?" or "#" inside the path.
     * ASCII-only case folding: "%C3%BC" ≡ "%c3%bc", but "Ü" ≢ "ü" — stated, no
     * mbstring dependency.
     */
    public static function canonical_path(string $raw): string
    {
        $path = rawurldecode(substr($raw, 0, strcspn($raw, '?#')));

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = rawurlencode($segment);
        }

        return strtolower('/' . implode('/', $segments));
    }

    /**
     * Only the order of query pieces is normalised. No parse_str(): it would
     * merge repeated keys and mangle dotted or nested names, so two different
     * queries could collapse into one identity.
     */
    public static function canonical_query(string $raw): string
    {
        $pieces = array_values(array_filter(
            explode('&', $raw),
            static fn(string $piece): bool => $piece !== ''
        ));
        sort($pieces, SORT_STRING);

        return implode('&', $pieces);
    }

    /**
     * @return array{path:string, query:string, raw_query:string}
     *         query = canonical (lookup + identity); raw_query = as sent, minus
     *         the leading "?" (what query passthrough appends — the visitor's
     *         exact bytes, not our reordering of them).
     */
    public static function request_parts(string $request_uri, string $home_path): array
    {
        $cut = strcspn($request_uri, '?#');
        $path = substr($request_uri, 0, $cut);

        $raw_query = '';
        if (($request_uri[$cut] ?? '') === '?') {
            $rest = substr($request_uri, $cut + 1);
            $raw_query = substr($rest, 0, strcspn($rest, '#'));
        }

        if ($home_path !== '') {
            $path = self::home_relative($path, $home_path);
        }

        return [
            'path'      => self::canonical_path($path),
            'query'     => self::canonical_query($raw_query),
            'raw_query' => $raw_query,
        ];
    }

    /**
     * Strip the home path once, and only as a whole segment: "/blog/x" → "/x",
     * "/blogger" untouched. Case-insensitive, as WP::parse_request() strips it.
     */
    public static function home_relative(string $path, string $home_path): string
    {
        return self::strip_home($path, $home_path) ?? $path;
    }

    /**
     * The UNIQUE key: byte-exact regardless of the table's collation, so the
     * database itself refuses a duplicate even under concurrent writes.
     */
    public static function source_hash(string $match_type, string $source): string
    {
        return sha1($match_type . "\n" . $source);
    }

    // ---------------------------------------------------------------- target predicate

    /**
     * A target is a site-relative path or an absolute http(s) URL without
     * credentials. Comparing against wp_sanitize_redirect() means what we
     * validate is exactly what wp_redirect() will send.
     *
     * Dot segments are rejected (literal or encoded): browsers resolve them
     * with rules that differ from our source aliasing, so "/a//../b" could loop
     * back to a source without the loop checks seeing it.
     */
    public static function target_ok(string $target): bool
    {
        if ($target === '' || preg_match('/[\s\\\\\x00-\x1F\x7F]/', $target) === 1) {
            return false;
        }
        if (wp_sanitize_redirect($target) !== $target) {
            return false;
        }

        if ($target[0] === '/') {
            if (isset($target[1]) && ($target[1] === '/' || $target[1] === '\\')) {
                return false;
            }
            $path = substr($target, 0, strcspn($target, '?#'));
        } else {
            $parts = wp_parse_url($target);
            if (!is_array($parts)) {
                return false;
            }
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if (!in_array($scheme, ['http', 'https'], true)
                || (string) ($parts['host'] ?? '') === ''
                // Plain ASCII hostnames only (IDNs as punycode). A browser decodes
                // "%65x.test" to "ex.test", so an encoded host would slip past
                // the same-site loop checks and bounce straight back.
                || preg_match('/^[a-z0-9.-]+$/i', (string) $parts['host']) !== 1
                || !self::canonical_ipv4_or_name((string) $parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])
            ) {
                return false;
            }
            $path = (string) ($parts['path'] ?? '');
        }

        return !self::has_dot_segment($path);
    }

    /**
     * Browsers read a host whose last label is numeric (or 0x…) as IPv4 and
     * normalise it: "127.1", "0x7f000001" and "2130706433" all become
     * 127.0.0.1. Such a host is accepted only in canonical dotted-quad form, so
     * the loop checks compare what the browser will actually request.
     */
    private static function canonical_ipv4_or_name(string $host): bool
    {
        $labels = explode('.', rtrim($host, '.'));
        $last   = (string) end($labels);
        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/i', $last) !== 1) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    // ---------------------------------------------------------------- regex

    /**
     * "~" is the delimiter, so an unescaped "~" in the body would end the
     * pattern early and turn the rest into modifiers. Rejected rather than
     * escaped for the author: the body is shown back exactly as stored.
     *
     * @return string|null the compiled pattern, or null if invalid
     */
    public static function compile_regex(string $body): ?string
    {
        if (self::has_unescaped_tilde($body)) {
            return null;
        }

        $pattern = '~' . $body . '~iu';

        return @preg_match($pattern, '') === false ? null : $pattern;
    }

    /**
     * @param array $input raw strings/bools: source, match_type, target, status_code, enabled(bool), note
     * @return array{rule: array{source:string, match_type:string, target:string, status_code:int, enabled:bool, note:string}, errors: list<string>}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $bad = [];
        $values = [];

        foreach (['source', 'match_type', 'target', 'status_code', 'note'] as $field) {
            $value = $input[$field] ?? '';
            // The slug monitor builds its input in PHP; an int status is not
            // form tampering, so it is not worth an error.
            if ($field === 'status_code' && is_int($value)) {
                $value = (string) $value;
            }
            if (!is_string($value)) {
                /* translators: %s: field label */
                $errors[] = sprintf(__('%s must be text.', 'sfxtheme'), self::label($field));
                $bad[$field] = true;
                $value = '';
            }
            $values[$field] = $value;
        }

        $enabled = $input['enabled'] ?? true;
        if (!is_bool($enabled)) {
            $errors[] = __('Enabled must be yes or no.', 'sfxtheme');
            $enabled = true;
        }

        $status = 301;
        if ($values['status_code'] !== '') {
            $status = ctype_digit($values['status_code']) ? (int) $values['status_code'] : 0;
        }
        if (!isset($bad['status_code']) && !in_array($status, self::STATUS_CODES, true)) {
            $errors[] = __('Choose a status code of 301, 302, 307, 308 or 410.', 'sfxtheme');
        }

        // The target of a 410 is ignored entirely, junk included.
        if ($status === 410) {
            $values['target'] = '';
        }

        foreach ($values as $field => $value) {
            if (isset($bad[$field])) {
                continue;
            }
            if (preg_match('//u', $value) !== 1) {
                /* translators: %s: field label */
                $errors[] = sprintf(__('%s is not valid UTF-8.', 'sfxtheme'), self::label($field));
                $bad[$field] = true;
            } elseif (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                /* translators: %s: field label */
                $errors[] = sprintf(__('%s must not contain control characters.', 'sfxtheme'), self::label($field));
                $bad[$field] = true;
            }
        }

        $match_type = $values['match_type'] === '' ? 'exact' : $values['match_type'];
        if (!isset($bad['match_type']) && !in_array($match_type, self::MATCH_TYPES, true)) {
            $errors[] = __('Choose the match type exact or regex.', 'sfxtheme');
            $bad['match_type'] = true;
        }

        $source = $values['source'];
        $groups = null;
        if (!isset($bad['source'])) {
            if ($source === '') {
                $errors[] = __('Enter a source.', 'sfxtheme');
                $bad['source'] = true;
            } elseif ($match_type === 'regex') {
                if (strlen($source) > self::MAX_SOURCE_BYTES) {
                    /* translators: %d: maximum length in bytes */
                    $errors[] = sprintf(__('The regular expression must be at most %d bytes long.', 'sfxtheme'), self::MAX_SOURCE_BYTES);
                    $bad['source'] = true;
                } elseif (self::has_unescaped_tilde($source)) {
                    $errors[] = __('The regular expression contains an unescaped ~; write \~ instead.', 'sfxtheme');
                    $bad['source'] = true;
                } elseif (self::compile_regex($source) === null) {
                    $errors[] = __('The regular expression is not valid.', 'sfxtheme');
                    $bad['source'] = true;
                } else {
                    $groups = self::group_count($source);
                }
            } elseif ($source[0] !== '/') {
                $errors[] = __('Enter the path only, e.g. /old-page.', 'sfxtheme');
                $bad['source'] = true;
            } elseif (str_contains($source, '#')) {
                $errors[] = __('The source must not contain #.', 'sfxtheme');
                $bad['source'] = true;
            } else {
                $source = self::canonical_path_and_query($source);
                if (strlen($source) > self::MAX_SOURCE_BYTES) {
                    /* translators: %d: maximum length in bytes */
                    $errors[] = sprintf(__('The source must be at most %d bytes long.', 'sfxtheme'), self::MAX_SOURCE_BYTES);
                    $bad['source'] = true;
                }
            }
        }

        $target = $values['target'];
        if ($status !== 410 && !isset($bad['target'])) {
            if ($target === '') {
                $errors[] = __('Enter a target.', 'sfxtheme');
            } elseif (strlen($target) > self::MAX_TARGET_BYTES) {
                /* translators: %d: maximum length in bytes */
                $errors[] = sprintf(__('The target must be at most %d bytes long.', 'sfxtheme'), self::MAX_TARGET_BYTES);
            } elseif ($match_type === 'regex' && self::placeholder_in_authority($target)) {
                $errors[] = __('Placeholders such as $1 are only allowed in the path of the target, not in its scheme or host.', 'sfxtheme');
            } elseif (!self::target_ok($match_type === 'regex' ? self::with_sentinel($target) : $target)) {
                $errors[] = __('Enter a target path starting with / or a full http(s) URL, without spaces, backslashes, ./.. segments or special characters.', 'sfxtheme');
            } elseif ($groups !== null && self::max_placeholder($target) > $groups) {
                /* translators: %d: number of groups in the regular expression */
                $errors[] = sprintf(__('The target refers to a group the regular expression does not have (it has %d).', 'sfxtheme'), $groups);
            }
        }

        if (!isset($bad['note']) && strlen($values['note']) > self::MAX_NOTE_BYTES) {
            /* translators: %d: maximum length in bytes */
            $errors[] = sprintf(__('The note must be at most %d bytes long.', 'sfxtheme'), self::MAX_NOTE_BYTES);
        }

        return [
            'rule'   => [
                'source'      => $source,
                'match_type'  => $match_type,
                'target'      => $target,
                'status_code' => $status,
                'enabled'     => $enabled,
                'note'        => $values['note'],
            ],
            'errors' => $errors,
        ];
    }

    // ---------------------------------------------------------------- matching

    /**
     * Exact path+query beats exact path beats regex (id order).
     *
     * @param list<array{id:int, source:string, match_type:string, target:string, status_code:int}> $candidates
     * @return null|array{id:int, status_code:int, url:?string}  url null for 410; url is
     *         the final ABSOLUTE URL incl. raw-query passthrough, after resolve_target +
     *         target_ok + length cap + wp_sanitize_redirect; this exact string is used
     *         for the runtime identity check AND for sending. A match whose target
     *         fails resolution returns null — the request continues normally, it does
     *         not fall through to a weaker rule.
     */
    public static function pick(array $candidates, string $path, string $query, string $raw_query, string $home_url): ?array
    {
        $exact_query = null;
        $exact_path = null;
        $regex = [];

        foreach ($candidates as $row) {
            $type = (string) ($row['match_type'] ?? '');
            $source = (string) ($row['source'] ?? '');
            if ($type === 'exact') {
                if ($query !== '' && $source === $path . '?' . $query) {
                    $exact_query ??= $row;
                } elseif ($source === $path) {
                    $exact_path ??= $row;
                }
            } elseif ($type === 'regex') {
                $regex[] = $row;
            }
        }

        // The rule fixed its query, so the request's query is not passed through.
        if ($exact_query !== null) {
            return self::finish($exact_query, null, '', $home_url);
        }
        if ($exact_path !== null) {
            return self::finish($exact_path, null, $raw_query, $home_url);
        }

        return self::pick_regex($regex, $path, $raw_query, $home_url);
    }

    /**
     * Fill $1–$9 from the captures. Captures come from the canonical path, so
     * they are already encoded and are inserted as they are.
     *
     * @return string|null null when the result is not a valid target, too long,
     *         or (absolute) points at a different host than the template.
     */
    public static function resolve_target(string $target, array $captures): ?string
    {
        $resolved = preg_replace_callback(
            self::PLACEHOLDER,
            static fn(array $m): string => (string) ($captures[(int) $m[1]] ?? ''),
            $target
        );

        if (!is_string($resolved) || strlen($resolved) > self::MAX_TARGET_BYTES || !self::target_ok($resolved)) {
            return null;
        }

        if ($target[0] !== '/') {
            $template_host = wp_parse_url(self::with_sentinel($target), PHP_URL_HOST);
            $host = wp_parse_url($resolved, PHP_URL_HOST);
            if (!is_string($template_host) || !is_string($host) || strtolower($template_host) !== strtolower($host)) {
                return null;
            }
        }

        return $resolved;
    }

    /**
     * Append the request's query only when the target has none. No per-key
     * merging: encoded key aliases ("%72ole") would let a visitor override a
     * parameter the rule fixed. A fragment stays after the query.
     */
    public static function append_query(string $target, string $query): string
    {
        if ($query === '') {
            return $target;
        }

        $hash = strpos($target, '#');
        $base = $hash === false ? $target : substr($target, 0, $hash);
        $fragment = $hash === false ? '' : substr($target, $hash);

        if (str_contains($base, '?')) {
            return $target;
        }

        return $base . '?' . $query . $fragment;
    }

    // ---------------------------------------------------------------- loops

    /**
     * Scheme, host, effective port, canonical path, canonical query. Scheme is
     * part of it on purpose: http → https on the same path is not a loop.
     */
    public static function url_identity(string $absolute_url): string
    {
        $parts = wp_parse_url($absolute_url);
        if (!is_array($parts) || (string) ($parts['host'] ?? '') === '') {
            // Never equal to a real identity.
            return 'invalid:' . $absolute_url;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $rest = (string) ($parts['path'] ?? '/');
        if (isset($parts['query'])) {
            $rest .= '?' . $parts['query'];
        }

        return $scheme . '://' . strtolower((string) $parts['host']) . ':' . $port . self::canonical_path_and_query($rest);
    }

    /**
     * Same-host canonical home-relative path (+ "?"query), the form sources are
     * stored in. Host comparison ignores the scheme (an http target is still
     * served by this site); a non-default port or a path outside the home path
     * is another site.
     *
     * @return string|null null for external targets and for 410 (empty) targets
     */
    public static function target_path(string $target, string $home_url): ?string
    {
        if ($target === '') {
            return null;
        }

        if ($target[0] === '/') {
            if (isset($target[1]) && ($target[1] === '/' || $target[1] === '\\')) {
                return null;
            }
            // Relative targets are home-relative already.
            return self::canonical_path_and_query($target);
        }

        $parts = wp_parse_url($target);
        $home = wp_parse_url($home_url);
        if (!is_array($parts) || !is_array($home)) {
            return null;
        }
        if (strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($home['host'] ?? ''))
            || self::explicit_port($parts) !== self::explicit_port($home)
        ) {
            return null;
        }

        $path = self::strip_home((string) ($parts['path'] ?? '/'), (string) ($home['path'] ?? ''));
        if ($path === null) {
            return null;
        }
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        return self::canonical_path_and_query($path);
    }

    /**
     * Save-time loop decision (spec "Loop prevention", both checks), for a rule
     * about to become an enabled exact rule. The caller decides that it is one;
     * regex and 410 rules have no checkable outgoing edge and pass.
     *
     * (a) target equals the rule's own source (path + query) — unless the
     *     target is absolute with a scheme other than home's (an upgrade).
     * (b) A→B→A through one other enabled exact rule. Paths only: a path-only
     *     source matches every query and passes it through, so "/a → /b" plus
     *     "/b → /a?x=1" loops. Conservative on purpose.
     *
     * @param array $rule    validated rule (source canonical)
     * @param int   $id      the rule's own id (0 for a new rule), excluded from $reverse
     * @param array $reverse enabled exact rules (id, source, target, status_code)
     * @return string|null   error message, null = no conflict
     */
    public static function loop_conflict(array $rule, int $id, array $reverse, string $home_url): ?string
    {
        if (($rule['match_type'] ?? '') !== 'exact' || (int) ($rule['status_code'] ?? 0) === 410) {
            return null;
        }

        $source = (string) ($rule['source'] ?? '');
        $target = (string) ($rule['target'] ?? '');
        $target_path = self::target_path($target, $home_url);
        if ($target_path === null) {
            return null;
        }

        if ($target_path === $source && !self::is_scheme_change($target, $home_url)) {
            return __('The target is the same address as the source; the redirect would point to itself.', 'sfxtheme');
        }

        $source_only = self::without_query($source);
        $target_only = self::without_query($target_path);

        foreach ($reverse as $other) {
            $other_id = (int) ($other['id'] ?? 0);
            if (($id > 0 && $other_id === $id)
                || (int) ($other['status_code'] ?? 0) === 410
                || ($other['match_type'] ?? 'exact') !== 'exact'
                || self::without_query((string) ($other['source'] ?? '')) !== $target_only
            ) {
                continue;
            }

            $back = self::target_path((string) ($other['target'] ?? ''), $home_url);
            if ($back !== null && self::without_query($back) === $source_only) {
                return sprintf(
                    /* translators: %d: id of the other redirect rule */
                    __('This redirect would create a loop: rule #%d redirects its target back to this source.', 'sfxtheme'),
                    $other_id
                );
            }
        }

        return null;
    }

    /**
     * One function builds the emitted URL, for sending and for identity alike.
     * String concatenation, never home_url($path): home_url() runs filters and
     * would re-encode what we already validated.
     */
    public static function absolute_target(string $target, string $home_url): string
    {
        if ($target !== '' && $target[0] === '/') {
            return rtrim($home_url, '/') . $target;
        }

        return $target;
    }

    // ---------------------------------------------------------------- CSV

    /**
     * Prefix a formula-like cell with "'" so a spreadsheet opening the file
     * shows it as text. "'" is itself a trigger, which keeps the transform
     * reversible: "'=x" → "''=x" → "'=x".
     */
    public static function csv_escape_cell(string $cell): string
    {
        return self::starts_with_trigger($cell) ? "'" . $cell : $cell;
    }

    /** Remove exactly the one "'" csv_escape_cell() added, nothing else. */
    public static function csv_unescape_cell(string $cell): string
    {
        if (str_starts_with($cell, "'") && self::starts_with_trigger(substr($cell, 1))) {
            return substr($cell, 1);
        }

        return $cell;
    }

    /**
     * Header names are trimmed and lowercased; a BOM on the first one is
     * stripped. Unknown and empty names are ignored (a spreadsheet's trailing
     * empty columns are not an error); a repeated name rejects the file, since
     * we could not know which column the author meant.
     *
     * The "target" column is not required here: it may be absent when every
     * row is a 410. A missing target then fails validate() for any 3xx record.
     *
     * @param list<string> $header raw header row
     * @return array{map: array<string,int>, errors: list<string>}
     */
    public static function csv_header_map(array $header): array
    {
        $map = [];
        $errors = [];
        $seen = [];

        foreach (array_values($header) as $index => $name) {
            $name = is_string($name) ? $name : '';
            if ($index === 0 && str_starts_with($name, "\xEF\xBB\xBF")) {
                $name = substr($name, 3);
            }
            $name = strtolower(trim($name));
            if ($name === '') {
                continue;
            }
            if (isset($seen[$name])) {
                /* translators: %s: CSV column name */
                $errors[] = sprintf(__('The column "%s" appears more than once in the header.', 'sfxtheme'), $name);
                continue;
            }
            $seen[$name] = true;
            if (in_array($name, self::CSV_COLUMNS, true)) {
                $map[$name] = $index;
            }
        }

        if (!isset($map['source'])) {
            $errors[] = __('The header has no "source" column.', 'sfxtheme');
        }

        $map[self::CSV_WIDTH_KEY] = count($header);

        return ['map' => $map, 'errors' => $errors];
    }

    /**
     * Maps one record to validate() input. Missing columns and empty cells
     * become empty strings, which validate() turns into its defaults. Blank
     * lines are the reader's to skip; they never reach here.
     *
     * @return array|string validate() input, or an error message for this record
     */
    public static function csv_record(array $record, array $map)
    {
        if (count($record) > (int) ($map[self::CSV_WIDTH_KEY] ?? 0)) {
            return __('This record has more fields than the header (an unterminated quote or a stray comma?).', 'sfxtheme');
        }

        $cell = static function (string $name) use ($record, $map): string {
            if (!isset($map[$name])) {
                return '';
            }
            $value = $record[$map[$name]] ?? '';

            return is_string($value) ? self::csv_unescape_cell($value) : '';
        };

        $enabled = strtolower(trim($cell('enabled')));
        if ($enabled !== '' && !array_key_exists($enabled, self::CSV_BOOL)) {
            /* translators: %s: the cell value */
            return sprintf(__('"%s" is not a valid value for enabled; use 1/0, yes/no or true/false.', 'sfxtheme'), $enabled);
        }

        return [
            'source'      => $cell('source'),
            'target'      => $cell('target'),
            'status_code' => $cell('status_code'),
            'match_type'  => $cell('match_type'),
            'enabled'     => $enabled === '' ? true : self::CSV_BOOL[$enabled],
            'note'        => $cell('note'),
        ];
    }

    // ---------------------------------------------------------------- private helpers

    /**
     * @param list<array> $rows regex candidates
     */
    private static function pick_regex(array $rows, string $path, string $raw_query, string $home_url): ?array
    {
        if ($rows === [] || strlen($path) > self::MAX_REGEX_SUBJECT) {
            return null;
        }

        usort($rows, static fn(array $a, array $b): int => (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));

        $backtrack = ini_get('pcre.backtrack_limit');
        $recursion = ini_get('pcre.recursion_limit');
        ini_set('pcre.backtrack_limit', self::PCRE_BACKTRACK_LIMIT);
        ini_set('pcre.recursion_limit', self::PCRE_RECURSION_LIMIT);

        try {
            $start = microtime(true);
            foreach ($rows as $row) {
                if (microtime(true) - $start > self::REGEX_BUDGET_SECONDS) {
                    return null;
                }
                $pattern = self::compile_regex((string) ($row['source'] ?? ''));
                if ($pattern === null) {
                    continue;
                }
                $captures = [];
                // false (limit hit) counts as no match, like 0.
                if (preg_match($pattern, $path, $captures) !== 1) {
                    continue;
                }

                return self::finish($row, $captures, $raw_query, $home_url);
            }

            return null;
        } finally {
            if ($backtrack !== false) {
                ini_set('pcre.backtrack_limit', $backtrack);
            }
            if ($recursion !== false) {
                ini_set('pcre.recursion_limit', $recursion);
            }
        }
    }

    /**
     * Build the result for a matched row.
     *
     * @param array|null $captures regex captures, null for exact rules
     */
    private static function finish(array $row, ?array $captures, string $raw_query, string $home_url): ?array
    {
        $id = (int) ($row['id'] ?? 0);
        $status = (int) ($row['status_code'] ?? 0);

        if ($status === 410) {
            return ['id' => $id, 'status_code' => 410, 'url' => null];
        }
        if (!in_array($status, self::STATUS_CODES, true)) {
            return null;
        }

        $target = (string) ($row['target'] ?? '');
        if ($captures !== null) {
            $target = self::resolve_target($target, $captures);
        } elseif (!self::target_ok($target)) {
            $target = null;
        }
        if ($target === null) {
            return null;
        }

        $url = self::append_query($target, $raw_query);
        if (strlen($url) > self::MAX_TARGET_BYTES) {
            return null;
        }

        return [
            'id'          => $id,
            'status_code' => $status,
            'url'         => wp_sanitize_redirect(self::absolute_target($url, $home_url)),
        ];
    }

    private static function canonical_path_and_query(string $value): string
    {
        $path = self::canonical_path($value);
        $question = strpos($value, '?');
        if ($question === false) {
            return $path;
        }

        $rest = substr($value, $question + 1);
        $query = self::canonical_query(substr($rest, 0, strcspn($rest, '#')));

        return $query === '' ? $path : $path . '?' . $query;
    }

    private static function without_query(string $value): string
    {
        return substr($value, 0, strcspn($value, '?'));
    }

    /** @return string|null the home-relative path, or null when $path is not under the home path */
    private static function strip_home(string $path, string $home_path): ?string
    {
        $home = rtrim($home_path, '/');
        if ($home === '') {
            return $path;
        }

        $lower_path = strtolower($path);
        $lower_home = strtolower($home);
        if ($lower_path === $lower_home) {
            return '/';
        }
        if (str_starts_with($lower_path, $lower_home . '/')) {
            return substr($path, strlen($home));
        }

        return null;
    }

    /** Explicit port with scheme defaults folded away, so http://h:80 ≡ https://h. */
    private static function explicit_port(array $parts): ?int
    {
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            return null;
        }

        return $port;
    }

    private static function is_scheme_change(string $target, string $home_url): bool
    {
        if ($target === '' || $target[0] === '/') {
            return false;
        }

        return strtolower((string) wp_parse_url($target, PHP_URL_SCHEME))
            !== strtolower((string) wp_parse_url($home_url, PHP_URL_SCHEME));
    }

    private static function has_dot_segment(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '.' || $decoded === '..') {
                return true;
            }
        }

        return false;
    }

    /** Backslash-parity aware: "\~" is escaped, "\\~" is not. */
    private static function has_unescaped_tilde(string $body): bool
    {
        $backslashes = 0;
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];
            if ($char === '\\') {
                $backslashes++;
                continue;
            }
            if ($char === '~' && $backslashes % 2 === 0) {
                return true;
            }
            $backslashes = 0;
        }

        return false;
    }

    /**
     * Number of capture groups. The empty alternative makes the pattern match
     * "", and PREG_UNMATCHED_AS_NULL reports every group, trailing ones too.
     * Named groups appear twice in $m (name and number); only numbers count.
     */
    private static function group_count(string $body): int
    {
        $m = [];
        // The body stays first, so leading directives such as (*UTF) or (?x) keep
        // working; the newline ends a trailing (?x) comment before the empty
        // alternative that guarantees a match.
        if (@preg_match('~' . $body . "\n|~iu", '', $m, PREG_UNMATCHED_AS_NULL) === false) {
            return 0;
        }

        return count(array_filter(array_keys($m), 'is_int')) - 1;
    }

    private static function max_placeholder(string $target): int
    {
        $max = 0;
        if (preg_match_all(self::PLACEHOLDER, $target, $m) > 0) {
            $max = max(array_map('intval', $m[1]));
        }

        return $max;
    }

    /**
     * Placeholders may only follow the authority: in scheme, host, port or
     * userinfo a capture could send the visitor to another site. Relative
     * targets have no authority; the resolved check catches "//evil" there.
     */
    private static function placeholder_in_authority(string $target): bool
    {
        if ($target === '' || $target[0] === '/') {
            return false;
        }

        $after_scheme = strpos($target, '//');
        $slash = strpos($target, '/', $after_scheme === false ? 0 : $after_scheme + 2);
        $authority = $slash === false ? $target : substr($target, 0, $slash);

        return preg_match(self::PLACEHOLDER, $authority) === 1;
    }

    private static function with_sentinel(string $target): string
    {
        return (string) preg_replace(self::PLACEHOLDER, self::SENTINEL, $target);
    }

    private static function starts_with_trigger(string $cell): bool
    {
        foreach (self::CSV_TRIGGERS as $trigger) {
            if (str_starts_with($cell, $trigger)) {
                return true;
            }
        }

        return false;
    }

    private static function label(string $field): string
    {
        switch ($field) {
            case 'source':
                return __('Source', 'sfxtheme');
            case 'target':
                return __('Target', 'sfxtheme');
            case 'match_type':
                return __('Match type', 'sfxtheme');
            case 'status_code':
                return __('Status code', 'sfxtheme');
            default:
                return __('Note', 'sfxtheme');
        }
    }
}
