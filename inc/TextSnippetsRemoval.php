<?php

declare(strict_types=1);

namespace SFX;

/**
 * One-time cleanup and admin notice for the removed Text Snippets feature.
 */
class TextSnippetsRemoval
{
    private const REMOVAL_FLAG_OPTION = 'sfx_text_snippets_removed';
    private const LEGACY_OPTION = 'sfx_text_snippets_options';
    private const LEGACY_POST_TYPE = 'cpt_text_snippet';
    private const LEGACY_TAXONOMY = 'cpt_text_snippet_cat';
    private const NOTICE_DISMISS_KEY = 'sfx_text_snippets_legacy_notice_dismissed';

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'run_one_time_cleanup']);
        add_action('admin_notices', [self::class, 'maybe_show_legacy_notice']);
        add_action('wp_ajax_sfx_dismiss_text_snippets_notice', [self::class, 'dismiss_notice']);
    }

    /**
     * Delete legacy options and transients once after the feature is removed.
     */
    public static function run_one_time_cleanup(): void
    {
        if (get_option(self::REMOVAL_FLAG_OPTION)) {
            return;
        }

        delete_option(self::LEGACY_OPTION);
        self::clear_snippet_transients();

        update_option(self::REMOVAL_FLAG_OPTION, 1, false);
    }

    /**
     * Show a warning when legacy snippet posts still exist in the database.
     */
    public static function maybe_show_legacy_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (get_user_meta(get_current_user_id(), self::NOTICE_DISMISS_KEY, true)) {
            return;
        }

        $count = self::count_legacy_posts();
        if ($count === 0) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            admin_url('admin-ajax.php?action=sfx_dismiss_text_snippets_notice'),
            'sfx_dismiss_text_snippets_notice',
            'nonce'
        );
        ?>
        <div class="notice notice-warning is-dismissible" data-dismiss-url="<?php echo esc_url($dismiss_url); ?>">
            <p>
                <strong><?php esc_html_e('Text Snippets feature removed', 'sfxtheme'); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: %d: number of legacy text snippet posts */
                    esc_html(
                        _n(
                            'The Text Snippets feature has been removed from this theme. %d legacy snippet post remains in the database.',
                            'The Text Snippets feature has been removed from this theme. %d legacy snippet posts remain in the database.',
                            $count,
                            'sfxtheme'
                        )
                    ),
                    (int) $count
                );
                ?>
            </p>
            <p>
                <?php esc_html_e('Any [snippet] shortcodes or {snippet_content:ID} Bricks dynamic tags in your content will now render as literal text. Please update affected pages and templates manually.', 'sfxtheme'); ?>
            </p>
            <p>
                <?php esc_html_e('Legacy snippet data will be permanently deleted if you remove this theme with "Delete Data on Uninstall" enabled in General Theme Options.', 'sfxtheme'); ?>
            </p>
        </div>
        <script>
        (function () {
            document.addEventListener('click', function (event) {
                var notice = event.target.closest('.notice[data-dismiss-url]');
                if (!notice) {
                    return;
                }
                var dismissButton = event.target.closest('.notice-dismiss');
                if (!dismissButton) {
                    return;
                }
                var url = notice.getAttribute('data-dismiss-url');
                if (!url) {
                    return;
                }
                fetch(url, { credentials: 'same-origin' });
            });
        })();
        </script>
        <?php
    }

    /**
     * Persist notice dismissal for the current user.
     */
    public static function dismiss_notice(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        check_ajax_referer('sfx_dismiss_text_snippets_notice', 'nonce');

        update_user_meta(get_current_user_id(), self::NOTICE_DISMISS_KEY, 1);
        wp_send_json_success();
    }

    /**
     * Count non-trashed legacy snippet posts.
     */
    public static function count_legacy_posts(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash'",
                self::LEGACY_POST_TYPE
            )
        );

        return (int) $count;
    }

    /**
     * Delete all legacy snippet posts, meta, and taxonomy terms.
     * Used by uninstall.php when delete_on_uninstall is enabled.
     */
    public static function purge_legacy_data(): void
    {
        global $wpdb;

        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
                self::LEGACY_POST_TYPE
            )
        );

        if (!empty($post_ids)) {
            foreach ($post_ids as $post_id) {
                wp_delete_post((int) $post_id, true);
            }
        }

        $term_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} AS t
                INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
                WHERE tt.taxonomy = %s",
                self::LEGACY_TAXONOMY
            )
        );

        if (!empty($term_ids)) {
            foreach ($term_ids as $term_id) {
                wp_delete_term((int) $term_id, self::LEGACY_TAXONOMY);
            }
        }

        delete_option(self::LEGACY_OPTION);
        delete_option(self::REMOVAL_FLAG_OPTION);
        self::clear_snippet_transients();
    }

    /**
     * Remove snippet-specific transients from wp_options.
     */
    private static function clear_snippet_transients(): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                WHERE option_name LIKE %s
                   OR option_name LIKE %s
                   OR option_name LIKE %s
                   OR option_name LIKE %s",
                '_transient_sfx_text_snippet_%',
                '_transient_timeout_sfx_text_snippet_%',
                '_transient_sfx_text_snippet_field_%',
                '_transient_timeout_sfx_text_snippet_field_%'
            )
        );
    }
}
