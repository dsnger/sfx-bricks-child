<?php

declare(strict_types=1);

namespace SFX\CustomDashboard;

/**
 * Provider class for content-related dashboard data
 * Uses only native WordPress functions - no plugins required
 */
class ContentProvider
{
    /**
     * Transient cache prefix
     */
    private const CACHE_PREFIX = 'sfx_dashboard_content_';

    /**
     * Cache duration in seconds (5 minutes)
     */
    private const CACHE_DURATION = 300;

    /**
     * Get drafts count for posts and pages
     *
     * @return array{posts: int, pages: int, total: int}
     */
    public static function get_drafts_count(): array
    {
        $post_counts = wp_count_posts('post');
        $page_counts = wp_count_posts('page');
        
        $posts = (int) ($post_counts->draft ?? 0);
        $pages = (int) ($page_counts->draft ?? 0);
        
        return [
            'posts' => $posts,
            'pages' => $pages,
            'total' => $posts + $pages,
        ];
    }

    /**
     * Get scheduled posts
     *
     * @param int $limit
     * @return array<int, array{id: int, title: string, date: string, type: string}>
     */
    public static function get_scheduled_posts(int $limit = 5): array
    {
        $cached = get_transient(self::CACHE_PREFIX . 'scheduled_' . $limit);
        if ($cached !== false) {
            return $cached;
        }

        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'future',
            'numberposts' => $limit,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        $result = [];
        foreach ($posts as $post) {
            $result[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'date' => get_the_date('', $post),
                'time' => get_the_time('', $post),
                'type' => $post->post_type,
                'edit_url' => get_edit_post_link($post->ID, 'raw'),
            ];
        }

        set_transient(self::CACHE_PREFIX . 'scheduled_' . $limit, $result, self::CACHE_DURATION);
        return $result;
    }

    /**
     * Get pending comments count
     *
     * @return int
     */
    public static function get_pending_comments(): int
    {
        $comments = wp_count_comments();
        return (int) ($comments->moderated ?? 0);
    }

    /**
     * Get recent revisions (activity)
     *
     * @param int $limit
     * @return array<int, array{id: int, title: string, parent_title: string, author: string, date: string, edit_url: string}>
     */
    public static function get_recent_revisions(int $limit = 10): array
    {
        $cached = get_transient(self::CACHE_PREFIX . 'revisions_' . $limit);
        if ($cached !== false) {
            return $cached;
        }

        $revisions = get_posts([
            'post_type' => 'revision',
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_status' => 'inherit',
        ]);

        $result = [];
        foreach ($revisions as $revision) {
            $parent = get_post($revision->post_parent);
            if (!$parent) {
                continue;
            }

            $author = get_userdata($revision->post_author);
            
            $result[] = [
                'id' => $revision->ID,
                'title' => $revision->post_title,
                'parent_title' => $parent->post_title,
                'parent_type' => $parent->post_type,
                'author' => $author ? $author->display_name : __('Unknown', 'sfxtheme'),
                'date' => human_time_diff(strtotime($revision->post_date), current_time('timestamp')) . ' ' . __('ago', 'sfxtheme'),
                'edit_url' => get_edit_post_link($parent->ID, 'raw'),
            ];
        }

        set_transient(self::CACHE_PREFIX . 'revisions_' . $limit, $result, self::CACHE_DURATION);
        return $result;
    }

    /**
     * Get stale content (not modified in X months)
     *
     * @param int $months
     * @param int $limit
     * @return array<int, array{id: int, title: string, type: string, modified: string, edit_url: string}>
     */
    public static function get_stale_content(int $months = 6, int $limit = 10): array
    {
        $cached = get_transient(self::CACHE_PREFIX . 'stale_' . $months . '_' . $limit);
        if ($cached !== false) {
            return $cached;
        }

        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$months} months"));

        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'numberposts' => $limit,
            'orderby' => 'modified',
            'order' => 'ASC',
            'date_query' => [
                [
                    'column' => 'post_modified',
                    'before' => $date_threshold,
                ],
            ],
        ]);

        $result = [];
        foreach ($posts as $post) {
            $result[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'modified' => human_time_diff(strtotime($post->post_modified), current_time('timestamp')) . ' ' . __('ago', 'sfxtheme'),
                'edit_url' => get_edit_post_link($post->ID, 'raw'),
            ];
        }

        set_transient(self::CACHE_PREFIX . 'stale_' . $months . '_' . $limit, $result, self::CACHE_DURATION * 2);
        return $result;
    }

    /**
     * Get taxonomy summary (categories and tags)
     *
     * @return array{categories: int, tags: int}
     */
    public static function get_taxonomy_summary(): array
    {
        return [
            'categories' => (int) wp_count_terms(['taxonomy' => 'category', 'hide_empty' => false]),
            'tags' => (int) wp_count_terms(['taxonomy' => 'post_tag', 'hide_empty' => false]),
        ];
    }

    /**
     * Get recent user registrations
     *
     * @param int $limit
     * @return array<int, array{id: int, name: string, email: string, role: string, registered: string}>
     */
    public static function get_recent_users(int $limit = 5): array
    {
        $cached = get_transient(self::CACHE_PREFIX . 'users_' . $limit);
        if ($cached !== false) {
            return $cached;
        }

        $users = get_users([
            'orderby' => 'registered',
            'order' => 'DESC',
            'number' => $limit,
        ]);

        $result = [];
        foreach ($users as $user) {
            $roles = $user->roles;
            $role = !empty($roles) ? ucfirst($roles[0]) : __('No role', 'sfxtheme');
            
            $result[] = [
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'role' => $role,
                'registered' => human_time_diff(strtotime($user->user_registered), current_time('timestamp')) . ' ' . __('ago', 'sfxtheme'),
                'edit_url' => get_edit_user_link($user->ID),
            ];
        }

        set_transient(self::CACHE_PREFIX . 'users_' . $limit, $result, self::CACHE_DURATION);
        return $result;
    }

    /**
     * Get homepage ID for quick edit
     *
     * @return int|null
     */
    public static function get_homepage_id(): ?int
    {
        $homepage_id = (int) get_option('page_on_front');
        return $homepage_id > 0 ? $homepage_id : null;
    }

    /**
     * Clear all content caches
     *
     * @return void
     */
    public static function clear_cache(): void
    {
        global $wpdb;
        
        // Delete all transients with our prefix
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . self::CACHE_PREFIX . '%'
            )
        );
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_timeout_' . self::CACHE_PREFIX . '%'
            )
        );
    }
}


