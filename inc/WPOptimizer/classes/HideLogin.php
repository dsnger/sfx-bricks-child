<?php

declare(strict_types=1);

namespace SFX\WPOptimizer\classes;

defined('ABSPATH') or die('Pet a cat!');

class HideLogin
{
    private static string $slug = '';

    private static bool $filters_registered = false;

    /** @var bool Used by tests to confirm same-pass request handling. */
    private static bool $handle_request_called = false;

    /**
     * @var list<string>
     */
    private const RESERVED_SLUGS = [
        'wp-admin',
        'wp-login',
        'wp-login.php',
        'wp-content',
        'wp-includes',
        'wp-signup.php',
        'wp-register.php',
        'admin',
        'login',
        'xmlrpc.php',
        'wp-json',
        'feed',
        'sitemap',
        'author',
        'page',
        'category',
        'tag',
    ];

    public static function normalize_slug(string $slug): string
    {
        $slug = trim($slug);
        $slug = trim($slug, '/');
        $slug = sanitize_title($slug);

        return $slug;
    }

    public static function is_reserved_slug(string $slug): bool
    {
        $slug = strtolower(self::normalize_slug($slug));

        if ($slug === '') {
            return false;
        }

        return in_array($slug, self::RESERVED_SLUGS, true);
    }

    public static function is_valid_slug(string $slug): bool
    {
        return self::get_invalid_slug_reason($slug) === null;
    }

    public static function get_invalid_slug_reason(string $slug): ?string
    {
        $slug = self::normalize_slug($slug);

        if ($slug === '') {
            return __('Custom login slug is required when Hide Login URL is enabled.', 'sfxtheme');
        }

        if (strlen($slug) < 3) {
            return __('Custom login slug must be at least 3 characters.', 'sfxtheme');
        }

        if (self::is_reserved_slug($slug)) {
            return __('That slug matches a reserved WordPress path.', 'sfxtheme');
        }

        if (self::slug_conflicts_with_public_route($slug)) {
            return __('That slug matches an existing page, post, content type, taxonomy, or other public URL on this site.', 'sfxtheme');
        }

        return null;
    }

    private static function slug_conflicts_with_public_route(string $slug): bool
    {
        if (function_exists('get_page_by_path')) {
            $page = get_page_by_path($slug);
            if (!empty($page)) {
                return true;
            }
        }

        if (function_exists('get_posts')) {
            $post_types = function_exists('get_post_types')
                ? get_post_types(['public' => true])
                : ['post', 'page'];

            $posts = get_posts([
                'name'           => $slug,
                'post_type'      => $post_types,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);

            if (!empty($posts)) {
                return true;
            }
        }

        if (function_exists('get_post_types')) {
            foreach (get_post_types(['public' => true], 'objects') as $post_type) {
                if (!is_object($post_type) || $post_type->name === 'attachment') {
                    continue;
                }

                $rewrite = $post_type->rewrite ?? false;
                if (!is_array($rewrite)) {
                    continue;
                }

                $rewrite_slug = $rewrite['slug'] ?? $post_type->name;
                if (is_string($rewrite_slug) && sanitize_title($rewrite_slug) === $slug) {
                    return true;
                }
            }
        }

        if (function_exists('get_taxonomies')) {
            foreach (get_taxonomies(['public' => true], 'objects') as $taxonomy) {
                if (!is_object($taxonomy)) {
                    continue;
                }

                $rewrite = $taxonomy->rewrite ?? false;
                if (!is_array($rewrite)) {
                    continue;
                }

                $rewrite_slug = $rewrite['slug'] ?? $taxonomy->name;
                if (is_string($rewrite_slug) && sanitize_title($rewrite_slug) === $slug) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function register(string $slug): void
    {
        $slug = self::normalize_slug($slug);
        if ($slug === '' || !self::is_valid_slug($slug)) {
            return;
        }

        self::$slug = $slug;

        if (!self::$filters_registered) {
            self::$filters_registered = true;
            add_filter('login_url', [self::class, 'filter_login_url'], 10, 3);
            add_filter('site_url', [self::class, 'filter_site_url'], 10, 4);
            add_filter('network_site_url', [self::class, 'filter_network_site_url'], 10, 3);
            add_filter('logout_url', [self::class, 'filter_logout_url'], 10, 2);
            add_filter('lostpassword_url', [self::class, 'filter_lostpassword_url'], 10, 2);
            add_filter('register_url', [self::class, 'filter_register_url'], 10, 1);
        }

        self::handle_request();
    }

    public static function was_handle_request_called(): bool
    {
        return self::$handle_request_called;
    }

    public static function reset_for_tests(): void
    {
        self::$slug = '';
        self::$filters_registered = false;
        self::$handle_request_called = false;
    }

    public static function get_slug(): string
    {
        return self::$slug;
    }

    public static function handle_request(): void
    {
        self::$handle_request_called = true;

        if (self::$slug === '') {
            return;
        }

        if (self::is_custom_login_request()) {
            self::serve_login_screen();
        }

        if (self::is_blocked_login_request()) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }

    public static function filter_login_url(string $login_url, string $redirect = '', bool $force_reauth = false): string
    {
        return self::rewrite_login_url($login_url);
    }

    public static function filter_site_url(string $url, string $path, ?string $scheme, ?int $blog_id): string
    {
        if ($path !== '' && (str_contains($path, 'wp-login.php') || str_contains($path, 'wp-signup.php'))) {
            return self::rewrite_login_url($url);
        }

        return $url;
    }

    public static function filter_network_site_url(string $url, string $path, ?string $scheme): string
    {
        if ($path !== '' && (str_contains($path, 'wp-login.php') || str_contains($path, 'wp-signup.php'))) {
            return self::rewrite_login_url($url);
        }

        return $url;
    }

    public static function filter_logout_url(string $logout_url, string $redirect): string
    {
        return self::rewrite_login_url($logout_url);
    }

    public static function filter_lostpassword_url(string $lostpassword_url, string $redirect): string
    {
        return self::rewrite_login_url($lostpassword_url);
    }

    public static function filter_register_url(string $register_url): string
    {
        return self::rewrite_login_url($register_url);
    }

    public static function rewrite_login_url(string $url): string
    {
        if (self::$slug === '' || !self::url_targets_login($url)) {
            return $url;
        }

        $parsed = wp_parse_url($url);
        $query = [];

        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        $custom_url = home_url('/' . self::$slug . '/');

        if ($query !== []) {
            $custom_url = add_query_arg($query, $custom_url);
        }

        return $custom_url;
    }

    private static function url_targets_login(string $url): bool
    {
        return str_contains($url, 'wp-login.php') || str_contains($url, 'wp-signup.php');
    }

    private static function is_custom_login_request(): bool
    {
        $request_path = self::get_request_path();

        return $request_path === self::$slug
            || str_starts_with($request_path, self::$slug . '/');
    }

    private static function is_blocked_login_request(): bool
    {
        if (is_user_logged_in()) {
            return false;
        }

        $request_path = self::get_request_path();

        if (in_array($request_path, ['wp-login.php', 'wp-signup.php', 'wp-register.php'], true)) {
            return true;
        }

        return self::is_wp_admin_request();
    }

    private static function is_wp_admin_request(): bool
    {
        $path = self::get_request_path();

        if ($path === 'wp-admin/admin-ajax.php' || $path === 'wp-admin/admin-post.php') {
            return false;
        }

        return $path === 'wp-admin' || str_starts_with($path, 'wp-admin/');
    }

    private static function get_request_path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $home_path = (string) wp_parse_url(home_url(), PHP_URL_PATH);

        if ($home_path !== '' && $home_path !== '/' && str_starts_with($path, $home_path)) {
            $path = substr($path, strlen($home_path));
        }

        return trim($path, '/');
    }

    private static function serve_login_screen(): void
    {
        global $pagenow;

        $pagenow = 'wp-login.php';
        require_once ABSPATH . 'wp-login.php';
        exit;
    }
}
