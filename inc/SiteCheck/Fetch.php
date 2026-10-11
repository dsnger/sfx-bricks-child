<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

use SFX\SiteCheck\Checks\FileChecks;

/**
 * Every outside request of the module goes through here (spec rules 6, 8, 9).
 * allowed_url() is rule 6 and the fetchable-URL rule in one predicate;
 * loopback() refuses anything it rejects without sending a request; remote()
 * is the only place that sends one: no cookies, no auth, redirects not
 * followed, 64 KB, 10 s.
 */
final class Fetch
{
    public const LIMIT = 65536;

    public const TIMEOUT = 10;

    /**
     * Seconds of Loopback work per observe call. A request started near the
     * end gets only what is left (at least 1 s), so one call stays near 21 s,
     * safely under PHP's usual 30 s max_execution_time.
     */
    public const BUDGET = 20.0;

    private static ?float $deadline = null;
    /** @var callable|null */
    private static $clock = null;

    /** Starts the time budget of one observe call. */
    public static function start_budget(float $seconds = self::BUDGET): void
    {
        self::$deadline = self::now() + $seconds;
    }

    /** For tests only: a fake clock (seconds as float), or null for microtime(). */
    public static function use_clock(?callable $clock): void
    {
        self::$clock = $clock;
    }

    private static function now(): float
    {
        return self::$clock === null ? microtime(true) : (float) (self::$clock)();
    }

    /**
     * Rule 6 and the fetchable-URL rule in one predicate: http(s) on the
     * host and port of home_url, no dot segments, no PHP-like extension
     * anywhere in the (fully decoded) path — except the PHP rule 6 allows:
     * xmlrpc.php, wp-admin/install.php, WordPress' front controller and the
     * module's own probe while it is being fetched — and no folder that could
     * run an unknown index script (see folder_refusal()).
     */
    public static function allowed_url(string $url): bool
    {
        return self::refusal($url) === '';
    }

    /** '' when the URL may be requested; else why not: host, encoding, path, php, dir, dir_unreadable, unmapped. */
    public static function refusal(string $url): string
    {
        if (!Locations::fetchable($url)) {
            return 'host';
        }
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        // Decoded exactly once, as the web server does: what is checked on
        // disk is what the server will open. An escape left after that one
        // decode (%2561) is ambiguous and never requested.
        $decoded = rawurldecode($path);
        if (preg_match('/%[0-9a-f]{2}/i', $decoded) === 1) {
            return 'encoding';
        }
        $decoded = str_replace('\\', '/', $decoded);
        if (preg_match('#(^|/)\.\.?(/|$)#', $decoded) === 1) {
            return 'path';
        }
        if (preg_match(FileChecks::PHP_LIKE, $decoded) !== 1) {
            return self::directory_refusal($decoded === '' ? '/' : $decoded);
        }
        // The module's own probe: only the exact URL Probe::run() is fetching right now.
        if ($decoded === $path && Probe::fetching($url)) {
            return '';
        }

        $allowed = [
            (string) wp_parse_url(site_url('/xmlrpc.php'), PHP_URL_PATH),
            (string) wp_parse_url(site_url('/wp-admin/install.php'), PHP_URL_PATH),
        ];
        $front = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/') . '/index.php';
        if ($decoded === $path && (in_array($path, $allowed, true) || $path === $front || strpos($path, $front . '/') === 0)) {
            return '';
        }

        return 'php';
    }

    /**
     * Rule 6 at the request boundary: a URL whose path is a folder on disk
     * runs that folder's index file (Apache DirectoryIndex), so it is sent
     * only when folder_refusal() clears the folder. The home page is
     * WordPress' front end (rule 6) and always allowed; a path no known
     * root maps cannot be verified and is refused. Paths that are no folder
     * on disk (pretty permalinks, feeds, REST) stay allowed.
     */
    private static function directory_refusal(string $decoded): string
    {
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if (rtrim($decoded, '/') === $home) {
            return '';
        }
        $disk = Locations::path_for_url($decoded);
        if ($disk === null) {
            return 'unmapped';
        }
        // Fail closed: only a path proven absent (its nearest existing
        // ancestor searchable) or a proven non-folder is let through.
        $state = FileChecks::disk_state($disk);
        if ($state === 'absent') {
            return '';
        }
        if ($state !== 'present' || @stat($disk) === false) {
            return 'dir_unreadable';
        }
        return @is_dir($disk) ? self::folder_refusal($disk) : '';
    }

    /**
     * Fails closed: '' only when the folder could be listed and holds no
     * PHP-like index file, or only the exact silence placeholder ('dir'
     * otherwise). An unreadable or missing folder is 'dir_unreadable'.
     */
    public static function folder_refusal(string $dir): string
    {
        $dir = untrailingslashit($dir);
        $names = @is_dir($dir) ? @scandir($dir) : false;
        if (!is_array($names)) {
            return 'dir_unreadable';
        }
        foreach ($names as $name) {
            if (stripos($name, 'index.') === 0 && preg_match(FileChecks::PHP_LIKE, $name) === 1 && !FileChecks::is_silence_file($dir . '/' . $name)) {
                return 'dir';
            }
        }

        return '';
    }

    public static function reason_text(string $refusal): string
    {
        if ($refusal === 'host') {
            return __('not on this site\'s address (other host, port or scheme); skipped, not fetched', 'sfxtheme');
        }
        if ($refusal === 'encoding') {
            return __('the address is encoded more than once, so it is unclear what the server would open; never requested', 'sfxtheme');
        }
        if ($refusal === 'path') {
            return __('not a plain path (dot segments); never requested', 'sfxtheme');
        }
        if ($refusal === 'dir') {
            return __('contains a PHP index file; never requested', 'sfxtheme');
        }
        if ($refusal === 'dir_unreadable') {
            return __('folder not readable; never requested', 'sfxtheme');
        }
        if ($refusal === 'unmapped') {
            return __('outside the known web folders, so it cannot be checked whether it runs a script; never requested', 'sfxtheme');
        }

        return __('the name contains a PHP extension; never requested', 'sfxtheme');
    }

    /**
     * A bounded Loopback request to an allowed URL; anything else is refused
     * without a request.
     */
    public static function loopback(string $method, string $url, ?string $body = null): array
    {
        if (!self::allowed_url($url)) {
            return self::no_answer('refused');
        }

        return self::remote($method, $url, $body);
    }

    /**
     * The only place that sends a request: no cookies, no auth, redirects not
     * followed, 64 KB, 10 s (rule 9). Callers decide the URL; only loopback()
     * and the https check's fixed http:// URL call it.
     *
     * WARNING: this bypasses refusal() and allowed_url() — it sends to any
     * URL it is given, PHP included (rule 6). It exists only for the https
     * check's fixed `http://` request, which allowed_url() refuses (not the
     * scheme and port of home_url). Every other request goes through loopback().
     */
    public static function remote(string $method, string $url, ?string $body = null): array
    {
        $timeout = self::TIMEOUT;
        if (self::$deadline !== null) {
            $left = self::$deadline - self::now();
            if ($left <= 0) {
                return self::no_answer('time_limit');
            }
            $timeout = max(1, min(self::TIMEOUT, (int) ceil($left)));
        }

        $args = [
            'timeout'             => $timeout,
            'redirection'         => 0,
            'limit_response_size' => self::LIMIT,
            'cookies'             => [],
            'headers'             => [],
            'sslverify'           => true,
        ];
        if ($method === 'POST') {
            $args['headers']['Content-Type'] = 'text/xml';
            $args['body'] = (string) $body;
            $response = wp_remote_post($url, $args);
        } else {
            $response = wp_remote_get($url, $args);
        }

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            // Timeout first: "cURL error 28: SSL connection timeout" is no TLS verdict.
            if (preg_match('/timed? ?out|cURL error 28\b/i', $message) === 1) {
                $kind = 'timeout';
            } else {
                $kind = preg_match('/ssl|tls|certificate/i', $message) === 1 ? 'tls' : 'network';
            }
            return self::no_answer($kind);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $headers = [];
        foreach (['location', 'content-type', 'x-robots-tag', 'cf-mitigated', 'x-amzn-waf-action'] as $name) {
            $value = wp_remote_retrieve_header($response, $name);
            $headers[$name] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
        $text = (string) wp_remote_retrieve_body($response);
        $truncated = strlen($text) >= self::LIMIT;
        $text = substr($text, 0, self::LIMIT);

        return [
            'status'    => $status,
            'headers'   => $headers,
            'body'      => $text,
            'truncated' => $truncated,
            'redirect'  => $status >= 300 && $status < 400,
            'location'  => $headers['location'],
            'challenge' => Observations::challenge($headers, $text),
            'error'     => '',
        ];
    }

    private static function no_answer(string $error): array
    {
        return ['status' => 0, 'headers' => [], 'body' => '', 'truncated' => false, 'redirect' => false, 'location' => '', 'challenge' => false, 'error' => $error];
    }
}
