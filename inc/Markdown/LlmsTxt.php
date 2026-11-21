<?php

declare(strict_types=1);

namespace SFX\Markdown;

/**
 * Site-wide /llms.txt (D13): answers only an address nothing else claimed.
 */
class LlmsTxt
{
    private const LIMIT = 500;

    /** @return array<string,string> */
    public static function headers(): array
    {
        return [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-SFX-Llms-Txt' => '1',
        ];
    }

    /**
     * D13: exact path, no query string, status 404 (or Rank Math's own 200), no Bricks maintenance.
     * GET/HEAD only and no feed/robots request: WordPress also reads query vars from a POST body, and
     * the password gate exempts feed and robots requests.
     */
    public static function matches(string $request_uri, string $home_path, int $status, ?string $matched_rule, bool $maintenance_applied, string $method, bool $is_feed, bool $is_robots): bool
    {
        if ($maintenance_applied || $is_feed || $is_robots || !in_array($method, ['GET', 'HEAD'], true) || str_contains($request_uri, '?')) {
            return false;
        }
        if ($request_uri !== rtrim($home_path, '/') . '/llms.txt') {
            return false;
        }

        return $status === 404 || ($status === 200 && $matched_rule === '^llms\.txt$');
    }

    public static function maybe_serve(): void
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $home_path = (string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/');
        $rule = $GLOBALS['wp']->matched_rule ?? null;
        // A missing class or method counts as applied: better no index than one past maintenance.
        $maintenance = !class_exists('\Bricks\Maintenance') || !method_exists('\Bricks\Maintenance', 'is_applied')
            || \Bricks\Maintenance::is_applied();

        if (!self::matches($uri, $home_path, (int) http_response_code(), is_string($rule) ? $rule : null, (bool) $maintenance, (string) ($_SERVER['REQUEST_METHOD'] ?? ''), is_feed(), is_robots())) {
            return;
        }

        status_header(200);
        foreach (self::headers() as $name => $value) {
            header("{$name}: {$value}");
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        echo self::render();
        exit;
    }

    public static function render(): string
    {
        $out = '# ' . Escape::label(self::decode((string) get_bloginfo('name'))) . "\n";
        $tagline = self::decode((string) get_bloginfo('description'));
        if ($tagline !== '') {
            $out .= "\n> " . Escape::label($tagline) . "\n";
        }
        $labels = Settings::offered_post_types();
        foreach (self::listed() as $type => $posts) {
            $out .= "\n## " . Escape::label($labels[$type] ?? $type) . "\n\n";
            foreach (array_slice($posts, 0, self::LIMIT) as $post) {
                $out .= '- [' . Escape::label(self::decode((string) get_the_title($post))) . '](' . Escape::destination(self::address($post)) . ")\n";
            }
            if (count($posts) > self::LIMIT) {
                $out .= "\n" . Escape::block_start(Escape::text(__('More entries exist than are listed here.', 'sfxtheme'))) . "\n";
            }
        }

        return $out;
    }

    /** Permalink of the newest listed post, without ?markdown=1; null when nothing is listed. */
    public static function newest_listed_url(): ?string
    {
        $newest = null;
        foreach (self::listed() as $posts) {
            foreach (array_slice($posts, 0, self::LIMIT) as $post) {
                if ($newest === null || $post->post_date > $newest->post_date) {
                    $newest = $post;
                }
            }
        }

        return $newest === null ? null : (string) get_permalink($newest);
    }

    /** @return array<string,\WP_Post[]> included type => up to LIMIT + 1 posts; empty types left out */
    private static function listed(): array
    {
        $index = (int) get_option('page_for_posts');
        $out = [];
        foreach (Settings::get()['post_types'] as $type) {
            $args = [
                'post_type' => $type,
                'post_status' => 'publish',
                'has_password' => false,
                'numberposts' => self::LIMIT + 1,
                'orderby' => $type === 'page' ? ['menu_order' => 'ASC', 'title' => 'ASC'] : 'date',
                'order' => 'DESC',
            ];
            if ($type === 'page' && $index > 0) {
                $args['post__not_in'] = [$index];
            }
            $posts = get_posts($args);
            if ($posts !== []) {
                $out[$type] = $posts;
            }
        }

        return $out;
    }

    private static function address($post): string
    {
        return add_query_arg('markdown', '1', (string) get_permalink($post));
    }

    /** WordPress stores names and titles entity-encoded, and get_the_title() adds numeric entities (wptexturize). */
    private static function decode(string $s): string
    {
        return \WP_HTML_Decoder::decode_text_node($s);
    }
}
