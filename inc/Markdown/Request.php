<?php

declare(strict_types=1);

namespace SFX\Markdown;

class Request
{
    /** D10: the full HTML parser exists from WP 6.7; below that the front end stays inert. */
    public static function parser_available(): bool
    {
        return class_exists('WP_HTML_Processor') && method_exists('WP_HTML_Processor', 'create_full_parser');
    }

    /** D8: text/markdown listed explicitly with q > 0 and not below the effective q of text/html. */
    public static function accepts_markdown(string $accept): bool
    {
        $q = [];
        foreach (explode(',', $accept) as $range) {
            $params = explode(';', $range);
            $type = strtolower(trim(array_shift($params)));
            if ($type === '' || isset($q[$type])) {
                continue; // A repeated range uses its first occurrence.
            }
            $q[$type] = 1.0;
            foreach ($params as $param) {
                $pair = explode('=', $param, 2);
                if (strtolower(trim($pair[0])) === 'q') {
                    $value = trim($pair[1] ?? '');
                    // A malformed q counts as 1.
                    $q[$type] = preg_match('/^(0(\.\d{0,3})?|1(\.0{0,3})?)$/', $value) ? (float) $value : 1.0;
                    break;
                }
            }
        }

        $markdown = $q['text/markdown'] ?? 0.0;
        $html = $q['text/html'] ?? $q['text/*'] ?? $q['*/*'] ?? 0.0;

        return $markdown > 0 && $markdown >= $html;
    }

    public static function wants_markdown(): bool
    {
        if (($_GET['markdown'] ?? null) === '1') {
            return true;
        }
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        return is_string($accept) && self::accepts_markdown($accept);
    }

    /** Spec § Eligible request: the one decision used by the response, the head link and Vary. */
    public static function is_eligible(): bool
    {
        if (!self::parser_available()) {
            return false;
        }
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request())) {
            return false;
        }
        // P8: Bricks builder, its preview iframe and its AJAX/REST calls.
        foreach (['bricks_is_builder', 'bricks_is_builder_iframe', 'bricks_is_builder_call'] as $fn) {
            if (function_exists($fn) && $fn()) {
                return false;
            }
        }
        if (is_feed() || is_embed() || is_trackback() || is_robots() || is_favicon() || is_preview()
            || get_query_var('sitemap')) {
            return false;
        }
        if (!is_singular()) {
            return false;
        }

        $post = get_queried_object();
        // Checked here, not via is_singular($types): is_singular([]) is true for every type.
        return $post instanceof \WP_Post
            && in_array(get_post_type($post), Settings::get()['post_types'], true)
            && get_post_status($post) === 'publish'
            && (int) get_option('page_for_posts') !== (int) $post->ID
            && !post_password_required($post);
    }

    /** D7: the request's canonical URL (keeps the page part) plus markdown=1. */
    public static function markdown_url(): ?string
    {
        $queried = get_queried_object(); // Explicit: a bare call reads the global $post, which a loop may have left changed.
        $url = $queried instanceof \WP_Post ? wp_get_canonical_url($queried) : null;

        return is_string($url) && $url !== '' ? add_query_arg('markdown', '1', $url) : null;
    }
}
