<?php

declare(strict_types=1);

namespace SFX\CustomDashboard;

/**
 * Provider for Bricks form submissions data
 *
 * @package SFX_Bricks_Child_Theme
 */
class FormSubmissionsProvider
{
    /**
     * Cache key prefix
     */
    private const CACHE_PREFIX = 'sfx_form_submissions_';

    /**
     * Cache duration in seconds (5 minutes)
     */
    private const CACHE_DURATION = 300;

    /**
     * Get recent form submissions
     *
     * @param int $limit Number of submissions to retrieve
     * @return array<int, array<string, mixed>>
     */
    public static function get_recent_submissions(int $limit = 5): array
    {
        $cache_key = self::CACHE_PREFIX . 'recent_' . $limit;
        $cached = get_transient($cache_key);

        if (false !== $cached) {
            return $cached;
        }

        $submissions = self::fetch_submissions($limit);
        set_transient($cache_key, $submissions, self::CACHE_DURATION);

        return $submissions;
    }

    /**
     * Fetch form submissions from database
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private static function fetch_submissions(int $limit): array
    {
        global $wpdb;

        // Check if Bricks stores submissions in custom table or post type
        // First, check for custom post type 'bricks_form_submission' or similar
        $post_type = 'bricks_form_submission';
        
        $submissions = [];

        // Try to get submissions from custom post type
        $args = [
            'post_type' => $post_type,
            'posts_per_page' => $limit,
            'post_status' => 'any',
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        $query = new \WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                
                $submissions[] = [
                    'id' => $post_id,
                    'date' => get_the_date('Y-m-d H:i:s'),
                    'form_name' => get_post_meta($post_id, 'form_name', true) ?: __('Unnamed Form', 'sfxtheme'),
                    'email' => get_post_meta($post_id, 'email', true) ?: get_post_meta($post_id, 'user_email', true),
                    'name' => get_post_meta($post_id, 'name', true) ?: get_post_meta($post_id, 'user_name', true),
                    'status' => get_post_status(),
                ];
            }
            wp_reset_postdata();
        } else {
            // If no submissions found, try checking in options or custom table
            // Bricks might store form data differently
            $submissions = self::check_alternative_storage($limit);
        }

        return $submissions;
    }

    /**
     * Check alternative storage methods for form submissions
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private static function check_alternative_storage(int $limit): array
    {
        global $wpdb;

        // Check for custom table (example: wp_bricks_form_submissions)
        $table_name = $wpdb->prefix . 'bricks_form_submissions';
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;

        if ($table_exists) {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d",
                    $limit
                ),
                ARRAY_A
            );

            if ($results) {
                return array_map(function($row) {
                    return [
                        'id' => $row['id'] ?? 0,
                        'date' => $row['created_at'] ?? $row['date'] ?? '',
                        'form_name' => $row['form_name'] ?? $row['form_id'] ?? __('Form Submission', 'sfxtheme'),
                        'email' => $row['email'] ?? '',
                        'name' => $row['name'] ?? '',
                        'status' => $row['status'] ?? 'received',
                    ];
                }, $results);
            }
        }

        return [];
    }

    /**
     * Get total submissions count
     *
     * @return int
     */
    public static function get_total_count(): int
    {
        $cache_key = self::CACHE_PREFIX . 'total_count';
        $cached = get_transient($cache_key);

        if (false !== $cached) {
            return (int) $cached;
        }

        $count = wp_count_posts('bricks_form_submission');
        $total = 0;

        if ($count) {
            foreach ($count as $status => $num) {
                $total += $num;
            }
        }

        set_transient($cache_key, $total, self::CACHE_DURATION);

        return $total;
    }

    /**
     * Clear all form submissions cache
     *
     * @return void
     */
    public static function clear_cache(): void
    {
        global $wpdb;

        // Delete all transients with our prefix
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM " . $wpdb->options . " WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_' . self::CACHE_PREFIX) . '%'
            )
        );
    }
}
