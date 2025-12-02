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
     * Clear all cached statistics including custom post types
     *
     * @param array<string> $custom_post_types Optional array of custom post types to clear
     * @return void
     */
    public static function clear_all_cache(array $custom_post_types = []): void
    {
        self::clear_cache();
        
        // Clear custom post type caches
        foreach ($custom_post_types as $post_type) {
            delete_transient(self::CACHE_PREFIX . 'cpt_' . $post_type);
        }
    }
}

