<?php

declare(strict_types=1);

namespace SFX\CustomDashboard;

/**
 * Provides real-time WordPress statistics with caching
 *
 * @package SFX_Bricks_Child_Theme
 */
class StatsProvider
{
    /**
     * Transient cache duration in seconds (5 minutes)
     */
    private const CACHE_DURATION = 300;

    /**
     * Transient key prefix
     */
    private const CACHE_PREFIX = 'sfx_dashboard_stat_';

    /**
     * Get count of published posts
     *
     * @return int
     */
    public static function get_published_posts_count(): int
    {
        $cache_key = self::CACHE_PREFIX . 'posts';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return (int) $cached;
        }

        $count = (int) wp_count_posts('post')->publish;
        set_transient($cache_key, $count, self::CACHE_DURATION);

        return $count;
    }

    /**
     * Get count of published pages
     *
     * @return int
     */
    public static function get_pages_count(): int
    {
        $cache_key = self::CACHE_PREFIX . 'pages';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return (int) $cached;
        }

        $count = (int) wp_count_posts('page')->publish;
        set_transient($cache_key, $count, self::CACHE_DURATION);

        return $count;
    }

    /**
     * Get count of media attachments
     *
     * @return int
     */
    public static function get_media_count(): int
    {
        $cache_key = self::CACHE_PREFIX . 'media';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return (int) $cached;
        }

        $count = (int) wp_count_posts('attachment')->inherit;
        set_transient($cache_key, $count, self::CACHE_DURATION);

        return $count;
    }

    /**
     * Get count of users
     *
     * @return int
     */
    public static function get_users_count(): int
    {
        $cache_key = self::CACHE_PREFIX . 'users';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return (int) $cached;
        }

        $count = (int) count_users()['total_users'];
        set_transient($cache_key, $count, self::CACHE_DURATION);

        return $count;
    }

    /**
     * Get count for custom stat with flexible query types
     *
     * Supports multiple data sources: WordPress native, WooCommerce, custom tables,
     * meta queries, and external APIs via callbacks.
     *
     * @param array<string, mixed> $config Stat configuration array
     * @return int
     */
    public static function get_custom_stat_count(array $config): int
    {
        $stat_id = $config['id'] ?? 'unknown';
        $cache_key = self::CACHE_PREFIX . 'custom_' . sanitize_key($stat_id);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return (int) $cached;
        }

        $count = 0;
        $query_type = $config['query_type'] ?? 'callback';

        switch ($query_type) {
            case 'wp_count_posts':
                // Count posts by type and status
                $post_type = $config['post_type'] ?? 'post';
                $status = $config['status'] ?? 'publish';
                $count_obj = wp_count_posts($post_type);
                $count = isset($count_obj->{$status}) ? (int) $count_obj->{$status} : 0;
                break;

            case 'wp_count_comments':
                // Count comments by status
                $comments = wp_count_comments();
                $status = $config['status'] ?? 'approved';
                $count = isset($comments->{$status}) ? (int) $comments->{$status} : 0;
                break;

            case 'wp_query':
                // WP_Query for complex post queries
                $args = $config['query_args'] ?? [];
                $args['fields'] = 'ids';
                $args['posts_per_page'] = -1;
                $query = new \WP_Query($args);
                $count = $query->found_posts;
                wp_reset_postdata();
                break;

            case 'user_query':
                // WP_User_Query for user counts
                $args = $config['query_args'] ?? [];
                $args['fields'] = 'ID';
                $query = new \WP_User_Query($args);
                $count = $query->get_total();
                break;

            case 'database':
                // Direct database query via callback (for security)
                if (!empty($config['sql_callback']) && is_callable($config['sql_callback'])) {
                    $count = (int) call_user_func($config['sql_callback']);
                }
                break;

            case 'woocommerce':
                // WooCommerce-specific queries
                if (function_exists('wc_get_products') && !empty($config['wc_query_type'])) {
                    $count = self::get_woocommerce_count($config);
                }
                break;

            case 'external_api':
                // External API calls via callback
                if (!empty($config['api_callback']) && is_callable($config['api_callback'])) {
                    $count = (int) call_user_func($config['api_callback']);
                }
                break;

            case 'callback':
            default:
                // Custom callback function (most flexible)
                if (isset($config['callback']) && is_callable($config['callback'])) {
                    $count = (int) call_user_func($config['callback']);
                }
                break;
        }

        // Ensure count is non-negative integer
        $count = max(0, (int) $count);

        set_transient($cache_key, $count, self::CACHE_DURATION);
        return $count;
    }

    /**
     * Get WooCommerce-specific count
     *
     * @param array<string, mixed> $config Configuration array with wc_query_type and optional query_args
     * @return int
     */
    private static function get_woocommerce_count(array $config): int
    {
        $wc_type = $config['wc_query_type'] ?? 'products';

        switch ($wc_type) {
            case 'products':
                $args = $config['query_args'] ?? [];
                $args['return'] = 'ids';
                $args['limit'] = -1;
                $products = wc_get_products($args);
                return count($products);

            case 'orders':
                if (!class_exists('WC_Order_Query')) {
                    return 0;
                }
                $args = $config['query_args'] ?? ['status' => ['completed']];
                $args['return'] = 'ids';
                $args['limit'] = -1;
                $query = new \WC_Order_Query($args);
                return count($query->get_orders());

            case 'customers':
                $args = $config['query_args'] ?? [];
                $query = new \WP_User_Query(array_merge([
                    'role' => 'customer',
                    'fields' => 'ID',
                ], $args));
                return $query->get_total();

            default:
                return 0;
        }
    }

    /**
     * Clear all cached statistics
     *
     * @return void
     */
    public static function clear_cache(): void
    {
        delete_transient(self::CACHE_PREFIX . 'posts');
        delete_transient(self::CACHE_PREFIX . 'pages');
        delete_transient(self::CACHE_PREFIX . 'media');
        delete_transient(self::CACHE_PREFIX . 'users');
    }

    /**
     * Get count for a custom post type
     *
     * @param string $post_type
     * @return int
     */
    public static function get_custom_post_type_count(string $post_type): int
    {
        $cache_key = self::CACHE_PREFIX . 'cpt_' . $post_type;
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return (int) $cached;
        }

        $count_obj = wp_count_posts($post_type);
        $count = isset($count_obj->publish) ? (int) $count_obj->publish : 0;
        set_transient($cache_key, $count, self::CACHE_DURATION);

        return $count;
    }

    /**
     * Get all stats at once
     *
     * @param array<string> $custom_post_types Optional array of custom post type names
     * @return array<string, int>
     */
    public static function get_all_stats(array $custom_post_types = []): array
    {
        $stats = [
            'posts' => self::get_published_posts_count(),
            'pages' => self::get_pages_count(),
            'media' => self::get_media_count(),
            'users' => self::get_users_count(),
        ];

        // Add custom post type stats
        foreach ($custom_post_types as $post_type) {
            $stats[$post_type] = self::get_custom_post_type_count($post_type);
        }

        return $stats;
    }

    /**
     * Clear all cached statistics including custom post types and custom stats
     *
     * @param array<string> $custom_post_types Optional array of custom post types to clear
     * @param array<string> $custom_stat_ids Optional array of custom stat IDs to clear
     * @return void
     */
    public static function clear_all_cache(array $custom_post_types = [], array $custom_stat_ids = []): void
    {
        self::clear_cache();

        // Clear custom post type caches
        foreach ($custom_post_types as $post_type) {
            delete_transient(self::CACHE_PREFIX . 'cpt_' . $post_type);
        }

        // Clear custom stat caches
        foreach ($custom_stat_ids as $stat_id) {
            delete_transient(self::CACHE_PREFIX . 'custom_' . sanitize_key($stat_id));
        }
    }
}

