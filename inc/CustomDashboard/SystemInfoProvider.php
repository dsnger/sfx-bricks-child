<?php

declare(strict_types=1);

namespace SFX\CustomDashboard;

/**
 * Provider class for system-related dashboard data
 * Uses only native WordPress functions - no plugins required
 */
class SystemInfoProvider
{
    /**
     * Transient cache prefix
     */
    private const CACHE_PREFIX = 'sfx_dashboard_sys_';

    /**
     * Cache duration in seconds (5 minutes)
     */
    private const CACHE_DURATION = 300;

    /**
     * Get site health status
     *
     * Reads WordPress's site health status directly from core functions.
     * Status is calculated the same way as Site Health screen:
     * - Weighted score based on issue counts
     * - "Good" only when score >= 80 and no critical issues
     * - Otherwise "Should be improved"
     *
     * @see wp-admin/includes/class-wp-site-health.php
     * @return array{status: string, label: string, critical: int, recommended: int, total: int, issues: int}
     */
    public static function get_site_health_status(): array
    {
        $result = [
            'status' => 'good',
            'label' => __('Good', 'sfxtheme'),
            'critical' => 0,
            'recommended' => 0,
            'total' => 0,
            'issues' => 0,
        ];

        // Get cached site health result from WordPress
        $health_result = get_transient('health-check-site-status-result');
        
        if ($health_result) {
            $health_data = json_decode($health_result, true);
            
            if (is_array($health_data)) {
                $good = (int) ($health_data['good'] ?? 0);
                $result['critical'] = (int) ($health_data['critical'] ?? 0);
                $result['recommended'] = (int) ($health_data['recommended'] ?? 0);
                $result['issues'] = $result['critical'] + $result['recommended'];
                $result['total'] = $good + $result['issues'];
                
                // Match WP Site Health screen calculation (wp-admin/js/site-health.js)
                $total_tests = $good + $result['recommended'] + ($result['critical'] * 1.5);
                if ($total_tests > 0) {
                    $failed_tests = ($result['recommended'] * 0.5) + ($result['critical'] * 1.5);
                    $score = 100 - (int) ceil(($failed_tests / $total_tests) * 100);
                } else {
                    $score = 100;
                }

                if ($score >= 80 && $result['critical'] === 0) {
                    $result['status'] = 'good';
                    $result['label'] = __('Good', 'sfxtheme');
                } else {
                    $result['status'] = 'recommended';
                    $result['label'] = __('Should be improved', 'sfxtheme');
                }
            }
        }

        return $result;
    }

    /**
     * Get pending updates data
     *
     * @return array{total: int, plugins: int, themes: int, wordpress: int}
     */
    public static function get_pending_updates(): array
    {
        $update_data = wp_get_update_data();
        
        return [
            'total' => $update_data['counts']['total'] ?? 0,
            'plugins' => $update_data['counts']['plugins'] ?? 0,
            'themes' => $update_data['counts']['themes'] ?? 0,
            'wordpress' => $update_data['counts']['wordpress'] ?? 0,
        ];
    }

    /**
     * Get WordPress version
     *
     * @return string
     */
    public static function get_wp_version(): string
    {
        return get_bloginfo('version');
    }

    /**
     * Get PHP version
     *
     * @return string
     */
    public static function get_php_version(): string
    {
        return phpversion();
    }

    /**
     * Get MySQL version
     *
     * @return string
     */
    public static function get_mysql_version(): string
    {
        global $wpdb;
        return $wpdb->db_version();
    }

    /**
     * Get database size in bytes
     *
     * @return int
     */
    public static function get_database_size(): int
    {
        $cached = get_transient(self::CACHE_PREFIX . 'db_size');
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        
        $size = 0;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = %s",
                DB_NAME
            )
        );
        
        if ($result) {
            $size = (int) $result;
        }

        set_transient(self::CACHE_PREFIX . 'db_size', $size, self::CACHE_DURATION * 2);
        return $size;
    }

    /**
     * Get media library size in bytes
     *
     * @return int
     */
    public static function get_media_library_size(): int
    {
        $cached = get_transient(self::CACHE_PREFIX . 'media_size');
        if ($cached !== false) {
            return (int) $cached;
        }

        $upload_dir = wp_upload_dir();
        $size = self::get_directory_size($upload_dir['basedir']);

        set_transient(self::CACHE_PREFIX . 'media_size', $size, self::CACHE_DURATION * 6);
        return $size;
    }

    /**
     * Calculate directory size recursively
     *
     * @param string $path
     * @return int
     */
    private static function get_directory_size(string $path): int
    {
        $size = 0;
        
        if (!is_dir($path)) {
            return $size;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Get cron jobs overview
     *
     * @return array{total: int, next_run: string|null}
     */
    public static function get_cron_overview(): array
    {
        $crons = _get_cron_array();
        
        $result = [
            'total' => 0,
            'next_run' => null,
        ];

        if (!is_array($crons)) {
            return $result;
        }

        $next_timestamp = null;
        
        foreach ($crons as $timestamp => $cron_hooks) {
            if (is_array($cron_hooks)) {
                $result['total'] += count($cron_hooks);
                
                if ($next_timestamp === null || $timestamp < $next_timestamp) {
                    $next_timestamp = $timestamp;
                }
            }
        }

        if ($next_timestamp) {
            $result['next_run'] = human_time_diff($next_timestamp, time());
        }

        return $result;
    }

    /**
     * Format bytes to human readable string
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    public static function format_bytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Clear all system info caches
     *
     * @return void
     */
    public static function clear_cache(): void
    {
        delete_transient(self::CACHE_PREFIX . 'health');
        delete_transient(self::CACHE_PREFIX . 'db_size');
        delete_transient(self::CACHE_PREFIX . 'media_size');
    }
}


