<?php

declare(strict_types=1);

namespace SFX\Markdown;

/**
 * Settings-page loopback checks (spec § /llms.txt "Settings-page check", D16). Pure classifiers plus
 * the requests; results hold raw detail text, escaped where the page prints it.
 */
class LoopbackCheck
{
    /** @return array{outcome:string, detail:string} */
    public static function classify_llms($response): array
    {
        if (is_wp_error($response)) {
            return ['outcome' => 'failed', 'detail' => $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 300 && $code < 400) {
            return ['outcome' => 'redirected', 'detail' => (string) wp_remote_retrieve_header($response, 'location')];
        }
        if ($code === 404 || $code === 410) {
            return ['outcome' => 'none', 'detail' => (string) $code];
        }
        if ($code !== 200) {
            return ['outcome' => 'failed', 'detail' => (string) $code];
        }
        if ((string) wp_remote_retrieve_header($response, 'x-sfx-llms-txt') === '1') {
            return ['outcome' => 'theme', 'detail' => ''];
        }
        $type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if (str_contains($type, 'text/html') || str_contains($type, 'application/xhtml+xml')) {
            return ['outcome' => 'html', 'detail' => $type];
        }
        if (str_contains($type, 'text/plain')) {
            return ['outcome' => 'foreign', 'detail' => $type];
        }

        return ['outcome' => 'failed', 'detail' => '200 ' . ($type === '' ? '-' : $type)];
    }

    /** @return string one of no_convert, cache_html, clean, failed */
    public static function classify_cache($control, $warm, $accept): string
    {
        if (self::unusable($control)) {
            return 'failed';
        }
        if (!self::is_markdown($control)) {
            return 'no_convert';
        }
        if (self::unusable($warm) || self::unusable($accept)) {
            return 'failed';
        }

        return self::is_markdown($accept) ? 'clean' : 'cache_html';
    }

    /** @return string[] switch-off guidance for the active SEO plugins, then the file line */
    public static function guidance(): array
    {
        $out = [];
        if (defined('WPSEO_VERSION')) {
            $out[] = __("Yoast SEO → Settings → llms.txt: switch off 'Enable llms.txt file feature'.", 'sfxtheme');
        }
        if (defined('AIOSEO_VERSION')) {
            $out[] = __("All in One SEO → Sitemaps → LLMs.txt: switch off 'Enable llms.txt'.", 'sfxtheme');
        }
        if (defined('SEOPRESS_PRO_VERSION')) {
            $out[] = __("SEOPress PRO: switch off llms.txt in SEOPress PRO's settings.", 'sfxtheme');
        }
        $out[] = __('If a file llms.txt stays in the site root, remove it there.', 'sfxtheme');

        return $out;
    }

    public static function get(string $url, array $headers = [])
    {
        $args = ['timeout' => 5, 'redirection' => 0, 'sslverify' => apply_filters('https_local_ssl_verify', false)];
        if ($headers !== []) {
            $args['headers'] = $headers;
        }

        return wp_remote_get($url, $args);
    }

    /** @return array{outcome:string, detail:string} */
    public static function run_llms(string $url): array
    {
        return self::classify_llms(self::get($url));
    }

    /** Control (?markdown=1), then the plain address so a cache can store it, then the plain address asking for Markdown. */
    public static function run_cache(string $page_url): string
    {
        $control = self::get(add_query_arg('markdown', '1', $page_url));
        if (self::unusable($control) || !self::is_markdown($control)) {
            return self::classify_cache($control, null, null);
        }
        $warm = self::get($page_url);

        return self::classify_cache($control, $warm, self::get($page_url, ['Accept' => 'text/markdown']));
    }

    private static function unusable($response): bool
    {
        return is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200;
    }

    private static function is_markdown($response): bool
    {
        return str_contains(strtolower((string) wp_remote_retrieve_header($response, 'content-type')), 'text/markdown');
    }
}
