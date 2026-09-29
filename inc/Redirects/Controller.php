<?php

declare(strict_types=1);

namespace SFX\Redirects;

/**
 * Wiring only: every decision lives in Rule (pure) or Repository (SQL).
 *
 * Matching is split across two hooks on purpose. It is decided at `wp` 0 —
 * the query exists, and a 410 must reset it before Bricks picks its templates
 * at `wp` 10 — but nothing is sent before `template_redirect` 0, after
 * PasswordProtected's gate (-10), so a protected site reveals no target and
 * counts no hit for a visitor who has not passed the gate.
 */
final class Controller
{
    private const CRON_HOOK = 'sfx_redirects_cleanup';

    /** Permalink tags that do not depend on the post's current terms (spec "Scope limit"). */
    private const SAFE_TAGS = [
        'postname', 'pagename', 'post_id', 'year', 'monthnum', 'day',
        'hour', 'minute', 'second', 'author',
    ];

    /**
     * The rule picked at `wp` 0, completed at `template_redirect` 0. Same
     * request, so a static is enough.
     *
     * @var null|array{id:int, status_code:int, url:?string}
     */
    private static ?array $match = null;

    public function __construct()
    {
        Settings::register();
        AdminPage::register();

        // Never on init: a front-end or CLI bootstrap must write nothing on the
        // module's behalf. The 1/100 cleanup in Repository covers the gap.
        add_action('admin_init', [Repository::class, 'maybe_install']);
        add_action('admin_init', [self::class, 'maybe_schedule_cleanup']);
        add_action(self::CRON_HOOK, [self::class, 'run_cleanup']);

        add_action('wp', [self::class, 'match_request'], 0);
        add_action('template_redirect', [self::class, 'send_match'], 0);
        // After redirect_canonical / wp_old_slug_redirect (10) and
        // wp_redirect_admin_locations (1000), each of which exits when it redirects.
        add_action('template_redirect', [self::class, 'log_404'], 9999);

        add_action('post_updated', [self::class, 'on_post_updated'], 10, 3);
    }

    public static function get_feature_config(): array
    {
        return [
            'class' => self::class,
            'menu_slug' => AdminPage::$menu_slug,
            'url' => admin_url('tools.php?page=sfx-redirects'),
            'page_title' => __(AdminPage::$page_title, 'sfxtheme'),
            'description' => __(AdminPage::$description, 'sfxtheme'),
            'activation_option_name' => 'sfx_general_options',
            'activation_option_key' => 'enable_redirects',
            'option_value' => true,
            'hook' => null,
            'error' => 'Missing Redirects Controller class in theme',
        ];
    }

    public static function maybe_schedule_cleanup(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    public static function run_cleanup(): void
    {
        Repository::maybe_cleanup(true);
    }

    // ---------------------------------------------------------------- matching

    public static function match_request(): void
    {
        self::$match = null;

        if (self::is_excluded()) {
            return;
        }

        $uri = self::request_uri();
        $home = home_url();
        $parts = Rule::request_parts($uri, (string) wp_parse_url($home, PHP_URL_PATH));

        $candidates = Repository::find_candidates($parts['path'], $parts['query']);
        if (!$candidates) {
            return;
        }

        $match = Rule::pick($candidates, $parts['path'], $parts['query'], $parts['raw_query'], $home);
        if ($match === null) {
            return;
        }

        // Status first: a 410 has no target, so there is nothing to compare.
        if ($match['status_code'] === 410) {
            if (headers_sent()) {
                return;
            }
            self::prepare_gone();
            self::$match = $match;
            return;
        }

        // Runtime loop check: a target that is this very URL is skipped.
        if (Rule::url_identity((string) $match['url']) === Rule::url_identity(self::current_url($uri, $home))) {
            return;
        }

        self::$match = $match;
    }

    public static function send_match(): void
    {
        $match = self::$match;
        if ($match === null || headers_sent()) {
            return;
        }

        if ($match['status_code'] === 410) {
            status_header(410);
            nocache_headers();
            Repository::record_hit($match['id']);
            // The request continues into the normal 404 template.
            return;
        }

        if (in_array($match['status_code'], [302, 307], true)) {
            nocache_headers();
        }

        // Sent exactly as pick() built it: the same string the identity check saw.
        if (wp_redirect((string) $match['url'], $match['status_code'], 'SFX Redirects')) {
            Repository::record_hit($match['id']);
            exit;
        }
        // A filter cancelled the redirect: no hit, no exit, the page renders.
    }

    /**
     * Feeds and sitemaps have their own header handling; the builder must be
     * able to edit a page that has a rule. Previews and builder markers are
     * visitor-controlled (?preview=true, a Referer with bricks=run), so they
     * only exempt a logged-in user who can edit posts.
     */
    private static function is_excluded(): bool
    {
        if (is_feed() || is_robots() || is_favicon()
            || get_query_var('sitemap') || get_query_var('sitemap-stylesheet')) {
            return true;
        }

        // Maintenance wins, like the password gate.
        if (class_exists('\Bricks\Maintenance') && \Bricks\Maintenance::get_mode()) {
            return true;
        }

        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return false;
        }

        return is_preview()
            || is_customize_preview()
            || (function_exists('bricks_is_builder') && bricks_is_builder())
            || (function_exists('bricks_is_builder_call') && bricks_is_builder_call())
            || isset($_GET['bricks_preview']);
    }

    /**
     * Empty 404 query, so Bricks selects its error template at `wp` 10 rather
     * than the page's content, and none of core's is_404() guesses redirect
     * the visitor away from the "gone".
     */
    private static function prepare_gone(): void
    {
        global $wp_query;

        $wp_query->set_404();
        $wp_query->posts = [];
        $wp_query->post_count = 0;
        $wp_query->post = null;
        $wp_query->queried_object = null;
        $wp_query->queried_object_id = 0;
        $GLOBALS['post'] = null;

        remove_action('template_redirect', 'redirect_canonical');
        remove_action('template_redirect', 'wp_old_slug_redirect');
        remove_action('template_redirect', 'wp_redirect_admin_locations', 1000);
    }

    // ---------------------------------------------------------------- 404 log

    public static function log_404(): void
    {
        if (!is_404() || http_response_code() !== 404) {
            return;
        }

        $settings = Settings::get();
        if (!$settings['log_404'] || !Repository::ready()) {
            return;
        }

        $parts = Rule::request_parts(self::request_uri(), (string) wp_parse_url(home_url(), PHP_URL_PATH));
        if (strlen($parts['path']) > 255) {
            return;
        }

        $referrer = $settings['log_referrer'] ? self::reduced_referrer() : '';

        // Repository stores '' for a referrer over 255 bytes or not valid UTF-8.
        Repository::log_404($parts['path'], $referrer);
    }

    /** Scheme + host + path only: a query can carry tokens or personal data. */
    private static function reduced_referrer(): string
    {
        $raw = isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER'])
            ? wp_unslash($_SERVER['HTTP_REFERER'])
            : '';
        $parts = $raw === '' ? false : wp_parse_url($raw);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '');
    }

    // ---------------------------------------------------------------- slug monitor

    public static function on_post_updated(int $post_id, \WP_Post $post_after, \WP_Post $post_before): void
    {
        if (!Settings::get()['auto_slug_redirect'] || !Repository::ready()) {
            return;
        }
        if ($post_before->post_status !== 'publish' || $post_after->post_status !== 'publish') {
            return;
        }
        $type = $post_after->post_type;
        if ($type === 'attachment' || !is_post_type_viewable($type)) {
            return;
        }
        if ($post_before->post_name === $post_after->post_name
            && (int) $post_before->post_parent === (int) $post_after->post_parent) {
            return;
        }
        if (!self::structure_is_safe($type)) {
            return;
        }

        $old = get_permalink($post_before);
        if (!is_string($old) || $old === '') {
            return;
        }
        $parts = wp_parse_url($old);
        // Plain ?p= permalinks: nothing path-shaped to redirect from.
        if (!is_array($parts) || isset($parts['query'])) {
            return;
        }

        $home_path = (string) wp_parse_url(home_url(), PHP_URL_PATH);
        $old_source = Rule::canonical_path(Rule::home_relative((string) ($parts['path'] ?? '/'), $home_path));

        Repository::on_slug_change($post_id, $old_source);
    }

    /**
     * Term tags (%category% and friends) are recomputed from the post's
     * CURRENT terms, already saved when post_updated fires, so the old URL
     * could come out wrong. Any tag outside the allowlist → do nothing.
     */
    private static function structure_is_safe(string $post_type): bool
    {
        global $wp_rewrite;

        if ($post_type === 'post') {
            $structure = (string) get_option('permalink_structure');
        } elseif ($post_type === 'page') {
            $structure = '%pagename%';
        } else {
            $structure = (string) $wp_rewrite->get_extra_permastruct($post_type);
        }

        preg_match_all('/%([^%\/]+)%/', $structure, $tags);
        $allowed = array_merge(self::SAFE_TAGS, [$post_type]);

        return array_diff($tags[1], $allowed) === [];
    }

    // ---------------------------------------------------------------- request

    private static function request_uri(): string
    {
        return isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? wp_unslash($_SERVER['REQUEST_URI'])
            : '/';
    }

    /** The absolute URL of this request, for the runtime identity check. */
    private static function current_url(string $uri, string $home): string
    {
        $host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])
            ? wp_unslash($_SERVER['HTTP_HOST'])
            : (string) wp_parse_url($home, PHP_URL_HOST);

        return (is_ssl() ? 'https' : 'http') . '://' . $host . $uri;
    }
}
