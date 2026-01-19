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
     * Common field keys that might contain a name
     */
    private const NAME_FIELD_KEYS = ['name', 'full_name', 'fullname', 'your-name', 'your_name', 'vorname', 'nachname', 'firstname', 'first_name', 'lastname', 'last_name'];

    /**
     * Common field keys that might contain an email
     */
    private const EMAIL_FIELD_KEYS = ['email', 'e-mail', 'your-email', 'your_email', 'mail', 'user_email', 'contact_email'];

    /**
     * Common field keys that might contain a subject/message preview
     */
    private const SUBJECT_FIELD_KEYS = ['subject', 'betreff', 'topic', 'reason', 'inquiry_type'];

    /**
     * Get Bricks form submissions admin URL
     *
     * @param string|null $form_id Optional form ID to filter by
     * @return string
     */
    public static function get_admin_url(?string $form_id = null): string
    {
        $url = admin_url('admin.php?page=bricks-form-submissions');
        if ($form_id) {
            $url = add_query_arg('form_id', $form_id, $url);
        }
        return $url;
    }

    /**
     * Check if Bricks form submissions table exists
     *
     * @return bool
     */
    public static function table_exists(): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bricks_form_submissions';
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
    }

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

        $submissions = self::fetch_from_bricks_table($limit);
        set_transient($cache_key, $submissions, self::CACHE_DURATION);

        return $submissions;
    }

    /**
     * Fetch form submissions from Bricks custom table
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private static function fetch_from_bricks_table(int $limit): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'bricks_form_submissions';

        if (!self::table_exists()) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        if (!$results) {
            return [];
        }

        return array_map([self::class, 'parse_submission_row'], $results);
    }

    /**
     * Parse a single submission row from the database
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function parse_submission_row(array $row): array
    {
        $form_id = $row['form_id'] ?? '';
        $form_name = $row['form_name'] ?? '';
        
        // Parse the fields JSON
        $fields_raw = $row['fields'] ?? '';
        $fields = self::parse_fields_json($fields_raw);
        
        // Extract common field values
        $extracted = self::extract_common_fields($fields);
        
        // Build display name: prefer form_name, fall back to form_id
        $display_name = !empty($form_name) ? $form_name : $form_id;
        if (empty($display_name)) {
            $display_name = __('Unnamed Form', 'sfxtheme');
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'form_id' => $form_id,
            'form_name' => $display_name,
            'date' => $row['created_at'] ?? '',
            'email' => $extracted['email'],
            'name' => $extracted['name'],
            'subject' => $extracted['subject'],
            'fields' => $fields,
            'fields_count' => count($fields),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'referrer' => $row['referrer'] ?? '',
            'post_id' => (int) ($row['post_id'] ?? 0),
        ];
    }

    /**
     * Parse fields JSON string into array
     *
     * @param string $fields_raw
     * @return array<string, mixed>
     */
    private static function parse_fields_json(string $fields_raw): array
    {
        if (empty($fields_raw)) {
            return [];
        }

        $fields = json_decode($fields_raw, true);
        
        if (!is_array($fields)) {
            return [];
        }

        // Clean up field keys (remove 'form-field-' prefix if present)
        $cleaned = [];
        foreach ($fields as $key => $value) {
            // Skip internal Bricks fields
            if (in_array($key, ['formId', 'postId', 'referrer', 'nonce'], true)) {
                continue;
            }
            
            // Remove common prefixes to get cleaner field names
            $clean_key = preg_replace('/^(form-field-|field-|form_field_)/', '', $key);
            $cleaned[$clean_key] = $value;
        }

        return $cleaned;
    }

    /**
     * Extract common field values (name, email, subject) from fields array
     *
     * @param array<string, mixed> $fields
     * @return array{name: string, email: string, subject: string}
     */
    private static function extract_common_fields(array $fields): array
    {
        $result = [
            'name' => '',
            'email' => '',
            'subject' => '',
        ];

        // Normalize field keys for comparison
        $normalized_fields = [];
        foreach ($fields as $key => $value) {
            $normalized_key = strtolower(str_replace(['-', ' '], '_', $key));
            $normalized_fields[$normalized_key] = $value;
        }

        // Find name
        foreach (self::NAME_FIELD_KEYS as $key) {
            $normalized_key = str_replace('-', '_', $key);
            if (!empty($normalized_fields[$normalized_key])) {
                $result['name'] = sanitize_text_field((string) $normalized_fields[$normalized_key]);
                break;
            }
        }

        // If we have firstname/lastname but no full name, combine them
        if (empty($result['name'])) {
            $first = '';
            $last = '';
            foreach (['firstname', 'first_name', 'vorname'] as $key) {
                if (!empty($normalized_fields[$key])) {
                    $first = sanitize_text_field((string) $normalized_fields[$key]);
                    break;
                }
            }
            foreach (['lastname', 'last_name', 'nachname'] as $key) {
                if (!empty($normalized_fields[$key])) {
                    $last = sanitize_text_field((string) $normalized_fields[$key]);
                    break;
                }
            }
            if ($first || $last) {
                $result['name'] = trim($first . ' ' . $last);
            }
        }

        // Find email
        foreach (self::EMAIL_FIELD_KEYS as $key) {
            $normalized_key = str_replace('-', '_', $key);
            if (!empty($normalized_fields[$normalized_key])) {
                $email = sanitize_email((string) $normalized_fields[$normalized_key]);
                if (is_email($email)) {
                    $result['email'] = $email;
                    break;
                }
            }
        }

        // Find subject
        foreach (self::SUBJECT_FIELD_KEYS as $key) {
            $normalized_key = str_replace('-', '_', $key);
            if (!empty($normalized_fields[$normalized_key])) {
                $result['subject'] = sanitize_text_field((string) $normalized_fields[$normalized_key]);
                break;
            }
        }

        return $result;
    }

    /**
     * Get total submissions count
     *
     * @return int
     */
    public static function get_total_count(): int
    {
        global $wpdb;

        $cache_key = self::CACHE_PREFIX . 'total_count';
        $cached = get_transient($cache_key);

        if (false !== $cached) {
            return (int) $cached;
        }

        $table_name = $wpdb->prefix . 'bricks_form_submissions';

        if (!self::table_exists()) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

        set_transient($cache_key, $total, self::CACHE_DURATION);

        return $total;
    }

    /**
     * Get submissions count grouped by form
     *
     * @return array<string, array{form_id: string, form_name: string, count: int}>
     */
    public static function get_forms_summary(): array
    {
        global $wpdb;

        $cache_key = self::CACHE_PREFIX . 'forms_summary';
        $cached = get_transient($cache_key);

        if (false !== $cached) {
            return $cached;
        }

        $table_name = $wpdb->prefix . 'bricks_form_submissions';

        if (!self::table_exists()) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            "SELECT form_id, form_name, COUNT(*) as count 
             FROM {$table_name} 
             GROUP BY form_id, form_name 
             ORDER BY count DESC",
            ARRAY_A
        );

        $summary = [];
        if ($results) {
            foreach ($results as $row) {
                $form_id = $row['form_id'] ?? '';
                $summary[$form_id] = [
                    'form_id' => $form_id,
                    'form_name' => $row['form_name'] ?: $form_id,
                    'count' => (int) $row['count'],
                ];
            }
        }

        set_transient($cache_key, $summary, self::CACHE_DURATION);

        return $summary;
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_' . self::CACHE_PREFIX) . '%',
                $wpdb->esc_like('_transient_timeout_' . self::CACHE_PREFIX) . '%'
            )
        );

        // Log error if query failed
        if ($result === false && !empty($wpdb->last_error)) {
            error_log('SFX Dashboard: Failed to clear form submissions cache - ' . $wpdb->last_error);
        }
    }
}
