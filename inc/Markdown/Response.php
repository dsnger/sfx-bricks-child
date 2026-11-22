<?php

declare(strict_types=1);

namespace SFX\Markdown;

/** Spec § Markdown response: capture the template's HTML, convert it, or send it unchanged. */
class Response
{
    /** @var string The template WordPress chose. */
    private static $template = '';
    /** @var int Output buffer level of the capture buffer. */
    private static $level = 0;
    /** @var bool P3: the capture handler ran before the wrapper ended the buffer. */
    private static $broken = false;
    /** @var bool Set right before the wrapper ends the capture buffer itself. */
    private static $ending = false;

    /** template_include at PHP_INT_MAX: swap in the wrapper for an eligible request that asks for Markdown. */
    public static function maybe_take_over($template)
    {
        if (!is_string($template) || !is_file($template) || !Request::is_eligible() || !Request::wants_markdown()) {
            return $template;
        }
        self::$template = $template;

        return __DIR__ . '/wrapper.php';
    }

    /** Opens the capture buffer; returns the template for the wrapper to include at global scope. */
    public static function capture(): string
    {
        self::$broken = false;
        self::$ending = false;
        ob_start([self::class, 'handle'], 0, PHP_OUTPUT_HANDLER_STDFLAGS);
        self::$level = ob_get_level();

        return self::$template;
    }

    /** P3: passes everything through unchanged; a call the wrapper did not make marks the capture broken. */
    public static function handle(string $buffer, int $phase): string
    {
        if (!self::$ending) {
            self::$broken = true;
        }

        return $buffer;
    }

    /**
     * The template threw: send what it printed on, as it would have gone without the module, so
     * whether headers were sent before the error stays the same.
     */
    public static function release(): void
    {
        if (self::intact()) {
            self::$ending = true;
            ob_end_flush();
        }
    }

    /** The template returned. One that calls exit never gets here: the shutdown drain releases the capture unchanged. */
    public static function finish(): void
    {
        if (!self::intact()) {
            return; // The template touched the capture: leave every buffer to the shutdown drain, as without the module.
        }
        self::$ending = true;
        $html = (string) ob_get_clean();
        $status = http_response_code();
        $headers = headers_list();
        // Fail closed: any Throwable before commit() passes the captured HTML through. A PHP fatal
        // error (memory, time limit) cannot be caught here; it ends the request without the page.
        // Callbacks below must not print: dropped on both paths, and held back so an echo cannot
        // send the headers before restore_headers() puts them back.
        ob_start();
        $discard = ob_get_level();
        $failed = false;
        try {
            $queried = get_queried_object(); // Explicit: a bare call reads the global $post, which a loop may have left changed.
            $canonical = $queried instanceof \WP_Post ? wp_get_canonical_url($queried) : null;
            $link = is_string($canonical) && $canonical !== '' ? self::link_header($canonical) : null;
            $markdown = $link !== null ? self::markdown($html, $canonical) : null;
        } catch (\Throwable $e) {
            $failed = true;
        }
        // Close what a callback opened and left behind, down to our own discard buffer, before the
        // headers are restored: a leftover handler may throw or change the status as it is closed.
        // Bounded: a buffer that cannot be removed, or an attempt that leaves the level where it
        // was (a site handler that turns the notice into an exception), ends the cleanup in doubt.
        for ($level = ob_get_level(); $level >= $discard; $level = ob_get_level()) {
            if (!(ob_get_status()['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE)) {
                $failed = true;
                break;
            }
            try {
                ob_end_clean();
            } catch (\Throwable $e) {
                $failed = true; // PHP removed the buffer whose handler threw.
            }
            if (ob_get_level() >= $level) {
                $failed = true;
                break;
            }
        }
        // A leftover handler that changed the status without throwing is in doubt too.
        if ($failed || http_response_code() !== $status) {
            self::restore_headers($status, $headers);
            $markdown = null;
        }
        if ($markdown === null) {
            echo $html; // Pass-through: unfiltered, so core's own buffer filters it once (P1).
            return;
        }
        self::commit($markdown, $link);
    }

    /** Converting steps 1–3 plus P1/P2; null means pass-through. */
    private static function markdown(string $html, string $canonical): ?string
    {
        foreach (ob_get_status(true) as $buffer) {
            if (!($buffer['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE)) {
                return null; // P2: the commit could not remove it.
            }
        }
        if (self::passes_through((int) http_response_code(), headers_list(), headers_sent())) {
            return null;
        }
        ob_start(); // Filter callbacks must not print; core drops their output too.
        try {
            // Unfiltered first: a page that cannot convert leaves the filter to core alone.
            $markdown = Converter::convert($html, $canonical);
            if ($markdown === null || !self::enhances()) {
                return $markdown;
            }
            // As core's buffer callback: a user error in a filter becomes an exception (template.php).
            set_error_handler(static function (int $level, string $message): bool {
                if ($level === E_USER_ERROR) {
                    throw new \ErrorException($message, 0, $level);
                }
                return false;
            });
            try {
                // Accepted limit: a filter that destroys the content area ran here and runs again in core.
                $filtered = (string) apply_filters('wp_template_enhancement_output_buffer', $html, $html);
            } finally {
                restore_error_handler();
            }
            return Converter::convert($filtered, $canonical);
        } finally { // A throw reaches finish(): in doubt, the captured HTML goes out unchanged.
            ob_end_clean();
        }
    }

    /** Puts back the status and headers a throwing callback changed; once headers are sent, nothing can be taken back. */
    private static function restore_headers($status, array $headers): void
    {
        if (headers_sent()) {
            return;
        }
        $by_name = static function (array $list): array {
            $out = [];
            foreach ($list as $line) {
                $out[strtolower(trim(explode(':', $line, 2)[0]))][] = $line;
            }
            return $out;
        };
        $before = $by_name($headers);
        $now = $by_name(headers_list());
        // Per name: a bare header_remove() also drops PHP's default Content-Type.
        foreach (array_keys($before + $now) as $name) {
            if (($before[$name] ?? []) !== ($now[$name] ?? [])) {
                header_remove($name);
                foreach ($before[$name] ?? [] as $line) {
                    header($line, false);
                }
                if ($name === 'content-type' && !isset($before[$name])) {
                    self::default_content_type(); // Removing it would otherwise send none.
                }
            }
        }
        if (is_int($status) && http_response_code() !== $status) {
            http_response_code($status);
        }
    }

    /** The Content-Type PHP sends when none was set (main/SAPI.c, sapi_get_default_content_type). */
    private static function default_content_type(): void
    {
        $type = (string) ini_get('default_mimetype');
        $charset = (string) ini_get('default_charset');
        if ($type !== '') {
            header('Content-Type: ' . $type . ($charset !== '' && stripos($type, 'text/') === 0 ? '; charset=' . $charset : ''));
        }
    }

    /** Converting step 1. */
    public static function passes_through(int $status, array $headers_list, bool $headers_sent): bool
    {
        if ($headers_sent || $status !== 200) {
            return true;
        }
        $type = null;
        foreach ($headers_list as $line) {
            $pair = explode(':', $line, 2);
            if (count($pair) !== 2) {
                continue;
            }
            $name = strtolower(trim($pair[0]));
            if ($name === 'location') {
                return true;
            }
            if ($name === 'content-type' && $type === null) {
                $type = $pair[1]; // PHP sends the first one, as core's enhancement check assumes.
            }
        }
        $type = $type ?? (string) ini_get('default_mimetype');

        return strtolower(trim(explode(';', $type, 2)[0])) !== 'text/html';
    }

    /** R6: the canonical Link header; null (pass-through) when the URL holds a control character. */
    public static function link_header(string $canonical): ?string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $canonical)) {
            return null;
        }

        return 'Link: <' . strtr($canonical, ['<' => '%3C', '>' => '%3E', ' ' => '%20', '"' => '%22', ',' => '%2C']) . '>; rel="canonical"';
    }

    /** Converting step 4, then Commit. */
    private static function commit(string $markdown, string $link): void
    {
        // First: page caches decide inside their buffer handlers, which the removal below runs.
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        // Only output written before the template; P2 made every one removable. Core's buffer
        // sees the CLEAN phase and returns early: no filter, no action.
        for ($i = ob_get_level(); $i > 0; $i--) {
            try {
                ob_end_clean();
            } catch (\Throwable $e) {
                // PHP removes a buffer whose handler throws. The buffers above it are gone already,
                // so the captured HTML could no longer go out as it would without the module: send
                // the Markdown and log the handler's failure.
                error_log('SFX Markdown: an output buffer handler threw while the Markdown response removed it: ' . $e->getMessage());
            }
        }
        if (self::enhances()) {
            // P1: what core does for a response that is not HTML (template.php:1025-1029).
            ob_start();
            try {
                do_action('wp_finalized_template_enhancement_output_buffer', $markdown);
            } catch (\Throwable $e) {
                // As in core: an action that throws does not stop the response.
            }
            ob_end_clean();
        }
        // Last, right before the body: the handlers and the action above may have set headers too.
        header_remove('Content-Length');
        header_remove('ETag');
        header_remove('Last-Modified');
        header('Content-Type: text/markdown; charset=UTF-8');
        Headers::merge_vary('Accept');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex');
        header($link);
        echo $markdown;
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            // Accepted limit (spec § Commit): drops later output unless other code drains buffers.
            self::discard();
            // R5: core drains at shutdown priority 1 (default-filters.php); re-open right after it.
            add_action('shutdown', [self::class, 'discard'], 1);
        }
    }

    /** A buffer that drops whatever is printed into it. */
    public static function discard(): void
    {
        ob_start(static function (): string {
            return '';
        });
    }

    /** Spec step 3 and P3: the capture buffer is still the top one and the template never touched it. */
    private static function intact(): bool
    {
        return !self::$broken && ob_get_level() === self::$level;
    }

    /** Whether core would enhance this template (WP 6.9+). */
    private static function enhances(): bool
    {
        return function_exists('wp_should_output_buffer_template_for_enhancement')
            && wp_should_output_buffer_template_for_enhancement();
    }
}
